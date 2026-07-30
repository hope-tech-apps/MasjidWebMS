<?php

namespace App\Support;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
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
    /** A settings array is admin-editable; this stops a typo becoming a mail blast. */
    private const MAX_RECIPIENTS = 20;

    /**
     * Fire both notifications for an accepted submission.
     *
     * Deliberately returns void and swallows everything: the caller has already told the
     * submitter their registration was received, and that statement is true regardless of
     * what happens to the email.
     */
    public static function submitted(Form $form, FormResponse $response): void
    {
        $masjid = $form->relationLoaded('masjid')
            ? $form->masjid
            : Masjid::find($form->masjid_id);

        $people = self::people($form, $response);

        self::attempt('coordinators', $form, $response, function () use ($form, $response, $masjid, $people) {
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
                entryCount: (int) $response->entry_count,
                amountLine: self::amountLine($form, $response),
                tierLabel: self::tierLabel($form),
                people: $people,
                adminUrl: self::adminUrl(),
            ));
        });

        self::attempt('receipt', $form, $response, function () use ($form, $response, $masjid, $people) {
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
                entryCount: (int) $response->entry_count,
                amountLine: self::amountLine($form, $response),
                tierLabel: self::tierLabel($form),
                people: $people,
                title: $settings['successTitle'] ?? null,
                body: $settings['successBody'] ?? null,
                nextSteps: array_values(array_filter(
                    (array) ($settings['successNextSteps'] ?? []),
                    fn ($step) => is_string($step) && trim($step) !== ''
                )),
                paymentNote: is_string($settings['paymentNote'] ?? null) ? $settings['paymentNote'] : null,
                replyTo: $masjid?->email,
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
            ->map(function (array $row) use ($nameKeys, $detailColumns, $columns) {
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
                    ->map(function (array $column) use ($values) {
                        $value = trim((string) ($values[$column['key']] ?? ''));

                        return $value === '' ? null : $column['label'] . ' ' . $value;
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

        $currency = $form->feeRule()['currency'] ?? 'USD';
        $amount = number_format((float) $response->amount_due, 2);

        return $currency === 'USD' ? '$' . $amount : $amount . ' ' . $currency;
    }

    /** "Early bird", so a coordinator can see which price this registration locked in. */
    private static function tierLabel(Form $form): ?string
    {
        $label = $form->feeRule()['currentTier']['label'] ?? null;

        return is_string($label) && trim($label) !== '' ? $label : null;
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
