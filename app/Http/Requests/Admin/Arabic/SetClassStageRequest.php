<?php

namespace App\Http\Requests\Admin\Arabic;

use App\Http\Requests\BaseFormRequest;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Letters\CurriculumRegistry;
use Illuminate\Validation\Rule;

class SetClassStageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(ArabicCurriculum::STAGES)],

            // Accepted only so the controller can refuse it with a sentence
            // that explains itself. The stage ladder is the qāʿidah's; English
            // has one stage and no column, so `alphabet=english` here is a
            // caller misunderstanding rather than a value to act on. Rejecting
            // it in the controller rather than with `Rule::in(['arabic'])` is
            // what makes the difference between a 422 saying "the selected
            // alphabet is invalid" and one saying why.
            'alphabet' => ['nullable', 'string', Rule::in(CurriculumRegistry::ALPHABETS)],
        ];
    }
}
