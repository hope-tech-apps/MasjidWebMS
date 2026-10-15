<?php

namespace App\Services\FamilyLedger;

use App\Models\{Contact, Masjid, TuitionAccount, TuitionCheckout, TuitionLine};
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\{CartTables, SchoolCalendarReaders};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Checkout is a request to Stripe. Only verified events append financial lines. */
class TuitionPayments
{
    public function __construct(private FamilyLedger $ledger, private RegistrationCheckoutService $checkout) {}

    public function start(TuitionAccount $account, Contact $payer, int $amount): array
    {
        $school = Masjid::findOrFail($account->masjid_id); $this->ledger->assertEnabled((int) $school->id);
        abort_unless($this->ledger->mayRead($account, $payer), 403);
        $this->ledger->require($school->canAcceptDonations(), 'Card payment is not available. Contact the school office.');
        return DB::transaction(function () use ($account, $payer, $amount, $school) {
            $account = TuitionAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $balance = $this->ledger->balance($account);
            $this->ledger->require($amount > 0 && $amount <= $balance && $amount <= 999999999, 'Choose an amount no greater than the balance.');
            $this->ledger->require($amount >= max($account->minimum_payment_minor, TuitionSettings::for((int) $school->id)->minimum_payment_minor), 'The amount is below the school minimum.');
            $pending = TuitionCheckout::where('account_id', $account->id)->where('payer_contact_id', $payer->id)->where('amount_minor', $amount)->where('balance_minor', $balance)->where('stripe_account_id', $school->stripe_account_id)->where('created_at', '>', now()->subMinutes(29))->whereNotNull('checkout_url')->latest('id')->first();
            if ($pending && ! TuitionLine::where('checkout_id', $pending->id)->where('kind', 'card_payment')->exists()) return ['url' => $pending->checkout_url];
            $attempt = TuitionCheckout::create(['masjid_id' => $school->id, 'account_id' => $account->id, 'payer_contact_id' => $payer->id, 'uuid' => (string) Str::uuid(), 'amount_minor' => $amount, 'balance_minor' => $balance, 'currency' => $account->currency, 'stripe_account_id' => $school->stripe_account_id]);
            $meta = ['tuition_checkout_uuid' => $attempt->uuid];
            // Server-configured portal origin; never a caller-supplied URL or generic cart return allowlist.
            $base = rtrim(config('app.url'), '/').'/family/'.$school->id.'/tuition';
            $params = ['mode' => 'payment', 'payment_method_types' => ['card'], 'metadata' => $meta,
                'payment_intent_data' => ['metadata' => $meta], 'expires_at' => now()->addMinutes(31)->timestamp,
                'line_items' => [['price_data' => ['currency' => $account->currency, 'unit_amount' => $amount, 'product_data' => ['name' => 'School tuition payment']], 'quantity' => 1]],
                'success_url' => $base.'?checkout=success', 'cancel_url' => $base.'?checkout=cancelled'];
            // Exact decimal fraction parsing: no binary floats in tuition amount/fee arithmetic.
            $fraction = (string) config('services.stripe.platform_fee_percentage', '0');
            $this->ledger->require((bool) preg_match('/^0(?:\.[0-9]{1,6})?$/D', $fraction), 'The school payment fee configuration needs office review.');
            $digits = str_pad(explode('.', $fraction)[1] ?? '', 6, '0');
            $fee = intdiv($amount * (int) $digits + 500000, 1000000);
            if ($fee > 0) $params['payment_intent_data']['application_fee_amount'] = $fee;
            $session = $this->checkout->createDirectCheckout($params, (string) $school->stripe_account_id, 'tuition:'.$attempt->uuid);
            $this->ledger->require(is_string($session['id'] ?? null) && is_string($session['url'] ?? null), 'Card payment could not be opened.');
            $attempt->update(['stripe_session_id' => $session['id'], 'checkout_url' => $session['url']]);
            return ['url' => $session['url']];
        });
    }

    /** Called only after StripeWebhookController verifies the signature and claims stripe_webhook_events. */
    /** A refunded Charge whose PaymentIntent paid a tuition checkout on this connected account. */
    public function ownsRefund(array $charge, ?string $connectedAccount): bool
    {
        return $this->refundAttempt($charge, $connectedAccount) !== null;
    }

    private function refundAttempt(array $charge, ?string $connectedAccount): ?TuitionCheckout
    {
        $pi = $charge['payment_intent'] ?? null;
        if (! is_string($pi) || $pi === '' || ! is_string($connectedAccount) || ! \Illuminate\Support\Facades\Schema::hasTable('tuition_lines')) return null;
        $checkoutId = TuitionLine::withoutMasjidScope()->where('stripe_payment_intent_id', $pi)->where('kind', 'card_payment')->value('checkout_id');
        return $checkoutId ? TuitionCheckout::withoutMasjidScope()->whereKey($checkoutId)->where('stripe_account_id', $connectedAccount)->first() : null;
    }

    public function event(string $type, array $object, ?string $connectedAccount, ?int $occurredAt = null): void
    {
        $uuid = $object['metadata']['tuition_checkout_uuid'] ?? null;
        if ((! is_string($uuid) || $uuid === '') && $type === 'charge.refunded') $uuid = $this->refundAttempt($object, $connectedAccount)?->uuid;
        if (! is_string($uuid) || $uuid === '' || ! is_string($connectedAccount)) return;
        CartTables::existsOrFail('tuition_checkouts');
        $attempt = TuitionCheckout::withoutMasjidScope()->where('uuid', $uuid)->where('stripe_account_id', $connectedAccount)->first();
        if (! $attempt) return;
        // An in-flight payment must remain recordable after the switch or connection changes.
        DB::transaction(function () use ($attempt, $type, $object, $occurredAt) {
            $account = TuitionAccount::withoutMasjidScope()->where('masjid_id', $attempt->masjid_id)->lockForUpdate()->findOrFail($attempt->account_id);
            $pi = $type === 'payment_intent.succeeded' ? ($object['id'] ?? null) : ($object['payment_intent'] ?? null);
            if (! is_string($pi) || $pi === '') return;
            $this->ledger->require(strtolower((string) ($object['currency'] ?? '')) === $attempt->currency, 'Stripe reported a different currency; office reconciliation required.');
            $date = \Carbon\CarbonImmutable::createFromTimestampUTC($occurredAt ?? now()->timestamp)->setTimezone(SchoolCalendarReaders::for($attempt->masjid_id)->timezone())->toDateString();
            $common = ['checkout_id' => $attempt->id, 'payer_contact_id' => $attempt->payer_contact_id, 'effective_on' => $date, 'stripe_payment_intent_id' => $pi, 'source' => 'stripe_webhook', 'recorded_by_label' => 'Verified Stripe webhook'];
            if ($type === 'charge.refunded') {
                $total = $object['amount_refunded'] ?? null;
                $this->ledger->require(is_int($total) && $total >= 0, 'Invalid Stripe refund amount.');
                $already = (int) TuitionLine::withoutMasjidScope()->where('account_id', $account->id)->where('checkout_id', $attempt->id)->where('kind', 'refund')->sum('amount_minor');
                if ($total > $already) $this->ledger->append($account, $common + ['kind' => 'refund', 'amount_minor' => $total - $already, 'reason' => 'Stripe refund', 'needs_review' => $total > $attempt->amount_minor, 'idempotency_key' => 'refund:'.$attempt->id.':'.$total]);
                return;
            }
            if (! in_array($type, ['checkout.session.completed', 'payment_intent.succeeded'], true)) return;
            if ($type === 'checkout.session.completed' && (($object['payment_status'] ?? '') !== 'paid' || ($object['id'] ?? '') !== $attempt->stripe_session_id)) return;
            $amount = $type === 'checkout.session.completed' ? ($object['amount_total'] ?? null) : ($object['amount_received'] ?? null);
            $this->ledger->require(is_int($amount) && $amount > 0, 'Invalid Stripe payment amount.');
            $balance = $this->ledger->balance($account);
            $this->ledger->append($account, $common + ['kind' => 'card_payment', 'amount_minor' => -$amount, 'reason' => 'Card payment confirmed by Stripe', 'method' => 'stripe', 'needs_review' => $amount !== $attempt->amount_minor || $balance !== $attempt->balance_minor || $amount > $balance, 'idempotency_key' => 'card:'.$attempt->id]);
        });
    }
}
