<?php

namespace App\Support\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\Page;
use App\Support\BrandAssets;
use App\Support\CapabilityCatalogue;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * An existing organisation seen through Studio's sections (Studio W2 S9, R14),
 * read-only, in Studio's order: identity, prayer, brand, content, features,
 * layout, platforms, domain, apps.
 *
 * NO DRAFT. Each section says where it is edited (`edit_in`): `studio` for
 * `features` (CapabilityWriter::apply, S7) and `brand` (the theme screen's
 * own endpoint, and BrandAssets::regenerate, S8); otherwise the SPA route of
 * the admin screen that already writes it, which already purges the renderer
 * and flushes the caches. A second writer per datum is exactly what landmine 3
 * warns about.
 *
 * EXPLICIT FIELDS ONLY. Every value is picked by name; nothing is a model's
 * toArray(). `platforms` carries account modes and has_* flags and never a
 * credential or identifier: no OneSignal key or id, no Apple team, no store
 * account (StudioOrganisationSnapshotTest walks the whole answer).
 *
 * `features` is the catalogue for the organisation's type
 * (CapabilityCatalogue::forOrgType, the ONE server list Studio reads) with
 * each entry's EFFECTIVE value: moduleIsOff for a module, hasCapability for a
 * grant, never the raw override.
 */
final class OrganisationSnapshot
{
    /** Studio's section order (StudioDraft::ANSWER_SECTIONS) plus `apps`, which S17 fills. */
    public const SECTIONS = ['identity', 'prayer', 'brand', 'content', 'features', 'layout', 'platforms', 'domain', 'apps'];

    public const EDIT_IN_STUDIO = 'studio';

    /**
     * The admin screens that already write each section. A `/masjid/…` route
     * acts on the dashboard's current organisation, so the SPA switches to this
     * one before it follows the link. The hashes open a MosqueDetailsTabsView
     * tab by its id.
     */
    public const EDIT_IN = [
        'identity' => '/masjid/details#basic-info',
        'prayer' => '/masjid/details#prayer-calculation',
        'content' => '/masjid/about',
        'layout' => '/masjid/pages',
        'platforms' => '/dashboard/super/masjids/{id}',
        'domain' => '/dashboard/super/masjids/{id}',
        'apps' => '/dashboard/super/masjids/{id}',
    ];

    /** @return array{org: array<string, mixed>, sections: array<string, array{data: mixed, edit_in: string}>} */
    public static function of(Masjid $org): array
    {
        $org->loadMissing(['masjidAbout', 'donationLink', 'socialMediaLinks', 'themeSettings', 'prayerCalculationSettings', 'iqamaTimeSettings', 'jumaaSettings', 'appPublishing']);

        $data = [
            'identity' => self::identity($org),
            'prayer' => self::prayer($org),
            'brand' => self::brand($org),
            'content' => self::content($org),
            'features' => self::features($org),
            'layout' => self::layout($org),
            'platforms' => self::platforms($org),
            'domain' => self::domain($org),
            'apps' => null,
        ];

        $sections = [];

        foreach (self::SECTIONS as $section) {
            $sections[$section] = [
                'data' => $data[$section],
                'edit_in' => in_array($section, ['features', 'brand'], true)
                    ? self::EDIT_IN_STUDIO
                    : str_replace('{id}', (string) $org->id, self::EDIT_IN[$section]),
            ];
        }

        return [
            'org' => [
                'id' => (int) $org->id,
                'name' => (string) $org->name,
                'org_type' => $org->orgType(),
                'slug' => $org->slug,
            ],
            'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    private static function identity(Masjid $org): array
    {
        $social = [];

        foreach (StarterFacts::SOCIAL_TYPES as $key => $type) {
            $social[$key] = $org->socialMediaLinks->firstWhere('type', $type)?->value;
        }

        return [
            'name' => $org->name,
            'org_type' => $org->orgType(),
            'slug' => $org->slug,
            'description' => $org->description,
            'email' => $org->email,
            'phone' => $org->phone,
            'address' => $org->address,
            'timezone' => $org->timezone,
            // Where the prayer times on the TV mockup are computed for.
            'latitude' => $org->latitude === null ? null : (float) $org->latitude,
            'longitude' => $org->longitude === null ? null : (float) $org->longitude,
            'website_link' => $org->website_link,
            'donation_link' => $org->donationLink?->link,
            'social' => $social,
            'listed' => $org->listed_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private static function prayer(Masjid $org): array
    {
        $calculation = $org->prayerCalculationSettings;
        $iqama = $org->iqamaTimeSettings;
        $jumaa = $org->jumaaSettings;
        $value = fn ($v) => $v instanceof \BackedEnum ? $v->value : $v;

        return [
            'module_on' => ! $org->moduleIsOff('prayer_times'),
            'calculation' => $calculation === null ? null : [
                'method' => $value($calculation->method),
                'madhab' => $value($calculation->madhab),
                'high_latitude_rule' => $value($calculation->high_latitude_rule),
            ],
            'iqama' => $iqama === null ? null : [
                'iqama_type' => $value($iqama->iqama_type),
                'show_iqama_times' => (bool) $iqama->show_iqama_times,
                'fajr' => $iqama->fajr,
                'dhuhr' => $iqama->dhuhr,
                'asr' => $iqama->asr,
                'maghrib' => $iqama->maghrib,
                'isha' => $iqama->isha,
            ],
            'jumaa' => $jumaa === null ? null : [
                'iqama' => $jumaa->iqama,
                'athans' => $jumaa->athans,
                'shifts' => $jumaa->shifts,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function brand(Masjid $org): array
    {
        $theme = $org->themeSettings;
        $tokens = is_array($theme?->tokens) ? $theme->tokens : [];

        $urls = [];
        foreach (['logo_url' => 'logos'] + array_combine(['favicon_url', 'touch_icon_url', 'share_image_url'], BrandAssets::COLLECTIONS) as $key => $collection) {
            $urls[$key] = Media::query()
                ->where('model_type', Masjid::class)
                ->where('model_id', $org->id)
                ->where('collection_name', $collection)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()?->original_url;
        }

        return [
            'colours' => $theme === null ? null : [
                'primary_color' => $theme->primary_color,
                'secondary_color' => $theme->secondary_color,
                'accent_color' => $theme->accent_color,
                'background_color' => $theme->background_color,
            ],
            'theme_layout' => is_array($tokens['layout'] ?? null) ? $tokens['layout'] : null,
            'has_theme' => $theme !== null,
        ] + $urls + [
            // Regenerating on an organisation with none of the three ADDS keys
            // to its /api/v1/settings: the confirm dialog says so (S8).
            'has_derivatives' => $urls['favicon_url'] !== null || $urls['touch_icon_url'] !== null || $urls['share_image_url'] !== null,
        ];
    }

    /** @return array<string, mixed> */
    private static function content(Masjid $org): array
    {
        return [
            'about' => $org->masjidAbout?->about,
            'mission' => $org->masjidAbout?->mission,
            'vision' => $org->masjidAbout?->vision,
        ];
    }

    /** @return list<array<string, mixed>> the catalogue's groups, each entry with its effective value */
    private static function features(Masjid $org): array
    {
        $overrides = is_array($org->capability_overrides) ? $org->capability_overrides : [];
        $groups = [];

        foreach (CapabilityCatalogue::forOrgType($org->orgType()) as $group) {
            $group['entries'] = array_map(fn (array $entry) => $entry + [
                'enabled' => $entry['kind'] === 'module'
                    ? ! $org->moduleIsOff($entry['key'])
                    : $org->hasCapability($entry['key']),
                // Whether a SuperAdmin decided it (an explicit override), or it
                // follows the org type's default. Column-backed grants have none.
                'decided' => $entry['writer'] === 'capability' && array_key_exists($entry['key'], $overrides),
            ], $group['entries']);

            $groups[] = $group;
        }

        return $groups;
    }

    /** @return array<string, mixed> */
    private static function layout(Masjid $org): array
    {
        $tokens = is_array($org->themeSettings?->tokens) ? $org->themeSettings->tokens : [];

        return [
            'website_on' => ! $org->moduleIsOff('website'),
            'theme_layout' => is_array($tokens['layout'] ?? null) ? $tokens['layout'] : null,
            'pages' => Page::query()
                ->where('masjid_id', $org->id)
                ->withCount(['sections' => fn ($q) => $q->where('sections.masjid_id', $org->id)])
                ->orderBy('order')
                ->orderBy('id')
                ->get()
                ->map(fn (Page $page) => [
                    'id' => (int) $page->id,
                    'slug' => (string) $page->slug,
                    'title' => (string) $page->title,
                    'is_active' => (bool) $page->is_active,
                    'show_in_menu' => (bool) $page->show_in_menu,
                    'sections' => (int) $page->sections_count,
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> modes and has_* flags only */
    private static function platforms(Masjid $org): array
    {
        $row = $org->appPublishing;

        return [
            'enabled_platforms' => is_array($row?->enabled_platforms) ? array_values($row->enabled_platforms) : [],
            'ios_account_mode' => $row?->ios_account_mode,
            'android_account_mode' => $row?->android_account_mode,
            'web_account_mode' => $row?->web_account_mode,
            'has_asc_key' => (bool) ($row?->has_asc_key ?? false),
            'has_play_service_account' => (bool) ($row?->has_play_service_account ?? false),
            'has_onesignal_key' => (bool) ($row?->has_onesignal_key ?? false),
            'has_onesignal_app' => filled($row?->onesignal_app_id),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function domain(Masjid $org): array
    {
        return MasjidDomain::query()
            ->where('masjid_id', $org->id)
            ->orderBy('id')
            ->get()
            ->map(fn (MasjidDomain $row) => [
                'host' => $row->host,
                'kind' => $row->kind,
                'status' => $row->status,
                'source' => $row->source,
                'served' => in_array($row->status, MasjidDomain::SERVED, true),
            ])
            ->all();
    }
}
