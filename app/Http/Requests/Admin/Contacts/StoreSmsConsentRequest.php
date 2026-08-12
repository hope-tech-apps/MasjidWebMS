<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;
use App\Models\Contact;
use Illuminate\Validation\Rule;

/**
 * Recording that a contact agreed to receive text messages (T-009).
 *
 * ## What this request deliberately does NOT accept
 *
 * A CONSENT DATE. There is no `consented_at` field, and there never should be:
 * a date the client can set is a date the client can backdate, and the date is
 * half of what makes the record evidence. `SmsConsentService::grant()` stamps
 * server time.
 *
 * A CONSENT VALUE. There is no `opt_in: true|false` boolean either. Granting and
 * withdrawing are separate endpoints (POST and DELETE) because they are not
 * symmetric operations: withdrawal additionally writes the durable suppression
 * list, and consent cannot be granted at all for a suppressed number. Modelling
 * them as one toggle would invite exactly the "just set it back to true" that
 * the law does not allow.
 *
 * `source` is required and must be one of Contact::SMS_CONSENT_SOURCES —
 * "somebody ticked a box" is not a defensible answer to "how did you obtain
 * consent?". `sms_reply_start` is excluded from what an admin may claim: it
 * means the subscriber texted START from their own handset, and only the inbound
 * webhook can write it.
 *
 * `evidence` is the free-text artifact reference beside the constant ("web form
 * response #4182", "signed 2026-03-04 registration packet"). Optional, because
 * an in-person sign-up may genuinely have no id — but strongly encouraged, and
 * the pairing is the point: the constant makes consent queryable, the evidence
 * makes it provable.
 */
class StoreSmsConsentRequest extends BaseFormRequest
{
    /** Sources an ADMIN may record. `sms_reply_start` is webhook-only. */
    public static function adminSelectableSources(): array
    {
        return array_values(array_filter(
            Contact::SMS_CONSENT_SOURCES,
            fn (string $source) => $source !== 'sms_reply_start',
        ));
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', Rule::in(self::adminSelectableSources())],
            'evidence' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'source.required' => 'Record HOW consent was obtained — a phone number on file is not consent.',
            'source.in' => 'Unknown consent source. Choose one of: '
                . implode(', ', self::adminSelectableSources()) . '.',
        ];
    }
}
