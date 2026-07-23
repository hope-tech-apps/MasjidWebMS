<?php

namespace App\Http\Requests\Admin\Provisioning;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a "Generate apps" request for one masjid.
 *
 * The masjid is taken from the ROUTE ({masjid_id}), never the body, so the tenant
 * is always server-derived. The body carries:
 *   - platforms: which apps to generate — a non-empty subset of [ios, android].
 *   - optional overrides for the dispatch payload; every override has a sensible
 *     default derived from the masjid + its masjid_app_publishing row, so a bare
 *     `{ platforms: ['ios','android'] }` is a complete request.
 *
 * Extends BaseFormRequest so a validation failure throws the legacy
 * { status:'failed', data:<errors> } envelope. Authorization is enforced by
 * route middleware (auth:sanctum + admin + super).
 */
class ProvisionAppsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // At least one platform, each one of the two supported repos.
            'platforms' => 'required|array|min:1',
            'platforms.*' => ['string', Rule::in(['ios', 'android'])],

            // ---- Optional common overrides ----
            'name' => 'sometimes|nullable|string|max:255',
            'display_name' => 'sometimes|nullable|string|max:255',
            // Apple Team ID override (else app-publishing, else platform default).
            'development_team' => 'sometimes|nullable|string|max:64',

            // ---- Optional iOS overrides ----
            'bundle_id' => 'sometimes|nullable|string|max:255',
            'include_tvos' => 'sometimes|boolean',

            // ---- Optional Android overrides ----
            'flavor' => 'sometimes|nullable|string|max:255',
            'application_id_suffix' => 'sometimes|nullable|string|max:255',
            'app_name' => 'sometimes|nullable|string|max:255',
            'onesignal_app_id' => 'sometimes|nullable|string|max:255',
        ];
    }

    public function attributes(): array
    {
        return [
            'platforms' => 'platforms',
            'platforms.*' => 'platform',
        ];
    }
}
