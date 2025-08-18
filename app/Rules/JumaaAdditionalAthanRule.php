<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class JumaaAdditionalAthanRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
            $fail('The additional athan time must be in the format H:i.');
        }

        $beginsTime = request()->input('begins');
        if(strtotime($value) >= strtotime($beginsTime)) {
            $fail('The additional athan time must be before the Jumaa begins time.');
        }
    }
}
