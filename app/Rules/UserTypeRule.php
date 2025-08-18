<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UserTypeRule implements ValidationRule
{

    private array $allowedUserTypes = ['User', 'MasjidAdmin'];

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if(!in_array($value, $this->allowedUserTypes, true)) {
            $fail("The {$attribute} must be a valid user type.");
        }
    }
}
