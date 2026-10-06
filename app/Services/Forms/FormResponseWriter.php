<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Support\FormAttachments;
use App\Support\FormDateTaken;
use App\Support\FormReservations;
use App\Support\FormSchema;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes one form response row: the record, and nothing that decides whether the
 * record may be written.
 *
 * This is the body of the public submit's transaction (FormSubmissionsController::store)
 * from "build the row" to "attach the uploads", moved here so a second caller, the
 * universal cart, can write the same row without going through the door.
 *
 * ## Gates are NOT here, on purpose
 *
 * Everything that asks "may this be recorded?" stays with the caller, in the caller's
 * own order and under its own lock: the form is open (`acceptsSubmissions()`), it is
 * inside its window, it is not at capacity, the date is free (`FormReservations::claim()`),
 * the card is allowed, the replay lookup, the staff code, the per-order caps.
 *
 * That split is the point. The public door asks its gates and then writes. The cart
 * writes only AFTER the shopper has paid, when the money has already been taken: a
 * payment that lands after the form closed, or at its last place, is still a payment,
 * and the row that records it must exist. So this class never reads the window, the
 * capacity, `is_active` or the card switch, and a caller that re-checked them here
 * would turn a paid ticket into money with no record.
 *
 * The one write that CAN refuse is the date reservation's unique index, when a
 * `$reserveOn` is given (FormReservations::hold() throws FormDateTaken). The door
 * claims the date before it gets here, so it never meets that. A caller that has not
 * claimed the date (the cart) passes no `$reserveOn`.
 *
 * ## What the caller owes this method
 *
 *  - Call it inside a DB transaction that holds the form's row lock
 *    (`Form::whereKey()->lockForUpdate()`), and pass the LOCKED row. The counter
 *    `forms.response_count` moves in the model's `created` hook, so it must move under
 *    the lock every capacity check is read under. A call outside a transaction is a bug
 *    and throws, rather than writing a row the counter cannot protect.
 *  - Pass answers already cleaned by `FormSchema::only()` and a price already worked out
 *    by `FormPayment::quote()`. Nothing is re-priced or re-validated here: the row keeps
 *    the integer-cents snapshot it is charged from, and this is not a second opinion.
 *
 * ## What this never does
 *
 *  - No Stripe: it opens, reads and closes no session or payment intent. A card row is
 *    written UNPAID with no `stripe_checkout_session_id`; payment is recorded later by
 *    FormResponse::markPaid().
 *  - No email and no notification. A form row is never emailed while it is unpaid
 *    (.claude/rules/stripe-payments.md); the caller sends after `markPaid()` returns true.
 *  - No cash settlement. A staff cash entry is settled by the door (`settleCash()`) after
 *    this returns, because it moves the staff code's use_count under the code's own lock.
 *
 * Pinned by tests/Feature/Cart/FormResponseWriterTest.php (the cart's call) and, for
 * the door, by the unchanged tests/Feature/FormSubmissionTest.php,
 * FormPaymentCheckoutTest.php, FormStaffCodeTest.php and FormDateReservationTest.php.
 */
final class FormResponseWriter
{
    /** No money leg: a form that takes no payment (payment_method stays NULL). */
    public const LEG_NONE = 'none';

    /** The staff CASH leg: cash its holder owes. The door settles it after the write. */
    public const LEG_STAFF = 'staff';

    /** A card registration: written UNPAID, snapshot Stripe is charged from. */
    public const LEG_ONLINE = 'online';

    /** A family paying the office: written UNPAID with no card fee. */
    public const LEG_OFFICE = 'office';

    /**
     * Write the row, its date hold, and its uploads.
     *
     * @param  Form  $locked  the form row, read under `lockForUpdate()` by the caller
     * @param  FormSchema  $schema  FormSchema::for($form): counts entries, prices legacy amount_due, reads identity
     * @param  array<string,mixed>  $clean  answers after FormSchema::only()
     * @param  string  $leg  one of the LEG_* constants
     * @param  array<string,mixed>|null  $quote  FormPayment::quote(); required for every leg but LEG_NONE
     * @param  array{device_id?:?string,ip_address?:?string,user_agent?:?string}  $origin  request details for the audit columns; empty for a caller with no request
     * @param  string|null  $clientKey  the replay guard's key; unique per form, so a second write under it is refused by the index
     * @param  array<string,mixed>  $fingerprint  what a replay is compared with (stored hashed); used only with a key
     * @param  string|null  $reserveOn  a date already claimed by the caller (FormReservations::claim()); null for none
     * @param  array<string,mixed>  $uploads  FormSchema::uploads(); empty for the cart
     *
     * @throws FormDateTaken when `$reserveOn` is given and the unique index refuses the hold
     * @throws LogicException when called outside a transaction, or with a money leg and no quote
     */
    public function write(
        Form $locked,
        FormSchema $schema,
        array $clean,
        string $leg,
        ?array $quote,
        array $origin = [],
        ?string $clientKey = null,
        array $fingerprint = [],
        ?string $reserveOn = null,
        array $uploads = [],
        array $staffEntry = [],
    ): FormResponse {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('FormResponseWriter::write() must run inside the transaction that holds the form lock.');
        }

        if ($leg !== self::LEG_NONE && $quote === null) {
            throw new LogicException("A form response with a '{$leg}' money leg needs the price it was quoted at.");
        }

        $created = new FormResponse(array_merge(
            [
                'form_id' => $locked->id,
                'masjid_id' => $locked->masjid_id,
                'data' => $clean,
                'entry_count' => $schema->entryCount($clean),
                'amount_due' => $schema->amountDue($clean),
                'status' => 'new',
                'device_id' => $origin['device_id'] ?? null,
                'ip_address' => $origin['ip_address'] ?? null,
                'user_agent' => $origin['user_agent'] ?? null,
                'submitted_at' => now(),
            ],
            $schema->identity($clean)
        ));

        // Not fillable: the replay guard's pair, and — for a staff entry — the
        // cents snapshot the cash is settled from (App\Support\FormPayment).
        $guarded = [];

        if ($clientKey !== null) {
            $guarded['client_submission_key'] = $clientKey;
            $guarded['client_payload_hash'] = FormResponse::payloadHash($fingerprint);
        }

        if ($leg === self::LEG_STAFF) {
            $guarded['amount_due_minor'] = $quote['amount_due_minor'];
            $guarded['currency'] = $quote['currency'];
        }

        // The breakdown the money leg was priced at (unit x quantity, and the tier
        // or level), beside the amount it multiplies to, for the receipt and the
        // admin view. Written wherever amount_due_minor is.
        if ($quote !== null && $leg !== self::LEG_NONE) {
            $guarded += [
                'unit_price_minor' => $quote['unit_minor'],
                'price_quantity' => $quote['quantity'],
                'price_label' => $quote['tier_label'] !== null ? mb_substr($quote['tier_label'], 0, 255) : null,
            ];
        }

        // A card registration is written UNPAID, with the snapshot Stripe is
        // charged from. Only the signed webhook moves it to paid.
        if ($leg === self::LEG_ONLINE) {
            $guarded += [
                'payment_method' => FormResponse::METHOD_ONLINE,
                'payment_status' => FormResponse::PAYMENT_UNPAID,
                'currency' => $quote['currency'],
                'amount_due_minor' => $quote['amount_due_minor'],
                'fee_covered_minor' => $quote['fee_covered_minor'],
                'total_minor' => $quote['total_minor'],
            ];
        }

        // A family paying the office is written UNPAID, owing the list price:
        // no card was offered a fee, so none is covered, and the total is what
        // is owed. Staff settle it by hand when the money comes.
        if ($leg === self::LEG_OFFICE) {
            $guarded += [
                'payment_method' => FormResponse::METHOD_OFFICE,
                'payment_status' => FormResponse::PAYMENT_UNPAID,
                'currency' => $quote['currency'],
                'amount_due_minor' => $quote['amount_due_minor'],
                'fee_covered_minor' => 0,
                'total_minor' => $quote['amount_due_minor'],
            ];
        }

        if ($leg === self::LEG_STAFF || $staffEntry !== []) {
            $guarded += $staffEntry;
            // Staff quotes are refreshed under the lock, even without audit fields; the decimal must agree.
            $guarded['amount_due'] = intdiv($quote['amount_due_minor'], 100).'.'.str_pad((string) ($quote['amount_due_minor'] % 100), 2, '0', STR_PAD_LEFT);
        }

        $created->forceFill($guarded)->save();

        // The hold, in the transaction that wrote the row: a card registration's
        // lapses with its payment page (FormReservations::cardHoldUntil()); cash,
        // the office and a form that takes no payment never lapse. The unique
        // index refusing it rolls the row back (FormDateTaken, answered by the door).
        if ($reserveOn !== null) {
            FormReservations::hold($created, $reserveOn, $leg === self::LEG_ONLINE ? FormReservations::cardHoldUntil(now()) : null);
        }

        // Inside the transaction, and only once the row exists: a submission
        // that arrives after the last place is taken returns before the caller
        // ever gets here, without touching the disk, and a write that fails here
        // rolls the response back (FormAttachments removes what it had written).
        $names = FormAttachments::store($created, $uploads);

        if ($names !== []) {
            // The respondent's filenames go back into `data` under their
            // field names, so the admin table and the CSV export have a cell
            // to render — the bytes stay on the private disk, reachable only
            // through the authenticated download endpoint.
            $created->update(['data' => array_merge($clean, $names)]);
        }

        return $created;
    }

    /**
     * The row an earlier write under this client_submission_key made on this form, or
     * null. For a caller that keys its writes (the cart's `cart:item:<id>`) and must
     * answer a replayed event with the first row rather than write a second: the unique
     * (form_id, client_submission_key) index is the backstop, this is the polite answer.
     * Read it under the form lock, before write().
     */
    public function earlier(int $formId, string $clientKey): ?FormResponse
    {
        return FormResponse::query()
            ->where('form_id', $formId)
            ->where('client_submission_key', $clientKey)
            ->first();
    }
}
