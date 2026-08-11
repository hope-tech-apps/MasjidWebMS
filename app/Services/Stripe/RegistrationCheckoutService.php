<?php

namespace App\Services\Stripe;

use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Services\Registrations\RegistrationException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * RegistrationCheckoutService — the OUTBOUND Stripe leg of a paid registration
 * (T-006c, docs/t006-registration-billing-design.md).
 *
 * A DELIBERATE SIBLING of DonationService, not a shared abstraction: the
 * donation path is locked, and the ratified design defers any shared-helper
 * extraction to its own task. This class mirrors that file's idiom (persist
 * first, key the create, direct charge on the connected account) rather than
 * refactoring it.
 *
 * Design (locked, .claude/rules/stripe-payments.md):
 *   - Stripe Connect STANDARD account + DIRECT charge: the Checkout Session is
 *     created ON the org's connected account (the `stripe_account` request
 *     option = the Stripe-Account header). Funds land in the ORG's balance; the
 *     org is merchant of record and bears its own refunds/disputes. Never
 *     destination charges, `transfer_data`, `on_behalf_of`, or a platform-held
 *     balance.
 *   - The platform takes only `application_fee_amount`, sent ONLY when > 0
 *     (Stripe rejects a zero fee).
 *   - PCI SAQ A: the card is entered on Stripe's HOSTED Checkout page. This
 *     app never renders a card form and never sees a PAN.
 *   - All money integer minor units — the CHARGED amount is the registration's
 *     `adjusted_total_minor` snapshot, always. The fee plan is never re-read
 *     for a price, so a later plan change cannot restate what somebody agreed
 *     to pay, and admin aid granted pre-checkout is already inside the
 *     snapshot.
 *   - **Webhooks are the source of truth.** Nothing here advances the
 *     registration: the seat stays `pending` and the money `awaiting` until a
 *     signature-verified `checkout.session.completed` /
 *     `payment_intent.succeeded` lands (RegistrationPaymentService). The
 *     returned URL is a redirect, not a promise.
 *
 * IDEMPOTENCY: the key is minted at INTAKE (T-006b) and stored on the
 * registration, so a retried checkout request re-sends the SAME key and Stripe
 * returns the same Session instead of opening a second one. The re-mint path
 * (a genuinely abandoned session) ROTATES the key — a new Session is the point
 * there (.claude/rules/registration-billing-data.md).
 *
 * $0 IS NOT A CHECKOUT: a free plan, or aid that waives the total to 0, is the
 * declared free-path carve-out and confirms in-request (T-006b). This class
 * refuses such a registration loudly rather than minting a zero session.
 *
 * Only `createCheckoutSession` touches the live API; it is a thin protected
 * seam returning a plain array, so every test stubs it and none of this ever
 * reaches Stripe.
 */
class RegistrationCheckoutService
{
    /**
     * Stripe's own bounds on a Checkout Session's `expires_at`: no sooner than
     * 30 minutes and no later than 24 hours from creation. The seat hold is
     * clamped into this window (and the registration's `checkout_expires_at`
     * rewritten to match) so the deadline WE sweep against and the deadline
     * Stripe expires against are the same instant.
     */
    private const STRIPE_MIN_EXPIRY_MINUTES = 30;

    private const STRIPE_MAX_EXPIRY_HOURS = 24;

    public function __construct(private StripeClient $stripe)
    {
    }

    /**
     * The platform's application fee (Connect) on a charge, integer minor
     * units. Computed from the same config the donation path reads; deliberately
     * restated here rather than reaching into DonationService, which is locked.
     */
    public static function applicationFee(int $chargedMinor, ?float $platformPct = null): int
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return (int) round($chargedMinor * $platformPct);
    }

    /**
     * Open (or re-mint) the hosted Checkout Session for a pending, paid
     * registration and return its URL.
     *
     * Refuses anything that is not a live, chargeable, one-time seat: the free
     * path has no Stripe leg, a confirmed/cancelled/waitlisted registration has
     * nothing to pay, subscription-shaped plans are T-006e, and an org that has
     * not finished Connect onboarding cannot be paid at all.
     *
     * @param  array{success_url?:string,cancel_url?:string}  $options
     * @return array{registration: Registration, checkout_url: string, session_id: ?string}
     *
     * @throws RegistrationException
     */
    public function checkout(Registration $registration, array $options = []): array
    {
        if ($registration->status !== Registration::STATUS_PENDING) {
            throw RegistrationException::notCheckoutable($registration->status);
        }

        // Never a $0 session: the free-path carve-out owns that case and has
        // already confirmed the seat synchronously.
        if ((int) $registration->adjusted_total_minor <= 0) {
            throw RegistrationException::nothingToCharge();
        }

        $offering = Offering::query()->whereKey($registration->offering_id)->first();
        $feePlan = FeePlan::query()->whereKey($registration->fee_plan_id)->first();

        if (! $offering || ! $feePlan) {
            throw RegistrationException::offeringClosed();
        }

        // Tenancy is re-asserted here even though the caller resolved the
        // registration through findByUuidForMasjid: a registration may never be
        // charged onto another organization's connected account.
        if ((int) $offering->masjid_id !== (int) $registration->masjid_id
            || (int) $feePlan->masjid_id !== (int) $registration->masjid_id) {
            throw RegistrationException::crossTenant('offering');
        }

        // T-006c ships ONE-TIME only. Money kinds never degrade: an installment
        // or recurring plan needs a subscription + schedule (T-006e), so it
        // fails loudly here rather than being charged in the wrong shape.
        if ($feePlan->kind !== FeePlan::KIND_ONE_TIME) {
            throw RegistrationException::checkoutKindUnsupported((string) $feePlan->kind);
        }

        $masjid = Masjid::find($registration->masjid_id);

        // Connect onboarding must be complete — the direct charge has nowhere
        // to land otherwise (same gate the public donation path uses).
        if (! $masjid || ! $masjid->canAcceptDonations()) {
            throw RegistrationException::orgCannotCollectPayments();
        }

        $amount = (int) $registration->adjusted_total_minor;
        $currency = strtolower((string) ($feePlan->currency ?: config('services.stripe.currency', 'usd')));
        $applicationFee = self::applicationFee($amount);

        $idempotencyKey = $this->keyFor($registration);
        $expiresAt = $this->resolveExpiresAt($registration);

        // Persist BEFORE talking to Stripe (donation idiom): the key we are
        // about to send is on the row, so a retry of this same request re-sends
        // it, and the seat deadline already agrees with the session's.
        $registration->idempotency_key = $idempotencyKey;
        $registration->checkout_expires_at = $expiresAt;
        $registration->save();

        // Carried on BOTH the session and the payment intent: the session
        // metadata routes `checkout.session.*`, the payment-intent metadata
        // routes `payment_intent.succeeded`, and either one alone is enough for
        // the webhook to resolve this registration.
        $metadata = [
            'registration_uuid' => $registration->uuid,
            'masjid_id' => (string) $registration->masjid_id,
            'offering_id' => (string) $registration->offering_id,
        ];

        $paymentIntentData = ['metadata' => $metadata];

        // Only attach a positive application fee — Stripe rejects a zero fee.
        if ($applicationFee > 0) {
            $paymentIntentData['application_fee_amount'] = $applicationFee;
        }

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $registration->uuid,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amount,
                    'product_data' => [
                        'name' => (string) $offering->name,
                    ],
                ],
            ]],
            'payment_intent_data' => $paymentIntentData,
            'metadata' => $metadata,
            // Stripe expires the session at the same instant the seat hold ends,
            // and the resulting checkout.session.expired releases the seat.
            'expires_at' => $expiresAt->getTimestamp(),
            'success_url' => $options['success_url']
                ?? rtrim((string) config('app.url'), '/') . '/registrations/thank-you?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $options['cancel_url']
                ?? rtrim((string) config('app.url'), '/') . '/registrations/cancelled',
        ];

        $session = $this->createCheckoutSession(
            $params,
            (string) $masjid->stripe_account_id,
            $idempotencyKey
        );

        // Record the handle only. Status is NOT advanced here — a session is a
        // redirect, not a payment.
        if (! empty($session['id'])) {
            $registration->stripe_checkout_session_id = $session['id'];
            $registration->save();
        }

        return [
            'registration' => $registration,
            'checkout_url' => (string) ($session['url'] ?? ''),
            'session_id' => $session['id'] ?? null,
        ];
    }

    /**
     * The key this Session create is idempotency-keyed with.
     *
     * First checkout: the key minted at intake, so a double-submitted request
     * cannot open two sessions. Re-mint (a session already exists): ROTATE —
     * the caller is deliberately abandoning the old session and Stripe would
     * otherwise replay the stale one.
     */
    private function keyFor(Registration $registration): string
    {
        if ($registration->stripe_checkout_session_id !== null || $registration->idempotency_key === null) {
            return 'reg_checkout_' . Str::uuid();
        }

        return (string) $registration->idempotency_key;
    }

    /**
     * The seat deadline, clamped into Stripe's accepted expiry window. The
     * clamped value is written back onto the registration so the reaper
     * (T-006f), the expired handler, and Stripe all sweep the same instant.
     */
    private function resolveExpiresAt(Registration $registration): CarbonInterface
    {
        $floor = now()->addMinutes(self::STRIPE_MIN_EXPIRY_MINUTES);
        $ceiling = now()->addHours(self::STRIPE_MAX_EXPIRY_HOURS);

        $wanted = $registration->checkout_expires_at
            ? $registration->checkout_expires_at->copy()
            : $floor->copy();

        if ($wanted->lt($floor)) {
            return $floor;
        }

        return $wanted->gt($ceiling) ? $ceiling : $wanted;
    }

    /**
     * Cancel the Stripe subscription behind a registration, if it has one —
     * the money half of T-006d's explicit admin cancel.
     *
     * Subscription legs are created in T-006e (installment/recurring); this
     * branch exists now so that cancelling a seat never silently leaves a live
     * subscription charging a family every month once they do. A registration
     * with no subscription id is a no-op: the one-time and free paths have
     * nothing recurring to stop.
     *
     * A subscription managed by a Subscription Schedule is cancelled with it,
     * so there is deliberately no second call for the schedule id.
     *
     * FAILURE IS NOT FATAL, mirroring DonationService::cancelSubscription: a
     * Stripe outage must not block the admin from cancelling the seat locally,
     * so the error is logged and the caller proceeds. The return value says
     * whether Stripe was actually told.
     */
    public function cancelSubscription(Registration $registration): bool
    {
        $subscriptionId = $registration->stripe_subscription_id;

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return false;
        }

        $masjid = Masjid::find($registration->masjid_id);

        try {
            $this->cancelStripeSubscription(
                $subscriptionId,
                (string) ($masjid?->stripe_account_id ?? '')
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('Stripe registration subscription cancel failed; cancelling the seat locally anyway.', [
                'registration_id' => $registration->id,
                'stripe_subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Stripe seams (thin wrappers; stubbed in tests). The ONLY methods here
    // that touch the live API.
    // ---------------------------------------------------------------------

    /**
     * Create the Checkout Session as a DIRECT charge on the connected account.
     *
     * @return array{id:?string,url:?string,payment_intent:?string}
     */
    protected function createCheckoutSession(
        array $params,
        string $connectedAccountId,
        string $idempotencyKey
    ): array {
        $session = $this->stripe->checkout->sessions->create($params, [
            'stripe_account' => $connectedAccountId,
            'idempotency_key' => $idempotencyKey,
        ]);

        return [
            'id' => $session->id,
            'url' => $session->url,
            'payment_intent' => is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent?->id ?? null),
        ];
    }

    /**
     * Cancel a subscription ON the connected account (direct-charge model, so
     * the subscription lives on the org's account, never the platform's).
     */
    protected function cancelStripeSubscription(string $stripeSubscriptionId, string $connectedAccountId): void
    {
        $opts = $connectedAccountId !== '' ? ['stripe_account' => $connectedAccountId] : [];

        $this->stripe->subscriptions->cancel($stripeSubscriptionId, [], $opts);
    }
}
