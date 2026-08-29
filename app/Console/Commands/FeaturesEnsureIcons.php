<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Models\MobileAppFeature;
use App\Support\MobileCache;
use Illuminate\Console\Command;

/**
 * Re-attach any MISSING mobile feature icon from the surviving SVGs under
 * storage/app/public/icons. This is the standing recovery path for the
 * 2026-08-28 outage: the `featuresIcons` media rows were gone (collateral of the
 * canary media-wipe), every feature served icon:null, and the Flutter drawer —
 * which force-unwraps feature.icon!.originalUrl! — rendered empty. It restores
 * exactly what was lost, the same way the (now re-enabled at the call site)
 * MobileAppFeaturesSeeder would.
 *
 * ==========================================================================
 * ADDITIVE, IDEMPOTENT, AND DELIBERATELY NOT SCHEDULED
 * ==========================================================================
 *
 * It only ATTACHES, and only to a feature that has NO icon — a feature that
 * already has one is never touched, so it is safe to run repeatedly and safe to
 * run at deploy time. It is NOT registered in the schedule on purpose: an
 * auto-healing cron would silently re-attach icons a wipe had removed, and the
 * watchdog (`media:verify`) would then see them present and never alert — the
 * loss would be masked instead of surfaced. The division of labour is the one
 * this codebase already uses: the verifier detects and pages, the OPERATOR
 * repairs, and this is the operator's tool (and a deploy step).
 *
 * `preservingOriginal()` keeps the source SVG at storage/app/public/icons, so
 * nothing is consumed and a later run finds the same files.
 */
class FeaturesEnsureIcons extends Command
{
    protected $signature = 'app:features-ensure-icons {--json : Emit the run as JSON and nothing else}';

    protected $description = 'Re-attach any missing mobile feature icon from storage/app/public/icons (idempotent; operator/deploy-run, never auto-scheduled).';

    /**
     * Feature icon file per NORMALISED feature key (lower-cased, non-alphanumerics
     * stripped, so both `qur'an` and `quran` resolve and `adhkar` maps to the file
     * it shipped as). Mirrors the fallback map in the mobile features controller.
     */
    private const ICON_FILES = [
        'quran' => 'alqurann.svg',
        'hadith' => 'hadith.svg',
        'adhkar' => 'azkar.svg',
        'azkar' => 'azkar.svg',
        'qibla' => 'qibla.svg',
        'tasbih' => 'tasbih.svg',
        'donate' => 'donate.svg',
        'aboutus' => 'about_us.svg',
        'gallery' => 'gallery.svg',
        'services' => 'services.svg',
        'announcements' => 'announcements.svg',
        'contactus' => 'contact.svg',
    ];

    public function handle(): int
    {
        $attached = [];
        $already = [];
        $unresolved = [];

        foreach (MobileAppFeature::all() as $feature) {
            if ($feature->icon) {
                $already[] = $feature->name;

                continue;
            }

            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $feature->key));
            $file = self::ICON_FILES[$key] ?? null;
            $path = $file !== null ? storage_path('app/public/icons/'.$file) : null;

            if ($file === null || ! is_file($path)) {
                $unresolved[] = [
                    'feature' => $feature->name,
                    'key' => (string) $feature->key,
                    'reason' => $file === null ? 'no icon mapping' : "source file missing: icons/{$file}",
                ];

                continue;
            }

            $media = $feature->addMedia($path)->preservingOriginal()->toMediaCollection('featuresIcons');
            $attached[] = ['feature' => $feature->name, 'media_id' => (int) $media->id, 'url' => $media->getFullUrl()];
        }

        // Feature icons are GLOBAL (attached to the shared MobileAppFeature rows),
        // but /features is cached PER masjid (10-min TTL). Without this flush a
        // correct repair looks like it did nothing for up to ten minutes — which
        // invites a second, redundant "fix". Only when something actually changed.
        $flushed = 0;
        if ($attached !== []) {
            foreach (Masjid::pluck('id') as $mid) {
                MobileCache::flushMasjid((int) $mid, MobileCache::FEATURES);
                $flushed++;
            }
        }

        $payload = [
            'attached' => $attached,
            'already_had_icon' => count($already),
            'unresolved' => $unresolved,
            'masjid_caches_flushed' => $flushed,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(count($attached).' icon(s) attached, '.count($already).' already present, '.count($unresolved).' unresolved.');
            foreach ($attached as $a) {
                $this->line("  + {$a['feature']} -> media {$a['media_id']}");
            }
            foreach ($unresolved as $u) {
                $this->warn("  ! {$u['feature']}: {$u['reason']}");
            }
            if ($flushed > 0) {
                $this->line("  flushed FEATURES cache for {$flushed} masjid(s).");
            }
        }

        // Non-zero when a feature is left without an icon we could restore — that
        // is a real gap (a new feature with no shipped SVG, or a lost source
        // file) an operator should see, and it fails a deploy gate correctly.
        return $unresolved === [] ? self::SUCCESS : self::FAILURE;
    }
}
