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
 * SUBSCRIPTIONS (T-006e): an installment or recurring plan opens the SAME
 * hosted Checkout page in `mode=subscription`, still a direct charge on the
 * org's account. Two differences and no others:
 *   - the platform's cut is `application_fee_percent` (the amount-based param
 *     does not exist for subscriptions), still omitted entirely when 0;
 *   - the metadata that routes the webhooks is written into
 *     `subscription_data.metadata`, so it lands on the SUBSCRIPTION and every
 *     `invoice.*` event it raises carries it.
 * An installment plan additionally gets a Subscription SCHEDULE
 * (`end_behavior=cancel` after N iterations) attached once Stripe has told us
 * the subscription id. **STRIPE owns the billing clock, the retries and the
 * dunning** — this codebase has no scheduler, no retry loop and no dunning
 * engine, by doctrine (.claude/rules/stripe-payments.md).
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
     * The platform's application fee for a SUBSCRIPTION, as a PERCENT (2.0 for
     * 2%). Subscriptions have no `application_fee_amount`: Stripe applies
     * `application_fee_percent` to every invoice instead. config stores the
     * platform cut as a fraction (0.02); Stripe wants the percent, rounded to
     * the 2 dp its API accepts.
     *
     * This is not a deviation from the locked rule — the ratified design calls
     * it out explicitly, and zero is still OMITTED rather than sent as 0.
     * Restated here rather than reaching into the locked DonationService, for
     * the same reason the whole class is a sibling and not an abstraction.
     */
    public static function applicationFeePercent(?float $platformPct = null): float
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return round($platformPct * 100, 2);
    }

    /**
     * What ONE Stripe charge is worth for this registration, integer minor
     * units — derived from the registration's own `adjusted_total_minor`
     * snapshot, never re-read from the plan.
     *
     *  - one_time / recurring → the snapshot itself (T-006b snapshots a
     *    recurring plan's per-interval amount, since an open-ended plan has no
     *    finite total).
     *  - installment → the snapshot divided by N, because the snapshot IS the
     *    full commitment for that kind. Aid granted pre-checkout therefore
     *    reduces every installment, which is the whole point of granting it
     *    before the Stripe leg exists.
     *
     * ROUNDING FAVOURS THE PAYER, by cents. `list_total = amount × N` divides
     * exactly, so a remainder can only appear once an admin has granted aid;
     * when it does, integer division drops up to N−1 minor units off what the
     * org collects. The alternative (rounding up) would charge a family more
     * than the total they were quoted, and the alternative to THAT (a
     * multi-phase schedule with an odd first payment) is complexity nobody
     * asked for. Never a float, in either direction.
     */
    public static function perChargeMinor(Registration $registration, FeePlan $feePlan): int
    {
        $total = (int) $registration->adjusted_total_minor;

        if ($feePlan->kind !== FeePlan::KIND_INSTALLMENT) {
            return $total;
        }

        $count = (int) $feePlan->installment_count;

        if ($count < 1) {
            throw RegistrationException::installmentCountMissing();
        }

        return intdiv($total, $count);
    }

    /**
     * Open (or re-mint) the hosted Checkout Session for a pending, paid
     * registration and return its URL.
     *
     * Refuses anything that is not a live, chargeable seat: the free path has
     * no Stripe leg, a confirmed/cancelled/waitlisted registration has nothing
     * to pay, an unrecognized money kind never degrades into a guess, and an
     * org that has not finished Connect onboarding cannot be paid at all.
     *
     * The plan's kind decides the SHAPE of the session (one-time payment vs
     * subscription) and nothing else — the account, the hosted surface, the
     * positive-only platform fee, the snapshot pricing and the
     * webhooks-are-the-truth contract are identical either way.
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

        // THE PROGRAM MUST STILL BE RUNNING. Re-checked here, at the moment money
        // moves, and not only at intake.
        //
        // A registration holds a PENDING seat and a payment link that a family
        // keeps — in an open tab, in an email. Everything above proves the row is
        // coherent; nothing asked whether the thing being paid for is still
        // happening. Measured before this guard, with the org's real connected
        // account: an offering switched to `is_active = false` still returned a
        // live hosted URL for $150.00, as did one whose `closes_at` was a month
        // in the past, as did a DEACTIVATED fee plan — while the public page for
        // that same offering reported `registration_state: closed`.
        //
        // What that costs a real family: the registrar cancels the Ramadan camp
        // using `is_active = false` — the exact non-destructive switch
        // OfferingsController::destroy tells them to use instead of deleting —
        // and every family still holding a pending seat can complete payment.
        // The webhook then confirms them and materialises group memberships for
        // a camp that is not happening. The organisation is merchant of record,
        // so every refund is a manual act in their own Stripe dashboard.
        //
        // Deliberately the SAME predicate the intake path applies
        // (App\Support\OfferingRegistrationState), so "can this be registered
        // for" has one answer everywhere rather than one at the door and a
        // different one at the till.
        if (! $offering->is_open) {
            throw RegistrationException::offeringClosed();
        }

        if (! $feePlan->is_active) {
            throw RegistrationException::planInactive();
        }

        // AN EXPIRED HOLD IS DEAD, NOT LATE. resolveExpiresAt() treated a
        // deadline in the past as "clamp up to the floor", so re-minting a
        // checkout resurrected a hold that had already lapsed and pushed
        // `checkout_expires_at` forward again — repeatable indefinitely, keeping
        // a seat and its payment link alive forever. The only thing that ever
        // closed that window was the `registrations:reap-expired` sweep, which
        // made a scheduled job the sole gate on a money path.
        if ($registration->checkout_expires_at !== null
            && $registration->checkout_expires_at->isPast()) {
            throw RegistrationException::notCheckoutable('expired');
        }

        // Money kinds never degrade (.claude/rules/registration-billing-data.md).
        // `free` has no Stripe leg at all and anything unrecognized is a data
        // error — both fail loudly here rather than being charged in some
        // guessed shape.
        if (! in_array($feePlan->kind, [
            FeePlan::KIND_ONE_TIME,
            FeePlan::KIND_INSTALLMENT,
            FeePlan::KIND_RECURRING,
        ], true)) {
            throw RegistrationException::checkoutKindUnsupported((string) $feePlan->kind);
        }

        $masjid = Masjid::find($registration->masjid_id);

        // Connect onboarding must be complete — the direct charge has nowhere
        // to land otherwise (same gate the public donation path uses).
        if (! $masjid || ! $masjid->canAcceptDonations()) {
            throw RegistrationException::orgCannotCollectPayments();
        }

        $currency = strtolower((string) ($feePlan->currency ?: config('services.stripe.currency', 'usd')));

        // What ONE charge is worth. For a subscription plan this is the
        // per-interval amount derived from the snapshot; for one_time it IS the
        // snapshot. Aid that floors an installment to nothing is the free-path
        // carve-out's problem, never a $0 subscription.
        $amount = self::perChargeMinor($registration, $feePlan);

        if ($amount <= 0) {
            throw RegistrationException::nothingToCharge();
        }

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

        $priceData = [
            'currency' => $currency,
            'unit_amount' => $amount,
            'product_data' => [
                'name' => (string) $offering->name,
            ],
        ];

        $params = [
            'client_reference_id' => $registration->uuid,
            'metadata' => $metadata,
            // Stripe expires the session at the same instant the seat hold ends,
            // and the resulting checkout.session.expired releases the seat.
            'expires_at' => $expiresAt->getTimestamp(),
            'success_url' => $options['success_url']
                ?? rtrim((string) config('app.url'), '/') . '/registrations/thank-you?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $options['cancel_url']
                ?? rtrim((string) config('app.url'), '/') . '/registrations/cancelled',
        ];

        if ($feePlan->isSubscriptionBased()) {
            $interval = (string) $feePlan->billing_interval;

            // A subscription plan without a valid interval cannot be billed at
            // all; guessing "month" would invent a billing cadence nobody
            // agreed to. Money kinds never degrade.
            if (! in_array($interval, FeePlan::BILLING_INTERVALS, true)) {
                throw RegistrationException::planIntervalMissing();
            }

            $priceData['recurring'] = ['interval' => $interval];

            // Written onto the SUBSCRIPTION, which is what makes every
            // `invoice.*` event it later raises carry our routing signal —
            // there is no payment intent to hang it on here.
            $subscriptionData = ['metadata' => $metadata];

            $feePercent = self::applicationFeePercent();

            // Positive-only, exactly like the one-time fee: Stripe rejects a
            // zero application_fee_percent, so the key is ABSENT, never 0.
            if ($feePercent > 0) {
                $subscriptionData['application_fee_percent'] = $feePercent;
            }

            $params['mode'] = 'subscription';
            $params['subscription_data'] = $subscriptionData;
        } else {
            $paymentIntentData = ['metadata' => $metadata];

            // Only attach a positive application fee — Stripe rejects a zero fee.
            $applicationFee = self::applicationFee($amount);

            if ($applicationFee > 0) {
                $paymentIntentData['application_fee_amount'] = $applicationFee;
            }

            $params['mode'] = 'payment';
            $params['payment_intent_data'] = $paymentIntentData;
        }

        $params['line_items'] = [[
            'quantity' => 1,
            'price_data' => $priceData,
        ]];

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
     * Attach the Subscription SCHEDULE that makes an installment plan STOP
     * after N payments — the object that turns "bill monthly forever" into
     * "bill monthly nine times, then cancel".
     *
     * Stripe cannot create a schedule from inside Checkout, so it is attached
     * the moment Stripe first tells us the subscription id. Both inbound events
     * that can carry it call this (`checkout.session.completed` and the first
     * `invoice.payment_succeeded`), so ORDER DOES NOT MATTER and a dropped
     * session event does not leave an installment plan billing forever.
     *
     * IDEMPOTENT and self-guarding: a registration that already has a schedule
     * id, is not on an installment plan, or has no subscription id yet, makes
     * no API call at all. `end_behavior=cancel` + `iterations=N` is the whole
     * mechanism — STRIPE counts the payments and stops; nothing local schedules
     * anything (.claude/rules/stripe-payments.md).
     *
     * FAILURE IS NOT FATAL: this runs inside a webhook, and throwing would make
     * Stripe retry the delivery forever over an object that is an optimisation
     * of the money path, not the money path itself. The local ledger
     * independently reaches `paid` once N invoices have settled.
     *
     * @return ?string  the schedule id, or null when nothing was attached
     */
    public function ensureInstallmentSchedule(Registration $registration, ?string $subscriptionId = null): ?string
    {
        if ($registration->stripe_subscription_schedule_id !== null) {
            return (string) $registration->stripe_subscription_schedule_id;
        }

        $subscriptionId ??= $registration->stripe_subscription_id;

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return null;
        }

        $feePlan = FeePlan::query()->whereKey($registration->fee_plan_id)->first();

        // Only installments are finite. An open-ended recurring plan has
        // nothing to schedule, and cancelling it is the admin's action.
        if (! $feePlan || $feePlan->kind !== FeePlan::KIND_INSTALLMENT) {
            return null;
        }

        $iterations = (int) $feePlan->installment_count;

        if ($iterations < 1) {
            return null;
        }

        $masjid = Masjid::find($registration->masjid_id);

        try {
            $scheduleId = $this->createInstallmentSchedule(
                $subscriptionId,
                $iterations,
                (string) ($masjid?->stripe_account_id ?? ''),
                [
                    'registration_uuid' => $registration->uuid,
                    'masjid_id' => (string) $registration->masjid_id,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe installment schedule attach failed; the local ledger still terminates at N payments.', [
                'registration_id' => $registration->id,
                'stripe_subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_string($scheduleId) || $scheduleId === '') {
            return null;
        }

        $registration->stripe_subscription_schedule_id = $scheduleId;
        $registration->save();

        return $scheduleId;
    }

    /**
     * Cancel the Stripe subscription behind a registration, if it has one —
     * the money half of T-006d's explicit admin cancel, and the reason
     * cancelling a seat can never leave a family being charged every month for
     * a place they no longer hold. A registration with no subscription id is a
     * no-op: the one-time and free paths have nothing recurring to stop.
     *
     * Cancelling stops FUTURE billing only. Invoices that already settled keep
     * their `succeeded` ledger rows untouched — v1 refunds are the org's own
     * action in its own Stripe dashboard (the org is merchant of record), and
     * restating a settled row would be a fabrication.
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
     * Create the Subscription Schedule that bounds an installment plan to N
     * payments, ON the connected account.
     *
     * Two SDK calls, one seam: `from_subscription` lifts the live subscription
     * into a schedule (its single phase mirrors the current period), then the
     * phase is given `iterations = N` and the schedule `end_behavior = cancel`
     * — so Stripe bills exactly N times, counting the invoice Checkout already
     * settled, and then cancels the subscription itself.
     *
     * @param  array<string,string>  $metadata  routing signal for schedule events
     */
    protected function createInstallmentSchedule(
        string $stripeSubscriptionId,
        int $iterations,
        string $connectedAccountId,
        array $metadata = []
    ): ?string {
        $opts = $connectedAccountId !== '' ? ['stripe_account' => $connectedAccountId] : [];

        $schedule = $this->stripe->subscriptionSchedules->create(
            ['from_subscription' => $stripeSubscriptionId],
            $opts
        );

        // Carry the subscription's own line items forward; only the iteration
        // count and the end behaviour are ours to state.
        $items = [];

        foreach (($schedule->phases[0]->items ?? []) as $item) {
            $price = is_string($item->price ?? null) ? $item->price : ($item->price->id ?? null);

            if ($price !== null) {
                $items[] = ['price' => $price, 'quantity' => (int) ($item->quantity ?? 1)];
            }
        }

        $phase = ['iterations' => $iterations];

        if ($items !== []) {
            $phase['items'] = $items;
        }

        $updated = $this->stripe->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'cancel',
            'phases' => [$phase],
            'metadata' => $metadata,
        ], $opts);

        return $updated->id ?? $schedule->id;
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
