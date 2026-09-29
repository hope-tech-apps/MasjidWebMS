<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Letters\EnglishCurriculum;
use App\Support\Letters\LetterTracker;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-004.2 — `2026_10_01_100000_split_english_letters_by_case`, run BOTH ways.
 *
 * WHAT THIS PINS (each test names its own failure):
 *  - up(): every English mark under a bare letter becomes TWO marks, `x.upper`
 *    and `x.lower`, each with the original status, note, marked-by, mastered date
 *    and timestamps (owner question B2's default), and the bare row is gone;
 *  - the mastered date of a `not_started` row that carries one is kept, as the 9
 *    such production rows need;
 *  - Arabic rows and other children's rows are not touched;
 *  - idempotent: a second run changes nothing, and never overwrites a case a
 *    teacher has since marked differently;
 *  - pre-flight: an English row with a foreign id aborts BEFORE any write;
 *  - down(): folds each pair back into one bare row when (and only when) the two
 *    cases agree, and REFUSES, changing nothing, when they differ on ANY of
 *    status, note, mastered date or who marked it, when one case is missing, or
 *    when a bare row still sits beside the pair;
 *  - who marked each case (`marked_by_user_id`) and the row timestamps survive
 *    up() onto both cases and down() onto the folded row;
 *  - both directions are ONE transaction: a failure part-way leaves the table as
 *    it was (proved by making a later statement throw);
 *  - a case the new code wrote between the migration's read and its first insert
 *    (the deploy has no maintenance mode) wins, and the migration still finishes;
 *  - the tracker reads the migrated data as the child's real progress.
 *
 * NOT PINNED HERE, and only checkable on MySQL: the case-insensitive collation
 * that is the reason the ids carry a suffix, and the `lockForUpdate` row lock
 * (a no-op on SQLite; the `insertOrIgnore` above is what the race test pins). SQLite compares case-sensitively;
 * `EnglishCurriculumTest::no_drill_id_is_a_bare_letter...` asserts the property
 * on the ids themselves, and DECISIONS.md lists the staging-MySQL run this
 * migration still needs before production.
 */
class SplitEnglishLettersByCaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_01_100000_split_english_letters_by_case.php';

    private Masjid $masjid;
    private Group $group;
    private GroupMembership $amal;
    private GroupMembership $bilal;

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

        app(TenantContext::class)->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
        ]);
        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $this->amal = $this->student();
        $this->bilal = $this->student();
    }

    private function student(): GroupMembership
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        return GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'contact_id' => $contact->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function row(GroupMembership $m, string $drill, string $status, array $extra = [], string $alphabet = 'english'): void
    {
        DB::table('arabic_letter_progress')->insert(array_merge([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'group_membership_id' => $m->id, 'alphabet' => $alphabet,
            'drill_id' => $drill, 'status' => $status, 'note' => null,
            'mastered_at' => null, 'marked_by_user_id' => null,
            'created_at' => '2026-09-10 12:00:00', 'updated_at' => '2026-09-11 12:00:00',
        ], $extra));
    }

    private function migrate(string $direction = 'up'): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->{$direction}();
    }

    /** @return array<string,object> keyed by "membership|drill" */
    private function english(): array
    {
        return DB::table('arabic_letter_progress')->where('alphabet', 'english')->get()
            ->keyBy(fn ($r) => $r->group_membership_id.'|'.$r->drill_id)->all();
    }

    #[Test]
    public function up_turns_each_bare_letter_into_a_capital_and_a_lower_case_mark_with_the_same_history(): void
    {
        $this->row($this->amal, 'a', 'mastered', [
            'note' => 'Knows the sound too.', 'mastered_at' => '2026-09-14 13:22:00', 'marked_by_user_id' => null,
        ]);
        $this->row($this->amal, 'b', 'learning');

        $this->migrate();

        $rows = $this->english();
        $this->assertCount(4, $rows);
        $this->assertArrayNotHasKey($this->amal->id.'|a', $rows, 'the bare row must be gone');
        $this->assertArrayNotHasKey($this->amal->id.'|b', $rows);

        foreach (['a.upper', 'a.lower'] as $id) {
            $r = $rows[$this->amal->id.'|'.$id];
            $this->assertSame('mastered', $r->status);
            $this->assertSame('Knows the sound too.', $r->note);
            // The date a parent reads must not become the day of the deploy.
            $this->assertSame('2026-09-14 13:22:00', $r->mastered_at);
            $this->assertSame('2026-09-10 12:00:00', $r->created_at);
            $this->assertSame($this->group->id, (int) $r->group_id);
            $this->assertSame($this->masjid->id, (int) $r->masjid_id);
        }
        $this->assertSame('learning', $rows[$this->amal->id.'|b.upper']->status);
        $this->assertSame('learning', $rows[$this->amal->id.'|b.lower']->status);
    }

    #[Test]
    public function a_not_started_row_that_still_carries_a_mastered_date_keeps_it_on_both_cases(): void
    {
        // Nine production rows are like this: marked mastered, then moved back.
        $this->row($this->amal, 'c', 'not_started', ['mastered_at' => '2026-09-14 13:30:00']);

        $this->migrate();

        $rows = $this->english();
        $this->assertSame('not_started', $rows[$this->amal->id.'|c.upper']->status);
        $this->assertSame('2026-09-14 13:30:00', $rows[$this->amal->id.'|c.upper']->mastered_at);
        $this->assertSame('2026-09-14 13:30:00', $rows[$this->amal->id.'|c.lower']->mastered_at);
    }

    #[Test]
    public function an_upper_case_bare_id_from_a_case_insensitive_database_is_split_under_the_lower_case_letter(): void
    {
        $this->row($this->amal, 'D', 'mastered');

        $this->migrate();

        $this->assertSame(['d.lower', 'd.upper'], collect($this->english())->pluck('drill_id')->sort()->values()->all());
    }

    #[Test]
    public function arabic_rows_and_other_children_are_not_touched(): void
    {
        $this->row($this->amal, 'ba', 'mastered', [], 'arabic');
        $this->row($this->amal, 'ba.fatha', 'learning', [], 'arabic');
        $this->row($this->bilal, 'a', 'learning');
        $this->row($this->amal, 'a', 'mastered');

        $this->migrate();

        $this->assertSame(2, DB::table('arabic_letter_progress')->where('alphabet', 'arabic')->count());
        $this->assertSame(
            ['ba', 'ba.fatha'],
            DB::table('arabic_letter_progress')->where('alphabet', 'arabic')->orderBy('drill_id')->pluck('drill_id')->all()
        );

        $rows = $this->english();
        $this->assertSame('learning', $rows[$this->bilal->id.'|a.upper']->status);
        $this->assertSame('mastered', $rows[$this->amal->id.'|a.upper']->status);
        // Each child's two cases came from that child's own row.
        $this->assertCount(4, $rows);
    }

    #[Test]
    public function running_it_twice_changes_nothing_and_never_overwrites_a_case_marked_since(): void
    {
        $this->row($this->amal, 'a', 'mastered');
        $this->migrate();

        // A teacher marks the lower case differently after the first run.
        DB::table('arabic_letter_progress')->where('drill_id', 'a.lower')->update(['status' => 'learning']);
        $before = $this->english();

        $this->migrate();

        $this->assertEquals($before, $this->english());

        // ...and a bare row that reappears (an old tab, a restore) is converted
        // without clobbering what is already there.
        $this->row($this->amal, 'a', 'not_started');
        $this->migrate();

        $rows = $this->english();
        $this->assertCount(2, $rows);
        $this->assertSame('mastered', $rows[$this->amal->id.'|a.upper']->status);
        $this->assertSame('learning', $rows[$this->amal->id.'|a.lower']->status);
    }

    #[Test]
    public function a_foreign_english_id_aborts_before_anything_is_written(): void
    {
        $this->row($this->amal, 'a', 'mastered');
        $this->row($this->amal, 'zz', 'mastered');

        try {
            $this->migrate();
            $this->fail('the migration should have refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('zz', $e->getMessage());
        }

        // The good row was NOT converted: the pre-flight runs before any write.
        $ids = DB::table('arabic_letter_progress')->orderBy('drill_id')->pluck('drill_id')->all();
        $this->assertSame(['a', 'zz'], $ids);
    }

    #[Test]
    public function down_folds_an_agreeing_pair_back_into_one_bare_row(): void
    {
        $this->row($this->amal, 'a', 'mastered', ['mastered_at' => '2026-09-14 13:22:00', 'note' => 'ok']);
        $this->row($this->amal, 'b', 'learning');
        $this->migrate();

        $this->migrate('down');

        $rows = $this->english();
        $this->assertSame(['a', 'b'], collect($rows)->pluck('drill_id')->sort()->values()->all());
        $this->assertSame('mastered', $rows[$this->amal->id.'|a']->status);
        $this->assertSame('2026-09-14 13:22:00', $rows[$this->amal->id.'|a']->mastered_at);
        $this->assertSame('ok', $rows[$this->amal->id.'|a']->note);
    }

    #[Test]
    public function down_refuses_and_changes_nothing_when_the_two_cases_differ(): void
    {
        $this->row($this->amal, 'a', 'mastered');
        $this->migrate();
        // The split exists to let these differ; folding them would lose one.
        DB::table('arabic_letter_progress')->where('drill_id', 'a.lower')->update(['status' => 'learning']);
        $before = $this->english();

        try {
            $this->migrate('down');
            $this->fail('down() must refuse rather than discard a mark');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('without losing data', $e->getMessage());
        }

        $this->assertEquals($before, $this->english());
    }

    #[Test]
    public function down_refuses_when_only_one_case_was_ever_marked(): void
    {
        $this->row($this->amal, 'a.upper', 'mastered');

        $this->expectException(\RuntimeException::class);
        $this->migrate('down');
    }

    private function teacher(): int
    {
        return User::factory()->create(['phone' => '+1'.random_int(1000000000, 9999999999)])->id;
    }

    /** Make the Nth statement whose SQL starts with $verb throw, to prove a transaction rolls the rest back. */
    private function failOnStatement(string $verb, int $nth): void
    {
        $seen = 0;
        DB::beforeExecuting(function (string $query) use ($verb, $nth, &$seen): void {
            if (str_starts_with(strtolower(ltrim($query)), $verb) && ++$seen === $nth) {
                throw new \RuntimeException('injected failure on '.$verb.' #'.$nth);
            }
        });
    }

    #[Test]
    public function up_keeps_who_marked_each_case_and_both_timestamps(): void
    {
        $teacher = $this->teacher();
        $this->row($this->amal, 'a', 'mastered', [
            'marked_by_user_id' => $teacher, 'created_at' => '2026-09-10 08:00:00', 'updated_at' => '2026-09-12 09:30:00',
        ]);

        $this->migrate();

        $rows = $this->english();
        foreach (['a.upper', 'a.lower'] as $id) {
            $r = $rows[$this->amal->id.'|'.$id];
            $this->assertSame($teacher, (int) $r->marked_by_user_id, "$id keeps the teacher who recorded the mark");
            $this->assertSame('2026-09-10 08:00:00', $r->created_at);
            $this->assertSame('2026-09-12 09:30:00', $r->updated_at);
        }
    }

    #[Test]
    public function down_keeps_who_marked_and_the_timestamps_on_the_folded_row(): void
    {
        $teacher = $this->teacher();
        $this->row($this->amal, 'a', 'mastered', [
            'marked_by_user_id' => $teacher, 'created_at' => '2026-09-10 08:00:00', 'updated_at' => '2026-09-12 09:30:00',
        ]);
        $this->migrate();

        $this->migrate('down');

        $r = $this->english()[$this->amal->id.'|a'];
        $this->assertSame($teacher, (int) $r->marked_by_user_id);
        $this->assertSame('2026-09-10 08:00:00', $r->created_at);
        $this->assertSame('2026-09-12 09:30:00', $r->updated_at);
    }

    /** Run down() and assert it refuses and leaves every English row exactly as it was. */
    private function assertDownRefusesAndChangesNothing(): void
    {
        $before = $this->english();

        try {
            $this->migrate('down');
            $this->fail('down() must refuse rather than discard a mark');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('without losing data', $e->getMessage());
        }

        $this->assertEquals($before, $this->english());
    }

    #[Test]
    public function down_refuses_when_only_the_note_differs(): void
    {
        $this->row($this->amal, 'a', 'mastered', ['mastered_at' => '2026-09-14 13:22:00']);
        $this->migrate();
        // Same status, same date: a teacher wrote a note on the lower case alone.
        DB::table('arabic_letter_progress')->where('drill_id', 'a.lower')->update(['note' => 'Confuses it with o.']);

        $this->assertDownRefusesAndChangesNothing();
    }

    #[Test]
    public function down_refuses_when_only_the_mastered_date_differs(): void
    {
        $this->row($this->amal, 'a', 'mastered', ['mastered_at' => '2026-09-14 13:22:00']);
        $this->migrate();
        // Unticked and re-ticked: same status again, a different first-mastered date.
        DB::table('arabic_letter_progress')->where('drill_id', 'a.lower')->update(['mastered_at' => '2026-09-21 10:00:00']);

        $this->assertDownRefusesAndChangesNothing();
    }

    #[Test]
    public function down_refuses_when_only_who_marked_it_differs(): void
    {
        $t1 = $this->teacher();
        $t2 = $this->teacher();
        $this->row($this->amal, 'a', 'mastered', ['mastered_at' => '2026-09-14 13:22:00', 'marked_by_user_id' => $t1]);
        $this->migrate();
        // Two teachers each ticked their own case the same day, with the same date and no note.
        DB::table('arabic_letter_progress')->where('drill_id', 'a.lower')->update(['marked_by_user_id' => $t2]);

        $this->assertDownRefusesAndChangesNothing();
    }

    #[Test]
    public function down_refuses_when_a_bare_row_sits_beside_an_agreeing_pair(): void
    {
        $this->row($this->amal, 'a', 'mastered');
        $this->migrate();
        // An old tab wrote a bare row after the split. The pair agrees; folding would collide with it.
        $this->row($this->amal, 'a', 'learning');

        $this->assertDownRefusesAndChangesNothing();
    }

    #[Test]
    public function up_is_all_or_nothing(): void
    {
        $this->row($this->amal, 'a', 'mastered');
        $this->row($this->amal, 'b', 'learning');
        $before = $this->english();

        // Legacy row `a` is fully converted, then the delete of `b` fails.
        $this->failOnStatement('delete', 2);

        try {
            $this->migrate();
            $this->fail('the injected failure should have surfaced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('injected failure', $e->getMessage());
        }

        $this->assertEquals($before, $this->english(), 'no copy of `a` may survive a failure on `b`');
    }

    #[Test]
    public function down_is_all_or_nothing(): void
    {
        $this->row($this->amal, 'a', 'mastered');
        $this->row($this->amal, 'b', 'learning');
        $this->migrate();
        $before = $this->english();

        // The first pair is folded, then the delete of the second pair fails.
        $this->failOnStatement('delete', 2);

        try {
            $this->migrate('down');
            $this->fail('the injected failure should have surfaced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('injected failure', $e->getMessage());
        }

        $this->assertEquals($before, $this->english(), 'no folded row may survive a failure on the second pair');
    }

    #[Test]
    public function a_case_the_new_code_wrote_after_the_migration_read_wins_and_the_migration_still_finishes(): void
    {
        $this->row($this->amal, 'a', 'mastered', ['note' => 'from the bare row', 'mastered_at' => '2026-09-14 13:22:00']);

        // bin/deploy runs the new code before `migrate --force`: a teacher taps capital A in the gap
        // between the migration's read and its first write. The unique index would reject a plain insert.
        $raced = false;
        DB::beforeExecuting(function (string $query) use (&$raced): void {
            if ($raced || ! str_starts_with(strtolower(ltrim($query)), 'insert')) {
                return;
            }
            $raced = true;
            $this->row($this->amal, 'a.upper', 'learning', ['note' => 'tapped during the deploy']);
        });

        $this->migrate();

        $this->assertTrue($raced, 'the hook must have fired before the migration wrote');
        $rows = $this->english();
        $this->assertCount(2, $rows);
        $this->assertArrayNotHasKey($this->amal->id.'|a', $rows);
        $this->assertSame('learning', $rows[$this->amal->id.'|a.upper']->status, 'the newer mark wins');
        $this->assertSame('tapped during the deploy', $rows[$this->amal->id.'|a.upper']->note);
        $this->assertSame('mastered', $rows[$this->amal->id.'|a.lower']->status, 'the other case still comes from the bare row');
        $this->assertSame('from the bare row', $rows[$this->amal->id.'|a.lower']->note);
    }

    #[Test]
    public function the_tracker_reads_the_migrated_marks_as_the_childs_real_progress(): void
    {
        foreach (['a', 'b', 'c'] as $letter) {
            $this->row($this->amal, $letter, 'mastered');
        }
        $this->migrate();

        app(TenantContext::class)->set($this->masjid->id);
        $payload = LetterTracker::for('english')->forStudent($this->group, $this->amal->load('contact'));

        $this->assertSame(['mastered' => 6, 'total' => 52], $payload['totals']);
        $this->assertSame(
            [['upper', 3, 26], ['lower', 3, 26]],
            array_map(fn ($s) => [$s['id'], $s['mastered'], $s['total']], $payload['set_totals'])
        );
        $this->assertSame(EnglishCurriculum::syllabus(null), array_merge(...array_map(
            fn ($l) => array_column($l['drills'], 'id'), $payload['letters']
        )));
    }
}
