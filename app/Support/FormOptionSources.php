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
 *
 * ## 'reservable_dates' (Ramadan giving through forms, 2026-09-25)
 *
 * The second source: the form's OWN date list (settings.reservation), less every
 * date another payer holds (App\Support\FormReservations). OFFER is the dates open
 * now, from today on the organisation's clock; LABEL is every listed or reserved
 * date. It needs no calendar and no capability, so the builder's source picker does
 * not list it (BUILDER_SOURCES): the list has no editor there, and a form arrives
 * with it through form:import.
 */
final class FormOptionSources
{
    public const SCHOOL_MEETING_DAYS = 'school_meeting_days';

    /** The form's own reservable dates, less those already held (FormReservations). */
    public const RESERVABLE_DATES = 'reservable_dates';

    /** key => what the builder calls it. Every source a stored schema may name. */
    public const SOURCES = [
        self::SCHOOL_MEETING_DAYS => 'School calendar — meeting days',
        self::RESERVABLE_DATES => 'This form\'s reservable dates',
    ];

    /**
     * The sources the builder offers to pick. RESERVABLE_DATES is not one: its date list
     * (settings.reservation) has no editor in the builder, and a question drawing on an
     * empty list refuses every answer.
     */
    public const BUILDER_SOURCES = [self::SCHOOL_MEETING_DAYS];

    /** The field types a source may fill. */
    public const TYPES = ['select', 'radio', 'checkboxGroup'];

    /** The field types the date list may fill: one date per registration. */
    public const RESERVABLE_TYPES = ['select', 'radio'];

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

        if ($field['optionsSource'] === self::RESERVABLE_DATES) {
            return self::reservableDates($form, $purpose, $answers);
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

                if ($field['optionsSource'] === self::RESERVABLE_DATES) {
                    $schema['sections'][$s]['fields'][$f]['options'] = self::reservableDates($form, $purpose);

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
     * closure rather than Rule::in so an EMPTY set still refuses, and says why,
     * in the words of the source it came from.
     *
     * @param  array<int,string>  $values
     */
    public static function rule(array $values, mixed $source = null): Closure
    {
        $none = self::noneOpen($source);
        $gone = $source === self::RESERVABLE_DATES ? FormReservations::NO_LONGER_OPEN : self::NO_LONGER_OPEN;

        return static function (string $attribute, mixed $value, Closure $fail) use ($values, $none, $gone): void {
            if (is_string($value) && in_array($value, $values, true)) {
                return;
            }

            $fail($values === [] ? $none : $gone);
        };
    }

    /** What a question whose source offers nothing says: no cleaning Sundays, or no dates. */
    public static function noneOpen(mixed $source): string
    {
        return $source === self::RESERVABLE_DATES ? FormReservations::NONE_OPEN : self::NONE_OPEN;
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
            // Calendar questions only: a form's own reservable dates are not meeting days.
            $names = collect(FormSchema::for($form)->allFields())
                ->filter(fn (array $row) => self::isSourced($row[2]) && $row[2]['optionsSource'] === self::SCHOOL_MEETING_DAYS)
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
     * The form's own date list: the dates open now for OFFER, every listed or reserved
     * date (and the answers passed in) for LABEL.
     *
     * @param  array<int,mixed>  $answers
     * @return array<int,array<string,mixed>>
     */
    private static function reservableDates(Form $form, string $purpose, array $answers = []): array
    {
        if ($purpose === self::OFFER) {
            return array_map(fn (string $date): array => [
                'value' => $date,
                'label' => FormReservations::label($date),
            ], FormReservations::offerable($form));
        }

        return FormReservations::labelled($form, $answers);
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
