<?php

namespace App\Support\Studio;

use App\Models\Masjid;

/**
 * Carries the draft's inks into the new organisation's theme: the
 * `tokens.color.on*` the PaletteReport chose (auto-ink where DesignTokens' own
 * ink fails 4.5:1, or the operator's override), MERGED with whatever tokens the
 * provisioner already wrote, which for a website is the preset's
 * `tokens.layout`. Replacing the tokens would lose the header and footer the
 * operator approved; StudioProvisionThemeTest holds both together.
 *
 * Draft-only (R10): the wizard has no inks, so this is not a request key. Only
 * the new organisation's theme is written; DesignTokens is untouched, so no
 * live tenant's colours move.
 */
final class ApplyDraftBrand
{
    /** @param  array<string, string>  $inks  onPrimary / onSecondary / onAccent */
    public static function apply(Masjid $masjid, array $inks): void
    {
        if ($inks === []) {
            return;
        }

        $theme = $masjid->themeSettings()->firstOrFail();
        $tokens = is_array($theme->tokens) ? $theme->tokens : [];
        $tokens['color'] = array_merge(is_array($tokens['color'] ?? null) ? $tokens['color'] : [], $inks);

        $theme->update(['tokens' => $tokens]);
    }
}
