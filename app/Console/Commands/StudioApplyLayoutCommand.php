<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\Renderer\RendererPurgeScheduler;
use App\Support\Studio\LayoutPresets;
use App\Support\Studio\StarterFacts;
use App\Support\Studio\StarterSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gives an existing organisation a Studio starter site (Studio W2 S11): one
 * that predates Studio, or a Studio organisation that added the web later.
 *
 * A DRY RUN unless --execute is given: it prints what StarterSite::applyTo()
 * would write, computed by the same code (StarterSite::outcome()), and writes
 * nothing. With --execute it writes exactly that. applyTo() never updates or
 * restores: a page whose slug the organisation holds, trashed or not, is
 * skipped with all its sections, so this cannot overwrite a word anyone wrote.
 *
 * On a LIVE organisation it still adds pages and sections under slugs the
 * organisation does not hold (existing-org recon R6), so running it there needs
 * the owner's go per organisation. `theme_settings.tokens.layout` moves a live
 * site's header and footer, so it is written only with --with-theme-layout
 * (plan R13), never as a side effect.
 *
 * Facts come from the live organisation's rows (StarterFacts::fromMasjid), in
 * English: the website locale is Studio W2 S12.
 */
class StudioApplyLayoutCommand extends Command
{
    protected $signature = 'studio:apply-layout
                            {masjid_id : The organisation to give a starter site}
                            {preset : A layout preset of the organisation\'s vertical}
                            {--with-theme-layout : Also set theme_settings.tokens.layout (moves the header and footer)}
                            {--execute : Write it; without this nothing is written}';

    protected $description = 'Give an existing organisation a Studio starter site (dry run unless --execute)';

    public function handle(): int
    {
        $id = (int) $this->argument('masjid_id');
        $presetKey = (string) $this->argument('preset');
        $execute = (bool) $this->option('execute');
        $withThemeLayout = (bool) $this->option('with-theme-layout');

        $org = Masjid::withTrashed()->find($id);

        if ($org === null) {
            return $this->refuse("There is no organisation {$id}.");
        }

        if ($org->trashed()) {
            return $this->refuse("Organisation {$id} ({$org->name}) is archived. Restore it before giving it a starter site.");
        }

        $offered = LayoutPresets::keysFor($org->orgType());

        if (! in_array($presetKey, $offered, true)) {
            return $this->refuse("\"{$presetKey}\" is not a layout preset for a {$org->orgType()} organisation. Choose one of: " . implode(', ', $offered) . '.');
        }

        if ($org->moduleIsOff('website')) {
            return $this->refuse("Organisation {$id} ({$org->name}) has its Website module switched off. Switch it on before giving it a starter site.");
        }

        if ($withThemeLayout && ! $org->themeSettings()->exists()) {
            return $this->refuse("Organisation {$id} ({$org->name}) has no theme settings, so there is no theme layout to set. Run it without --with-theme-layout, or save its theme first.");
        }

        $facts = StarterFacts::fromMasjid($org);
        $themeLayout = LayoutPresets::find($presetKey)['theme_layout'];
        $held = StarterSite::heldPages($org);

        $this->info(sprintf(
            '%s: organisation %d (%s), a %s, preset %s, labels in %s.',
            $execute ? 'EXECUTE' : 'DRY RUN, nothing is written (add --execute to write)',
            $org->id, $org->name, $org->orgType(), $presetKey, $facts->locale,
        ));

        if (! $execute) {
            $this->report(StarterSite::outcome(StarterSite::plan($org, $presetKey, $facts, $held), $held), $held, $withThemeLayout, $themeLayout, false);

            return self::SUCCESS;
        }

        $outcome = DB::transaction(function () use ($org, $presetKey, $facts, $withThemeLayout, $themeLayout) {
            $outcome = StarterSite::applyTo($org, $presetKey, $facts);

            if ($withThemeLayout) {
                $theme = $org->themeSettings()->lockForUpdate()->firstOrFail();
                $tokens = is_array($theme->tokens) ? $theme->tokens : [];
                $tokens['layout'] = $themeLayout;
                $theme->update(['tokens' => $tokens]);
            }

            return $outcome;
        });

        // Committed. The renderer's cached pages do not know the new ones yet.
        RendererPurgeScheduler::afterSave((int) $org->id);

        // tokens.layout is baked into the mobile masjid SHOW payload, so the
        // apps would keep the old header and footer until the TTL, as after a
        // theme save in the admin (ThemeSettingsController::save).
        if ($withThemeLayout) {
            MobileCache::flushFamily($org);
        }

        // Warning, not info: production runs LOG_LEVEL=warning, and this added
        // pages to an organisation's site.
        Log::warning('studio:apply-layout wrote a starter site', [
            'masjid_id' => (int) $org->id,
            'preset' => $presetKey,
            'operator' => self::operator(),
            'pages_created' => count($outcome['created']),
            'pages_skipped' => count($outcome['skipped']),
            'links_to_unlive_pages' => count($outcome['unlive_links']),
            'sections_active' => $outcome['sections_active'],
            'sections_inactive' => count($outcome['sections_inactive']),
            'placeholders_open' => $outcome['placeholders_open'],
            'theme_layout_written' => $withThemeLayout,
        ]);

        $this->report($outcome, $held, $withThemeLayout, $themeLayout, true);

        return self::SUCCESS;
    }

    /**
     * @param  array{preset: string, created: list<string>, skipped: list<string>, sections_active: int, sections_inactive: list<array<string, mixed>>, placeholders_open: int, unlive_links: list<array{page: string, target: string}>}  $outcome
     * @param  array<string, array{id: int, trashed: bool, active: bool}>  $held
     * @param  array<string, string>  $themeLayout  e.g. {header: overlay, footer: columns}
     */
    private function report(array $outcome, array $held, bool $withThemeLayout, array $themeLayout, bool $written): void
    {
        $verb = $written ? 'Created' : 'Would create';

        $this->line(sprintf('  %s %d page(s): %s', $verb, count($outcome['created']), $outcome['created'] === [] ? 'none' : implode(', ', $outcome['created'])));

        foreach ($outcome['skipped'] as $slug) {
            $this->line(sprintf(
                '  Skipped "%s": the organisation already has a %s page with this slug, and it is left untouched.',
                $slug,
                ($held[$slug]['trashed'] ?? false) ? 'trashed' : 'live',
            ));
        }

        foreach ($outcome['unlive_links'] as $link) {
            $this->line(sprintf(
                '  "%s" links to "%s", which the organisation holds but the site does not serve (%s): a banner loses that button, any other section waits inactive.',
                $link['page'],
                $link['target'],
                ($held[$link['target']]['trashed'] ?? false) ? 'trashed' : 'inactive',
            ));
        }

        $this->line(sprintf('  Sections: %d active, %d inactive until filled.', $outcome['sections_active'], count($outcome['sections_inactive'])));

        foreach ($outcome['sections_inactive'] as $section) {
            $this->line(sprintf(
                '    - %s / %s (%s)%s',
                $section['page'],
                $section['title'] !== '' && $section['title'] !== null ? $section['title'] : $section['slot'],
                $section['section_type'],
                $section['hints'] === [] ? '' : ': ' . implode(' ', $section['hints']),
            ));
        }

        $this->line(sprintf('  Placeholders %s: %d.', $written ? 'opened' : 'to open', $outcome['placeholders_open']));

        $this->line($withThemeLayout
            ? sprintf(
                '  Theme layout: %s %s (moves the header and footer).',
                $written ? 'set to' : 'would be set to',
                implode(', ', array_map(fn ($part, $value) => "{$part} {$value}", array_keys($themeLayout), $themeLayout)),
            )
            : '  Theme layout: unchanged (--with-theme-layout sets it).');
    }

    private function refuse(string $sentence): int
    {
        $this->error($sentence);

        return self::FAILURE;
    }

    /** Who ran it, from the operating system, for the log line. */
    private static function operator(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && ($user['name'] ?? '') !== '') {
                $sudo = getenv('SUDO_USER');

                return $sudo !== false && $sudo !== '' ? "{$user['name']} (sudo from {$sudo})" : $user['name'];
            }
        }

        $env = getenv('USER');

        return $env !== false && $env !== '' ? $env : 'unknown';
    }
}
