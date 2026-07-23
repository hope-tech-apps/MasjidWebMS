<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-masjid color theme.
 *
 * Emits the canonical { primary, secondary, accent, background } shape consumed
 * IDENTICALLY by the Nuxt web (/api/v1/settings) and the mobile apps
 * (/api/mobile/masjids/{id}). The mobile surface reuses this same array (see
 * MasjidsController::themePayload) so both clients read the exact same keys.
 */
class ThemeSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'primary' => $this->primary_color,
            'secondary' => $this->secondary_color,
            'accent' => $this->accent_color,
            'background' => $this->background_color,
            // Full resolved design-token tree (additive; legacy keys above are
            // unchanged for older clients). See App\Support\DesignTokens.
            'tokens' => $this->resolvedTokens(),
        ];
    }
}
