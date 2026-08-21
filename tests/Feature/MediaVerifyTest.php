<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * The verifier must be shown to GO RED, or it is decoration.
 *
 * Every state this file asserts was live on production and detected by nothing:
 * media rows pointing at files that were never copied off somebody's laptop,
 * seed art on disk that no row has claimed for months, listed organisations
 * showing no logo in the app directory, and finally the whole `media` table at
 * zero rows against an auto_increment of 422 while a lobby television read
 * "No announcements".
 *
 * The assertions that matter here are the ones where `media:verify` exits
 * non-zero, and the two where it must NOT: a clean estate has to stay silent,
 * and an orphan file must never be graded — a check that cries on an ordinary
 * Tuesday is a check somebody turns off, and then the emergency is unheard.
 */
class MediaVerifyTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Storage::fake('public');

        // The run's MEMORY, per test. Pointing it at the real
        // `storage/app/media-verify` would let one test's census become the next
        // test's baseline — and a suite where test B inherits test A's 5 rows
        // and then finds a fresh database would report a wipe that never
        // happened. The isolation is the point being demonstrated: the baseline
        // is a real file with real continuity, not an in-memory convenience.
        $this->scratch = sys_get_temp_dir().'/media-verify-'.uniqid('', true);

        config(['media-verify.baseline.path' => $this->scratch.'/baseline.json']);

        // No backup destination unless a test builds one, so `manifestSeed()`
        // can never read whatever is lying around on the machine running this.
        config(['backup.destination' => $this->scratch.'/backups']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->scratch)) {
            exec('rm -rf '.escapeshellarg($this->scratch));
        }

        parent::tearDown();
    }

    // ================================================================
    // 1. Dangling rows — a row whose file is not there
    // ================================================================

    #[Test]
    public function a_media_row_whose_file_is_gone_is_a_finding(): void
    {
        $masjid = $this->makeMasjid();

        $this->makeMedia($masjid, 'logos', withFile: false);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'media:verify exited 0 on a row pointing at a file that is not on disk');
        $this->assertSame('broken', $run['status']);
        $this->assertSame(1, $run['dangling']['rows']);

        $finding = $this->finding($run, 'dangling_media');

        $this->assertSame('critical', $finding['severity'], 'a collection that is entirely gone is not a maintenance ticket');
        $this->assertSame(Masjid::class, $finding['evidence']['model_type']);
        $this->assertSame('logos', $finding['evidence']['collection_name']);
        $this->assertSame(1, $finding['evidence']['dangling']);

        // The reproducing detail: WHICH file was looked for, on WHICH disk.
        $this->assertSame('public', $finding['evidence']['sample'][0]['disk']);
        $this->assertStringContainsString('.png', $finding['evidence']['sample'][0]['path']);
    }

    #[Test]
    public function dangling_rows_are_grouped_by_model_and_collection(): void
    {
        // The shape of the real loss: 25 logo records and 45 announcement
        // images, and an operator who needs to read "25 logos are broken" on one
        // line rather than scroll 25 rows. Unlisted organisations here so the
        // directory check stays out of this test's way.
        foreach (range(1, 3) as $i) {
            $this->makeMedia($this->makeMasjid(listed: false), 'logos', withFile: false);
        }

        $announcement = $this->makeAnnouncement($this->makeMasjid(listed: false));

        $this->makeMedia($announcement, 'announcements', withFile: false);
        $this->makeMedia($announcement, 'announcements', withFile: false);

        // One healthy row, so "grouped" is not "everything in one bucket".
        $this->makeMedia($this->makeMasjid(listed: false), 'header_logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertSame(5, $run['dangling']['rows']);

        $groups = collect($run['dangling']['groups'])
            ->keyBy(fn (array $g) => $g['model_type'].'::'.$g['collection_name']);

        $this->assertCount(2, $groups, 'a healthy collection was reported as a dangling group');
        $this->assertSame(3, $groups[Masjid::class.'::logos']['dangling']);
        $this->assertSame(3, $groups[Masjid::class.'::logos']['total']);
        $this->assertSame(2, $groups[Announcement::class.'::announcements']['dangling']);

        // Loudest group first — the operator reads the top of the table.
        $this->assertSame(Masjid::class, $run['dangling']['groups'][0]['model_type']);
    }

    #[Test]
    public function a_partly_broken_collection_reads_quieter_than_a_wholly_lost_one(): void
    {
        // Both page. They must not read the same: "3 of 3 gone" is a category
        // of content that has stopped existing; "1 of 3" is maintenance.
        $masjid = $this->makeMasjid(listed: false);

        $this->makeMedia($masjid, 'galleries', withFile: true);
        $this->makeMedia($masjid, 'galleries', withFile: true);
        $this->makeMedia($masjid, 'galleries', withFile: false);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertSame('high', $this->finding($run, 'dangling_media')['severity']);
    }

    // ================================================================
    // 2. Orphan files — reported loudly, graded never
    // ================================================================

    #[Test]
    public function a_file_with_no_media_row_is_reported_and_never_graded(): void
    {
        // 56 folders of stock seed art survived on production while the 226 rows
        // that should have pointed at bytes were gone. Unreferenced bytes break
        // nothing for anybody — no request 404s, no app renders a hole — so this
        // must not be a finding, or the check becomes a permanent amber and gets
        // silenced. It must still be impossible to miss.
        //
        // This also pins the disjunct MediaVerify rejected: the `media` table is
        // empty here and there ARE unreferenced bytes on the disk, and that
        // combination must NOT reach the `empty` verdict. Litter is not evidence
        // of a wipe — a seeder, an aborted upload and a tenant deleted last year
        // all leave exactly this.
        Storage::disk('public')->put('9001/quruan-book.jpeg', str_repeat('x', 2048));
        Storage::disk('public')->put('9002/charity_icon.png', str_repeat('x', 1024));

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit, 'unreferenced bytes were graded as a defect');
        $this->assertSame('clean', $run['status']);
        $this->assertSame([], $run['findings']);

        $this->assertSame(2, $run['orphans']['groups']);
        $this->assertSame(2, $run['orphans']['files']);
        $this->assertSame(3072, $run['orphans']['bytes'],
            'the operator must see the SIZE of what media-library:clean would delete, before anyone runs it');
        $this->assertFalse($run['orphans']['truncated']);
    }

    #[Test]
    public function a_directory_that_belongs_to_a_media_row_is_not_an_orphan(): void
    {
        $masjid = $this->makeMasjid(listed: false);
        $media = $this->makeMedia($masjid, 'logos', withFile: true);

        Storage::disk('public')->put('9001/stray.png', 'x');

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame(1, $run['orphans']['groups'], 'a directory a live row points at was called an orphan');
        $this->assertSame('9001', basename($run['orphans']['sample'][0]['path']));
        $this->assertNotSame((string) $media->id, basename($run['orphans']['sample'][0]['path']));
    }

    #[Test]
    public function a_path_that_is_not_shaped_like_media_is_never_called_an_orphan(): void
    {
        // Reporting a stranger's file as deletable is how a verifier becomes the
        // cause of the next incident. Spatie stores media at `{id}/…`, so a
        // non-numeric directory is not media and is named separately.
        Storage::disk('public')->put('some-other-feature/report.pdf', 'x');
        Storage::disk('public')->put('loose-at-the-root.txt', 'x');

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame(0, $run['orphans']['groups'], 'a non-media path was counted as a deletable orphan');
        $this->assertCount(2, $run['orphans']['unrecognised']);
    }

    // ================================================================
    // 3. A listed organisation with no logo
    // ================================================================

    #[Test]
    public function a_listed_organisation_with_no_logo_is_a_finding(): void
    {
        // GET /api/mobile/masjids is `Masjid::listed()->with('logo')->get()` —
        // the screen every app opens with — and StoreMasjidRequest declares
        // `'logo' => 'required|image'`. An organisation with none did not choose
        // that; something took it.
        //
        // A SIBLING organisation with a working logo, deliberately: it keeps the
        // `media` table non-empty (an empty table is the louder `empty` verdict,
        // asserted separately below) and it proves this check discriminates
        // between two organisations rather than firing on a global condition.
        $healthy = $this->makeMasjid(listed: true);
        $this->makeMedia($healthy, 'logos', withFile: true);

        $listed = $this->makeMasjid(listed: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'a listed organisation with no logo passed');
        $this->assertSame('broken', $run['status']);

        $finding = $this->finding($run, 'listed_organisation_without_logo');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame(2, $finding['evidence']['listed']);
        $this->assertSame(1, $finding['evidence']['without_logo']);
        $this->assertSame($listed->id, $finding['evidence']['organisations'][0]['masjid_id']);
        $this->assertSame('no_logo_record', $finding['evidence']['organisations'][0]['reason']);
    }

    #[Test]
    public function a_listed_organisation_whose_logo_file_is_gone_is_a_finding(): void
    {
        // The other half, and a different repair: the RECORD is there, so the
        // directory happily emits a URL, and the URL 404s. This is what "every
        // masjid showed no logo in the apps" actually was before the rows went
        // too.
        $listed = $this->makeMasjid(listed: true);
        $media = $this->makeMedia($listed, 'logos', withFile: false);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit);

        $finding = $this->finding($run, 'listed_organisation_without_logo');

        $this->assertSame('logo_file_missing', $finding['evidence']['organisations'][0]['reason']);
        $this->assertSame($media->id, $finding['evidence']['organisations'][0]['media_id']);
    }

    #[Test]
    public function an_unlisted_organisation_without_a_logo_is_not_a_finding(): void
    {
        // The rule is derived from what the app READS, not invented. `listed_at`
        // is the directory gate (.claude/rules/directory-listing.md); an
        // organisation nobody can find in the picker is not a live defect on it,
        // and grading it would make every half-finished pilot tenant a page.
        $this->makeMasjid(listed: false);

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame(0, $run['expectations']['listed']);
        $this->assertSame([], $run['findings']);
    }

    // ================================================================
    // 4. The whole table empty — a different event, not a bigger one
    // ================================================================

    #[Test]
    public function a_wholly_empty_media_table_is_a_louder_condition_than_a_broken_row(): void
    {
        // MEASURED 2026-08-21: `media` held 0 rows against an auto_increment of
        // 422. Folded into `broken`, this would be indistinguishable from one
        // dangling thumbnail to an alert rule that never reads the body — and it
        // is not a bigger version of a dangling thumbnail, it is a different
        // event with a different response.
        $this->makeMasjid(listed: true);
        $this->makeMasjid(listed: true);

        Storage::disk('public')->put('9001/masjid_on_zokhrufa.png', 'x');

        $channel = \Mockery::mock();
        $channel->shouldIgnoreMissing();

        Log::shouldReceive('channel')->andReturn($channel);

        $channel->shouldReceive('critical')->once();
        $channel->shouldReceive('error')->never();
        $channel->shouldReceive('warning')->never();
        $channel->shouldReceive('info')->never();

        [$exit, $run] = $this->verify();

        $this->assertSame(4, $exit, 'an emptied media table exited on the same code as a single broken row');
        $this->assertSame('empty', $run['status']);
        $this->assertSame(0, $run['estate']['media_rows']);

        $finding = $this->finding($run, 'media_table_empty');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame(2, $finding['evidence']['listed_organisations']);
        $this->assertSame(1, $finding['evidence']['orphan_groups']);
        $this->assertStringContainsString('media-library:clean', $finding['evidence']['note']);

        // The symptoms are still reported — suppressing them would make the
        // loudest report the least informative one — they simply do not decide
        // the verdict.
        $this->assertSame(2, $run['expectations']['without_logo']);
        $this->assertCount(1, $run['findings'], 'the wiped estate was reported as three separate problems');
    }

    #[Test]
    public function an_empty_table_on_a_platform_that_expects_nothing_stays_quiet(): void
    {
        // A fresh install and a scratch database both have zero media rows. A
        // check that pages on those is a check that gets uninstalled on day one.
        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);
        $this->assertSame([], $run['findings']);
    }

    // ================================================================
    // 5. A clean estate exits 0 and says nothing loud
    // ================================================================

    #[Test]
    public function a_clean_estate_exits_zero_and_logs_at_info(): void
    {
        $listed = $this->makeMasjid(listed: true);
        $this->makeMedia($listed, 'logos', withFile: true);

        $announcement = $this->makeAnnouncement($listed);
        $this->makeMedia($announcement, 'announcements', withFile: true);

        $channel = \Mockery::mock();
        $channel->shouldIgnoreMissing();

        Log::shouldReceive('channel')->andReturn($channel);

        // One line per run even when clean: a check nobody can prove ran is a
        // check that can stop running unnoticed, which is the entire shape of
        // this incident.
        $channel->shouldReceive('info')->once()->with('media:verify clean', \Mockery::type('array'));
        $channel->shouldReceive('warning')->never();
        $channel->shouldReceive('error')->never();
        $channel->shouldReceive('critical')->never();

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);
        $this->assertSame([], $run['findings']);
        $this->assertSame([], $run['errors']);
        $this->assertSame([], $run['degraded_by']);
        $this->assertSame(0, $run['dangling']['rows']);
        $this->assertSame(0, $run['orphans']['groups']);
        $this->assertSame(2, $run['estate']['rows_checked']);
    }

    // ================================================================
    // 6. The bound, and what it costs the verdict
    // ================================================================

    #[Test]
    public function the_row_budget_bounds_the_scan_and_refuses_to_call_the_rest_clean(): void
    {
        // The check costs one filesystem stat per media row, which is a syscall
        // on a local disk and a network round trip on a remote one. Past the
        // ceiling it stops — and it must never certify rows it did not look at.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 5) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        $channel = \Mockery::mock();
        $channel->shouldIgnoreMissing();

        Log::shouldReceive('channel')->andReturn($channel);

        // A ticket, not a page: a truncated budget must not ring at the volume
        // of an emptied table.
        $channel->shouldReceive('warning')->once();
        $channel->shouldReceive('info')->never();
        $channel->shouldReceive('error')->never();
        $channel->shouldReceive('critical')->never();

        [$exit, $run] = $this->verify(['--max-rows' => 2]);

        $this->assertSame(3, $exit, 'a run that examined 2 of 5 rows called the platform clean');
        $this->assertSame('partial', $run['status']);
        $this->assertSame(2, $run['estate']['rows_checked']);
        $this->assertSame(5, $run['estate']['media_rows']);
        $this->assertContains('truncated_row_budget', $run['degraded_by']);
    }

    #[Test]
    public function a_truncated_scan_never_reports_the_row_check_as_pass(): void
    {
        // The run was already `partial` at exit 3 — that was never in doubt. What
        // was wrong is the CHECK ROW: a scan that read 3 of 10 rows, all of them
        // healthy, emitted `pass` with wording byte-identical to ten verified
        // rows. A reader scanning check outcomes for the word `pass` is exactly
        // who that misleads, and this project has shipped that same shape — a
        // surface reporting success about something it did not look at — enough
        // times to warrant its own test.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 10) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        [$exit, $run] = $this->verify(['--max-rows' => 3]);

        $check = $this->check($run, 'media-rows-have-their-files');

        $this->assertNotSame('pass', $check['outcome'],
            'the row check called a scan that read 3 of 10 rows a pass');
        $this->assertSame('partial', $check['outcome']);
        $this->assertStringContainsString('never read', $check['detail']);
        $this->assertSame(3, $exit);
    }

    #[Test]
    public function a_complete_scan_of_a_healthy_estate_still_passes(): void
    {
        // The other half: the new branch must fire on truncation only. A run
        // that read everything and found everything present still says `pass`,
        // or the detector becomes permanently amber and gets switched off.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 10) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        [$exit, $run] = $this->verify();

        $this->assertSame('pass', $this->check($run, 'media-rows-have-their-files')['outcome']);
        $this->assertSame(0, $exit);
    }

    #[Test]
    public function a_finding_outranks_a_truncated_budget(): void
    {
        // Damage the run actually FOUND must page even when the run was also
        // cut short. `partial` is the quieter verdict and must never swallow a
        // louder one.
        $masjid = $this->makeMasjid(listed: false);

        $this->makeMedia($masjid, 'logos', withFile: false);

        foreach (range(1, 4) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        [$exit, $run] = $this->verify(['--max-rows' => 2]);

        $this->assertSame(1, $exit);
        $this->assertSame('broken', $run['status']);
        $this->assertContains('truncated_row_budget', $run['degraded_by'],
            'the truncation was silently dropped because something louder happened');
    }

    // ================================================================
    // 7. The two things it must never do
    // ================================================================

    #[Test]
    public function a_row_naming_a_disk_that_does_not_resolve_is_a_finding_not_a_degradation(): void
    {
        // A disk this application has no driver for is not a gap in THIS RUN's
        // evidence — it is a broken reference. No code path anywhere can reach
        // the file behind that row: not the app, not the API, not a restore.
        // Reported as `partial` it read as "I could not check some of it" about
        // rows that were already, permanently, unreachable.
        $masjid = $this->makeMasjid(listed: false);

        $this->makeMedia($masjid, 'logos', withFile: false, disk: 'a-disk-that-does-not-exist');
        $this->makeMedia($masjid, 'header_logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'a row pointing at a disk that does not exist was graded as a degraded run');
        $this->assertSame('broken', $run['status']);

        $finding = $this->finding($run, 'media_rows_name_an_unresolvable_disk');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('a-disk-that-does-not-exist', $finding['evidence']['disk']);
        $this->assertSame(1, $finding['evidence']['rows']);

        // Still never reported as a missing FILE: unknown stays unknown.
        $this->assertSame(0, $run['dangling']['rows'], 'a row on an unresolvable disk was called a missing file');

        // The distinction a reader can check: this is the unresolvable bucket,
        // and the transient one is empty.
        $this->assertArrayHasKey('a-disk-that-does-not-exist', $run['estate']['unresolvable_disks']);
        $this->assertSame([], $run['estate']['unreadable_disks']);
        $this->assertNotContains('unreadable_disk', $run['degraded_by']);

        $this->assertSame('FAIL', $this->check($run, 'media-rows-name-a-disk-that-resolves')['outcome']);
    }

    #[Test]
    public function a_disk_that_resolves_and_then_fails_a_read_is_a_degradation_not_a_finding(): void
    {
        // The other half of the same distinction, and the reason `partial` still
        // exists: a network blip on a disk that IS configured says nothing about
        // the estate. "400 files are gone" because a mount hiccuped for one run
        // is a false catastrophe, and a verifier that cries wolf gets turned off.
        $this->flakyDisk('flaky');

        $masjid = $this->makeMasjid(listed: false);

        $this->makeMedia($masjid, 'logos', withFile: false, disk: 'flaky');
        $this->makeMedia($masjid, 'header_logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(3, $exit, 'a transient read failure was graded as damage to the estate');
        $this->assertSame('partial', $run['status']);
        $this->assertSame(0, $run['dangling']['rows']);
        $this->assertContains('unreadable_disk', $run['degraded_by']);

        $this->assertArrayHasKey('flaky', $run['estate']['unreadable_disks']);
        $this->assertSame([], $run['estate']['unresolvable_disks']);
        $this->assertSame([], $run['findings'], 'a transient read failure produced a finding');
    }

    #[Test]
    public function a_check_never_says_pass_about_rows_it_did_not_read(): void
    {
        // The defect shape this project keeps hitting: a surface reporting
        // success about something it did not look at. Two rows were VISITED,
        // one was VERIFIED, and `pass` is a claim about the second number.
        $this->flakyDisk('flaky');

        $masjid = $this->makeMasjid(listed: false);

        $this->makeMedia($masjid, 'logos', withFile: false, disk: 'flaky');
        $this->makeMedia($masjid, 'header_logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(3, $exit);
        $this->assertSame(2, $run['estate']['rows_checked'], 'two rows were visited');
        $this->assertSame(1, $run['estate']['rows_verified'], 'only one row had its file state determined');

        $check = $this->check($run, 'media-rows-have-their-files');

        $this->assertSame('partial', $check['outcome'],
            'the check said `'.$check['outcome'].'` about a row whose disk it could not read');
        $this->assertStringContainsString('1 of 2', $check['detail']);
        $this->assertContains('unverified_rows', $run['degraded_by']);
    }

    #[Test]
    public function a_logo_on_a_disk_that_cannot_be_read_is_unverifiable_not_present(): void
    {
        // `fileExists()` answers true, false or NULL, and the audit used to test
        // only for `false`. A null therefore fell through and was counted as a
        // logo that resolves — the directory reported healthy on the strength of
        // a file nobody looked at.
        $this->flakyDisk('flaky');

        $healthy = $this->makeMasjid(listed: true);
        $this->makeMedia($healthy, 'logos', withFile: true);

        $unknown = $this->makeMasjid(listed: true);
        $this->makeMedia($unknown, 'logos', withFile: false, disk: 'flaky');

        [$exit, $run] = $this->verify();

        $this->assertSame(3, $exit, 'an unreadable logo was counted as a logo that resolves');
        $this->assertSame('partial', $run['status']);

        $this->assertSame(0, $run['expectations']['without_logo'],
            'a logo nobody could read was reported as MISSING, which it is not');
        $this->assertSame(1, $run['expectations']['unverifiable']);
        $this->assertSame('logo_unverifiable', $run['expectations']['unverifiable_organisations'][0]['reason']);
        $this->assertSame($unknown->id, $run['expectations']['unverifiable_organisations'][0]['masjid_id']);

        $check = $this->check($run, 'listed-organisations-have-a-logo');

        $this->assertSame('partial', $check['outcome'],
            'the directory check said `'.$check['outcome'].'` while one of its two logos was never read');

        $this->assertSame([], $run['findings']);
    }

    #[Test]
    public function it_repairs_nothing_and_deletes_nothing(): void
    {
        // The failure being guarded against is a cleanup nobody asked for. This
        // command's only power is to say what it sees.
        $listed = $this->makeMasjid(listed: true);
        $dangling = $this->makeMedia($listed, 'logos', withFile: false);
        $healthy = $this->makeMedia($listed, 'header_logos', withFile: true);

        Storage::disk('public')->put('9001/orphan.png', 'bytes');

        [$exit] = $this->verify();

        $this->assertSame(1, $exit);

        $this->assertDatabaseHas('media', ['id' => $dangling->id]);
        $this->assertDatabaseHas('media', ['id' => $healthy->id]);
        $this->assertSame(2, Media::query()->count(), 'the verifier deleted a media row');

        Storage::disk('public')->assertExists('9001/orphan.png');
        Storage::disk('public')->assertExists($healthy->getPathRelativeToRoot());

        // ...and it did not "helpfully" put a placeholder where the missing file
        // was either.
        Storage::disk('public')->assertMissing($dangling->getPathRelativeToRoot());
    }

    #[Test]
    public function the_human_report_reads_at_a_glance_and_disclaims_the_delete(): void
    {
        // `--json` is for the deploy gate and the alert rule; this is what an
        // operator actually sees, and the two things they must not have to dig
        // for are "25 logos are broken" and "I have not touched your files".
        $listed = $this->makeMasjid(listed: true);

        foreach (range(1, 3) as $i) {
            $this->makeMedia($listed, 'logos', withFile: false);
        }

        Storage::disk('public')->put('9001/seed-art.png', str_repeat('x', 4096));

        Artisan::call('media:verify');

        $output = Artisan::output();

        $this->assertStringContainsString('read-only', $output);
        $this->assertStringContainsString('never deletes', $output);

        // The grouped line, not three rows of media ids.
        $this->assertStringContainsString('Masjid', $output);
        $this->assertStringContainsString('logos', $output);

        // The orphan figure, above the check table, with the warning attached.
        $this->assertStringContainsString('unreferenced on disk', $output);
        $this->assertStringContainsString('4.0 KB', $output);
        $this->assertStringContainsString('media-library:clean', $output);

        $this->assertStringContainsString('BROKEN', $output);
        $this->assertStringContainsString('exit 1', $output);

        // VISITED and VERIFIED are printed as two numbers, because a reader who
        // sees one number reads it as the second.
        $this->assertStringContainsString('verified:', $output);

        // The memory is on the face of the report, not buried in the JSON: its
        // whole value is that somebody can diff last night against tonight.
        $this->assertStringContainsString('memory: baseline', $output);
    }

    #[Test]
    public function a_run_with_no_media_table_renders_and_says_it_is_not_evidence(): void
    {
        // The `incomplete` verdict has to survive every block added to the
        // report since: a run that cannot say anything true must still print
        // without throwing, or the one honest verdict becomes a stack trace.
        Schema::drop('media');

        [$exit, $run] = $this->verify();

        $this->assertSame(2, $exit);
        $this->assertSame('incomplete', $run['status']);
        $this->assertSame('none', $run['census']['baseline_source']);
        $this->assertSame('skipped', $run['public_link']['outcome']);

        Artisan::call('media:verify');

        $this->assertStringContainsString('INCOMPLETE', Artisan::output());
    }

    // ================================================================
    // 8. The memory — a DELETED row leaves no trace without one
    // ================================================================

    #[Test]
    public function a_run_with_no_baseline_records_one_and_does_not_pretend_it_compared(): void
    {
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 3) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame('none', $run['census']['baseline_source']);
        $this->assertSame('skipped', $this->check($run, 'media-collections-hold-their-size')['outcome'],
            'a run with nothing to compare against claimed a verdict about a drop');
        $this->assertSame([], $run['census']['drops']);

        // ...and it left a memory behind for the next one.
        $this->assertFileExists($this->scratch.'/baseline.json');

        [, $second] = $this->verify();

        $this->assertSame('local', $second['census']['baseline_source']);
        $this->assertSame(3, $this->collection($second, Masjid::class, 'galleries')['was']);
    }

    #[Test]
    public function a_collection_that_stops_existing_between_runs_is_a_finding(): void
    {
        // THE ONE THAT WAS MISSING. On 2026-08-17 the table held 226 rows; some
        // time after, every one was deleted. The empty-table check catches that
        // because the count reached EXACTLY ZERO — and would have missed the
        // identical event minus one surviving row, which is what this is.
        //
        // Twelve logo rows go; one header_logos row stays, so the table is not
        // empty, no organisation is listed, and every other check in the command
        // is green. Without a memory this run is `clean`.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 12) as $i) {
            $this->makeMedia($masjid, 'logos', withFile: true);
        }

        $survivor = $this->makeMedia($masjid, 'header_logos', withFile: true);

        [$exit] = $this->verify();

        $this->assertSame(0, $exit, 'the healthy estate was not clean, so this test proves nothing');

        // The incident: the rows are DELETED. Nothing dangles, because there is
        // nothing left to dangle.
        $this->deleteMedia(Media::query()->where('collection_name', 'logos')->pluck('id')->all());

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, '12 of 13 media rows were deleted and the run exited 0');
        $this->assertSame('broken', $run['status']);
        $this->assertSame(0, $run['dangling']['rows'], 'a deleted row cannot dangle — that is the whole problem');
        $this->assertSame(1, $run['estate']['media_rows']);

        $finding = $this->finding($run, 'media_collection_shrank');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('vanished', $finding['evidence']['kind']);
        $this->assertSame('logos', $finding['evidence']['collection_name']);
        $this->assertSame(Masjid::class, $finding['evidence']['model_type']);
        $this->assertSame(12, $finding['evidence']['was']);
        $this->assertSame(0, $finding['evidence']['now']);

        // The survivor is not reported as anything.
        $this->assertSame(1, $this->collection($run, Masjid::class, 'header_logos')['rows']);
        $this->assertDatabaseHas('media', ['id' => $survivor->id]);
    }

    #[Test]
    public function one_category_of_content_disappearing_is_a_finding_while_the_rest_is_untouched(): void
    {
        // The far likelier partial, and the one nothing covered: the logo audit
        // only covers logos because the running app demands them. Announcement
        // images, section images, service icons and avatars — 159 of the 226
        // rows that went — had no check at all.
        $masjid = $this->makeMasjid(listed: true);
        $this->makeMedia($masjid, 'logos', withFile: true);

        $announcement = $this->makeAnnouncement($masjid);

        foreach (range(1, 14) as $i) {
            $this->makeMedia($announcement, 'announcements', withFile: true);
        }

        [$exit] = $this->verify();

        $this->assertSame(0, $exit);

        $this->deleteMedia(Media::query()->where('collection_name', 'announcements')->pluck('id')->all());

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'every announcement image on the platform was deleted and the run exited 0');

        $finding = $this->finding($run, 'media_collection_shrank');

        $this->assertSame('announcements', $finding['evidence']['collection_name']);
        $this->assertSame(14, $finding['evidence']['was']);

        // The logo the directory reads is still there, and the run says so
        // rather than sweeping everything into one alarm.
        $this->assertSame(0, $run['expectations']['without_logo']);
        $this->assertSame(1, $this->collection($run, Masjid::class, 'logos')['rows']);
    }

    #[Test]
    public function ordinary_deletion_is_not_a_finding_and_the_baseline_follows_it_down(): void
    {
        // The amber-every-night guard. Content is legitimately deleted here every
        // day — an announcement comes down, a gallery item is removed — and a
        // rule that fires on that is a rule somebody silences, and then the
        // emergency is unheard. The baseline must also SLIP, or twenty ordinary
        // deletions would eventually add up to a spurious page.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 12) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        $this->verify();

        $this->deleteMedia(Media::query()->orderBy('id')->limit(4)->pluck('id')->all());

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit, 'an admin deleting four gallery items paged the on-call engineer');
        $this->assertSame([], $run['census']['drops']);
        $this->assertSame('pass', $this->check($run, 'media-collections-hold-their-size')['outcome']);

        $this->deleteMedia(Media::query()->orderBy('id')->limit(3)->pluck('id')->all());

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);

        // The baseline moved 12 -> 8 -> 5 rather than holding at 12 and
        // eventually declaring a catastrophe built out of ordinary days.
        $this->assertSame(8, $this->collection($run, Masjid::class, 'galleries')['was']);
        $this->assertSame(5, $this->collection($run, Masjid::class, 'galleries')['rows']);
    }

    #[Test]
    public function a_held_baseline_repeats_the_finding_until_it_is_fixed_or_accepted(): void
    {
        // THE RATCHET. If the baseline followed the loss down, the run after the
        // wipe would compare the wreckage against itself, find nothing, and go
        // quiet. One alert, once, for an event that in fact went unnoticed for
        // days and was finally reported by a photograph of a television. A
        // watchdog that barks once is a watchdog somebody slept through.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 12) as $i) {
            $this->makeMedia($masjid, 'logos', withFile: true);
        }

        $this->makeMedia($masjid, 'header_logos', withFile: true);

        $this->verify();

        $this->deleteMedia(Media::query()->where('collection_name', 'logos')->pluck('id')->all());

        [$first] = $this->verify();

        $this->assertSame(1, $first);

        // Nothing changes. The estate is still missing the same 12 rows, so the
        // page is still owed.
        [$second, $run] = $this->verify();

        $this->assertSame(1, $second, 'the alert went quiet on the second run because the memory absorbed the loss');
        $this->assertSame(12, $this->finding($run, 'media_collection_shrank')['evidence']['was']);
        $this->assertNotNull($this->collection($run, Masjid::class, 'logos')['held_since']);

        // The operator says "yes, we meant that". ONE gesture, and it is the
        // only thing that clears a held drop.
        [$accepted, $acceptRun] = $this->verify(['--accept-baseline' => true]);

        $this->assertSame(0, $accepted);
        $this->assertSame('accepted', $acceptRun['census']['baseline_source']);

        // Acceptance keeps the series and records it at zero, rather than
        // forgetting the collection ever existed: the memory should be able to
        // say it was TOLD the logos are gone.
        $this->assertSame(0, $this->collection($acceptRun, Masjid::class, 'logos')['rows']);

        [$after, $afterRun] = $this->verify();

        $this->assertSame(0, $after, 'an accepted baseline was not actually recorded');
        $this->assertSame(0, $this->collection($afterRun, Masjid::class, 'logos')['was']);
        $this->assertSame([], $afterRun['census']['drops']);
    }

    #[Test]
    public function the_estate_recovering_clears_a_held_drop_without_anyone_accepting_it(): void
    {
        // The hold is not a latch that only a human can open. Rows restored from
        // a backup put the count back, and the check must go green on its own —
        // otherwise the repair leaves a permanent red and the red stops meaning
        // anything.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 12) as $i) {
            $this->makeMedia($masjid, 'logos', withFile: true);
        }

        $this->makeMedia($masjid, 'header_logos', withFile: true);
        $this->verify();

        $this->deleteMedia(Media::query()->where('collection_name', 'logos')->pluck('id')->all());

        [$broken] = $this->verify();

        $this->assertSame(1, $broken);

        foreach (range(1, 12) as $i) {
            $this->makeMedia($masjid, 'logos', withFile: true);
        }

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit, 'a restored collection stayed red for ever');
        $this->assertSame([], $run['census']['drops']);
        $this->assertNull($this->collection($run, Masjid::class, 'logos')['held_since']);
    }

    #[Test]
    public function a_drain_too_slow_to_trip_a_single_run_still_trips_the_high_water_mark(): void
    {
        // Because a drop within the threshold LOWERS the baseline, nine rows per
        // run would empty a collection without the per-run rule ever firing. So
        // each series also carries a high-water mark, and losing three quarters
        // of it inside the window is a finding however gradually it happened.
        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 40) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        $this->verify();

        foreach ([9, 9, 9] as $step) {
            $this->deleteMedia(Media::query()->orderBy('id')->limit($step)->pluck('id')->all());

            [$exit] = $this->verify();

            $this->assertSame(0, $exit, 'a drop of '.$step.' rows tripped the per-run threshold, which is 10');
        }

        // 40 -> 13 so far, each step under the bar. One more and three quarters
        // of the high-water mark is gone.
        $this->deleteMedia(Media::query()->orderBy('id')->limit(9)->pluck('id')->all());

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'a collection drained from 40 rows to 4, nine at a time, and nothing fired');

        $finding = $this->finding($run, 'media_collection_shrank');

        $this->assertSame('drained', $finding['evidence']['kind']);
        $this->assertSame(40, $finding['evidence']['peak']);
        $this->assertSame(4, $finding['evidence']['now']);
    }

    #[Test]
    public function a_wiped_table_is_still_reported_as_one_event_and_not_as_six(): void
    {
        // Precedence, with a memory in play: when the table is empty EVERY
        // collection has vanished, and listing them all would describe the
        // symptoms while burying the event. The census is still reported — an
        // operator restoring from backup needs to know what was in it — it just
        // decides nothing here.
        $listed = $this->makeMasjid(listed: true);

        $this->makeMedia($listed, 'logos', withFile: true);

        $announcement = $this->makeAnnouncement($listed);

        foreach (range(1, 10) as $i) {
            $this->makeMedia($announcement, 'announcements', withFile: true);
        }

        [$exit] = $this->verify();

        $this->assertSame(0, $exit);

        $this->deleteMedia(Media::query()->pluck('id')->all());

        [$exit, $run] = $this->verify();

        $this->assertSame(4, $exit, 'a wiped table stopped being the loudest verdict once the census could speak');
        $this->assertSame('empty', $run['status']);
        $this->assertCount(1, $run['findings'], 'the wiped estate was reported as one event plus its symptoms');
        $this->assertSame('media_table_empty', $run['findings'][0]['kind']);

        // Reported, and deciding nothing.
        $this->assertNotSame([], $run['census']['drops']);
    }

    #[Test]
    public function with_no_local_baseline_the_newest_backup_manifest_seeds_the_platform_total(): void
    {
        // The memory is a file, and a file can be missing: the first run after
        // this ships, or the run after somebody wiped storage/. `backup:run`
        // already writes a number that predates both. Depended on as a CONTRACT
        // — `media_integrity.rows` in a set's manifest.json — and it can seed
        // only the platform TOTAL, because the manifest records one number and
        // not a census.
        $this->writeBackupManifest('20260817-021500', rows: 226, createdAt: '2026-08-17T02:15:00+00:00');

        $masjid = $this->makeMasjid(listed: false);

        foreach (range(1, 3) as $i) {
            $this->makeMedia($masjid, 'galleries', withFile: true);
        }

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'the estate fell from 226 rows to 3 and the first run called it clean');
        $this->assertSame('backup_manifest', $run['census']['baseline_source']);

        $finding = $this->finding($run, 'media_estate_shrank');

        $this->assertSame(226, $finding['evidence']['was']);
        $this->assertSame(3, $finding['evidence']['now']);
        $this->assertSame('20260817-021500', $finding['evidence']['set']);
    }

    #[Test]
    public function a_missing_or_unreadable_backup_destination_costs_the_run_nothing(): void
    {
        // A host with no backups installed is the ordinary state, not an error,
        // and a half-written or differently-shaped manifest must not become an
        // exception in the middle of a verifier.
        $this->writeBackupManifest('20260818-021500', rows: 226, createdAt: '2026-08-18T02:15:00+00:00',
            complete: false);

        $masjid = $this->makeMasjid(listed: false);
        $this->makeMedia($masjid, 'galleries', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame('none', $run['census']['baseline_source'],
            'an unfinished backup set was read as a baseline');
    }

    // ================================================================
    // 9. The link every media URL is served through
    // ================================================================

    #[Test]
    public function a_broken_public_storage_link_is_a_finding_even_when_every_row_and_file_is_intact(): void
    {
        // `storage/app/public` is reachable over HTTP only because
        // `public/storage` points at it. Break the link and every URL this
        // command validates 404s while every row and every file it examined is
        // perfectly intact — a `clean` report about a platform showing no
        // images, which is the precise failure shape this command exists to
        // refuse.
        $root = realpath(Storage::disk('public')->path(''));

        config(['media-verify.public_link.target' => $root]);
        config(['media-verify.public_link.link' => $this->scratch.'/public/storage']);

        $masjid = $this->makeMasjid(listed: true);
        $this->makeMedia($masjid, 'logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit, 'every image on the platform 404s and the command reported clean');
        $this->assertSame('FAIL', $run['public_link']['outcome']);
        $this->assertSame('absent', $run['public_link']['reason']);
        $this->assertSame('public', $run['public_link']['served_disk']);

        $finding = $this->finding($run, 'public_storage_link_broken');

        $this->assertSame('critical', $finding['severity']);
        $this->assertStringContainsString('storage:link', $finding['evidence']['note']);

        // Nothing in the estate is damaged, and the report says so rather than
        // sending anybody to a backup.
        $this->assertSame(0, $run['dangling']['rows']);
        $this->assertSame(0, $run['expectations']['without_logo']);

        // Repair it, and the same estate is clean.
        mkdir($this->scratch.'/public', 0775, true);
        symlink($root, $this->scratch.'/public/storage');

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $run['public_link']['outcome']);
    }

    #[Test]
    public function a_link_pointing_somewhere_other_than_the_disk_is_a_finding(): void
    {
        $root = realpath(Storage::disk('public')->path(''));

        mkdir($this->scratch.'/elsewhere', 0775, true);
        mkdir($this->scratch.'/public', 0775, true);
        symlink($this->scratch.'/elsewhere', $this->scratch.'/public/storage');

        config(['media-verify.public_link.target' => $root]);
        config(['media-verify.public_link.link' => $this->scratch.'/public/storage']);

        $masjid = $this->makeMasjid(listed: false);
        $this->makeMedia($masjid, 'logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(1, $exit);
        $this->assertSame('wrong_target', $run['public_link']['reason']);
    }

    #[Test]
    public function the_link_is_skipped_where_it_serves_nothing_rather_than_guessed_at(): void
    {
        // On a host whose media lives on S3 there is no `public/storage` in the
        // path, and inventing a verdict about it would be the same overclaim
        // this command refuses everywhere else.
        config(['media-verify.public_link.target' => $this->scratch.'/not-a-media-disk']);
        config(['media-verify.public_link.link' => $this->scratch.'/public/storage']);

        mkdir($this->scratch.'/not-a-media-disk', 0775, true);

        $masjid = $this->makeMasjid(listed: false);
        $this->makeMedia($masjid, 'logos', withFile: true);

        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame('skipped', $run['public_link']['outcome']);
        $this->assertNull($run['public_link']['served_disk']);
    }

    // ================================================================
    // 10. The relation the directory reads
    // ================================================================

    #[Test]
    public function the_logo_relation_does_not_match_another_models_media_row(): void
    {
        // `media` is POLYMORPHIC: `model_id` alone is not a foreign key to
        // anything, it is one half of one. Without `model_type` the relation
        // matched any `logos` row whose `model_id` happened to equal this
        // masjid's id, and the organisation picker every mobile app opens with
        // is `Masjid::listed()->with('logo')`.
        $masjid = $this->makeMasjid(listed: true);

        $foreign = new Media;
        $foreign->model_type = Announcement::class;
        $foreign->model_id = $masjid->id;
        $foreign->uuid = (string) Str::uuid();
        $foreign->collection_name = 'logos';
        $foreign->name = 'not-a-masjid-logo';
        $foreign->file_name = 'not-a-masjid-logo.png';
        $foreign->mime_type = 'image/png';
        $foreign->disk = 'public';
        $foreign->size = 12;
        $foreign->manipulations = [];
        $foreign->custom_properties = [];
        $foreign->generated_conversions = [];
        $foreign->responsive_images = [];
        $foreign->order_column = 1;
        $foreign->save();

        // With its bytes on disk, so nothing else in the command has anything to
        // say about it and this test is only about the relation.
        Storage::disk('public')->put($foreign->getPathRelativeToRoot(), 'imagebytes');

        $this->assertNull($masjid->fresh()->logo,
            'the directory rendered another model\'s logo because model_id collided');

        $own = $this->makeMedia($masjid, 'logos', withFile: true);

        $this->assertSame($own->id, $masjid->fresh()->logo->id);

        // And `media:verify` reads the same pair, so the check and the thing it
        // checks cannot disagree.
        [$exit, $run] = $this->verify();

        $this->assertSame(0, $exit);
        $this->assertSame(0, $run['expectations']['without_logo']);
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function verify(array $options = []): array
    {
        $exit = Artisan::call('media:verify', array_merge(['--json' => true], $options));

        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded, 'media:verify did not emit parseable JSON: '.Artisan::output());

        return [$exit, $decoded];
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function check(array $run, string $name): array
    {
        foreach ($run['checks'] as $check) {
            if ($check['check'] === $name) {
                return $check;
            }
        }

        $this->fail("no `{$name}` check in: ".json_encode(array_column($run['checks'], 'check')));
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function collection(array $run, string $modelType, string $collection): array
    {
        foreach ($run['census']['collections'] as $row) {
            if ($row['model_type'] === $modelType && $row['collection_name'] === $collection) {
                return $row;
            }
        }

        $this->fail("no `{$modelType}::{$collection}` in the census: ".json_encode($run['census']['collections']));
    }

    /**
     * Rows removed the way the incident removed them: gone from the table with
     * no model event, so nothing tidied up after them and nothing recorded that
     * they had ever been there.
     *
     * @param  array<int,int>  $ids
     */
    private function deleteMedia(array $ids): void
    {
        Media::query()->whereIn('id', $ids)->delete();
    }

    /**
     * A disk that RESOLVES and then throws on every read — a mount that
     * hiccuped, not a disk that does not exist. The distinction is the whole of
     * F3 and it cannot be tested with a name that has no driver.
     */
    private function flakyDisk(string $name): void
    {
        $filesystem = \Mockery::mock(FilesystemContract::class);

        $filesystem->shouldReceive('exists')->andThrow(new \RuntimeException('connection reset by peer'));
        $filesystem->shouldReceive('directories')->andThrow(new \RuntimeException('connection reset by peer'));
        $filesystem->shouldReceive('files')->andReturn([]);

        Storage::set($name, $filesystem);
    }

    /**
     * A backup manifest written by hand, to the published contract and not
     * through `backup:run`: this test pins what `media:verify` READS, and it
     * must keep failing if that shape changes even when the writer still agrees
     * with itself.
     */
    private function writeBackupManifest(string $set, int $rows, string $createdAt, bool $complete = true): void
    {
        $directory = config('backup.destination').'/'.$set;

        mkdir($directory, 0775, true);

        file_put_contents($directory.'/manifest.json', json_encode([
            'manifest_version' => 1,
            'set' => $set,
            'created_at' => $createdAt,
            'complete' => $complete,
            'media_integrity' => [
                'status' => 'checked',
                'rows' => $rows,
                'rows_with_file' => $rows,
                'rows_without_file' => 0,
            ],
        ]));
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function finding(array $run, string $kind): array
    {
        foreach ($run['findings'] as $finding) {
            if ($finding['kind'] === $kind) {
                return $finding;
            }
        }

        $this->fail("no `{$kind}` finding in: ".json_encode(array_column($run['findings'], 'kind')));
    }

    private function makeMasjid(bool $listed = false): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        if ($listed) {
            // `listed_at` is deliberately not fillable — publishing is one
            // SuperAdmin endpoint, never a mass-assignment side effect. See
            // .claude/rules/directory-listing.md.
            $masjid->forceFill(['listed_at' => now()])->save();
        }

        return $masjid;
    }

    private function makeAnnouncement(Masjid $masjid): Announcement
    {
        return Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => 'Notice',
            'summary' => 'Notice',
            'details' => 'Notice',
            'text' => 'Notice',
            'start_date' => now()->subDay()->format('Y-m-d'),
            'end_date' => now()->addDay()->format('Y-m-d'),
        ]);
    }

    /**
     * A media row written directly, so a test can put the row and the bytes into
     * exactly the state production was in — including the state the library's
     * own API cannot produce, which is a row with no file behind it.
     */
    private function makeMedia(object $owner, string $collection, bool $withFile, string $disk = 'public'): Media
    {
        $media = new Media;

        $media->model_type = $owner::class;
        $media->model_id = $owner->id;
        $media->uuid = (string) Str::uuid();
        $media->collection_name = $collection;
        $media->name = 'image';
        $media->file_name = 'image-'.Str::random(6).'.png';
        $media->mime_type = 'image/png';
        $media->disk = $disk;
        $media->size = 12;
        $media->manipulations = [];
        $media->custom_properties = [];
        $media->generated_conversions = [];
        $media->responsive_images = [];
        $media->order_column = 1;
        $media->save();

        if ($withFile) {
            Storage::disk($disk)->put($media->getPathRelativeToRoot(), 'imagebytes');
        }

        return $media;
    }
}
