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
            // REQUIRED ONLY WHEN NOTHING ELSE NAMES A PRICE. A quote that
            // carries a `registration_uuid` is a question about THAT
            // registration — "what do I still owe" — and the answer is its own
            // snapshot on its own plan, which the controller resolves from the
            // row rather than from the client.
            //
            // `required` here was half of the 2026-08-13 defect: it forced the
            // payment page to name a plan for an in-flight registration, and
            // the only plan the endpoint then accepted was a PURCHASABLE one —
            // which, after the documented deactivate-and-replace price rise, is
            // never the plan the registration is actually held on. Measured on
            // one fixture at one instant: checkout 200 charging 15000, quote on
            // the registration's OWN plan id 404 "This fee plan is not
            // available.", quote on the NEW plan's id 200 reporting that plan's
            // id and `kind: recurring` beside a one-time $150 snapshot.
            'fee_plan_id' => ['required_without:registration_uuid', 'nullable', 'integer', 'min:1'],
            // Quote an EXISTING registration instead: its snapshot already
            // includes any aid an admin granted, which is the only way
            // adjustments can legitimately move a price.
            'registration_uuid' => 'nullable|string|max:36',
            'code' => 'nullable|string|max:64',
        ];
    }
}
