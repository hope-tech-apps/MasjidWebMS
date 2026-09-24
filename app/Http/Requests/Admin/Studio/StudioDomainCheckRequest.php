<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;
use App\Models\MasjidDomain;
use App\Support\HostName;
use App\Support\WritableHost;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/studio/domains/check.
 *
 *   {kind: 'managed_subdomain', label}          -> <label>.<managed_suffix>
 *   {kind: 'custom', host, zone_apex}
 *
 * The label and the hosts are normalised before the rules run, so what is
 * validated is exactly what would be stored. Then, as every host-writing path
 * does (App\Support\WritableHost): no single label, no `localhost`, nothing
 * under `.localhost`, `.local`, `.internal`, `.pages.dev` or `.workers.dev`.
 * A custom host may not sit under our own managed suffix (that is what
 * `managed_subdomain` is for), and it must be its zone apex or end with
 * `.<zone_apex>`; no public-suffix guessing, the operator states the zone.
 */
class StudioDomainCheckRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('label'))) {
            $merge['label'] = strtolower(trim($this->input('label')));
        }

        foreach (['host', 'zone_apex'] as $field) {
            if (is_string($this->input($field))) {
                // A value that does not normalise is kept as typed, so the
                // error names what the operator entered.
                $merge[$field] = HostName::normalize($this->input($field)) ?? $this->input($field);
            }
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(MasjidDomain::KINDS)],

            'label' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_MANAGED_SUBDOMAIN,
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (($refusal = self::labelRefusal((string) $value)) !== null) {
                        $fail($refusal);
                    }
                },
            ],

            'host' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_CUSTOM,
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (($refusal = self::customHostRefusal((string) $value)) !== null) {
                        $fail($refusal);
                    }
                },
            ],

            'zone_apex' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_CUSTOM,
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (($refusal = self::zoneApexRefusal((string) $value, (string) $this->input('host'))) !== null) {
                        $fail($refusal);
                    }
                },
            ],
        ];
    }

    /**
     * Why a managed-subdomain label cannot be used, or null. Shared with
     * ProvisionMasjidRequest's `slug`, which names the same subdomain: one copy
     * of the rule, so the check and the provision cannot disagree.
     */
    public static function labelRefusal(string $label): ?string
    {
        if (! HostName::isLabel($label)) {
            return 'Use letters, digits and hyphens only, up to 63 characters, not starting or ending with a hyphen.';
        }

        if (in_array($label, (array) config('cloudflare.reserved_labels'), true)) {
            return "\"{$label}\" is reserved and cannot be an organisation's subdomain.";
        }

        return null;
    }

    /** Why a custom host cannot be used, or null. Shared with ProvisionMasjidRequest's `web_domain.custom_host`. */
    public static function customHostRefusal(string $value): ?string
    {
        $suffix = (string) config('cloudflare.managed_suffix');
        $host = HostName::normalize($value);

        if ($host === null) {
            return 'That is not a host name.';
        }

        if (($refusal = WritableHost::refusal($host)) !== null) {
            return $refusal;
        }

        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return "Hosts under {$suffix} are managed subdomains; choose that option instead.";
        }

        return null;
    }

    /** Why a zone apex cannot hold `$hostValue`, or null. Shared with ProvisionMasjidRequest's `web_domain.custom_zone_apex`. */
    public static function zoneApexRefusal(string $value, string $hostValue): ?string
    {
        $apex = HostName::normalize($value);

        if ($apex === null) {
            return 'The zone is not a host name.';
        }

        if (($refusal = WritableHost::refusal($apex)) !== null) {
            return $refusal;
        }

        $host = HostName::normalize($hostValue);

        if ($host !== null && $host !== $apex && ! str_ends_with($host, '.' . $apex)) {
            return "{$host} is not in the zone {$apex}.";
        }

        return null;
    }

    /** The host this request names, normalised. Only call after validation. */
    public function domainHost(): string
    {
        if ($this->validated('kind') === MasjidDomain::KIND_MANAGED_SUBDOMAIN) {
            return $this->validated('label') . '.' . config('cloudflare.managed_suffix');
        }

        return (string) HostName::normalize($this->validated('host'));
    }
}
