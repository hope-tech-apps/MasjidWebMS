<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The mobile media BOUNDARY.
 *
 * ==========================================================================
 * WHY THIS EXISTS
 * ==========================================================================
 *
 * The Flutter clients parse every media object defensively
 * (`icon = json['icon'] != null ? Icon.fromJson(...) : null`) and then
 * FORCE-UNWRAP it at the render site — `SvgPicture.network(feature.icon!.
 * originalUrl!)`, `NetworkImage(a.image!.originalUrl!)`. A `!` on a null throws
 * "Null check operator used on a null value" inside a build()/itemBuilder, and
 * because the app installs no ErrorWidget boundary the throw blanks the WHOLE
 * screen/drawer, not one tile. That is precisely the 2026-08-28 incident: the
 * `featuresIcons` media rows were gone, `/features` served `icon:null`, and the
 * functionalities drawer rendered empty for every masjid.
 *
 * The durable fix is client-side (a null-safe render + an ErrorWidget boundary,
 * shipping in the next app build). Until every install has updated, the SERVER
 * must never hand a shipped client the shape that crashes it. So every mobile
 * endpoint that emits a media object routes it through here first: a real media
 * envelope when the media is present, and a NON-NULL stub carrying a served
 * placeholder URL when it is missing. The `original_url`/`preview_url` are never
 * null, so the client's `!` can never fire.
 *
 * This is a floor, not a feature: it turns a blank screen into a placeholder.
 * The right icon still comes from the real media; run `media:verify` +
 * `app:features-ensure-icons` to restore that.
 */
class MobileMedia
{
    /**
     * A served raster placeholder for image fields (announcements, service
     * image, about images, donation banner). Shipped in the repo under
     * public/, so it needs no `storage:link` and is present on every deploy.
     */
    public static function imagePlaceholderUrl(): string
    {
        return url('mobile-assets/placeholder.png');
    }

    /**
     * A served SVG placeholder for icon fields rendered with SvgPicture. When a
     * specific feature icon file is known (the surviving storage/app/public/
     * icons/*.svg set), prefer it so the drawer still shows the RIGHT glyph;
     * otherwise the generic placeholder SVG.
     */
    public static function iconPlaceholderUrl(?string $iconFile = null): string
    {
        if ($iconFile !== null && Storage::disk('public')->exists('icons/'.$iconFile)) {
            return url('storage/icons/'.$iconFile);
        }

        return url('mobile-assets/placeholder.svg');
    }

    /**
     * Turn a (possibly null) media relation into a media envelope whose
     * `original_url` and `preview_url` are guaranteed NON-NULL.
     *
     * @param  mixed  $media  an Eloquent Media model, an array, or null
     */
    public static function envelope($media, string $fallbackUrl): array
    {
        if ($media !== null) {
            $arr = is_array($media) ? $media : $media->toArray();

            if (! empty($arr['original_url'])) {
                $arr['preview_url'] = $arr['preview_url'] ?? $arr['original_url'];

                return $arr;
            }
        }

        // Mirrors the minimal shape the clients decode (`id` is the only field an
        // older non-optional model requires); the non-null URLs are the point.
        return [
            'id' => 0,
            'original_url' => $fallbackUrl,
            'preview_url' => $fallbackUrl,
        ];
    }
}
