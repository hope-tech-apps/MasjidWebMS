<?php

namespace App\Http\Requests\Mobile\Users;

use App\Http\Requests\BaseFormRequest;

/**
 * `PUT /api/mobile/user` — re-point an existing install at an organisation.
 *
 * The telemetry fields carry no rules here for the same reason they carry none
 * on registration and none on the heartbeat: see StoreMobileAppUserRequest.
 * AppClientHeader::resolve() drops what it cannot use, so the columns are safe
 * without a refusal path, and this verb runs on every organisation switch — a
 * 422 over a counter would be a member who cannot change organisations.
 */
class UpdateMobileAppUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'masjid_id' => 'required|exists:masjids,id',
            'device_id' => 'required|exists:mobile_app_users,device_id',
        ];
    }
}
