<?php

namespace Tests\Feature;

use App\Models\ClassAssignment;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `curriculum:import`: the one path that changes a school's guide.
 *
 * It had no test. Extending it to replace cells (the school's separated Qur'an,
 * Arabic and Islamic Studies weeks supersede the combined column for Pre-K to
 * Grade 2, weeks 1-8) means production's guide is changed by data, so what is
 * pinned here is what the owner approves and what he can undo: the dry run's
 * exact counts, the expected-counts guard, the masjid guard, the transaction, the
 * inverse file and snapshot written first, the byte-for-byte verify, and that no
 * teacher's plan or piece of work is ever written.
 */
class ImportCurriculumWeeksCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMBINED = "Qur\u{2019}an & Islamic Studies";

    private Masjid $school;

    private User $teacher;

    private Group $class;

    private string $storage;

    private string $base;

    private string $split;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // Every file the command writes goes to a scratch storage path, never the checkout's.
        $this->storage = sys_get_temp_dir() . '/curriculum-import-test-' . uniqid();
        mkdir($this->storage, 0777, true);
        $this->app->useStoragePath($this->storage);

        $this->base = base_path('database/curriculum/al-razi-pacing-2026-27.json');
        $this->split = base_path('database/curriculum/al-razi-qai-split-2026-27-q1.json');

        // The split file is made for masjid 14, so the school is masjid 14.
        $this->school = $this->makeSchool('Al-Razi Test ' . uniqid(), 14);

        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Pre-K', 'slug' => 'prek']);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->storage);

        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rmrf("{$path}/{$entry}");
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    private function makeSchool(string $name, ?int $id = null): Masjid
    {
        $m = new Masjid;
        $m->forceFill([
            'name' => $name, 'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ] + ($id ? ['id' => $id] : []))->save();

        return $m;
    }

    /** @return array{0: int, 1: string} */
    private function runImport(string $file, array $options = [], ?int $masjid = null): array
    {
        $exit = Artisan::call('curriculum:import', ['masjid' => $masjid ?? $this->school->id, 'file' => $file] + $options);

        return [$exit, Artisan::output()];
    }

    /** @return array<string, mixed> */
    private function plan(string $output): array
    {
        $this->assertSame(1, preg_match('/^PLAN (.+)$/m', $output, $m), "no PLAN line in:\n{$output}");

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function rowCount(?int $masjid = null): int
    {
        return DB::table('curriculum_weeks')->where('masjid_id', $masjid ?? $this->school->id)->count();
    }

    /** A hash of every column of every row of one tenant, ids and timestamps included. */
    private function fingerprint(?int $masjid = null, ?callable $where = null): string
    {
        $q = DB::table('curriculum_weeks')->where('masjid_id', $masjid ?? $this->school->id)->orderBy('id');

        if ($where) {
            $where($q);
        }

        return md5(json_encode($q->get()->all()));
    }

    private function importBase(): void
    {
        [$exit] = $this->runImport($this->base);
        $this->assertSame(0, $exit);
    }

    private const APPLY_EXPECT = 'before=1512,delete=32,insert=96,update=0,unchanged=0,after=1576,plans_touching=0,assignments_touching=0';

    private function applySplit(): array
    {
        [$exit, $out] = $this->runImport($this->split, ['--expect' => self::APPLY_EXPECT]);
        $this->assertSame(0, $exit, $out);

        return [$exit, $out];
    }

    /** A copy of the split file with `$edit` applied to its decoded payload. */
    private function splitVariant(callable $edit): string
    {
        $payload = json_decode((string) file_get_contents($this->split), true, flags: JSON_THROW_ON_ERROR);
        $payload = $edit($payload) ?? $payload;
        $path = $this->storage . '/variant-' . uniqid() . '.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** Every file the command has written so far, sorted; empty when it has written none. */
    private function storedFiles(): array
    {
        $found = glob($this->storage . '/app/private/curriculum-imports/*') ?: [];
        sort($found);

        return $found;
    }

    private function inverseFile(): string
    {
        $found = glob($this->storage . '/app/private/curriculum-imports/*inverse-of-*');
        $this->assertNotEmpty($found, 'no inverse file was written');
        sort($found);

        return end($found);
    }

    // ---------------------------------------------------------- the base file, as it always behaved

    #[Test]
    public function the_base_file_still_imports_1512_cells_and_re_imports_with_nothing_changed(): void
    {
        [$exit, $out] = $this->runImport($this->base);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Imported 1512 cells', $out);
        $this->assertSame(1512, $this->rowCount());
        $first = $this->fingerprint();

        [$exit, $out] = $this->runImport($this->base);
        $this->assertSame(0, $exit);
        $this->assertSame(1512, $this->rowCount());
        $plan = $this->plan($out);
        $this->assertSame([0, 0, 0, 1512, 1512], [$plan['delete'], $plan['insert'], $plan['update'], $plan['unchanged'], $plan['after']]);
        $this->assertSame($first, $this->fingerprint(), 'nothing changed on a second import');

        [$exit] = $this->runImport($this->base, ['--fresh' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(1512, $this->rowCount());
    }

    #[Test]
    public function a_row_missing_its_grade_is_skipped_as_it_always_was(): void
    {
        $path = $this->storage . '/skip.json';
        file_put_contents($path, json_encode(['source_label' => 'T', 'rows' => [
            ['subject' => 'Science', 'week_no' => 1, 'focus' => 'x'],
            ['grade_label' => 'Grade 1', 'subject' => 'Science', 'week_no' => 1, 'focus' => 'y'],
        ]]));

        [$exit, $out] = $this->runImport($path);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('(1 skipped)', $out);
        $this->assertSame(1, $this->rowCount());
    }

    #[Test]
    public function fresh_deletes_inside_the_transaction_so_a_failed_import_loses_nothing(): void
    {
        $this->importBase();
        $before = $this->fingerprint();

        $saves = 0;
        $armed = true;
        CurriculumWeek::saving(function () use (&$saves, &$armed) {
            if ($armed && ++$saves === 50) {
                throw new RuntimeException('disk full');
            }
        });

        try {
            $this->runImport($this->base, ['--fresh' => true]);
            $this->fail('the import should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        } finally {
            $armed = false;
        }

        $this->assertSame(1512, $this->rowCount(), 'the --fresh delete was rolled back with the failed import');
        $this->assertSame($before, $this->fingerprint());
    }

    // ---------------------------------------------------------- the split: dry run

    #[Test]
    public function the_split_dry_run_prints_the_exact_counts_and_writes_nothing(): void
    {
        $this->importBase();
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();
        $this->assertCount(2, $filesBefore, 'the base apply wrote its own inverse file and snapshot');

        [$exit, $out] = $this->runImport($this->split, ['--dry-run' => true]);
        $plan = $this->plan($out);

        $this->assertSame(0, $exit);
        $this->assertSame(14, $plan['masjid_id']);
        $this->assertSame([
            'before' => 1512, 'delete' => 32, 'delete_absent' => 0, 'insert' => 96, 'update' => 0,
            'unchanged' => 0, 'after' => 1576, 'plans_touching' => 0, 'assignments_touching' => 0,
        ], array_intersect_key($plan, array_flip([
            'before', 'delete', 'delete_absent', 'insert', 'update', 'unchanged', 'after', 'plans_touching', 'assignments_touching',
        ])));
        $this->assertTrue($plan['dry_run']);
        $this->assertSame(hash_file('sha256', $this->split), $plan['file_sha256']);
        // The data file is in a public repository: the printed provenance names the source by
        // its hashes and a date, never a private copy's id or an internal note.
        $this->assertSame('2026-09-07', $plan['source']['document_date']);
        $this->assertSame('98332b22dd36298ed5d575f22e44745069fd94ddada25929fcfa1f6fe34119e1', $plan['source']['docx_sha256']);
        $this->assertSame(
            ['title', 'document_date', 'docx_sha256', 'txt_path', 'txt_sha256', 'generator'],
            array_keys($plan['source']),
            'the printed provenance carries exactly the public fields'
        );
        $this->assertArrayNotHasKey('tables', $plan['source']);
        $this->assertSame(220, $plan['by_subject_after'][self::COMBINED]);
        $this->assertSame(32, $plan['by_subject_after']["Qur'an"]);
        $this->assertStringContainsString('applies after al-razi-pacing-2026-27.json', $out);

        $this->assertSame($before, $this->fingerprint(), 'a dry run changes no row');
        $this->assertSame($filesBefore, $this->storedFiles(), 'and writes no file');
    }

    // ---------------------------------------------------------- the split: apply

    #[Test]
    public function the_split_applies_with_the_matching_expect_and_every_cell_equals_the_file(): void
    {
        $this->importBase();
        $july = ['English Language Arts', 'Healthful Living', 'Mathematics', 'Science', 'Social Studies'];
        $untouchedWhere = fn ($q) => $q->whereIn('subject', $july);
        // The 1260 rows the split must not touch, ids and timestamps included.
        $before = $this->fingerprint(null, $untouchedWhere);
        $this->assertSame(1260, DB::table('curriculum_weeks')->whereIn('subject', $july)->count());

        Log::spy();
        $filesBefore = $this->storedFiles();
        [, $out] = $this->applySplit();

        $this->assertSame(1576, $this->rowCount());
        $this->assertSame($before, $this->fingerprint(null, $untouchedWhere), 'the 1260 other rows are byte-identical');

        $bySubject = array_map('intval', DB::table('curriculum_weeks')->groupBy('subject')->selectRaw('subject, count(*) n')->pluck('n', 'subject')->all());
        $expected = [
            'English Language Arts' => 252, 'Healthful Living' => 252, 'Mathematics' => 252, 'Science' => 252, 'Social Studies' => 252,
            self::COMBINED => 220, "Qur'an" => 32, 'Islamic Studies' => 32, 'Arabic Language' => 32,
        ];
        ksort($bySubject);
        ksort($expected);
        $this->assertSame($expected, $bySubject);

        // Pre-K to Grade 2 keep the combined column for weeks 9-36 only; Grades 3-5 keep all 36.
        foreach (['Pre-Kindergarten', 'Kindergarten', 'Grade 1', 'Grade 2'] as $g) {
            $weeks = DB::table('curriculum_weeks')->where('grade_label', $g)->where('subject', self::COMBINED)->orderBy('week_no')->pluck('week_no')->all();
            $this->assertSame(range(9, 36), $weeks, $g);
        }
        foreach (['Grade 3', 'Grade 4', 'Grade 5'] as $g) {
            $weeks = DB::table('curriculum_weeks')->where('grade_label', $g)->where('subject', self::COMBINED)->orderBy('week_no')->pluck('week_no')->all();
            $this->assertSame(range(1, 36), $weeks, $g);
        }

        // Every row of the file, read back from the database, is the file's, byte for byte.
        $file = json_decode((string) file_get_contents($this->split), true);
        foreach ($file['rows'] as $r) {
            $row = DB::table('curriculum_weeks')->where('masjid_id', 14)->where('grade_label', $r['grade_label'])
                ->where('subject', $r['subject'])->where('week_no', $r['week_no'])->first();
            $this->assertNotNull($row, "{$r['grade_label']} {$r['subject']} {$r['week_no']}");

            foreach (['quarter', 'focus', 'objective', 'learning_outcome', 'standard_code', 'assessment_note'] as $c) {
                $this->assertSame($r[$c], $row->{$c}, "{$r['grade_label']} {$r['subject']} {$r['week_no']} {$c}");
            }
            $this->assertSame($file['source_label'], $row->source_label);
        }

        // The safety files: written, 0600, decodable.
        $dir = $this->storage . '/app/private/curriculum-imports';
        $files = array_values(array_diff($this->storedFiles(), $filesBefore));
        $this->assertCount(2, $files, 'the split apply added exactly an inverse file and a snapshot');
        $this->assertSame(0700, fileperms($dir) & 0777);
        foreach ($files as $f) {
            $this->assertSame(0600, fileperms($f) & 0777, $f);
            $this->assertIsArray(json_decode((string) file_get_contents($f), true), $f);
        }
        $inverse = json_decode((string) file_get_contents($this->inverseFile()), true);
        $this->assertCount(96, $inverse['replaces']);
        $this->assertCount(32, $inverse['rows']);
        $this->assertSame(hash_file('sha256', $this->split), $inverse['inverse_of']['sha256']);
        $snapshot = json_decode((string) file_get_contents(current(array_filter($files, fn ($f) => str_contains($f, 'snapshot')))), true);
        $this->assertCount(1512, $snapshot['rows']);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $ctx): bool {
            return $message === 'Curriculum import applied'
                && $ctx['masjid_id'] === 14
                && $ctx['file_sha256'] === hash_file('sha256', base_path('database/curriculum/al-razi-qai-split-2026-27-q1.json'))
                && $ctx['source']['txt_sha256'] === '40cc44ac38a4b8b2be01fd07ef7fdf081682f0ff17ca78968992024a82ea8a0f'
                && $ctx['counts']['insert'] === 96;
        });
        $this->assertStringContainsString('Imported 96 cells', $out);
    }

    #[Test]
    public function applying_it_twice_changes_nothing_the_second_time(): void
    {
        $this->importBase();
        $this->applySplit();
        $after = $this->fingerprint();

        // The cells it replaces are already gone (delete_absent 32), which is a refusal
        // unless the operator says it is expected.
        [$exit, $out] = $this->runImport($this->split);
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('32 cells the file replaces are not in the guide', $out);
        $this->assertSame($after, $this->fingerprint());

        [$exit, $out] = $this->runImport($this->split, ['--allow-references' => true]);
        $plan = $this->plan($out);

        $this->assertSame(0, $exit);
        $this->assertSame([0, 0, 0, 96, 1576, 32], [
            $plan['delete'], $plan['insert'], $plan['update'], $plan['unchanged'], $plan['after'], $plan['delete_absent'],
        ]);
        $this->assertSame($after, $this->fingerprint());
    }

    // ---------------------------------------------------------- verify

    #[Test]
    public function verify_passes_after_the_apply_and_names_one_altered_cell(): void
    {
        $this->importBase();
        $this->applySplit();

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('mismatches: 0', $out);
        $this->assertStringContainsString('1576 cells', $out);

        DB::table('curriculum_weeks')->where('grade_label', 'Kindergarten')->where('subject', "Qur'an")->where('week_no', 4)
            ->update(['objective' => 'Memorize Surah Al-Ikhlas']);

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mismatches: 1', $out);
        $this->assertStringContainsString('Kindergarten / Qur\'an / week 4: objective differs', $out);
    }

    #[Test]
    public function verify_before_the_apply_names_the_replaced_cells_still_present_and_writes_nothing(): void
    {
        $this->importBase();
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mismatches: 128', $out, '96 missing cells and 32 replaced cells still present');
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($filesBefore, $this->storedFiles(), 'verify writes no file');
    }

    #[Test]
    public function verify_cannot_be_combined_with_anything_that_writes(): void
    {
        foreach (['--dry-run' => true, '--fresh' => true, '--allow-references' => true, '--expect' => 'delete=0'] as $option => $value) {
            [$exit, $out] = $this->runImport($this->base, ['--verify' => true, $option => $value]);
            $this->assertSame(1, $exit, $option);
            $this->assertStringContainsString('only reads', $out);
        }
    }

    // ---------------------------------------------------------- rollback

    #[Test]
    public function the_inverse_file_puts_back_exactly_what_was_there_and_the_split_can_be_re_applied(): void
    {
        $this->importBase();
        $this->applySplit();
        $inverse = $this->inverseFile();

        [$exit, $out] = $this->runImport($inverse, ['--dry-run' => true]);
        $plan = $this->plan($out);
        $this->assertSame(0, $exit);
        $this->assertSame([96, 32, 0, 0, 1512], [$plan['delete'], $plan['insert'], $plan['update'], $plan['unchanged'], $plan['after']]);

        [$exit, $out] = $this->runImport($inverse, ['--expect' => 'delete=96,insert=32,after=1512']);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1512, $this->rowCount());

        [$exit, $out] = $this->runImport($this->base, ['--verify' => true]);
        $this->assertSame(0, $exit, "the base file is the database again:\n{$out}");

        $this->applySplit();
        $this->assertSame(1576, $this->rowCount());
    }

    #[Test]
    public function re_running_the_base_file_after_the_split_brings_the_combined_cells_back_and_the_split_fixes_it(): void
    {
        $this->importBase();
        $this->applySplit();

        [$exit, $out] = $this->runImport($this->base);
        $this->assertSame(0, $exit);
        $this->assertSame(32, $this->plan($out)['insert']);
        $this->assertSame(1608, $this->rowCount());

        [$exit] = $this->runImport($this->split);
        $this->assertSame(0, $exit);
        $this->assertSame(1576, $this->rowCount());
    }

    // ---------------------------------------------------------- guards: each refuses and writes nothing

    #[Test]
    public function every_guard_refuses_and_leaves_the_guide_exactly_as_it_was(): void
    {
        $this->importBase();
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();

        $oversize = $this->splitVariant(function (array $p) {
            $p['rows'][0]['objective'] = str_repeat('x', 501);

            return $p;
        });
        $overlap = $this->splitVariant(function (array $p) {
            $p['replaces'][] = ['grade_label' => $p['rows'][0]['grade_label'], 'subject' => $p['rows'][0]['subject'], 'week_no' => $p['rows'][0]['week_no']];

            return $p;
        });
        $duplicate = $this->splitVariant(function (array $p) {
            $p['rows'][] = $p['rows'][5];

            return $p;
        });
        $wrongId = $this->splitVariant(function (array $p) {
            $p['for_masjid']['id'] = 99;

            return $p;
        });
        $wrongType = $this->splitVariant(function (array $p) {
            $p['for_masjid']['org_type'] = 'masjid';

            return $p;
        });
        $noFocus = $this->splitVariant(function (array $p) {
            $p['rows'][0]['focus'] = '';

            return $p;
        });
        $badWeek = $this->splitVariant(function (array $p) {
            $p['rows'][0]['week_no'] = 300;

            return $p;
        });

        $cases = [
            'an --expect that is off by one' => [$this->split, ['--expect' => 'delete=31'], 'delete=31, but the plan says 32'],
            'an --expect key that does not exist' => [$this->split, ['--expect' => 'deletes=32'], 'not key=number'],
            '--fresh with a file that replaces' => [$this->split, ['--fresh' => true], '--fresh cannot be combined'],
            'a 501-character objective' => [$oversize, [], 'objective is 501 characters; the limit is 500'],
            'a row that is also replaced' => [$overlap, [], 'is also in `rows`'],
            'a duplicate cell' => [$duplicate, [], 'appears twice'],
            'a file for another masjid id' => [$wrongId, [], 'This file is for masjid 99, not 14'],
            'a file for another kind of organisation' => [$wrongType, [], 'this file is for a masjid'],
            'an empty focus' => [$noFocus, [], 'focus is empty'],
            'a week outside the column' => [$badWeek, [], 'outside 1-255'],
        ];

        foreach ($cases as $name => [$file, $options, $message]) {
            [$exit, $out] = $this->runImport($file, $options);

            $this->assertSame(1, $exit, $name);
            $this->assertStringContainsString($message, $out, $name);
            $this->assertSame($before, $this->fingerprint(), "{$name}: the guide is unchanged");
            $this->assertSame($filesBefore, $this->storedFiles(), "{$name}: no file written");
        }
    }

    #[Test]
    public function a_masjid_whose_name_lacks_the_files_word_is_refused(): void
    {
        $this->importBase();
        $before = $this->fingerprint();
        $this->school->forceFill(['name' => 'Some Other School'])->save();

        [$exit, $out] = $this->runImport($this->split);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('name contains "razi"', $out);
        $this->assertSame($before, $this->fingerprint());
    }

    #[Test]
    public function an_unknown_masjid_and_a_missing_file_are_refused(): void
    {
        [$exit, $out] = $this->runImport($this->split, [], 9999);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No masjid with id 9999', $out);

        [$exit, $out] = $this->runImport($this->storage . '/nope.json');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No file at', $out);
    }

    // ---------------------------------------------------------- other tenants

    #[Test]
    public function another_schools_guide_is_untouched_by_the_apply_and_the_rollback(): void
    {
        $other = $this->makeSchool('Other School ' . uniqid());
        $this->importBase();

        foreach (range(1, 10) as $w) {
            DB::table('curriculum_weeks')->insert([
                'masjid_id' => $other->id, 'grade_label' => 'Pre-Kindergarten', 'subject' => self::COMBINED,
                'week_no' => $w, 'quarter' => 1, 'focus' => "Their own week {$w}", 'standard_code' => null,
                'source_label' => 'Their guide', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $theirs = $this->fingerprint($other->id);

        $this->applySplit();
        $this->assertSame($theirs, $this->fingerprint($other->id), 'after the apply');
        $this->assertSame(10, $this->rowCount($other->id));

        $this->runImport($this->inverseFile(), ['--expect' => 'delete=96,insert=32,after=1512']);
        $this->assertSame($theirs, $this->fingerprint($other->id), 'after the rollback');
    }

    // ---------------------------------------------------------- teachers' own records

    #[Test]
    public function nothing_a_teacher_wrote_is_changed_and_the_counts_say_what_would_be_touched(): void
    {
        $this->importBase();

        $cell = CurriculumWeek::query()->where('grade_label', 'Pre-Kindergarten')->where('subject', self::COMBINED)->where('week_no', 3)->first();

        $plan = LessonPlan::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
            'session_date' => now()->toDateString(), 'subject' => self::COMBINED, 'grade_label' => 'Pre-Kindergarten',
            'curriculum_week_no' => 3, 'objective' => $cell->focus, 'prefill_source' => $cell->source_label, 'body' => 'Body.',
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
        $url = "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments";
        $snapshot = ['standard_code' => null, 'curriculum_focus' => $cell->focus, 'curriculum_week_no' => 3];
        $body = $snapshot + ['title' => 'Wudu circle', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString()];
        $id = $this->postJson($url, $body)->assertCreated()->json('data.id');

        $planBefore = LessonPlan::query()->findOrFail($plan->id)->getAttributes();
        $assignmentBefore = ClassAssignment::withTrashed()->findOrFail($id)->getAttributes();

        [, $out] = $this->runImport($this->split, ['--dry-run' => true]);
        $counts = $this->plan($out);
        $this->assertSame(1, $counts['plans_touching']);
        $this->assertSame(1, $counts['plans_combined_subject']);
        $this->assertSame(1, $counts['assignments_touching']);

        // An apply that pinned the earlier numbers is refused: the owner approved something else.
        [$exit, $out] = $this->runImport($this->split, ['--allow-references' => true, '--expect' => 'plans_touching=0,assignments_touching=0']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('plans_touching=0, but the plan says 1', $out);
        $this->assertSame(1512, $this->rowCount());

        [$exit, $out] = $this->runImport($this->split, ['--allow-references' => true, '--expect' => 'delete=32,insert=96,plans_touching=1,assignments_touching=1']);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1576, $this->rowCount());

        $this->assertSame($planBefore, LessonPlan::query()->findOrFail($plan->id)->getAttributes(), 'the lesson plan is byte-identical');
        $this->assertSame($assignmentBefore, ClassAssignment::withTrashed()->findOrFail($id)->getAttributes(), 'the assignment is byte-identical');

        // The work still saves on an edit that leaves its standard snapshot as it was,
        // though the guide cell it copied is gone.
        $this->assertFalse(CurriculumWeek::query()->where('subject', self::COMBINED)->where('grade_label', 'Pre-Kindergarten')->where('week_no', 3)->exists());
        $this->putJson("{$url}/{$id}", $body)->assertOk();
    }

    // ---------------------------------------------------------- an apply refuses, unless it is told it may

    private function copyingPlan(): LessonPlan
    {
        $cell = CurriculumWeek::query()->where('grade_label', 'Pre-Kindergarten')->where('subject', self::COMBINED)->where('week_no', 3)->firstOrFail();

        return LessonPlan::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
            'session_date' => now()->toDateString(), 'subject' => self::COMBINED, 'grade_label' => 'Pre-Kindergarten',
            'curriculum_week_no' => 3, 'objective' => $cell->focus, 'prefill_source' => $cell->source_label, 'body' => 'Body.',
        ]);
    }

    private function copyingAssignment(): int
    {
        $cell = CurriculumWeek::query()->where('grade_label', 'Pre-Kindergarten')->where('subject', self::COMBINED)->where('week_no', 3)->firstOrFail();

        Sanctum::actingAs($this->teacher, ['staff']);

        return $this->postJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/assignments", [
            'standard_code' => null, 'curriculum_focus' => $cell->focus, 'curriculum_week_no' => 3,
            'title' => 'Wudu circle', 'scale' => 'points', 'points_possible' => 10, 'assigned_on' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    /** Nothing changed, no file written, and the command said why. */
    private function assertRefusedUntouched(int $exit, string $out, string $why, string $before, array $filesBefore, int $rows): void
    {
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString($why, $out);
        $this->assertStringContainsString('--allow-references', $out);
        $this->assertSame($rows, $this->rowCount());
        $this->assertSame($before, $this->fingerprint(), 'the guide is unchanged');
        $this->assertSame($filesBefore, $this->storedFiles(), 'no inverse file or snapshot was written');
    }

    #[Test]
    public function a_lesson_plan_that_copies_a_deleted_cell_refuses_the_apply_even_without_expect_until_allowed(): void
    {
        $this->importBase();
        $this->copyingPlan();
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();

        [$exit, $out] = $this->runImport($this->split);
        $this->assertRefusedUntouched($exit, $out, '1 lesson plans copy a cell this file deletes', $before, $filesBefore, 1512);

        // Pinning the right numbers is not the explicit yes.
        [$exit, $out] = $this->runImport($this->split, ['--expect' => 'delete=32,insert=96,plans_touching=1']);
        $this->assertRefusedUntouched($exit, $out, '1 lesson plans copy a cell this file deletes', $before, $filesBefore, 1512);

        // The dry run still prints the counts, and says an apply needs the flag.
        [$exit, $out] = $this->runImport($this->split, ['--dry-run' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, $this->plan($out)['plans_touching']);
        $this->assertStringContainsString('an apply of this plan needs --allow-references', $out);

        [$exit, $out] = $this->runImport($this->split, ['--allow-references' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1576, $this->rowCount());
    }

    #[Test]
    public function an_assignment_that_copies_a_deleted_cell_refuses_the_apply_even_without_expect_until_allowed(): void
    {
        $this->importBase();
        $this->copyingAssignment();
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();

        [$exit, $out] = $this->runImport($this->split);
        $this->assertRefusedUntouched($exit, $out, '1 assignments copy a cell this file deletes', $before, $filesBefore, 1512);

        [$exit, $out] = $this->runImport($this->split, ['--dry-run' => true]);
        $plan = $this->plan($out);
        $this->assertSame([0, 1], [$plan['plans_touching'], $plan['assignments_touching']]);

        [$exit, $out] = $this->runImport($this->split, ['--allow-references' => true, '--expect' => 'assignments_touching=1']);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1576, $this->rowCount());
    }

    #[Test]
    public function cells_to_replace_that_are_not_there_refuse_the_apply_until_allowed(): void
    {
        // No base guide: the 32 combined cells the file replaces are absent, as they would be
        // if production spelled the combined subject differently from the file.
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();

        [$exit, $out] = $this->runImport($this->split);
        $this->assertRefusedUntouched($exit, $out, '32 cells the file replaces are not in the guide', $before, $filesBefore, 0);

        [$exit, $out] = $this->runImport($this->split, ['--dry-run' => true]);
        $plan = $this->plan($out);
        $this->assertSame([0, 32, 96], [$plan['delete'], $plan['delete_absent'], $plan['insert']]);

        [$exit, $out] = $this->runImport($this->split, ['--allow-references' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(96, $this->rowCount());
    }

    #[Test]
    public function a_plan_with_no_references_still_applies_without_the_flag(): void
    {
        $this->importBase();

        [$exit, $out] = $this->runImport($this->split);

        $this->assertSame(0, $exit, $out);
        $this->assertSame(1576, $this->rowCount());
    }

    #[Test]
    public function the_dry_run_prints_the_apply_line_that_pins_every_count_the_flag_waives(): void
    {
        $this->importBase();
        $this->copyingPlan();

        [$exit, $out] = $this->runImport($this->split, ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'approved apply: --expect=delete=32,insert=96,after=1576,plans_touching=1,assignments_touching=0,delete_absent=0 --allow-references',
            $out
        );

        // A plan that needs no flag prints no apply line.
        $clean = $this->makeSchool('Al-Razi Clean ' . uniqid());
        [, $cleanOut] = $this->runImport($this->base, ['--dry-run' => true], $clean->id);
        $this->assertStringNotContainsString('approved apply:', $cleanOut);

        // A teacher who saves a plan between that dry run and the apply stops the pinned apply.
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();
        $this->copyingAssignment();
        [$exit, $out] = $this->runImport($this->split, [
            '--expect' => 'delete=32,insert=96,after=1576,plans_touching=1,assignments_touching=0,delete_absent=0',
            '--allow-references' => true,
        ]);
        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('assignments_touching=0, but the plan says 1', $out);
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($filesBefore, $this->storedFiles());
    }

    #[Test]
    public function the_documented_apply_example_and_the_runbook_pin_what_the_flag_waives(): void
    {
        $command = (string) file_get_contents(base_path('app/Console/Commands/ImportCurriculumWeeks.php'));
        $this->assertMatchesRegularExpression(
            '/curriculum:import 14 <file> --expect=\S*plans_touching=\d\S*assignments_touching=\d\S*delete_absent=\d\S* --allow-references/',
            $command,
            'the docblock example pins the three counts --allow-references waives'
        );
        $this->assertStringContainsString('the inverse file is an', strtolower(preg_replace('/\s+\*?\s*/', ' ', $command)), 'the rollback note says the inverse refuses too');

        foreach (['DECISIONS.md', '.claude/rules/groups.md'] as $doc) {
            $text = (string) file_get_contents(base_path($doc));
            $this->assertStringContainsString('plans_touching', $text, $doc);
            $this->assertMatchesRegularExpression('/inverse file[^.]*refuses/i', preg_replace('/\s+/', ' ', $text), "{$doc}: the rollback note names the refusal");
        }

        $this->assertStringNotContainsString(
            '--expect=<the approved counts>',
            (string) file_get_contents(base_path('DECISIONS.md')),
            'the runbook names the counts to pin, not "the approved counts"'
        );
    }

    #[Test]
    public function applying_the_inverse_after_teachers_planned_on_the_split_cells_refuses_until_allowed(): void
    {
        $this->importBase();
        $this->applySplit();
        $inverse = $this->inverseFile();

        // A teacher plans on a split cell (Pre-K Qur'an, week 3).
        $cell = CurriculumWeek::query()->where('grade_label', 'Pre-Kindergarten')->where('subject', "Qur'an")->where('week_no', 3)->firstOrFail();
        LessonPlan::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
            'session_date' => now()->toDateString(), 'subject' => "Qur'an", 'grade_label' => 'Pre-Kindergarten',
            'curriculum_week_no' => 3, 'objective' => $cell->objective, 'prefill_source' => $cell->source_label, 'body' => 'Body.',
        ]);
        $before = $this->fingerprint();
        $filesBefore = $this->storedFiles();

        [$exit, $out] = $this->runImport($inverse, ['--expect' => 'delete=96,insert=32,after=1512']);
        $this->assertRefusedUntouched($exit, $out, '1 lesson plans copy a cell this file deletes', $before, $filesBefore, 1576);

        [$exit, $out] = $this->runImport($inverse, ['--dry-run' => true]);
        $this->assertSame(1, $this->plan($out)['plans_touching']);
        $this->assertStringContainsString('approved apply: --expect=delete=96,insert=32,after=1512,plans_touching=1,assignments_touching=0,delete_absent=0 --allow-references', $out);

        [$exit, $out] = $this->runImport($inverse, ['--expect' => 'delete=96,insert=32,after=1512,plans_touching=1,assignments_touching=0,delete_absent=0', '--allow-references' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1512, $this->rowCount());
        $this->assertSame(1, LessonPlan::query()->count(), 'the teacher\'s plan is never written');
    }

    // ---------------------------------------------------------- verify's total

    #[Test]
    public function verify_prints_the_tenant_total_beside_the_expected_after_and_fails_on_another_total(): void
    {
        $this->importBase();
        $this->applySplit();

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString("has 1576 cells (plan's after: not given", $out);

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true, '--expect' => 'after=1576']);
        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString("has 1576 cells (plan's after: 1576)", $out);

        // A cell nobody planned: every file cell still matches, so only the total shows it.
        DB::table('curriculum_weeks')->insert([
            'masjid_id' => $this->school->id, 'grade_label' => 'Grade 5', 'subject' => 'Stray', 'week_no' => 1, 'quarter' => 1,
            'focus' => 'Stray', 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true]);
        $this->assertSame(0, $exit, 'without the total, verify cannot see it');

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true, '--expect' => 'after=1576']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('the tenant holds 1577 cells, not the expected 1576', $out);

        [$exit, $out] = $this->runImport($this->split, ['--verify' => true, '--expect' => 'delete=0']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--expect=after=N', $out);
    }

    // ---------------------------------------------------------- an empty Objective is an absent one

    #[Test]
    public function an_empty_objective_or_outcome_is_stored_as_absent(): void
    {
        $this->importBase();
        $variant = $this->splitVariant(function (array $p) {
            $p['rows'][0]['objective'] = '';
            $p['rows'][0]['learning_outcome'] = '';

            return $p;
        });

        [$exit, $out] = $this->runImport($variant);
        $this->assertSame(0, $exit, $out);

        $first = json_decode((string) file_get_contents($variant), true)['rows'][0];
        $row = CurriculumWeek::query()
            ->where('grade_label', $first['grade_label'])->where('subject', $first['subject'])->where('week_no', $first['week_no'])
            ->firstOrFail();

        $this->assertNull($row->objective);
        $this->assertNull($row->learning_outcome);
        $this->assertSame($row->focus, $row->toPrefillArray()['objective'], 'the prefill falls back to the focus');
        $this->assertArrayNotHasKey('learning_outcome', $row->toPrefillArray());

        [$exit] = $this->runImport($variant, ['--verify' => true]);
        $this->assertSame(0, $exit, 'verify agrees with what was stored');
    }
}
