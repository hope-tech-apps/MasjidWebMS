<?php

namespace App\Services\Stripe;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\FormNotifier;
use Illuminate\Support\Facades\Log;

/**
 * The INBOUND (webhook) leg of a form response paid by card (DECISIONS.md 2026-09-11):
 * the fourth sibling, cloned from MealOrderPaymentService rather than refactored out
 * of it (.claude/rules/stripe-payments.md). It is the only thing that marks a card
 * registration paid, and the only moment that registration's receipt and coordinator
 * email go.
 *
 * DISPATCH SAFETY: `isFormResponseEvent()` is true only for an object carrying
 * `metadata.form_response_uuid`, which FormResponseCheckoutService writes on the
 * session AND the payment intent. StripeWebhookController asks it after the order and
 * registration questions and before the donation default, so a form event can never
 * book a Donation or touch a meal order or a registration, and every other event keeps
 * the route it had.
 *
 * TENANCY comes from `event.account`, never from metadata: the connected account names
 * the masjid (a trashed one too, so money for an offboarded organisation is still
 * recorded), and the uuid is looked up WITHIN it. A uuid presented on another
 * organisation's account is a miss.
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
 *
 * Pinned by tests/Feature/FormPaymentWebhookTest.php.
 */
class FormResponsePaymentService
{
    /** The routing signal: our uuid in the object's metadata (top level). */
    public static function isFormResponseEvent(array $object): bool
    {
        return self::responseUuid($object) !== null;
    }

    /**
     * A completed Checkout Session. The session id is recorded when the row has none;
     * the row is settled only when the session is paid.
     */
    public function handleCheckoutCompleted(array $session, ?string $account): void
    {
        $resolved = $this->resolve($session, $account);

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
        $resolved = $this->resolve($pi, $account);

        if ($resolved === null) {
            return;
        }

        [$row, $masjid] = $resolved;

        $this->settle($row, $masjid, $this->stringOrNull($pi['id'] ?? null));
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
     * Resolve the row this event names, tenant-safely: the masjid comes from the
     * connected account the event was raised on, and the row by uuid WITHIN that masjid.
     * Every refusal is logged; the one where money may have moved and nothing is
     * recorded is loud, because the organisation (merchant of record) owns the refund.
     *
     * @return array{0: FormResponse, 1: Masjid}|null
     */
    private function resolve(array $object, ?string $account): ?array
    {
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

        return [$row, $masjid];
    }

    /**
     * The session's handle, only while the row has none, in one statement: nothing else
     * on the row is written, so a success event handled at the same moment is never
     * overwritten. markPaid() re-reads the row, so the copy in hand needs no refresh.
     */
    private function recordSession(FormResponse $row, ?string $sessionId): void
    {
        if ($sessionId === null || $row->stripe_checkout_session_id !== null) {
            return;
        }

        FormResponse::query()
            ->whereKey($row->getKey())
            ->whereNull('stripe_checkout_session_id')
            ->update(['stripe_checkout_session_id' => $sessionId]);
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

    private function stringOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
