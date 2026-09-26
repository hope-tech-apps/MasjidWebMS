<?php

namespace App\Http\Requests\Admin\Contacts;

use App\Http\Requests\BaseFormRequest;

/**
 * Staff recording that a contact consented, in Manara, to this organisation's
 * email — which lifts an import's `not_opted_in` precaution and nothing else
 * (the broadcast opt-out service's liftPrecaution(); the controller is the
 * one file here allowed to name it, per EmailUnsubscribeTest's guard).
 *
 * `evidence` is REQUIRED, unlike the SMS twin's: this is the only way a
 * suppression row is ever released by somebody other than the subscriber, so
 * the row must say how consent was given ("signed the newsletter sheet at
 * Jumu'ah, 3 Oct"). No date is accepted; the server stamps it.
 */
class StoreEmailConsentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'evidence' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'evidence.required' => 'Say how the person gave consent (for example "signed the newsletter sheet on 3 Oct"). An address on file is not consent.',
        ];
    }
}
