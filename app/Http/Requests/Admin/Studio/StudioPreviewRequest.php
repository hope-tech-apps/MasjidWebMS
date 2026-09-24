<?php

namespace App\Http\Requests\Admin\Studio;

/**
 * Validates POST /api/admin/studio/drafts/{draft_id}/preview, body {answers?}.
 *
 * `answers` are whole sections that stand in for the saved ones for this one
 * preview, so Step 2 can show a change before autosave lands. They are held to
 * exactly the autosave's rules (UpdateStudioDraftRequest: known sections and
 * keys only, no store secret at any depth, the size limit, and the SPA's
 * form-encoded booleans and numbers coerced), because a preview of answers the
 * draft could never hold would be a mockup of nothing. There is no
 * lock_version: a preview writes nothing, so it cannot be stale.
 */
class StudioPreviewRequest extends UpdateStudioDraftRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['lock_version'], $rules['current_step']);

        return $rules;
    }
}
