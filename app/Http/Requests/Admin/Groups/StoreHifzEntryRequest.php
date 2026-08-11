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
