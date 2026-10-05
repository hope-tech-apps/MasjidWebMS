<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\MasjidPointsSetting;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Support\ClassStore;
use App\Support\ClassStoreRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoreFixture;
use Tests\TestCase;

/**
 * T-003.4 (W6): the ledger's WRITE rules, straight at App\Support\ClassStore (what a teacher
 * may do through the API is ClassStoreApiTest; here is what the store enforces whoever asks).
 *
 * The invariants, each with a test that fails without it:
 *   - a balance never goes below zero, and a redemption that cannot be afforded writes NOTHING;
 *   - a prize is this school's, and either school-wide or THIS class's own;
 *   - stock is optional (blank = unlimited), decremented with the redemption and given back
 *     by a reversal, and never goes below zero;
 *   - a repeated request is a replay, not a second deduction;
 *   - a correction is a NEW reversal row, once, and only of what a child spent;
 *   - cash-out to paper is built and OFF: refused while the school's setting is false.
 */
class ClassStoreLedgerTest extends TestCase
{
    use BuildsClassStoreFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreSchools();
        $this->storeOn();
    }

    protected function tearDown(): void
    {
        $this->thaw();

        parent::tearDown();
    }

    private function redeem(GroupMembership $m, Prize $p, ?string $request = null, ?Group $group = null): array
    {
        return ClassStore::redeem($group ?? $this->class, $m, $p, $this->teacher, $request, 'note');
    }

    private function refusedWith(string $reason, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("expected a refusal: {$reason}");
        } catch (ClassStoreRefusal $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    private function rows(): int
    {
        return PrizeLedgerEntry::query()->count();
    }

    // ------------------------------------------------------------- redeeming

    #[Test]
    public function a_redemption_debits_the_price_and_snapshots_the_prize(): void
    {
        $this->credit($this->amira, 12);
        $prize = $this->prize(['title' => 'Gold star pencil', 'cost_bucks' => 5]);

        $done = $this->redeem($this->amira, $prize);

        $entry = $done['entry']->fresh();
        $this->assertFalse($done['replayed']);
        $this->assertSame(PrizeLedgerEntry::KIND_REDEEMED, $entry->kind);
        $this->assertSame(-5, $entry->amount);
        $this->assertSame('Gold star pencil', $entry->prize_title);
        $this->assertSame(5, $entry->prize_cost);
        $this->assertSame($prize->id, $entry->prize_id);
        $this->assertSame($this->teacher->id, $entry->created_by_user_id);
        $this->assertSame($this->school->id, $entry->masjid_id);
        $this->assertSame($this->class->id, $entry->group_id);
        $this->assertSame(7, $this->balanceOf($this->amira));

        // Repricing or renaming the prize afterwards restates nothing a child paid.
        $prize->update(['title' => 'Renamed', 'cost_bucks' => 50]);
        $this->assertSame('Gold star pencil', $entry->fresh()->prize_title);
        $this->assertSame(-5, $entry->fresh()->amount);
    }

    #[Test]
    public function exactly_enough_is_enough_and_one_short_writes_nothing(): void
    {
        $prize = $this->prize(['cost_bucks' => 5, 'stock' => 3]);
        $this->credit($this->amira, 4);

        $rows = $this->rows();
        $this->refusedWith('not_enough_bucks', fn () => $this->redeem($this->amira, $prize));
        $this->assertSame($rows, $this->rows(), 'a refused redemption writes nothing');
        $this->assertSame(3, $prize->fresh()->stock, 'and takes nothing off the shelf');
        $this->assertSame(4, $this->balanceOf($this->amira));

        $this->credit($this->amira, 1);
        $this->redeem($this->amira, $prize);
        $this->assertSame(0, $this->balanceOf($this->amira), 'spending exactly the balance leaves zero, not below');
        $this->assertSame(2, $prize->fresh()->stock);
    }

    #[Test]
    public function a_balance_is_per_student_so_one_childs_bucks_never_pay_for_anothers(): void
    {
        $this->credit($this->yusuf, 50);
        $prize = $this->prize(['cost_bucks' => 5]);

        $this->refusedWith('not_enough_bucks', fn () => $this->redeem($this->amira, $prize));
        $this->assertSame(50, $this->balanceOf($this->yusuf));
    }

    #[Test]
    public function only_a_current_student_of_this_class_can_be_given_a_prize(): void
    {
        $prize = $this->prize(['cost_bucks' => 1]);
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $stranger = $this->enrol($this->school, $other, \App\Models\Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]));
        $this->credit($stranger, 20);
        $guardianEdge = GroupMembership::query()->where('contact_id', $this->amiraParent->id)->firstOrFail();
        $this->credit($this->yusuf, 20);

        // A student of ANOTHER class is not this class's to spend, even in the same school.
        $this->refusedWith('not_a_student', fn () => $this->redeem($stranger, $prize));
        // A guardian edge names a relationship, not a person to give a prize to.
        $this->refusedWith('not_a_student', fn () => $this->redeem($guardianEdge, $prize));
        // A child who has left is no longer this room's, and the refusal says so: the teacher is told why
        // nothing can be spent rather than "no such student". It does not say where their Bucks are, because
        // that depends on how they left (moved to another class, the balance went with them).
        $this->yusuf->forceFill(['left_on' => now()->subDay()->toDateString()])->save();
        $this->refusedWith('student_left', fn () => $this->redeem($this->yusuf->fresh(), $prize));
        try {
            $this->redeem($this->yusuf->fresh(), $prize);
            $this->fail('a student who has left cannot be given a prize');
        } catch (\App\Support\ClassStoreRefusal $e) {
            $this->assertSame('That student has left this class, so nothing can be spent for them here.', $e->getMessage());
        }
        $this->assertSame(20, $this->balanceOf($this->yusuf), 'nothing is taken, and nothing is lost');

        $this->assertSame(0, PrizeLedgerEntry::query()->where('kind', PrizeLedgerEntry::KIND_REDEEMED)->count());
    }

    // ------------------------------------------------------------- the shelf

    #[Test]
    public function a_prize_must_be_school_wide_or_this_classs_own(): void
    {
        $this->credit($this->amira, 100);
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);

        $wide = $this->prize(['title' => 'School-wide']);
        $mine = $this->prize(['title' => 'Mine', 'group_id' => $this->class->id]);
        $theirs = $this->prize(['title' => 'Theirs', 'group_id' => $other->id]);

        $this->redeem($this->amira, $wide);
        $this->redeem($this->amira, $mine);
        $this->refusedWith('prize_other_class', fn () => $this->redeem($this->amira, $theirs));

        $this->assertSame(2, PrizeLedgerEntry::query()->where('kind', PrizeLedgerEntry::KIND_REDEEMED)->count());
    }

    #[Test]
    public function another_schools_prize_is_refused_even_when_called_unbound_and_by_id(): void
    {
        $this->credit($this->amira, 100);
        $otherSchool = $this->newSchool('Other School');
        $foreign = $this->prize(['title' => 'Foreign', 'masjid_id' => $otherSchool->id], $otherSchool);

        // Unbound (a console caller), where the tenant scope filters nothing: the explicit
        // school check inside ClassStore is what stops it.
        $this->refusedWith('prize_unknown', fn () => $this->redeem($this->amira, $foreign));

        $this->assertSame(1, $this->rows(), 'only the credit');
        $this->assertSame(100, $this->balanceOf($this->amira));
    }

    #[Test]
    public function a_retired_prize_cannot_be_given(): void
    {
        $this->credit($this->amira, 10);
        $prize = $this->prize(['is_active' => false]);

        $this->refusedWith('prize_retired', fn () => $this->redeem($this->amira, $prize));
        $this->assertSame(10, $this->balanceOf($this->amira));
    }

    // ------------------------------------------------------------------ stock

    #[Test]
    public function blank_stock_is_unlimited_and_a_count_runs_out_at_zero(): void
    {
        $this->credit($this->amira, 100);

        $unlimited = $this->prize(['stock' => null, 'cost_bucks' => 1]);
        foreach (range(1, 5) as $i) {
            $this->redeem($this->amira, $unlimited);
        }
        $this->assertNull($unlimited->fresh()->stock, 'unlimited stays unlimited: it is not counted down');

        $two = $this->prize(['stock' => 2, 'cost_bucks' => 1]);
        $this->redeem($this->amira, $two);
        $this->redeem($this->amira, $two);
        $this->assertSame(0, $two->fresh()->stock);
        $this->refusedWith('out_of_stock', fn () => $this->redeem($this->amira, $two));
        $this->assertSame(0, $two->fresh()->stock, 'never below zero');
        $this->assertSame(93, $this->balanceOf($this->amira), 'the refused one cost nothing');
    }

    #[Test]
    public function a_reversal_puts_the_prize_back_on_the_shelf_but_only_where_stock_is_counted(): void
    {
        $this->credit($this->amira, 20);
        $counted = $this->prize(['stock' => 1, 'cost_bucks' => 5]);
        $free = $this->prize(['stock' => null, 'cost_bucks' => 5]);

        $a = $this->redeem($this->amira, $counted)['entry'];
        $b = $this->redeem($this->amira, $free)['entry'];
        $this->assertSame(0, $counted->fresh()->stock);

        ClassStore::reverse($this->class, $a, $this->teacher);
        ClassStore::reverse($this->class, $b, $this->teacher);

        $this->assertSame(1, $counted->fresh()->stock);
        $this->assertNull($free->fresh()->stock);
        $this->assertSame(20, $this->balanceOf($this->amira));
    }

    // ------------------------------------------------------------- idempotence

    #[Test]
    public function the_same_request_id_is_a_replay_and_takes_nothing_twice(): void
    {
        $this->credit($this->amira, 20);
        $prize = $this->prize(['stock' => 5, 'cost_bucks' => 5]);

        $first = $this->redeem($this->amira, $prize, 'click-0001-aaaa');
        $again = $this->redeem($this->amira, $prize, 'click-0001-aaaa');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['entry']->id, $again['entry']->id);
        $this->assertSame(15, $this->balanceOf($this->amira), 'charged once');
        $this->assertSame(4, $prize->fresh()->stock, 'one off the shelf, not two');
        $this->assertSame(1, PrizeLedgerEntry::query()->where('kind', PrizeLedgerEntry::KIND_REDEEMED)->count());

        $this->redeem($this->amira, $prize, 'click-0002-bbbb');
        $this->assertSame(10, $this->balanceOf($this->amira), 'a new request id is a new redemption');
    }

    #[Test]
    public function a_request_id_is_scoped_to_the_student_so_it_cannot_replay_another_childs_click(): void
    {
        $this->credit($this->amira, 10);
        $this->credit($this->yusuf, 10);
        $prize = $this->prize(['cost_bucks' => 5]);

        $a = $this->redeem($this->amira, $prize, 'same-token-1234');
        $y = $this->redeem($this->yusuf, $prize, 'same-token-1234');

        $this->assertFalse($y['replayed']);
        $this->assertNotSame($a['entry']->id, $y['entry']->id);
        $this->assertSame(5, $this->balanceOf($this->amira));
        $this->assertSame(5, $this->balanceOf($this->yusuf));
    }

    #[Test]
    public function a_balance_that_changes_under_a_redemption_rolls_the_whole_thing_back(): void
    {
        // SQLite has no row locks, so the invariant is ALSO checked after the write. Simulate
        // another connection spending the child's bucks between our balance read and our insert.
        $this->credit($this->amira, 5);
        $prize = $this->prize(['stock' => 3, 'cost_bucks' => 5]);

        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired) {
            if (! $fired && str_contains($query, 'insert into "prize_ledger_entries"')) {
                $fired = true;
                DB::table('prize_ledger_entries')->insert([
                    'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
                    'kind' => 'redeemed', 'amount' => -5, 'occurred_at' => now(),
                ]);
            }
        });

        $this->refusedWith('balance_changed', fn () => $this->redeem($this->amira, $prize));

        $this->assertSame(5, $this->balanceOf($this->amira), 'nothing of the failed attempt survives');
        $this->assertSame(3, $prize->fresh()->stock);
        $this->assertSame(0, PrizeLedgerEntry::query()->where('kind', PrizeLedgerEntry::KIND_REDEEMED)->count());
    }

    // ---------------------------------------------------------------- reversal

    #[Test]
    public function a_reversal_is_a_new_row_that_gives_the_bucks_back_once(): void
    {
        $this->credit($this->amira, 10);
        $spent = $this->redeem($this->amira, $this->prize(['cost_bucks' => 4]))['entry'];
        $before = $this->rows();

        $done = ClassStore::reverse($this->class, $spent, $this->teacher, 'wrong child');
        $again = ClassStore::reverse($this->class, $spent, $this->teacher);

        $this->assertFalse($done['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($done['entry']->id, $again['entry']->id);
        $this->assertSame($before + 1, $this->rows(), 'exactly one new row, and the original is untouched');
        $this->assertSame(10, $this->balanceOf($this->amira));

        $row = $done['entry']->fresh();
        $this->assertSame(PrizeLedgerEntry::KIND_REVERSAL, $row->kind);
        $this->assertSame(4, $row->amount);
        $this->assertSame($spent->id, $row->reverses_entry_id);
        $this->assertSame("reversal:{$spent->id}", $row->dedupe_key);
        $this->assertSame('wrong child', $row->note);
        $this->assertSame(-4, $spent->fresh()->amount, 'the original entry is never edited');
    }

    #[Test]
    public function only_what_a_child_spent_can_be_reversed_never_what_they_earned(): void
    {
        $earned = $this->credit($this->amira, 10);
        $this->credit($this->amira, 5);
        $spent = $this->redeem($this->amira, $this->prize(['cost_bucks' => 1]))['entry'];
        $reversal = ClassStore::reverse($this->class, $spent, $this->teacher)['entry'];

        // Reversing an `earned` row would delete bucks a child earned; a reversal cannot be reversed
        // (that is a second redemption, done as one).
        $this->refusedWith('not_reversible', fn () => ClassStore::reverse($this->class, $earned, $this->teacher));
        $this->refusedWith('not_reversible', fn () => ClassStore::reverse($this->class, $reversal, $this->teacher));

        $this->assertSame(15, $this->balanceOf($this->amira));
    }

    #[Test]
    public function an_entry_of_another_class_cannot_be_reversed_from_this_one(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $stranger = $this->enrol($this->school, $other, \App\Models\Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]));
        $this->credit($stranger, 10);
        $spent = $this->redeem($stranger, $this->prize(['cost_bucks' => 3]), null, $other)['entry'];

        $this->refusedWith('entry_unknown', fn () => ClassStore::reverse($this->class, $spent, $this->teacher));

        $this->assertSame(7, $this->balanceOf($stranger));
    }

    #[Test]
    public function an_expired_balance_stays_ended_so_an_old_entry_cannot_be_reversed_into_it(): void
    {
        $this->credit($this->amira, 10);
        $spent = $this->redeem($this->amira, $this->prize(['cost_bucks' => 4]))['entry'];
        PrizeLedgerEntry::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
            'kind' => PrizeLedgerEntry::KIND_EXPIRED, 'amount' => -6,
        ]);

        $this->refusedWith('expired', fn () => ClassStore::reverse($this->class, $spent, $this->teacher));
        $this->assertSame(0, $this->balanceOf($this->amira));
    }

    // ---------------------------------------------------------------- paper

    #[Test]
    public function the_note_breakdown_is_the_20_10_5_1_set_and_always_adds_up(): void
    {
        $this->assertSame(['20' => 2, '10' => 0, '5' => 1, '1' => 2], ClassStore::breakdown(47));
        $this->assertSame(['20' => 0, '10' => 0, '5' => 0, '1' => 0], ClassStore::breakdown(0));
        $this->assertSame(['20' => 0, '10' => 1, '5' => 1, '1' => 4], ClassStore::breakdown(19));
        $this->assertSame(['20' => 5, '10' => 0, '5' => 0, '1' => 0], ClassStore::breakdown(100));

        foreach (range(0, 300) as $n) {
            $b = ClassStore::breakdown($n);
            $this->assertSame($n, 20 * $b['20'] + 10 * $b['10'] + 5 * $b['5'] + $b['1'], "the notes for {$n} must add up");
            $this->assertLessThan(2, $b['10'], 'never two tens where a twenty does it');
            $this->assertLessThan(2, $b['5']);
            $this->assertLessThan(5, $b['1']);
        }
    }

    #[Test]
    public function cash_out_is_off_until_the_school_switches_it_on_and_off_writes_nothing(): void
    {
        $this->credit($this->amira, 30);
        $rows = $this->rows();

        $this->refusedWith('paper_bucks_off', fn () => ClassStore::cashOut($this->class, $this->amira, 10, $this->teacher));
        $this->assertSame($rows, $this->rows());
        $this->assertSame(30, $this->balanceOf($this->amira));

        // A settings row that exists but says false is still off.
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'paper_bucks_enabled' => false]);
        $this->refusedWith('paper_bucks_off', fn () => ClassStore::cashOut($this->class, $this->amira, 10, $this->teacher));
        $this->assertSame($rows, $this->rows());
    }

    #[Test]
    public function with_paper_on_a_cash_out_debits_records_the_notes_and_never_overdraws(): void
    {
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'paper_bucks_enabled' => true]);
        $this->credit($this->amira, 30);

        $done = ClassStore::cashOut($this->class, $this->amira, 27, $this->teacher, 'cash-out-0001');
        $entry = $done['entry']->fresh();

        $this->assertSame(PrizeLedgerEntry::KIND_CASHED_OUT, $entry->kind);
        $this->assertSame(-27, $entry->amount);
        $this->assertSame(['20' => 1, '10' => 0, '5' => 1, '1' => 2], $entry->breakdown);
        $this->assertSame(3, $this->balanceOf($this->amira));

        $this->assertTrue(ClassStore::cashOut($this->class, $this->amira, 27, $this->teacher, 'cash-out-0001')['replayed']);
        $this->assertSame(3, $this->balanceOf($this->amira), 'a repeated request pays out once');

        $this->refusedWith('not_enough_bucks', fn () => ClassStore::cashOut($this->class, $this->amira, 4, $this->teacher));
        $this->refusedWith('bad_amount', fn () => ClassStore::cashOut($this->class, $this->amira, 0, $this->teacher));
        $this->assertSame(3, $this->balanceOf($this->amira));

        // A wrong cash-out is reversed like a wrong redemption: a new row, the notes carried over.
        $undo = ClassStore::reverse($this->class, $done['entry'], $this->teacher)['entry'];
        $this->assertSame(27, $undo->amount);
        $this->assertSame(['20' => 1, '10' => 0, '5' => 1, '1' => 2], $undo->fresh()->breakdown);
        $this->assertSame(30, $this->balanceOf($this->amira));
    }

    // ------------------------------------------- review fixes: the locks are pinned

    /** The source of one method, by its own line range (a lock removed from it changes this text). */
    private function bodyOf(string $class, string $method): string
    {
        $r = new ReflectionMethod($class, $method);
        $lines = file((string) $r->getFileName());

        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }

    /** How many INSERTs into the ledger the callback tried (counted before they ran). */
    private function insertsDuring(callable $work): int
    {
        $count = 0;
        $armed = true;
        DB::beforeExecuting(function (string $query) use (&$count, &$armed) {
            if ($armed && str_contains($query, 'insert into "prize_ledger_entries"')) {
                $count++;
            }
        });

        try {
            $work();
        } finally {
            $armed = false;
        }

        return $count;
    }

    #[Test]
    public function every_ledger_write_takes_its_row_locks_and_takes_them_first(): void
    {
        // SQLite has no row locks, so no behavioural test can see `lockForUpdate` go: pin the
        // source, as tests/Feature/TeacherAttachTest.php does for its user-row lock. On MySQL these
        // are what serialise two teachers redeeming the last stock, or a redemption racing the mint.
        $store = ClassStore::class;

        $this->assertSame(5, substr_count((string) file_get_contents(app_path('Support/ClassStore.php')), 'lockForUpdate()'), 'ClassStore holds five locks: the prize (to redeem it and to edit it), and the roster row in three places');

        // A prize edit compares the stock only under the row's lock, or a stale count could still land.
        $edit = $this->bodyOf($store, 'updatePrize');
        $this->assertSame(1, substr_count($edit, 'lockForUpdate()'), 'a prize edit locks the prize row');
        $this->assertLessThan(strpos($edit, '$current !== $expectedStock'), strpos($edit, 'lockForUpdate()'), 'and compares the stock after taking it');

        foreach (['lockStudent', 'reverse', 'appendForSystem'] as $method) {
            $this->assertSame(1, substr_count($this->bodyOf($store, $method), 'lockForUpdate()'), "{$store}::{$method} must lock the student's roster row");
        }

        $redeem = $this->bodyOf($store, 'redeem');
        $this->assertSame(1, substr_count($redeem, 'lockForUpdate()'), 'redeem locks the prize row');
        $this->assertLessThan(strpos($redeem, 'lockForUpdate()'), strpos($redeem, 'self::lockStudent('), 'the student is locked before the prize');
        $this->assertLessThan(strpos($redeem, 'self::existing('), strpos($redeem, 'self::lockStudent('), 'and before anything is read');
        $this->assertLessThan(strpos($redeem, 'self::rawBalance('), strpos($redeem, 'lockForUpdate()'), 'the balance is read under both locks');

        $cash = $this->bodyOf($store, 'cashOut');
        $this->assertLessThan(strpos($cash, 'self::rawBalance('), strpos($cash, 'self::lockStudent('), 'a cash-out reads the balance only after locking the student');

        $reverse = $this->bodyOf($store, 'reverse');
        $this->assertLessThan(strpos($reverse, 'self::existing('), strpos($reverse, 'lockForUpdate()'));

        $system = $this->bodyOf($store, 'appendForSystem');
        $this->assertLessThan(strpos($system, '$decide('), strpos($system, 'lockForUpdate()'), 'minting and expiry decide only under the lock');

        $purge = $this->bodyOf(PrizeLedgerEntry::class, 'purgeDueSets');
        $this->assertSame(1, substr_count($purge, 'lockForUpdate()'), 'the retention purge holds the roster rows');
        $this->assertLessThan(strpos($purge, '->delete()'), strpos($purge, 'lockForUpdate()'), 'and holds them before it deletes');
    }

    // ---------------------------------- review fixes: replays on the last bucks and stock

    #[Test]
    public function a_replay_of_the_redemption_that_took_the_last_bucks_and_the_last_stock_is_a_replay_not_a_refusal(): void
    {
        $this->credit($this->amira, 5);
        $prize = $this->prize(['cost_bucks' => 5, 'stock' => 1]);

        $first = $this->redeem($this->amira, $prize, 'last-one-0001');
        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame(0, $prize->fresh()->stock);

        // The teacher's screen never heard back and taps again: nothing is left to pay with or to
        // give, and the answer must still be "that already happened", not not_enough_bucks / out_of_stock.
        $again = $this->redeem($this->amira, $prize, 'last-one-0001');

        $this->assertTrue($again['replayed']);
        $this->assertSame($first['entry']->id, $again['entry']->id);
        $this->assertSame(1, PrizeLedgerEntry::query()->where('kind', PrizeLedgerEntry::KIND_REDEEMED)->count());
        $this->assertSame(0, $prize->fresh()->stock);
    }

    #[Test]
    public function a_replay_attempts_no_write_at_all(): void
    {
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'paper_bucks_enabled' => true]);
        $this->credit($this->amira, 40);
        $prize = $this->prize(['cost_bucks' => 5]);
        $spent = $this->redeem($this->amira, $prize, 'replay-write-0001')['entry'];
        $cashed = ClassStore::cashOut($this->class, $this->amira, 4, $this->teacher, 'replay-cash-0001')['entry'];
        ClassStore::reverse($this->class, $spent, $this->teacher);

        // Each replay is answered from the row that is already there: not by trying an insert and
        // catching the unique index (which also works, but is a write the answer does not need).
        $this->assertSame(0, $this->insertsDuring(fn () => $this->redeem($this->amira, $prize, 'replay-write-0001')));
        $this->assertSame(0, $this->insertsDuring(fn () => ClassStore::reverse($this->class, $spent, $this->teacher)));
        $this->assertSame(0, $this->insertsDuring(fn () => ClassStore::cashOut($this->class, $this->amira, 4, $this->teacher, 'replay-cash-0001')));

        // And a fresh request does write, so the counter is measuring what it says.
        $this->assertSame(1, $this->insertsDuring(fn () => $this->redeem($this->amira, $prize, 'replay-write-0002')));
        $this->assertNotNull($cashed);
    }

    #[Test]
    public function a_key_that_loses_the_race_on_the_unique_index_is_a_replay_of_the_winner_not_a_500(): void
    {
        // guarded() is the second replay layer: two taps that both passed the pre-check. Called
        // straight, with a write that violates the index a committed rival row holds (a test that
        // races through the same connection would roll the rival back with our savepoint).
        $key = ClassStoreKeys::redeemed($this->amira->id, 'race-token-0001');
        $winner = PrizeLedgerEntry::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
            'kind' => PrizeLedgerEntry::KIND_REDEEMED, 'amount' => -5, 'dedupe_key' => $key,
        ]);
        $guarded = new ReflectionMethod(ClassStore::class, 'guarded');
        $duplicate = fn () => [
            'entry' => PrizeLedgerEntry::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
                'kind' => PrizeLedgerEntry::KIND_REDEEMED, 'amount' => -5, 'dedupe_key' => $key,
            ]),
            'replayed' => false,
        ];

        $result = $guarded->invoke(null, $key, null, $duplicate);

        $this->assertTrue($result['replayed']);
        $this->assertSame($winner->id, $result['entry']->id);
        $this->assertSame(1, PrizeLedgerEntry::query()->where('dedupe_key', $key)->count());

        // The race's loser is a replay only if it asked for the same thing (B11): another prize is a 409.
        try {
            $guarded->invoke(null, $key, fn (PrizeLedgerEntry $e): bool => false, $duplicate);
            $this->fail('a mismatched replay must be refused');
        } catch (\App\Support\ClassStoreRefusal $e) {
            $this->assertSame('request_id_reused', $e->reason);
            $this->assertSame(409, $e->status);
        }

        // A violation with no key of ours to point at is not swallowed.
        $this->expectException(UniqueConstraintViolationException::class);
        $guarded->invoke(null, null, null, $duplicate);
    }

    #[Test]
    public function a_request_id_belongs_to_its_kind_so_a_redemption_id_cannot_replay_a_cash_out(): void
    {
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'paper_bucks_enabled' => true]);
        $this->credit($this->amira, 20);

        $spent = $this->redeem($this->amira, $this->prize(['cost_bucks' => 5]), 'shared-token-0001');
        $cash = ClassStore::cashOut($this->class, $this->amira, 4, $this->teacher, 'shared-token-0001');

        $this->assertFalse($cash['replayed'], 'the same token on a different kind of write is a new write');
        $this->assertNotSame($spent['entry']->id, $cash['entry']->id);
        $this->assertSame(PrizeLedgerEntry::KIND_CASHED_OUT, $cash['entry']->kind);
        $this->assertSame(11, $this->balanceOf($this->amira));
    }

    #[Test]
    public function an_overdraft_of_exactly_one_buck_is_refused_like_a_bigger_one(): void
    {
        $this->credit($this->amira, 1);
        $prize = $this->prize(['cost_bucks' => 1, 'stock' => 2]);

        // Another connection spends the child's last buck between our balance read and our insert.
        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired) {
            if (! $fired && str_contains($query, 'insert into "prize_ledger_entries"')) {
                $fired = true;
                DB::table('prize_ledger_entries')->insert([
                    'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
                    'kind' => 'redeemed', 'amount' => -1, 'occurred_at' => now(),
                ]);
            }
        });

        $this->refusedWith('balance_changed', fn () => $this->redeem($this->amira, $prize));

        $this->assertSame(1, $this->balanceOf($this->amira), 'nothing of the failed attempt survives, the rival row included');
        $this->assertSame(2, $prize->fresh()->stock);
    }

    // ------------------------------- review fixes: stock, price and the system's own rows

    #[Test]
    public function stock_that_runs_out_between_the_check_and_the_decrement_refuses_and_writes_nothing(): void
    {
        $this->credit($this->amira, 10);
        $prize = $this->prize(['cost_bucks' => 5, 'stock' => 1]);

        // The last unit goes to someone else after inStock() said yes and before we decrement.
        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired, $prize) {
            if (! $fired && str_contains($query, 'insert into "prize_ledger_entries"')) {
                $fired = true;
                DB::table('prizes')->where('id', $prize->id)->update(['stock' => 0]);
            }
        });

        $this->refusedWith('out_of_stock', fn () => $this->redeem($this->amira, $prize));

        // Without the guard on the decrement the stock would go to -1 and the redemption would stand.
        $this->assertGreaterThanOrEqual(0, (int) $prize->fresh()->stock, 'never below zero');
        $this->assertSame(10, $this->balanceOf($this->amira), 'the child was not charged for a prize that was not there');
        $this->assertSame(0, PrizeLedgerEntry::query()->where('kind', PrizeLedgerEntry::KIND_REDEEMED)->count());
    }

    #[Test]
    public function a_prize_that_is_out_of_stock_is_refused_before_anything_is_written(): void
    {
        $this->credit($this->amira, 10);
        $prize = $this->prize(['cost_bucks' => 5, 'stock' => 0]);

        $inserts = $this->insertsDuring(fn () => $this->refusedWith('out_of_stock', fn () => $this->redeem($this->amira, $prize)));

        $this->assertSame(0, $inserts, 'the shelf is checked before a ledger row is even attempted');
    }

    #[Test]
    public function the_price_charged_is_the_locked_rows_not_the_one_the_caller_loaded_earlier(): void
    {
        $this->credit($this->amira, 20);
        $stale = $this->prize(['cost_bucks' => 5]);
        DB::table('prizes')->where('id', $stale->id)->update(['cost_bucks' => 8]);   // repriced after the caller read it

        $entry = $this->redeem($this->amira, $stale)['entry']->fresh();

        $this->assertSame(-8, $entry->amount);
        $this->assertSame(8, $entry->prize_cost);
        $this->assertSame(12, $this->balanceOf($this->amira));
    }

    #[Test]
    public function the_systems_own_rows_are_written_once_only_and_only_into_the_named_class(): void
    {
        $row = fn (string $key) => fn (int $balance): array => [
            'kind' => PrizeLedgerEntry::KIND_EARNED, 'amount' => 3, 'week_start' => '2026-10-04', 'week_basis' => 3, 'dedupe_key' => $key,
        ];

        $this->assertNotNull(ClassStore::appendForSystem($this->class, $this->amira->id, $row('earned:sys:once')));

        // Sequential repeat: answered from the existing row, no insert attempted.
        $again = null;
        $inserts = $this->insertsDuring(function () use (&$again, $row) {
            $again = ClassStore::appendForSystem($this->class, $this->amira->id, $row('earned:sys:once'));
        });
        $this->assertNull($again);
        $this->assertSame(0, $inserts);

        // A rival run writes the key between our check and our insert: the unique index says so and
        // the answer is "already done", not an exception out of the hourly sweep.
        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired) {
            if (! $fired && str_contains($query, 'insert into "prize_ledger_entries"')) {
                $fired = true;
                DB::table('prize_ledger_entries')->insert([
                    'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->amira->id,
                    'kind' => 'earned', 'amount' => 3, 'dedupe_key' => 'earned:sys:race', 'occurred_at' => now(),
                ]);
            }
        });
        $this->assertNull(ClassStore::appendForSystem($this->class, $this->amira->id, $row('earned:sys:race')));
        $this->assertSame(3, $this->balanceOf($this->amira), 'only the first row of the test remains');

        // A student of another class is not this class's to write into.
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $before = $this->rows();
        $this->assertNull(ClassStore::appendForSystem($other, $this->amira->id, $row('earned:sys:wrong-class')));
        $this->assertSame($before, $this->rows());
    }

    // ---------------------------------------------------- reading a balance

    #[Test]
    public function a_balance_read_through_the_audience_query_is_only_the_callers_own_students(): void
    {
        $this->credit($this->amira, 9);
        $this->credit($this->yusuf, 4);
        $audience = app(\App\Support\GroupAudience::class);

        $asTeacher = $audience->readablePrizeLedgerQuery($this->teacher, $this->class);
        $this->assertSame([$this->amira->id => 9, $this->yusuf->id => 4], ClassStore::balances($asTeacher, [$this->amira->id, $this->yusuf->id]));

        $this->actAsFamily($this->amiraParent);
        $asParent = $audience->readablePrizeLedgerQuery($this->amiraParent, $this->class);
        $this->assertSame([$this->amira->id => 9], ClassStore::balances($asParent, [$this->amira->id, $this->yusuf->id]), "another family's child is not in a parent's SUM");
        $this->assertSame([], ClassStore::balances($asParent, []));
    }

    private function actAsFamily(\App\Models\Contact $contact): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
        app(\App\Support\TenantContext::class)->set($this->school->id);
    }
}

/** The dedupe key ClassStore::keyFor builds for a redemption, written out so a test does not call the private method. */
final class ClassStoreKeys
{
    public static function redeemed(int $membershipId, string $requestId): string
    {
        return 'redeemed:'.$membershipId.':'.$requestId;
    }
}
