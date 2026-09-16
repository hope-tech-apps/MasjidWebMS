<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Models\ContactReason;
use App\Models\ContactUsMessage;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidMobileAppFeature;
use App\Models\Service;
use App\Support\AppMenu;
use App\Support\GivingSwitch;
use Illuminate\Console\Command;

/**
 * What the app-features cutover WOULD do, printed, with nothing written.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SHIPS A WEEK BEFORE THE MIGRATION IT DESCRIBES
 * ---------------------------------------------------------------------------
 * S2b replaces the `masjid_mobile_app_features` pivot — the switch that decides
 * what the app's drawer lists today — with the organisation module switches. For
 * most rows the two already agree. Where they do not, somebody has to CHOOSE,
 * and the choice is not a developer's: turning an app row off by switching the
 * module off also hides an admin screen, closes a public intake form, or takes
 * a section off a website.
 *
 * Those choices take an owner days, not minutes. So the plan runs first, on its
 * own, and the resolutions land in `config/app_feature_cutover.php` before the
 * migration is written. Running it early is the whole point: it starts the
 * owner's clock.
 *
 * ---------------------------------------------------------------------------
 * IT WRITES NOTHING, AND SAYS SO OUT LOUD
 * ---------------------------------------------------------------------------
 * Every module switch in this application is written through one path that
 * records a `masjid_capability_changes` row. So "this command changed nothing"
 * is checkable rather than merely intended: the ledger count and the pivot row
 * count are taken before and after, and a difference is a hard failure with a
 * message saying the plan wrote something it must not have. See
 * AppFeatureCutoverPlanTest, which is what pins it.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR THINGS IT CAN FIND
 * ---------------------------------------------------------------------------
 *   (a) CONTENT   an app row is OFF on a key whose admin module still holds
 *                 live content or open intake — announcements, an About page,
 *                 gallery photos, services, contact reasons or messages, a
 *                 donation link. Writing that OFF takes the screen away from
 *                 the organisation's own admins and, for contact requests, shuts
 *                 a public form. BLOCKING: somebody has to say which they meant.
 *
 *   (b) GIVING    the app's Donate row is OFF at an organisation whose Giving
 *                 has a connected Stripe account, active funds, or monthly gifts
 *                 Stripe can still bill. The literal mapping never writes
 *                 `giving = true`, and it may only write `giving = false` when
 *                 that is safe — a switch must never be the thing that stops a
 *                 donor's gift being visible to the people who owe them a
 *                 receipt. BLOCKING.
 *
 *   (c) NO ROWS   the organisation has no pivot rows at all, so its `/features`
 *                 returns `[]` today and would return eleven rows after the
 *                 cutover. It is the ONLY change an installed build can see, so
 *                 it is listed even though nothing is in conflict. A NOTICE.
 *
 *   (d) WORSHIP   a masjid with one of the five worship rows switched off in
 *                 the pivot. There is no admin screen behind those, so nothing
 *                 is in conflict — it is listed because the owner should see
 *                 that, for example, Qur'an really is off today at the
 *                 organisations where it is off, rather than discovering it in
 *                 an app. A NOTICE.
 *
 * Blocking findings set the exit code; notices never do. A plan with notices and
 * no blockers is a plan that is ready.
 *
 * ---------------------------------------------------------------------------
 * THE MAPPING IS LITERAL, AND THE ASYMMETRY IS DELIBERATE
 * ---------------------------------------------------------------------------
 * ids 1-5 → the five worship modules, 7 → about_us, 8 → gallery, 9 → services,
 * 10 → announcements (never `events`), 11 → contact_requests.
 *
 * id 6 (Donate) is the one that is not one-to-one. `AppMenu` shows the menu's
 * donate entry when `donation_link` OR `giving` is on, so the cutover writes
 * `donation_link` and treats `giving` as a separate, guarded decision:
 *
 *   pivot ON   → donation_link = true. NEVER giving = true: giving carries
 *                Stripe, funds and receipts, and nothing about an app drawer row
 *                is evidence that an organisation is ready to take card gifts.
 *   pivot OFF  → donation_link = false, and giving = false only where that is
 *                already safe (the same precondition the SuperAdmin switch
 *                enforces) or where the owner has resolved it.
 */
class AppFeaturesCutoverPlan extends Command
{
    protected $signature = 'app-features:cutover-plan
                            {--org= : Plan one organisation id instead of all of them}
                            {--conflicts-only : Print only the rows that need a decision}
                            {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Print what the legacy app-features cutover would write, per organisation and legacy id. Read-only: it writes nothing.';

    /** Exit code when at least one BLOCKING conflict is unresolved. */
    public const EXIT_CONFLICTS = 2;

    /**
     * The literal A2 mapping: legacy Mobile App Features id → the module key the
     * cutover writes. Id 6 is handled separately (see the class docblock) and is
     * listed here as the key it writes, not as both keys it reads.
     */
    public const LEGACY_MAP = [
        1 => 'quran',
        2 => 'hadith',
        3 => 'adhkar',
        4 => 'qibla',
        5 => 'tasbih',
        6 => 'donation_link',
        7 => 'about_us',
        8 => 'gallery',
        9 => 'services',
        10 => 'announcements',
        11 => 'contact_requests',
    ];

    /** The app-only ids: a switch here changes one row of the app menu and nothing else. */
    public const WORSHIP_IDS = [1, 2, 3, 4, 5];

    /**
     * What switching each module OFF costs an organisation beyond the app row.
     * Deliberately written as consequences rather than as screen names: the
     * person resolving these is deciding whether to take something away.
     */
    private const SIDE_EFFECTS = [
        'quran' => 'None. App-only module; there is no admin screen.',
        'hadith' => 'None. App-only module; there is no admin screen.',
        'adhkar' => 'None. App-only module; there is no admin screen.',
        'qibla' => 'None. App-only module; there is no admin screen.',
        'tasbih' => 'None. App-only module; there is no admin screen.',
        'donation_link' => 'Admins can no longer change the donation link. The link already set keeps showing on the website, the TV board QR code and the app.',
        'about_us' => 'The About Us, mission and vision editor is hidden from this organisation\'s admins. Text already published keeps showing.',
        'gallery' => 'The photo gallery editor is hidden from this organisation\'s admins. Photos already uploaded keep showing.',
        'services' => 'The services editor is hidden. The list already published stays, and Broadcasts, Friday lunch and About Us can still use it.',
        'announcements' => 'The announcements editor and the Announcements broadcast channel are hidden. Announcements already published keep showing on the website and in the app.',
        'contact_requests' => 'PUBLIC INTAKE CLOSES: the website and app contact forms refuse new messages, and the reasons and inbox are hidden from admins.',
    ];

    public function handle(): int
    {
        // The promise this command makes, measured rather than asserted in prose.
        $ledgerBefore = MasjidCapabilityChange::query()->count();
        $pivotBefore = MasjidMobileAppFeature::query()->count();

        $orgs = $this->organisations();

        if ($orgs === null) {
            return self::FAILURE;
        }

        $plan = [];

        foreach ($orgs as $org) {
            $plan[] = $this->planFor($org);
        }

        $blocking = 0;
        $notices = 0;

        foreach ($plan as $orgPlan) {
            foreach ($orgPlan['findings'] as $finding) {
                $finding['blocking'] ? $blocking++ : $notices++;
            }
        }

        $this->assertWroteNothing($ledgerBefore, $pivotBefore);

        $summary = [
            'organisations' => count($plan),
            'blocking' => $blocking,
            'notices' => $notices,
            'resolutions_file' => config('app_feature_cutover') === null
                ? 'config/app_feature_cutover.php does not exist yet'
                : 'config/app_feature_cutover.php',
            'plan' => $plan,
        ];

        return $this->option('json')
            ? $this->emitJson($summary)
            : $this->emitTables($summary);
    }

    // ------------------------------------------------------------- the reading

    /** @return \Illuminate\Support\Collection<int, Masjid>|null */
    private function organisations()
    {
        $id = trim((string) $this->option('org'));

        if ($id === '') {
            return Masjid::query()->orderBy('id')->get();
        }

        $org = Masjid::find($id);

        if ($org === null) {
            $this->error("No organisation with id {$id}.");

            return null;
        }

        return collect([$org]);
    }

    /** @return array<string, mixed> */
    private function planFor(Masjid $org): array
    {
        // One read of the pivot per organisation: id => is_available.
        $pivot = MasjidMobileAppFeature::query()
            ->where('masjid_id', $org->id)
            ->pluck('is_available', 'feature_id')
            ->map(fn ($value) => (bool) $value)
            ->all();

        $rows = [];
        $findings = [];

        // (c) No pivot rows at all. Today `/features` answers `[]` and every
        // installed build draws an empty drawer; after the cutover it answers
        // eleven rows derived from the switches. That is the only change a
        // build nobody has updated can actually see, so it is said first.
        if ($pivot === []) {
            $findings[] = $this->finding(
                'c',
                false,
                null,
                'This organisation has no app-feature rows, so GET /features returns [] today. After the cutover it returns 11 rows derived from its switches — the only change an installed build sees.'
            );
        }

        foreach (self::LEGACY_MAP as $legacyId => $key) {
            $pivotValue = $pivot[$legacyId] ?? null;
            $derived = AppMenu::legacyAvailability($org, $legacyId);
            $writes = $this->writesFor($org, $legacyId, $key, $pivotValue);

            $rows[] = [
                'legacy_id' => $legacyId,
                'key' => $key,
                'pivot' => $pivotValue,
                'derived_today' => $derived,
                'agrees' => $pivotValue === null ? null : ($pivotValue === $derived),
                'writes' => $writes,
                'side_effect' => $pivotValue === false
                    ? (self::SIDE_EFFECTS[$key] ?? 'Unknown module.')
                    : 'None: nothing is switched off.',
            ];

            if ($pivotValue !== false) {
                continue;
            }

            // (d) A worship row off at a masjid. No admin side effect; listed so
            // nobody discovers it in an app.
            if (in_array($legacyId, self::WORSHIP_IDS, true)) {
                if ($org->isMasjid()) {
                    $findings[] = $this->finding(
                        'd',
                        false,
                        $key,
                        "Legacy id {$legacyId} ({$key}) is OFF in the pivot at a masjid. No admin screen is affected; the cutover switches the module off and the app menu stops listing it."
                    );
                }

                continue;
            }

            // (b) Donate off while Giving still has money attached to it.
            if ($legacyId === 6) {
                $giving = $this->givingEvidence($org);

                if ($giving !== []) {
                    $findings[] = $this->finding(
                        'b',
                        ! $this->isResolved($org, 'giving'),
                        'giving',
                        'Donate is OFF in the pivot, and Giving still has: ' . implode('; ', $giving)
                            . '. The cutover writes donation_link=false and will NOT touch giving until this is resolved.'
                    );
                }
            }

            // (a) An app row off on a key whose admin module still holds live
            // content or open intake.
            $content = $this->contentEvidence($org, $key);

            if ($content !== []) {
                $findings[] = $this->finding(
                    'a',
                    ! $this->isResolved($org, $key),
                    $key,
                    "Legacy id {$legacyId} is OFF in the pivot, but {$key} still holds: " . implode('; ', $content)
                        . '. Switching it off also means: ' . (self::SIDE_EFFECTS[$key] ?? 'unknown.')
                );
            }
        }

        return [
            'masjid_id' => (int) $org->id,
            'name' => (string) $org->name,
            'org_type' => (string) $org->org_type,
            'has_pivot_rows' => $pivot !== [],
            'rows' => $rows,
            'findings' => $findings,
        ];
    }

    /**
     * The override the migration would write for this id, as `key => bool`.
     *
     * An id with no pivot row writes nothing: there is no decision on record to
     * carry over, and inventing one would change what an installed build sees.
     *
     * @return array<string, bool>
     */
    private function writesFor(Masjid $org, int $legacyId, string $key, ?bool $pivotValue): array
    {
        if ($pivotValue === null) {
            return [];
        }

        if ($legacyId !== 6) {
            return [$key => $pivotValue];
        }

        // Donate ON: the link only. Never giving — an app drawer row is not
        // evidence that an organisation is ready to take card gifts.
        if ($pivotValue === true) {
            return ['donation_link' => true];
        }

        $writes = ['donation_link' => false];

        // Donate OFF: giving follows only where switching it off is already
        // safe, or where the owner has resolved it. This is the same
        // precondition MasjidsController::setCapability enforces by hand.
        if ($this->givingEvidence($org) === [] || $this->isResolved($org, 'giving')) {
            $writes['giving'] = false;
        }

        return $writes;
    }

    /**
     * What Giving still has attached to it, in sentences, or [] when switching
     * it off would cost nothing.
     *
     * @return list<string>
     */
    private function givingEvidence(Masjid $org): array
    {
        $evidence = [];

        if ($org->canAcceptDonations()) {
            $evidence[] = 'a connected Stripe account that can take card gifts';
        }

        $funds = Fund::withoutMasjidScope()
            ->where('masjid_id', $org->id)
            ->where('is_active', true)
            ->count();

        if ($funds > 0) {
            $evidence[] = $funds . ' active ' . ($funds === 1 ? 'fund' : 'funds');
        }

        $live = GivingSwitch::liveSubscriptionCount($org);

        if ($live > 0) {
            $evidence[] = $live . ' monthly ' . ($live === 1 ? 'gift' : 'gifts') . ' Stripe can still bill';
        }

        $open = GivingSwitch::openCheckoutCount($org);

        if ($open > 0) {
            $evidence[] = $open . ' monthly-gift checkout ' . ($open === 1 ? 'page' : 'pages') . ' still open';
        }

        return $evidence;
    }

    /**
     * What an admin module still holds, in sentences, or [] when taking the
     * screen away costs this organisation nothing today.
     *
     * Contact requests answers non-empty even with nothing stored, because the
     * cost there is not stored content — it is that a PUBLIC FORM stops
     * accepting messages, which no row count can show.
     *
     * @return list<string>
     */
    private function contentEvidence(Masjid $org, string $key): array
    {
        $evidence = [];

        switch ($key) {
            case 'announcements':
                $count = Announcement::where('masjid_id', $org->id)->count();

                if ($count > 0) {
                    $evidence[] = $count . ' ' . ($count === 1 ? 'announcement' : 'announcements');
                }

                break;

            case 'about_us':
                if (MasjidAbout::where('masjid_id', $org->id)->exists()) {
                    $evidence[] = 'an About Us / mission / vision record';
                }

                break;

            case 'gallery':
                $count = $org->gallery()->count();

                if ($count > 0) {
                    $evidence[] = $count . ' gallery ' . ($count === 1 ? 'photo' : 'photos');
                }

                break;

            case 'services':
                $count = Service::where('masjid_id', $org->id)->count();

                if ($count > 0) {
                    $evidence[] = $count . ' ' . ($count === 1 ? 'service' : 'services');
                }

                break;

            case 'contact_requests':
                $reasons = ContactReason::where('masjid_id', $org->id)->count();
                $messages = ContactUsMessage::where('masjid_id', $org->id)->count();

                if ($reasons > 0) {
                    $evidence[] = $reasons . ' contact ' . ($reasons === 1 ? 'reason' : 'reasons');
                }

                if ($messages > 0) {
                    $evidence[] = $messages . ' received ' . ($messages === 1 ? 'message' : 'messages');
                }

                // Said even at zero: the loss here is future messages, and a
                // form nobody has used yet still stops working.
                $evidence[] = 'an open public contact form';

                break;

            case 'donation_link':
                if ($org->donationLink()->exists()) {
                    $evidence[] = 'a donation link already set';
                }

                break;
        }

        return $evidence;
    }

    /**
     * Has the owner already decided this one?
     *
     * `config/app_feature_cutover.php` does not exist until the resolutions are
     * written, so an absent file is the normal early state and means "nothing is
     * resolved yet", never an error.
     */
    private function isResolved(Masjid $org, string $key): bool
    {
        $resolutions = config('app_feature_cutover');

        if (! is_array($resolutions)) {
            return false;
        }

        $decision = $resolutions[(int) $org->id][$key] ?? null;

        return in_array($decision, ['show_in_app', 'hide_everywhere'], true);
    }

    /** @return array<string, mixed> */
    private function finding(string $class, bool $blocking, ?string $key, string $message): array
    {
        return [
            'class' => $class,
            'blocking' => $blocking,
            'key' => $key,
            'message' => $message,
        ];
    }

    // -------------------------------------------------------- the read-only vow

    /**
     * The command's one promise, checked rather than claimed.
     *
     * Every module switch in this application writes a `masjid_capability_changes`
     * row through one path, so a ledger that grew during a plan run means the
     * plan wrote a switch. A pivot row count that moved means it wrote the old
     * table. Either is a bug serious enough to stop on: the whole reason this
     * runs on production is that it cannot change production.
     */
    private function assertWroteNothing(int $ledgerBefore, int $pivotBefore): void
    {
        $ledgerAfter = MasjidCapabilityChange::query()->count();
        $pivotAfter = MasjidMobileAppFeature::query()->count();

        if ($ledgerAfter !== $ledgerBefore) {
            throw new \RuntimeException(
                "app-features:cutover-plan is read-only, but the capability ledger went from {$ledgerBefore} to {$ledgerAfter} rows during this run."
            );
        }

        if ($pivotAfter !== $pivotBefore) {
            throw new \RuntimeException(
                "app-features:cutover-plan is read-only, but masjid_mobile_app_features went from {$pivotBefore} to {$pivotAfter} rows during this run."
            );
        }
    }

    // ---------------------------------------------------------------- output

    /** @param array<string, mixed> $summary */
    private function emitJson(array $summary): int
    {
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $summary['blocking'] > 0 ? self::EXIT_CONFLICTS : self::SUCCESS;
    }

    /** @param array<string, mixed> $summary */
    private function emitTables(array $summary): int
    {
        $this->info('app-features cutover plan — READ ONLY, nothing below has been written.');
        $this->line("  Organisations: {$summary['organisations']}    Decisions needed: {$summary['blocking']}    Notices: {$summary['notices']}");
        $this->line("  Resolutions: {$summary['resolutions_file']}");
        $this->newLine();

        foreach ($summary['plan'] as $orgPlan) {
            if ($this->option('conflicts-only') && $orgPlan['findings'] === []) {
                continue;
            }

            $this->line("<comment>#{$orgPlan['masjid_id']} {$orgPlan['name']}</comment> ({$orgPlan['org_type']})");

            if (! $orgPlan['has_pivot_rows']) {
                $this->line('  No app-feature rows at all.');
            }

            if (! $this->option('conflicts-only')) {
                $this->table(
                    ['id', 'Key', 'Pivot', 'Switches today', 'Would write', 'Also means'],
                    array_map(fn (array $row) => [
                        $row['legacy_id'],
                        $row['key'],
                        $this->tick($row['pivot']),
                        $this->tick($row['derived_today']),
                        $row['writes'] === []
                            ? '—'
                            : implode(', ', array_map(
                                fn (string $key, bool $value) => $key . '=' . ($value ? 'true' : 'false'),
                                array_keys($row['writes']),
                                $row['writes']
                            )),
                        $row['side_effect'],
                    ], $orgPlan['rows'])
                );
            }

            foreach ($orgPlan['findings'] as $finding) {
                $line = "  [{$finding['class']}] {$finding['message']}";

                $finding['blocking'] ? $this->warn($line) : $this->line($line);
            }

            $this->newLine();
        }

        if ($summary['blocking'] > 0) {
            $this->warn("{$summary['blocking']} decision(s) need the owner before the cutover migration can run.");
            $this->line('  Record each one in config/app_feature_cutover.php as org => key => show_in_app | hide_everywhere.');

            return self::EXIT_CONFLICTS;
        }

        $this->info('No decision is outstanding. Notices above are for information only.');

        return self::SUCCESS;
    }

    private function tick(?bool $value): string
    {
        return match ($value) {
            true => 'on',
            false => 'OFF',
            null => 'no row',
        };
    }
}
