<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

class MatchOldUserPasswordRule implements ValidationRule
{
    private $userId;

    public function __construct($id)
    {
        $this->userId = $id;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::find($this->userId);
        if(!$user) {
            $fail('The targeted user not found.');
        } else if(!Hash::check($value, $user->password)) {
            $fail('Incorrect entered old password.');
        }
    }
}
