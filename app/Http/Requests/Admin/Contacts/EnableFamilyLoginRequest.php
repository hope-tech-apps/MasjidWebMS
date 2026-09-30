<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalisesSubmittedAddress;
use Closure;

/**
 * Opening family sign-in for one contact (T-015d, admin half).
 *
 * ## What this request deliberately does NOT accept
 *
 * A DEFAULT. `login_email` is `required` and there is no fallback to
 * `contacts.email`, here or in the service. The imported column is a household
 * address that nobody verified and that `GroupAudience` already reads as a
 * STAFF identity bridge; promoting it to a credential by omission would mean an
 * admin who left the field blank had just mailed a child's records wherever a
 * spreadsheet pointed. The address is typed, once, per contact.
 *
 * AN ENABLED FLAG or a TIMESTAMP. There is no `enabled: true|false` and no
 * `login_enabled_at`. Enabling and revoking are separate verbs (POST / DELETE)
 * because they are not inverses — revocation additionally ends live sessions and
 * writes an audit row — and a timestamp a client can set is a timestamp a client
 * can backdate, which is half of what makes the audit trail evidence.
 * `FamilyAccessService` stamps server time.
 *
 * A CONTACT ID. The subject is the route's `{contact_id}`, resolved through the
 * tenant-scoped `findOrFail`, so another organisation's contact is a 404 rather
 * than a body the validator would have had to be trusted to check.
 *
 * `email` (the format rule) rather than `email:rfc,dns`: a DNS lookup inside a
 * request validator turns an admin's save into a network call and fails closed
 * on a transient resolver blip. Whether the mailbox exists is answered by the
 * only thing that can answer it — the parent receiving the code.
 */
class EnableFamilyLoginRequest extends BaseFormRequest
{
    use NormalisesSubmittedAddress;

    /**
     * What the office is told when the part before the `@` has an accent.
     *
     * This form is used by staff, who can act on the reason, so it says what to
     * do instead of the generic "not an email address" the public sign-in doors
     * give (those say nothing about whose address a refusal resembles). A domain
     * is converted to punycode for the parent; a local part has no such
     * conversion, and production stores no accented one.
     */
    public const ACCENTED_LOCAL_PART = 'The part before the @ cannot have accents or other non-English letters. '
        . 'Remove the accents before the @, or use another address for this parent.';

    /**
     * The address the parent will TYPE at the portal is the one stored, so it is
     * put in the form the sign-in doors look it up in (a non-ASCII domain as
     * punycode) and a non-ASCII local part is refused here, before a grant is
     * written that no parent could ever sign in to.
     */
    protected function prepareForValidation(): void
    {
        $this->normaliseSubmittedAddress('login_email');
    }

    public function rules(): array
    {
        return [
            // max:255 matches the `login_email` column. Uniqueness is NOT a rule
            // here: it is case-insensitive, spans soft-deleted contacts and is
            // scoped to the bound tenant, so it lives in FamilyAccessService
            // beside the normalisation that makes it hold — one door, not a
            // validator and a service that agree today.
            //
            // The accent check runs BEFORE `email`: that rule refuses a non-ASCII
            // local part too, with its own generic sentence, and `bail` would stop
            // there and never reach the office-facing one.
            'login_email' => [
                'bail',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $at = is_string($value) ? strrpos($value, '@') : false;

                    if ($at !== false && preg_match('/[^\x00-\x7F]/', substr($value, 0, $at)) === 1) {
                        $fail(self::ACCENTED_LOCAL_PART);
                    }
                },
                'email',
                'ascii',
                'max:255',
            ],

            // The operator has read the refusal and confirmed taking the address
            // off a member whose portal access has already ended. NOT a force
            // flag: a holder who can sign in right now still refuses with this
            // set, because two live logins on one address lock both parents out
            // silently and no confirmation box may buy that. See
            // FamilyAccessService::resolveAddressConflict().
            //
            // Absent by default and meaningless on its own — sending it when
            // there is no conflict changes nothing — so it can never be the
            // reason something happened, only the reason a refusal was lifted.
            'reassign_address' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'login_email.required' => 'Enter the sign-in email address for this parent or guardian. '
                . 'It is a credential and is deliberately separate from the contact email on their record.',
            'login_email.email' => 'That does not look like an email address.',
            'login_email.ascii' => 'That does not look like an email address.',
        ];
    }
}
