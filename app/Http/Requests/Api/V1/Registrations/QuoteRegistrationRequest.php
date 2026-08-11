<?php

namespace App\Http\Requests\Api\V1\Registrations;

use App\Http\Requests\BaseFormRequest;

/**
 * The request boundary for the PUBLIC price-quote endpoint (T-006c).
 *
 * A quote WRITES NOTHING — it is a server-priced preview so a registrant sees
 * the real number before committing. Extends BaseFormRequest so a rejection
 * leaves as the legacy {status:'failed'} 422 field bag, the same envelope the
 * form-submission and appointment endpoints already return.
 *
 * `code` is accepted but never trusted: pricing is decided server-side from the
 * immutable fee plan and admin-granted adjustments. A client-supplied code can
 * only ever be reported back as not-applied — there is no self-service discount
 * hole here (ratified design, "Financial aid — strictly pre-checkout").
 */
class QuoteRegistrationRequest extends BaseFormRequest
{
    /**
     * Deliberately unauthenticated — this is a public price preview. Which
     * organization it prices against comes from the `masjid-id` header in the
     * controller; there is no user here to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fee_plan_id' => 'required|integer|min:1',
            // Quote an EXISTING registration instead: its snapshot already
            // includes any aid an admin granted, which is the only way
            // adjustments can legitimately move a price.
            'registration_uuid' => 'nullable|string|max:36',
            'code' => 'nullable|string|max:64',
        ];
    }
}
