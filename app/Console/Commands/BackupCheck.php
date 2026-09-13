<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupSet;
use App\Support\Backup\HalfIntegrity;
use App\Support\Backup\OffsiteShipper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Is there a recent, verified backup on this machine — and is there a copy of it
 * anywhere else?
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS. THE PLATFORM HAD NEVER TAKEN A BACKUP.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Not a stale one. None, ever. On 2026-09-12 production was found in this state:
 *
 *   - `backup:run` had been scheduled nightly at 02:40 for weeks;
 *   - its destination, /var/backups/manara, DID NOT EXIST, because the one-time
 *     `sudo bin/backup --install` was never run on that box;
 *   - `backup:run` handled that correctly and refused every single night with
 *     "The backup destination is not usable, so nothing was written.";
 *   - nobody ever saw that sentence, because the cron line ends in
 *     `>> /dev/null 2>&1` and nothing else was watching.
 *
 * Every check in `backup:run` worked. Every refusal was right. The gap is that
 * from the outside — which is where everybody stands — a refusal and a success
 * look identical, and this platform stood outside for weeks.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE DESIGN PROPERTY THAT MATTERS: THIS IS A PULL, NOT A PUSH
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The obvious watchdog is one that fires when `backup:run` reports a failure.
 * That watchdog would have said nothing for the entire outage, because on most
 * of those nights `backup:run` did not report a failure — it never ran at all,
 * or it ran and its refusal went to /dev/null. An alerter driven by events is
 * silent exactly when the event source dies, and the event source dying is the
 * failure being watched for.
 *
 * So this command never looks at `backup:run`. It looks at the DESTINATION and
 * asks one question with a timestamp in it: how old is the newest set here that
 * passes every check we would apply to a set we had just written? Nothing has to
 * tell it anything. A backup that stops happening produces a growing number, and
 * a growing number crosses a threshold on its own.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AND THE HARDER QUESTION: WHAT IF THIS COMMAND ITSELF STOPS RUNNING?
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A checker whose own failure is invisible reproduces the bug it exists to
 * catch. There are four defences here and they are deliberately not all of the
 * same kind, because three of them share a single point of failure:
 *
 *  1. IT DOES NOT DEPEND ON THE THING IT WATCHES. Separate schedule entry,
 *     separate hour, separate `withoutOverlapping` lock. A `backup:run` wedged
 *     under a stuck lock — the failure mode `withoutOverlapping(60)` exists to
 *     bound — cannot take this with it, and neither can a `backup:run` that
 *     throws on the first line.
 *
 *  2. IT WRITES ITS OWN HISTORY AND READS IT BACK. Every run stamps
 *     `backup.check.heartbeat_path` and reports the gap since the previous one.
 *     A checker that has been dead for a week and then runs SAYS SO, in its own
 *     output, at `warning`. Without this, a resumed watchdog reports a green
 *     platform and nothing anywhere records that it was blind.
 *
 *  3. ONE LINE PER RUN, ON EVERY RUN INCLUDING A CLEAN ONE. `schedule:run`
 *     discards stdout, so the log line is the whole of the scheduled alert path.
 *     A clean line every day means the ABSENCE of one is visible, which is the
 *     only way silence can carry information.
 *
 *  4. THE ONE THAT SURVIVES THE HOST: an outbound ping, after a clean run only,
 *     to `backup.check.heartbeat_url` — a dead man's switch that alerts when it
 *     is NOT called. Defences 1-3 are all in-band: a file on the disk being
 *     watched, a log line written by the application being watched, an email
 *     sent by the mailer on the host being watched. If cron dies, if PHP-FPM
 *     dies, if the droplet dies, all three go quiet together, and quiet is what
 *     a healthy night looks like. Only an outside observer expecting to hear
 *     from us distinguishes those.
 *
 *     IT IS UNSET TODAY. Until it is set, this checker's own silence is
 *     DETECTABLE — the heartbeat file and the missing daily log line both show
 *     it to anybody who looks — and it is not ALERTED. That is the honest
 *     residual risk of this design and it is written here rather than implied
 *     away. The ping fires only after a PASS, so a failing check and a dead
 *     check both raise the outside alarm.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EXIT CODES — the vocabulary `tenancy:canary` and `media:verify` already use
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   0  pass      info      a verified set younger than the threshold is here,
 *                          and the off-site position is as configured
 *   1  failed    error     a finding — no set, no recent set, a set that no
 *                          longer verifies, an unusable destination, or an
 *                          off-site copy that is configured and not there  PAGE
 *   2  blocked   error     this run is not evidence about this platform: no
 *                          destination is configured at all                PAGE
 *   3  partial   warning   it IS evidence and it names what it could not see —
 *                          crashed `*.partial` runs left behind, or a gap in
 *                          this checker's own history                    TICKET
 *
 * NOT CONFIGURED IS NOT THE SAME AS BROKEN. With no off-site store configured,
 * this says so on EVERY run, in the human report and in the log summary, in a
 * sentence an operator does not have to read code to understand — and it does
 * not page, because today that is a known and ticketed gap and an amber that
 * burns every ordinary night is an amber that gets silenced. Set
 * BACKUP_OFFSITE_ENABLED, and a missing off-site copy becomes a finding.
 */
class BackupCheck extends Command
{
    protected $signature = 'backup:check
                            {--max-age= : Override backup.check.max_age_hours for this run}
                            {--json : Emit the verdict as JSON on stdout}';

    protected $description = 'Fail loudly when there is no recent, verified, off-site backup.';

    private const EXIT_PASS = 0;

    private const EXIT_FAILED = 1;

    private const EXIT_BLOCKED = 2;

    private const EXIT_PARTIAL = 3;

    /** @var list<array{severity: string, kind: string, summary: string}> */
    private array $findings = [];

    /** @var list<string> */
    private array $degradedBy = [];

    public function handle(): int
    {
        // A SECOND RUN IN ONE PROCESS MUST NOT INHERIT THE FIRST RUN'S VERDICT.
        //
        // Artisan caches command instances, so these two arrays survive from one
        // invocation to the next inside a single process. Left alone, a check
        // that correctly found no backups would keep reporting "NOTHING CAN BE
        // RESTORED" on every later run in that process, even with a complete
        // verified set on the disk — the watchdog stuck barking at a problem
        // that had been fixed, which is how a watchdog gets ignored.
        //
        // Cron gives each nightly run its own process, so this was not reachable
        // in production. `schedule:run` is the one that could: it executes every
        // command due in the same minute in ONE process.
        $this->findings = [];
        $this->degradedBy = [];

        $config = (array) config('backup');
        $now = CarbonImmutable::now('UTC');
        $destination = rtrim((string) ($config['destination'] ?? ''), '/');

        if ($destination === '') {
            return $this->verdict('blocked', $now, [
                'destination' => null,
            ], 'No backup destination is configured (backup.destination is empty), so this check has nothing to look at and cannot say anything about this platform.');
        }

        $maxAgeHours = (int) ($this->option('max-age') ?? $config['check']['max_age_hours'] ?? 36);

        $payload = [
            'destination' => $destination,
            'max_age_hours' => $maxAgeHours,
        ];

        // 1. The destination itself. THIS is the check that would have caught
        //    the actual incident on its first night: the directory was never
        //    created, `backup:run` refused correctly every night, and the
        //    refusal went to /dev/null.
        if (! is_dir($destination)) {
            $this->finding('error', 'destination_missing', sprintf(
                '%s does not exist, so every scheduled `backup:run` is refusing and writing nothing. NOTHING IS BEING '
                .'BACKED UP. Fix with: sudo bin/backup --install',
                $destination,
            ));
        } elseif (! is_writable($destination)) {
            $this->finding('error', 'destination_unwritable', sprintf(
                '%s is not writable by the user the scheduler runs as, so every scheduled `backup:run` is refusing and '
                .'writing nothing. Fix with: sudo bin/backup --install',
                $destination,
            ));
        }

        $sets = BackupSet::all($destination);
        $payload['sets_held'] = count($sets);

        // 2. A crashed run leaves a `*.partial` that nothing will ever restore.
        //    One is litter. A growing pile of them is a run that is failing at
        //    the same point every night, which is a different sentence.
        $partials = $this->stalePartials($destination, $now);
        $payload['stale_partials'] = $partials;

        if ($partials !== []) {
            $this->degradedBy[] = 'stale_partial_runs';
        }

        // 3. The newest set that passes every check we would apply to one we had
        //    just written. Walked newest-first: a corrupt newest set is a
        //    finding in its own right AND must not be allowed to hide a
        //    perfectly good set behind it, because those are two different
        //    conversations with the operator.
        $newestVerified = null;
        $examined = [];

        foreach ($sets as $set) {
            $problems = $this->examine($set, $config);

            $examined[] = ['set' => $set->id(), 'problems' => $problems];

            if ($problems === []) {
                $newestVerified = $set;

                break;
            }
        }

        $payload['examined'] = $examined;

        if ($sets === []) {
            // Distinct from "the newest is old". No set has EVER been written
            // here, which is the state production was actually in.
            $this->finding('error', 'no_set_ever', sprintf(
                'There is not one finished backup set in %s. Either nothing has ever succeeded here, or something '
                .'removed them all. NOTHING CAN BE RESTORED.',
                $destination,
            ));
        } elseif ($newestVerified === null) {
            $this->finding('error', 'no_verified_set', sprintf(
                'All %d set(s) in %s failed verification, so there is nothing here that can be restored. First failure: %s',
                count($sets),
                $destination,
                $examined[0]['problems'][0] ?? 'no reason recorded',
            ));
        } else {
            // The newest set failing while an older one passes is its own
            // finding: last night's backup is broken even though the platform
            // is not yet unprotected.
            if ($examined[0]['set'] !== $newestVerified->id()) {
                $this->finding('error', 'newest_set_corrupt', sprintf(
                    'The newest set %s does not verify (%s). The newest set that does is %s.',
                    $examined[0]['set'],
                    $examined[0]['problems'][0] ?? 'no reason recorded',
                    $newestVerified->id(),
                ));
            }

            $takenAt = $this->takenAt($newestVerified);
            $ageHours = $takenAt === null ? null : round($takenAt->diffInMinutes($now) / 60, 1);

            $payload['newest_verified'] = [
                'set' => $newestVerified->id(),
                'taken_at' => $takenAt?->toIso8601String(),
                'age_hours' => $ageHours,
                'bytes' => $newestVerified->bytes(),
            ];

            if ($takenAt === null) {
                // A manifest with no readable timestamp cannot be aged, and an
                // unaged set is not evidence that backups are current.
                $this->finding('error', 'newest_set_undateable', sprintf(
                    'Set %s verifies and its manifest carries no readable `created_at`, so there is no way to say how '
                    .'old this platform\'s newest backup is.',
                    $newestVerified->id(),
                ));
            } elseif ($ageHours > $maxAgeHours) {
                $this->finding('error', 'stale', sprintf(
                    'The newest verified set (%s) was taken %s hours ago, past the %d-hour threshold. `backup:run` is '
                    .'scheduled nightly, so this means at least one night produced nothing and nobody was told.',
                    $newestVerified->id(),
                    $ageHours,
                    $maxAgeHours,
                ));
            }
        }

        // 4. The off-site position, stated on every run whatever it is.
        $payload['offsite'] = $this->offsite($config, $newestVerified);

        // 5. This checker's own history, before it overwrites it.
        $payload['self'] = $this->selfHistory($config, $now);

        $this->writeHeartbeat($config, $now);

        return $this->verdict($this->status(), $now, $payload);
    }

    /**
     * Every reason this set could not be restored right now — the manifest-level
     * checks `backup:restore` makes, PLUS the inside-the-bytes checks
     * `backup:run` makes before it will call what it wrote a backup.
     *
     * Both come from shared code (BackupSet::problems, HalfIntegrity) rather
     * than being restated here. Two definitions of "a valid set" drift, and the
     * one that drifts is always the one that gets read at 3am.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function examine(BackupSet $set, array $config): array
    {
        $problems = $set->problems();

        if ($problems !== []) {
            return $problems;
        }

        $manifest = (array) $set->manifest();

        foreach ([
            HalfIntegrity::database($set->databasePath(), $config),
            HalfIntegrity::mediaAgainstManifest($set->mediaPath(), $manifest),
        ] as $problem) {
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * Is the newest restorable set anywhere but this disk?
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function offsite(array $config, ?BackupSet $set): array
    {
        $shipper = OffsiteShipper::forConfig($config);
        $target = $shipper->target();

        if (! $target->enabled) {
            $sentence = sprintf(
                'OFF-SITE: NONE. %s Every backup this platform holds is on the same volume as the application it '
                .'backs up, so one destroyed droplet loses both.',
                (string) $target->reason,
            );

            // Not configured and not required is the known, ticketed state of
            // this platform today. It is stated in full on every single run, and
            // it does not page — an alarm that fires on an ordinary Tuesday gets
            // silenced, and then the emergency is unheard. Setting
            // BACKUP_OFFSITE_REQUIRED (or actually enabling it) makes it a
            // finding.
            if ((bool) ($config['offsite']['required'] ?? false)) {
                $this->finding('error', 'offsite_missing', $sentence.' BACKUP_OFFSITE_REQUIRED is set, so this is a failure.');
            }

            return ['status' => OffsiteShipper::NOT_CONFIGURED, 'required' => (bool) ($config['offsite']['required'] ?? false), 'reason' => $target->reason, 'target' => $target->describe()];
        }

        if ($set === null) {
            return ['status' => 'not_applicable', 'reason' => 'there is no verified set to look for off-site', 'target' => $target->describe()];
        }

        $verified = $shipper->verify($set);

        if ($verified['status'] !== OffsiteShipper::PRESENT) {
            // `unreachable` is graded as a failure alongside `absent`, and that
            // is deliberate. BackupRun refuses to write a manifest claiming a
            // completeness nobody could check, on the principle that a claim
            // nobody could check is not one to write down. "I could not confirm
            // there is an off-site copy" is operationally the same position as
            // not having one.
            $this->finding('error', 'offsite_'.$verified['status'], sprintf(
                'OFF-SITE: %s. %s',
                strtoupper((string) $verified['status']),
                (string) ($verified['reason'] ?? 'no reason given'),
            ));
        }

        return ['status' => $verified['status'], 'required' => true, 'reason' => $verified['reason'], 'target' => $target->describe(), 'objects' => $verified['objects']];
    }

    /**
     * When did this command last run, and how big is the gap?
     *
     * A watchdog that cannot say whether it was awake is a watchdog that can
     * stop silently — the exact shape of the bug this command exists for, one
     * level up. Read BEFORE the new heartbeat is written, so the gap reported is
     * the gap that actually happened.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function selfHistory(array $config, CarbonImmutable $now): array
    {
        $path = (string) ($config['check']['heartbeat_path'] ?? '');
        $gapHours = (int) ($config['check']['self_gap_hours'] ?? 36);

        // A MISSING heartbeat is REPORTED AND NOT GRADED, and the distinction
        // matters. The file is absent on the first run after this command
        // exists, and again on any deploy that lands a fresh tree — so grading
        // it would put an amber on an ordinary release, and an amber that burns
        // on an ordinary Tuesday is an amber that gets silenced. What IS graded
        // is a heartbeat that exists and is old: the checker was running, and
        // then it was not, which is the only shape that carries information.
        if ($path === '' || ! is_file($path)) {
            return ['previous_run' => null, 'gap_hours' => null, 'note' => 'this checker has no record of a previous run on this host'];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        $previous = is_array($decoded) ? ($decoded['ran_at'] ?? null) : null;

        if (! is_string($previous)) {
            return ['previous_run' => null, 'gap_hours' => null, 'note' => 'the heartbeat file is unreadable'];
        }

        try {
            $gap = round(CarbonImmutable::parse($previous)->diffInMinutes($now) / 60, 1);
        } catch (Throwable) {
            return ['previous_run' => $previous, 'gap_hours' => null, 'note' => 'the recorded previous run is not a readable timestamp'];
        }

        if ($gap > $gapHours) {
            $this->degradedBy[] = 'checker_was_not_running';
        }

        return [
            'previous_run' => $previous,
            'gap_hours' => $gap,
            'previous_status' => is_array($decoded) ? ($decoded['status'] ?? null) : null,
        ];
    }

    /** @param array<string, mixed> $config */
    private function writeHeartbeat(array $config, CarbonImmutable $now): void
    {
        $path = (string) ($config['check']['heartbeat_path'] ?? '');

        if ($path === '') {
            return;
        }

        // The heartbeat records that the CHECK RAN, not that it passed — a run
        // that found a broken platform is still proof the watchdog is awake, and
        // conflating the two would make a failing platform look like a dead
        // checker.
        try {
            $directory = dirname($path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            @file_put_contents($path, json_encode([
                'ran_at' => $now->toIso8601String(),
                'status' => $this->status(),
                'findings' => count($this->findings),
            ], JSON_PRETTY_PRINT)."\n");
        } catch (Throwable) {
            // A watchdog must not fall over because it could not write its own
            // diary. The verdict below is unaffected.
        }
    }

    /**
     * The dead man's switch. Pinged ONLY after a clean run, so that a failing
     * check and a dead check are the same signal to the outside world.
     *
     * @param  array<string, mixed>  $config
     */
    private function pingDeadMansSwitch(array $config): ?string
    {
        $url = trim((string) ($config['check']['heartbeat_url'] ?? ''));

        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) ($config['check']['heartbeat_timeout'] ?? 10))->get($url);

            return $response->successful() ? 'pinged' : 'ping answered '.$response->status();
        } catch (Throwable $e) {
            // Never fail the check because the outside observer was unreachable.
            // The failure is reported in the payload so a ping that has been
            // failing for a week is visible in the daily line.
            return 'ping failed: '.$e->getMessage();
        }
    }

    /**
     * Any `*.partial` older than a run could plausibly take. A crashed run
     * leaves one and nothing will ever restore it; a pile of them is a run
     * failing at the same point every night.
     *
     * @return list<string>
     */
    private function stalePartials(string $destination, CarbonImmutable $now): array
    {
        if (! is_dir($destination)) {
            return [];
        }

        $stale = [];

        foreach ((array) (@scandir($destination) ?: []) as $entry) {
            if (! is_string($entry) || ! str_ends_with($entry, '.partial')) {
                continue;
            }

            $modified = @filemtime($destination.'/'.$entry);

            if ($modified !== false && ($now->getTimestamp() - $modified) > 86400) {
                $stale[] = $entry;
            }
        }

        return $stale;
    }

    private function takenAt(BackupSet $set): ?CarbonImmutable
    {
        $created = $set->manifest()['created_at'] ?? null;

        if (! is_string($created)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($created)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function finding(string $severity, string $kind, string $summary): void
    {
        $this->findings[] = ['severity' => $severity, 'kind' => $kind, 'summary' => $summary];
    }

    private function status(): string
    {
        if ($this->findings !== []) {
            return 'failed';
        }

        return $this->degradedBy === [] ? 'pass' : 'partial';
    }

    /** @param array<string, mixed> $payload */
    private function verdict(string $status, CarbonImmutable $now, array $payload, ?string $blockedReason = null): int
    {
        if ($blockedReason !== null) {
            $this->finding('error', 'not_configured', $blockedReason);
        }

        $payload = [
            'status' => $status,
            'checked_at' => $now->toIso8601String(),
            'findings' => $this->findings,
            'degraded_by' => array_values(array_unique($this->degradedBy)),
        ] + $payload;

        if ($status === 'pass') {
            $payload['dead_mans_switch'] = $this->pingDeadMansSwitch((array) config('backup'));
        }

        if ($this->option('json')) {
            $this->renderJson($payload);
        } else {
            $this->renderHuman($payload);
        }

        $this->log($status, $payload);

        return match ($status) {
            'pass' => self::EXIT_PASS,
            'partial' => self::EXIT_PARTIAL,
            'blocked' => self::EXIT_BLOCKED,
            default => self::EXIT_FAILED,
        };
    }

    /** @param array<string, mixed> $payload */
    private function renderJson(array $payload): void
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $payload */
    private function renderHuman(array $payload): void
    {
        $this->newLine();
        $this->line('<options=bold>backup:check</> — read-only. This command writes nothing but its own heartbeat.');
        $this->line(sprintf('destination: %s  |  sets held: %s', $payload['destination'] ?? '(none configured)', $payload['sets_held'] ?? 0));

        $newest = $payload['newest_verified'] ?? null;

        $this->line($newest === null
            ? 'newest verified set: <fg=red>NONE</>'
            : sprintf(
                'newest verified set: %s  |  age: %s h  |  threshold: %d h',
                $newest['set'],
                $newest['age_hours'] ?? '?',
                (int) ($payload['max_age_hours'] ?? 0),
            ));

        // Printed on every run, whatever it says. An operator must not have to
        // read code to learn their backups exist in one place only.
        $offsite = (array) ($payload['offsite'] ?? []);
        $this->line(match ($offsite['status'] ?? '') {
            OffsiteShipper::PRESENT => 'off-site: <fg=green>present</> — '.($offsite['target'] ?? ''),
            OffsiteShipper::NOT_CONFIGURED => '<fg=yellow>off-site: NONE CONFIGURED</> — these sets exist on this volume only, and this volume is the one they are a backup of.',
            'not_applicable' => 'off-site: not checked — there is no verified set to look for.',
            default => '<fg=red>off-site: '.strtoupper((string) ($offsite['status'] ?? '?')).'</> — '.($offsite['reason'] ?? ''),
        });

        $self = (array) ($payload['self'] ?? []);
        $this->line(sprintf(
            'this checker last ran: %s',
            $self['previous_run'] ?? 'never recorded on this host',
        ));

        $this->newLine();

        foreach ($payload['findings'] as $finding) {
            $this->error('  '.$finding['summary']);
        }

        foreach (($payload['stale_partials'] ?? []) as $partial) {
            $this->warn(sprintf('  %s is a crashed run left behind. Nothing will ever restore a *.partial.', $partial));
        }

        if (in_array('checker_was_not_running', $payload['degraded_by'], true)) {
            $this->warn(sprintf(
                '  This check had not run for %s hours before now. It is scheduled daily; something stopped it, and while it was stopped nothing was watching the backups.',
                $self['gap_hours'] ?? '?',
            ));
        }

        if ($payload['status'] === 'pass') {
            $this->info('  A recent, verified backup set is here.');
        }
    }

    /**
     * Exactly one line per run, including a clean one, and its LEVEL carries the
     * verdict so an alerting rule can route without parsing the body. This is
     * the entire scheduled alert path: `schedule:run` discards stdout and
     * nothing inspects the exit status.
     *
     * A clean line every day is not noise. It is the only thing that makes the
     * ABSENCE of a line mean something, and the absence of a line is how this
     * checker itself going dark becomes visible.
     *
     * @param  array<string, mixed>  $payload
     */
    private function log(string $status, array $payload): void
    {
        $channel = Log::channel(config('backup.check.log_channel'));

        $summary = [
            'status' => $status,
            'destination' => $payload['destination'] ?? null,
            'sets_held' => $payload['sets_held'] ?? 0,
            'newest_verified' => $payload['newest_verified'] ?? null,
            // In the summary of every run including a clean one, because
            // "backups exist in one place only" is a standing fact an operator
            // has to be able to see without opening anything.
            'offsite' => $payload['offsite']['status'] ?? null,
            'degraded_by' => $payload['degraded_by'],
            'findings' => count($payload['findings']),
        ];

        if ($status === 'pass') {
            $channel->info('backup:check pass', $summary);

            return;
        }

        if ($status === 'partial') {
            // A ticket, not a page. A crashed `*.partial` from last week must
            // not ring at the same volume as a platform with no backups.
            $channel->warning('backup:check partial', $summary);

            return;
        }

        $channel->error('backup:check '.$status, $summary + [
            'detail' => array_map(static fn (array $f) => $f['summary'], $payload['findings']),
        ]);
    }
}
