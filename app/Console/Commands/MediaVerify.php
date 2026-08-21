<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Does every media ROW still have its FILE, does every FILE still have a ROW,
 * and does every organisation the apps can see still have the picture the apps
 * go looking for.
 *
 * ==========================================================================
 * IT IS READ-ONLY, AND THAT IS THE POINT, NOT A PREFERENCE
 * ==========================================================================
 *
 * This command NEVER deletes, moves, repairs, re-uploads, back-fills or
 * "cleans" anything. It issues SELECTs and filesystem reads and it writes a
 * report. There is no `--fix`, no `--prune`, no `--force`, and adding one would
 * defeat the purpose:
 *
 * The failure this exists to catch is a cleanup NOBODY ASKED FOR. On 2026-08-17
 * the `media` table held 226 rows — 77 feature icons, 45 announcement images,
 * 25 logo records, 22 services, 20 avatars, 17 section images. Some time after
 * that backup, every one of them was deleted. `media-library:clean` does
 * exactly that to rows whose files are missing; it is not scheduled here, no
 * migration mentions it, and the cause is STILL UNIDENTIFIED. A verifier that
 * could also delete would be one cron entry, one flag or one tired operator
 * away from being the next cause.
 *
 * So this command's only power is to say what it sees. The operator repairs.
 *
 * ==========================================================================
 * WHAT IT ANSWERS, AND WHY EACH QUESTION EXISTS
 * ==========================================================================
 *
 * **1. Dangling rows** — a `media` row whose file is absent on its own disk.
 * This is the state the platform sat in for an unknown period with nothing
 * detecting it. Of the 52 announcement media ids in the backup, ZERO had a file
 * in `storage/app/public/{id}/`; the surviving 56 folders on disk are seed art
 * in id ranges that do not overlap the lost records at all. The likely story is
 * that content was seeded on a laptop, the DATABASE was moved to production,
 * and the files never came with it — a row and its bytes parting company with
 * nothing in the application noticing or caring. Reported as a count and
 * GROUPED BY `model_type` + `collection_name`, so "25 logos are broken" is one
 * line rather than 25.
 *
 * **2. Orphan files** — bytes under a media disk with no row pointing at them.
 * The other half of the same drift, and the half `media-library:clean` DELETES.
 * An operator about to run that command should be able to see the size of what
 * it would take first. See "why orphans never grade" below.
 *
 * **3. A listed organisation with no logo.** Not an invented rule:
 *
 *   - `Mobile\MasjidsController@index` — the organisation picker every mobile
 *     app opens with — is literally `Masjid::listed()->with('logo')->get()`,
 *     so the logo is part of the public directory payload by construction.
 *   - `Admin\Masjids\StoreMasjidRequest` declares `'logo' => 'required|image'`.
 *     The system REFUSES to create an organisation without one. An org that has
 *     none did not choose that; something took it.
 *
 * So: a masjid with `listed_at` set, and either no `logos` media row or one
 * whose file is gone, is a live defect on the screen every app user sees first
 * — which is exactly the class of thing nobody noticed for weeks.
 *
 * `footer_logos` is `required` at creation too and is deliberately NOT graded
 * here: nothing in the public directory reads it, and a check is only worth its
 * noise where an absence is visible to somebody. Grade what the app reads.
 *
 * A note on the derivation: `Masjid::logo()` WAS
 * `hasOne(Media::class, 'model_id')->where('collection_name', 'logos')` with NO
 * `model_type` predicate, so any `logos` row belonging to a different model
 * that happened to share the id satisfied it. This command has always queried
 * `model_type = Masjid::class AND collection_name = 'logos'`, which is strictly
 * narrower; the relation now carries the same predicate, so the check and the
 * thing it checks cannot drift apart. `header_logo()`, `footer_logo()` and
 * `galleries()` on the same model still lack it — latent, since nothing else
 * writes those collections, and out of this command's scope.
 *
 * **4. The whole table being empty.** A DISTINCT and much louder condition than
 * "some rows are broken", and it gets its own status, its own exit code and its
 * own log level — see the contract below. It is what was actually true on
 * 2026-08-21: `media` held 0 rows against an auto_increment of 422, so every
 * masjid showed no logo in the apps and every announcement was withheld from
 * the feed, and the first report was a photograph of a television on a masjid
 * wall, days later.
 *
 * An empty table is only reported as WIPED when the RUNNING APPLICATION still
 * demands media from it — concretely, when at least one organisation is in the
 * public directory, because the directory endpoint reads a logo for each one.
 * An empty table on a platform that demands nothing is an empty platform (a
 * fresh install, a scratch database) and this command stays silent there: a
 * check that cries on a clean install is a check that gets silenced, and then
 * the emergency is unheard.
 *
 * Orphan files were considered as a second trigger — 56 folders of seed art
 * survived the wipe, so "bytes on disk that no row claims" looks like proof
 * media once existed here — and REJECTED. Unreferenced bytes are the weakest
 * evidence on this platform: a scratch disk, a seeder, an aborted upload and a
 * tenant deleted last year all leave them, and this is the loudest sentence the
 * command can say. It rests on what the application demands, never on litter.
 * The orphan figures are still carried in the finding as corroboration for
 * whoever reads it.
 *
 * **5. A collection that has stopped existing SINCE THE LAST RUN.** The other
 * four questions are all answered by looking at what is there. A DELETED row is
 * not there: absence is indistinguishable from "that content never existed"
 * unless the run remembers what it counted last time. Questions 1-3 catch a row
 * that outlived its file; question 4 catches the table hitting EXACTLY ZERO —
 * and would have missed the same event minus one surviving row, and would miss
 * the far likelier partial, somebody deleting the 45 announcement images and
 * leaving the rest. The logo audit covers logos only because the running app
 * demands them; nothing covered announcement images, section images, service
 * icons or avatars, which is 159 of the 226 rows that went.
 *
 * So the run keeps a census — one row count per `model_type` +
 * `collection_name`, plus a platform total — and compares this run against the
 * last. The threshold, the ratchet that stops the memory being poisoned by the
 * very loss it just reported, and what the rule still cannot see are all argued
 * in `config/media-verify.php` under `baseline` and in `gradeCensus()` below.
 * The census is a database aggregate, NOT a by-product of the bounded stat
 * scan: a scan truncated by `--max-rows` reports group floors, and a floor
 * compared against a baseline reads as a catastrophe that did not happen.
 *
 * It also retires a heuristic. Question 4 has to guess whether an empty table
 * is a wipe or a fresh install, and guesses from `listed_at`. With a memory
 * there is no guess for anything else: a platform that had 226 rows yesterday
 * and has 1 today is not a fresh install, whatever its directory says.
 *
 * **6. The link every media URL is actually served through.** `storage/app/
 * public` is reachable over HTTP only because `public/storage` points at it.
 * Break that link and every URL this command validates 404s while every row and
 * every file it examines is perfectly intact — a `clean` report about a platform
 * showing no images anywhere, which is the precise failure shape this command
 * exists to refuse. Checked only where it is load-bearing: a media disk on this
 * host must actually resolve to the target, or the check reports `skipped` and
 * says why rather than inventing a verdict about somebody else's S3 bucket.
 *
 * ==========================================================================
 * THE EXIT CONTRACT — read this before wiring anything to it
 * ==========================================================================
 *
 * Deliberately the SAME VOCABULARY as `tenancy:canary`, one code wider. Codes
 * 0-3 carry that command's exact meanings and log levels so an operator does
 * not have to hold two dialects in their head, and there is one addition,
 * argued below:
 *
 *   0  clean       every media row examined, every file present, every listed
 *                  organisation has a logo that resolves        info     quiet
 *   1  broken      a finding — a dangling row, or a listed org with no logo.
 *                  The estate is damaged and somebody must look  error    page
 *   2  incomplete  the run is not evidence about this platform: no `media`
 *                  table, no disk it could read, nothing verified error    page
 *   3  partial     the run IS evidence, about most of the estate, and it names
 *                  what it did not see                           warning  ticket
 *   4  empty       the `media` table is EMPTY while the platform still expects
 *                  media                                         critical page
 *
 * WHY 4 EXISTS. The brief this was built to is that "a clean estate, a degraded
 * one and a catastrophic one must be distinguishable by an alerting rule that
 * never reads the body". Folded into code 1, a wiped table would be
 * indistinguishable from a single broken thumbnail — and a wiped table is not a
 * bigger version of a dangling row, it is a different event with a different
 * response (stop, find who deleted them, restore from backup). Extending the
 * canary's ladder by one is cheaper than a second vocabulary, and `critical` is
 * the level Laravel already offers above `error`.
 *
 * A FINDING (1) INCLUDES: a dangling row; a listed organisation with no logo; a
 * collection that lost more than ordinary editing explains; a broken
 * `public/storage`; and A DISK NAMED BY A ROW THAT DOES NOT RESOLVE. That last
 * one used to degrade the run to `partial`, and it was wrong: a row pointing at
 * a disk this application has no driver for is a BROKEN REFERENCE — the file
 * behind it is unreachable by every code path, not just by this one — and it is
 * exactly what this command exists to report. `partial` is now reserved for
 * TRANSIENT read failures: a disk that resolves and then throws on a read, and
 * a run cut short by `--max-rows`. The two are separated in the payload
 * (`estate.unresolvable_disks` versus `estate.unreadable_disks`) so a reader
 * can check the distinction rather than take it on trust.
 *
 * PRECEDENCE, in strict order: `empty` outranks a finding, because when the
 * table is empty every listed organisation is logo-less and reporting three
 * findings would describe the symptom while burying the event. A finding
 * outranks `incomplete` and `partial`, because damage the run actually FOUND
 * must page even if the run was also truncated. `incomplete` outranks
 * `partial`: "this run is not evidence" is a stronger statement than "this run
 * is evidence about most of it". `clean` is the only verdict that has to be
 * earned outright.
 *
 * WHY ORPHANS NEVER GRADE. Unreferenced bytes break nothing for anybody: no
 * page 404s, no app renders a hole, no congregation sees a blank board. They
 * cost disk and they are what a careless `media-library:clean` deletes. Graded,
 * they would be a permanent amber on any estate with history — one abandoned
 * upload and the check is yellow forever — and an alarm that fires on an
 * ordinary Tuesday gets silenced, and then the emergency is unheard. So the
 * orphan figures are printed ABOVE the check table, carried in the JSON and put
 * in the log summary on every run including a clean one, where they can be read
 * and diffed, and they move no verdict. This is the same stance
 * `tenancy:canary` takes on `coverage.route_table`, for the same reason.
 *
 * ==========================================================================
 * COST, AND WHAT HAPPENS PAST THE BOUND
 * ==========================================================================
 *
 * The dangling check costs ONE FILESYSTEM STAT PER MEDIA ROW. That is trivial
 * on the estate this was written for (226 rows in the last good backup) and it
 * is trivial on a local disk generally; it is NOT trivial on a gallery-heavy
 * tenant, and on a remote disk every one of those stats is a network round trip
 * rather than a syscall. So it is bounded by `--max-rows`
 * (`media-verify.max_rows`, default 25000). Rows are read in id order, in
 * chunks of 500, and past the ceiling the scan STOPS.
 *
 * Past the bound the run reports `truncated_row_budget`, says how many rows it
 * left unexamined, and CANNOT be `clean` — it degrades to `partial` (exit 3,
 * `warning`, a ticket) unless it already has a finding, in which case the
 * finding wins and it is exit 1. It never invents a verdict about rows it did
 * not look at.
 *
 * The orphan walk is bounded separately by `--max-files`
 * (`media-verify.max_files`, default 50000) counting directories listed plus
 * files sized. Past it the walk stops and every orphan figure is reported as a
 * FLOOR — "at least N groups, at least X bytes" — never as a total. That
 * truncation degrades nothing, for the same reason orphans do not.
 *
 * The database cost is fixed and small: one `count`, the chunked id-ordered
 * scan, one `whereIn` per 1000 candidate directories, one `distinct` for the
 * disk names, one query for listed organisations, one for their logo rows, one
 * `GROUP BY` for the census, and one `count` per unresolvable disk (normally
 * none). No query is per-row.
 *
 * ==========================================================================
 * WHAT IT DOES NOT SEE
 * ==========================================================================
 *
 * Stated here rather than discovered later, because a report that says `clean`
 * without saying how narrow that word is, is how the last one got trusted:
 *
 *   - A file that EXISTS but is corrupt, truncated or zero bytes. Existence is
 *     what is checked; bytes are not read and checksums are not stored.
 *   - Generated CONVERSIONS and responsive images. A missing conversion falls
 *     back to the original in most render paths, so it is a degradation rather
 *     than a hole, and walking them multiplies the stat count by the number of
 *     registered conversions.
 *   - A media row whose OWNER model is gone. Spatie leaves the row when the
 *     owner is SOFT-deleted, which is correct and common here (`Announcement`,
 *     `Service`, `Masjid`, `User` all use SoftDeletes), so the honest signal is
 *     too weak to grade.
 *   - Any collection other than `logos` for the "should have" check. See above.
 *   - WHY a collection shrank. The census counts rows; it cannot tell an
 *     operator clearing a gallery from a stray `media-library:clean`, and it
 *     will report a deliberate mass deletion as a finding. That is the
 *     deliberate direction of the error, and `--accept-baseline` is the one
 *     gesture that says "yes, we meant that".
 *   - A drop the FIRST time it is seen on a host with no memory yet. A run with
 *     no baseline records one and says so; it never pretends to have compared.
 *   - A drop SMALLER than the threshold, and a drain slower than the
 *     high-water window. Both bounds are argued in `config/media-verify.php`;
 *     the ones this rule cannot see by construction are listed there too.
 *   - A collection that was ALREADY empty when the first baseline was taken.
 *     The memory starts where it starts; only the backup manifests predate it,
 *     and they record one total, not a census.
 *   - WHETHER A FILE IS SERVED. `public/storage` is checked as a link; no HTTP
 *     request is made, and a web server that does not follow symlinks
 *     (`DisableSymlinks`, an nginx `disable_symlinks on`) will 404 media that
 *     this command reports as reachable.
 */
class MediaVerify extends Command
{
    protected $signature = 'media:verify
        {--max-rows= : Ceiling on media rows examined this run (default media-verify.max_rows)}
        {--max-files= : Ceiling on directories listed and files sized while walking for orphans}
        {--accept-baseline : Record this run\'s census as the new baseline, clearing any held drop. Touches no media row and no media file.}
        {--json : Emit the run as JSON and nothing else}';

    protected $description = 'Verify every media row still has its file and every listed organisation still has its logo (read-only; never repairs).';

    private const SEVERITY_CRITICAL = 'critical';

    private const SEVERITY_HIGH = 'high';

    private const EXIT_CLEAN = 0;

    private const EXIT_BROKEN = 1;

    private const EXIT_INCOMPLETE = 2;

    private const EXIT_PARTIAL = 3;

    private const EXIT_EMPTY = 4;

    /** Rows read per chunk. Small enough to bound memory, large enough that the query count stays noise. */
    private const CHUNK = 500;

    /** How many examples a grouped finding carries. The COUNT is exact; the list is a sample. */
    private const SAMPLE = 5;

    /** @var array<int,array<string,mixed>> */
    private array $findings = [];

    /** @var array<int,array<string,string>> */
    private array $checks = [];

    /** @var array<int,string> */
    private array $errors = [];

    /** @var array<int,string> */
    private array $notes = [];

    /** Why this run is `partial`, as a list an alert rule can route on without re-deriving it. @var array<int,string> */
    private array $degradedBy = [];

    /** The run is not evidence about this platform. Exit 2. */
    private bool $blocked = false;

    /** The run IS evidence, about most of it, and names the gap. Exit 3. */
    private bool $degraded = false;

    /** Resolved disks, by name. `false` means the disk is configured-but-unreadable or absent. */
    private array $diskCache = [];

    /**
     * A disk that RESOLVED and then threw on a read. Transient; degrades the run
     * to `partial`. @var array<string,string> disk name => why the read failed
     */
    private array $unreadableDisks = [];

    /**
     * A disk this application has no driver for at all. When a media row names
     * one, that row is a broken reference and the run is `broken`, not
     * `partial`. @var array<string,string> disk name => why it did not resolve
     */
    private array $unresolvableDisks = [];

    public function handle(): int
    {
        // `Artisan::call` reuses this instance, so a second run in the same
        // process would inherit the first one's findings. Only tests exercise
        // that, and a test comparing two verdicts is exactly where a stale
        // field would give a false answer.
        $this->findings = [];
        $this->checks = [];
        $this->errors = [];
        $this->notes = [];
        $this->degradedBy = [];
        $this->blocked = false;
        $this->degraded = false;
        $this->diskCache = [];
        $this->unreadableDisks = [];
        $this->unresolvableDisks = [];

        $startedAt = now();
        $started = microtime(true);

        $maxRows = max(1, (int) ($this->option('max-rows') ?: config('media-verify.max_rows', 25000)));
        $maxFiles = max(1, (int) ($this->option('max-files') ?: config('media-verify.max_files', 50000)));

        /** @var class-string $mediaModel */
        $mediaModel = config('media-library.media_model');
        $table = (new $mediaModel)->getTable();

        if (! Schema::hasTable($table)) {
            // Not "no media" — no place for media to be. Nothing below this
            // line can say anything true about the estate.
            $this->blocked = true;
            $this->errors[] = "There is no `{$table}` table — this run is not evidence about the media estate.";

            return $this->report($startedAt, $started, [], [], [], [], 0, 0, 0);
        }

        $totalRows = (int) $mediaModel::query()->count();

        // -------------------------------------------------------- dangling
        [$groups, $checked, $verified] = $this->scanRows($mediaModel, $maxRows);

        $dangling = array_sum(array_column($groups, 'dangling'));

        // -------------------------------------------------------- orphans
        $diskNames = $this->diskNames($table);

        $orphans = $this->walkDisks($table, $maxFiles, $diskNames);

        // -------------------------------------------- empty-handed models
        $expectations = $this->auditListedOrganisations($table);

        // ------------------------------------------------------- verdicts
        //
        // Order matters and is argued in the class docblock: the wiped-estate
        // question is asked FIRST, because when it is true the other two checks
        // are describing its symptoms.
        $wiped = $totalRows === 0 && $expectations['listed'] > 0;

        if ($wiped) {
            $this->addCheck('media-table-is-populated', 'FAIL',
                'the `media` table holds NO rows while '.$expectations['listed'].
                ' organisation(s) in the public directory need a logo out of it');

            $this->addFinding(
                self::SEVERITY_CRITICAL,
                'media_table_empty',
                'The `media` table is EMPTY. Every logo, icon and image reference on the platform is dangling at once.',
                [
                    'media_rows' => 0,
                    'listed_organisations' => $expectations['listed'],
                    'orphan_groups' => $orphans['groups'],
                    'orphan_bytes' => $orphans['bytes'],
                    // Named because it is the single most likely way this
                    // happens, and because whoever reads this at 3am should not
                    // have to go and find out what deletes dangling rows.
                    'note' => 'This is what `media-library:clean` does to rows whose files are missing. '.
                        'It is not scheduled in this application. Do not run it; restore the rows.',
                ]
            );
        } else {
            $this->addCheck('media-table-is-populated',
                $totalRows > 0 ? 'pass' : 'skipped',
                $totalRows > 0
                    ? $totalRows.' row(s)'
                    : 'no rows, and no organisation in the public directory needs any — '.
                        'an empty estate, not a wiped one');
        }

        // The dangling and logo checks still RUN and still report on a wiped
        // estate; they simply do not decide the verdict there. Suppressing them
        // would make the loudest report the least informative one.
        $this->gradeDangling($groups, $dangling, $checked, $verified, $totalRows, $wiped);
        $this->gradeExpectations($expectations, $wiped);

        // ----------------------------------------------------- the memory
        //
        // Last, because it is the only question whose answer this run also
        // WRITES, and it must be written from a census taken after everything
        // else has had its chance to fail loudly.
        $census = $this->gradeCensus($this->census($table), $totalRows, $wiped);

        // -------------------------------------------------- the public link
        $link = $this->auditPublicStorageLink($diskNames);

        $this->gradePublicLink($link);

        // ---------------------------------------------------------- disks
        //
        // After every path that could have touched a disk, so the two kinds of
        // disk failure are both known before either is graded.
        $this->gradeDisks($table, $totalRows, $verified);

        return $this->report($startedAt, $started, $groups, $orphans, $expectations, [
            'rows_total' => $totalRows,
            'rows_checked' => $checked,
            'rows_verified' => $verified,
            'max_rows' => $maxRows,
            'max_files' => $maxFiles,
        ], $totalRows, $checked, $verified, $census, $link);
    }

    // ------------------------------------------------------------- dangling

    /**
     * One stat per media row, in id order, stopping at the ceiling.
     *
     * Returns [groups, VISITED, VERIFIED]. The two counts are not the same
     * number and must never be printed as if they were: a row on a disk that
     * could not be read was visited and NOT verified, and a check that says
     * `pass` while quoting the visited count is reporting success about rows it
     * did not look at.
     *
     * @param  class-string  $mediaModel
     * @return array{0: array<string,array<string,mixed>>, 1: int, 2: int}
     */
    private function scanRows(string $mediaModel, int $maxRows): array
    {
        $groups = [];
        $checked = 0;
        $unverified = 0;

        $mediaModel::query()->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$groups, &$checked, &$unverified, $maxRows) {
            foreach ($rows as $media) {
                if ($checked >= $maxRows) {
                    return false;
                }

                $checked++;

                $key = ($media->model_type ?: 'unknown').'::'.($media->collection_name ?: 'unknown');

                $groups[$key] ??= [
                    'model_type' => (string) ($media->model_type ?: 'unknown'),
                    'collection_name' => (string) ($media->collection_name ?: 'unknown'),
                    'total' => 0,
                    'unverified' => 0,
                    'dangling' => 0,
                    'sample' => [],
                ];

                $groups[$key]['total']++;

                $present = $this->fileExists($media);

                if ($present === null) {
                    // The disk itself could not be read. That is not "the file
                    // is missing" and must never be reported as one.
                    $unverified++;
                    $groups[$key]['unverified']++;

                    continue;
                }

                if ($present) {
                    continue;
                }

                $groups[$key]['dangling']++;

                if (count($groups[$key]['sample']) < self::SAMPLE) {
                    $groups[$key]['sample'][] = [
                        'media_id' => (int) $media->id,
                        'model_id' => (int) $media->model_id,
                        'disk' => (string) $media->disk,
                        'path' => $this->relativePath($media),
                    ];
                }
            }

            return true;
        });

        if ($unverified > 0) {
            $this->notes[] = $unverified.' row(s) sat on a disk this run could not read: NOT counted as missing, and NOT counted as verified either.';
        }

        return [$groups, $checked, $checked - $unverified];
    }

    /**
     * True = the file is there, false = it is not, NULL = the disk could not be
     * read and this run knows nothing about that row. The third answer is the
     * one that matters: an unreadable disk reported as 400 missing files is a
     * false catastrophe, and a check that cries wolf is a check that gets
     * turned off.
     */
    private function fileExists(object $media): ?bool
    {
        $disk = (string) $media->disk;

        $filesystem = $this->disk($disk);

        if ($filesystem === null) {
            return null;
        }

        try {
            return $filesystem->exists($this->relativePath($media));
        } catch (\Throwable $e) {
            $this->markDiskUnreadable($disk, $e->getMessage());

            return null;
        }
    }

    /**
     * The path Spatie itself would resolve, so a custom path generator or a
     * `media-library.prefix` is honoured rather than re-implemented. The
     * fallback is the DefaultPathGenerator layout, used only if the library
     * throws — in which case saying "I assumed {id}/{file_name}" beats saying
     * nothing.
     */
    private function relativePath(object $media): string
    {
        try {
            return $media->getPathRelativeToRoot();
        } catch (\Throwable) {
            $prefix = trim((string) config('media-library.prefix', ''), '/');

            return ($prefix !== '' ? $prefix.'/' : '').$media->id.'/'.$media->file_name;
        }
    }

    /**
     * `$checked` is how many rows were VISITED; `$verified` is how many had
     * their file state actually determined. The gap is rows on a disk that
     * could not be read, and it is the difference between a check that passed
     * and a check that shrugged.
     *
     * @param  array<string,array<string,mixed>>  $groups
     */
    private function gradeDangling(array $groups, int $dangling, int $checked, int $verified, int $totalRows, bool $wiped): void
    {
        if ($checked < $totalRows) {
            $this->degraded = true;
            $this->degradedBy[] = 'truncated_row_budget';
            $this->errors[] = 'Row budget exhausted after '.$checked.' of '.$totalRows.
                ' row(s); '.($totalRows - $checked).' row(s) were never looked at. Raise --max-rows.';
        }

        if ($totalRows === 0) {
            $this->addCheck('media-rows-have-their-files', 'skipped', 'no rows to check');

            return;
        }

        if ($dangling === 0 && $verified < $checked) {
            // The defect this project keeps hitting: a surface reporting success
            // about something it did not look at. Every row it DID read was
            // fine; that is not the same sentence as `pass`.
            $this->degraded = true;
            $this->degradedBy[] = 'unverified_rows';
            $this->addCheck('media-rows-have-their-files', 'partial',
                $verified.' of '.$checked.' visited row(s) verified, every one of those present — '.
                ($checked - $verified).' could not be read and are NOT certified');

            return;
        }

        if ($dangling === 0 && $checked < $totalRows) {
            // TRUNCATION IS NOT A CLEAN BILL EITHER.
            //
            // Same sentence as the branch above, one cause further out: the rows
            // that were read were all fine, and the rest were never opened. This
            // used to emit `pass`, which made a scan that stopped after 3 of 10
            // rows — the other 7 of them dangling — produce output byte-identical
            // to ten healthy rows. The run was already degraded by
            // `truncated_row_budget`, but the CHECK said pass, and a reader
            // scanning check outcomes is exactly who this misleads.
            $this->addCheck('media-rows-have-their-files', 'partial',
                $verified.' of '.$totalRows.' row(s) verified, every one of those present — '.
                ($totalRows - $checked).' row(s) were never read (row budget); raise --max-rows');

            return;
        }

        if ($dangling === 0) {
            $this->addCheck('media-rows-have-their-files', 'pass',
                $verified.' of '.$totalRows.' row(s) verified, every file present');

            return;
        }

        if ($verified < $checked) {
            $this->degraded = true;
            $this->degradedBy[] = 'unverified_rows';
        }

        $this->addCheck('media-rows-have-their-files', 'FAIL',
            $dangling.' of '.$verified.' verified row(s) point at a file that is not there'.
            ($verified < $checked ? ' ('.($checked - $verified).' further row(s) could not be read)' : ''));

        // On a wiped estate there are no rows and therefore no groups; this
        // loop simply does not run. Guarded anyway so the intent is legible.
        if ($wiped) {
            return;
        }

        foreach ($groups as $group) {
            if ($group['dangling'] === 0) {
                continue;
            }

            // "the ENTIRE collection is gone" is a claim about every row in it,
            // so it may only be made when every row in it was actually read.
            $whole = $group['dangling'] === $group['total'] && ($group['unverified'] ?? 0) === 0;

            $this->addFinding(
                // A collection that is ENTIRELY dangling is a category of
                // content that has stopped existing — 25 logos, all of them.
                // A collection that is partly dangling is a maintenance
                // problem. Both page; they do not read the same.
                $whole ? self::SEVERITY_CRITICAL : self::SEVERITY_HIGH,
                'dangling_media',
                $group['dangling'].' of '.$group['total'].' `'.$group['collection_name'].
                    '` media row(s) on '.$this->shortClass($group['model_type']).
                    ' point at files that are not on disk'.($whole ? ' — the ENTIRE collection is gone' : ''),
                [
                    'model_type' => $group['model_type'],
                    'collection_name' => $group['collection_name'],
                    'dangling' => $group['dangling'],
                    'total' => $group['total'],
                    'unverified' => $group['unverified'] ?? 0,
                    'sample' => $group['sample'],
                ]
            );
        }
    }

    // -------------------------------------------------------------- orphans

    /**
     * Walk every media disk for numeric top-level directories no row points at.
     *
     * Spatie's DefaultPathGenerator puts a media item at `{prefix}/{id}/…`, so
     * a numeric top-level directory IS a media id and anything else is not
     * media at all. Non-numeric entries are counted separately as
     * `unrecognised` and are never called orphans: reporting a stranger's file
     * as deletable is how a verifier becomes the cause of the next incident.
     *
     * @param  array<int,string>  $diskNames
     * @return array{groups:int, bytes:int, files:int, truncated:bool, sample:array<int,array<string,mixed>>, unrecognised:array<int,string>, disks:array<int,string>}
     */
    private function walkDisks(string $table, int $maxFiles, array $diskNames): array
    {
        $out = [
            'groups' => 0,
            'bytes' => 0,
            'files' => 0,
            'truncated' => false,
            'sample' => [],
            'unrecognised' => [],
            'disks' => [],
        ];

        $prefix = trim((string) config('media-library.prefix', ''), '/');
        $budget = $maxFiles;

        foreach ($diskNames as $name) {
            $filesystem = $this->disk($name);

            if ($filesystem === null) {
                continue;
            }

            $out['disks'][] = $name;

            try {
                $directories = $filesystem->directories($prefix);
                $loose = $filesystem->files($prefix);
            } catch (\Throwable $e) {
                $this->markDiskUnreadable($name, $e->getMessage());

                continue;
            }

            foreach ($loose as $file) {
                $out['unrecognised'][] = $name.':'.$file;
            }

            $budget -= count($directories);

            $candidates = [];

            foreach ($directories as $directory) {
                $base = basename($directory);

                if (! ctype_digit($base)) {
                    $out['unrecognised'][] = $name.':'.$directory;

                    continue;
                }

                $candidates[(int) $base] = $directory;
            }

            if ($budget <= 0) {
                $out['truncated'] = true;
            }

            foreach ($this->unplacedIds($table, $name, array_keys($candidates)) as $id) {
                if ($budget <= 0) {
                    $out['truncated'] = true;

                    break;
                }

                $directory = $candidates[$id];
                $bytes = 0;
                $count = 0;

                try {
                    foreach ($filesystem->allFiles($directory) as $file) {
                        if ($budget-- <= 0) {
                            $out['truncated'] = true;

                            break;
                        }

                        $count++;
                        $bytes += (int) $filesystem->size($file);
                    }
                } catch (\Throwable $e) {
                    $this->markDiskUnreadable($name, $e->getMessage());

                    continue;
                }

                $out['groups']++;
                $out['files'] += $count;
                $out['bytes'] += $bytes;

                if (count($out['sample']) < self::SAMPLE) {
                    $out['sample'][] = [
                        'disk' => $name,
                        'path' => $directory,
                        'files' => $count,
                        'bytes' => $bytes,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Which of these directory ids has no row claiming them on this disk.
     *
     * Batched `whereIn` rather than loading every media id into memory: the
     * candidate set is bounded by what is actually ON the disk, and a table with
     * a million rows should not become a million-element PHP array to answer a
     * question about 56 folders.
     *
     * A row claims a directory through EITHER `disk` or `conversions_disk` —
     * when the two differ, the conversions live under the same `{id}/` path on
     * the second disk and are not orphans.
     *
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function unplacedIds(string $table, string $disk, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placed = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::table($table)
                ->whereIn('id', $chunk)
                ->where(fn ($q) => $q->where('disk', $disk)->orWhere('conversions_disk', $disk))
                ->pluck('id');

            foreach ($rows as $id) {
                $placed[(int) $id] = true;
            }
        }

        return array_values(array_filter($ids, static fn (int $id) => ! isset($placed[$id])));
    }

    /**
     * Every disk a media row names, plus the configured default, plus anything
     * `media-verify.extra_disks` adds. The default is included even when no row
     * names it, because "no row names the media disk" is the wiped estate — the
     * exact case where the surviving bytes are the evidence.
     *
     * @return array<int,string>
     */
    private function diskNames(string $table): array
    {
        $names = DB::table($table)
            ->select('disk')
            ->distinct()
            ->pluck('disk')
            ->filter()
            ->map(static fn ($d) => (string) $d)
            ->all();

        $names[] = (string) config('media-library.disk_name', 'public');

        foreach ((array) config('media-verify.extra_disks', []) as $extra) {
            $extra = trim((string) $extra);

            if ($extra !== '') {
                $names[] = $extra;
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    // ------------------------------------------------- empty-handed models

    /**
     * Every LISTED organisation should have a logo the directory can render.
     *
     * Two ways it does not, and they are different repairs: no `logos` row at
     * all (the record was deleted) versus a row whose file is gone (the bytes
     * were). Both show the app user the same nothing.
     *
     * There is a THIRD answer and it is neither of those: the row is there and
     * its disk could not be read, so this run does not know. That one used to
     * fall through the `=== false` test and be counted as a logo that resolves —
     * a pass reported about a file nobody looked at. It is now reported as
     * `logo_unverifiable`, it is kept out of the `missing` count because it is
     * not evidence of absence, and it stops the check from saying `pass`.
     *
     * @return array{listed:int, missing:array<int,array<string,mixed>>, unverifiable:array<int,array<string,mixed>>}
     */
    private function auditListedOrganisations(string $table): array
    {
        $listed = Masjid::listed()->orderBy('id')->get(['id', 'name']);

        if ($listed->isEmpty()) {
            return ['listed' => 0, 'missing' => [], 'unverifiable' => []];
        }

        /** @var class-string $mediaModel */
        $mediaModel = config('media-library.media_model');

        // One query for every logo row on the platform, keyed by owner. Newest
        // first so `first()` per owner matches the relation's `latest()`.
        $logos = $mediaModel::query()
            ->where('model_type', Masjid::class)
            ->where('collection_name', 'logos')
            ->whereIn('model_id', $listed->pluck('id')->all())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('model_id');

        $missing = [];
        $unverifiable = [];

        foreach ($listed as $masjid) {
            $media = ($logos[$masjid->id] ?? collect())->first();

            if ($media === null) {
                $missing[] = [
                    'masjid_id' => (int) $masjid->id,
                    'name' => (string) $masjid->name,
                    'reason' => 'no_logo_record',
                ];

                continue;
            }

            $present = $this->fileExists($media);

            if ($present === false) {
                $missing[] = [
                    'masjid_id' => (int) $masjid->id,
                    'name' => (string) $masjid->name,
                    'reason' => 'logo_file_missing',
                    'media_id' => (int) $media->id,
                    'path' => $this->relativePath($media),
                ];

                continue;
            }

            if ($present === null) {
                $unverifiable[] = [
                    'masjid_id' => (int) $masjid->id,
                    'name' => (string) $masjid->name,
                    'reason' => 'logo_unverifiable',
                    'media_id' => (int) $media->id,
                    'disk' => (string) $media->disk,
                    'path' => $this->relativePath($media),
                ];
            }
        }

        return ['listed' => $listed->count(), 'missing' => $missing, 'unverifiable' => $unverifiable];
    }

    /**
     * @param  array{listed:int, missing:array<int,array<string,mixed>>, unverifiable:array<int,array<string,mixed>>}  $expectations
     */
    private function gradeExpectations(array $expectations, bool $wiped): void
    {
        if ($expectations['listed'] === 0) {
            $this->addCheck('listed-organisations-have-a-logo', 'skipped',
                'no organisation is in the public directory');

            return;
        }

        $missing = $expectations['missing'];
        $unverifiable = $expectations['unverifiable'] ?? [];

        if ($unverifiable !== []) {
            $this->degraded = true;
            $this->degradedBy[] = 'unverified_rows';
            $this->notes[] = count($unverifiable).' listed organisation(s) have a `logos` row whose disk this run '.
                'could not read. They are NOT counted as logo-less and they are NOT counted as resolving.';
        }

        if ($missing === [] && $unverifiable !== []) {
            $this->addCheck('listed-organisations-have-a-logo', 'partial',
                ($expectations['listed'] - count($unverifiable)).' of '.$expectations['listed'].
                ' listed organisation(s) have a logo that resolves; '.count($unverifiable).
                ' could not be read (logo_unverifiable)');

            return;
        }

        if ($missing === []) {
            $this->addCheck('listed-organisations-have-a-logo', 'pass',
                'all '.$expectations['listed'].' listed organisation(s) have a logo that resolves');

            return;
        }

        $this->addCheck('listed-organisations-have-a-logo', 'FAIL',
            count($missing).' of '.$expectations['listed'].
            ' listed organisation(s) show no logo in the app directory'.
            ($unverifiable !== [] ? ' (a further '.count($unverifiable).' could not be read)' : ''));

        if ($wiped) {
            return;
        }

        $this->addFinding(
            self::SEVERITY_CRITICAL,
            'listed_organisation_without_logo',
            count($missing).' of '.$expectations['listed'].' organisation(s) in the public directory have no logo — '.
                'GET /api/mobile/masjids is the screen every app opens with',
            [
                'listed' => $expectations['listed'],
                'without_logo' => count($missing),
                'unverifiable' => count($unverifiable),
                'organisations' => array_slice($missing, 0, 25),
            ]
        );
    }

    // --------------------------------------------------------- the memory

    /**
     * How many rows each `model_type` + `collection_name` holds RIGHT NOW.
     *
     * A database aggregate, deliberately NOT a by-product of `scanRows()`. That
     * scan stops at `--max-rows`, so its group totals are FLOORS past the
     * ceiling, and a floor compared against a baseline reads as a catastrophic
     * drop that did not happen. One `GROUP BY` is exact, unbounded, and costs
     * one query however large the table is.
     *
     * @return array<string,array{model_type:string,collection_name:string,rows:int}>
     */
    private function census(string $table): array
    {
        $out = [];

        $rows = DB::table($table)
            ->select('model_type', 'collection_name', DB::raw('COUNT(*) as row_count'))
            ->groupBy('model_type', 'collection_name')
            ->get();

        foreach ($rows as $row) {
            $modelType = (string) ($row->model_type ?: 'unknown');
            $collection = (string) ($row->collection_name ?: 'unknown');

            $out[$modelType.'::'.$collection] = [
                'model_type' => $modelType,
                'collection_name' => $collection,
                'rows' => (int) $row->row_count,
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * Compare this run's census against the last one's, and hand the next run a
     * memory.
     *
     * ======================================================================
     * WHY A MEMORY AT ALL
     * ======================================================================
     *
     * Every other question this command asks is answered by looking at what is
     * there. A DELETED row is not there. A dangling row can be detected because
     * the row survives to be checked; a removed row is simply absent, and
     * absence is indistinguishable from "that content never existed" without a
     * baseline. So the empty-table check catches the wipe that happened — the
     * table at EXACTLY ZERO — and would have missed the identical event minus
     * one surviving row, and misses the far likelier partial: somebody deletes
     * the 45 announcement images and leaves the other 181 rows alone.
     *
     * ======================================================================
     * THE THRESHOLD, AND WHY IT IS NOT "ANY DROP"
     * ======================================================================
     *
     * Content is legitimately deleted every day here. An announcement comes
     * down, a gallery is cleared, a logo is replaced (which deletes a row and
     * writes a new one). "Any drop is a finding" would make this the
     * amber-every-night detector the whole file is written against — and an
     * alarm that fires on an ordinary Tuesday gets silenced, and then the
     * emergency is unheard.
     *
     * What separates "an admin deleted a thing" from "a category of content
     * stopped existing" is SHAPE, not motion. Ordinary editing is a few rows out
     * of ONE tenant's share of a platform-wide collection. The incident is most
     * of a collection, at once, across every tenant. So a drop is a finding only
     * when it clears BOTH bars:
     *
     *   - `min_drop` (10 rows) — above what a person plausibly deletes by hand
     *     between two runs six hours apart, and below the SMALLEST group in the
     *     real loss (17 section images). Every one of the six groups that went
     *     on 2026-08-17 clears it; a morning of admin work does not.
     *
     *   - `drop_ratio` (half) — half of a PLATFORM-WIDE collection disappearing
     *     in one interval is not a shape human editing produces. To lose half of
     *     45 announcement images somebody must delete 23 of them across several
     *     organisations in one sitting.
     *
     * `vanish_floor` (5) is the single exception to the ratio: a collection that
     * reaches EXACTLY ZERO has stopped existing platform-wide, which is
     * qualitatively the event rather than a large instance of ordinary editing.
     * It gets a lower bar — but not a bar of one, because a two-row collection
     * emptying is one admin action and nothing more.
     *
     * MEASURED AGAINST WHAT THIS APPLICATION ACTUALLY DOES. Every image-editing
     * path here is replace-in-place: `MasjidsController@235`,
     * `ServicesController@88`, `AnnouncementsController@82`,
     * `MasjidAboutUsController@46` and the rest all call
     * `clearMediaCollection(...)` and then `addMedia(...)` in the same request,
     * so an ordinary edit is a delete AND an insert and the census — taken every
     * six hours — sees the NET, which is zero. The paths that delete without
     * replacing are `MasjidGalleryController` (one gallery item at a time) and
     * the assistant's `ToolRegistry@364` (one announcement at a time). Nothing
     * scheduled in this application deletes a media row at all. So the bar is
     * not being set against a hypothesis about admin behaviour: there is no
     * routine process here that removes ten rows from a collection in six hours,
     * which is why an amber every night is not the expected outcome.
     *
     * ======================================================================
     * THE RATCHET — why the memory does not follow the estate down
     * ======================================================================
     *
     * A drop WITHIN the threshold lowers the baseline; it has to, or twenty
     * ordinary deletions would eventually add up to a spurious page. A drop that
     * clears the threshold does NOT: the baseline is HELD at its previous value
     * and the finding is emitted again on every run until the rows come back or
     * an operator runs `--accept-baseline`.
     *
     * That is the whole point. If the baseline followed the loss down, the run
     * after the wipe would compare the wreckage against itself, find nothing and
     * go quiet — one alert, once, for an event that in fact went unnoticed for
     * days and was reported by a photograph of a television. A watchdog that
     * barks once is a watchdog somebody slept through.
     *
     * ======================================================================
     * WHAT THIS RULE STILL CANNOT SEE
     * ======================================================================
     *
     *   - WHY anything shrank. It cannot tell a deliberate clear-out from a
     *     stray `media-library:clean`, and it WILL report a deliberate mass
     *     deletion as a finding. That is the direction the error is pointed on
     *     purpose; `--accept-baseline` is the one gesture that says "we meant
     *     that", and habitually reaching for it defeats the detector.
     *   - Anything on the FIRST run on a host. With no baseline there is nothing
     *     to compare, and the run says so instead of implying it compared.
     *   - A drop under the threshold: 9 rows, or 40% of a collection, every run,
     *     for ever. The high-water net below catches a drain of three quarters
     *     inside 30 days however gradually it happened; a drain slower than that
     *     is invisible here, and a collection that shrinks over a year is
     *     invisible BY DESIGN, because that is what ordinary attrition looks
     *     like.
     *   - A swap: 45 rows deleted and 45 written in the same interval nets to
     *     zero and says nothing. Counts are all this remembers; it does not
     *     remember which ids they were.
     *   - Anything that was ALREADY gone when the first baseline was taken. The
     *     memory starts where it starts. Only the backup manifests predate it,
     *     and they record one platform total, not a census.
     *   - Any of it PER TENANT. The census is platform-wide, so one organisation
     *     losing everything it owns is diluted by every organisation that did
     *     not. On a platform with two tenants that dilution is nearly nothing
     *     and this rule will be noisy; on this one, with 25 listed
     *     organisations, it is the reason the ratio bar can be as low as half.
     *
     * @param  array<string,array{model_type:string,collection_name:string,rows:int}>  $census
     * @return array<string,mixed>
     */
    private function gradeCensus(array $census, int $totalRows, bool $wiped): array
    {
        $thresholds = [
            'min_drop' => max(1, (int) config('media-verify.baseline.min_drop', 10)),
            'drop_ratio' => (float) config('media-verify.baseline.drop_ratio', 0.5),
            'vanish_floor' => max(1, (int) config('media-verify.baseline.vanish_floor', 5)),
            'peak_ratio' => (float) config('media-verify.baseline.peak_ratio', 0.75),
            'peak_window_days' => max(1, (int) config('media-verify.baseline.peak_window_days', 30)),
        ];

        $stamp = now()->toIso8601String();
        $path = $this->baselinePath();

        $fresh = static fn (int $rows): array => [
            'rows' => $rows,
            'peak' => $rows,
            'peak_at' => $stamp,
            'held_since' => null,
        ];

        $collections = [];

        foreach ($census as $key => $row) {
            $collections[$key] = $row + ['was' => null, 'peak' => $row['rows'], 'held_since' => null];
        }

        $baseline = $this->readBaseline();

        // ------------------------------------------------- --accept-baseline
        if ($this->option('accept-baseline')) {
            $groups = [];
            $known = is_array($baseline['groups'] ?? null) ? $baseline['groups'] : [];

            // Every series the memory already knew about, not just the ones with
            // rows today. Accepting a loss means accepting that a collection is
            // now EMPTY, and a memory that simply forgets the series cannot say
            // that it was told to.
            $keys = array_values(array_unique(array_merge(array_keys($census), array_keys($known))));
            sort($keys);

            foreach ($keys as $key) {
                $current = $census[$key] ?? null;
                $prior = is_array($known[$key] ?? null) ? $known[$key] : null;

                $rows = (int) ($current['rows'] ?? 0);

                $groups[$key] = $fresh($rows) + [
                    'model_type' => (string) ($current['model_type'] ?? $prior['model_type'] ?? 'unknown'),
                    'collection_name' => (string) ($current['collection_name'] ?? $prior['collection_name'] ?? 'unknown'),
                ];

                $collections[$key] = [
                    'model_type' => $groups[$key]['model_type'],
                    'collection_name' => $groups[$key]['collection_name'],
                    'rows' => $rows,
                    'was' => isset($prior['rows']) ? (int) $prior['rows'] : null,
                    'peak' => $rows,
                    'held_since' => null,
                ];
            }

            $written = $this->writeBaseline([
                'version' => 1,
                'updated_at' => $stamp,
                'source' => 'accepted',
                'total' => $fresh($totalRows),
                'groups' => $groups,
            ]);

            $this->addCheck('media-collections-hold-their-size', 'skipped',
                'the operator accepted this run as the new baseline: '.count($census).
                ' collection(s), '.$totalRows.' row(s)');

            $this->notes[] = '--accept-baseline: the previous memory was discarded and this run\'s census '.
                'recorded in its place'.($written ? '' : ' — AND THE WRITE FAILED, so nothing was recorded').
                '. Any held drop is cleared.';

            return $this->censusPayload('accepted', $path, $stamp, $collections, [], $totalRows, null,
                $thresholds, $fresh($totalRows));
        }

        // -------------------------------------------------------- the memory
        $source = 'local';
        $seed = null;

        if ($baseline === null) {
            $seed = $this->manifestSeed();

            if ($seed !== null) {
                // The manifest records ONE NUMBER, not a census, so it can seed
                // the coarse platform total and nothing finer. Depended on as a
                // documented contract — `media_integrity.rows` in a set's
                // manifest.json — and absent on any host where backups are not
                // installed yet, which is the ordinary case, not an error.
                $source = 'backup_manifest';
                $baseline = [
                    'groups' => [],
                    'total' => [
                        'rows' => $seed['rows'],
                        'peak' => $seed['rows'],
                        'peak_at' => $seed['created_at'],
                        'held_since' => null,
                    ],
                ];

                $this->notes[] = 'No local baseline yet; the platform TOTAL was seeded from backup set '.
                    $seed['set'].' ('.$seed['rows'].' media row(s) at '.$seed['created_at'].
                    '). Per-collection memory starts with this run.';
            } else {
                $source = 'none';
            }
        }

        $priorGroups = is_array($baseline['groups'] ?? null) ? $baseline['groups'] : [];
        $priorTotal = is_array($baseline['total'] ?? null) ? $baseline['total'] : null;

        $keys = array_values(array_unique(array_merge(array_keys($census), array_keys($priorGroups))));
        sort($keys);

        $nextGroups = [];
        $drops = [];

        foreach ($keys as $key) {
            $current = $census[$key] ?? null;
            $prior = is_array($priorGroups[$key] ?? null) ? $priorGroups[$key] : null;

            $rows = (int) ($current['rows'] ?? 0);
            $modelType = (string) ($current['model_type'] ?? $prior['model_type'] ?? 'unknown');
            $collection = (string) ($current['collection_name'] ?? $prior['collection_name'] ?? 'unknown');

            $verdict = $this->evaluateSeries($prior, $rows, $thresholds, $stamp);

            $nextGroups[$key] = $verdict['next'] + [
                'model_type' => $modelType,
                'collection_name' => $collection,
            ];

            $collections[$key] = [
                'model_type' => $modelType,
                'collection_name' => $collection,
                'rows' => $rows,
                'was' => $verdict['was'],
                'peak' => $verdict['next']['peak'],
                'held_since' => $verdict['next']['held_since'],
            ];

            if ($verdict['severe']) {
                $drops[] = [
                    'model_type' => $modelType,
                    'collection_name' => $collection,
                    'kind' => $verdict['kind'],
                    'was' => $verdict['was'],
                    'now' => $rows,
                    'drop' => $verdict['drop'],
                    'ratio' => round($verdict['ratio'], 3),
                    'peak' => $verdict['next']['peak'],
                    'peak_at' => $verdict['next']['peak_at'],
                    'held_since' => $verdict['next']['held_since'],
                ];
            }
        }

        usort($drops, static fn (array $a, array $b) => $b['drop'] <=> $a['drop']);

        // The TOTAL is the coarse net under the fine one, and it is the only
        // series a backup manifest can seed. It is GRADED only when the
        // per-collection net caught nothing: when six collections have each
        // fired, restating the sum adds a line and no information. It is still
        // HELD in that case, so the memory is not quietly poisoned by a loss the
        // run has already called a finding.
        $totalVerdict = $this->evaluateSeries($priorTotal, $totalRows, $thresholds, $stamp);
        $nextTotal = $totalVerdict['next'];

        if ($drops !== [] && $priorTotal !== null && ! $totalVerdict['severe']) {
            $held = (int) ($priorTotal['rows'] ?? $totalRows);

            if ($held > $totalRows) {
                $nextTotal['rows'] = $held;
                $nextTotal['held_since'] = $priorTotal['held_since'] ?? $stamp;
            }
        }

        $totalDrop = ($drops === [] && $totalVerdict['severe']) ? [
            'kind' => $totalVerdict['kind'],
            'was' => $totalVerdict['was'],
            'now' => $totalRows,
            'drop' => $totalVerdict['drop'],
            'ratio' => round($totalVerdict['ratio'], 3),
            'source' => $source,
            'set' => $seed['set'] ?? null,
        ] : null;

        // --------------------------------------------------------- the check
        if ($source === 'none') {
            $this->addCheck('media-collections-hold-their-size', 'skipped',
                'no baseline yet — this run records one; a collection that shrinks from here is a finding');
            $this->notes[] = 'This run had no memory of a previous one, so nothing was compared. The census of '.
                count($census).' collection(s) was recorded at '.$path.'.';
        } elseif ($drops === [] && $totalDrop === null) {
            $this->addCheck('media-collections-hold-their-size', 'pass',
                count($collections).' collection(s) tracked against the previous run, none past the threshold ('.
                $thresholds['min_drop'].'+ rows AND '.(int) round($thresholds['drop_ratio'] * 100).'%+)');
        } else {
            $this->addCheck('media-collections-hold-their-size', 'FAIL',
                ($totalDrop !== null
                    ? 'the media estate fell from '.$totalDrop['was'].' to '.$totalRows.' row(s)'
                    : count($drops).' collection(s) lost more rows than ordinary editing explains'));
        }

        // ------------------------------------------------------- the finding
        //
        // Suppressed on a wiped estate for the same reason the dangling and logo
        // findings are: when the table is empty every collection has vanished,
        // and six findings would describe the symptoms while burying the event.
        if (! $wiped) {
            foreach ($drops as $drop) {
                $this->addFinding(
                    $drop['kind'] === 'vanished' ? self::SEVERITY_CRITICAL : self::SEVERITY_HIGH,
                    'media_collection_shrank',
                    match ($drop['kind']) {
                        'vanished' => 'the `'.$drop['collection_name'].'` media collection on '.
                            $this->shortClass($drop['model_type']).' HAS STOPPED EXISTING — '.
                            $drop['was'].' row(s) at the previous run, 0 now',
                        'drained' => 'the `'.$drop['collection_name'].'` media collection on '.
                            $this->shortClass($drop['model_type']).' has lost '.$drop['drop'].' of a high-water '.
                            $drop['peak'].' row(s) ('.(int) round($drop['ratio'] * 100).'%) since '.
                            $drop['peak_at'].', in steps too small to trip a single run',
                        default => 'the `'.$drop['collection_name'].'` media collection on '.
                            $this->shortClass($drop['model_type']).' lost '.$drop['drop'].' of '.$drop['was'].
                            ' row(s) ('.(int) round($drop['ratio'] * 100).'%) since the previous run',
                    },
                    $drop + [
                        'thresholds' => $thresholds,
                        'baseline' => $path,
                        'note' => 'These rows are GONE, not dangling — there is nothing left on disk to find, '.
                            'which is why no other check here can see this. Compare against the newest backup '.
                            'set before restoring. If the deletion was deliberate, `media:verify '.
                            '--accept-baseline` records the new size; until then this repeats every run.',
                    ]
                );
            }

            if ($totalDrop !== null) {
                $this->addFinding(
                    $totalDrop['now'] === 0 ? self::SEVERITY_CRITICAL : self::SEVERITY_HIGH,
                    'media_estate_shrank',
                    'the media estate fell from '.$totalDrop['was'].' to '.$totalDrop['now'].' row(s) ('.
                        (int) round($totalDrop['ratio'] * 100).'%) since '.
                        ($totalDrop['source'] === 'backup_manifest'
                            ? 'backup set '.$totalDrop['set']
                            : 'the previous run'),
                    $totalDrop + [
                        'thresholds' => $thresholds,
                        'baseline' => $path,
                        'note' => 'No single collection cleared the threshold, so this is a broad shallow loss '.
                            'rather than one category disappearing. Compare against the newest backup set.',
                    ]
                );
            }
        }

        $this->writeBaseline([
            'version' => 1,
            'updated_at' => $stamp,
            'source' => $source === 'backup_manifest' ? 'seeded_from_backup_manifest' : 'local',
            'total' => $nextTotal,
            'groups' => $nextGroups,
        ]);

        return $this->censusPayload($source, $path, $baseline['updated_at'] ?? null, $collections,
            $drops, $totalRows, $totalDrop, $thresholds, $nextTotal);
    }

    /**
     * One series — a collection, or the platform total — against its baseline.
     *
     * @param  array<string,mixed>|null  $was
     * @param  array<string,mixed>  $t
     * @return array{severe:bool, kind:?string, was:?int, drop:int, ratio:float, next:array<string,mixed>}
     */
    private function evaluateSeries(?array $was, int $now, array $t, string $stamp): array
    {
        if ($was === null || ! isset($was['rows'])) {
            return [
                'severe' => false, 'kind' => null, 'was' => null, 'drop' => 0, 'ratio' => 0.0,
                'next' => ['rows' => $now, 'peak' => $now, 'peak_at' => $stamp, 'held_since' => null],
            ];
        }

        $rows = (int) $was['rows'];
        $peak = max((int) ($was['peak'] ?? $rows), $rows);
        $peakAt = isset($was['peak_at']) ? (string) $was['peak_at'] : null;
        $heldSince = isset($was['held_since']) ? (string) $was['held_since'] : null;

        $drop = $rows - $now;
        $ratio = $rows > 0 ? $drop / $rows : 0.0;
        $kind = null;

        if ($drop > 0) {
            if ($now === 0 && $rows >= $t['vanish_floor']) {
                $kind = 'vanished';
            } elseif ($drop >= $t['min_drop'] && $ratio >= $t['drop_ratio']) {
                $kind = 'decimated';
            }
        }

        $parsed = $this->parseStamp($peakAt);
        $peakExpired = $parsed !== null && $parsed->lt(now()->subDays($t['peak_window_days']));

        if ($kind === null && $peak > $rows) {
            $peakDrop = $peak - $now;

            if (! $peakExpired && $peak > 0 && $peakDrop >= $t['min_drop'] && $peakDrop / $peak >= $t['peak_ratio']) {
                $kind = 'drained';
                $drop = $peakDrop;
                $ratio = $peakDrop / $peak;
            }
        }

        if ($kind !== null) {
            // HELD. See the ratchet argument on gradeCensus().
            return [
                'severe' => true, 'kind' => $kind, 'was' => $rows, 'drop' => $drop, 'ratio' => $ratio,
                'next' => [
                    'rows' => $rows,
                    'peak' => $peak,
                    'peak_at' => $peakAt ?? $stamp,
                    'held_since' => $heldSince ?: $stamp,
                ],
            ];
        }

        if ($now >= $peak) {
            $peak = $now;
            $peakAt = $stamp;
        } elseif ($peakExpired || $parsed === null) {
            // The window closed without the mark being reached again, so it
            // forgets: ordinary attrition over a year must not become a
            // permanent page. An unparseable stamp restarts the window rather
            // than freezing it.
            $peak = max($now, $rows);
            $peakAt = $stamp;
        }

        return [
            'severe' => false, 'kind' => null, 'was' => $rows, 'drop' => max(0, $drop), 'ratio' => max(0.0, $ratio),
            'next' => ['rows' => $now, 'peak' => $peak, 'peak_at' => $peakAt, 'held_since' => null],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $collections
     * @param  array<int,array<string,mixed>>  $drops
     * @param  array<string,mixed>|null  $totalDrop
     * @param  array<string,mixed>  $thresholds
     * @param  array<string,mixed>|null  $nextTotal
     * @return array<string,mixed>
     */
    private function censusPayload(string $source, string $path, ?string $baselineAt, array $collections,
        array $drops, int $totalRows, ?array $totalDrop, array $thresholds, ?array $nextTotal = null): array
    {
        $rows = array_values($collections);

        usort($rows, static fn (array $a, array $b) => [$a['model_type'], $a['collection_name']]
            <=> [$b['model_type'], $b['collection_name']]);

        return [
            'baseline_source' => $source,
            'baseline_path' => $path,
            'baseline_at' => $baselineAt,
            'thresholds' => $thresholds,
            'total_rows' => $totalRows,
            'total_baseline' => $nextTotal['rows'] ?? null,
            'total_held_since' => $nextTotal['held_since'] ?? null,
            'collections' => $rows,
            'drops' => $drops,
            'total_drop' => $totalDrop,
        ];
    }

    private function baselinePath(): string
    {
        return (string) config('media-verify.baseline.path', storage_path('app/media-verify/baseline.json'));
    }

    /** @return array<string,mixed>|null */
    private function readBaseline(): ?array
    {
        $path = $this->baselinePath();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            $this->notes[] = 'The baseline at '.$path.' exists and could not be READ; this run compared nothing '.
                'and recorded a fresh one.';

            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_array($decoded['groups'] ?? null)) {
            $this->notes[] = 'The baseline at '.$path.' is not a baseline this version understands; this run '.
                'compared nothing and recorded a fresh one.';

            return null;
        }

        return $decoded;
    }

    /**
     * Written atomically, because a run killed mid-write must leave the previous
     * memory intact rather than a truncated file that the next run discards.
     *
     * This is the ONLY thing this command writes, and it is not part of the
     * media estate: not a media row, not a media file, and deliberately outside
     * every disk the orphan walk covers, so it can never be reported as
     * unreferenced bytes by the command that wrote it.
     *
     * @param  array<string,mixed>  $baseline
     */
    private function writeBaseline(array $baseline): bool
    {
        $path = $this->baselinePath();

        if ($path === '') {
            return false;
        }

        try {
            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $this->notes[] = 'The baseline directory '.$directory.' could not be created; this run has no '.
                    'memory to hand the next one.';

                return false;
            }

            $temporary = $path.'.'.getmypid().'.tmp';
            $encoded = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false || @file_put_contents($temporary, $encoded."\n") === false) {
                @unlink($temporary);
                $this->notes[] = 'The baseline at '.$path.' could not be written; this run has no memory to hand '.
                    'the next one.';

                return false;
            }

            if (! @rename($temporary, $path)) {
                @unlink($temporary);
                $this->notes[] = 'The baseline at '.$path.' could not be replaced; the previous memory is intact '.
                    'and this run was not recorded.';

                return false;
            }
        } catch (\Throwable $e) {
            $this->notes[] = 'The baseline at '.$path.' could not be written: '.$e->getMessage();

            return false;
        }

        return true;
    }

    /**
     * The newest finished backup set's media row count, read as a CONTRACT.
     *
     * `backup:run` owns that file; this reads `<destination>/<set>/manifest.json`
     * for `complete: true` and an integer `media_integrity.rows`, and nothing
     * else. It is consulted only when there is no local baseline — the first run
     * on a host, or the run after somebody wiped `storage/` — and it can seed
     * only the platform TOTAL, because the manifest records one number and not a
     * census.
     *
     * Every failure here is silence: no destination, no set, an unreadable or
     * differently-shaped manifest all mean "no seed", which is the ordinary
     * state on a host where backups are not installed yet.
     *
     * @return array{rows:int,set:string,created_at:string}|null
     */
    private function manifestSeed(): ?array
    {
        $destination = rtrim((string) config('backup.destination', ''), '/');

        if ($destination === '' || ! is_dir($destination)) {
            return null;
        }

        $best = null;

        try {
            foreach ((array) scandir($destination) as $entry) {
                $entry = (string) $entry;

                if ($entry === '.' || $entry === '..' || str_ends_with($entry, '.partial')) {
                    continue;
                }

                $manifestPath = $destination.'/'.$entry.'/manifest.json';

                if (! is_file($manifestPath)) {
                    continue;
                }

                $decoded = json_decode((string) @file_get_contents($manifestPath), true);

                if (! is_array($decoded) || ($decoded['complete'] ?? null) !== true) {
                    continue;
                }

                $integrity = $decoded['media_integrity'] ?? null;

                if (! is_array($integrity) || ! is_numeric($integrity['rows'] ?? null)) {
                    continue;
                }

                $createdAt = (string) ($decoded['created_at'] ?? '');
                $order = $this->parseStamp($createdAt)?->getTimestamp() ?? (int) @filemtime($manifestPath);

                if ($best === null || $order > $best['order']) {
                    $best = [
                        'order' => $order,
                        'rows' => (int) $integrity['rows'],
                        'set' => (string) ($decoded['set'] ?? $entry),
                        'created_at' => $createdAt !== '' ? $createdAt : date('c', $order),
                    ];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        if ($best === null) {
            return null;
        }

        unset($best['order']);

        return $best;
    }

    private function parseStamp(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------- the serving path

    /**
     * `public/storage` is how `storage/app/public` is reachable over HTTP. Break
     * it and every media URL this command validates 404s while every row and
     * every file it examined is intact — a `clean` report about a platform
     * showing no images anywhere.
     *
     * Graded only where it is LOAD-BEARING: some media disk on this host must
     * actually resolve to the target. On a host whose media lives on S3, or in a
     * test whose disk is faked somewhere else, the answer is `skipped` with the
     * reason attached, never a verdict about a path that serves nothing.
     *
     * @param  array<int,string>  $diskNames
     * @return array<string,mixed>
     */
    private function auditPublicStorageLink(array $diskNames): array
    {
        $link = (string) config('media-verify.public_link.link', '');
        $target = (string) config('media-verify.public_link.target', '');

        $out = [
            'outcome' => 'skipped',
            'reason' => null,
            'detail' => '',
            'link' => $link,
            'target' => $target,
            'served_disk' => null,
        ];

        if ($link === '' || $target === '') {
            $out['detail'] = 'no serving path is configured for this host';

            return $out;
        }

        $targetReal = realpath($target);

        if ($targetReal === false) {
            $out['detail'] = 'the target '.$target.' does not exist on this host';

            return $out;
        }

        foreach ($diskNames as $name) {
            $filesystem = $this->disk($name);

            if ($filesystem === null) {
                continue;
            }

            try {
                $root = realpath((string) $filesystem->path(''));
            } catch (\Throwable) {
                continue;
            }

            if ($root !== false && $root === $targetReal) {
                $out['served_disk'] = $name;

                break;
            }
        }

        if ($out['served_disk'] === null) {
            $out['detail'] = 'no media disk on this host resolves to '.$targetReal.
                ', so '.$link.' is not the path this estate is served through';

            return $out;
        }

        if (! is_link($link) && ! file_exists($link)) {
            $out['outcome'] = 'FAIL';
            $out['reason'] = 'absent';
            $out['detail'] = $link.' does not exist — every media URL on the `'.$out['served_disk'].
                '` disk 404s while the rows and the files behind them are intact';

            return $out;
        }

        $linkReal = realpath($link);

        if ($linkReal === false) {
            $out['outcome'] = 'FAIL';
            $out['reason'] = 'dangling';
            $out['detail'] = $link.' is a link that resolves to nothing — every media URL on the `'.
                $out['served_disk'].'` disk 404s';

            return $out;
        }

        if ($linkReal !== $targetReal) {
            $out['outcome'] = 'FAIL';
            $out['reason'] = 'wrong_target';
            $out['detail'] = $link.' resolves to '.$linkReal.', not '.$targetReal.
                ' — media is served from somewhere other than the disk this run checked';

            return $out;
        }

        $out['outcome'] = 'pass';
        $out['detail'] = $link.' resolves to the root of the `'.$out['served_disk'].'` disk';

        return $out;
    }

    /** @param array<string,mixed> $link */
    private function gradePublicLink(array $link): void
    {
        $this->addCheck('media-is-served-through-the-public-link',
            $link['outcome'] === 'FAIL' ? 'FAIL' : ($link['outcome'] === 'pass' ? 'pass' : 'skipped'),
            (string) $link['detail']);

        if ($link['outcome'] !== 'FAIL') {
            return;
        }

        $this->addFinding(
            self::SEVERITY_CRITICAL,
            'public_storage_link_broken',
            'the link every media URL is served through is '.$link['reason'].' — '.
                'every image on this platform 404s while its row and its file are intact',
            [
                'link' => $link['link'],
                'target' => $link['target'],
                'reason' => $link['reason'],
                'disk' => $link['served_disk'],
                'note' => '`php artisan storage:link` recreates it. Nothing in the media estate is damaged by '.
                    'this and nothing needs restoring; until it is fixed, every other check here is green '.
                    'about images nobody can load.',
            ]
        );
    }

    // ----------------------------------------------------------- the disks

    /**
     * The two disk failures are DIFFERENT EVENTS and this is where they part.
     *
     * A disk that does not RESOLVE has no driver in this application: no code
     * path anywhere can reach a file on it, so a media row naming one is a
     * broken reference — the exact class of damage this command exists to report
     * — and it is a finding at exit 1, not a degradation. It used to be
     * `partial`, which said "I could not check some of it" about rows that were
     * already, permanently, unreachable.
     *
     * A disk that resolved and then THREW is a transient read failure: a network
     * blip, a permission change. That is a genuine gap in this run's evidence
     * and nothing more, so it stays `partial` at exit 3. The payload carries the
     * two under separate keys so a reader can check which one they have rather
     * than take the verdict on trust.
     */
    private function gradeDisks(string $table, int $totalRows, int $verified): void
    {
        $named = [];

        foreach ($this->unresolvableDisks as $name => $why) {
            $rows = 0;

            try {
                $rows = (int) DB::table($table)->where('disk', $name)->count();
            } catch (\Throwable) {
                $rows = 0;
            }

            if ($rows === 0) {
                // Reached because the walk covers the configured media disk and
                // `media-verify.extra_disks` whether or not a row names them.
                // Nothing is broken by a disk nothing points at.
                $this->notes[] = "Disk [{$name}] does not resolve on this host ({$why}), and no media row names ".
                    'it — nothing in the estate is broken by it.';

                continue;
            }

            $named[$name] = $rows;

            $this->addFinding(
                self::SEVERITY_CRITICAL,
                'media_rows_name_an_unresolvable_disk',
                $rows.' media row(s) name disk `'.$name.'`, which this application has no driver for — '.
                    'every file behind those rows is unreachable by every code path, not just by this one',
                [
                    'disk' => $name,
                    'rows' => $rows,
                    'why' => $why,
                    'note' => 'This is a BROKEN REFERENCE, not a gap in this run. Either the disk was removed '.
                        'from config/filesystems.php with rows still pointing at it, or the rows were written '.
                        'on a machine that had it. It is exit 1 and it will not clear itself.',
                ]
            );
        }

        $this->addCheck('media-rows-name-a-disk-that-resolves',
            $named !== [] ? 'FAIL' : ($totalRows === 0 ? 'skipped' : 'pass'),
            $named !== []
                ? implode('; ', array_map(
                    static fn (string $name, int $rows) => $rows.' row(s) name `'.$name.'`, which does not resolve',
                    array_keys($named), $named))
                : ($totalRows === 0 ? 'no rows to check' : 'every disk named by a media row resolves'));

        if ($this->unreadableDisks !== []) {
            $this->degraded = true;
            $this->degradedBy[] = 'unreadable_disk';

            foreach ($this->unreadableDisks as $name => $why) {
                $this->errors[] = "Disk [{$name}] resolved and then failed a read: {$why}";
            }
        }

        if ($totalRows > 0 && $verified === 0 && ($this->unreadableDisks !== [] || $this->unresolvableDisks !== [])) {
            // Not one row's file state was determined. Whatever else this run
            // says, it is not evidence about whether the files are there.
            $this->blocked = true;
            $this->errors[] = 'Not one of the '.$totalRows.' media row(s) could be verified: every disk they '.
                'name was unreadable or unresolvable. This run is not evidence about the media estate.';
        }
    }

    // ------------------------------------------------------------ reporting

    /** @param array<string,mixed> $evidence */
    private function addFinding(string $severity, string $kind, string $summary, array $evidence): void
    {
        $this->findings[] = [
            'severity' => $severity,
            'kind' => $kind,
            'summary' => $summary,
            'evidence' => $evidence,
        ];
    }

    /** @param 'pass'|'FAIL'|'partial'|'skipped' $outcome */
    private function addCheck(string $check, string $outcome, string $detail): void
    {
        $this->checks[] = [
            'check' => $check,
            'outcome' => $outcome,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $groups
     * @param  array<string,mixed>  $orphans
     * @param  array<string,mixed>  $expectations
     * @param  array<string,mixed>  $budget
     * @param  array<string,mixed>  $census
     * @param  array<string,mixed>  $link
     */
    private function report(
        Carbon $startedAt,
        float $started,
        array $groups,
        array $orphans,
        array $expectations,
        array $budget,
        int $totalRows,
        int $checked,
        int $verified,
        array $census = [],
        array $link = [],
    ): int {
        $status = match (true) {
            $this->hasFinding('media_table_empty') => 'empty',
            $this->findings !== [] => 'broken',
            $this->blocked => 'incomplete',
            $this->degraded => 'partial',
            default => 'clean',
        };

        $exit = match ($status) {
            'empty' => self::EXIT_EMPTY,
            'broken' => self::EXIT_BROKEN,
            'incomplete' => self::EXIT_INCOMPLETE,
            'partial' => self::EXIT_PARTIAL,
            default => self::EXIT_CLEAN,
        };

        $danglingGroups = array_values(array_filter(
            $groups,
            static fn (array $g) => $g['dangling'] > 0
        ));

        usort($danglingGroups, static fn ($a, $b) => $b['dangling'] <=> $a['dangling']);

        $payload = [
            'command' => 'media:verify',
            'status' => $status,
            'started_at' => $startedAt->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'estate' => [
                'media_rows' => $totalRows,
                // VISITED and VERIFIED are different numbers whenever a disk
                // could not be read, and no line here may quote the first while
                // meaning the second.
                'rows_checked' => $checked,
                'rows_verified' => $verified,
                'disks' => $orphans['disks'] ?? [],
                // Two keys, because they are two events with two verdicts: a
                // row naming an unresolvable disk is `broken`, a disk that
                // failed a read is `partial`. See gradeDisks().
                'unresolvable_disks' => $this->unresolvableDisks,
                'unreadable_disks' => $this->unreadableDisks,
            ],
            'dangling' => [
                'rows' => array_sum(array_column($groups, 'dangling')),
                'groups' => $danglingGroups,
            ],
            // Reported on every run, graded on none. See the docblock.
            'orphans' => [
                'groups' => $orphans['groups'] ?? 0,
                'files' => $orphans['files'] ?? 0,
                'bytes' => $orphans['bytes'] ?? 0,
                'truncated' => $orphans['truncated'] ?? false,
                'sample' => $orphans['sample'] ?? [],
                'unrecognised' => array_slice($orphans['unrecognised'] ?? [], 0, 25),
            ],
            'expectations' => [
                'rule' => 'a masjid with listed_at set must have a `logos` media row whose file exists '.
                    '(Mobile\\MasjidsController@index reads it; StoreMasjidRequest requires it at creation)',
                'listed' => $expectations['listed'] ?? 0,
                'without_logo' => count($expectations['missing'] ?? []),
                // Neither logo-less nor resolving: this run does not know. Kept
                // out of `without_logo` because it is not evidence of absence,
                // and out of `pass` because it is not evidence of presence.
                'unverifiable' => count($expectations['unverifiable'] ?? []),
                'organisations' => array_slice($expectations['missing'] ?? [], 0, 25),
                'unverifiable_organisations' => array_slice($expectations['unverifiable'] ?? [], 0, 25),
            ],
            // The memory: what each collection held at the previous run, what it
            // holds now, and every drop past the threshold. Reported on every
            // run so it can be diffed, whatever the verdict.
            'census' => $census !== [] ? $census : [
                'baseline_source' => 'none',
                'baseline_path' => $this->baselinePath(),
                'baseline_at' => null,
                'thresholds' => [],
                'total_rows' => $totalRows,
                'total_baseline' => null,
                'total_held_since' => null,
                'collections' => [],
                'drops' => [],
                'total_drop' => null,
            ],
            'public_link' => $link !== [] ? $link : [
                'outcome' => 'skipped',
                'reason' => null,
                'detail' => 'this run did not get far enough to look',
                'link' => null,
                'target' => null,
                'served_disk' => null,
            ],
            'budget' => $budget,
            'checks' => $this->checks,
            'findings' => $this->findings,
            'errors' => $this->errors,
            'degraded_by' => array_values(array_unique($this->degradedBy)),
            'notes' => $this->notes,
        ];

        $this->log($status, $payload);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->renderHuman($payload);

        return $exit;
    }

    private function hasFinding(string $kind): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line per run, whatever the verdict, because `schedule:run` discards
     * stdout and a check nobody can prove ran is a check that can stop running
     * unnoticed — which is the whole shape of this incident.
     *
     * The LEVEL carries the verdict so an alert rule can route without parsing
     * the body. The contract is documented in routes/console.php.
     *
     * @param  array<string,mixed>  $payload
     */
    private function log(string $status, array $payload): void
    {
        $channel = Log::channel(config('media-verify.log_channel'));

        $summary = [
            'status' => $status,
            'media_rows' => $payload['estate']['media_rows'],
            'rows_checked' => $payload['estate']['rows_checked'],
            'rows_verified' => $payload['estate']['rows_verified'],
            'dangling_rows' => $payload['dangling']['rows'],
            // In the summary on every run including a clean one: it grades
            // nothing, and an operator about to run `media-library:clean` needs
            // to have seen it beforehand, not afterwards.
            'orphans' => [
                'groups' => $payload['orphans']['groups'],
                'bytes' => $payload['orphans']['bytes'],
                'truncated' => $payload['orphans']['truncated'],
            ],
            'listed_without_logo' => $payload['expectations']['without_logo'],
            'listed_unverifiable' => $payload['expectations']['unverifiable'],
            // The census in the summary of every run, clean ones included: the
            // whole value of a memory is that somebody can diff last night's
            // line against tonight's without opening anything.
            'census' => [
                'baseline' => $payload['census']['baseline_source'],
                'collections' => count($payload['census']['collections']),
                'drops' => count($payload['census']['drops']) + ($payload['census']['total_drop'] !== null ? 1 : 0),
            ],
            'public_link' => $payload['public_link']['outcome'],
            'findings' => count($payload['findings']),
            'errors' => $payload['errors'],
            'degraded_by' => $payload['degraded_by'],
        ];

        if ($status === 'clean') {
            $channel->info('media:verify clean', $summary);

            return;
        }

        if ($status === 'partial') {
            // A ticket, not a page. One row left unscanned by a budget must not
            // ring at the same volume as an emptied table, or the volume stops
            // meaning anything.
            $channel->warning('media:verify partial', $summary);

            return;
        }

        $detail = array_map(static fn (array $f) => [
            'severity' => $f['severity'],
            'kind' => $f['kind'],
            'summary' => $f['summary'],
        ], $payload['findings']);

        if ($status === 'empty') {
            // The one level above `error`, for the one condition that is not a
            // bigger version of anything else on this ladder.
            $channel->critical('media:verify EMPTY — the media table holds no rows', $summary + ['detail' => $detail]);

            return;
        }

        $channel->error('media:verify '.$status, $summary + ['detail' => $detail]);
    }

    /** @param array<string,mixed> $payload */
    private function renderHuman(array $payload): void
    {
        $estate = $payload['estate'];
        $orphans = $payload['orphans'];

        $this->line('');
        $this->line('<options=bold>media:verify</> — read-only. This command never deletes, moves or repairs anything.');
        $this->line('media rows: '.$estate['media_rows'].
            '  |  visited: '.$estate['rows_checked'].
            '  |  <options=bold>verified: '.$estate['rows_verified'].'</>'.
            '  |  disks: '.(implode(', ', $estate['disks']) ?: 'none').
            '  |  '.$payload['duration_ms'].'ms');

        $this->line('dangling rows: '.($payload['dangling']['rows'] > 0
            ? '<fg=red>'.$payload['dangling']['rows'].'</>'
            : '0').
            '  |  listed organisations without a logo: '.($payload['expectations']['without_logo'] > 0
                ? '<fg=red>'.$payload['expectations']['without_logo'].'</>'
                : '0').' of '.$payload['expectations']['listed']);

        // Printed here, above the check table, for the same reason
        // tenancy:canary prints its route-table census there: it decides no
        // verdict and it must still be read.
        $this->line('unreferenced on disk: '.($orphans['truncated'] ? 'at least ' : '').
            $orphans['groups'].' group(s), '.$orphans['files'].' file(s), '.
            $this->bytes($orphans['bytes']).
            ' — <fg=yellow>this is what `media-library:clean` would delete</>; this command will not.');

        if ($orphans['unrecognised'] !== []) {
            $this->line('unrecognised paths (NOT counted as orphans): '.count($orphans['unrecognised']).
                ' — '.implode(', ', array_slice($orphans['unrecognised'], 0, 3)));
        }

        $census = $payload['census'];
        $censusDrops = count($census['drops']) + ($census['total_drop'] !== null ? 1 : 0);

        $this->line('memory: baseline '.$census['baseline_source'].
            ($census['baseline_at'] !== null ? ' from '.$census['baseline_at'] : '').
            '  |  collections tracked: '.count($census['collections']).
            '  |  drops past threshold: '.($censusDrops > 0 ? '<fg=red>'.$censusDrops.'</>' : '0'));

        if ($payload['public_link']['outcome'] === 'FAIL') {
            $this->line('<fg=red;options=bold>serving path BROKEN</> — '.$payload['public_link']['detail']);
        }

        $this->line('');

        if ($payload['checks'] !== []) {
            $this->table(['', 'check', 'detail'], array_map(static fn (array $c) => [
                match ($c['outcome']) { 'pass' => 'ok', 'skipped' => '--', 'partial' => '~', default => 'FAIL' },
                $c['check'],
                $c['detail'],
            ], $payload['checks']));
        }

        if ($payload['dangling']['groups'] !== []) {
            $this->line('');
            $this->line('<options=bold>dangling by owner and collection</>');
            $this->table(['model_type', 'collection', 'dangling', 'of', 'example path'],
                array_map(fn (array $g) => [
                    $this->shortClass($g['model_type']),
                    $g['collection_name'],
                    $g['dangling'],
                    $g['total'],
                    $g['sample'][0]['path'] ?? '',
                ], $payload['dangling']['groups']));
        }

        if ($census['drops'] !== []) {
            $this->line('');
            $this->line('<options=bold>collections that lost more than ordinary editing explains</>');
            $this->table(['model_type', 'collection', 'was', 'now', 'lost', 'held since'],
                array_map(fn (array $d) => [
                    $this->shortClass($d['model_type']),
                    $d['collection_name'],
                    $d['kind'] === 'drained' ? $d['peak'].' (peak)' : $d['was'],
                    $d['now'],
                    $d['drop'].' ('.(int) round($d['ratio'] * 100).'%)',
                    $d['held_since'] ?? '',
                ], $census['drops']));
        }

        if ($payload['expectations']['organisations'] !== []) {
            $this->line('');
            $this->line('<options=bold>listed organisations the app directory shows without a logo</>');
            $this->table(['masjid', 'name', 'why'], array_map(static fn (array $m) => [
                $m['masjid_id'],
                $m['name'],
                $m['reason'],
            ], $payload['expectations']['organisations']));
        }

        if ($payload['expectations']['unverifiable_organisations'] !== []) {
            $this->line('');
            $this->line('<options=bold>listed organisations whose logo this run could NOT read</> '.
                '(neither missing nor confirmed present)');
            $this->table(['masjid', 'name', 'disk', 'why'], array_map(static fn (array $m) => [
                $m['masjid_id'],
                $m['name'],
                $m['disk'] ?? '',
                $m['reason'],
            ], $payload['expectations']['unverifiable_organisations']));
        }

        foreach ($payload['notes'] as $note) {
            $this->line("  <fg=gray>note:</> {$note}");
        }

        foreach ($payload['errors'] as $error) {
            $this->line("  <fg=red>error:</> {$error}");
        }

        $this->line('');

        match ($payload['status']) {
            'empty' => $this->line('<fg=red;options=bold>EMPTY</> — the `media` table holds no rows at all. '.
                'Every image reference on this platform is dangling at once. Restore the rows; '.
                'do NOT run `media-library:clean`. (exit 4)'),
            'broken' => $this->line('<fg=red;options=bold>BROKEN</> — '.count($payload['findings']).
                ' finding(s). (exit 1)'),
            'incomplete' => $this->line('<fg=red;options=bold>INCOMPLETE</> — this run is not evidence about '.
                'the media estate. (exit 2)'),
            'partial' => $this->line('<fg=yellow;options=bold>PARTIAL</> — evidence about most of the estate; '.
                'gaps named above ('.implode(', ', $payload['degraded_by']).'). (exit 3)'),
            default => $this->line('<fg=green;options=bold>CLEAN</> — all '.$estate['rows_verified'].
                ' media row(s) were verified and have their file, every listed organisation has a logo that '.
                'resolves, and no collection shrank past the threshold. (exit 0)'),
        };

        $this->line('');

        foreach ($payload['findings'] as $finding) {
            $this->line('  <fg=red>['.$finding['severity'].']</> '.$finding['kind'].' — '.$finding['summary']);
        }
    }

    // -------------------------------------------------------------- helpers

    /**
     * Resolve a disk once, remember the failure. Returns null when the disk is
     * unusable — the caller must treat that as "unknown", never as "missing".
     */
    private function disk(string $name): ?object
    {
        if (array_key_exists($name, $this->diskCache)) {
            return $this->diskCache[$name];
        }

        try {
            return $this->diskCache[$name] = Storage::disk($name);
        } catch (\Throwable $e) {
            // This is NOT a read failure. There is no driver for this name, so
            // no code path in the application can reach a file on it — the
            // failure belongs to the rows that name it, not to this run.
            $this->unresolvableDisks[$name] ??= $e->getMessage();

            return $this->diskCache[$name] = null;
        }
    }

    /**
     * A disk that RESOLVED and then threw. Transient by assumption — a network
     * blip, a permission change, a full descriptor table — and therefore a
     * degradation of this run rather than a defect in the estate.
     */
    private function markDiskUnreadable(string $name, string $why): void
    {
        $this->unreadableDisks[$name] ??= $why;
        $this->diskCache[$name] = null;
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts) ?: $fqcn;
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$i];
    }
}
