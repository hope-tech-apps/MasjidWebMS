<?php

namespace App\Services\Stripe;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\FormPayment;
use App\Support\FormPaymentReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Throwable;

/**
 * The OUTBOUND Stripe leg of a form response paid by card (DECISIONS.md 2026-09-11):
 * the fourth sibling, cloned from MealOrderCheckoutService as it stands at 42f07d6
 * rather than refactored out of it (.claude/rules/stripe-payments.md). Same doctrine:
 *
 *   - Connect STANDARD + DIRECT charge: the hosted Checkout Session is created ON the
 *     organisation's connected account (`stripe_account`). The organisation is
 *     merchant of record; the platform takes only `application_fee_amount`, sent
 *     ONLY when > 0.
 *   - PCI SAQ A: hosted Checkout only.
 *   - Integer minor units, charged from the row's snapshot (amount_due_minor,
 *     fee_covered_minor, total_minor, written at submit by App\Support\FormPayment),
 *     never recomputed from the decimal fee.
 *   - **The webhook is the source of truth.** Nothing here marks a row paid or sends
 *     a receipt; the URL handed back is a redirect, not a promise.
 *
 * `form_response_uuid` (distinct from donation_uuid, registration_uuid and
 * order_uuid) is the routing key, on BOTH the session and the payment intent, with
 * the masjid and form ids beside it. Metadata never decides tenancy: the webhook
 * takes the masjid from event.account.
 *
 * Where it departs from the lunch sibling, and why:
 *
 *   - A page lives 30 minutes (`expires_at`), not Stripe's default 24 hours. The
 *     festival also takes cash at the gate, and a card page left open in a tab for a
 *     day is a second payment waiting to happen (festival brief, blocker 4).
 *   - Card only (`payment_method_types`), not whatever the organisation has switched on
 *     in its Stripe dashboard. A bank debit completes the page days before its money
 *     moves, and a registration that is "complete" but unpaid can then be neither paid
 *     again nor settled by hand at the door: every path reads "complete" as "just paid,
 *     take nothing". Apple Pay and Google Pay come with card, and every receipt and
 *     screen already says "by card".
 *   - A cancelled registration is never payable (preflight()), the rule
 *     MealOrderCheckoutService::paymentLink() applies to a cancelled order.
 *   - checkout() takes the ROW LOCK as well as reopen(). The first page is opened
 *     after the submit has committed, so a double-tap that races past the replay
 *     guard meets this lock, waits, and is handed the page the first request opened.
 *   - The payer's email is prefilled only when Stripe will take it (prefillEmail()),
 *     and a Stripe refusal of it is retried once without it. The form's `email:rfc`
 *     rule accepts addresses Stripe rejects, and one would otherwise fail this
 *     checkout and every reopen for good.
 *   - Refusals are FormCheckoutRefused, so the public controllers show these
 *     messages and never a database error's.
 *
 * Only the three protected seams touch the live API; tests subclass them, so nothing
 * here reaches Stripe from the suite.
 */
class FormResponseCheckoutService
{
    /** Stripe says the page was paid; the webhook will record it (FormCheckoutRefused::paidOnStripe()). */
    public const PAID_ON_STRIPE = 'Payment received — confirming. Please do not pay again.';

    /** The organisation cannot take a card right now (Connect not live). */
    public const UNAVAILABLE = "This form can't take card payments right now.";

    /** An admin cancelled the registration: no page, open or new, for it. */
    public const CANCELLED = 'This registration has been cancelled, so it cannot be paid. Please contact the organisers.';

    /** What a payer is told when Stripe itself failed; the detail is logged, never shown. */
    public const COULD_NOT_OPEN = 'The payment page could not be opened. Please try again in a moment.';

    /** How long a payment page stays payable (festival brief, blocker 4). */
    public const PAGE_LIFETIME_MINUTES = 30;

    /**
     * Thirty minutes is also Stripe's floor, measured from when IT creates the
     * session, after this request has crossed the network. An `expires_at` exactly
     * thirty minutes from our clock would sometimes land a second under the floor and
     * be refused, so the page gets one minute of slack.
     */
    private const EXPIRY_SLACK_SECONDS = 60;

    public function __construct(private StripeClient $stripe)
    {
    }

    /** The platform's application fee on a charge, integer minor units (the meal-order rule). */
    public static function applicationFee(int $chargedMinor, ?float $platformPct = null): int
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return (int) round($chargedMinor * $platformPct);
    }

    /**
     * Why a card payment of $totalMinor cannot be taken for $masjid, or null when it
     * can. Stated once: the submit asks it BEFORE writing anything, and every page
     * this service opens asks it again.
     */
    public static function refusal(?Masjid $masjid, int $totalMinor): ?string
    {
        if ($totalMinor <= 0) {
            return 'This registration has nothing to pay.';
        }

        if ($totalMinor > FormPayment::MAX_CHARGE_MINOR) {
            return 'This registration is more than a card can be charged at once. Please contact the organisers.';
        }

        // Connect onboarding must be complete: a direct charge has nowhere to land
        // otherwise (the gate every sibling uses).
        if ($masjid === null || ! $masjid->canAcceptDonations()) {
            return self::UNAVAILABLE;
        }

        return null;
    }

    /**
     * The address Stripe may prefill on the hosted page, or null: FILTER_VALIDATE_EMAIL
     * and a dotted domain. The form's own `email:rfc` rule accepts `amal@localhost`,
     * which Stripe refuses, and a refused prefill fails the whole checkout.
     */
    public static function prefillEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return str_contains(substr($email, strrpos($email, '@') + 1), '.') ? $email : null;
    }

    /**
     * Log Stripe failing to open or read a page, at warning (the level production
     * runs at), by id and Stripe's error code. Never the message: Stripe quotes the
     * parameter it refused, which can be the payer's email address.
     */
    public static function reportFailure(Throwable $e, FormResponse $response): void
    {
        Log::warning('Stripe did not open a form payment page.', [
            'masjid_id' => $response->masjid_id,
            'form_id' => $response->form_id,
            'form_response_id' => $response->id,
            'exception' => $e::class,
            'stripe_code' => $e instanceof ApiErrorException ? $e->getStripeCode() : null,
            'http_status' => $e instanceof ApiErrorException ? $e->getHttpStatus() : null,
        ]);
    }

    /**
     * Open the hosted page for an unpaid card registration the submit has just
     * written, and return its URL. Under the row lock: a request that got here first
     * has already opened a page, and that page is the answer.
     *
     * @param  string  $returnTo  FormPaymentReturn::base(): an allowlisted origin and a checked path
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     *
     * @throws FormCheckoutRefused
     */
    public function checkout(FormResponse $response, string $returnTo): array
    {
        return $this->onLockedRow($response, function (FormResponse $row, Masjid $masjid, Form $form) use ($returnTo): array {
            if ($row->stripe_checkout_session_id) {
                return $this->reuseOrReplace($row, $masjid, $form, $returnTo);
            }

            return $this->openPage($row, $masjid, $form, $returnTo);
        });
    }

    /**
     * "Return to payment": the page for an unpaid card registration, for a payer who
     * cancelled, was declined, or lost the tab. MealOrderCheckoutService::paymentLink()
     * at 42f07d6, for a form response:
     *
     *   - serialised on the row, so two presses get ONE page;
     *   - an open page is handed back as it is, so a payer never holds two;
     *   - a completed page is refused as "confirming", and the webhook records it;
     *   - an expired (or missing) page is replaced on a NEW idempotency key, or Stripe
     *     would replay the old attempt;
     *   - a cancelled registration is refused, and so is a cash, externally paid or
     *     card-paid one.
     *
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     *
     * @throws FormCheckoutRefused
     */
    public function reopen(FormResponse $response, string $returnTo): array
    {
        return $this->onLockedRow($response, function (FormResponse $row, Masjid $masjid, Form $form) use ($returnTo): array {
            if ($row->stripe_checkout_session_id) {
                return $this->reuseOrReplace($row, $masjid, $form, $returnTo);
            }

            // A key saved by an attempt that never recorded a page (Stripe down, say)
            // would otherwise be replayed: Stripe repeats a saved failure for 24h and
            // rejects the key outright when the parameters differ. That attempt handed
            // nobody a page, and the row lock rules out a concurrent one, so a fresh key
            // cannot make a second payable page.
            $row->idempotency_key = null;

            return $this->openPage($row, $masjid, $form, $returnTo);
        });
    }

    /**
     * Close a registration's open payment page: the first step of an admin taking
     * cash for it (festival brief, blocker 4), and what cancelling one does so it is not
     * left payable (FormResponsesController::update()). Returns Stripe's status for the page:
     * 'expired' once closed (or already), 'complete' when the payer paid first, even
     * a moment before the close landed, which the webhook records; null when there is
     * no page to close.
     *
     * The caller holds the row lock for the whole action (close, then settle as cash),
     * so no page can be opened in between. The row is re-read under the lock here as
     * well, which nests harmlessly. The session id stays on the row: a payment that
     * still lands is recorded against it, and never flips a cash row back.
     *
     * @throws FormCheckoutRefused when the page could not be closed and may still be payable
     */
    public function closeOpenSession(FormResponse $response): ?string
    {
        return DB::transaction(function () use ($response): ?string {
            $row = FormResponse::query()->whereKey($response->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->stripe_checkout_session_id || $row->isPaid()) {
                return null;
            }

            $account = (string) Masjid::find($row->masjid_id)?->stripe_account_id;

            if ($account === '') {
                return null;
            }

            $session = $this->retrieveCheckoutSession((string) $row->stripe_checkout_session_id, $account);

            if ($session['status'] !== 'open') {
                return $session['status'];
            }

            return $this->closeSession($row, $account);
        });
    }

    /**
     * Lock the row, refuse what may not be charged, and run $open on the locked copy.
     * What $open returns is copied back onto $response.
     *
     * When it throws instead (a refusal, or Stripe failing), the row as the lock found it
     * is copied back. The callers answer with $response, which was read before the lock,
     * and a refusal is usually caused by what committed in between: "Take cash" or a
     * cancel landing while this request waited. Answered from the earlier copy, a refusal
     * would say "unpaid, can pay" beside "already paid" or "cancelled". The transaction
     * has rolled back, so the row as locked is the row as stored.
     *
     * @param  callable(FormResponse, Masjid, Form): array{response: FormResponse, checkout_url: string, session_id: ?string}  $open
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     */
    private function onLockedRow(FormResponse $response, callable $open): array
    {
        $locked = null;

        try {
            $result = DB::transaction(function () use ($response, $open, &$locked): array {
                $row = FormResponse::query()->whereKey($response->getKey())->lockForUpdate()->firstOrFail();
                $locked = $row->getAttributes();

                [$masjid, $form] = $this->preflight($row);

                return $open($row, $masjid, $form);
            });
        } catch (Throwable $e) {
            if ($locked !== null) {
                $response->setRawAttributes($locked, true);
            }

            throw $e;
        }

        $response->setRawAttributes($result['response']->getAttributes(), true);

        return $result;
    }

    /**
     * Every reason this registration may NOT be charged, stated once. Writes nothing.
     *
     * @return array{0: Masjid, 1: Form}
     *
     * @throws FormCheckoutRefused
     */
    private function preflight(FormResponse $row): array
    {
        if ($row->isPaid()) {
            throw new FormCheckoutRefused('This registration has already been paid.');
        }

        // Cancelled by an admin: never payable, whoever asks. The payer's "Return to
        // payment", a replayed submit and a first page racing the cancel all arrive here
        // under the row lock, as MealOrderCheckoutService::paymentLink() refuses a
        // cancelled order at 42f07d6. Cancelling closes the page that was open
        // (FormResponsesController::update()); this stops another being opened.
        if ($row->status === FormResponse::STATUS_CANCELLED) {
            throw new FormCheckoutRefused(self::CANCELLED);
        }

        // Cash and externally paid rows are always paid, so what is left here is a row
        // with no money leg (a free form, the Wix fallback): no card's to take.
        if ($row->payment_method !== FormResponse::METHOD_ONLINE) {
            throw new FormCheckoutRefused('This registration is not paid by card.');
        }

        $masjid = Masjid::find($row->masjid_id);
        $reason = self::refusal($masjid, (int) $row->total_minor);

        if ($reason !== null) {
            throw new FormCheckoutRefused($reason);
        }

        // Hand-filtered: the public paths that call this run unbound.
        $form = Form::query()->where('masjid_id', $row->masjid_id)->whereKey($row->form_id)->first();

        // Card payment switched off since the row was written (the Wix fallback, chosen
        // mid-sale), or the form removed: no new card page for it.
        if ($form === null || ! $form->takesOnlinePayment()) {
            throw new FormCheckoutRefused('Card payment is not available for this form right now.');
        }

        return [$masjid, $form];
    }

    /**
     * A page is already recorded on the row: hand it back while it is open, refuse it
     * once it is paid, and replace it once it has expired.
     *
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     */
    private function reuseOrReplace(FormResponse $row, Masjid $masjid, Form $form, string $returnTo): array
    {
        $account = (string) $masjid->stripe_account_id;
        $session = $this->retrieveCheckoutSession((string) $row->stripe_checkout_session_id, $account);

        if ($session['status'] === 'complete') {
            throw FormCheckoutRefused::paidOnStripe();
        }

        if ($session['status'] === 'open' && $session['url']) {
            return [
                'response' => $row,
                'checkout_url' => (string) $session['url'],
                'session_id' => $row->stripe_checkout_session_id,
            ];
        }

        // Open, but with no address to hand out: closed FIRST, so the payer can never
        // hold two payable pages.
        if ($session['status'] === 'open' && $this->closeSession($row, $account) === 'complete') {
            throw FormCheckoutRefused::paidOnStripe();
        }

        $row->stripe_checkout_session_id = null;
        $row->idempotency_key = null;

        return $this->openPage($row, $masjid, $form, $returnTo);
    }

    /**
     * Open a new page on the locked row: the idempotency key persisted BEFORE the call,
     * and only the session id recorded after it.
     *
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     */
    private function openPage(FormResponse $row, Masjid $masjid, Form $form, string $returnTo): array
    {
        $currency = strtolower((string) ($row->currency ?: 'usd'));
        $lines = $this->lineItems($row, $form, $currency);

        // Asked again of the exact lines about to be sent: the hosted page must never
        // show one total while the row records another.
        FormPayment::assertLinesMatchTotal($lines, (int) $row->total_minor);

        $metadata = [
            'form_response_uuid' => (string) $row->uuid,
            'masjid_id' => (string) $row->masjid_id,
            'form_id' => (string) $row->form_id,
        ];

        $paymentIntentData = ['metadata' => $metadata];
        $fee = self::applicationFee((int) $row->total_minor);

        if ($fee > 0) {
            $paymentIntentData['application_fee_amount'] = $fee;
        }

        $params = array_merge([
            'mode' => 'payment',
            // Card only: a delayed method would complete the page before its money moved.
            'payment_method_types' => ['card'],
            'client_reference_id' => (string) $row->uuid,
            'metadata' => $metadata,
            'line_items' => $lines,
            'payment_intent_data' => $paymentIntentData,
            'expires_at' => now()
                ->addMinutes(self::PAGE_LIFETIME_MINUTES)
                ->addSeconds(self::EXPIRY_SLACK_SECONDS)
                ->getTimestamp(),
        ], FormPaymentReturn::urls($returnTo, $row));

        $email = self::prefillEmail($row->respondent_email);

        if ($email !== null) {
            $params['customer_email'] = $email;
        }

        try {
            $session = $this->create($row, $params, $masjid);
        } catch (InvalidRequestException $e) {
            if (! isset($params['customer_email'])) {
                throw $e;
            }

            // By id and Stripe's code: Stripe's message quotes the address.
            Log::warning('Stripe refused a form checkout with the payer\'s email; retrying once without it.', [
                'masjid_id' => $row->masjid_id,
                'form_id' => $row->form_id,
                'form_response_id' => $row->id,
                'stripe_code' => $e->getStripeCode(),
                'stripe_param' => $e->getStripeParam(),
            ]);

            unset($params['customer_email']);

            // A new key: the refused one belongs to the parameters it was sent with.
            $row->idempotency_key = null;
            $session = $this->create($row, $params, $masjid);
        }

        // The handle only: a session is a redirect, not a payment.
        if (! empty($session['id'])) {
            $row->stripe_checkout_session_id = $session['id'];
            $row->save();
        }

        return [
            'response' => $row,
            'checkout_url' => (string) ($session['url'] ?? ''),
            'session_id' => $session['id'] ?? null,
        ];
    }

    /**
     * Persist the key BEFORE talking to Stripe, then open the page with it, so a
     * retried request re-sends the same key and Stripe answers with the same page.
     *
     * @return array{id:?string,url:?string,payment_intent:?string}
     */
    private function create(FormResponse $row, array $params, Masjid $masjid): array
    {
        $row->idempotency_key = $row->idempotency_key ?: ('form_response_' . Str::uuid());
        $row->save();

        return $this->createCheckoutSession($params, (string) $masjid->stripe_account_id, (string) $row->idempotency_key);
    }

    /**
     * The lines for the row's snapshot.
     *
     * Normally one line of the entries at the unit price, named after the form and the
     * tier the row was priced at, plus the named card-fee line when a fee is covered.
     * The first line is rebuilt through FormPayment::quote() at the row's
     * submitted_at, so a reopen after a tier has stepped still shows the price the row
     * was registered at. When the form's price has changed since, so the rebuild no
     * longer reproduces the snapshot, the snapshot is charged as a single line: a payer
     * is never charged anything but what they were quoted.
     *
     * @return array<int,array{quantity:int,price_data:array{currency:string,unit_amount:int,product_data:array{name:string}}}>
     */
    private function lineItems(FormResponse $row, Form $form, string $currency): array
    {
        $amountDue = (int) $row->amount_due_minor;
        $lines = [];

        if ($amountDue > 0) {
            try {
                $quote = FormPayment::quote($form, is_array($row->data) ? $row->data : [], false, false, $row->submitted_at);
            } catch (LogicException) {
                $quote = null; // a price the form can no longer state; the snapshot still stands
            }

            $lines[] = $quote !== null && $quote['amount_due_minor'] === $amountDue && $quote['line_items'] !== []
                ? self::line($currency, $quote['unit_minor'], $quote['quantity'], $quote['line_items'][0]['price_data']['product_data']['name'])
                : self::line($currency, $amountDue, 1, trim((string) $form->name) !== '' ? trim((string) $form->name) : 'Registration');
        }

        if ((int) $row->fee_covered_minor > 0) {
            $lines[] = self::line($currency, (int) $row->fee_covered_minor, 1, FormPayment::FEE_LINE_NAME);
        }

        return $lines;
    }

    /** @return array{quantity:int,price_data:array{currency:string,unit_amount:int,product_data:array{name:string}}} */
    private static function line(string $currency, int $unitMinor, int $quantity, string $name): array
    {
        return [
            'quantity' => $quantity,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $unitMinor,
                'product_data' => ['name' => $name],
            ],
        ];
    }

    /**
     * Close an open page: 'expired' once closed, or 'complete' when Stripe refused
     * because the payer had just paid. The two are told apart by asking Stripe again,
     * because "take the cash" and "they have already paid by card" are different
     * instructions for the admin at the table.
     *
     * @throws FormCheckoutRefused when the page is still open after the refusal
     */
    private function closeSession(FormResponse $row, string $account): string
    {
        $sessionId = (string) $row->stripe_checkout_session_id;

        try {
            $this->expireCheckoutSession($sessionId, $account);

            return 'expired';
        } catch (InvalidRequestException $e) {
            $now = $this->retrieveCheckoutSession($sessionId, $account)['status'] ?? null;

            if ($now === 'complete' || $now === 'expired') {
                return $now;
            }

            throw new FormCheckoutRefused('That payment page could not be closed. Try again in a moment.');
        }
    }

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

    protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
    {
        $this->stripe->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]);
    }

    /** @return array{status: string, url: ?string} Stripe's 'open' | 'complete' | 'expired'. */
    protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], [
            'stripe_account' => $connectedAccountId,
        ]);

        return ['status' => (string) $session->status, 'url' => $session->url];
    }
}
