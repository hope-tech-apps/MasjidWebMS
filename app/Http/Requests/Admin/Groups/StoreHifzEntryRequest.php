<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Models\HifzEntry;
use App\Support\QuranIndex;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Record ONE recitation heard from ONE student (T-014).
 *
 * ## The range is checked against the muṣḥaf, not merely against itself
 *
 * `min:1|max:114` keeps a surah number inside the muṣḥaf, but nothing declarative
 * can say that al-Fātiḥah has no ayah 300 or that 5:82 comes after 2:255. Those
 * are the two mistakes a teacher actually makes on a phone at the end of a
 * ḥalaqa — a fat-fingered ayah and a range typed in backwards — and both are
 * refused here, in the after-validation pass, using App\Support\QuranIndex:
 *
 *   - every ayah must exist in ITS OWN surah;
 *   - the range must run FORWARDS through the muṣḥaf, compared as absolute ayah
 *     ordinals so a range that crosses surahs (46:1 .. 51:30, i.e. juz 26) is
 *     ordinary rather than a special case.
 *
 * The checks run only once the shape checks have passed, so a missing field
 * reports "required" rather than a confusing complaint about surah 0.
 *
 * ## `whole_surah`: a complete sūrah without typing its āyāt
 *
 * A teacher hearing all of An-Nabaʾ should not have to know it has 40 āyāt
 * (BISS teachers, 2026-09-21). With `whole_surah` true and `from_surah` given,
 * the range is FILLED IN here, before validation, as `from_surah:1 ..
 * from_surah:<last>`, the last āyah read from QuranIndex. Nothing is stored
 * that says "whole": the row is an ordinary range that happens to cover the
 * sūrah, so HifzProgress, the parent's view and every listing read it exactly
 * as they would the same range typed by hand, and `serialize()` derives the
 * flag back from the coordinates.
 *
 * If the client ALSO sends a range, it must be that same full range. A client
 * that says "whole sūrah" and "āyāt 1-10" in one request is confused, and
 * recording either reading would be a guess about a child's record.
 *
 * `membership_id` is only SHAPE-checked. Whether it names a participant of THIS
 * ḥalaqa is settled in HifzEntriesController through the tenant-scoped relation
 * — so another organization's id is a miss. Re-implementing the tenant filter in
 * an `exists:` rule would duplicate the guardrail
 * (.claude/rules/tenant-scoping.md) and, worse, would confirm the existence of a
 * row the caller may not see.
 *
 * `recited_at` is optional and may be in the past (a teacher entering the
 * morning's ḥalaqa after ʿaṣr), but never in the future: a recitation that has
 * not happened yet is a mistake, not an instruction — and here it would also
 * plant a future entry at the head of the sabak history the student's current
 * position is derived from.
 *
 * The TEACHER is not accepted from the client: the authenticated account is
 * recorded as having heard the recitation, the same call T-013 made for
 * `awarded_by_user_id`. masjid_id is not accepted either, and never will be —
 * the BelongsToMasjid creating hook stamps it from the bound tenant.
 */
class StoreHifzEntryRequest extends BaseFormRequest
{
    /** Set when `whole_surah` was asked for and a sent range contradicts it. */
    private ?string $wholeSurahConflict = null;

    protected function prepareForValidation(): void
    {
        if (! $this->has('whole_surah')) {
            return;
        }

        // The SPA posts form-encoded, so a checkbox arrives as "true"/"false",
        // which Laravel's `boolean` rule refuses (.claude/rules/shipping.md).
        // NULL_ON_FAILURE leaves real nonsense for the rule to reject.
        $whole = filter_var($this->input('whole_surah'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($whole === null) {
            return;
        }

        $this->merge(['whole_surah' => $whole]);

        $surah = filter_var($this->input('from_surah'), FILTER_VALIDATE_INT);

        if (! $whole || $surah === false || ! QuranIndex::isSurah($surah)) {
            // Not asked for, or no real sūrah to fill in from: the ordinary
            // rules report what is missing.
            return;
        }

        $full = [
            'from_surah' => $surah,
            'from_ayah' => 1,
            'to_surah' => $surah,
            'to_ayah' => QuranIndex::ayahsIn($surah),
        ];

        foreach (['from_ayah', 'to_surah', 'to_ayah'] as $key) {
            if ($this->filled($key) && (int) $this->input($key) !== $full[$key]) {
                $this->wholeSurahConflict = $key;
            }
        }

        if ($this->wholeSurahConflict === null) {
            $this->merge($full);
        }
    }

    public function rules(): array
    {
        $maxMistakes = max(1, (int) config('groups.hifz.max_mistakes', 100));

        return [
            'membership_id' => 'required|integer',
            'kind' => ['required', Rule::in(HifzEntry::KINDS)],

            'from_surah' => 'required|integer|min:1|max:' . count(QuranIndex::SURAHS),
            'from_ayah' => 'required|integer|min:1',
            'to_surah' => 'required|integer|min:1|max:' . count(QuranIndex::SURAHS),
            'to_ayah' => 'required|integer|min:1',
            'whole_surah' => 'sometimes|boolean',

            'quality' => ['required', Rule::in(HifzEntry::QUALITIES)],

            'major_mistakes' => 'sometimes|integer|min:0|max:' . $maxMistakes,
            'minor_mistakes' => 'sometimes|integer|min:0|max:' . $maxMistakes,

            'note' => 'nullable|string|max:' . (int) config('groups.hifz.max_note_length', 1000),
            'recited_at' => 'nullable|date|before_or_equal:now',
        ];
    }

    /**
     * The semantic half of the range check — everything Rule objects cannot say.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->wholeSurahConflict !== null) {
                $surah = (int) $this->input('from_surah');
                $validator->errors()->add($this->wholeSurahConflict, sprintf(
                    'Whole surah was ticked, which means %s 1 to %d, but a different range was sent as well.',
                    QuranIndex::name($surah) ?? $surah,
                    QuranIndex::ayahsIn($surah) ?? 0
                ));

                return;
            }

            // Only meaningful once all four coordinates are present integers in
            // range; otherwise the shape errors already say what is wrong.
            if ($validator->errors()->hasAny(['from_surah', 'from_ayah', 'to_surah', 'to_ayah'])) {
                return;
            }

            $fromSurah = (int) $this->input('from_surah');
            $fromAyah = (int) $this->input('from_ayah');
            $toSurah = (int) $this->input('to_surah');
            $toAyah = (int) $this->input('to_ayah');

            $from = QuranIndex::ordinal($fromSurah, $fromAyah);
            $to = QuranIndex::ordinal($toSurah, $toAyah);

            if ($from === null) {
                $validator->errors()->add('from_ayah', sprintf(
                    'Surat %s has %d ayahs, so there is no ayah %d.',
                    QuranIndex::name($fromSurah) ?? $fromSurah,
                    QuranIndex::ayahsIn($fromSurah) ?? 0,
                    $fromAyah
                ));
            }

            if ($to === null) {
                $validator->errors()->add('to_ayah', sprintf(
                    'Surat %s has %d ayahs, so there is no ayah %d.',
                    QuranIndex::name($toSurah) ?? $toSurah,
                    QuranIndex::ayahsIn($toSurah) ?? 0,
                    $toAyah
                ));
            }

            if ($from !== null && $to !== null && $to < $from) {
                $validator->errors()->add(
                    'to_ayah',
                    'A recitation range must run forwards through the mushaf; the end comes before the start.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'recited_at.before_or_equal' => 'A recitation cannot be dated in the future.',
        ];
    }
}
