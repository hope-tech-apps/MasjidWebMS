<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;

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
    public function rules(): array
    {
        return [
            // max:255 matches the `login_email` column. Uniqueness is NOT a rule
            // here: it is case-insensitive, spans soft-deleted contacts and is
            // scoped to the bound tenant, so it lives in FamilyAccessService
            // beside the normalisation that makes it hold — one door, not a
            // validator and a service that agree today.
            'login_email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'login_email.required' => 'Enter the sign-in email address for this parent or guardian. '
                . 'It is a credential and is deliberately separate from the contact email on their record.',
            'login_email.email' => 'That does not look like an email address.',
        ];
    }
}
