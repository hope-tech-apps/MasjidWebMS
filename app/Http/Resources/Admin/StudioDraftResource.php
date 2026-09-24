<?php

namespace App\Http\Resources\Admin;

use App\Models\StudioDraft;
use App\Support\Studio\PaletteContrast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Studio draft as the SPA reads it (docs/manara-studio-w1.md S2).
 *
 * The logo never carries a storage path or a public URL: `url` is the
 * authenticated endpoint, relative, and the SPA fetches it with the bearer token
 * as a blob (.claude/rules/private-uploads.md).
 *
 * `palette` is computed on every read rather than stored, so the report can
 * never describe colours the draft no longer has. It is null until all four
 * colours are chosen (see StudioDraft::brandColours()).
 *
 * @mixin StudioDraft
 */
class StudioDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StudioDraft $draft */
        $draft = $this->resource;

        return [
            'id' => $draft->id,
            'status' => $draft->status,
            'current_step' => $draft->current_step,
            'schema_version' => $draft->schema_version,
            'lock_version' => $draft->lock_version,
            'name' => $draft->name,
            'org_type' => $draft->org_type,
            // An object even when empty, so the SPA can index sections into it.
            'answers' => $draft->answers === [] ? new \stdClass : $draft->answers,
            'logo' => $draft->hasLogo() ? [
                'original_name' => $draft->logo_original_name,
                'mime_type' => $draft->logo_mime_type,
                'size_bytes' => $draft->logo_size_bytes,
                'width' => $draft->logo_width,
                'height' => $draft->logo_height,
                'sha256' => $draft->logo_sha256,
                'url' => "/api/admin/studio/drafts/{$draft->id}/logo",
            ] : null,
            'palette' => $this->palette($draft),
            'provisioned_masjid_id' => $draft->provisioned_masjid_id,
            'provisioned_at' => $draft->provisioned_at?->toIso8601String(),
            'created_at' => $draft->created_at?->toIso8601String(),
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }

    /** The resource alone, for the { status, data } envelope the admin API uses. */
    public static function payload(StudioDraft $draft): array
    {
        return (new self($draft))->resolve(request());
    }

    private function palette(StudioDraft $draft): ?array
    {
        $colours = $draft->brandColours();

        if ($colours === null) {
            return null;
        }

        $overrides = $draft->section('brand')['ink_overrides'] ?? [];

        return PaletteContrast::report(
            $colours,
            is_array($overrides) ? $overrides : [],
            $draft->hasLogo() ? ['width' => $draft->logo_width, 'height' => $draft->logo_height] : null,
        );
    }
}
