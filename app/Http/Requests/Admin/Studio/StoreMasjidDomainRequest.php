<?php

namespace App\Http\Requests\Admin\Studio;

use App\Models\MasjidDomain;
use App\Support\HostName;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/admin/masjids/{masjid_id}/domains (Manara Studio W1, S7).
 *
 *   kind=managed_subdomain&label=al-noor        -> al-noor.<managed_suffix>
 *   kind=custom&host=www.example.org&zone_apex=example.org
 *
 * Form-encoded (what the SPA's global axios default sends) or JSON; there is no
 * boolean in the body, so neither encoding can arrive as a string the rules
 * refuse (.claude/rules/shipping.md).
 *
 * Every rule of the domain check is the rule here, by inheritance rather than
 * by copy: a host the check calls available must be one this accepts, and a
 * second copy of the host rules is the parallel list shipping.md warns about.
 * What this adds is the answer to the check's question: a host some row
 * already holds (whatever its status, `reserved` included) is refused with the
 * legacy 422 envelope, on the field the operator typed it into.
 */
class StoreMasjidDomainRequest extends StudioDomainCheckRequest
{
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // From the inputs prepareForValidation() already normalised,
                // not validated(): this runs inside the validation pass.
                $managed = $this->input('kind') === MasjidDomain::KIND_MANAGED_SUBDOMAIN;
                $host = $managed
                    ? $this->input('label') . '.' . config('cloudflare.managed_suffix')
                    : (string) HostName::normalize((string) $this->input('host'));
                $holder = MasjidDomain::query()->where('host', $host)->value('masjid_id');

                if ($holder !== null) {
                    $field = $managed ? 'label' : 'host';
                    $validator->errors()->add($field, "{$host} is already recorded for organisation #{$holder}.");
                }
            },
        ];
    }

    /** The zone the host lives in: our managed zone, or the one the operator stated. */
    public function zoneApex(): string
    {
        return $this->validated('kind') === MasjidDomain::KIND_MANAGED_SUBDOMAIN
            ? (string) config('cloudflare.managed_zone')
            : (string) $this->validated('zone_apex');
    }
}
