<?php

namespace App\Services\Stripe;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\FormPayment;
use App\Support\FormPaymentReturn;
use App\Support\FormReservations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
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
 * ## Charged through another organisation (DECISIONS.md 2026-09-15)
 *
 * The account a page is opened on comes from FormChargeAccount::for(), never from the
 * organisation's own column: an organisation a SuperAdmin linked to its parent (BISS
 * through Burlington Masjid) is charged on the parent's account. For such a charge:
 *
 *   - the account string, the holder's id, a random `charge_ref` and the page's
 *     expiry are PINNED on the row in create(), under the row lock, in the same save as
 *     the idempotency key and before Stripe is called. A row that ever had a pin is
 *     re-pinned by every later page. Every later retrieve and expire of that row's page
 *     uses the pin, whatever the link says by then; the idempotency key is dropped when
 *     the pinned account changes (keys are scoped per account).
 *   - the metadata carries only `form_charge_ref` and `form_id`, never the public uuid
 *     (a bearer handle) or a masjid id, and there is no client_reference_id: the holder's
 *     Stripe users can read the session. KNOWN EXCEPTION: success_url and cancel_url
 *     still carry the uuid (FormPaymentReturn::urls()), because the return page reads the
 *     status by it; closing that needs the public page to keep the uuid itself.
 *     The payment intent's description names the organisation and the form, and its
 *     statement suffix is the organisation's initials ("BISS"). Adaptive Pricing is off,
 *     so the session reports exactly the row's total in the row's currency.
 *   - when card payment is no longer available (unlinked, holder disconnected) a row
 *     whose pinned page was paid still answers "confirming", and one whose page is
 *     still open is handed that page back, before "unavailable" is said.
 *   - when Stripe refuses a pinned page's account outright (isUnreachable(): 403 or
 *     `account_invalid`, never a 401), the live organisation holding that exact account
 *     is switched off (failClosedOnUnreachable()), as account.application.deauthorized
 *     would, in case that event is never delivered.
 *
 * An organisation that is not linked, and has no pinned row, opens exactly the session
 * it always did.
 *
 * Only the three protected seams touch the live API; tests subclass them, so nothing
 * here reaches Stripe from the suite. Each refuses any account that is not an acct_ id,
 * so a missed call site can never open a page on the platform's own account.
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

    /**
     * closeOpenSession()'s answer when Stripe will not let the platform read or close a
     * PINNED page any more (the holder disconnected the platform, or the account is gone).
     * The page's state cannot be known; it can no longer be paid once charge_expires_at
     * has passed.
     */
    public const UNREACHABLE = 'unreachable';

    /** How long a payment page stays payable (festival brief, blocker 4). */
    public const PAGE_LIFETIME_MINUTES = 30;

    /**
     * Thirty minutes is also Stripe's floor, measured from when IT creates the
     * session, after this request has crossed the network. An `expires_at` exactly
     * thirty minutes from our clock would sometimes land a second under the floor and
     * be refused, so the page gets one minute of slack.
     */
    private const EXPIRY_SLACK_SECONDS = 60;

    /**
     * Accounts Stripe answered "no access" for while a PINNED page was read, closed or
     * opened, keyed by account, with ids for the log line. Emptied at the start of each
     * public call and acted on by failClosedOnUnreachable() once its transaction has ended.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $unreachableAccounts = [];

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
        return self::amountRefusal($totalMinor)
            // Connect onboarding must be complete on the account that would be charged:
            // the organisation's own, or its holder's (FormChargeAccount).
            ?? (FormChargeAccount::for($masjid) === null ? self::UNAVAILABLE : null);
    }

    /**
     * The statement descriptor suffix of a charge taken through another organisation's
     * account: the charged organisation's initials, uppercase letters and digits only,
     * at most 10 ("Burlington Islamic Sunday School" is "BISS"). Null when the name
     * yields no letter, which Stripe would refuse.
     */
    public static function statementSuffix(?string $name): ?string
    {
        $initials = '';

        foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $initials .= mb_substr($word, 0, 1);
        }

        $suffix = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii($initials))), 0, 10);

        return preg_match('/[A-Z]/', $suffix) === 1 ? $suffix : null;
    }

    /**
     * Whether Stripe refused because the platform can no longer act on ONE connected
     * account (a Standard account that disconnected the platform answers 403, "does not
     * have access to account"; a vanished one `account_invalid`), as opposed to Stripe
     * being down.
     *
     * An AuthenticationException (401) is deliberately NOT one: it means the platform's
     * own secret key is wrong or rotated, which breaks every account at once and is fixed
     * by fixing the key. Read as "unreachable", a bad .env would tell admins the holder
     * disconnected and invite them to settle pages by hand that may have been paid. It
     * stays a Stripe failure: nothing is recorded (503), and asking again works once the
     * key is right. (A restricted platform key missing a scope would also answer 403; the
     * platform runs on a standard secret key, so a 403 names the account.)
     */
    public static function isUnreachable(Throwable $e): bool
    {
        if ($e instanceof AuthenticationException) {
            return false;
        }

        return $e instanceof PermissionException
            || ($e instanceof ApiErrorException && $e->getStripeCode() === 'account_invalid');
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
        return $this->onLockedRow($response, function (FormResponse $row, Masjid $masjid, Form $form, FormCharge $charge) use ($returnTo): array {
            if ($row->stripe_checkout_session_id) {
                return $this->reuseOrReplace($row, $masjid, $form, $charge, $returnTo);
            }

            return $this->openPage($row, $form, $charge, $returnTo);
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
        return $this->onLockedRow($response, function (FormResponse $row, Masjid $masjid, Form $form, FormCharge $charge) use ($returnTo): array {
            if ($row->stripe_checkout_session_id) {
                return $this->reuseOrReplace($row, $masjid, $form, $charge, $returnTo);
            }

            // A key saved by an attempt that never recorded a page (Stripe down, say)
            // would otherwise be replayed: Stripe repeats a saved failure for 24h and
            // rejects the key outright when the parameters differ. That attempt handed
            // nobody a page, and the row lock rules out a concurrent one, so a fresh key
            // cannot make a second payable page.
            $row->idempotency_key = null;

            return $this->openPage($row, $form, $charge, $returnTo);
        });
    }

    /**
     * Close a registration's open payment page: the first step of an admin taking
     * cash for it (festival brief, blocker 4), and what cancelling one does so it is not
     * left payable (FormResponsesController::update()). Returns Stripe's status for the page:
     * 'expired' once closed (or already), 'complete' when the payer paid first, even
     * a moment before the close landed, which the webhook records; null when there is
     * no page to close, or no account to ask about it.
     *
     * The page is asked about on the account it was opened on: the row's pin
     * (DECISIONS.md 2026-09-15), or, for a row written before pins, its organisation's
     * own account. When Stripe will no longer let the platform act on a PINNED account,
     * the answer is self::UNREACHABLE rather than an exception: the holder disconnected,
     * and asking again will never work.
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
        $this->unreachableAccounts = [];

        try {
            return $this->closeOpenSessionLocked($response);
        } finally {
            $this->failClosedOnUnreachable();
        }
    }

    /** closeOpenSession() under the row lock; an unreachable pin is noted for failClosedOnUnreachable(). */
    private function closeOpenSessionLocked(FormResponse $response): ?string
    {
        return DB::transaction(function () use ($response): ?string {
            $row = FormResponse::query()->whereKey($response->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->stripe_checkout_session_id || $row->isPaid()) {
                return null;
            }

            if (! $row->hasChargePin()) {
                $account = (string) Masjid::find($row->masjid_id)?->stripe_account_id;

                if (! FormChargeAccount::isAccount($account)) {
                    return null;
                }

                return $this->closeOn($row, $account);
            }

            try {
                return $this->closeOn($row, (string) $row->charge_account_id);
            } catch (ApiErrorException $e) {
                if (! self::isUnreachable($e)) {
                    throw $e;
                }

                Log::warning('A form payment page could not be checked because Stripe no longer lets the platform act on the account it was opened on.', [
                    'masjid_id' => $row->masjid_id,
                    'form_id' => $row->form_id,
                    'form_response_id' => $row->id,
                    'charge_masjid_id' => $row->charge_masjid_id,
                    'exception' => $e::class,
                    'stripe_code' => $e->getStripeCode(),
                ]);

                $this->noteUnreachable((string) $row->charge_account_id, $row, $e);

                return self::UNREACHABLE;
            }
        });
    }

    /** Remember that Stripe will not let the platform act on $account (isUnreachable()). */
    private function noteUnreachable(string $account, FormResponse $row, Throwable $e): void
    {
        if (! FormChargeAccount::isAccount($account)) {
            return;
        }

        $this->unreachableAccounts[$account] = [
            'masjid_id' => $row->masjid_id,
            'form_id' => $row->form_id,
            'form_response_id' => $row->id,
            'exception' => $e::class,
            'stripe_code' => $e instanceof ApiErrorException ? $e->getStripeCode() : null,
        ];
    }

    /**
     * Fail closed without waiting for account.application.deauthorized (DECISIONS.md
     * 2026-09-15). The live Connect endpoint may not deliver that event, and until it does
     * the stored flags keep saying "can take charges", so every new registration would
     * pass refusal() and meet a page that can never open.
     *
     * For each account noted during this call: every LIVE organisation whose CURRENT
     * stripe_account_id is exactly that account has its charges and payouts flags cleared,
     * and stripe_deauthorized_at stamped, the same as the webhook arm. An account no live
     * organisation holds any more (the holder re-onboarded onto a new one) changes nobody.
     * A reconnect's account.updated, created after the stamp, turns them back on
     * (StripeWebhookController).
     *
     * Runs after the call's own transaction has ended, so a refusal that rolls the row
     * back does not roll this back with it. Logged at warning.
     */
    private function failClosedOnUnreachable(): void
    {
        $noted = $this->unreachableAccounts;
        $this->unreachableAccounts = [];

        foreach ($noted as $account => $context) {
            $switched = [];

            foreach (Masjid::query()->where('stripe_account_id', $account)->get() as $holder) {
                if (! $holder->stripe_charges_enabled && ! $holder->stripe_payouts_enabled && $holder->stripe_deauthorized_at !== null) {
                    continue;
                }

                $holder->forceFill([
                    'stripe_charges_enabled' => false,
                    'stripe_payouts_enabled' => false,
                    'stripe_deauthorized_at' => now()->utc()->format('Y-m-d H:i:s'),
                ])->save();

                $switched[] = (int) $holder->id;
            }

            if ($switched !== []) {
                Log::warning('Stripe no longer lets the platform act on the Connect account a form payment page was opened on; the organisation holding it has its card payments switched off until it reconnects.', $context + [
                    'account' => $account,
                    'masjid_ids' => $switched,
                ]);
            }
        }
    }

    /** Ask Stripe about the row's page on $account, and close it while it is open. */
    private function closeOn(FormResponse $row, string $account): string
    {
        $session = $this->retrieveCheckoutSession((string) $row->stripe_checkout_session_id, $account);

        if ($session['status'] !== 'open') {
            return $session['status'];
        }

        return $this->closeSession($row, $account);
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
     * @param  callable(FormResponse, Masjid, Form, FormCharge): array{response: FormResponse, checkout_url: string, session_id: ?string}  $open
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     */
    private function onLockedRow(FormResponse $response, callable $open): array
    {
        $locked = null;
        $this->unreachableAccounts = [];

        try {
            $result = DB::transaction(function () use ($response, $open, &$locked): array {
                $row = FormResponse::query()->whereKey($response->getKey())->lockForUpdate()->firstOrFail();
                $locked = $row->getAttributes();

                $ready = $this->preflight($row);

                // A pinned page handed back while card payment is unavailable (preflight()).
                if (isset($ready['page'])) {
                    return $ready['page'];
                }

                return $open($row, $ready['masjid'], $ready['form'], $ready['charge']);
            });
        } catch (Throwable $e) {
            if ($locked !== null) {
                $response->setRawAttributes($locked, true);
            }

            // After the rollback, so switching the account off is not rolled back with it.
            $this->failClosedOnUnreachable();

            throw $e;
        }

        $this->failClosedOnUnreachable();

        $response->setRawAttributes($result['response']->getAttributes(), true);

        return $result;
    }

    /**
     * Every reason this registration may NOT be charged, stated once. Writes nothing.
     *
     * When the only reason is that card payment is unavailable right now, a row whose page
     * was pinned is asked about on its pin first (DECISIONS.md 2026-09-15): a paid page is
     * "confirming", an open one is handed back. Only then is "unavailable" said, so a
     * family that has paid is never offered the office instead.
     *
     * @return array{masjid: Masjid, form: Form, charge: FormCharge}|array{page: array{response: FormResponse, checkout_url: string, session_id: ?string}}
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

        $reason = self::amountRefusal((int) $row->total_minor);

        if ($reason !== null) {
            throw new FormCheckoutRefused($reason);
        }

        $masjid = Masjid::find($row->masjid_id);
        $charge = FormChargeAccount::for($masjid);

        if ($charge === null) {
            $page = $this->pinnedPageWhileUnavailable($row);

            if ($page !== null) {
                return ['page' => $page];
            }

            throw new FormCheckoutRefused(self::UNAVAILABLE);
        }

        // Hand-filtered: the public paths that call this run unbound.
        $form = Form::query()->where('masjid_id', $row->masjid_id)->whereKey($row->form_id)->first();

        // Card payment switched off since the row was written (the Wix fallback, chosen
        // mid-sale), or the form removed: no new card page for it.
        if ($form === null || ! $form->takesOnlinePayment()) {
            throw new FormCheckoutRefused('Card payment is not available for this form right now.');
        }

        return ['masjid' => $masjid, 'form' => $form, 'charge' => $charge];
    }

    /** Nothing to charge, or more than a card can take at once; null when the amount is chargeable. */
    private static function amountRefusal(int $totalMinor): ?string
    {
        if ($totalMinor <= 0) {
            return 'This registration has nothing to pay.';
        }

        if ($totalMinor > FormPayment::MAX_CHARGE_MINOR) {
            return 'This registration is more than a card can be charged at once. Please contact the organisers.';
        }

        return null;
    }

    /**
     * Card payment is unavailable, but this row's page was pinned: ask Stripe about it on
     * the pin. Complete: "confirming". Open with an address: that page. Anything else,
     * including an account Stripe no longer lets the platform read: null, and the caller
     * says unavailable. Stripe being down is thrown, as for any page.
     *
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}|null
     *
     * @throws FormCheckoutRefused
     */
    private function pinnedPageWhileUnavailable(FormResponse $row): ?array
    {
        if (! $row->hasChargePin() || ! $row->stripe_checkout_session_id) {
            return null;
        }

        try {
            $session = $this->retrieveCheckoutSession((string) $row->stripe_checkout_session_id, (string) $row->charge_account_id);
        } catch (ApiErrorException $e) {
            if (self::isUnreachable($e)) {
                $this->noteUnreachable((string) $row->charge_account_id, $row, $e);

                return null;
            }

            throw $e;
        }

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

        return null;
    }

    /**
     * A page is already recorded on the row: hand it back while it is open, refuse it
     * once it is paid, and replace it once it has expired.
     *
     * The page is asked about on the account it was opened on (the pin; for a row written
     * before pins, the organisation's own account). With no such account there is nobody
     * to ask whether it is still payable, so no second page is opened. A pinned account
     * Stripe no longer lets the platform read counts as expired only once the pinned
     * expiry has passed, since Stripe takes no payment on a page after its expires_at.
     *
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     */
    private function reuseOrReplace(FormResponse $row, Masjid $masjid, Form $form, FormCharge $charge, string $returnTo): array
    {
        $account = $row->hasChargePin() ? (string) $row->charge_account_id : (string) $masjid->stripe_account_id;

        if (! FormChargeAccount::isAccount($account)) {
            throw new FormCheckoutRefused(self::UNAVAILABLE);
        }

        try {
            $session = $this->retrieveCheckoutSession((string) $row->stripe_checkout_session_id, $account);
        } catch (ApiErrorException $e) {
            if (! $row->hasChargePin() || ! self::isUnreachable($e)) {
                throw $e;
            }

            $this->noteUnreachable($account, $row, $e);

            if ($row->charge_expires_at === null || ! $row->charge_expires_at->isPast()) {
                throw new FormCheckoutRefused(self::UNAVAILABLE);
            }

            $session = ['status' => 'expired', 'url' => null];
        }

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

        return $this->openPage($row, $form, $charge, $returnTo);
    }

    /**
     * Open a new page on the locked row: the idempotency key persisted BEFORE the call,
     * and only the session id recorded after it.
     *
     * @return array{response: FormResponse, checkout_url: string, session_id: ?string}
     */
    private function openPage(FormResponse $row, Form $form, FormCharge $charge, string $returnTo): array
    {
        $currency = strtolower((string) ($row->currency ?: 'usd'));
        $lines = $this->lineItems($row, $form, $currency);

        // Asked again of the exact lines about to be sent: the hosted page must never
        // show one total while the row records another.
        FormPayment::assertLinesMatchTotal($lines, (int) $row->total_minor);

        // A registration that reserved a date keeps it for this page's life, or is refused
        // when its lapsed hold has gone to another payer: nobody is sent to pay for a date
        // that is no longer theirs (App\Support\FormReservations). Nothing for any other row.
        FormReservations::renewForPage($row);

        $chargeRef = null;

        if ($charge->linked) {
            // Charged on another organisation's account, whose Stripe users read this
            // metadata: an opaque reference and the form, never the public uuid (a bearer
            // handle for the status read) and never a masjid id.
            $chargeRef = is_string($row->charge_ref) && $row->charge_ref !== '' ? $row->charge_ref : self::newChargeRef();

            $metadata = [
                'form_charge_ref' => $chargeRef,
                'form_id' => (string) $row->form_id,
            ];

            $paymentIntentData = [
                'metadata' => $metadata,
                'description' => self::linkedDescription($charge->organisation, $form),
            ];

            $suffix = self::statementSuffix($charge->organisation->name);

            if ($suffix !== null) {
                $paymentIntentData['statement_descriptor_suffix'] = $suffix;
            }
        } else {
            $metadata = [
                'form_response_uuid' => (string) $row->uuid,
                'masjid_id' => (string) $row->masjid_id,
                'form_id' => (string) $row->form_id,
            ];

            $paymentIntentData = ['metadata' => $metadata];
        }

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

        if ($charge->linked) {
            unset($params['client_reference_id']);

            // The webhook records a linked charge only for exactly the row's total in the
            // row's currency (FormResponsePaymentService::acceptPinned()). Adaptive Pricing,
            // switched on in the holder's own dashboard, would show and report a converted
            // price, and on the pinned API version (2024-06-20) the session and the payment
            // intent then carry the presentment currency: a family charged, a row left unpaid.
            $params['adaptive_pricing'] = ['enabled' => false];

            // KNOWN EXPOSURE (not closed here): success_url and cancel_url above still carry
            // the row's public uuid (FormPaymentReturn::urls()), and the holder's Stripe
            // users can read them on the session. Removing it needs the public form page to
            // recover the uuid from what it kept at submit; see DECISIONS.md 2026-09-15.
        }

        $email = self::prefillEmail($row->respondent_email);

        if ($email !== null) {
            $params['customer_email'] = $email;
        }

        try {
            $session = $this->create($row, $params, $charge, $chargeRef);
        } catch (InvalidRequestException $e) {
            // An account Stripe no longer lets the platform act on refuses the retry too.
            $pinnedCharge = $charge->linked || $row->hasChargePin();

            if (! isset($params['customer_email']) || ($pinnedCharge && self::isUnreachable($e))) {
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
            $session = $this->create($row, $params, $charge, $chargeRef);
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
     * A charge through another organisation's account, and any later page of a row that
     * was ever pinned, writes the pin in THE SAME save (DECISIONS.md 2026-09-15): the
     * account, its holder, the reference and the page's expiry. This is the only writer
     * of those columns. The key is dropped first when the account changes, because Stripe
     * scopes keys per account.
     *
     * @return array{id:?string,url:?string,payment_intent:?string}
     */
    private function create(FormResponse $row, array $params, FormCharge $charge, ?string $chargeRef): array
    {
        $pinned = $row->hasChargePin();
        $accountChanges = $pinned
            ? ! hash_equals((string) $row->charge_account_id, $charge->accountId)
            : $charge->linked;

        if ($accountChanges) {
            $row->idempotency_key = null;
        }

        $row->idempotency_key = $row->idempotency_key ?: ('form_response_' . Str::uuid());

        if ($pinned || $charge->linked) {
            $row->charge_account_id = $charge->accountId;
            $row->charge_masjid_id = (int) $charge->holder->id;
            $row->charge_expires_at = now()->setTimestamp((int) $params['expires_at']);

            if ($chargeRef !== null) {
                $row->charge_ref = $chargeRef;
            }
        }

        $row->save();

        try {
            return $this->createCheckoutSession($params, $charge->accountId, (string) $row->idempotency_key);
        } catch (ApiErrorException $e) {
            // A page about to be pinned to an account Stripe no longer lets the platform act
            // on: that account's holder is switched off once the transaction has ended, so the
            // next family is routed to the office instead of meeting the same failure.
            if (($pinned || $charge->linked) && self::isUnreachable($e)) {
                $this->noteUnreachable($charge->accountId, $row, $e);
            }

            throw $e;
        }
    }

    /** A random reference nothing else can derive: the only routing key a linked charge carries. */
    private static function newChargeRef(): string
    {
        return 'fcr_' . bin2hex(random_bytes(16));
    }

    /** "Burlington Islamic Sunday School — Registration 2026": whose payment this is, on the holder's dashboard. */
    private static function linkedDescription(Masjid $organisation, Form $form): string
    {
        $formName = trim((string) $form->name) !== '' ? trim((string) $form->name) : 'Registration';

        return Str::limit(trim((string) $organisation->name) . ' — ' . $formName, 500, '');
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
            // An account Stripe no longer lets the platform act on is not "a refused
            // close"; the caller decides what that means.
            if (self::isUnreachable($e)) {
                throw $e;
            }

            $now = $this->retrieveCheckoutSession($sessionId, $account)['status'] ?? null;

            if ($now === 'complete' || $now === 'expired') {
                return $now;
            }

            throw new FormCheckoutRefused('That payment page could not be closed. Try again in a moment.');
        }
    }

    /**
     * Refuse any account that is not a connected account's id, BEFORE Stripe is called.
     * An empty `stripe_account` sends the request to the platform's own account, which
     * would make the platform merchant of record.
     */
    private static function assertConnectedAccount(string $connectedAccountId): void
    {
        if (! FormChargeAccount::isAccount($connectedAccountId)) {
            throw new LogicException('A form payment page is only ever opened, read or closed on a connected account.');
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
        self::assertConnectedAccount($connectedAccountId);

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
        self::assertConnectedAccount($connectedAccountId);

        $this->stripe->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]);
    }

    /** @return array{status: string, url: ?string} Stripe's 'open' | 'complete' | 'expired'. */
    protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
    {
        self::assertConnectedAccount($connectedAccountId);

        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], [
            'stripe_account' => $connectedAccountId,
        ]);

        return ['status' => (string) $session->status, 'url' => $session->url];
    }
}
