<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\MasjidPointsSetting;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\BucksExpiry;
use App\Support\ClassStore;
use App\Support\ClassStoreRefusal;
use App\Support\SchoolCalendar;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\BuildsClassStoreFixture;
use Tests\Support\LogsLikeProduction;
use Tests\TestCase;

/**
 * A class-store balance follows a student who is moved to another class: the LEDGER's half.
 *
 * `ClassStore::carryBalance()` writes one `transfer_out` on the roster row being left and one
 * `transfer_in` on the row in the new class, for the old row's whole balance or not at all. This
 * suite drives that writer directly, in the order the move runs it (the place in the new class
 * exists, then the pair, then the old row is closed), because the move itself does not call it
 * yet: that wiring, and every sentence the office reads, has its own tests with the move.
 *
 * What is pinned here:
 *   - the pair: both rows' every column, the two keys, whole balance or nothing, both rows or
 *     neither, a fault and never a refusal;
 *   - the rule on two classes' dates that decides whether Bucks move at all;
 *   - expiry: one date on both rows of the pair, each counted with its own sign, so a cutoff
 *     older than the move takes nothing of a carried balance, a cutoff after it takes all of it,
 *     and a write-off given back onto the old row is treated as a classmate's is;
 *   - the other writers once a student has been moved: the mint, the undo, the spend;
 *   - what the office may read: no figure in the reconciliation, no count in a refusal.
 *
 * The school's clock is America/New_York. Unless a test says otherwise "now" is Monday
 * 2026-10-12 09:00 and the grace before a cutoff acts is the default seven days. The class store
 * is OFF for the school, as it is for every organisation, except where a test needs a route or
 * a command that is behind the switch.
 */
class ClassStoreCarryTest extends TestCase
{
    use BuildsClassStoreFixture;
    use LogsLikeProduction;
    use RefreshDatabase;

    /** The ten descriptive columns that are NULL on both rows of a pair. */
    private const EMPTY_ON_A_TRANSFER = [
        'note', 'prize_id', 'prize_title', 'prize_cost', 'breakdown',
        'week_start', 'week_basis', 'week_points', 'week_rate', 'reverses_entry_id',
    ];

    private const MOVED_AWAY = 'That student was moved to another class after this was recorded, so it can no longer be undone here.';

    /** The class a student is moved into. */
    private Group $next;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreSchools();
        ClassStore::forgetCarryReady();
        config(['groups.bucks.expiry_grace_days' => 7]);
        $this->freeze('2026-10-12 09:00');

        $this->next = $this->newClass('Grade 4');
    }

    protected function tearDown(): void
    {
        ClassStore::forgetCarryReady();
        $this->forgetProductionLogs();
        $this->thaw();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string,mixed>  $attributes */
    private function newClass(string $name, array $attributes = [], ?int $schoolId = null): Group
    {
        return Group::factory()->create($attributes + [
            'masjid_id' => $schoolId ?? $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => $name,
        ]);
    }

    private function student(string $firstName, ?Group $class = null): GroupMembership
    {
        $class ??= $this->class;

        return $this->enrol($this->school, $class, Contact::factory()->create([
            'masjid_id' => $this->school->id, 'email' => null, 'first_name' => $firstName, 'last_name' => 'Example',
        ]));
    }

    private function today(): string
    {
        return SchoolCalendar::for((int) $this->school->id)->today();
    }

    /**
     * The ledger's half of a move, in the order RosterMove::write() does it: the place in the
     * new class exists (created, or re-opened on a return), the pair is written, and only then
     * is the old row closed and stamped with where the student went.
     */
    private function move(GroupMembership $old, Group $to, ?User $by = null, ?string $today = null): GroupMembership
    {
        $today ??= $this->today();
        $old = GroupMembership::query()->findOrFail($old->id);
        $from = Group::withoutGlobalScopes()->findOrFail($old->group_id);
        $to = Group::withoutGlobalScopes()->findOrFail($to->id);

        return DB::transaction(function () use ($old, $from, $to, $by, $today): GroupMembership {
            $new = GroupMembership::query()
                ->where('group_id', $to->id)
                ->where('contact_id', $old->contact_id)
                ->participants()
                ->first();

            if ($new === null) {
                $new = GroupMembership::create([
                    'masjid_id' => $old->masjid_id, 'group_id' => $to->id, 'contact_id' => $old->contact_id, 'role' => $old->role,
                ]);
            } else {
                $new->returnToRoster();
            }

            $new->markMovedIn($by, (int) $from->id, $today)->save();

            ClassStore::carryBalance($from, $old, $to, $new, $by, $today);

            $old->markLeftByStaff($by, CarbonImmutable::parse($today)->subDay()->toDateString())
                ->markMovedOut($by, (int) $to->id, $today)
                ->save();

            return $new;
        });
    }

    /** "Put back" on the roster: one save that clears the leaving date and nothing else. */
    private function putBack(GroupMembership $row): void
    {
        GroupMembership::query()->findOrFail($row->id)->returnToRoster()->save();
    }

    /** The hourly sweep's work for every class of the school, with the real BucksExpiry. */
    private function sweep(): void
    {
        $calendar = SchoolCalendar::for((int) $this->school->id);

        foreach (Group::withoutGlobalScopes()->where('masjid_id', $this->school->id)->orderBy('id')->get() as $group) {
            BucksExpiry::forClass($this->school, $group, $calendar);
        }
    }

    private function schoolYear(string $firstDay, string $lastDay): SchoolYear
    {
        return app(TenantContext::class)->runWithout(fn () => SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => substr($firstDay, 0, 4).'-'.substr($lastDay, 2, 2),
            'first_day' => $firstDay, 'last_day' => $lastDay,
        ]));
    }

    /** One roster row's ledger as `kind:amount`, oldest first. */
    private function ledger(GroupMembership $m): array
    {
        return DB::table('prize_ledger_entries')->where('group_membership_id', $m->id)->orderBy('id')->get()
            ->map(fn ($e): string => $e->kind.':'.$e->amount)->all();
    }

    /** One roster row's ledger rows of one kind, as the TABLE holds them (never through the model). */
    private function stored(GroupMembership $m, string $kind): array
    {
        return DB::table('prize_ledger_entries')->where('group_membership_id', $m->id)->where('kind', $kind)->orderBy('id')->get()
            ->map(fn ($e): array => (array) $e)->all();
    }

    private function transferRows(): int
    {
        return DB::table('prize_ledger_entries')->whereIn('kind', PrizeLedgerEntry::TRANSFER_KINDS)->count();
    }

    /** A row written straight into the ledger, for a state no public writer produces on demand. */
    private function plant(GroupMembership $m, string $kind, int $amount, array $attributes = []): PrizeLedgerEntry
    {
        return PrizeLedgerEntry::create($attributes + [
            'masjid_id' => $m->masjid_id, 'group_id' => $m->group_id, 'group_membership_id' => $m->id,
            'kind' => $kind, 'amount' => $amount, 'occurred_at' => now(),
        ]);
    }

    private function redeem(GroupMembership $m, int $cost, ?int $stock = null): PrizeLedgerEntry
    {
        $group = Group::withoutGlobalScopes()->findOrFail($m->group_id);

        return ClassStore::redeem($group, $m, $this->prize(['cost_bucks' => $cost, 'stock' => $stock]), $this->teacher)['entry'];
    }

    private function refusedWith(string $reason, callable $attempt): ClassStoreRefusal
    {
        try {
            $attempt();
        } catch (ClassStoreRefusal $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }

        $this->fail("expected a refusal: {$reason}");
    }

    private function faultWith(string $needle, callable $attempt): LogicException
    {
        try {
            $attempt();
        } catch (LogicException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return $e;
        }

        $this->fail("expected a fault: {$needle}");
    }

    /** `bucks_from` as a SuperAdmin would have set it, so the real mint counts the weeks under test. */
    private function mintingFrom(string $day): void
    {
        $this->storeOn();
        MasjidPointsSetting::withoutMasjidScope()->updateOrCreate(['masjid_id' => $this->school->id], ['bucks_from' => $day]);
    }

    private function mint(): void
    {
        $this->assertSame(0, Artisan::call('bucks:mint'));
        $this->assertStringContainsString('0 failure(s)', Artisan::output());
    }

    // ---------------------------------------------------------------- the pair

    #[Test]
    public function the_pair_is_two_rows_for_the_whole_balance_and_each_explains_its_own_roster_row(): void
    {
        $this->credit($this->amira, 7, '2026-09-27');
        $this->credit($this->amira, 5, '2026-10-04');
        ClassStore::redeem($this->class, $this->amira, $this->prize(['title' => 'Kite', 'cost_bucks' => 2]), $this->teacher, null, 'a note for the old class only');
        $before = $this->ledger($this->amira);

        $new = $this->move($this->amira, $this->next, $this->admin);

        $this->assertSame([...$before, 'transfer_out:-10'], $this->ledger($this->amira), 'the old rows are untouched and one row is added');
        $this->assertSame(['transfer_in:10'], $this->ledger($new), 'one amount arrives: never a row per source row, never a copy');
        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame(10, $this->balanceOf($new));

        [$out] = $this->stored($this->amira, PrizeLedgerEntry::KIND_TRANSFER_OUT);
        [$in] = $this->stored($new, PrizeLedgerEntry::KIND_TRANSFER_IN);

        $this->assertSame([(int) $this->school->id, (int) $this->class->id, (int) $this->amira->id, -10], [(int) $out['masjid_id'], (int) $out['group_id'], (int) $out['group_membership_id'], (int) $out['amount']]);
        $this->assertSame([(int) $this->school->id, (int) $this->next->id, (int) $new->id, 10], [(int) $in['masjid_id'], (int) $in['group_id'], (int) $in['group_membership_id'], (int) $in['amount']]);

        // The two keys. Neither names the other roster row, and "one in per out" is the unique index's.
        $this->assertSame('transfer_out:'.$this->amira->id.':1', $out['dedupe_key']);
        $this->assertSame('transfer_in:'.$out['id'], $in['dedupe_key']);

        foreach ([$out, $in] as $row) {
            $this->assertSame((int) $this->admin->id, (int) $row['created_by_user_id'], 'the mover');
            $this->assertSame(now()->format('Y-m-d H:i:s'), $row['occurred_at'], 'now');
            $this->assertNotNull($row['retained_until'], 'stamped by the model like every row');

            foreach (self::EMPTY_ON_A_TRANSFER as $column) {
                $this->assertNull($row[$column], "{$row['kind']}.{$column} carries nothing of the old class's history");
            }
        }

        // A console or seeder path has no user to name.
        $second = $this->move($this->yusufWith(3), $this->next);
        $this->assertNull($this->stored($second, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['created_by_user_id']);
    }

    private function yusufWith(int $bucks): GroupMembership
    {
        $this->credit($this->yusuf, $bucks, '2026-10-04');

        return $this->yusuf;
    }

    #[Test]
    public function counts_from_is_stored_on_both_rows_and_is_read_back_from_the_table_not_the_model(): void
    {
        // An attribute that is not fillable is dropped by create() without an error: the pair
        // would be stored with no date and the next sweep would write the carried balance off.
        $this->assertContains('counts_from', (new PrizeLedgerEntry)->getFillable());
        $this->assertArrayNotHasKey('counts_from', (new PrizeLedgerEntry)->getCasts(), 'compared as text, like week_start');
        $this->assertSame('date', Schema::getColumnType('prize_ledger_entries', 'counts_from'));

        $this->credit($this->amira, 6, '2026-10-04');
        $new = $this->move($this->amira, $this->next);

        $this->assertSame('2026-10-04', $this->stored($this->amira, PrizeLedgerEntry::KIND_TRANSFER_OUT)[0]['counts_from']);
        $this->assertSame('2026-10-04', $this->stored($new, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
        $this->assertSame(2, DB::table('prize_ledger_entries')->where('counts_from', '>=', '2026-10-04')->count(), 'and it compares as a day');
        $this->assertSame(0, DB::table('prize_ledger_entries')->where('counts_from', '>=', '2026-10-05')->count());
        $this->assertSame(0, DB::table('prize_ledger_entries')->whereNotIn('kind', PrizeLedgerEntry::TRANSFER_KINDS)->whereNotNull('counts_from')->count());
    }

    #[Test]
    public function the_two_kinds_are_neither_minted_nor_reversible(): void
    {
        $this->assertSame(['transfer_out', 'transfer_in'], PrizeLedgerEntry::TRANSFER_KINDS);

        foreach (PrizeLedgerEntry::TRANSFER_KINDS as $kind) {
            $this->assertContains($kind, PrizeLedgerEntry::KINDS);
            $this->assertNotContains($kind, PrizeLedgerEntry::MINTED_KINDS);
            $this->assertNotContains($kind, PrizeLedgerEntry::REVERSIBLE_KINDS);
            $this->assertLessThanOrEqual(16, strlen($kind), 'kind is a string of 16');
        }

        // A teacher who tries to undo either row of a pair is told it is not something to undo.
        $this->credit($this->amira, 4, '2026-10-04');
        $new = $this->move($this->amira, $this->next);
        $in = PrizeLedgerEntry::query()->where('group_membership_id', $new->id)->sole();
        $out = PrizeLedgerEntry::query()->where('group_membership_id', $this->amira->id)->where('kind', PrizeLedgerEntry::KIND_TRANSFER_OUT)->sole();

        $this->refusedWith('not_reversible', fn () => ClassStore::reverse($this->next, $in, $this->teacher));
        $this->refusedWith('not_reversible', fn () => ClassStore::reverse($this->class, $out, $this->teacher));
        $this->assertSame(4, $this->balanceOf($new));
    }

    #[Test]
    public function a_zero_or_a_negative_balance_writes_nothing(): void
    {
        // No ledger row at all.
        $new = $this->move($this->amira, $this->next);
        $this->assertSame(0, PrizeLedgerEntry::query()->count());

        // Rows that sum to zero.
        $layla = $this->student('Layla');
        $this->credit($layla, 4, '2026-10-04');
        $this->redeem($layla, 4);
        $laylaNew = $this->move($layla, $this->next);

        // A balance below zero: the debt stays where it is, visible in the old class.
        $this->credit($this->yusuf, 3, '2026-10-04');
        $this->plant($this->yusuf, PrizeLedgerEntry::KIND_REDEEMED, -5);
        $yusufNew = $this->move($this->yusuf, $this->next);

        $this->assertSame(0, $this->transferRows());
        $this->assertSame(-2, $this->balanceOf($this->yusuf));

        foreach ([$new, $laylaNew, $yusufNew] as $place) {
            $this->assertSame([], $this->ledger($place));
        }
    }

    #[Test]
    public function there_and_back_and_there_again_numbers_the_keys_per_roster_row_and_adds_to_what_a_row_holds(): void
    {
        $this->credit($this->amira, 6, '2026-10-04');

        // F to T.
        $there = $this->move($this->amira, $this->next);
        $this->credit($there, 2, '2026-10-04');
        $held = $this->ledger($this->amira);

        // Back: the place held before is re-opened, and the new row is ADDED to what it holds.
        $back = $this->move($there, $this->class);
        $this->assertSame($this->amira->id, $back->id, 'a return re-opens the same roster row');
        $this->assertSame([...$held, 'transfer_in:8'], $this->ledger($this->amira), 'nothing on the re-opened row is reset or netted');
        $this->assertSame(8, $this->balanceOf($this->amira));
        $this->assertSame(0, $this->balanceOf($there));

        // And F to T again.
        $again = $this->move($this->amira, $this->next);
        $this->assertSame($there->id, $again->id);
        $this->assertSame(['transfer_in:6', 'earned:2', 'transfer_out:-8', 'transfer_in:8'], $this->ledger($there));
        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame(8, $this->balanceOf($there));

        $keys = DB::table('prize_ledger_entries')->where('kind', PrizeLedgerEntry::KIND_TRANSFER_OUT)->orderBy('id')->pluck('dedupe_key', 'id');
        $this->assertSame(
            ['transfer_out:'.$this->amira->id.':1', 'transfer_out:'.$there->id.':1', 'transfer_out:'.$this->amira->id.':2'],
            $keys->values()->all(),
        );
        $this->assertSame(
            $keys->keys()->map(fn ($id): string => 'transfer_in:'.$id)->all(),
            DB::table('prize_ledger_entries')->where('kind', PrizeLedgerEntry::KIND_TRANSFER_IN)->orderBy('id')->pluck('dedupe_key')->all(),
            'each transfer_in is keyed on the transfer_out it answers',
        );
    }

    #[Test]
    public function a_duplicate_key_is_a_fault_and_not_a_roster_that_changed(): void
    {
        // The move answers a unique violation as "this roster changed, try again". That is right
        // for the roster's own index and a loop for a ledger key that is wrong by construction.
        $this->credit($this->amira, 4127, '2026-10-04');
        $this->plant($this->yusuf, PrizeLedgerEntry::KIND_EARNED, 0, ['dedupe_key' => 'transfer_out:'.$this->amira->id.':1']);

        try {
            $this->move($this->amira, $this->next);
            $this->fail('a duplicate transfer_out key must be a fault');
        } catch (LogicException $e) {
            $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $e);
            $this->assertNull($e->getPrevious(), "the database's message carries the insert's values and is not chained");
            $this->assertStringNotContainsString('4127', $e->getMessage(), 'a fault names keys and ids, never the amount');
        }

        $this->assertSame(0, $this->transferRows());
        $this->assertSame(4127, $this->balanceOf($this->amira));
        $this->assertNull($this->amira->fresh()->left_on, 'the whole move rolled back');

        // The second key. Two rows are planted first, so the transfer_out will take the third id
        // from here, and the key its transfer_in is about to ask for is already spoken for. (The
        // planted transfer_out row moves n on to 2, past the key planted above.)
        $outId = (int) DB::table('prize_ledger_entries')->max('id') + 3;
        $this->plant($this->yusuf, PrizeLedgerEntry::KIND_EARNED, 0, ['dedupe_key' => 'transfer_in:'.$outId]);
        $this->plant($this->amira, PrizeLedgerEntry::KIND_TRANSFER_OUT, 0, ['dedupe_key' => 'unrelated']);

        $fault = $this->faultWith('transfer_in:'.$outId, fn () => $this->move($this->amira, $this->next));
        $this->assertNull($fault->getPrevious());
        $this->assertStringNotContainsString('4127', $fault->getMessage());
        $this->assertSame(1, $this->transferRows(), 'only the planted row: neither row of the pair stayed');
        $this->assertSame(4127, $this->balanceOf($this->amira));
    }

    #[Test]
    public function a_failure_of_the_second_insert_leaves_neither_row(): void
    {
        $this->credit($this->amira, 9, '2026-10-04');

        PrizeLedgerEntry::creating(function (PrizeLedgerEntry $entry): void {
            if ($entry->kind === PrizeLedgerEntry::KIND_TRANSFER_IN) {
                throw new RuntimeException('planted: the second insert fails');
            }
        });

        try {
            $this->move($this->amira, $this->next);
            $this->fail('the planted failure must leave the move');
        } catch (RuntimeException $e) {
            $this->assertSame('planted: the second insert fails', $e->getMessage());
        }

        $this->assertSame(['earned:9'], $this->ledger($this->amira), 'no half pair: the transfer_out went with the rollback');
        $this->assertSame(0, $this->transferRows());
        $this->assertNull($this->amira->fresh()->left_on);
        $this->assertSame(0, GroupMembership::query()->where('group_id', $this->next->id)->count(), 'and so did the new place');
    }

    #[Test]
    public function outside_a_transaction_it_throws_before_it_reads_anything(): void
    {
        $this->credit($this->amira, 5, '2026-10-04');
        $new = $this->enrol($this->school, $this->next, $this->amiraContact);
        $today = $this->today();

        // RefreshDatabase runs every test inside a transaction of its own, which would satisfy
        // the guard. Step out of it for the call and back in afterwards, so the trait still has
        // a transaction to roll back (as OrganisationProvisionerTest does). The rows built above
        // go with it; the guard is the first statement and reads nothing.
        DB::rollBack();

        try {
            $this->assertSame(0, DB::transactionLevel(), 'the premise: nothing is open');
            DB::enableQueryLog();

            $this->faultWith('no transaction is open', fn () => ClassStore::carryBalance($this->class, $this->amira, $this->next, $new, null, $today));

            $this->assertSame([], DB::getQueryLog(), 'refused before a single statement');
            DB::disableQueryLog();
        } finally {
            DB::beginTransaction();
        }
    }

    #[Test]
    public function it_is_the_moves_own_write_and_every_misuse_is_a_fault(): void
    {
        $this->credit($this->amira, 5, '2026-10-04');
        $new = $this->enrol($this->school, $this->next, $this->amiraContact);
        $today = $this->today();
        $inMove = fn (callable $work) => fn () => DB::transaction($work);

        $this->faultWith('same row was given twice', $inMove(fn () => ClassStore::carryBalance($this->class, $this->amira, $this->class, $this->amira, null, $today)));

        // A guardian entry is a relationship, not a place that holds a balance.
        $edge = GroupMembership::query()->where('contact_id', $this->amiraParent->id)->sole();
        $this->faultWith("not a student's own place", $inMove(fn () => ClassStore::carryBalance($this->class, $this->amira, $this->class, $edge, null, $today)));

        // Another child's place.
        $other = $this->enrol($this->school, $this->next, $this->yusufContact);
        $this->faultWith("two different people's", $inMove(fn () => ClassStore::carryBalance($this->class, $this->amira, $this->next, $other, null, $today)));

        // The rows are not the ones held in the two classes named.
        $this->faultWith('not the places held', $inMove(fn () => ClassStore::carryBalance($this->next, $this->amira, $this->class, $new, null, $today)));

        // Another organisation's class, even for the same contact id.
        $elsewhere = $this->newSchool('Elsewhere');
        $theirClass = $this->newClass('Their class', [], (int) $elsewhere->id);
        $theirRow = GroupMembership::create([
            'masjid_id' => $elsewhere->id, 'group_id' => $theirClass->id, 'contact_id' => $this->amiraContact->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $this->faultWith('not in one organisation', $inMove(fn () => ClassStore::carryBalance($this->class, $this->amira, $theirClass, $theirRow, null, $today)));

        $this->assertSame(0, $this->transferRows());
        $this->assertSame(5, $this->balanceOf($this->amira));

        // It takes no lock and goes through neither of the two wrappers that swallow a failure.
        $method = new ReflectionMethod(ClassStore::class, 'carryBalance');
        $lines = file((string) $method->getFileName());
        $start = $method->getStartLine();
        $body = implode('', array_slice($lines, $start - 1, $method->getEndLine() - $start + 1));

        foreach (['lockForUpdate', 'sharedLock', 'self::appendForSystem(', 'self::guarded(', 'self::lockStudent(', 'DB::transaction('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "carryBalance must not use {$forbidden}");
        }

        $this->assertSame('void', (string) $method->getReturnType(), 'no caller can learn whether a pair was written');

        // Its one caller is the move. Until the move is wired to it there is none at all, and
        // there is never a second: counted over the whole of app/, comments included.
        $callers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $calls = substr_count((string) file_get_contents($file->getPathname()), 'ClassStore::carryBalance(');

                if ($calls > 0) {
                    $callers[str_replace(app_path().'/', '', $file->getPathname())] = $calls;
                }
            }
        }

        $this->assertContains($callers, [[], ['Support/RosterMove.php' => 1]], 'carryBalance is called from the move, once, and from nowhere else');
    }

    #[Test]
    public function the_self_check_compares_each_row_with_itself_and_catches_only_this_writes_own_arithmetic(): void
    {
        // A re-opened row that reads NEGATIVE before the pair, for an older reason of its own,
        // is not this write's fault, and neither is it when the row still reads negative
        // afterwards: the check asks that the row went up by exactly what left the other one,
        // not that it ends above zero. A fault here would stop a whole class's move.
        $this->plant($this->amira, PrizeLedgerEntry::KIND_EARNED, 2, ['week_start' => '2026-09-27']);
        $this->plant($this->amira, PrizeLedgerEntry::KIND_REDEEMED, -9);
        $there = $this->move($this->amira, $this->next);
        $this->assertSame(0, $this->transferRows(), 'a debt is not carried');

        $this->credit($there, 3, '2026-10-04');
        $this->move($there, $this->class);

        $this->assertSame(-4, $this->balanceOf($this->amira), 'minus seven, plus the three that came back');
        $this->assertSame(0, $this->balanceOf($there));
        $this->assertSame(['earned:2', 'redeemed:-9', 'transfer_in:3'], $this->ledger($this->amira));

        // A wrong amount is caught. The writer is a final class with a private insert, so the
        // mistake is planted where the row is made: the transfer_in arrives one buck short.
        $this->credit($this->yusuf, 4127, '2026-10-04');

        PrizeLedgerEntry::creating(function (PrizeLedgerEntry $entry): void {
            if ($entry->kind === PrizeLedgerEntry::KIND_TRANSFER_IN) {
                $entry->amount = $entry->amount - 1;
            }
        });

        $fault = $this->faultWith('does not add up', fn () => $this->move($this->yusuf, $this->next));
        $this->assertMatchesRegularExpression('/^The pair written for roster rows \d+ and \d+ does not add up: nothing was carried\.$/', $fault->getMessage(), 'two ids and no amount');
        $this->assertSame(['earned:4127'], $this->ledger($this->yusuf), 'and nothing was carried');
        $this->assertNull($this->yusuf->fresh()->left_on);
    }

    #[Test]
    public function a_pair_is_logged_once_after_the_commit_with_ids_only_and_not_at_all_when_nothing_was_written(): void
    {
        $this->logLikeProduction();
        $this->credit($this->amira, 4127, '2026-10-04');

        // A move that carries nothing says nothing.
        $this->move($this->yusuf, $this->next);
        $this->assertSame([], $this->loggedLines('laravel.log', 'class_store.carried'));

        // A move that rolls back after the pair says nothing either.
        try {
            DB::transaction(function (): void {
                $new = $this->enrol($this->school, $this->next, $this->amiraContact);
                ClassStore::carryBalance($this->class, $this->amira, $this->next, $new, $this->admin, $this->today());
                $this->assertSame([], $this->loggedLines('laravel.log', 'class_store.carried'), 'nothing before the commit');

                throw new RuntimeException('the move fails after the pair');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->transferRows());
        $this->assertSame([], $this->loggedLines('laravel.log', 'class_store.carried'));

        $new = $this->move($this->amira, $this->next, $this->admin);

        // Production runs LOG_LEVEL=warning: an info line would not be here.
        $lines = $this->loggedLines('laravel.log', 'class_store.carried');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('.WARNING: class_store.carried', $lines[0]);
        $this->assertStringContainsString('"membership":'.$this->amira->id, $lines[0]);
        $this->assertStringContainsString('"new_membership":'.$new->id, $lines[0]);
        $this->assertStringContainsString('"by":'.$this->admin->id, $lines[0]);
        // That a pair was written, and nothing about how much.
        $this->assertStringNotContainsString('4127', $lines[0]);
        $this->assertStringNotContainsString('Amira', $lines[0]);
        $this->assertSame(['membership', 'new_membership', 'by'], array_keys(json_decode(substr($lines[0], strpos($lines[0], '{')), true, 512, JSON_THROW_ON_ERROR) ?? []));
    }

    #[Test]
    public function the_pair_is_written_whatever_the_schools_switch_says(): void
    {
        // Every organisation today: the store is off. A school that switched it off still holds rows.
        $this->assertFalse(SchoolSettings::classStore($this->school->fresh()));
        $this->credit($this->amira, 12, '2026-10-04');

        $new = $this->move($this->amira, $this->next);

        $this->assertSame(2, $this->transferRows());
        $this->assertSame(12, $this->balanceOf($new));
        $this->assertFalse(SchoolSettings::classStore($this->school->fresh()), 'and nothing here switches it on');
    }

    // ------------------------------------------------- the rule on two classes

    #[Test]
    public function whether_bucks_move_is_decided_from_two_classes_dates_and_a_day_alone(): void
    {
        $class = fn (?string $endsOn): Group => new Group(['ends_on' => $endsOn]);
        $today = '2026-06-16';

        DB::enableQueryLog();

        $this->assertSame(ClassStore::CARRY_MOVE, ClassStore::carryRule($class(null), $class(null), $today));
        $this->assertSame(ClassStore::CARRY_MOVE, ClassStore::carryRule($class('2026-12-18'), $class('2027-06-11'), $today));
        $this->assertSame(ClassStore::CARRY_FROM_ENDED, ClassStore::carryRule($class('2026-06-14'), $class(null), $today));
        $this->assertSame(ClassStore::CARRY_TO_ENDED, ClassStore::carryRule($class(null), $class('2026-06-14'), $today));
        $this->assertSame(ClassStore::CARRY_FROM_ENDED, ClassStore::carryRule($class('2026-06-14'), $class('2026-06-01'), $today), 'the class being left decides first');

        // A class that ends TODAY has not ended; one that ended yesterday has.
        $this->assertSame(ClassStore::CARRY_MOVE, ClassStore::carryRule($class('2026-06-16'), $class('2026-06-16'), $today));
        $this->assertSame(ClassStore::CARRY_FROM_ENDED, ClassStore::carryRule($class('2026-06-15'), $class(null), $today));
        $this->assertSame(ClassStore::CARRY_TO_ENDED, ClassStore::carryRule($class('2026-06-16'), $class('2026-06-15'), $today));

        $this->assertSame([], DB::getQueryLog(), 'it reads no ledger and nothing about any child');
        DB::disableQueryLog();

        $this->assertSame(['move', 'from_ended', 'to_ended'], [ClassStore::CARRY_MOVE, ClassStore::CARRY_FROM_ENDED, ClassStore::CARRY_TO_ENDED]);
    }

    #[Test]
    public function no_pair_leaves_a_class_that_has_ended_and_its_balance_is_written_off_there_when_due(): void
    {
        // The old class ended on Sunday 14 June. Its own end is a cutoff only its own sweep
        // evaluates, so a balance carried out in the days before the write-off would escape it.
        $this->freeze('2026-06-16 09:00');
        $this->credit($this->amira, 9, '2026-05-31');
        $this->credit($this->yusuf, 9, '2026-05-31');
        $this->class->forceFill(['ends_on' => '2026-06-14'])->save();

        $new = $this->move($this->amira, $this->next);

        $this->assertSame(0, $this->transferRows());
        $this->assertSame(9, $this->balanceOf($this->amira), 'it stays on the row the student left');
        $this->assertSame(0, $this->balanceOf($new));

        // Still inside the grace: nothing is taken yet, from the child who moved or the one who stayed.
        $this->freeze('2026-06-21 09:00');
        $this->sweep();
        $this->assertSame([9, 9], [$this->balanceOf($this->amira), $this->balanceOf($this->yusuf)]);

        // Due: the sweep's holders have no leaving-date filter, so the moved child's old row ends
        // with its class exactly as a classmate's does.
        $this->freeze('2026-06-22 09:00');
        $this->sweep();
        $this->assertSame([0, 0, 0], [$this->balanceOf($this->amira), $this->balanceOf($this->yusuf), $this->balanceOf($new)]);
        $this->assertSame(['earned:9', 'expired:-9'], $this->ledger($this->amira));
    }

    #[Test]
    public function no_pair_enters_a_class_that_has_ended_and_the_balance_stays_on_the_old_row(): void
    {
        // Reachable only with a back-dated day: the class being entered ended before today. Its
        // cutoff would otherwise be applied to an amount it never governed.
        $this->credit($this->amira, 9, '2026-10-04');
        $this->next->forceFill(['ends_on' => '2026-10-09'])->save();

        $new = $this->move($this->amira, $this->next);

        $this->assertSame(0, $this->transferRows());
        $this->assertSame(9, $this->balanceOf($this->amira));
        $this->assertSame(0, $this->balanceOf($new));

        // The ended class's sweep finds nothing of hers to take, now or once its cutoff is due.
        $this->freeze('2026-10-20 09:00');
        $this->sweep();
        $this->assertSame(9, $this->balanceOf($this->amira), 'still on the old row, in a class with no cutoff');
        $this->assertSame([], $this->ledger($new));
    }

    // ------------------------------------------------- the date a pair carries

    #[Test]
    public function a_carried_amount_counts_as_minted_in_the_newest_week_the_old_row_was_minted_for(): void
    {
        // The newest POSITIVE minted row decides: a clawback for a later week is not a mint.
        $this->credit($this->amira, 5, '2026-09-27');
        $this->credit($this->amira, 3, '2026-10-04');
        $this->plant($this->amira, PrizeLedgerEntry::KIND_ADJUSTED, 4, ['week_start' => '2026-09-20', 'week_basis' => 4]);
        $this->plant($this->amira, PrizeLedgerEntry::KIND_ADJUSTED, -1, ['week_start' => '2026-10-11', 'week_basis' => 0]);
        $this->plant($this->amira, PrizeLedgerEntry::KIND_ADJUSTED, 0, ['week_start' => '2026-10-11', 'week_basis' => 0]);
        $this->freeze('2026-10-19 09:00');

        $new = $this->move($this->amira, $this->next);

        $this->assertSame(11, $this->balanceOf($new));
        $this->assertSame('2026-10-04', $this->stored($new, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);

        // A row with only a positive `adjusted` row is dated by that row's week.
        $this->plant($this->yusuf, PrizeLedgerEntry::KIND_ADJUSTED, 2, ['week_start' => '2026-10-04', 'week_basis' => 2]);
        $yusufNew = $this->move($this->yusuf, $this->next);
        $this->assertSame('2026-10-04', $this->stored($yusufNew, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
    }

    #[Test]
    public function the_date_travels_down_a_chain_of_moves(): void
    {
        $third = $this->newClass('Grade 5');
        $this->credit($this->amira, 8, '2026-09-27');

        // Nothing is minted in the second class: the date its transfer_in brought is all it has.
        $second = $this->move($this->amira, $this->next);
        $last = $this->move($second, $third);

        $this->assertSame('transfer_out:'.$second->id.':1', $this->stored($second, PrizeLedgerEntry::KIND_TRANSFER_OUT)[0]['dedupe_key']);
        $this->assertSame('2026-09-27', $this->stored($second, PrizeLedgerEntry::KIND_TRANSFER_OUT)[0]['counts_from']);
        $this->assertSame('2026-09-27', $this->stored($last, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
        $this->assertSame(['earned:8', 'transfer_out:-8'], $this->ledger($this->amira), 'the first class is not touched by the move onward');

        // A newer week minted on the way wins over the date that was brought, and an older one does not.
        $this->credit($this->yusuf, 2, '2026-10-04');
        $yusufSecond = $this->move($this->yusuf, $this->next);
        $this->credit($yusufSecond, 1, '2026-09-20');
        $yusufLast = $this->move($yusufSecond, $third);
        $this->assertSame('2026-10-04', $this->stored($yusufLast, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);

        $this->freeze('2026-10-19 09:00');
        $this->credit($yusufLast, 1, '2026-10-11');
        $fourth = $this->newClass('Grade 6');
        $this->assertSame('2026-10-11', $this->stored($this->move($yusufLast, $fourth), PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
    }

    #[Test]
    public function the_date_is_never_after_the_day_the_move_ran_and_is_null_when_the_old_row_was_never_minted_for(): void
    {
        // The upper bound is the day the move is RUN: a cutoff after it must take the carried amount.
        $this->credit($this->amira, 5, '2026-10-18');
        $new = $this->move($this->amira, $this->next);
        $this->assertSame('2026-10-12', $this->stored($new, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
        $this->assertSame('2026-10-12', $this->stored($this->amira, PrizeLedgerEntry::KIND_TRANSFER_OUT)[0]['counts_from']);

        // Bucks with no week behind them (none can exist through the mint): no date to give.
        $this->credit($this->yusuf, 5);
        $yusufNew = $this->move($this->yusuf, $this->next);
        $this->assertNull($this->stored($yusufNew, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
        $this->assertNull($this->stored($this->yusuf, PrizeLedgerEntry::KIND_TRANSFER_OUT)[0]['counts_from']);
    }

    // ------------------------------------------------------------------ expiry

    /**
     * 14 June 2026 is the last day (a Sunday); its cutoff is the 15th and is due from the 22nd.
     * `class`: only the class being left has that end date, there is no school year and the class
     * being entered never ends. `year`: a school year ends that day and no class has an end date.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: int, 4: ?string}>
     */
    public static function juneMoves(): array
    {
        return [
            // what ends, the day of the move, is a pair written, what is left in the new class, where the write-off landed
            "the old class's end date, moved on the 10th: carried, and that class's later end no longer reaches it" => ['class', '2026-06-10', true, 9, null],
            "the old class's end date, moved on the 16th: the class had ended, so nothing is carried" => ['class', '2026-06-16', false, 0, 'old'],
            "the old class's end date, moved on the 30th: written off on the 22nd, nothing to carry" => ['class', '2026-06-30', false, 0, 'old'],
            'the school year, moved on the 10th: carried, then written off in the new class' => ['year', '2026-06-10', true, 0, 'new'],
            'the school year, moved on the 16th: carried, then written off in the new class' => ['year', '2026-06-16', true, 0, 'new'],
            'the school year, moved on the 30th: written off on the 22nd, nothing to carry' => ['year', '2026-06-30', false, 0, 'old'],
            // The owner's rule, on its three days: nothing survives the year, whichever side of the last day the move is on.
            'the school year, moved three days before the last day' => ['year', '2026-06-11', true, 0, 'new'],
            'the school year, moved three days after the last day' => ['year', '2026-06-17', true, 0, 'new'],
            'the school year, moved ten days after the last day' => ['year', '2026-06-24', false, 0, 'old'],
        ];
    }

    #[Test]
    #[DataProvider('juneMoves')]
    public function a_year_end_takes_a_carried_balance_and_the_old_classs_own_end_does_not_follow_it(string $ends, string $movedOn, bool $pair, int $kept, ?string $writtenOffOn): void
    {
        $this->freeze('2026-06-08 09:00');
        $this->credit($this->amira, 9, '2026-05-31');

        if ($ends === 'class') {
            $this->class->forceFill(['ends_on' => '2026-06-14'])->save();
        } else {
            $this->schoolYear('2025-09-07', '2026-06-14');
        }

        $new = null;

        // Every day from the 8th of June to the 10th of July: the move on its day, then the sweep.
        for ($day = CarbonImmutable::parse('2026-06-08'); $day->toDateString() <= '2026-07-10'; $day = $day->addDay()) {
            $this->freeze($day->toDateString().' 09:00');

            if ($day->toDateString() === $movedOn) {
                $new = $this->move($this->amira, $this->next);
            }

            $this->sweep();
        }

        $this->assertSame($pair ? 2 : 0, $this->transferRows());
        $this->assertSame($kept, $this->balanceOf($new));
        $this->assertSame(0, $this->balanceOf($this->amira), 'nothing is left on the old row either way');

        $expired = DB::table('prize_ledger_entries')->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->get();

        if ($writtenOffOn === null) {
            $this->assertCount(0, $expired, 'no cutoff of the new class reaches it');

            return;
        }

        $row = $writtenOffOn === 'old' ? $this->amira : $new;
        $this->assertCount(1, $expired, 'written off once, on one row');
        $this->assertSame((int) $row->id, (int) $expired[0]->group_membership_id);
        $this->assertSame(-9, (int) $expired[0]->amount);
        $this->assertSame('expired:'.$row->id.':2026-06-15:1', $expired[0]->dedupe_key);
        $this->assertSame('2026-06-22', substr((string) $expired[0]->occurred_at, 0, 10), 'on the first sweep after the grace, whenever the move was');
    }

    #[Test]
    public function a_move_after_the_years_write_off_carries_only_what_the_new_year_minted(): void
    {
        $this->schoolYear('2025-09-07', '2026-06-14');
        $this->freeze('2026-06-22 09:00');
        $this->credit($this->amira, 9, '2026-05-31');
        $this->sweep();
        $this->assertSame(0, $this->balanceOf($this->amira));

        // The week of 21 June closes on the 28th and is minted for the new year.
        $this->freeze('2026-07-01 09:00');
        $this->credit($this->amira, 4, '2026-06-21');
        $new = $this->move($this->amira, $this->next);

        foreach (range(1, 3) as $run) {
            $this->sweep();
            $this->assertSame(4, $this->balanceOf($new), 'the date it carries is on or after the cutoff, so it is the new year\'s');
        }

        $this->assertSame('2026-06-21', $this->stored($new, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);
    }

    #[Test]
    public function a_mid_year_move_is_not_written_off_by_last_years_cutoff_on_any_run_or_after_the_old_rows_set_is_purged(): void
    {
        // The fault being designed out: a transfer_in is not a minted row, so without a date of
        // its own the whole carried amount reads as "from before" every cutoff ever due, and an
        // ordinary October move in a school with one finished year on file is written off at
        // the next 40 past the hour.
        $this->schoolYear('2025-09-07', '2026-06-14');
        $this->credit($this->amira, 12, '2026-10-04');
        $this->assertContains('2026-06-15', BucksExpiry::cutoffs($this->class, SchoolCalendar::for((int) $this->school->id)), "last year's cutoff is on file and due");
        $this->sweep();
        $this->assertSame(12, $this->balanceOf($this->amira), 'settled on the old row: this October is after that cutoff');

        $new = $this->move($this->amira, $this->next);

        foreach (range(1, 10) as $run) {
            $this->sweep();

            if ($run === 1 || $run === 10) {
                $this->assertSame(12, $this->balanceOf($new), "kept on run {$run}");
            }
        }

        // The purge separates the pair: the old row left, sums to zero and is past its retention.
        $removed = PrizeLedgerEntry::purgeDueSets(now()->addDays(400)->toDateString());
        $this->assertSame(2, $removed);
        $this->assertSame([], $this->ledger($this->amira), 'the old row\'s whole set went, its transfer_out included');
        $this->assertSame(['transfer_in:12'], $this->ledger($new), 'the half that stays carries the protecting date itself');

        $this->sweep();
        $this->sweep();
        $this->assertSame(12, $this->balanceOf($new));
        $this->assertSame(0, DB::table('prize_ledger_entries')->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->count());
    }

    #[Test]
    public function the_purge_takes_the_old_rows_whole_set_and_leaves_the_half_that_stays(): void
    {
        // The purge consults no kind, and nothing reads one half of a pair to explain the other.
        config(['groups.bucks.retention_days' => 30]);
        $this->credit($this->amira, 9, '2026-10-04');
        $this->redeem($this->amira, 4);
        $new = $this->move($this->amira, $this->next);
        $this->assertSame(['earned:9', 'redeemed:-4', 'transfer_out:-5'], $this->ledger($this->amira));

        // After a pair the old row has a leaving date and sums to exactly zero, so its whole set
        // goes together once its newest row, the transfer_out, is due. A day early, nothing goes.
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets(now()->addDays(29)->toDateString()));
        $this->assertSame(3, PrizeLedgerEntry::purgeDueSets(now()->addDays(30)->toDateString()));
        $this->assertSame([], $this->ledger($this->amira));

        // The new row's set stays while the student is enrolled, however old it is, and after
        // they leave for as long as it is worth anything.
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets(now()->addDays(800)->toDateString()));
        $new->markLeftByStaff(null)->save();
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets(now()->addDays(800)->toDateString()));
        $this->assertSame(['transfer_in:5'], $this->ledger($new));

        // Worth nothing and belonging to nobody still here: then it goes too, as any set does.
        $this->plant($new, PrizeLedgerEntry::KIND_EXPIRED, -5);
        $this->assertSame(2, PrizeLedgerEntry::purgeDueSets(now()->addDays(800)->toDateString()));
        $this->assertSame(0, PrizeLedgerEntry::query()->count());
    }

    #[Test]
    public function a_cutoff_typed_after_the_move_and_dated_before_it_takes_the_carried_amount_whole_or_not_at_all(): void
    {
        // Amira's old row was last minted for the week of 27 September; Yusuf's for 4 October as well.
        $this->credit($this->amira, 5, '2026-09-27');
        $this->credit($this->yusuf, 5, '2026-09-27');
        $this->credit($this->yusuf, 3, '2026-10-04');
        $amira = $this->move($this->amira, $this->next);
        $yusuf = $this->move($this->yusuf, $this->next);

        // Afterwards the office adds a school year that ended on 3 October: cutoff the 4th, already due.
        $this->schoolYear('2025-09-07', '2026-10-03');
        $this->sweep();

        $this->assertSame(0, $this->balanceOf($amira), 'nothing on the old row was minted from that cutoff on: taken whole, as it would have been there');
        // The mixed state again: one date cannot split 5 from before and 3 from after, and it keeps the whole.
        $this->assertSame(8, $this->balanceOf($yusuf));

        // An end date typed later on the class that was LEFT does not reach what was carried.
        $this->class->forceFill(['ends_on' => '2026-10-02'])->save();
        $this->sweep();
        $this->assertSame(8, $this->balanceOf($yusuf));

        // One typed later on the class ENTERED is that class's own cutoff: it takes the carried
        // amount unless the date it carries is on or after it.
        $this->next->forceFill(['ends_on' => '2026-10-03'])->save();
        $this->sweep();
        $this->assertSame(8, $this->balanceOf($yusuf), 'cutoff the 4th: the amount counts from the 4th');

        $this->next->forceFill(['ends_on' => '2026-10-04'])->save();
        $this->sweep();
        $this->assertSame(0, $this->balanceOf($yusuf), 'cutoff the 5th: after the date it carries');
    }

    #[Test]
    public function the_mixed_state_keeps_the_whole_balance_and_a_return_to_that_row_ends_the_leniency(): void
    {
        // PINNED AS DESIGNED, AND AN OPEN ITEM (the mixed state, with the class-store rules'
        // owner): at the instant of the move the old row holds Bucks from BOTH sides of a cutoff
        // that is already due and not yet settled on it. The exact answer would keep the 3 and
        // lose the 5; one date keeps or loses the whole, and the rule keeps it. It errs towards a
        // child keeping Bucks and shows nobody a figure. Whoever makes the grace count from the
        // day a date was typed lengthens this state from an hour to days and must revisit it.
        foreach ([$this->amira, $this->yusuf] as $child) {
            $this->credit($child, 5, '2026-09-27');
            $this->credit($child, 3, '2026-10-04');
        }

        // The office types a year that ended on 3 October (cutoff the 4th, due since the 11th)
        // and moves Amira before the next sweep.
        $this->schoolYear('2025-09-07', '2026-10-03');
        $there = $this->move($this->amira, $this->next);
        $this->sweep();

        $this->assertSame(8, $this->balanceOf($there), 'the whole is kept');
        $this->assertSame(3, $this->balanceOf($this->yusuf), 'the classmate who stayed loses the old 5');

        // She spends 2 in the new class and is moved back. The transfer_out left the old row's
        // "later" short by d = 5, the part a sweep at the moment of the move would have taken.
        $this->redeem($there, 2);
        $this->move($there, $this->class);
        $this->assertSame(6, $this->balanceOf($this->amira));

        $this->sweep();
        $this->assertSame(1, $this->balanceOf($this->amira), 'the sweep takes min(what she holds there, d) = 5');
        $this->sweep();
        $this->assertSame(1, $this->balanceOf($this->amira), 'and no more on a later run');
        $this->assertSame(['earned:5', 'earned:3', 'transfer_out:-8', 'transfer_in:6', 'expired:-5'], $this->ledger($this->amira));
    }

    #[Test]
    public function a_return_to_a_row_left_in_the_mixed_state_never_loses_more_than_is_held_there(): void
    {
        $this->credit($this->amira, 5, '2026-09-27');
        $this->credit($this->amira, 3, '2026-10-04');
        $this->schoolYear('2025-09-07', '2026-10-03');
        $there = $this->move($this->amira, $this->next);

        // She comes back with 2: less than the 5 a sweep would have taken at the move.
        $this->redeem($there, 6);
        $this->move($there, $this->class);
        $this->sweep();
        $this->sweep();

        $this->assertSame(0, $this->balanceOf($this->amira), 'min(2, 5): what she holds, and never below zero');
        $this->assertSame(1, DB::table('prize_ledger_entries')->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->count());
    }

    #[Test]
    public function the_grace_is_never_more_than_seven_days_whatever_the_environment_says(): void
    {
        foreach ([30 => 7, 8 => 7, 7 => 7, 3 => 3, 0 => 0, -2 => 0] as $configured => $read) {
            config(['groups.bucks.expiry_grace_days' => $configured]);
            $this->assertSame($read, BucksExpiry::graceDays(), "configured {$configured}");
        }

        $this->assertSame(7, BucksExpiry::MAX_GRACE_DAYS);
        $this->assertStringContainsString('A value above 7 is read as 7', (string) file_get_contents(config_path('groups.php')));

        // And the sweep uses it: with 30 configured, a cutoff eight days old acts.
        config(['groups.bucks.expiry_grace_days' => 30]);
        $this->credit($this->amira, 9, '2026-09-27');
        $this->class->forceFill(['ends_on' => '2026-10-03'])->save();
        $this->sweep();

        $this->assertSame(0, $this->balanceOf($this->amira));
    }

    #[Test]
    public function a_week_cannot_be_minted_beyond_a_cutoff_that_is_still_inside_its_grace_so_a_move_in_those_days_rescues_nothing(): void
    {
        // The reason for the bound above, with the real mint: the year ends on Saturday 10
        // October (cutoff the 11th, due from the 18th). On the 17th, the last day of the grace,
        // the week that starts on the cutoff has not closed, so nothing on the old row can be
        // dated on or after it, and a balance moved that day is written off with the year.
        $this->schoolYear('2025-09-07', '2026-10-10');
        $this->mintingFrom('2026-10-04');
        $this->awardAt('2026-10-06 10:00', $this->amira, 9);
        $this->awardAt('2026-10-13 10:00', $this->amira, 4);

        $this->freeze('2026-10-17 23:30');
        $this->mint();
        $this->assertSame(['earned:9'], $this->ledger($this->amira), 'the week of the 11th is still in progress');

        $new = $this->move($this->amira, $this->next);
        $this->assertSame('2026-10-04', $this->stored($new, PrizeLedgerEntry::KIND_TRANSFER_IN)[0]['counts_from']);

        $this->freeze('2026-10-18 00:40');
        $this->sweep();
        $this->assertSame(0, $this->balanceOf($new), 'the old year\'s Bucks end with the year, in the class she was moved to');
    }

    #[Test]
    public function a_write_off_given_back_after_a_move_is_swept_on_the_old_row_as_a_classmates_is(): void
    {
        // The year was typed as ending on Saturday 26 September (cutoff the 27th).
        $year = $this->schoolYear('2025-09-07', '2026-09-26');
        $this->freeze('2026-10-05 09:00');

        foreach ([$this->amira, $this->yusuf] as $child) {
            $this->credit($child, 6, '2026-09-20');
        }

        $this->sweep();
        $this->assertSame([0, 0], [$this->balanceOf($this->amira), $this->balanceOf($this->yusuf)]);

        // New Bucks for the week of 4 October, and Amira is moved with hers.
        $this->freeze('2026-10-12 09:00');

        foreach ([$this->amira, $this->yusuf] as $child) {
            $this->credit($child, 6, '2026-10-04');
        }

        $new = $this->move($this->amira, $this->next);

        // A week later the office corrects the last day to 3 October: the cutoff of the 27th no
        // longer exists, so its write-offs are given back, and the cutoff of the 4th (due) acts.
        $this->freeze('2026-10-19 09:00');
        app(TenantContext::class)->runWithout(fn () => $year->forceFill(['last_day' => '2026-10-03'])->save());
        $this->sweep();

        $this->assertSame(['earned:6', 'expired:-6', 'earned:6', 'reversal:6', 'expired:-6'], $this->ledger($this->yusuf));
        // Without the transfer_out in "later", the earned 6 that left would still count as held
        // from the cutoff on, the sweep would take nothing, and she would keep the given-back 6
        // here on top of the 6 she carried: Bucks every classmate lost.
        $this->assertSame(['earned:6', 'expired:-6', 'earned:6', 'transfer_out:-6', 'reversal:6', 'expired:-6'], $this->ledger($this->amira));

        $this->assertSame(6, $this->balanceOf($this->yusuf));
        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame(6, $this->balanceOf($new), 'her 6 is on the new row, and the cutoff of the 4th leaves it there');

        $this->sweep();
        $this->assertSame([6, 0, 6], [$this->balanceOf($this->yusuf), $this->balanceOf($this->amira), $this->balanceOf($new)], 'settled');
    }

    #[Test]
    public function a_write_off_given_back_after_a_move_is_exact_where_bucks_were_spent(): void
    {
        $year = $this->schoolYear('2025-09-07', '2026-09-26');
        $this->freeze('2026-10-05 09:00');

        foreach ([$this->amira, $this->yusuf] as $child) {
            $this->credit($child, 5, '2026-09-20');
        }

        $this->sweep();

        // Each earns 10 after the cutoff and spends 4. Amira is moved with her 6.
        $this->freeze('2026-10-12 09:00');

        foreach ([$this->amira, $this->yusuf] as $child) {
            $this->credit($child, 10, '2026-10-04');
            $this->redeem($child, 4);
        }

        $new = $this->move($this->amira, $this->next);

        $this->freeze('2026-10-19 09:00');
        app(TenantContext::class)->runWithout(fn () => $year->forceFill(['last_day' => '2026-10-03'])->save());
        $this->sweep();

        // The classmate: 10 - 4 + 5 given back = 11, of which 10 is from the cutoff on: loses 1.
        $this->assertSame(10, $this->balanceOf($this->yusuf));
        // Her old row: 5 given back, of which 10 - 6 = 4 counts as from the cutoff on: loses 1 too.
        $this->assertSame(4, $this->balanceOf($this->amira));
        $this->assertSame(6, $this->balanceOf($new));
        $this->assertSame(-1, (int) DB::table('prize_ledger_entries')->where('group_membership_id', $this->amira->id)->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->orderByDesc('id')->value('amount'));
    }

    #[Test]
    public function a_write_off_given_back_after_a_move_whose_date_was_removed_stays_on_the_old_row(): void
    {
        // THE STATED COST: when the office corrects a typed date, every classmate gets the
        // write-off back where they can spend it. A moved child gets it on the row they left,
        // where nothing can be spent. No second transfer is chained after it.
        $this->freeze('2026-10-05 09:00');
        $this->class->forceFill(['ends_on' => '2026-09-26'])->save();

        foreach ([$this->amira, $this->yusuf] as $child) {
            $this->credit($child, 6, '2026-09-20');
        }

        $this->sweep();
        $this->assertSame(0, $this->balanceOf($this->amira));

        // The end date was a mistake and is removed. Amira earns again and is moved before the sweep.
        $this->freeze('2026-10-12 09:00');
        $this->class->forceFill(['ends_on' => null])->save();
        $this->credit($this->amira, 2, '2026-10-04');
        $new = $this->move($this->amira, $this->next);
        $this->assertSame(2, $this->balanceOf($new));

        $this->sweep();
        $this->sweep();

        $this->assertSame(['earned:6', 'expired:-6', 'earned:2', 'transfer_out:-2', 'reversal:6'], $this->ledger($this->amira));
        $this->assertSame(6, $this->balanceOf($this->amira), 'on a row the student has left');
        $this->assertSame(['transfer_in:2'], $this->ledger($new), 'it is not chased into the new class');
        $this->assertSame(6, $this->balanceOf($this->yusuf));

        // Nothing can be spent from it, and the sentence no longer says where her Bucks are.
        $refusal = $this->refusedWith('student_left', fn () => ClassStore::redeem($this->class, $this->amira->fresh(), $this->prize(['cost_bucks' => 1]), $this->teacher));
        $this->assertSame('That student has left this class, so nothing can be spent for them here.', $refusal->getMessage());

        // And it is not purged: a set is removed only when it is worth nothing.
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets(now()->addDays(800)->toDateString()));
        $this->assertSame(6, $this->balanceOf($this->amira));
    }

    #[Test]
    public function before_the_column_exists_the_sweep_does_not_name_it(): void
    {
        // bin/deploy serves the new code before it migrates. Without the column there can be no
        // transfer row, and the hourly sweep must go on exactly as it did.
        $this->credit($this->amira, 9, '2026-09-27');
        $this->credit($this->amira, 2, '2026-10-04');
        $this->credit($this->yusuf, 9, '2026-09-27');
        $this->class->forceFill(['ends_on' => '2026-10-03'])->save();
        $this->storeOn();

        // SQLite reads a double-quoted name that is no column as a string, so an unknown column
        // in a comparison is not an error here as it is on MySQL: the statements are read instead.
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $named = function () use (&$statements): array {
            return array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'counts_from')));
        };

        // The control: with the column there, the sum that decides a write-off does name it.
        $this->assertSame(0, Artisan::call('bucks:expire', ['--dry-run' => true]));
        $this->assertNotSame([], $named(), 'the premise: the sweep reads counts_from once the column exists');

        Schema::table('prize_ledger_entries', fn ($table) => $table->dropColumn('counts_from'));
        ClassStore::forgetCarryReady();
        $this->assertFalse(ClassStore::carryReady());
        $statements = [];

        $this->assertSame(0, Artisan::call('bucks:expire'));
        $this->assertStringContainsString('0 failure(s)', Artisan::output());
        $this->assertSame([], $named(), 'no statement of the sweep names a column that is not there yet');
        $this->assertSame(['earned:9', 'earned:2', 'expired:-9'], $this->ledger($this->amira));
        $this->assertSame(0, $this->balanceOf($this->yusuf));
    }

    // -------------------------------------- the mint, once a student was moved

    // -------------------------------------- the undo, once a student was moved

    // ---------------------------------------------------- what the office reads

    /** Every number anywhere in a decoded answer. */
    private function figures(array $payload): array
    {
        $out = [];
        array_walk_recursive($payload, function ($value, $key) use (&$out): void {
            if ((is_int($value) || is_float($value)) && ! in_array($key, ['group_id', 'min_class_size', 'weeks', 'requested_weeks', 'points_per_buck', 'suppressed_classes'], true)) {
                $out[] = $value;
            }
        });

        return $out;
    }

    /**
     * The test of the reconciliation as the class-store rules wrote it: no figure in any answer,
     * and no sum or difference of two figures taken from one answer or across a before and
     * after, equals one child's balance.
     *
     * @param  list<array<string,mixed>>  $answers
     */
    private function assertNoFigureGivesAway(int $balance, array $answers): void
    {
        $all = [];

        foreach ($answers as $answer) {
            $all = [...$all, ...$this->figures($answer)];
        }

        $this->assertNotContains($balance, $all, 'a figure equals the child\'s balance');

        foreach ($all as $a) {
            foreach ($all as $b) {
                $this->assertNotSame($balance, abs($a - $b), "a difference ({$a} and {$b}) equals the child's balance");
                $this->assertNotSame($balance, $a + $b, "a sum ({$a} and {$b}) equals the child's balance");
            }
        }
    }

    #[Test]
    public function the_new_classs_teacher_and_the_family_read_the_balance_and_none_of_the_old_classs_history(): void
    {
        $this->storeOn();
        $this->credit($this->amira, 10, '2026-10-04');
        ClassStore::redeem($this->class, $this->amira, $this->prize(['title' => 'Kite', 'cost_bucks' => 4]), $this->teacher, null, 'a note for the old class only');
        $new = $this->move($this->amira, $this->next, $this->admin);

        $this->actAs($this->teacherOf($this->school, $this->next));
        $res = $this->getJson($this->teacherUrl('/members/'.$new->id.'/bucks', null, $this->next))->assertOk();

        $this->assertSame(6, $res->json('meta.balance'));
        $rows = $res->json('data.data');
        $this->assertCount(1, $rows, 'one line: what was brought');
        $this->assertSame(['transfer_in', 6], [$rows[0]['kind'], $rows[0]['amount']]);
        $this->assertFalse($rows[0]['reversible'], 'there is nothing to undo');

        foreach (['week_start', 'prize_id', 'prize_title', 'prize_cost', 'reverses_entry_id', 'breakdown', 'note'] as $empty) {
            $this->assertNull($rows[0][$empty], $empty);
        }

        foreach (['Kite', 'a note for the old class', 'counts_from', 'dedupe', 'transfer_out'] as $leak) {
            $this->assertStringNotContainsString($leak, $res->getContent());
        }

        // The old class's teacher reads their own class's ledger: the line that says it left.
        $this->actAs($this->teacher);
        $old = $this->getJson($this->teacherUrl('/members/'.$this->amira->id.'/bucks'))->assertOk();
        $this->assertSame(0, $old->json('meta.balance'));
        $this->assertSame(['transfer_out', 'redeemed', 'earned'], array_column($old->json('data.data'), 'kind'));
        $this->assertFalse($old->json('data.data.0.reversible'));
        $this->assertStringNotContainsString('counts_from', $old->getContent());

        // A parent: the narrow shape, unchanged, through their entry in the new class.
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->next->id, 'contact_id' => $this->amiraParent->id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $this->amiraContact->id,
        ]);
        $family = $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$new->id.'/bucks', null, $this->next))->assertOk();
        $this->assertSame(6, $family->json('meta.balance'));
        $this->assertSame(['id', 'kind', 'amount', 'week_start', 'prize_title', 'occurred_at', 'is_reversed'], array_keys($family->json('data.data.0')));
        $this->assertSame(['transfer_in', 6, null, null], array_slice(array_values($family->json('data.data.0')), 1, 4));
    }

    #[Test]
    public function the_records_export_prints_the_two_rows_as_they_are_and_has_no_column_for_the_date(): void
    {
        $this->credit($this->amira, 7, '2026-10-04');
        $new = $this->move($this->amira, $this->next);

        $this->actAs($this->admin);
        $res = $this->get($this->adminUrl('/records/export?dataset=bucks_ledger'))->assertOk();
        ob_start();
        $res->sendContent();
        $csv = (string) ob_get_clean();
        $rows = array_map('str_getcsv', array_filter(explode("\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv))));

        // The header is the one the export has always written: this change adds nothing to it.
        $this->assertSame(['Entry id', 'Class id', 'Membership id', 'Kind', 'Amount', 'Week start', 'Prize', 'Corrects entry id', 'Occurred at'], $rows[0]);
        $this->assertCount(4, $rows);
        $this->assertSame([(string) $this->class->id, (string) $this->amira->id, 'transfer_out', '-7', '', '', ''], array_slice($rows[2], 1, 7));
        $this->assertSame([(string) $this->next->id, (string) $new->id, 'transfer_in', '7', '', '', ''], array_slice($rows[3], 1, 7));
        $this->assertStringNotContainsString('counts_from', $csv);
        $this->assertStringNotContainsString('Counts from', $csv);
    }

    // ------------------------------------------ the two readers, and the command

    #[Test]
    public function the_two_readers_are_about_a_schema_and_a_school_and_never_about_a_child(): void
    {
        // Neither can be asked about one roster row, so nothing built on them can differ child by child.
        $this->assertSame([], (new ReflectionMethod(ClassStore::class, 'carryReady'))->getParameters());
        $this->assertSame(['masjidId'], array_map(fn ($p): string => $p->getName(), (new ReflectionMethod(ClassStore::class, 'schoolHoldsRows'))->getParameters()));

        $other = $this->newSchool('Elsewhere');
        $this->assertFalse(ClassStore::schoolHoldsRows((int) $this->school->id));

        $this->credit($this->amira, 1);
        $this->assertTrue(ClassStore::schoolHoldsRows((int) $this->school->id), 'whatever the switch says: the store is off here');
        $this->assertFalse(ClassStore::schoolHoldsRows((int) $other->id), "another school's rows are not this school's");

        // The column: there, remembered; gone, "not yet"; a question that cannot be answered, "not yet".
        $this->assertTrue(ClassStore::carryReady());
        Schema::table('prize_ledger_entries', fn ($table) => $table->dropColumn('counts_from'));
        $this->assertTrue(ClassStore::carryReady(), 'a column that exists is remembered for the life of the process');
        ClassStore::forgetCarryReady();
        $this->assertFalse(ClassStore::carryReady());

        // A missing column is asked about again after a short while, not on every call.
        Schema::table('prize_ledger_entries', fn ($table) => $table->date('counts_from')->nullable());
        $this->assertFalse(ClassStore::carryReady());
        $this->freeze('2026-10-12 09:00:31');
        $this->assertTrue(ClassStore::carryReady());

        ClassStore::forgetCarryReady();
        Schema::shouldReceive('hasColumn')->once()->andThrow(new RuntimeException('the database is away'));
        $this->assertFalse(ClassStore::carryReady());
    }
}
