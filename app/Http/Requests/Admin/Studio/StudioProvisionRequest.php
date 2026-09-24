<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the body of POST /api/admin/studio/drafts/{draft_id}/provision
 * (docs/manara-studio-w1.md S8, R7):
 *
 *   {lock_version?: int,
 *    secrets?: {ios?: {asc_key_p8, asc_key_id, asc_issuer_id},
 *               android?: {play_service_account_json}}}
 *
 * `lock_version` is the version of the draft Step 3 reviewed. The server
 * provisions the draft it holds, so a save from another tab after the review
 * would otherwise be provisioned unseen; with it, that draft is refused (409)
 * and reloaded. Optional, so a caller without a review still provisions what
 * is saved.
 *
 * The BYO store credentials are typed at Step 3 and exist only in this body:
 * they are never written to the draft, so there is nothing to scrub, back up
 * or rotate. Whether each one is REQUIRED is not decided here: the draft is
 * flattened into ProvisionMasjidRequest, whose `required_if:…,byo` rules are
 * the wizard's, so a missing key is refused with the wizard's own message.
 */
class StudioProvisionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'lock_version' => ['sometimes', 'integer', 'min:0'],
            'secrets' => ['nullable', 'array'],
            'secrets.ios' => ['nullable', 'array'],
            'secrets.ios.asc_key_p8' => ['nullable', 'string'],
            'secrets.ios.asc_key_id' => ['nullable', 'string', 'max:255'],
            'secrets.ios.asc_issuer_id' => ['nullable', 'string', 'max:255'],
            'secrets.android' => ['nullable', 'array'],
            'secrets.android.play_service_account_json' => ['nullable', 'string'],
        ];
    }

    public function lockVersion(): ?int
    {
        $version = $this->validated('lock_version');

        return $version === null ? null : (int) $version;
    }

    /** @return array{ios?: array<string, string>, android?: array<string, string>} */
    public function secrets(): array
    {
        $secrets = $this->validated('secrets') ?? [];

        return array_filter([
            'ios' => array_filter((array) ($secrets['ios'] ?? []), fn ($v) => is_string($v) && $v !== ''),
            'android' => array_filter((array) ($secrets['android'] ?? []), fn ($v) => is_string($v) && $v !== ''),
        ]);
    }
}
