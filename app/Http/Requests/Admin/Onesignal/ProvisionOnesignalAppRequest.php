<?php

namespace App\Http\Requests\Admin\Onesignal;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates a request to ensure a per-masjid OneSignal app (W2 S14).
 *
 * The masjid is taken from the ROUTE ({masjid_id}), never from the body, so the
 * tenant is always server-derived. The body only carries app metadata:
 *   - bundle_id: the iOS bundle identifier (apns_bundle_id). It fills the
 *                organisation's ios_bundle_id when that is empty; it never
 *                replaces one already on file.
 *   - platforms: optional, the platforms to configure ("ios", "android");
 *                defaults to iOS, which is what a bundle id is for.
 *   - name:      accepted for compatibility and ignored: the app is named
 *                "Manara · <organisation> · #<id>" ("Manara [<env>] · …" outside
 *                production) so it is findable in the dashboard, and a retry
 *                finds it again by that name.
 *
 * Authorization is enforced by route middleware (auth:sanctum + super).
 */
class ProvisionOnesignalAppRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'bundle_id' => 'required|string|max:155',
            'platforms' => 'sometimes|array|min:1',
            'platforms.*' => 'string|distinct|in:ios,android',
            'name' => 'sometimes|nullable|string|max:255',
        ];
    }
}
