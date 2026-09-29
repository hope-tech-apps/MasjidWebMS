<?php

namespace App\Http\Requests\Admin\Studio;

use App\Models\MasjidDomain;
use App\Support\HostName;
use App\Support\Studio\CanonicalPair;
use Illuminate\Validation\Rule;
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
 *
 * W2 S5: an optional `canonical` (`www` or `apex`) on a custom host that is a
 * zone's apex or its `www` asks for BOTH hosts: the canonical one serving and
 * the other redirecting to it (CanonicalPair). Absent, the request is exactly
 * W1's: one host, no pair. Both hosts of a pair must be free.
 */
class StoreMasjidDomainRequest extends StudioDomainCheckRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'canonical' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_CUSTOM,
                'nullable',
                'string',
                Rule::in(CanonicalPair::CHOICES),
            ],
        ];
    }

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

                    return;
                }

                if ($managed || ! $this->filled('canonical')) {
                    return;
                }

                $apex = (string) HostName::normalize((string) $this->input('zone_apex'));
                $pair = CanonicalPair::for($host, $apex, (string) $this->input('canonical'));

                if ($pair === null) {
                    $validator->errors()->add('canonical', "A canonical host applies only to {$apex} and www.{$apex}.");

                    return;
                }

                $other = $pair['serving'] === $host ? $pair['redirect'] : $pair['serving'];
                $otherHolder = MasjidDomain::query()->where('host', $other)->value('masjid_id');

                if ($otherHolder !== null) {
                    $validator->errors()->add('canonical', "{$other} is already recorded for organisation #{$otherHolder}.");
                }
            },
        ];
    }

    /**
     * The pair this request asks for, or null for W1's single host. Only call
     * after validation.
     *
     * @return array{serving: string, redirect: string}|null
     */
    public function canonicalPair(): ?array
    {
        if ($this->validated('kind') !== MasjidDomain::KIND_CUSTOM || blank($this->validated('canonical'))) {
            return null;
        }

        return CanonicalPair::for($this->domainHost(), $this->zoneApex(), (string) $this->validated('canonical'));
    }

    /** The zone the host lives in: our managed zone, or the one the operator stated. */
    public function zoneApex(): string
    {
        return $this->validated('kind') === MasjidDomain::KIND_MANAGED_SUBDOMAIN
            ? (string) config('cloudflare.managed_zone')
            : (string) $this->validated('zone_apex');
    }
}
