<?php

namespace App\Support;

use App\Models\Form;
use App\Models\FormResponse;
use Illuminate\Support\Collection;

/**
 * Flattens submissions into ONE ROW PER PERSON.
 *
 * The responses list answers "who filled in the form". An organiser running a camp needs
 * the other question — "who is actually coming" — and those are different numbers: one
 * parent submitting for four people is one response and four attendees. Without this you
 * have to open every registration to build a check-in sheet by hand.
 *
 * A form's repeatable section is the roster: each of its entries becomes a row, carried
 * alongside the registrant's contact details so a coordinator can reach whoever signed
 * that person up. A form WITHOUT a repeatable section still produces a roster — one row
 * per submission — so this works for an RSVP or a membership form too.
 *
 * Flattening happens in PHP rather than SQL because the entries live inside a JSON column
 * and JSON-path queries are not portable between MySQL (production) and SQLite (tests).
 * The practical bound is one form's registrations, which is hundreds of rows for a camp,
 * not millions.
 */
class FormRoster
{
    public function __construct(private readonly Form $form)
    {
    }

    public static function for(Form $form): self
    {
        return new self($form);
    }

    /**
     * The columns a roster table should show: the repeatable section's own fields, in the
     * order the form asks for them.
     *
     * @return array<int,array{key:string,label:string,type:string}>
     */
    public function columns(): array
    {
        $section = $this->form->repeatableSection();

        if (! $section) {
            // No repeatable section: the "person" is the registrant, so the roster's
            // columns are the form's flat fields.
            $columns = [];

            foreach ($this->form->sections() as $flat) {
                foreach ($flat['fields'] ?? [] as $field) {
                    if (isset($field['name'], $field['label'])) {
                        $columns[] = [
                            'key' => $field['name'],
                            'label' => $field['label'],
                            'type' => $field['type'] ?? 'text',
                        ];
                    }
                }
            }

            return $columns;
        }

        return collect($section['fields'] ?? [])
            ->filter(fn ($f) => isset($f['name'], $f['label']))
            ->map(fn ($f) => [
                'key' => $f['name'],
                'label' => $f['label'],
                'type' => $f['type'] ?? 'text',
            ])
            ->values()
            ->all();
    }

    /**
     * One row per attendee.
     *
     * @param  Collection<int,FormResponse>  $responses
     * @return Collection<int,array<string,mixed>>
     */
    public function rows(Collection $responses): Collection
    {
        $section = $this->form->repeatableSection();
        $sectionId = $section['id'] ?? null;
        $columns = collect($this->columns())->pluck('key')->all();

        $rows = collect();

        // Payment is read only on a form set up to take payment (Form::hasPaymentSettings()).
        // A fee form that never was, the camp, has no payment state to report, and reading
        // one would call every family on it "Unpaid".
        $money = $this->form->hasPaymentSettings();

        foreach ($responses as $response) {
            $data = $response->data ?? [];

            // Read as the door reads it, with no query per row for the form.
            $response->setRelation('form', $this->form);

            // Shared context so every attendee row can be acted on without opening the
            // submission it came from — including, at a paying event, whether this
            // person's registration is paid and whether their bracelets have gone out
            // (DECISIONS.md 2026-09-11).
            $context = [
                'response_id' => $response->id,
                'registered_by' => $response->respondent_name,
                'registrant_email' => $response->respondent_email,
                'registrant_phone' => $response->respondent_phone,
                'status' => $response->status,
                'submitted_at' => optional($response->submitted_at)->toIso8601String(),
                'payment_status' => $money ? $response->paymentState() : null,
                'payment_method' => $response->payment_method,
                'payment' => $money ? self::paymentLabel($response) : '',
                'holder' => self::holder($response),
                'collected_at' => optional($response->collected_at)->toIso8601String(),
            ];

            if (! $sectionId) {
                $rows->push(array_merge($context, [
                    'entry_index' => 0,
                    'values' => collect($columns)
                        ->mapWithKeys(fn ($k) => [$k => $this->scalar($data[$k] ?? null)])
                        ->all(),
                ]));

                continue;
            }

            $entries = $data[$sectionId] ?? [];

            if (! is_array($entries) || $entries === []) {
                // A submission with no entries would otherwise vanish from the roster,
                // hiding a registration that still needs chasing.
                $rows->push(array_merge($context, [
                    'entry_index' => 0,
                    'values' => collect($columns)->mapWithKeys(fn ($k) => [$k => null])->all(),
                    'incomplete' => true,
                ]));

                continue;
            }

            foreach ($entries as $index => $entry) {
                $rows->push(array_merge($context, [
                    'entry_index' => $index,
                    'values' => collect($columns)
                        ->mapWithKeys(fn ($k) => [
                            $k => $this->scalar(is_array($entry) ? ($entry[$k] ?? null) : null),
                        ])
                        ->all(),
                ]));
            }
        }

        return $rows;
    }

    /**
     * Head-count summaries an organiser reads before anything else: how many people, and
     * how they split across each choice field (the brothers/sisters split, for a camp).
     *
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function summary(Collection $rows): array
    {
        $section = $this->form->repeatableSection();
        $fields = collect($section['fields'] ?? $this->columns());

        $breakdowns = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? null;
            $name = $field['name'] ?? $field['key'] ?? null;

            if (! $name || ! in_array($type, ['select', 'radio'], true)) {
                continue;
            }

            $counts = $rows
                ->map(fn ($row) => $row['values'][$name] ?? null)
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->countBy();

            $breakdowns[] = [
                'field' => $name,
                'label' => $field['label'] ?? $name,
                'options' => collect($field['options'] ?? [])
                    ->map(fn ($o) => [
                        'value' => $o['value'] ?? '',
                        'label' => $o['label'] ?? ($o['value'] ?? ''),
                        'count' => (int) ($counts[$o['value'] ?? ''] ?? 0),
                    ])
                    ->all(),
            ];
        }

        return [
            'people' => $rows->count(),
            'submissions' => $rows->pluck('response_id')->unique()->count(),
            'breakdowns' => $breakdowns,
        ];
    }

    /**
     * The payment badge in words, for the roster and its CSV: "Paid by card", "Cash
     * (Najd Haddad)", "Paid elsewhere", "Unpaid" — or blank when nothing was ever owed.
     */
    public static function paymentLabel(FormResponse $response): string
    {
        $state = $response->paymentState();

        if ($state === null) {
            return '';
        }

        if ($state === FormResponse::PAYMENT_UNPAID) {
            return 'Unpaid';
        }

        return match ($response->payment_method) {
            FormResponse::METHOD_ONLINE => 'Paid by card',
            FormResponse::METHOD_CASH => ($holder = self::holder($response)) !== null ? "Cash ({$holder})" : 'Cash',
            FormResponse::METHOD_EXTERNAL => 'Paid elsewhere',
            default => 'Paid',
        };
    }

    /**
     * Who holds a cash registration's money: the code's holder for an entry made at the
     * gate, or the admin who took it at the table. Null for every other row.
     */
    public static function holder(FormResponse $response): ?string
    {
        if ($response->payment_method !== FormResponse::METHOD_CASH) {
            return null;
        }

        return $response->staffCode?->holder_name ?? $response->markedPaidBy?->name;
    }

    /** JSON can hold arrays (checkbox groups) and booleans; a table cell needs a string. */
    private function scalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(
                fn ($v) => is_scalar($v) ? (string) $v : '',
                $value
            ));
        }

        return $value;
    }
}
