<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * PUT /api/family/masjids/{masjid_id}/password.
 *
 * A door where a password is CHOSEN, and therefore where strength is enforced.
 * The rule itself is `strength()` below, which the app's create-account and
 * forgot-password door uses too. The caller is already authenticated — this
 * route sits behind `auth:family` + `family.parent` — so there is nothing to
 * disclose and a specific, helpful 422 is the right answer.
 *
 * ---------------------------------------------------------------------------
 * WHY NO `current_password`
 * ---------------------------------------------------------------------------
 *
 * The usual reason to demand the old password is that a session might be
 * someone else's. Here the session was minted from a code sent to the family's
 * own mailbox, and `set()` ends every OTHER session the contact holds — so an
 * attacker who has the phone and changes the password cannot also keep the
 * parent's other sessions alive, and the parent's own next code sign-in takes
 * the account straight back. Demanding the old password would instead break the
 * case this route exists for: a parent who never had one, and a parent who
 * forgot theirs and just signed in with a code to fix exactly that.
 *
 * ---------------------------------------------------------------------------
 * THE RULE
 * ---------------------------------------------------------------------------
 *
 * Twelve characters and a check against known-breached passwords, and nothing
 * else. No character-class requirements: they push people toward `Password1!`
 * and are not what NIST 800-63B asks for. Length plus a breach check is. Both
 * come from `config/family.php`, which is also where the reasoning lives.
 *
 * `uncompromised()` calls Have I Been Pwned over k-anonymity — five characters
 * of a SHA-1 prefix leave the server, never the password — and Laravel fails
 * OPEN if that call cannot be made, so a network problem at the school cannot
 * stop a parent setting a password. It is switched off under `testing` so the
 * suite makes no outbound request.
 */
class SetFamilyPasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                self::strength(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::strengthMessages() + [
            'password.confirmed' => 'The two passwords did not match.',
        ];
    }

    /**
     * THE password policy, for every door where a contact CHOOSES one.
     *
     * There are two such doors since 2026-09-16: this one (a parent inside the
     * portal) and the app's `verify-code` with a `password`
     * (App\Http\Requests\Member\VerifyMemberCodeRequest), which is how a member
     * creates an account or resets a forgotten password. Both write the SAME
     * column, `contacts.password`, which both realms read, so they must refuse
     * exactly the same passwords. A second copy of this rule is a second place
     * for the two to drift apart, and the person would then meet a password one
     * screen accepted and the other would never have allowed.
     *
     * Never apply it where a password is PRESENTED (see PasswordSignInRequest).
     */
    public static function strength(): Password
    {
        $rule = Password::min(self::minLength());

        if (config('family.password.check_breaches', true)) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * The sentences for a refused choice, keyed on the `password` field both
     * doors use.
     *
     * @return array<string, string>
     */
    public static function strengthMessages(): array
    {
        return [
            'password.min' => 'Please choose a password of at least ' . self::minLength() . ' characters. A short phrase you will remember works well.',
            'password.uncompromised' => 'That password has appeared in a public data breach. Please choose a different one.',
        ];
    }

    private static function minLength(): int
    {
        return (int) config('family.password.min_length', 12);
    }
}
