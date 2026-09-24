<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;
use App\Support\Letters\CurriculumRegistry;
use Illuminate\Validation\Rule;

/**
 * Mark a set of drills mastered for one child in one call (teacher feedback,
 * BISS 2026-09-21: a child who already knows their letters should not cost the
 * teacher a hundred taps).
 *
 * ## Why `scope` exists
 *
 * This used to take the track and nothing else, on the stated ground that
 * "WHICH drills is not the client's to say: it is the class's stage". That was
 * true of the rows written and false of the words above the button, which read
 * "Mark all N remaining “Long Vowels” drills": the class's stage is CUMULATIVE,
 * so on the last stage the action marked all 336 drills — every letter, short
 * vowel, sukun, shadda and tanween — under a sentence naming one stage of five.
 * A teacher reported it as marking work the class had never done, which is
 * exactly what it did.
 *
 * The scopes are therefore named, and each one matches a sentence a teacher can
 * be shown before it runs:
 *
 *   stage       (default) only the drills THIS stage introduces
 *   everything  the whole cumulative denominator, for a child who knows it all
 *   group       one letter group — throat, heavy or light
 *
 * The default is the narrow one on purpose: the destructive reading of an
 * ambiguous button should be the one a teacher has to ask for.
 */
class MasterAllDrillsRequest extends BaseFormRequest
{
    public const SCOPE_STAGE = 'stage';
    public const SCOPE_EVERYTHING = 'everything';
    public const SCOPE_GROUP = 'group';

    public const SCOPES = [self::SCOPE_STAGE, self::SCOPE_EVERYTHING, self::SCOPE_GROUP];

    public function rules(): array
    {
        return [
            'alphabet' => ['nullable', 'string', Rule::in(CurriculumRegistry::ALPHABETS)],
            'scope' => ['nullable', 'string', Rule::in(self::SCOPES)],
            // The group id is checked against the TRACK's own groups in the
            // controller, not here: which groups exist is the curriculum's
            // answer, and an English class has none at all.
            'group' => ['required_if:scope,'.self::SCOPE_GROUP, 'string'],
        ];
    }

    public function scope(): string
    {
        return (string) ($this->validated('scope') ?? self::SCOPE_STAGE);
    }
}
