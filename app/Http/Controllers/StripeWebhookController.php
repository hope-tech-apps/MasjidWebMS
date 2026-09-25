<?php

namespace App\Http\Controllers;

use App\Mail\DonationReceiptMail;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Models\StripeWebhookEvent;
use App\Services\Crm\DonorContactService;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Services\Receipts\Letterhead;
use App\Services\Receipts\ReceiptService;
use App\Services\Stripe\DonationService;
use App\Services\Stripe\FormResponsePaymentService;
use App\Services\Stripe\MealOrderPaymentService;
use App\Services\Stripe\MealOrderTopUpPaymentService;
use App\Services\Stripe\RegistrationPaymentService;
use App\Services\Stripe\StripeConnectService;
use App\Support\Errors;
use App\Support\GivingSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 *
 * TWO SIGNING SECRETS (T-006c). Registration charges are direct charges on the
 * ORG's connected account, and Stripe delivers those through a CONNECT
 * endpoint, which signs with its own secret. Both secrets are tried, both
 * fail-closed: an unset secret verifies nothing rather than waving anything
 * through, and a payload matching neither is a 401.
 *
 * DISPATCH SAFETY (the highest-risk touchpoint of T-006c, and the reason the
 * additions here are strictly additive): an object carrying
 * `metadata.registration_uuid` routes to RegistrationPaymentService; EVERYTHING
 * ELSE falls through to today's donation handling, byte-for-byte unchanged. A
 * registration event can therefore never book a Donation, and a donation event
 * can never touch a registration. Pinned by RegistrationWebhookTest alongside
 * the untouched DonationFlowTest suite.
 *
 * T-006e widens that ONE branch and nothing else. `invoice.payment_succeeded`
 * and `customer.subscription.deleted` were already dispatched to the donation
 * path; they now ask the same question first, and a payload without our uuid
 * takes the identical route it took before. `invoice.payment_failed` and
 * `subscription_schedule.completed` are new arms whose non-registration case is
 * `null` — precisely what `default` did with them yesterday. No donation
 * behaviour is altered; DonationFlowTest passes untouched.
 *
 * FORMS (DECISIONS.md 2026-09-11) add one more question, on the two success events
 * only, asked after the order and registration questions and before the donation
 * default: an object carrying `metadata.form_response_uuid` goes to
 * FormResponsePaymentService. A form's expired page is not routed (a form holds no
 * seat), so it is acked and ignored exactly as before, and every other event takes
 * the route it took yesterday. Pinned by FormPaymentWebhookTest.
 *
 * LUNCH TOP-UPS (owner, 2026-09-24) are asked about FIRST, before orders. A paid
 * order's customer pays the difference for a bigger order on its own Checkout
 * Session, whose metadata carries `kind` = MealOrderTopUp::STRIPE_KIND AND the
 * order's `order_uuid`. Were the order question asked first, that session would
 * be read as the order's own payment. Its payment intent carries `kind` and no
 * `order_uuid`, and is acked and ignored: the session event settles a top-up.
 * Every event without `kind` takes the route it took before. Pinned by
 * MealOrderTopUpTest.
 *
 * THE GIVING SWITCH NEVER REFUSES HERE (DECISIONS.md, organisation switches wave 2).
 * Money that reaches this controller has already moved at Stripe, so a donation for
 * an organisation whose Giving is switched off is booked, receipted and emailed
 * exactly like any other. The only addition is a warning, once per donation or
 * commitment, from App\Support\GivingSwitch::noteArrivalIfOff, called AFTER that
 * work and unable to throw. Pinned by ModuleSideDoorsTest.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private DonationService $donations,
        private ReceiptService $receipts,
        private DonationReceiptPdfService $receiptPdfs,
        private StripeConnectService $connect,
        private DonorContactService $donorContacts,
        private RegistrationPaymentService $registrationPayments,
        private MealOrderPaymentService $mealOrderPayments,
        private FormResponsePaymentService $formResponsePayments,
        private MealOrderTopUpPaymentService $mealOrderTopUps,
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
     *
     * Two endpoints feed this route and they sign differently: the PLATFORM
     * endpoint (donations on the platform's own account) and the CONNECT
     * endpoint (every event raised on a connected account — which is every
     * registration charge, since those are direct charges on the ORG). A
     * payload authentic under EITHER configured secret is accepted; one
     * authentic under neither is rejected.
     *
     * FAIL CLOSED, in both directions: with no secrets configured at all
     * nothing is ever accepted, and an unconfigured Connect secret simply means
     * connect deliveries fail verification — it never becomes a bypass.
     */
    private function verifiedEvent(Request $request): ?array
    {
        $secrets = array_values(array_filter([
            config('services.stripe.webhook_secret'),
            config('services.stripe.connect_webhook_secret'),
        ]));

        if ($secrets === []) {
            // Fail closed — never accept an unverified webhook.
            return null;
        }

        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');

        foreach ($secrets as $secret) {
            try {
                // Authenticates the raw payload (throws on bad signature /
                // timestamp outside tolerance). We then decode the same raw
                // payload we just proved authentic.
                \Stripe\Webhook::constructEvent($payload, $signature, $secret);
            } catch (\Throwable $e) {
                continue;   // try the other endpoint's secret.
            }

            $event = json_decode($payload, true);

            return is_array($event) && isset($event['id'], $event['type']) ? $event : null;
        }

        return null;
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
        // When Stripe raised the event: for a top-up, when the page was paid, which
        // decides whether it came before the cutoff whatever the delivery's delay.
        $paidAt = is_int($event['created'] ?? null) ? $event['created'] : null;

        // The branch that decides whose event this is, by DISTINCT metadata key.
        // A meal order carries metadata.order_uuid; a registration carries
        // metadata.registration_uuid; a form response carries
        // metadata.form_response_uuid; everything else keeps today's donation
        // behaviour exactly. The keys never collide, so an order event can never
        // book a donation or a registration, and vice versa.
        // A lunch top-up names its order too, so it is asked about first.
        $isTopUp = MealOrderTopUpPaymentService::isTopUpEvent($object);
        $isOrder = ! $isTopUp && MealOrderPaymentService::isOrderEvent($object);
        $isRegistration = ! $isTopUp && ! $isOrder && RegistrationPaymentService::isRegistrationEvent($object);
        $isFormResponse = ! $isTopUp && ! $isOrder && ! $isRegistration && FormResponsePaymentService::isFormResponseEvent($object);

        match ($event['type']) {
            'checkout.session.completed' => match (true) {
                $isTopUp => $this->mealOrderTopUps->handleCheckoutCompleted($object, $account, $paidAt),
                $isOrder => $this->mealOrderPayments->handleCheckoutCompleted($object, $account),
                $isRegistration => $this->registrationPayments->handleCheckoutCompleted($object, $account),
                $isFormResponse => $this->formResponsePayments->handleCheckoutCompleted($object, $account),
                default => $this->handleCheckoutCompleted($object),
            },
            // A delayed payment method (a bank debit) completes the page with
            // payment_status `unpaid`; when its money moves, Stripe sends this with
            // the same session, now `paid`. Same handlers, same idempotent settlement.
            // Form checkout is card only, so a form response should never get here;
            // if one does, its own handler settles it rather than the donation path.
            'checkout.session.async_payment_succeeded' => match (true) {
                $isTopUp => $this->mealOrderTopUps->handleCheckoutCompleted($object, $account, $paidAt),
                $isOrder => $this->mealOrderPayments->handleCheckoutCompleted($object, $account),
                $isRegistration => $this->registrationPayments->handleCheckoutCompleted($object, $account),
                $isFormResponse => $this->formResponsePayments->handleCheckoutCompleted($object, $account),
                default => $this->handleCheckoutCompleted($object),
            },
            // ...and this when it never does. Nothing was booked on the unpaid
            // completion, so each handler says so at warning; a registration also
            // gives its held seat back.
            'checkout.session.async_payment_failed' => match (true) {
                $isTopUp => Log::warning('A delayed payment for a lunch top-up failed; nothing was recorded.', [
                    'checkout_session_id' => $object['id'] ?? null,
                    'account' => $account,
                ]),
                $isOrder => $this->mealOrderPayments->handleAsyncPaymentFailed($object, $account),
                $isRegistration => $this->registrationPayments->handleAsyncPaymentFailed($object, $account),
                $isFormResponse => Log::warning('A delayed payment for a form registration failed; nothing was recorded.', [
                    'checkout_session_id' => $object['id'] ?? null,
                    'account' => $account,
                ]),
                default => $this->handleAsyncPaymentFailed($object, $account),
            },
            'payment_intent.succeeded' => match (true) {
                // The session event settles a top-up; its payment intent is acked.
                $isTopUp => null,
                $isOrder => $this->mealOrderPayments->handlePaymentIntentSucceeded($object, $account),
                $isRegistration => $this->registrationPayments->handlePaymentIntentSucceeded($object, $account),
                $isFormResponse => $this->formResponsePayments->handlePaymentIntentSucceeded($object, $account),
                default => $this->handlePaymentIntentSucceeded($object, $account),
            },
            // New event type for this slice: the seat-release trigger. A
            // donation has no expiry semantics, so a non-registration expiry is
            // acked and ignored exactly as it was before.
            // A lunch top-up's page expiring drops the change it was holding.
            'checkout.session.expired' => match (true) {
                $isTopUp => $this->mealOrderTopUps->handleCheckoutExpired($object, $account),
                $isRegistration => $this->registrationPayments->handleCheckoutExpired($object, $account),
                default => null,
            },
            // Shared with the recurring-DONATION path, which owns this event
            // today. The registration branch is additive: an invoice without
            // our uuid anywhere in it books a donation exactly as it always
            // has (T-006e).
            'invoice.payment_succeeded' => $isRegistration
                ? $this->registrationPayments->handleInvoicePaid($object, $account)
                : $this->handleInvoicePaid($object, $account),
            // New for T-006e: the dunning event. Donations never handled it, so
            // a non-registration failure is acked and ignored exactly as before.
            'invoice.payment_failed' => $isRegistration
                ? $this->registrationPayments->handleInvoiceFailed($object, $account)
                : null,
            'customer.subscription.deleted' => $isRegistration
                ? $this->registrationPayments->handleSubscriptionDeleted($object, $account)
                : $this->donations->cancelSubscriptionByStripeId((string) ($object['id'] ?? '')),
            // New for T-006e: an installment commitment billed out in full.
            'subscription_schedule.completed' => $isRegistration
                ? $this->registrationPayments->handleScheduleCompleted($object, $account)
                : null,
            // Unchanged for every account the platform was never disconnected from. One whose
            // disconnection was recorded ignores an update Stripe created before it
            // (syncAccountStatusUnlessStale()).
            'account.updated' => $this->syncAccountStatusUnlessStale($object, $event),
            // New for DECISIONS.md 2026-09-15, all three additive (each was `default`'s
            // null before). A refund or dispute only FLAGS a form registration whose charge
            // was pinned to the event's account; every other charge is acked as before.
            'charge.refunded' => $this->formResponsePayments->handleChargeFlag($object, $account, FormResponse::CHARGE_FLAG_REFUNDED),
            'charge.dispute.created' => $this->formResponsePayments->handleChargeFlag($object, $account, FormResponse::CHARGE_FLAG_DISPUTED),
            // An organisation disconnected the platform from its Standard account. No
            // account.updated follows, so the stored flags would say "can take charges"
            // forever: cleared here, so every gate that reads them fails closed.
            'account.application.deauthorized' => $this->handleAccountDeauthorized($account, $event),
            default => null, // unhandled event types are acked and ignored.
        };
    }

    /**
     * account.application.deauthorized (DECISIONS.md 2026-09-15): the connected account
     * `event.account` no longer lets the platform act on it. Clear the charges and payouts
     * flags of the live organisation(s) holding it, so FormChargeAccount (and
     * canAcceptDonations()) stop offering card payments on an account nothing can be
     * charged on. The account id itself is kept: pinned pages still name it.
     *
     * The disconnection is RECORDED (`masjids.stripe_deauthorized_at`, Stripe's time for
     * the event), because Stripe does not order deliveries: an account.updated queued or
     * retried from before the disconnect, landing after this, would otherwise turn the
     * flags back on. Only an update Stripe created after it (a reconnect) does.
     * FormResponseCheckoutService stamps the same column when Stripe refuses a pinned page
     * this event never arrived for. Logged at warning.
     */
    private function handleAccountDeauthorized(?string $account, array $event): void
    {
        if (! is_string($account) || ! str_starts_with($account, 'acct_')) {
            Log::warning('A Stripe deauthorization arrived without a connected account; nothing was changed.');

            return;
        }

        $at = self::eventCreated($event) ?? now()->getTimestamp();
        $holders = Masjid::query()->where('stripe_account_id', $account)->get();

        foreach ($holders as $holder) {
            // Never moved earlier: a later stamp (a refused page seen first) already covers this.
            $stamp = max($at, self::deauthorizedAt($holder) ?? 0);

            $holder->forceFill([
                'stripe_charges_enabled' => false,
                'stripe_payouts_enabled' => false,
                'stripe_deauthorized_at' => Carbon::createFromTimestampUTC($stamp)->format('Y-m-d H:i:s'),
            ])->save();
        }

        Log::warning('An organisation disconnected the platform from its Stripe account; its card payments are switched off.', [
            'account' => $account,
            'masjid_ids' => $holders->pluck('id')->all(),
        ]);
    }

    /**
     * account.updated, exactly as before (StripeConnectService::syncAccountStatus()) for
     * every account with no recorded disconnection. For one with a recorded disconnection
     * (handleAccountDeauthorized(), or a pinned form page Stripe refused):
     *
     *  - an update Stripe created at or before that moment is a late delivery from before
     *    the disconnect: ignored, at warning, so the flags stay off;
     *  - one created after it is the account connected again: the record is cleared and the
     *    flags follow Stripe from then on.
     */
    private function syncAccountStatusUnlessStale(array $object, array $event): void
    {
        $accountId = $object['id'] ?? null;

        if (is_string($accountId) && $accountId !== '') {
            $marked = Masjid::query()
                ->where('stripe_account_id', $accountId)
                ->whereNotNull('stripe_deauthorized_at')
                ->get();

            if ($marked->isNotEmpty()) {
                $since = (int) $marked->map(fn (Masjid $masjid) => self::deauthorizedAt($masjid) ?? 0)->max();
                $created = self::eventCreated($event);

                if ($created === null || $created <= $since) {
                    Log::warning('A Stripe account update created before the platform was disconnected from that account arrived late; it was ignored, so its card payments stay off.', [
                        'account' => $accountId,
                        'event_created' => $created,
                        'deauthorized_at' => $since,
                        'charges_enabled' => (bool) ($object['charges_enabled'] ?? false),
                        'masjid_ids' => $marked->pluck('id')->all(),
                    ]);

                    return;
                }

                foreach ($marked as $masjid) {
                    $masjid->forceFill(['stripe_deauthorized_at' => null])->save();
                }

                Log::warning('A Stripe account the platform had been disconnected from sent an update created after the disconnection; its card payment flags follow Stripe again.', [
                    'account' => $accountId,
                    'event_created' => $created,
                    'charges_enabled' => (bool) ($object['charges_enabled'] ?? false),
                    'masjid_ids' => $marked->pluck('id')->all(),
                ]);
            }
        }

        $this->connect->syncAccountStatus($object);
    }

    /** Stripe's own time for an event (unix seconds), or null when it carries none. */
    private static function eventCreated(array $event): ?int
    {
        $created = $event['created'] ?? null;

        return is_int($created) || (is_string($created) && ctype_digit($created)) ? (int) $created : null;
    }

    /** The recorded disconnection of $masjid's account, as unix seconds (UTC), or null. */
    private static function deauthorizedAt(Masjid $masjid): ?int
    {
        $value = $masjid->stripe_deauthorized_at;

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->getTimestamp()
            : Carbon::parse((string) $value, 'UTC')->getTimestamp();
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

        // A completed Checkout Session only means "money moved" when it is paid:
        // `payment_status`, never `status`, which is `complete` for a bank debit
        // whose money has not moved. That one stays pending here and is settled by
        // checkout.session.async_payment_succeeded or payment_intent.succeeded.
        $paid = ($session['payment_status'] ?? null) === 'paid';

        if ($paid) {
            $this->donations->markSucceeded($donation, $ids);
            // Seed the donor CRM from the checkout details, then issue + email
            // the receipt to that contact. Each step is idempotent.
            $this->donorContacts->linkFromCheckoutSession($donation->refresh(), $session);
            $receipt = $this->receipts->issueFor($donation->refresh());
            if ($receipt) {
                $this->deliverReceipt($donation->refresh(), $receipt);
            }

            // Booked and receipted above whatever the switch says; this only notes
            // it, once per donation (payment_intent.succeeded shares the key).
            GivingSwitch::noteArrivalIfOff((int) $donation->masjid_id, 'gift', $donation->id, [
                'amount_minor' => (int) $donation->charged_amount,
            ]);
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

        // A new monthly commitment that bills every month. Linked above whatever
        // the switch says; noted once per commitment.
        GivingSwitch::noteArrivalIfOff((int) $subscription->masjid_id, 'monthly_gift_started', $subscription->id, [
            'amount_minor' => (int) $subscription->charged_amount,
            'interval' => $subscription->interval,
        ]);
    }

    /**
     * A delayed donation payment (a bank debit) failed after its page completed.
     * The donation was never marked succeeded, so no receipt exists to void: it
     * stays pending, and the failure is logged for the organisation's records.
     */
    private function handleAsyncPaymentFailed(array $session, ?string $account): void
    {
        $donation = ($session['mode'] ?? null) === 'subscription' ? null : $this->findDonation([
            'uuid' => $session['metadata']['donation_uuid'] ?? ($session['client_reference_id'] ?? null),
            'stripe_checkout_session_id' => $session['id'] ?? null,
            'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
        ]);

        Log::warning('A delayed donation payment failed; nothing was marked succeeded and no receipt was issued.', [
            'donation_id' => $donation?->id,
            'masjid_id' => $donation ? (int) $donation->masjid_id : null,
            'checkout_session_id' => $session['id'] ?? null,
            'mode' => $session['mode'] ?? null,
            'account' => $account,
        ]);
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

        // A replayed invoice returns the donation it already booked, so keying on
        // the donation notes each charge once.
        GivingSwitch::noteArrivalIfOff((int) $donation->masjid_id, 'gift', $donation->id, [
            'amount_minor' => (int) $donation->charged_amount,
        ]);
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

        // Printable copy of the receipt, attached to the same email. Rendered
        // OUTSIDE the send's try/catch and tolerated on failure on purpose: the
        // receipt is already issued, and a dompdf hiccup must not cost the donor
        // the HTML receipt they got before this attachment existed.
        $pdf = null;
        $pdfName = null;
        try {
            $pdf = $this->receiptPdfs->pdfFor($receipt);
            $pdfName = $this->receiptPdfs->filename($receipt);
        } catch (\Throwable $e) {
            Log::warning('Receipt PDF render failed; sending receipt without the attachment', [
                'donation_id' => $donation->id,
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
        }

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
                pdf: $pdf,
                pdfName: $pdfName,
                // The same wording decision the attached PDF made, so the email
                // and the receipt it carries never disagree.
                religiousOrg: Letterhead::religiousOrg($masjid),
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

        // Same key as the checkout event for this donation: noted once.
        GivingSwitch::noteArrivalIfOff((int) $donation->masjid_id, 'gift', $donation->id, [
            'amount_minor' => (int) $donation->charged_amount,
        ]);
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
