<?php

namespace App\Http\Controllers;

use App\Mail\DonationReceiptMail;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Masjid;
use App\Models\StripeWebhookEvent;
use App\Services\Crm\DonorContactService;
use App\Services\Receipts\ReceiptService;
use App\Services\Stripe\DonationService;
use App\Services\Stripe\StripeConnectService;
use App\Support\Errors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook receiver — the SOURCE OF TRUTH for donation state.
 *
 * Why webhooks and not the browser redirect: the success redirect can be
 * spoofed, dropped, or fired before the money actually moves. We only ever
 * advance a donation to `succeeded` and issue its receipt from a
 * signature-verified webhook.
 *
 * Security & correctness:
 *   - Signature is the ONLY gate. The route is registered OUTSIDE auth/throttle
 *     (like the Pusher webhook); every request is HMAC-verified against
 *     STRIPE_WEBHOOK_SECRET. Fail CLOSED if the secret isn't configured.
 *   - Dedup: every event id is recorded in `stripe_webhook_events` (unique).
 *     A duplicate delivery of an already-processed event is acknowledged 200
 *     without re-running side effects.
 *   - Idempotent & order-independent: handlers re-read current donation status
 *     and receipt issuance is idempotent per donation, so duplicate or
 *     out-of-order events (checkout.session.completed vs payment_intent.succeeded)
 *     converge to one succeeded donation + one receipt.
 *
 * Direct-charge note: for events on a connected account, Stripe includes the
 * connected `account` id at the top level; we pass it through to Stripe reads.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private DonationService $donations,
        private ReceiptService $receipts,
        private StripeConnectService $connect,
        private DonorContactService $donorContacts,
    ) {
    }

    public function handle(Request $request)
    {
        $event = $this->verifiedEvent($request);
        if ($event === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Dedup on the unique event id. If we've already fully processed this
        // event, ack without repeating side effects.
        if ($this->alreadyProcessed($event)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Duplicate event ignored.',
            ], Response::HTTP_OK);
        }

        try {
            $this->dispatch($event);

            StripeWebhookEvent::where('stripe_event_id', $event['id'])
                ->update(['processed_at' => now()]);

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed.',
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            // Leave processed_at null so Stripe's retry can re-attempt. Return
            // 500 so Stripe knows to retry.
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify the Stripe signature against the raw body and return the event as
     * a plain array, or null when verification fails / is not configured.
     */
    private function verifiedEvent(Request $request): ?array
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            // Fail closed — never accept an unverified webhook.
            return null;
        }

        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        try {
            // Authenticates the raw payload (throws on bad signature / timestamp
            // outside tolerance). We then decode the same raw payload we just
            // proved authentic.
            \Stripe\Webhook::constructEvent($payload, (string) $signature, $secret);
        } catch (\Throwable $e) {
            return null;
        }

        $event = json_decode($payload, true);

        return is_array($event) && isset($event['id'], $event['type']) ? $event : null;
    }

    /**
     * Record the event id and report whether it was already processed. The
     * unique constraint makes this atomic against concurrent duplicate
     * deliveries.
     */
    private function alreadyProcessed(array $event): bool
    {
        $record = StripeWebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event['id']],
            ['type' => $event['type']],
        );

        return $record->processed_at !== null;
    }

    private function dispatch(array $event): void
    {
        $object = $event['data']['object'] ?? [];
        $account = $event['account'] ?? null;

        match ($event['type']) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($object, $account),
            'invoice.payment_succeeded' => $this->handleInvoicePaid($object, $account),
            'customer.subscription.deleted' => $this->donations->cancelSubscriptionByStripeId((string) ($object['id'] ?? '')),
            'account.updated' => $this->connect->syncAccountStatus($object),
            default => null, // unhandled event types are acked and ignored.
        };
    }

    private function handleCheckoutCompleted(array $session): void
    {
        // A subscription checkout only links the commitment + seeds the donor;
        // the money is booked per invoice by invoice.payment_succeeded.
        if (($session['mode'] ?? null) === 'subscription') {
            $this->handleSubscriptionCheckout($session);

            return;
        }

        $donation = $this->findDonation([
            'uuid' => $session['metadata']['donation_uuid'] ?? ($session['client_reference_id'] ?? null),
            'stripe_checkout_session_id' => $session['id'] ?? null,
            'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
        ]);

        if (! $donation) {
            return;
        }

        $ids = [
            'checkout_session_id' => $session['id'] ?? null,
            'payment_intent_id' => $session['payment_intent'] ?? null,
        ];

        // A completed Checkout Session only means "money moved" when it is paid.
        $paid = ($session['payment_status'] ?? null) === 'paid'
            || ($session['status'] ?? null) === 'complete';

        if ($paid) {
            $this->donations->markSucceeded($donation, $ids);
            // Seed the donor CRM from the checkout details, then issue + email
            // the receipt to that contact. Each step is idempotent.
            $this->donorContacts->linkFromCheckoutSession($donation->refresh(), $session);
            $receipt = $this->receipts->issueFor($donation->refresh());
            if ($receipt) {
                $this->deliverReceipt($donation->refresh(), $receipt);
            }
        } else {
            $this->donations->recordStripeIds($donation, $ids);
        }
    }

    /**
     * A subscription-mode checkout completed. Pin the Stripe subscription/customer
     * ids to our commitment and seed the donor contact from the first checkout —
     * every monthly charge then inherits that contact. No donation is booked here.
     */
    private function handleSubscriptionCheckout(array $session): void
    {
        $subscription = $this->donations->linkSubscriptionCheckout($session);

        if (! $subscription) {
            return;
        }

        $this->donorContacts->linkSubscriptionContact($subscription->refresh(), $session);
    }

    /**
     * A recurring invoice was paid — book it as a donation and issue + deliver its
     * receipt, exactly as a one-time gift. Non-subscription / unrelated invoices
     * resolve to null and are acked without effect.
     */
    private function handleInvoicePaid(array $invoice, ?string $account): void
    {
        $donation = $this->donations->bookRecurringInvoice($invoice, $account);

        if (! $donation) {
            return;
        }

        $receipt = $this->receipts->issueFor($donation->refresh());
        if ($receipt) {
            $this->deliverReceipt($donation->refresh(), $receipt);
        }
    }

    /**
     * Email the donor their receipt — exactly once (guarded by
     * receipt_delivered_at). Best-effort: a mail failure is logged, never
     * throws, so it can't fail the webhook or block receipt issuance.
     */
    private function deliverReceipt(Donation $donation, DonationReceipt $receipt): void
    {
        if ($donation->receipt_delivered_at) {
            return;
        }

        $contact = $donation->contact_id
            ? Contact::withoutMasjidScope()->find($donation->contact_id)
            : null;

        $email = $contact?->email;
        if (! $email) {
            return;
        }

        $masjid = Masjid::find($donation->masjid_id);
        $fund = $donation->fund()->withoutGlobalScopes()->first();
        $donorName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));

        try {
            Mail::to($email)->send(new DonationReceiptMail(
                masjidName: $masjid?->name ?? 'Your masjid',
                donorName: $donorName !== '' ? $donorName : 'Valued donor',
                serial: (int) $receipt->serial_number,
                issueDate: (string) $receipt->issue_date,
                fundName: $fund?->name ?? 'General',
                currency: strtoupper((string) $receipt->currency),
                grossAmount: number_format(((int) $receipt->gross_amount) / 100, 2),
                eligibleAmount: number_format(((int) $receipt->eligible_amount) / 100, 2),
                reference: (string) $donation->uuid,
                recurring: $donation->type === 'recurring',
            ));

            $donation->forceFill(['receipt_delivered_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Receipt email failed to send', [
                'donation_id' => $donation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handlePaymentIntentSucceeded(array $pi, ?string $account): void
    {
        $donation = $this->findDonation([
            'uuid' => $pi['metadata']['donation_uuid'] ?? null,
            'stripe_payment_intent_id' => $pi['id'] ?? null,
        ]);

        if (! $donation) {
            return;
        }

        [$chargeId, $btId, $fee, $net] = $this->resolveCharge($pi, $account, $donation);

        $this->donations->markSucceeded($donation, [
            'payment_intent_id' => $pi['id'] ?? null,
            'charge_id' => $chargeId,
            'balance_transaction_id' => $btId,
            'fee' => $fee,
            'net' => $net,
        ]);

        $this->receipts->issueFor($donation->refresh());
    }

    /**
     * Resolve charge id + balance transaction + fee/net, preferring data
     * already expanded on the payload, then a Stripe read, then the
     * deterministic fee formula (spike fallback until live keys land).
     *
     * @return array{0:?string,1:?string,2:?int,3:?int}
     */
    private function resolveCharge(array $pi, ?string $account, Donation $donation): array
    {
        $latest = $pi['latest_charge'] ?? null;
        $chargeId = is_array($latest) ? ($latest['id'] ?? null) : $latest;
        $btId = $fee = $net = null;

        if (is_array($latest)) {
            $bt = $latest['balance_transaction'] ?? null;
            if (is_array($bt)) {
                $btId = $bt['id'] ?? null;
                $fee = isset($bt['fee']) ? (int) $bt['fee'] : null;
                $net = isset($bt['net']) ? (int) $bt['net'] : null;
            } elseif (is_string($bt)) {
                $btId = $bt;
            }
        }

        if ($fee === null && $chargeId) {
            try {
                $f = $this->donations->fetchChargeFinancials($chargeId, $account);
                $btId = $f['balance_transaction_id'] ?? $btId;
                $fee = $f['fee'] ?? null;
                $net = $f['net'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('Stripe charge fetch failed; using fee formula fallback.', [
                    'charge' => $chargeId,
                ]);
            }
        }

        if ($fee === null) {
            $fee = DonationService::computeStripeFee((int) $donation->charged_amount);
            $net = (int) $donation->charged_amount - $fee;
        }

        return [$chargeId, $btId, $fee, $net];
    }

    /**
     * Find a donation across masjids by any known identifier. The webhook runs
     * UNBOUND (no tenant), so these globally-unique keys are correct without
     * the tenant scope.
     */
    private function findDonation(array $keys): ?Donation
    {
        foreach (['uuid', 'stripe_payment_intent_id', 'stripe_checkout_session_id'] as $column) {
            if (! empty($keys[$column])) {
                $donation = Donation::where($column, $keys[$column])->first();
                if ($donation) {
                    return $donation;
                }
            }
        }

        return null;
    }
}
