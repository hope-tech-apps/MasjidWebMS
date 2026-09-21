<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;
use App\Support\Letters\CurriculumRegistry;
use Illuminate\Validation\Rule;

/**
 * Mark every drill on one track mastered for one child (teacher feedback, BISS
 * 2026-09-21: a child who already knows their letters should not cost the
 * teacher a hundred taps).
 *
 * Only the track is chosen. WHICH drills is not the client's to say: it is the
 * class's stage on that track, the same denominator the progress bar counts, so
 * "all of them" cannot mean a different set here than it does on the screen.
 * Absent means Arabic, as it does on every other letters endpoint.
 */
class MasterAllDrillsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'alphabet' => ['nullable', 'string', Rule::in(CurriculumRegistry::ALPHABETS)],
        ];
    }
}
