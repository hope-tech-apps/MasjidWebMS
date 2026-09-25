<?php

namespace App\Support;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Services\Stripe\FormChargeAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the two emails a submission should produce: one to the masjid's coordinators, one
 * back to whoever filled the form in.
 *
 * Without this, a registration only exists on a screen nobody is obliged to open. For a
 * camp with a price that steps up on a date, "nobody checked the admin panel for four
 * days" is a real cost, so the notification is part of accepting the submission — not a
 * nicety.
 *
 * Two rules govern everything here:
 *
 *  1. **Mail must never cost a registration.** The response row is already committed
 *     before this runs, and every send is wrapped: a bad SMTP credential, a malformed
 *     recipient, a queue outage — all of it degrades to a log line. A person who filled in
 *     sixty fields correctly does not get an error because Resend was down.
 *
 *  2. **Health details never leave the admin screen.** A camp form collects allergies,
 *     medical conditions and medications. Those belong behind a login, not in an inbox
 *     that gets forwarded. So the coordinator email carries only what identifies a
 *     registration and lets someone act on it — who registered, how to reach them, who is
 *     attending, how many, how much is owed — and links to the rest. Concretely: names and
 *     the structured answers (numbers, dropdown choices) travel; free-text answers, which
 *     is where health details actually live, never do.
 */
class FormNotifier
{
    /** The coordinators' payment line for a family that chose to pay the office and has not yet. */
    public const OWED_AT_THE_OFFICE = 'Owed — paying the office';

    /** A settings array is admin-editable; this stops a typo becoming a mail blast. */
    private const MAX_RECIPIENTS = 20;

    /**
     * Fire both notifications for an accepted submission.
     *
     * "Accepted" is the submit for a free entry or cash taken at the gate, and the payment
     * for a card registration: the signed webhook calls this on the unpaid→paid transition
     * (App\Services\Stripe\FormResponsePaymentService). A card registration still waiting
     * on Stripe is not accepted yet, so it is never emailed. A receipt then would confirm
     * a registration that may never be paid for.
     *
     * Once a registration is paid, both emails say how ("Paid $30.87 by card", "Paid in
     * cash", "Paid (recorded by staff)"), and the receipt carries the WhatsApp group link
     * only when the registration is settled (FormResponse::isSettled()), never to someone
     * who still owes.
     *
     * Deliberately returns void and swallows everything: the caller has already told the
     * submitter their registration was received (or Stripe has taken their money), and that
     * statement is true regardless of what happens to the email.
     *
     * $toCoordinators is false when the coordinators were already told about this
     * registration at submit: a Wix payer an admin marks paid at the door, or cash taken
     * at the table for a registration made with no money leg. The payer still gets the
     * paid receipt, which is where the group link travels in the fallback (festival
     * brief, blocker 5); the coordinators do not hear about the same person twice.
     *
     * ## A family paying the office (BISS, 2026-09-13)
     *
     * The one unpaid money leg that IS emailed at submit: nothing else will ever tell
     * that family their registration arrived. Both emails state the amount owed (the
     * "Total due" line, since there is no payment line yet), and the receipt's note is
     * the office's own instructions (settings.payment.officeInstructions), falling back
     * to the form's payment note. Nothing settled-only rides along: no payment line and
     * no group link (isSettled() is false). When staff record the money,
     * FormResponsesController::settleByHand() sends the paid receipt with
     * $toCoordinators false, so the coordinators hear about the family once.
     */
    public static function submitted(Form $form, FormResponse $response, bool $toCoordinators = true): void
    {
        if ($response->hasMoneyLeg() && ! $response->isPaid() && ! self::owesTheOffice($response)) {
            return;
        }

        $masjid = $form->relationLoaded('masjid')
            ? $form->masjid
            : Masjid::find($form->masjid_id);

        try {
            $people = self::people($form, $response);
        } catch (\Throwable $e) {
            // The attendee list is a courtesy; the emails are not. Without this, a schema
            // the roster cannot read would 500 a submit that is already committed, or a
            // webhook whose payment is already recorded (whose retry then sends nothing).
            Log::error('Form notification could not list the attendees; sending without them.', [
                'form_id' => $form->id,
                'response_id' => $response->id,
                'masjid_id' => $form->masjid_id,
                'exception' => $e->getMessage(),
            ]);

            $people = [];
        }

        $paymentLine = self::paymentLine($response);

        // The coordinators' copy of a family paying the office says it still owes, in the
        // payment slot (money review, 2026-09-14). The family's receipt does not: it states
        // "Total due" with the office's instructions instead.
        // Drawn as owed, never in the paid green, with the amount labelled "Amount owed".
        $owedAtOffice = $paymentLine === null && self::owesTheOffice($response);
        $coordinatorPaymentLine = $paymentLine ?? ($owedAtOffice ? self::OWED_AT_THE_OFFICE : null);

        self::attempt('coordinators', $form, $response, function () use ($form, $response, $masjid, $people, $coordinatorPaymentLine, $owedAtOffice, $toCoordinators) {
            if (! $toCoordinators) {
                return;
            }

            $recipients = self::coordinatorRecipients($form, $masjid);

            if ($recipients === []) {
                // Worth a log line rather than silence: a form with nowhere to notify is
                // usually a configuration mistake, and it is invisible from the outside.
                Log::warning('Form submission has no notification recipients.', [
                    'form_id' => $form->id,
                    'masjid_id' => $form->masjid_id,
                ]);

                return;
            }

            Mail::to($recipients)->send(new FormResponseSubmitted(
                responseId: $response->id,
                formName: $form->name,
                masjidName: $masjid?->name ?? 'your masjid',
                registrantName: $response->respondent_name,
                registrantEmail: $response->respondent_email,
                registrantPhone: $response->respondent_phone,
                entryCount: self::entryCount($form, $response),
                amountLine: self::amountLine($form, $response),
                tierLabel: self::tierLabel($form, $response),
                people: $people,
                adminUrl: self::adminUrl(),
                paymentLine: $coordinatorPaymentLine,
                paymentOwed: $owedAtOffice,
                breakdownLine: self::breakdownLine($response),
                reservedDate: self::reservedDate($response),
            ));
        });

        self::attempt('receipt', $form, $response, function () use ($form, $response, $masjid, $people, $paymentLine) {
            if (! self::receiptsEnabled($form)) {
                return;
            }

            $to = $response->respondent_email;

            if (! is_string($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $settings = $form->settings ?? [];

            Mail::to($to)->send(new FormSubmissionReceipt(
                responseId: $response->id,
                formName: $form->name,
                masjidName: $masjid?->name ?? 'your masjid',
                registrantName: $response->respondent_name,
                entryCount: self::entryCount($form, $response),
                amountLine: self::amountLine($form, $response),
                tierLabel: self::tierLabel($form, $response),
                people: $people,
                title: $settings['successTitle'] ?? null,
                body: $settings['successBody'] ?? null,
                nextSteps: array_values(array_filter(
                    (array) ($settings['successNextSteps'] ?? []),
                    fn ($step) => is_string($step) && trim($step) !== ''
                )),
                // How to pay is noise once paid, and under the Wix fallback it is the pay-here
                // link: restated on a paid receipt, it invites a second payment. A family
                // paying the office is told the office's own instructions instead.
                paymentNote: $paymentLine === null
                    ? (self::owesTheOffice($response) ? $form->officeInstructions() : null)
                        ?? (is_string($settings['paymentNote'] ?? null) ? $settings['paymentNote'] : null)
                    : null,
                masjidEmail: $masjid?->email,
                paymentLine: $paymentLine,
                whatsappUrl: self::whatsappUrl($form, $response),
                whatsappLabel: self::whatsappLabel($form),
                breakdownLine: self::breakdownLine($response),
                reservedDate: self::reservedDate($response),
            ));
        });
    }

    /**
     * Who hears about a submission.
     *
     * `settings.notifyEmails` names the people actually running the event. When it is not
     * set the masjid's own contact address is used instead, so a form can never be
     * configured into silently notifying nobody — the failure mode this whole class exists
     * to remove.
     *
     * @return array<int,string>
     */
    public static function coordinatorRecipients(Form $form, ?Masjid $masjid): array
    {
        $declared = $form->settings['notifyEmails'] ?? null;

        // Accept both a list and the comma-separated string an admin is likely to type.
        $candidates = is_string($declared)
            ? preg_split('/[,;\s]+/', $declared) ?: []
            : (is_array($declared) ? $declared : []);

        $emails = collect($candidates)
            ->filter(fn ($email) => is_string($email))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->take(self::MAX_RECIPIENTS)
            ->values()
            ->all();

        if ($emails !== []) {
            return $emails;
        }

        $fallback = $masjid?->email;

        return is_string($fallback) && filter_var($fallback, FILTER_VALIDATE_EMAIL)
            ? [strtolower(trim($fallback))]
            : [];
    }

    /**
     * The attendees, reduced to what is safe to put in an inbox.
     *
     * Built from FormRoster so it stays consistent with the roster screen — one entry of
     * the repeatable section is one person; a form without one produces a single row.
     * Only name-like text, numbers and dropdown choices are carried across. Free text is
     * dropped wholesale, because that is where "peanut allergy, carries an EpiPen" lives.
     *
     * @return array<int,array{name:string,detail:string}>
     */
    public static function people(Form $form, FormResponse $response): array
    {
        $roster = FormRoster::for($form);
        $columns = $roster->columns();
        $optionLabels = self::optionLabels($form);

        $nameKeys = [];
        $detailColumns = [];

        foreach ($columns as $column) {
            $type = $column['type'] ?? 'text';
            $isName = $type === 'text' && preg_match('/name/i', $column['key']) === 1;

            if ($isName) {
                $nameKeys[] = $column['key'];

                continue;
            }

            if (in_array($type, ['number', 'select', 'radio'], true)) {
                $detailColumns[] = $column;
            }
        }

        return $roster->rows(collect([$response]))
            ->map(function (array $row) use ($nameKeys, $detailColumns, $columns, $optionLabels) {
                $values = $row['values'] ?? [];

                $name = collect($nameKeys)
                    ->map(fn ($key) => trim((string) ($values[$key] ?? '')))
                    ->filter()
                    ->implode(' ');

                if ($name === '') {
                    // No name-like column at all (an RSVP keyed on email, say) — fall back
                    // to the first column so the row is still identifiable.
                    $first = $columns[0]['key'] ?? null;
                    $name = $first ? trim((string) ($values[$first] ?? '')) : '';
                }

                $detail = collect($detailColumns)
                    ->map(function (array $column) use ($values, $optionLabels) {
                        $value = trim((string) ($values[$column['key']] ?? ''));

                        if ($value === '') {
                            return null;
                        }

                        // A dropdown stores its value ("brothers"); a coordinator should
                        // read the label they wrote on the form ("Brothers").
                        $value = $optionLabels[$column['key']][$value] ?? $value;

                        return $column['label'] . ' ' . $value;
                    })
                    ->filter()
                    ->implode(' · ');

                return [
                    'name' => $name === '' ? 'Unnamed attendee' : mb_substr($name, 0, 120),
                    'detail' => mb_substr($detail, 0, 160),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * value => label for every choice field in the schema, keyed by field name.
     *
     * @return array<string,array<string,string>>
     */
    private static function optionLabels(Form $form): array
    {
        $map = [];

        foreach ($form->sections() as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! isset($field['name']) || ! is_array($field)) {
                    continue;
                }

                // Typed options as stored, or a calendar-sourced question's days.
                foreach (FormOptionSources::resolve($form, $field, FormOptionSources::LABEL) as $option) {
                    if (isset($option['value'])) {
                        $map[$field['name']][(string) $option['value']] =
                            (string) ($option['label'] ?? $option['value']);
                    }
                }
            }
        }

        return $map;
    }

    /** Whether the person who submitted gets a copy. On unless a form opts out. */
    private static function receiptsEnabled(Form $form): bool
    {
        return ($form->settings['confirmationEmail'] ?? true) !== false;
    }

    /**
     * The stored total, rendered for a human. Null when the form charges nothing, so an
     * RSVP does not announce "$0.00 due".
     *
     * Reads `amount_due` off the response rather than recomputing it: the price tier may
     * have stepped up since, and the email must restate what this person actually owes.
     */
    private static function amountLine(Form $form, FormResponse $response): ?string
    {
        if ($response->amount_due === null) {
            return null;
        }

        $currency = self::feeAtSubmit($form, $response)['currency'] ?? 'USD';
        $amount = number_format((float) $response->amount_due, 2);

        return $currency === 'USD' ? '$' . $amount : $amount . ' ' . $currency;
    }

    /**
     * How a paid registration was paid, for a human, or null while nothing has been paid
     * (a free form, a legacy row, a Wix payer staff have not marked yet).
     *
     * A card states what Stripe charged, the card fee included, because that can differ
     * from the price shown beside it. Cash and a payment staff recorded state only how:
     * the amount is that price. A payment staff recorded names how it came when they
     * said so ("Paid by Zelle (recorded by staff)"; FormResponse::PAID_VIA).
     */
    public static function paymentLine(FormResponse $response): ?string
    {
        if (! $response->isPaid()) {
            return null;
        }

        $via = FormResponse::PAID_VIA_LABELS[$response->paid_via] ?? null;

        // A card payment taken on another organisation's account (BISS through Burlington
        // Masjid; DECISIONS.md 2026-09-15) says whose name the card statement carries.
        $processedBy = '';

        if ($response->payment_method === FormResponse::METHOD_ONLINE && $response->isChargedThroughAnotherOrg()) {
            $holder = FormChargeAccount::chargedThrough($response);
            $processedBy = $holder !== null ? " (processed by {$holder->name})" : '';
        }

        return match ($response->payment_method) {
            FormResponse::METHOD_ONLINE => ($response->total_minor !== null
                ? 'Paid ' . self::money((int) $response->total_minor, $response->currency) . ' by card'
                : 'Paid by card') . $processedBy,
            FormResponse::METHOD_CASH => 'Paid in cash',
            FormResponse::METHOD_EXTERNAL => $via !== null ? "Paid by {$via} (recorded by staff)" : 'Paid (recorded by staff)',
            default => null,
        };
    }

    /**
     * "People registered" on the emails: the row's entry count, except on a form priced by
     * a quantity question or by answer (Ramadan giving, 2026-09-25), where there is no
     * list of people and the count would read 1 for four people's Zakat-ul-Fitr. There
     * the breakdown line says how many, and 0 hides the row.
     */
    private static function entryCount(Form $form, FormResponse $response): int
    {
        $fee = $form->feeRule($response->submitted_at);

        if (isset($fee['perQuantityOf']) || ($fee['pricing'] ?? null) === Form::PRICING_CHOICE) {
            return 0;
        }

        return (int) $response->entry_count;
    }

    /**
     * "$17.00 × 4", from the snapshot the row was written at (FormResponse::priceBreakdown()),
     * when more than one unit was charged. Null otherwise: one unit is the amount itself.
     */
    private static function breakdownLine(FormResponse $response): ?string
    {
        $breakdown = $response->priceBreakdown();

        if ($breakdown === null || $breakdown['quantity'] < 2) {
            return null;
        }

        return self::money($breakdown['unit_minor'], $breakdown['currency']) . ' × ' . $breakdown['quantity'];
    }

    /**
     * The date this registration holds from its form's list, for a human, or null: none
     * reserved, or its hold went to another payer (FormReservations), when the email must
     * not promise a date that is no longer theirs.
     */
    private static function reservedDate(FormResponse $response): ?string
    {
        $reservation = FormReservations::of($response);

        return $reservation !== null && $reservation->isHolding() ? FormReservations::label($reservation->date()) : null;
    }

    /** An unpaid registration whose family chose to pay the office. */
    private static function owesTheOffice(FormResponse $response): bool
    {
        return $response->payment_method === FormResponse::METHOD_OFFICE && ! $response->isPaid();
    }

    /**
     * The WhatsApp group link, for a settled registration only (paid, or nothing was ever
     * owed). A card payer mid-checkout, or a Wix payer staff have not marked yet, gets it
     * in the receipt sent when the payment is recorded.
     */
    private static function whatsappUrl(Form $form, FormResponse $response): ?string
    {
        $url = $form->whatsappUrl();

        return $url !== null && $response->setRelation('form', $form)->isSettled() ? $url : null;
    }

    private static function whatsappLabel(Form $form): ?string
    {
        $label = $form->settings['whatsappLabel'] ?? null;

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    /**
     * Integer cents for a human ("$30.87", "30.87 CAD"), with no float ever holding the
     * amount. Public for the triage answer that names a card payment
     * (FormResponsesController), so the admin reads it as the payer's receipt states it.
     */
    public static function money(int $minor, ?string $currency): string
    {
        $code = strtoupper(trim((string) $currency)) ?: 'USD';
        $amount = number_format(intdiv($minor, 100)) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return $code === 'USD' ? '$' . $amount : $amount . ' ' . $code;
    }

    /**
     * "Early bird" or "3 children", so a coordinator can see which price this
     * registration locked in: FormPayment::quote() over the row's own answers at its
     * submitted_at, the reading the Stripe line is named from.
     *
     * Only when that quote still reproduces what the row owes (its cents snapshot, or the
     * decimal on a row without one). If the admin has edited the prices since, the label
     * of today's schedule would sit beside yesterday's amount, so there is none: the
     * FormResponseCheckoutService::lineItems() rule (money review, 2026-09-14).
     */
    private static function tierLabel(Form $form, FormResponse $response): ?string
    {
        $data = is_array($response->data) ? $response->data : [];

        try {
            $quote = FormPayment::quote($form, $data, false, false, $response->submitted_at);
        } catch (\LogicException) {
            return null; // a price the form can no longer state
        }

        $owed = $response->owedMinor();

        return $quote !== null && $owed !== null && $quote['amount_due_minor'] === $owed ? $quote['tier_label'] : null;
    }

    /**
     * The fee rule as it stood when this registration was made.
     *
     * A payment can be recorded days after the submit (the webhook, "Take cash", "Mark
     * paid (external)"), when a later tier is in force, and the emails restate the price
     * this person registered at, not today's. It is the instant
     * FormResponseCheckoutService::lineItems() names the Stripe line from, so the email and
     * the hosted page name the same tier. A row with no submitted_at is read as of now, as
     * every row was before payments moved these emails to payment time.
     *
     * @return array<string,mixed>|null
     */
    private static function feeAtSubmit(Form $form, FormResponse $response): ?array
    {
        return $form->feeRule($response->submitted_at);
    }

    private static function adminUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/masjid/form-responses';
    }

    /** Runs a send, converting any failure into a log line. @param callable():void $send */
    private static function attempt(string $kind, Form $form, FormResponse $response, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::error("Form {$kind} notification failed.", [
                'form_id' => $form->id,
                'response_id' => $response->id,
                'masjid_id' => $form->masjid_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
