<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;
use App\Rules\MatchOldUserPasswordRule;
use App\Support\ContactIdentity;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * A signed-in staff member edits their own name, phone, avatar and password
 * here, and NOT their sign-in address.
 *
 * `users.email` is an identity, not a contact detail. `GroupAudience::identitiesFor()`
 * resolves a staff login to the parent contact holding the same address and
 * grants that contact's standing, and `users.email` is utf8mb4_unicode_ci (a
 * look-alike spelling equals the real one to the database). Rewriting it with no
 * proof of the mailbox let any admin-realm user point their login at somebody
 * else's address. The field is still SENT (the profile screen posts the whole
 * form), so it is required and must be the address the account already holds,
 * case aside; anything else is refused and nothing is written.
 *
 * Until the owner decides on a verified flow (mail a link to the NEW address,
 * confirm, then switch), only a platform SuperAdmin can change `users.email`,
 * through the `super`-only users routes (`UsersController::update`). An
 * organisation's administrator cannot, so the refusal names the SuperAdmin and
 * not the administrator: see DECISIONS.md 2026-09-29, follow-ups.
 */
class UpdateProfileRequest extends BaseFormRequest
{
    public const EMAIL_CHANGE_REFUSED = 'Your sign-in email cannot be changed here. Only a platform SuperAdmin can change it.';

    public function rules(): array
    {
        $userId = Auth::id();

        return [
            'name' => 'required|string',
            // `bail` and `string` come BEFORE the refusal: a rule after a failed one
            // still runs unless the chain stops, and `email[]=x` reached the closure
            // as an array, whose `(string)` cast is an ErrorException (a 500).
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! ContactIdentity::sameAddress($this->user()?->email, (string) $value)) {
                        $fail(self::EMAIL_CHANGE_REFUSED);
                    }
                },
            ],
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            // `extensions` pins the file's NAME to the kinds `mimes` holds its BYTES to: the
            // media library keeps the client's file name on the public disk, where `x.html`
            // would be served as a page (Concerns\ValidatesVideoSection::sectionUploadRules).
            // This route has no capability gate, so every admin-realm login reaches it.
            // `bail` stops at the first failure, so a file that is not an image is not also
            // told to rename it.
            'avatar' => 'bail|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
            'old_password' => ['nullable', 'required_with:password', new MatchOldUserPasswordRule($userId)],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'max:20',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*?&#]/',
                'confirmed',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.extensions' => 'The avatar\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
