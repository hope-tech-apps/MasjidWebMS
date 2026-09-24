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
        $reserved = (array) config('cloudflare.reserved_labels');
        $suffix = (string) config('cloudflare.managed_suffix');

        return [
            'kind' => ['required', 'string', Rule::in(MasjidDomain::KINDS)],

            'label' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_MANAGED_SUBDOMAIN,
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($reserved) {
                    if (! HostName::isLabel((string) $value)) {
                        $fail('Use letters, digits and hyphens only, up to 63 characters, not starting or ending with a hyphen.');

                        return;
                    }

                    if (in_array($value, $reserved, true)) {
                        $fail("\"{$value}\" is reserved and cannot be an organisation's subdomain.");
                    }
                },
            ],

            'host' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_CUSTOM,
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($suffix) {
                    $host = HostName::normalize((string) $value);

                    if ($host === null) {
                        $fail('That is not a host name.');

                        return;
                    }

                    if (($refusal = WritableHost::refusal($host)) !== null) {
                        $fail($refusal);

                        return;
                    }

                    if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                        $fail("Hosts under {$suffix} are managed subdomains; choose that option instead.");
                    }
                },
            ],

            'zone_apex' => [
                'exclude_unless:kind,' . MasjidDomain::KIND_CUSTOM,
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    $apex = HostName::normalize((string) $value);

                    if ($apex === null) {
                        $fail('The zone is not a host name.');

                        return;
                    }

                    if (($refusal = WritableHost::refusal($apex)) !== null) {
                        $fail($refusal);

                        return;
                    }

                    $host = HostName::normalize((string) $this->input('host'));

                    if ($host !== null && $host !== $apex && ! str_ends_with($host, '.' . $apex)) {
                        $fail("{$host} is not in the zone {$apex}.");
                    }
                },
            ],
        ];
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
