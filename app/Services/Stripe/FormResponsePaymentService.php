<?php

namespace App\Services\Stripe;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\FormNotifier;
use App\Support\FormReservations;
use Illuminate\Support\Facades\Log;

/**
 * The INBOUND (webhook) leg of a form response paid by card (DECISIONS.md 2026-09-11):
 * the fourth sibling, cloned from MealOrderPaymentService rather than refactored out
 * of it (.claude/rules/stripe-payments.md). It is the only thing that marks a card
 * registration paid, and the only moment that registration's receipt and coordinator
 * email go.
 *
 * DISPATCH SAFETY: `isFormResponseEvent()` is true only for an object carrying
 * `metadata.form_response_uuid` or `metadata.form_charge_ref`, which
 * FormResponseCheckoutService writes on the session AND the payment intent.
 * StripeWebhookController asks it after the order and registration questions and before
 * the donation default, so a form event can never book a Donation or touch a meal order
 * or a registration, and every other event keeps the route it had.
 *
 * TENANCY comes from `event.account`, never from metadata: the connected account names
 * the masjid (a trashed one too, so money for an offboarded organisation is still
 * recorded), and the uuid is looked up WITHIN it. A uuid presented on another
 * organisation's account is a miss.
 *
 * ## A row whose page was PINNED to an account (DECISIONS.md 2026-09-15)
 *
 * An organisation a SuperAdmin linked to its parent (BISS through Burlington Masjid) is
 * charged on the PARENT's account, whose own Stripe users can read and write metadata. So
 * a pinned row is matched strictly, and only ever by these rules:
 *
 *  - the event's account equals the row's pin (hash_equals);
 *  - a checkout.session.* is the page the app itself recorded on the row
 *    (`stripe_checkout_session_id`), for exactly the row's total and currency;
 *  - a payment_intent.* received exactly the row's total in its currency, and carries the
 *    row's own reference when it names one.
 *
 * Any mismatch is a warning and records NOTHING: no session id is adopted, no payment
 * intent written, the row never flips. A linked charge carries `form_charge_ref` (a
 * random per-row key), never the public uuid; a pinned row reached by uuid is held to the
 * same rules and NEVER takes the unpinned path below. Rows with no pin (every row before
 * 2026-09-15, every organisation that is not linked) take that path exactly as before,
 * warning texts included. Emails and branding are always the ROW's own organisation's.
 *
 * ORDER INDEPENDENCE: checkout.session.completed and payment_intent.succeeded both fire
 * for one payment, in either order, possibly more than once. Both end in
 * FormResponse::markPaid(), which re-reads the row under a lock and is true on the
 * unpaid→paid transition only, so whichever lands first sends the one receipt and the
 * one coordinator email, and the other changes nothing.
 *
 * What is recorded, what is refused, and why. Every refusal is a warning (production
 * logs at warning), and nothing here throws on bad data: a 500 makes Stripe retry an
 * event that can never succeed.
 *
 *  - "Paid" means the session says `payment_status = paid`. That is stricter than the
 *    siblings, which also accept `status = complete`: every checkout.session.completed
 *    carries `status: complete`, including one paid by a delayed method (a bank debit)
 *    whose money has not moved. Such a completion records the session id and nothing
 *    else; its payment_intent.succeeded settles the row when the money lands.
 *  - A card payment landing on a row that is not waiting for one (cash taken at the
 *    gate, a payment staff recorded, a row with no money leg) records the payment
 *    intent id, so the organisation can find the charge and refund it, and never flips
 *    the row.
 *  - A SECOND payment intent on a row already paid by card is the payer charged twice.
 *    The first stays on the row; the second is logged for the refund.
 *  - Money landing on a row an admin triaged 'cancelled' is recorded as paid (it did
 *    move) and said out loud, like a cancelled meal order. The payer still gets the
 *    receipt for their money.
 *  - A refund or a dispute of a PINNED row's charge, made in the holder's dashboard, only
 *    FLAGS the row (`charge_flag`; handleChargeFlag()). payment_status never moves.
 *
 * Pinned by tests/Feature/FormPaymentWebhookTest.php and FormLinkedWebhookTest.php.
 */
class FormResponsePaymentService
{
    private const KIND_SESSION = 'session';

    private const KIND_PAYMENT_INTENT = 'payment_intent';

    /** The routing signal: our uuid, or a linked charge's reference, in the object's metadata (top level). */
    public static function isFormResponseEvent(array $object): bool
    {
        return self::responseUuid($object) !== null || self::chargeRef($object) !== null;
    }

    /**
     * A completed Checkout Session. The session id is recorded when the row has none;
     * the row is settled only when the session is paid.
     */
    public function handleCheckoutCompleted(array $session, ?string $account): void
    {
        $resolved = $this->resolve($session, $account, self::KIND_SESSION);

        if ($resolved === null) {
            return;
        }

        [$row, $masjid] = $resolved;

        $this->recordSession($row, $this->stringOrNull($session['id'] ?? null));

        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $this->settle($row, $masjid, $this->stringOrNull($session['payment_intent'] ?? null));
    }

    /**
     * A succeeded PaymentIntent: the money has moved. Settles the row the session event
     * may already have settled, or lands first itself, or (for a delayed method) is the
     * only event that ever says the registration was paid.
     */
    public function handlePaymentIntentSucceeded(array $pi, ?string $account): void
    {
        $resolved = $this->resolve($pi, $account, self::KIND_PAYMENT_INTENT);

        if ($resolved === null) {
            return;
        }

        [$row, $masjid] = $resolved;

        $this->settle($row, $masjid, $this->stringOrNull($pi['id'] ?? null));
    }

    /**
     * charge.refunded / charge.dispute.created on the account a PINNED row was charged on
     * (DECISIONS.md 2026-09-15). The holder's Stripe users can refund a charge, and a
     * payer can dispute it, without anyone at the row's organisation knowing: the row is
     * flagged so its screen says to look. payment_status is never changed, and nothing
     * else is written. A refund also records how much is refunded so far
     * (`charge_refunded_minor`), because charge.refunded fires for a PARTIAL refund too.
     *
     * The row is found by the payment intent the event names, matched against the one the
     * verified webhook RECORDED on the row. That is the only lookup that hits in practice:
     * a webhook's charge and dispute carry `payment_intent` as a bare id, and a charge's
     * own metadata does not inherit the payment intent's `form_charge_ref`. A reference
     * the object does carry (an expanded payment intent, or metadata someone wrote on the
     * charge) is used only to say, at warning, that it named a row without its payment.
     *
     * Metadata never decides: the holder's Stripe users can write it. A row is flagged only
     * when all of these hold, else nothing is written:
     *
     *  - its pin equals the event's account;
     *  - it is paid, and has a payment intent recorded;
     *  - the event names that payment intent (hash_equals).
     *
     * A charge that names no pinned row (every donation, lunch order and registration
     * refund) is acked silently, exactly as before this arm existed.
     *
     * @param  string  $flag  FormResponse::CHARGE_FLAG_REFUNDED | CHARGE_FLAG_DISPUTED
     */
    public function handleChargeFlag(array $object, ?string $account, string $flag): void
    {
        $intent = $object['payment_intent'] ?? null;
        $ref = self::chargeRef(is_array($intent) ? $intent : []) ?? self::chargeRef($object);
        $intentId = is_array($intent) ? $this->stringOrNull($intent['id'] ?? null) : $this->stringOrNull($intent);

        $row = $intentId !== null
            ? FormResponse::query()->where('stripe_payment_intent_id', $intentId)->first()
            : null;

        $row ??= $ref !== null ? FormResponse::findByChargeRef($ref) : null;

        if ($row === null) {
            if ($ref !== null) {
                Log::warning('A refund or dispute named a form charge reference that no registration carries; nothing was flagged.', [
                    'flag' => $flag,
                    'account' => $account,
                    'payment_intent' => $intentId,
                ]);
            }

            return;
        }

        // Only a row charged on a pinned account is flagged. An unpinned row's charge sits on
        // its own organisation's account, whose admins refund it themselves.
        if (! $row->hasChargePin()) {
            return;
        }

        if (! is_string($account) || $account === '' || ! hash_equals((string) $row->charge_account_id, $account)) {
            Log::warning('A refund or dispute for a form registration arrived on an account its charge was not taken on; nothing was flagged.', $this->context($row) + [
                'flag' => $flag,
                'account' => $account,
                'charge_masjid_id' => $row->charge_masjid_id,
            ]);

            return;
        }

        // The payment the verified webhook recorded, and only that one: an unpaid row, or a
        // charge made outside the app carrying a copied reference, flags nothing.
        if (! $row->isPaid()
            || $intentId === null
            || $row->stripe_payment_intent_id === null
            || ! hash_equals((string) $row->stripe_payment_intent_id, $intentId)) {
            Log::warning('A refund or dispute named a form registration, but not the payment recorded on it; nothing was flagged.', $this->context($row) + [
                'flag' => $flag,
                'payment_intent' => $intentId,
                'recorded_payment_intent' => $row->stripe_payment_intent_id,
                'payment_status' => $row->payment_status,
            ]);

            return;
        }

        $refundedMinor = $flag === FormResponse::CHARGE_FLAG_REFUNDED ? $this->refundedMinor($object, $row) : null;

        if (! $row->flagCharge($flag, $refundedMinor)) {
            return;
        }

        Log::warning(
            $flag === FormResponse::CHARGE_FLAG_DISPUTED
                ? 'A card payment for a form registration, taken through another organisation\'s Stripe account, was disputed. '
                    . 'The registration is flagged; its payment status is unchanged.'
                : 'A card payment for a form registration, taken through another organisation\'s Stripe account, was refunded there. '
                    . 'The registration is flagged; its payment status is unchanged.',
            $this->context($row) + [
                'flag' => $flag,
                'charge_masjid_id' => $row->charge_masjid_id,
                'payment_intent' => $intentId,
                'refunded_minor' => $refundedMinor,
                'total_minor' => $row->total_minor,
            ]
        );
    }

    /** A refunded charge's amount_refunded in the row's currency, or null when it cannot be read as one. */
    private function refundedMinor(array $charge, FormResponse $row): ?int
    {
        $amount = $charge['amount_refunded'] ?? null;
        $currency = strtolower((string) ($charge['currency'] ?? ''));

        if (! is_int($amount) || $amount < 0 || $currency === '' || $currency !== strtolower((string) $row->currency)) {
            return null;
        }

        return $amount;
    }

    /**
     * Mark the row paid and, on the transition only, send the two emails. markPaid()
     * commits before it returns, so the mail goes after the row says paid.
     */
    private function settle(FormResponse $row, Masjid $masjid, ?string $paymentIntentId): void
    {
        if (! $row->markPaid($paymentIntentId)) {
            $this->explainNoTransition($row, $paymentIntentId);

            return;
        }

        if ($row->status === FormResponse::STATUS_CANCELLED) {
            Log::warning(
                'A cancelled form registration was paid on Stripe; it is recorded as paid. The organisation '
                . 'should refund it in its Stripe dashboard or restore the registration.',
                $this->context($row)
            );
        }

        // Paid after its lapsed date hold went to another payer (Ramadan giving,
        // 2026-09-25): recorded as paid like any payment and logged here; the receipt and
        // the coordinators' email notify() sends say the date could not be kept
        // (FormNotifier's lost date).
        FormReservations::notePaidAfterLosingDate($row);

        $this->notify($row, $masjid);
    }

    /**
     * markPaid() changed nothing. An online row already paid is the other success event
     * or a redelivery, and says nothing, unless it names a different payment. Any other
     * row was never waiting for a card.
     */
    private function explainNoTransition(FormResponse $row, ?string $paymentIntentId): void
    {
        if ($row->payment_method !== FormResponse::METHOD_ONLINE) {
            Log::warning(
                'A card payment landed on a form registration that is not paid by card; it was recorded but '
                . 'the registration was NOT changed. The organisation should refund it in its Stripe dashboard.',
                $this->context($row) + [
                    'payment_method' => $row->payment_method,
                    'payment_intent' => $paymentIntentId,
                ]
            );

            return;
        }

        $recorded = $row->stripe_payment_intent_id;

        if ($paymentIntentId !== null && $recorded !== null && $recorded !== $paymentIntentId) {
            Log::warning(
                'A second card payment landed on a form registration that was already paid; the first stays '
                . 'on the registration. The organisation should refund the second in its Stripe dashboard.',
                $this->context($row) + [
                    'payment_intent' => $paymentIntentId,
                    'recorded_payment_intent' => $recorded,
                ]
            );
        }
    }

    /**
     * The receipt ("Paid $X by card", with the group link) and the coordinator email,
     * through the same FormNotifier the free and cash entries use at submit.
     *
     * $masjid is the ROW's own organisation, never the holder of a pinned account: a
     * BISS registration charged through Burlington is emailed as BISS.
     *
     * An offboarded organisation's payment is recorded, but nobody is emailed on its
     * behalf (App\Support\PublicTenant): that organisation no longer runs anything here.
     */
    private function notify(FormResponse $row, Masjid $masjid): void
    {
        if ($masjid->trashed()) {
            Log::warning(
                'A form registration was paid on the account of an organisation that has been offboarded; it '
                . 'is recorded as paid, and nobody was emailed on the organisation\'s behalf.',
                $this->context($row)
            );

            return;
        }

        // Hand-filtered, because the webhook runs unbound. withTrashed: a form removed
        // after its page opened still names what was paid for.
        $form = Form::withTrashed()->where('masjid_id', $row->masjid_id)->whereKey($row->form_id)->first();

        if ($form === null) {
            Log::warning('A form registration was paid, but its form could not be found; nobody was emailed.', $this->context($row));

            return;
        }

        FormNotifier::submitted($form->setRelation('masjid', $masjid), $row);
    }

    /**
     * Resolve the row this event names, and the row's own organisation.
     *
     *  - A linked charge's reference: the row is found by it alone (it is random and
     *    unique), then held to the pinned rules (acceptPinned()).
     *  - A uuid: the organisation holding the event's account, the row by uuid WITHIN it,
     *    exactly as before. A row found that way which carries a pin is still held to the
     *    pinned rules, and never accepted by the account alone.
     *
     * Every refusal is logged; the one where money may have moved and nothing is recorded
     * is loud, because the merchant of record owns the refund.
     *
     * @return array{0: FormResponse, 1: Masjid}|null
     */
    private function resolve(array $object, ?string $account, string $kind): ?array
    {
        $ref = self::chargeRef($object);

        if ($ref !== null) {
            return $this->resolveByChargeRef($object, $account, $kind, $ref);
        }

        $uuid = self::responseUuid($object);

        if ($uuid === null) {
            return null;
        }

        if ($account === null || $account === '') {
            Log::warning('Form-payment event arrived without a connected account; ignoring.', [
                'form_response_uuid' => $uuid,
            ]);

            return null;
        }

        $masjid = Masjid::where('stripe_account_id', $account)->first()
            ?? Masjid::onlyTrashed()->where('stripe_account_id', $account)->first();

        if (! $masjid) {
            // A pinned row's uuid is never sent on a linked charge. One arriving here on an
            // account no organisation holds (the holder since offboarded and purged) is
            // still decided by its pin, never by this refusal alone.
            $pinned = FormResponse::query()->where('uuid', $uuid)->first();

            if ($pinned !== null && $pinned->hasChargePin()) {
                return $this->acceptPinned($pinned, $object, $account, $kind);
            }

            Log::warning('Form-payment event for an unknown connected account; ignoring.', [
                'form_response_uuid' => $uuid,
                'account' => $account,
            ]);

            return null;
        }

        $row = FormResponse::findByUuidForMasjid($uuid, (int) $masjid->id);

        if ($row === null) {
            Log::warning(
                'Form-payment event named a registration that does not belong to the organisation holding this '
                . 'connected account; NOTHING was recorded and Stripe will not retry. If money moved, the refund '
                . 'is the organisation\'s own action in its Stripe dashboard.',
                [
                    'form_response_uuid' => $uuid,
                    'masjid_id' => (int) $masjid->id,
                    'account' => $account,
                ]
            );

            return null;
        }

        // A row that was ever pinned never takes the unpinned path.
        if ($row->hasChargePin()) {
            return $this->acceptPinned($row, $object, $account, $kind);
        }

        return [$row, $masjid];
    }

    /**
     * A linked charge's reference: the row by reference, then the pinned rules.
     *
     * @return array{0: FormResponse, 1: Masjid}|null
     */
    private function resolveByChargeRef(array $object, ?string $account, string $kind, string $ref): ?array
    {
        if ($account === null || $account === '') {
            Log::warning('A linked form-payment event arrived without a connected account; nothing was recorded.', [
                'form_id' => $object['metadata']['form_id'] ?? null,
                'object' => $object['id'] ?? null,
            ]);

            return null;
        }

        $row = FormResponse::findByChargeRef($ref);

        if ($row === null) {
            Log::warning(
                'A linked form-payment event named a charge reference no registration carries; NOTHING was recorded. '
                . 'If money moved, the refund is the account holder\'s action in its Stripe dashboard.',
                [
                    'account' => $account,
                    'form_id' => $object['metadata']['form_id'] ?? null,
                    'object' => $object['id'] ?? null,
                ]
            );

            return null;
        }

        return $this->acceptPinned($row, $object, $account, $kind);
    }

    /**
     * The pinned rules (DECISIONS.md 2026-09-15). The row is accepted only when the event's
     * account is the row's pin AND the object is the page the app recorded (a session) or
     * the exact amount in the exact currency (a payment intent). The organisation handed
     * back is the ROW's own, withTrashed, never the account's holder.
     *
     * Two readings of "exact", both Stripe's own and neither writable through metadata:
     *
     *  - A session localised by Adaptive Pricing (linked sessions switch it off, but the
     *    holder's dashboard owns the default) reports its presentment currency at the top
     *    level on the pinned API version, and the row's currency and total in
     *    `currency_conversion` (source_currency, amount_total). When that block is present
     *    it is what is compared.
     *  - A payment intent whose id is the one already RECORDED on the row (by an event these
     *    rules accepted) is that same payment redelivered, and is accepted as it is.
     *
     * @return array{0: FormResponse, 1: Masjid}|null
     */
    private function acceptPinned(FormResponse $row, array $object, ?string $account, string $kind): ?array
    {
        $refuse = function (string $reason) use ($row, $object, $account, $kind): null {
            Log::warning(
                'A form-payment event did not match the page this registration\'s card payment was opened on; NOTHING '
                . 'was recorded and the registration was NOT changed. If money moved, the refund is the account '
                . 'holder\'s action in its Stripe dashboard.',
                $this->context($row) + [
                    'reason' => $reason,
                    'event_object' => $kind,
                    'object' => $object['id'] ?? null,
                    'account' => $account,
                    'charge_masjid_id' => $row->charge_masjid_id,
                ]
            );

            return null;
        };

        if (! $row->hasChargePin()) {
            return $refuse('not_pinned');
        }

        if (! is_string($account) || $account === '' || ! hash_equals((string) $row->charge_account_id, $account)) {
            return $refuse('account');
        }

        $objectId = $this->stringOrNull($object['id'] ?? null);
        $recordedIntent = $kind === self::KIND_PAYMENT_INTENT
            && $objectId !== null
            && $row->stripe_payment_intent_id !== null
            && hash_equals((string) $row->stripe_payment_intent_id, $objectId);

        if (! $recordedIntent) {
            [$currency, $amount] = $this->reportedTotal($object, $kind);

            if ($currency === '' || $currency !== strtolower((string) $row->currency)) {
                return $refuse('currency');
            }

            if ($kind === self::KIND_SESSION
                && ($objectId === null || $row->stripe_checkout_session_id === null
                    || ! hash_equals((string) $row->stripe_checkout_session_id, $objectId))) {
                return $refuse('session');
            }

            if (! is_int($amount) || $amount !== (int) $row->total_minor) {
                return $refuse('amount');
            }

            $ref = self::chargeRef($object);

            // DEFENSIVE ONLY: it cannot refuse today. An object carrying a reference was
            // routed to resolveByChargeRef(), which found this row BY that reference, and a
            // uuid-path object carries none. It stays so that a future route which finds a
            // row some other way can never settle it with another row's reference.
            if ($kind === self::KIND_PAYMENT_INTENT && $ref !== null
                && ($row->charge_ref === null || ! hash_equals((string) $row->charge_ref, $ref))) {
                return $refuse('reference');
            }
        }

        $organisation = Masjid::withTrashed()->find((int) $row->masjid_id);

        if ($organisation === null) {
            return $refuse('organisation_missing');
        }

        return [$row, $organisation];
    }

    /**
     * The session's handle, only while the row has none, in one statement: nothing else
     * on the row is written, so a success event handled at the same moment is never
     * overwritten. markPaid() re-reads the row, so the copy in hand needs no refresh.
     *
     * A pinned row reaches here only when the session IS the one recorded on it
     * (acceptPinned()), so a foreign session id is never adopted.
     */
    private function recordSession(FormResponse $row, ?string $sessionId): void
    {
        if ($sessionId === null || $row->stripe_checkout_session_id !== null || $row->hasChargePin()) {
            return;
        }

        FormResponse::query()
            ->whereKey($row->getKey())
            ->whereNull('stripe_checkout_session_id')
            ->update(['stripe_checkout_session_id' => $sessionId]);
    }

    /**
     * The currency and total an object reports in the INTEGRATION's terms: for a session,
     * `currency_conversion` (source_currency, amount_total) when Adaptive Pricing localised
     * it, else its own currency and amount_total; for a payment intent, its currency and
     * amount_received. The amount is null unless it is an integer.
     *
     * @return array{0: string, 1: ?int}
     */
    private function reportedTotal(array $object, string $kind): array
    {
        if ($kind === self::KIND_SESSION) {
            $conversion = $object['currency_conversion'] ?? null;

            if (is_array($conversion) && is_string($conversion['source_currency'] ?? null) && $conversion['source_currency'] !== '') {
                $amount = $conversion['amount_total'] ?? null;

                return [strtolower($conversion['source_currency']), is_int($amount) ? $amount : null];
            }

            $amount = $object['amount_total'] ?? null;
        } else {
            $amount = $object['amount_received'] ?? null;
        }

        return [strtolower((string) ($object['currency'] ?? '')), is_int($amount) ? $amount : null];
    }

    /** Ids only: never the payer's name or email. @return array<string,mixed> */
    private function context(FormResponse $row): array
    {
        return [
            'form_response_uuid' => $row->uuid,
            'form_response_id' => (int) $row->id,
            'masjid_id' => (int) $row->masjid_id,
            'form_id' => (int) $row->form_id,
        ];
    }

    /** Our uuid in the object's metadata, or null. Sessions and PaymentIntents both carry it top-level. */
    private static function responseUuid(array $object): ?string
    {
        $uuid = $object['metadata']['form_response_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** A linked charge's opaque reference in the object's metadata, or null. */
    private static function chargeRef(array $object): ?string
    {
        $ref = $object['metadata']['form_charge_ref'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    private function stringOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
