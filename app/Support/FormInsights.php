<?php

namespace App\Support;

use App\Models\Form;
use Illuminate\Support\Collection;

/**
 * Manara Insights — what a masjid can actually learn from a form's responses.
 *
 * DELIBERATELY DETERMINISTIC. No model call, no third party.
 *
 * Form responses are the most sensitive data this platform holds: for a camp
 * registration they are children's names, ages, allergies, medical conditions and
 * current medications. Sending that to an external API to have a paragraph written about
 * it is a bad trade — the masjid gains a sentence and loses control of a minor's medical
 * record. So the questions an organiser actually asks ("how many are coming, what's the
 * brothers/sisters split, how much is still unpaid, who hasn't confirmed") are answered
 * here in SQL and PHP, exactly, every time.
 *
 * What is summarised is bounded on purpose:
 *   - choice fields (select / radio / checkbox / checkboxGroup) become label counts,
 *   - numeric fields become min / max / average / distribution buckets,
 *   - free text and the identity fields are NEVER read.
 * A name, an email, a phone number, an allergy or a medication cannot reach this output,
 * because nothing here ever looks at a field of those kinds.
 *
 * Insights are gated on the Assistant entitlement (the `assistant` middleware) because
 * that is the tier the product owner sells them in — not because they need a model.
 */
class FormInsights
{
    /** Numeric fields are bucketed rather than listed, so an age cannot identify a child. */
    private const AGE_BUCKETS = [
        ['label' => 'Under 13', 'min' => 0, 'max' => 12],
        ['label' => '13–17', 'min' => 13, 'max' => 17],
        ['label' => '18–34', 'min' => 18, 'max' => 34],
        ['label' => '35–54', 'min' => 35, 'max' => 54],
        ['label' => '55+', 'min' => 55, 'max' => 200],
    ];

    /** A distribution with very few contributors identifies people; suppress it. */
    private const MIN_GROUP_FOR_BREAKDOWN = 3;

    public function __construct(private readonly Form $form)
    {
    }

    public static function for(Form $form): self
    {
        return new self($form);
    }

    /**
     * @param  Collection<int,\App\Models\FormResponse>  $responses
     * @return array<string,mixed>
     */
    public function build(Collection $responses): array
    {
        return [
            'totals' => $this->totals($responses),
            'by_status' => $this->byStatus($responses),
            'timeline' => $this->timeline($responses),
            'breakdowns' => $this->breakdowns($responses),
            'capacity' => $this->capacity($responses),
            'privacy_note' => 'Insights are calculated on this server from choice and numeric answers only. '
                . 'Names, contact details and free-text answers such as allergies or medical notes are never included, '
                . 'and no response data is sent to any outside service.',
        ];
    }

    /** @param Collection<int,\App\Models\FormResponse> $responses */
    private function totals(Collection $responses): array
    {
        $entries = (int) $responses->sum('entry_count');

        return [
            'responses' => $responses->count(),
            // For a camp, "people coming" and "forms submitted" are very different
            // numbers, and the one organisers need is the former.
            'entries' => $entries,
            'average_entries_per_response' => $responses->count() > 0
                ? round($entries / $responses->count(), 2)
                : 0,
            'amount_due_total' => round((float) $responses->sum('amount_due'), 2),
            'amount_due_outstanding' => round(
                (float) $responses->whereIn('status', ['new', 'waitlisted'])->sum('amount_due'),
                2
            ),
        ];
    }

    /** @param Collection<int,\App\Models\FormResponse> $responses */
    private function byStatus(Collection $responses): array
    {
        $counts = $responses->groupBy('status')->map->count();

        return collect(\App\Models\FormResponse::STATUSES)
            ->map(fn (string $status) => [
                'status' => $status,
                'responses' => (int) ($counts[$status] ?? 0),
                'entries' => (int) $responses->where('status', $status)->sum('entry_count'),
            ])
            ->all();
    }

    /**
     * Submissions per day, so an organiser can see whether registration has stalled.
     *
     * @param  Collection<int,\App\Models\FormResponse>  $responses
     */
    private function timeline(Collection $responses): array
    {
        return $responses
            ->groupBy(fn ($r) => optional($r->submitted_at)->format('Y-m-d') ?? 'unknown')
            ->map(fn ($group, $day) => [
                'date' => $day,
                'responses' => $group->count(),
                'entries' => (int) $group->sum('entry_count'),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * Counts per option for every CHOICE field, and buckets for every NUMBER field.
     *
     * Nothing else is read. Text, textarea, email, tel, date and file fields are
     * skipped entirely — that is what keeps allergies, medications and the name of
     * somebody's résumé out of this payload.
     *
     * @param  Collection<int,\App\Models\FormResponse>  $responses
     */
    private function breakdowns(Collection $responses): array
    {
        $out = [];

        foreach ($this->form->sections() as $section) {
            $sectionId = $section['id'] ?? null;
            $repeatable = ! empty($section['repeatable']);

            foreach ($section['fields'] ?? [] as $field) {
                $type = $field['type'] ?? null;
                $name = $field['name'] ?? null;

                if (! $name) {
                    continue;
                }

                $values = $this->valuesFor($responses, $sectionId, $repeatable, $name);

                if (in_array($type, ['select', 'radio', 'checkbox', 'checkboxGroup'], true)) {
                    $breakdown = $this->choiceBreakdown($field, $values);
                } elseif ($type === 'number') {
                    $breakdown = $this->numberBreakdown($values);
                } else {
                    // text / textarea / email / tel / date / file — never summarised.
                    continue;
                }

                if ($breakdown === null) {
                    continue;
                }

                $out[] = array_merge([
                    'field' => $name,
                    'label' => $field['label'] ?? $name,
                    'section' => $section['title'] ?? $sectionId,
                    'type' => $type,
                ], $breakdown);
            }
        }

        return $out;
    }

    /**
     * Every submitted value for one field, flattening the rows of a repeatable section.
     *
     * @param  Collection<int,\App\Models\FormResponse>  $responses
     * @return array<int,mixed>
     */
    private function valuesFor(Collection $responses, ?string $sectionId, bool $repeatable, string $name): array
    {
        $values = [];

        foreach ($responses as $response) {
            $data = $response->data ?? [];

            if ($repeatable && $sectionId) {
                foreach ($data[$sectionId] ?? [] as $row) {
                    if (is_array($row) && array_key_exists($name, $row)) {
                        $values[] = $row[$name];
                    }
                }

                continue;
            }

            if (array_key_exists($name, $data)) {
                $values[] = $data[$name];
            }
        }

        return $values;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<string,mixed>|null
     */
    private function choiceBreakdown(array $field, array $values): ?array
    {
        $type = $field['type'];

        if ($type === 'checkbox') {
            $yes = collect($values)->filter(fn ($v) => $v === true || $v === 1 || $v === '1')->count();
            $total = count($values);

            if ($total < self::MIN_GROUP_FOR_BREAKDOWN) {
                return null;
            }

            return [
                'answered' => $total,
                'options' => [
                    ['value' => 'yes', 'label' => 'Yes', 'count' => $yes],
                    ['value' => 'no', 'label' => 'No', 'count' => $total - $yes],
                ],
            ];
        }

        // checkboxGroup values are arrays; flatten so each selection counts once.
        $flat = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $flat[] = $v;
                }
            } elseif ($value !== null && $value !== '') {
                $flat[] = $value;
            }
        }

        if (count($values) < self::MIN_GROUP_FOR_BREAKDOWN) {
            return null;
        }

        $counts = collect($flat)->countBy();

        $options = collect($field['options'] ?? [])
            ->map(fn ($option) => [
                'value' => $option['value'] ?? '',
                'label' => $option['label'] ?? ($option['value'] ?? ''),
                'count' => (int) ($counts[$option['value'] ?? ''] ?? 0),
            ])
            ->all();

        return [
            'answered' => count($values),
            'options' => $options,
        ];
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<string,mixed>|null
     */
    private function numberBreakdown(array $values): ?array
    {
        $numbers = collect($values)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v)
            ->values();

        if ($numbers->count() < self::MIN_GROUP_FOR_BREAKDOWN) {
            return null;
        }

        $buckets = collect(self::AGE_BUCKETS)->map(fn (array $bucket) => [
            'label' => $bucket['label'],
            'count' => $numbers->filter(fn ($n) => $n >= $bucket['min'] && $n <= $bucket['max'])->count(),
        ])->all();

        return [
            'answered' => $numbers->count(),
            'min' => $numbers->min(),
            'max' => $numbers->max(),
            'average' => round($numbers->avg(), 1),
            'buckets' => $buckets,
        ];
    }

    /** @param Collection<int,\App\Models\FormResponse> $responses */
    private function capacity(Collection $responses): ?array
    {
        if ($this->form->capacity === null) {
            return null;
        }

        $entries = (int) $responses->sum('entry_count');

        return [
            'capacity' => $this->form->capacity,
            'responses' => $this->form->response_count,
            'entries' => $entries,
            'remaining' => max(0, $this->form->capacity - $this->form->response_count),
            'percent_full' => $this->form->capacity > 0
                ? min(100, (int) round(($this->form->response_count / $this->form->capacity) * 100))
                : null,
        ];
    }
}
