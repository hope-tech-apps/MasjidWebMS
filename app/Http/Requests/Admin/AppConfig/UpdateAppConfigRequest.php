<?php

namespace App\Http\Requests\Admin\AppConfig;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates a single platform's app-version config update. All fields
 * `sometimes` so the admin can patch one toggle (e.g. just flip
 * force_update) without re-posting everything. URLs are http(s) only.
 */
class UpdateAppConfigRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'minimum_version' => 'sometimes|required|string|max:20|regex:/^\d+(\.\d+){0,3}$/',
            'minimum_build' => 'sometimes|required|integer|min:0',
            'force_update' => 'sometimes|boolean',
            'update_message' => 'sometimes|nullable|string|max:500',
            'latest_version' => 'sometimes|nullable|string|max:20|regex:/^\d+(\.\d+){0,3}$/',
            'store_url' => 'sometimes|nullable|url:http,https|max:500',
            'maintenance_mode' => 'sometimes|boolean',
            'maintenance_message' => 'sometimes|nullable|string|max:500',
            // Which shell the app draws. FOUR spellings accepted, not two:
            // `menu`/`legacy` are the canonical pair the server documents, and
            // `side_menu`/`tabs_drawer` are the vocabulary both clients were
            // compiled with. Both clients map an UNKNOWN value to the new
            // shell, so refusing the spelling an operator has in front of them
            // — while the apps would have honoured it — is how this lever
            // reports success and changes nothing. See config/app_menu.php's
            // `navigation_aliases` for the full table.
            'navigation' => 'sometimes|nullable|in:menu,legacy,side_menu,tabs_drawer',
        ];
    }
}
