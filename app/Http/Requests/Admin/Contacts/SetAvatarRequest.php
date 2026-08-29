<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;
use App\Support\Avatar;
use Illuminate\Validation\Rule;

/**
 * Choose (or clear) a person's avatar.
 *
 * The three parts move together: an avatar is one of forty drawings, so a
 * character without a tone names no file. Sending all three null clears the
 * choice and the person falls back to their initials — which is a real, chosen
 * state, not an error.
 */
class SetAvatarRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'character' => ['present', 'nullable', Rule::in(Avatar::CHARACTERS)],
            'tone' => ['present', 'nullable', Rule::in(Avatar::TONES)],
            'color' => ['present', 'nullable', Rule::in(Avatar::COLORS)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $parts = [$this->input('character'), $this->input('tone'), $this->input('color')];
            $given = count(array_filter($parts, static fn ($p) => $p !== null && $p !== ''));

            // All three, or none. Two-thirds of a choice names no drawing, and
            // storing it would leave a child with a permanently blank face that
            // nothing reports as wrong.
            if ($given !== 0 && $given !== 3) {
                $validator->errors()->add(
                    'character',
                    'Choose a character, a skin tone and a colour together, or clear all three.'
                );
            }
        });
    }
}
