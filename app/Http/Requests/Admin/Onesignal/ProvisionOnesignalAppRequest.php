<?php

namespace App\Http\Requests\Admin\Onesignal;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates a request to auto-provision a per-masjid OneSignal app.
 *
 * The masjid is taken from the ROUTE ({masjid_id}), never from the body, so the
 * tenant is always server-derived. The body only carries app metadata:
 *   - bundle_id: the iOS bundle identifier (apns_bundle_id) for the new app.
 *   - name:      optional display name (defaults to the masjid name).
 *
 * Authorization is enforced by route middleware (auth:sanctum + super).
 */
class ProvisionOnesignalAppRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'bundle_id' => 'required|string|max:255',
            'name' => 'sometimes|nullable|string|max:255',
        ];
    }
}
