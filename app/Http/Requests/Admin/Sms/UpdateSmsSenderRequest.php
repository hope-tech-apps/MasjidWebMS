<?php

namespace App\Http\Requests\Admin\Sms;

use App\Http\Requests\BaseFormRequest;
use App\Models\MasjidSmsSender;
use App\Services\Sms\PhoneNumber;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rule;

/**
 * Recording a tenant's SMS sender identity and its A2P 10DLC registration state
 * (T-009). Super-Admin only.
 *
 * This request is the boundary of a HUMAN process. Brand and campaign
 * registration happens in the provider's console and at the carriers, takes
 * days, and can be rejected. Nothing here performs it — the operator does that
 * work and then records the outcome, which is why `registration_status` is an
 * input rather than something this application infers.
 *
 * Two rules the validator enforces, both of them the difference between a
 * refusal and an outage:
 *
 *  - `approved` requires an originating identity. Approving a sender with no
 *    number and no messaging service would produce a tenant that passes
 *    `canSend()`'s first half and then fails at the provider on every single
 *    message.
 *  - The number must normalise to E.164. The inbound STOP webhook resolves the
 *    tenant by matching the `To` number against this column; a number stored as
 *    "(613) 555-0142" would never match, and the opt-outs for that organisation
 *    would silently go unrecorded. Storing it normalised is what keeps that
 *    join exact.
 */
class UpdateSmsSenderRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone_number')) {
            // Normalise BEFORE validation so what is stored is what the webhook
            // will later match against. An unnormalisable number falls through
            // as-is and is rejected by the rule below.
            $this->merge([
                'phone_number' => PhoneNumber::e164($this->input('phone_number')) ?? $this->input('phone_number'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'max:32'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'messaging_service_sid' => ['nullable', 'string', 'max:64'],
            'sender_label' => ['nullable', 'string', 'max:64'],
            'registration_status' => ['required', Rule::in(MasjidSmsSender::STATUSES)],
            'brand_registration_id' => ['nullable', 'string', 'max:64'],
            'campaign_registration_id' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $number = $this->input('phone_number');

            if (filled($number) && PhoneNumber::e164($number) === null) {
                $validator->errors()->add(
                    'phone_number',
                    'Enter the sending number in full international form (for example +16135550142). '
                    . 'Inbound STOP messages are matched to this organization by this number, so it must be exact.'
                );
            }

            if (
                $this->input('registration_status') === MasjidSmsSender::STATUS_APPROVED
                && blank($number)
                && blank($this->input('messaging_service_sid'))
            ) {
                $validator->errors()->add(
                    'registration_status',
                    'An approved sender needs a phone number or a messaging service to send from. '
                    . 'Record the number the carriers approved before marking it approved.'
                );
            }
        });
    }
}
