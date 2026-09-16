<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Letters\CurriculumRegistry;
use Illuminate\Validation\Rule;

class MarkDrillRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Validated against the CLASS'S STAGE in the controller, not here:
            // a drill id alone cannot be judged without knowing what the room is
            // working on, and accepting one from a later stage would put a mark
            // in a cell the progress bar never counts. It cannot be judged
            // without knowing the ALPHABET either, and for the same reason —
            // `ba` is a drill on one track and nothing at all on the other.
            'drill_id' => ['required', 'string', 'max:40'],
            'status' => ['required', Rule::in(ArabicCurriculum::STATUSES)],

            // Absent means Arabic: every client that existed before the English
            // track sends no alphabet, and their marks must keep landing on the
            // qāʿidah. The allowlist is what stops a typo minting a third
            // alphabet in a plain varchar column.
            'alphabet' => ['nullable', 'string', Rule::in(CurriculumRegistry::ALPHABETS)],

            // What the teacher wants to say about this child on this drill.
            // Optional, and an ABSENT key is not the same as an empty one: a
            // client that never sends the field must not wipe a note somebody
            // typed, so the controller only writes when the key is present.
            // `nullable` rather than `sometimes` alone, because clearing a note
            // deliberately is a thing a teacher must be able to do.
            'note' => ['sometimes', 'nullable', 'string', 'max:' . (int) config('groups.arabic.max_note_length', 1000)],
        ];
    }
}
