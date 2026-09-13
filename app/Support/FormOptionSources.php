<?php

namespace App\Support;

use App\Models\Form;
use App\Models\FormResponse;
use Closure;

/**
 * Choice options that come from somewhere other than the form's own schema.
 *
 * A select / radio / checkboxGroup field may carry `optionsSource` instead of
 * `options`. The first and only source is 'school_meeting_days' (BISS's
 * cleaning-Sunday sign-up). A sourced field STORES NO OPTIONS: it holds a
 * reference, never a copy (.claude/rules/section-types.md), so closing a Sunday
 * on the calendar changes the form with no form edit.
 *
 * Every reader of `field.options` goes through resolve() or schema():
 *
 *   OFFER  open meeting days strictly after today (SchoolCalendar::offerableDays)
 *          — the public schema (SectionContentBinder::bindForm,
 *          OfferingPublicPayload) AND submit validation (FormSchema), so the
 *          page and both form doors agree.
 *   LABEL  every meeting day, closed ones with detail 'No school — reason'
 *          — FormInsights and FormNotifier, so an answer stays readable after
 *          its day passes or closes. Any ISO answer outside every year is
 *          still labelled by formatting it. FormRoster's breakdown never meets
 *          a sourced field: it reads the repeatable section's fields, where a
 *          source is refused, and a flat form's columns carry no options.
 *
 * AN EMPTY OFFER SET REFUSES EVERYTHING. No year yet, the year over, or every
 * remaining day closed: a non-empty answer gets NONE_OPEN and a required field
 * is not passed. FormSchema used to skip the in-list check when a field had no
 * options, which for a sourced field would have let any string through.
 */
final class FormOptionSources
{
    public const SCHOOL_MEETING_DAYS = 'school_meeting_days';

    /** key => what the builder calls it. */
    public const SOURCES = [
        self::SCHOOL_MEETING_DAYS => 'School calendar — meeting days',
    ];

    /** The field types a source may fill. */
    public const TYPES = ['select', 'radio', 'checkboxGroup'];

    public const OFFER = 'offer';

    public const LABEL = 'label';

    public const NONE_OPEN = 'No cleaning Sundays are open right now.';

    /** Some days are open, but fewer than the question asks a family to pick. */
    public const NOT_ENOUGH_OPEN = 'Not enough cleaning Sundays are open right now.';

    public const NO_LONGER_OPEN ='That day is no longer available — reload the form to see the current list.';

    /** A null optionsSource means typed options, exactly as if the key were absent. */
    public static function isSourced(mixed $field): bool
    {
        return is_array($field) && isset($field['optionsSource']);
    }

    /**
     * The options for one field. A field with no source gets its own typed
     * options back untouched, so callers need no branch.
     *
     * @param  array<string,mixed>  $field
     * @param  array<int,mixed>  $answers  LABEL only: answers already stored, so one outside the live set is still labelled
     * @return array<int,array<string,mixed>>
     */
    public static function resolve(Form $form, array $field, string $purpose, array $answers = []): array
    {
        if (! self::isSourced($field)) {
            return is_array($field['options'] ?? null) ? $field['options'] : [];
        }

        return self::fromSource(SchoolCalendar::for((int) $form->masjid_id), $field['optionsSource'], $purpose, $answers);
    }

    /**
     * The form's schema with every sourced field's options filled in. A form
     * with no sourced field comes back exactly as stored, and loads no calendar.
     */
    public static function schema(Form $form, string $purpose = self::OFFER): mixed
    {
        $schema = $form->schema;

        if (! is_array($schema) || ! is_array($schema['sections'] ?? null)) {
            return $schema;
        }

        $calendar = null;

        foreach ($schema['sections'] as $s => $section) {
            $fields = is_array($section) && is_array($section['fields'] ?? null) ? $section['fields'] : [];

            foreach ($fields as $f => $field) {
                if (! self::isSourced($field)) {
                    continue;
                }

                $calendar ??= SchoolCalendar::for((int) $form->masjid_id);
                $schema['sections'][$s]['fields'][$f]['options'] = self::fromSource($calendar, $field['optionsSource'], $purpose);
            }
        }

        return $schema;
    }

    /**
     * The member check for a sourced field, against the live offer set. A
     * closure rather than Rule::in so an EMPTY set still refuses, and says why.
     *
     * @param  array<int,string>  $values
     */
    public static function rule(array $values): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($values): void {
            if (is_string($value) && in_array($value, $values, true)) {
                return;
            }

            $fail($values === [] ? self::NONE_OPEN : self::NO_LONGER_OPEN);
        };
    }

    /**
     * How many of an organisation's submissions name any of these days in a
     * calendar-sourced field. Deleting a school year they name is refused
     * (SchoolCalendarController::destroyYear).
     *
     * @param  array<int,string>  $days
     */
    public static function answersNaming(int $masjidId, array $days): int
    {
        if ($days === []) {
            return 0;
        }

        $wanted = array_fill_keys($days, true);
        $count = 0;

        foreach (Form::query()->where('masjid_id', $masjidId)->get() as $form) {
            $names = collect(FormSchema::for($form)->allFields())
                ->filter(fn (array $row) => self::isSourced($row[2]))
                ->map(fn (array $row) => $row[2]['name'])
                ->unique()->values()->all();

            if ($names === []) {
                continue;
            }

            FormResponse::query()
                ->where('form_id', $form->id)
                ->select(['id', 'data'])
                ->chunkById(200, function ($responses) use ($names, $wanted, &$count): void {
                    foreach ($responses as $response) {
                        $data = is_array($response->data) ? $response->data : [];

                        foreach ($names as $name) {
                            foreach ((array) ($data[$name] ?? []) as $answer) {
                                if (is_string($answer) && isset($wanted[$answer])) {
                                    $count++;

                                    continue 3;
                                }
                            }
                        }
                    }
                });
        }

        return $count;
    }

    /**
     * @param  array<int,mixed>  $answers
     * @return array<int,array<string,mixed>>
     */
    private static function fromSource(SchoolCalendar $calendar, mixed $source, string $purpose, array $answers = []): array
    {
        // An unknown source offers nothing; ValidFormSchema refuses one at save.
        if ($source !== self::SCHOOL_MEETING_DAYS) {
            return [];
        }

        if ($purpose === self::OFFER) {
            return array_map(fn (string $day): array => [
                'value' => $day,
                'label' => SchoolCalendar::label($day),
            ], $calendar->offerableDays());
        }

        $options = [];

        foreach ($calendar->labelledDays() as $day) {
            $options[$day['date']] = ['value' => $day['date'], 'label' => SchoolCalendar::label($day['date'])]
                + ($day['closed'] ? ['detail' => 'No school — '.$day['reason']] : []);
        }

        foreach ($answers as $answer) {
            if (SchoolCalendar::isIsoDate($answer) && ! isset($options[$answer])) {
                $options[$answer] = ['value' => $answer, 'label' => SchoolCalendar::label($answer)];
            }
        }

        ksort($options, SORT_STRING);

        return array_values($options);
    }
}
