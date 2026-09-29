<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\MasjidPointsSetting;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoreFixture;
use Tests\TestCase;

/**
 * T-003.4 (W6): the class store over HTTP, for the three people who touch it.
 *
 *   - the class's TEACHER runs the store (balances in roster order, prizes, redeem, reverse,
 *     cash-out to paper which is built and OFF, a class prize);
 *   - the OFFICE keeps the school-wide prize list and reads class totals, and a SuperAdmin
 *     alone sets the rate, the paper switch and the start day;
 *   - a PARENT reads their own child's balance and history, and can do nothing else.
 *
 * Who may READ a balance at all is ClassStorePrivacyTest; the ledger's own rules are
 * ClassStoreLedgerTest. What is pinned here is the wiring: the gates, the shapes, the status
 * codes, and that a school without the store is untouched.
 */
class ClassStoreApiTest extends TestCase
{
    use BuildsClassStoreFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreSchools();
        $this->storeOn();
        $this->actAs($this->teacher);
    }

    protected function tearDown(): void
    {
        $this->thaw();

        parent::tearDown();
    }

    private function paperOn(): void
    {
        MasjidPointsSetting::withoutMasjidScope()->updateOrCreate(['masjid_id' => $this->school->id], ['paper_bucks_enabled' => true]);
    }

    private function redeemUrl(GroupMembership $m): string
    {
        return $this->teacherUrl('/members/'.$m->id.'/prizes/redeem');
    }

    // ------------------------------------------------------------ the switch

    #[Test]
    public function a_school_without_the_store_answers_403_to_every_store_route_and_its_payloads_are_unchanged(): void
    {
        $this->credit($this->amira, 10);
        $prize = $this->prize();
        $withStore = $this->getJson($this->teacherUrl(''))->assertOk();
        $this->assertTrue($withStore->json('data.class_store'), 'the screen learns the store is on from the class payload');

        $this->storeOn(null, false);

        $reads = ['/bucks', '/bucks/handout', '/prizes', '/members/'.$this->amira->id.'/bucks'];
        foreach ($reads as $path) {
            $this->getJson($this->teacherUrl($path))->assertForbidden();
        }

        $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $prize->id])->assertForbidden();
        $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/cash-out'), ['amount' => 1])->assertForbidden();
        $this->postJson($this->teacherUrl('/prize-entries/1/reverse'))->assertForbidden();
        $this->postJson($this->teacherUrl('/prizes'), ['title' => 'X', 'cost_bucks' => 1])->assertForbidden();
        $this->putJson($this->teacherUrl('/prizes/'.$prize->id), ['cost_bucks' => 1])->assertForbidden();

        // The class payload is exactly what it was before the store existed: no key at all.
        $off = $this->getJson($this->teacherUrl(''))->assertOk();
        $this->assertArrayNotHasKey('class_store', $off->json('data'));
        $this->assertSame(array_diff(array_keys($withStore->json('data')), ['class_store']), array_keys($off->json('data')));

        // Nothing was written by any refused request.
        $this->assertSame(1, PrizeLedgerEntry::query()->count());
        $this->assertSame(10, $this->balanceOf($this->amira));
    }

    #[Test]
    public function a_parents_class_payload_gains_the_flag_only_when_the_store_is_on(): void
    {
        $on = $this->asParent($this->amiraParent)->getJson($this->familyUrl(''))->assertOk();
        $this->assertTrue($on->json('data.class_store'));

        $this->storeOn(null, false);
        $off = $this->asParent($this->amiraParent)->getJson($this->familyUrl(''))->assertOk();
        $this->assertArrayNotHasKey('class_store', $off->json('data'));
    }

    // ------------------------------------------------------- the teacher reads

    #[Test]
    public function balances_come_in_roster_order_with_no_rank_and_no_class_wide_total(): void
    {
        // Yusuf has the MORE bucks, and still comes second: roster order, never sorted by points.
        $this->credit($this->amira, 3);
        $this->credit($this->yusuf, 40);

        $res = $this->getJson($this->teacherUrl('/bucks'))->assertOk();

        $this->assertSame([$this->amira->id, $this->yusuf->id], collect($res->json('data.students'))->pluck('membership_id')->all());
        $this->assertSame([3, 40], collect($res->json('data.students'))->pluck('balance')->all());
        $this->assertSame(['points_per_buck' => 1, 'paper_bucks_enabled' => false], $res->json('data.settings'));

        $body = $res->getContent();
        foreach (['rank', 'position', 'place', 'leaderboard', 'top', 'total_bucks', 'class_total', 'winner'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden, $body, "the store must not carry {$forbidden}");
        }
        $this->assertSame(['membership_id', 'grade_label', 'contact', 'balance'], array_keys($res->json('data.students.0')));
        $this->assertSame(['id', 'first_name', 'last_name', 'avatar'], array_keys($res->json('data.students.0.contact')));
        // Names only: a student's contact details and a guardian never appear.
        $this->assertStringNotContainsString($this->amiraParent->login_email, $body);
    }

    #[Test]
    public function a_students_history_is_newest_first_with_the_balance_and_what_can_still_be_corrected(): void
    {
        $this->credit($this->amira, 10);
        $spent = ClassStoreLedgerHelper::redeem($this, $this->amira, $this->prize(['title' => 'Kite', 'cost_bucks' => 4]));

        $res = $this->getJson($this->teacherUrl('/members/'.$this->amira->id.'/bucks'))->assertOk();

        $this->assertSame(6, $res->json('meta.balance'));
        $rows = $res->json('data.data');
        $this->assertSame([PrizeLedgerEntry::KIND_REDEEMED, PrizeLedgerEntry::KIND_EARNED], array_column($rows, 'kind'));
        $this->assertSame(-4, $rows[0]['amount']);
        $this->assertSame('Kite', $rows[0]['prize_title']);
        $this->assertTrue($rows[0]['reversible']);
        $this->assertFalse($rows[1]['reversible'], 'what a child earned is never reversed');

        $this->postJson($this->teacherUrl('/prize-entries/'.$spent->id.'/reverse'))->assertCreated();

        $after = $this->getJson($this->teacherUrl('/members/'.$this->amira->id.'/bucks'))->json('data.data');
        $byId = collect($after)->keyBy('id');
        $this->assertTrue($byId[$spent->id]['is_reversed']);
        $this->assertFalse($byId[$spent->id]['reversible'], 'already corrected once');
    }

    // ---------------------------------------------------------------- redeem

    #[Test]
    public function a_teacher_redeems_a_prize_for_a_student_and_gets_the_new_balance_back(): void
    {
        $this->credit($this->amira, 12);
        $prize = $this->prize(['title' => 'Sticker', 'cost_bucks' => 5, 'stock' => 2]);

        $res = $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $prize->id, 'note' => 'helped a friend', 'request_id' => 'click-aaaa-0001'])
            ->assertCreated();

        $this->assertSame(7, $res->json('data.balance'));
        $this->assertSame('redeemed', $res->json('data.entry.kind'));
        $this->assertSame(-5, $res->json('data.entry.amount'));
        $this->assertSame('helped a friend', $res->json('data.entry.note'));
        $this->assertSame(1, $res->json('data.prize.stock'), 'the shelf says how many are left');
        $this->assertFalse($res->json('data.replayed'));
        $this->assertSame($this->teacher->name, $res->json('data.entry.created_by.name'));

        // The same click again is a replay: 200, no second deduction.
        $again = $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $prize->id, 'request_id' => 'click-aaaa-0001'])->assertOk();
        $this->assertTrue($again->json('data.replayed'));
        $this->assertSame(7, $again->json('data.balance'));
        $this->assertSame(1, $prize->fresh()->stock);
    }

    #[Test]
    public function a_redemption_works_in_the_encoding_the_browser_actually_sends(): void
    {
        // The SPA posts form-encoded, where every number is a string.
        $this->credit($this->amira, 12);
        $prize = $this->prize(['cost_bucks' => 5]);

        $this->post($this->redeemUrl($this->amira), ['prize_id' => (string) $prize->id, 'request_id' => 'form-click-0001'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.balance', 7);
    }

    #[Test]
    public function every_refusal_says_why_and_writes_nothing(): void
    {
        $this->credit($this->amira, 3);
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $theirs = $this->prize(['title' => 'Theirs', 'group_id' => $other->id]);
        $dear = $this->prize(['title' => 'Dear', 'cost_bucks' => 9]);
        $gone = $this->prize(['title' => 'Gone', 'stock' => 0, 'cost_bucks' => 1]);
        $retired = $this->prize(['title' => 'Retired', 'is_active' => false, 'cost_bucks' => 1]);
        $foreignSchool = $this->newSchool('Elsewhere');
        $foreign = $this->prize(['title' => 'Foreign', 'masjid_id' => $foreignSchool->id], $foreignSchool);
        $rows = PrizeLedgerEntry::query()->count();

        foreach ([
            [$dear, 'not_enough_bucks'],
            [$theirs, 'prize_other_class'],
            [$gone, 'out_of_stock'],
            [$retired, 'prize_retired'],
            [$foreign, 'prize_unknown'],
        ] as [$prize, $reason]) {
            $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $prize->id])
                ->assertStatus(422)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('reason', $reason);
        }

        $this->postJson($this->redeemUrl($this->amira), ['prize_id' => 999999])->assertStatus(422)->assertJsonPath('reason', 'prize_unknown');
        $this->postJson($this->redeemUrl($this->amira), [])->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $dear->id, 'request_id' => 'bad id with spaces'])->assertStatus(422);

        $this->assertSame($rows, PrizeLedgerEntry::query()->count());
        $this->assertSame(3, $this->balanceOf($this->amira));
    }

    #[Test]
    public function a_student_of_another_class_or_school_is_a_404_and_a_teacher_of_another_class_is_refused(): void
    {
        $prize = $this->prize();
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $stranger = $this->enrol($this->school, $other, Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]));
        $this->credit($stranger, 20);

        // A student of a class this teacher leads in name only.
        $this->postJson($this->redeemUrl($stranger), ['prize_id' => $prize->id])->assertNotFound();
        $this->getJson($this->teacherUrl('/members/'.$stranger->id.'/bucks'))->assertNotFound();

        // The other class's teacher may not run THIS class's store.
        $colleague = $this->teacherOf($this->school, $other);
        $this->actAs($colleague);
        $this->getJson($this->teacherUrl('/bucks'))->assertForbidden();
        $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $prize->id])->assertForbidden();
        $this->getJson($this->teacherUrl('/bucks', null, $other))->assertOk();

        $this->assertSame(20, $this->balanceOf($stranger));
    }

    #[Test]
    public function a_co_teacher_of_the_same_class_runs_the_same_store(): void
    {
        $this->credit($this->amira, 10);
        $co = $this->teacherOf($this->school, $this->class);
        $this->actAs($co);

        $this->postJson($this->redeemUrl($this->amira), ['prize_id' => $this->prize(['cost_bucks' => 2])->id])->assertCreated()
            ->assertJsonPath('data.balance', 8)
            ->assertJsonPath('data.entry.created_by.id', $co->id);
    }

    // --------------------------------------------------------------- reverse

    #[Test]
    public function a_reversal_is_a_new_entry_once_and_is_refused_for_what_cannot_be_undone(): void
    {
        $earned = $this->credit($this->amira, 10);
        $spent = ClassStoreLedgerHelper::redeem($this, $this->amira, $this->prize(['cost_bucks' => 4, 'stock' => 3]));

        $first = $this->postJson($this->teacherUrl('/prize-entries/'.$spent->id.'/reverse'), ['note' => 'wrong child'])->assertCreated();
        $this->assertSame(10, $first->json('data.balance'));
        $this->assertSame('reversal', $first->json('data.entry.kind'));
        $this->assertSame($spent->id, $first->json('data.entry.reverses_entry_id'));
        $this->assertSame(3, Prize::query()->find($spent->prize_id)->stock, 'the prize is back on the shelf');

        $again = $this->postJson($this->teacherUrl('/prize-entries/'.$spent->id.'/reverse'))->assertOk();
        $this->assertTrue($again->json('data.replayed'));
        $this->assertSame(10, $again->json('data.balance'));

        $this->postJson($this->teacherUrl('/prize-entries/'.$earned->id.'/reverse'))->assertStatus(422)->assertJsonPath('reason', 'not_reversible');
        $this->postJson($this->teacherUrl('/prize-entries/999999/reverse'))->assertNotFound();
    }

    #[Test]
    public function an_entry_of_another_class_or_school_cannot_be_reversed_from_here(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $stranger = $this->enrol($this->school, $other, Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]));
        $this->credit($stranger, 10);
        $spent = ClassStoreLedgerHelper::redeem($this, $stranger, $this->prize(['cost_bucks' => 4]), $other);

        $this->postJson($this->teacherUrl('/prize-entries/'.$spent->id.'/reverse'))->assertNotFound();
        $this->assertSame(6, $this->balanceOf($stranger));
    }

    #[Test]
    public function there_is_no_route_that_edits_or_deletes_a_ledger_entry(): void
    {
        $this->credit($this->amira, 5);
        $spent = ClassStoreLedgerHelper::redeem($this, $this->amira, $this->prize(['cost_bucks' => 1]));

        foreach (['put', 'patch', 'delete'] as $verb) {
            foreach (['/prize-entries/'.$spent->id, '/prize-entries/'.$spent->id.'/reverse', '/bucks/'.$spent->id, '/members/'.$this->amira->id.'/bucks/'.$spent->id] as $path) {
                $status = $this->{$verb.'Json'}($this->teacherUrl($path))->getStatusCode();
                $this->assertContains($status, [404, 405], strtoupper($verb).' '.$path.' answered '.$status);
            }
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! preg_match('#/(prize-entries|bucks)(/|$)#', $route->uri())) {
                continue;
            }

            $this->assertSame([], array_values(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE'])), $route->uri());
        }

        $this->assertSame(-1, $spent->fresh()->amount);
    }

    // ----------------------------------------------------------------- paper

    #[Test]
    public function cash_out_answers_403_until_the_school_turns_paper_on_and_writes_nothing(): void
    {
        $this->credit($this->amira, 30);

        $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/cash-out'), ['amount' => 10])
            ->assertForbidden()->assertJsonPath('reason', 'paper_bucks_off');
        $this->getJson($this->teacherUrl('/bucks/handout'))->assertForbidden()->assertJsonPath('reason', 'paper_bucks_off');

        $this->assertSame(30, $this->balanceOf($this->amira));
        $this->assertFalse($this->getJson($this->teacherUrl('/bucks'))->json('data.settings.paper_bucks_enabled'));
    }

    #[Test]
    public function with_paper_on_a_cash_out_records_the_notes_and_the_handout_lists_them_until_reversed(): void
    {
        $this->paperOn();
        $this->credit($this->amira, 60);
        $this->credit($this->yusuf, 30);
        $this->freeze('2026-10-12 10:00');

        $one = $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/cash-out'), ['amount' => 47, 'request_id' => 'cashout-0001'])->assertCreated();
        $this->assertSame(13, $one->json('data.balance'));
        $this->assertSame(['20' => 2, '10' => 0, '5' => 1, '1' => 2], $one->json('data.entry.breakdown'));
        $two = $this->postJson($this->teacherUrl('/members/'.$this->yusuf->id.'/prizes/cash-out'), ['amount' => 25])->assertCreated();
        $this->assertTrue($this->getJson($this->teacherUrl('/bucks'))->json('data.settings.paper_bucks_enabled'));

        $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/cash-out'), ['amount' => 47, 'request_id' => 'cashout-0001'])
            ->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/cash-out'), ['amount' => 500])->assertStatus(422)->assertJsonPath('reason', 'not_enough_bucks');
        $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/cash-out'), ['amount' => 0])->assertStatus(422);

        $hand = $this->getJson($this->teacherUrl('/bucks/handout'))->assertOk();
        $this->assertSame('2026-10-12', $hand->json('data.date'));
        $this->assertSame([47, 25], collect($hand->json('data.entries'))->pluck('amount')->all());
        $this->assertSame(72, $hand->json('data.totals.amount'));
        $this->assertSame(['20' => 3, '10' => 0, '5' => 2, '1' => 2], $hand->json('data.totals.notes'), '47 = 20+20+5+1+1 and 25 = 20+5');

        // A wrong cash-out is reversed and leaves the hand-out.
        $this->postJson($this->teacherUrl('/prize-entries/'.$two->json('data.entry.id').'/reverse'))->assertCreated();
        $after = $this->getJson($this->teacherUrl('/bucks/handout'))->assertOk();
        $this->assertSame([47], collect($after->json('data.entries'))->pluck('amount')->all());
        $this->assertSame(47, $after->json('data.totals.amount'));

        // A day with none is empty, an impossible date is refused, and yesterday's is not today's.
        $this->getJson($this->teacherUrl('/bucks/handout?date=2026-10-11'))->assertOk()->assertJsonPath('data.totals.amount', 0);
        $this->getJson($this->teacherUrl('/bucks/handout?date=2026-13-45'))->assertStatus(422);
        $this->getJson($this->teacherUrl('/bucks/handout?date=tomorrow'))->assertStatus(422);
    }

    // ---------------------------------------------------- a class's own prizes

    #[Test]
    public function a_teacher_creates_edits_and_retires_their_classs_own_prize(): void
    {
        $created = $this->postJson($this->teacherUrl('/prizes'), ['title' => '  Bookmark ', 'description' => 'Laminated', 'cost_bucks' => 4, 'stock' => 10])
            ->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('Bookmark', $created->json('data.title'));
        $this->assertSame('class', $created->json('data.scope'));
        $this->assertTrue($created->json('data.editable'));

        $row = Prize::query()->findOrFail($id);
        $this->assertSame($this->class->id, $row->group_id);
        $this->assertSame($this->school->id, $row->masjid_id);
        $this->assertSame($this->teacher->id, $row->created_by_user_id);

        $this->putJson($this->teacherUrl('/prizes/'.$id), ['cost_bucks' => 6, 'stock' => 3])->assertOk()->assertJsonPath('data.cost_bucks', 6)->assertJsonPath('data.stock', 3);
        // Blank means unlimited.
        $this->putJson($this->teacherUrl('/prizes/'.$id), ['stock' => null])->assertOk()->assertJsonPath('data.stock', null)->assertJsonPath('data.in_stock', true);
        // Retire, in the form encoding the SPA sends ("false" is a string there).
        $this->put($this->teacherUrl('/prizes/'.$id), ['is_active' => 'false'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse(Prize::query()->find($id)->is_active);
        $this->assertNotNull(Prize::query()->find($id), 'retired, never deleted: the ledger names it');

        // The retired prize is off the redeemable shelf but still listed for the teacher to bring back.
        $shelf = collect($this->getJson($this->teacherUrl('/prizes'))->json('data'))->keyBy('id');
        $this->assertFalse($shelf[$id]['is_active']);
    }

    #[Test]
    public function a_form_encoded_blank_stock_means_unlimited(): void
    {
        $res = $this->post($this->teacherUrl('/prizes'), ['title' => 'Pencil', 'cost_bucks' => '3', 'stock' => '', 'description' => ''], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertNull($res->json('data.stock'));
        $this->assertNull(Prize::query()->find($res->json('data.id'))->stock);
        $this->assertSame(3, $res->json('data.cost_bucks'));
    }

    #[Test]
    public function a_prize_title_is_unique_on_its_own_list_without_regard_to_case_or_edge_spaces(): void
    {
        $this->postJson($this->teacherUrl('/prizes'), ['title' => 'Pencil', 'cost_bucks' => 3])->assertCreated();

        $this->postJson($this->teacherUrl('/prizes'), ['title' => ' pencil ', 'cost_bucks' => 3])->assertStatus(422)->assertJsonPath('status', 'failed')->assertJsonStructure(['data' => ['title']]);
        // The same title on ANOTHER class's list, and on the school-wide list, is a different prize.
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $this->prize(['title' => 'Pencil', 'group_id' => $other->id]);
        $this->prize(['title' => 'Pencil']);
        $this->postJson($this->teacherUrl('/prizes'), ['title' => 'Eraser', 'cost_bucks' => 3])->assertCreated();

        // Editing a prize to another's title is refused; keeping its own title is fine.
        $eraser = Prize::query()->where('title', 'Eraser')->firstOrFail();
        $this->putJson($this->teacherUrl('/prizes/'.$eraser->id), ['title' => 'PENCIL'])->assertStatus(422)->assertJsonStructure(['data' => ['title']]);
        $this->putJson($this->teacherUrl('/prizes/'.$eraser->id), ['title' => 'Eraser', 'cost_bucks' => 2])->assertOk();
    }

    #[Test]
    public function a_prize_is_validated_at_the_boundary(): void
    {
        foreach ([
            ['title' => '', 'cost_bucks' => 3],
            ['title' => str_repeat('x', 121), 'cost_bucks' => 3],
            ['title' => 'A', 'cost_bucks' => 0],
            ['title' => 'A', 'cost_bucks' => Prize::MAX_COST + 1],
            ['title' => 'A', 'cost_bucks' => 'five'],
            ['title' => 'A', 'cost_bucks' => 3, 'stock' => -1],
            ['title' => 'A', 'cost_bucks' => 3, 'stock' => Prize::MAX_STOCK + 1],
            ['title' => 'A', 'cost_bucks' => 3, 'description' => str_repeat('d', 501)],
            ['cost_bucks' => 3],
        ] as $bad) {
            $this->postJson($this->teacherUrl('/prizes'), $bad)->assertStatus(422)->assertJsonPath('status', 'failed');
        }

        $this->assertSame(0, Prize::query()->count());
    }

    #[Test]
    public function a_teacher_cannot_write_the_school_wide_list_or_another_classs_prize_and_the_body_cannot_choose_the_shelf(): void
    {
        $wide = $this->prize(['title' => 'School-wide']);
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $theirs = $this->prize(['title' => 'Theirs', 'group_id' => $other->id]);

        $this->putJson($this->teacherUrl('/prizes/'.$wide->id), ['cost_bucks' => 1])->assertNotFound();
        $this->putJson($this->teacherUrl('/prizes/'.$theirs->id), ['cost_bucks' => 1])->assertNotFound();
        $this->assertSame(5, $wide->fresh()->cost_bucks);
        $this->assertSame(5, $theirs->fresh()->cost_bucks);

        // A body naming a school-wide shelf, another class or another school changes nothing.
        $made = $this->postJson($this->teacherUrl('/prizes'), [
            'title' => 'Sneaky', 'cost_bucks' => 1, 'group_id' => null, 'masjid_id' => 999, 'created_by_user_id' => 999,
        ])->assertCreated();
        $row = Prize::query()->findOrFail($made->json('data.id'));
        $this->assertSame($this->class->id, $row->group_id);
        $this->assertSame($this->school->id, $row->masjid_id);
        $this->assertSame($this->teacher->id, $row->created_by_user_id);
    }

    #[Test]
    public function the_shelf_is_the_school_wide_list_plus_this_classs_own_and_marks_which_are_editable(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $this->prize(['title' => 'Wide', 'cost_bucks' => 2]);
        $this->prize(['title' => 'Mine', 'cost_bucks' => 1, 'group_id' => $this->class->id]);
        $this->prize(['title' => 'Theirs', 'group_id' => $other->id]);
        $foreign = $this->newSchool('Elsewhere');
        $this->prize(['title' => 'Foreign', 'masjid_id' => $foreign->id], $foreign);

        $shelf = collect($this->getJson($this->teacherUrl('/prizes'))->assertOk()->json('data'));

        $this->assertSame(['Mine', 'Wide'], $shelf->pluck('title')->all(), 'cheapest first, and only this class');
        $this->assertSame([true, false], $shelf->pluck('editable')->all());
        $this->assertSame(['class', 'school'], $shelf->pluck('scope')->all());
    }

    // ------------------------------------------------------------- the office

    #[Test]
    public function the_office_keeps_the_school_wide_list_and_never_a_classs_own(): void
    {
        $classPrize = $this->prize(['title' => 'Class only', 'group_id' => $this->class->id]);
        $this->actAs($this->admin);

        $made = $this->postJson($this->adminUrl('/prizes'), ['title' => 'Certificate', 'cost_bucks' => 8, 'stock' => 20, 'group_id' => $this->class->id])
            ->assertCreated();
        $row = Prize::query()->findOrFail($made->json('data.id'));
        $this->assertNull($row->group_id, 'the route decides the shelf, not the body');
        $this->assertSame('school', $made->json('data.scope'));
        $this->assertFalse($made->json('data.editable'), 'no teacher edits a school-wide prize');

        $list = collect($this->getJson($this->adminUrl('/prizes'))->assertOk()->json('data'));
        $this->assertSame(['Certificate'], $list->pluck('title')->all(), "a class's own prize is not on the office list");

        $this->putJson($this->adminUrl('/prizes/'.$row->id), ['stock' => null, 'cost_bucks' => 9])->assertOk()->assertJsonPath('data.stock', null);
        $this->put($this->adminUrl('/prizes/'.$row->id), ['is_active' => 'false'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->putJson($this->adminUrl('/prizes/'.$classPrize->id), ['cost_bucks' => 1])->assertNotFound();
        $this->postJson($this->adminUrl('/prizes'), ['title' => 'certificate', 'cost_bucks' => 1])->assertStatus(422);

        foreach ([$this->adminUrl('/prizes/'.$row->id), $this->adminUrl('/prize-reconciliation')] as $url) {
            $this->assertContains($this->deleteJson($url)->getStatusCode(), [404, 405], 'a prize is retired, never deleted');
        }
    }

    #[Test]
    public function the_office_routes_answer_403_for_a_school_without_the_store_and_are_closed_to_a_teacher(): void
    {
        $this->actAs($this->admin);
        $this->storeOn(null, false);

        $this->getJson($this->adminUrl('/prizes'))->assertForbidden();
        $this->postJson($this->adminUrl('/prizes'), ['title' => 'X', 'cost_bucks' => 1])->assertForbidden();
        $this->getJson($this->adminUrl('/prize-reconciliation'))->assertForbidden();
        $this->assertSame(0, Prize::query()->count());

        $this->storeOn();
        $this->actAs($this->teacher);
        $this->getJson($this->adminUrl('/prizes'))->assertUnauthorized();
        $this->getJson($this->adminUrl('/prize-reconciliation'))->assertUnauthorized();
    }

    #[Test]
    public function another_schools_administrator_reaches_none_of_this_schools_store(): void
    {
        $other = $this->newSchool('Elsewhere');
        $this->storeOn($other);
        $stranger = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $other->user_id = $stranger->id;
        $other->save();
        $this->prize(['title' => 'Ours']);
        $this->actAs($stranger);

        $this->getJson($this->adminUrl('/prizes'))->assertForbidden();
        $this->getJson($this->adminUrl('/prize-reconciliation'))->assertForbidden();
        $mine = $this->getJson($this->adminUrl('/prizes', $other))->assertOk();
        $this->assertSame([], $mine->json('data'), 'their own list is empty, not ours');
    }

    #[Test]
    public function the_reconciliation_reports_class_totals_and_never_names_a_child(): void
    {
        $this->credit($this->amira, 12, '2026-10-04');
        $this->credit($this->yusuf, 5, '2026-10-04');
        ClassStoreLedgerHelper::redeem($this, $this->amira, $this->prize(['cost_bucks' => 4]));

        $this->actAs($this->admin);
        $res = $this->getJson($this->adminUrl('/prize-reconciliation'))->assertOk();

        $class = collect($res->json('data.classes'))->firstWhere('group_id', $this->class->id);
        $this->assertSame('Grade 3', $class['name']);
        $this->assertSame(17, $class['minted']);
        $this->assertSame(4, $class['redeemed']);
        $this->assertSame(13, $class['outstanding']);
        $this->assertSame(2, $class['children_holding']);
        $this->assertSame(0, $class['negative_balances']);
        $this->assertSame(13, $res->json('data.totals.outstanding'));

        // CLASS TOTALS ONLY: no child's name, no roster id, no per-student list anywhere.
        $body = $res->getContent();
        foreach (['Amira', 'Yusuf', 'Karimi', 'membership', 'student', 'contact', (string) $this->amiraParent->login_email] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "the office view must not carry {$leak}");
        }

        $this->getJson($this->adminUrl('/prize-reconciliation?weeks=0'))->assertStatus(422);
        $this->getJson($this->adminUrl('/prize-reconciliation?weeks=abc'))->assertStatus(422);
        $this->getJson($this->adminUrl('/prize-reconciliation?weeks=4'))->assertOk();
    }

    #[Test]
    public function the_reconciliation_compares_the_ledger_with_the_points_it_came_from(): void
    {
        $this->freeze('2026-10-12 09:00');
        MasjidPointsSetting::withoutMasjidScope()->updateOrCreate(['masjid_id' => $this->school->id], ['bucks_from' => '2026-10-04']);
        $this->awardAt('2026-10-05 10:00', $this->amira, 5);
        $this->awardAt('2026-10-06 10:00', $this->yusuf, 3);
        $this->awardAt('2026-10-06 10:00', $this->yusuf, 2, \App\Models\BehaviorSkill::POLARITY_NEGATIVE);
        \Illuminate\Support\Facades\Artisan::call('bucks:mint');

        $this->actAs($this->admin);
        $data = $this->getJson($this->adminUrl('/prize-reconciliation'))->assertOk()->json('data');
        $class = collect($data['classes'])->firstWhere('group_id', $this->class->id);

        $this->assertSame(1, $data['window']['weeks']);
        $this->assertSame('2026-10-04', $data['window']['from']);
        $this->assertSame(8, $class['window_minted']);
        $this->assertSame(8, $class['window_expected']);
        $this->assertSame(0, $class['window_difference']);
        $this->assertSame(1, $class['weeks_converted']);

        // A week older than the adjustment window that changed afterwards is a DIFFERENCE the office can see.
        $this->freeze('2026-11-02 09:00');
        $this->awardAt('2026-10-06 12:00', $this->yusuf, 4);
        $data = $this->getJson($this->adminUrl('/prize-reconciliation?weeks=8'))->assertOk()->json('data');
        $class = collect($data['classes'])->firstWhere('group_id', $this->class->id);
        $this->assertSame(4, $class['window_difference'] * -1);
    }

    // ------------------------------------------------------ the SuperAdmin

    #[Test]
    public function only_a_superadmin_reads_or_sets_how_points_become_bucks(): void
    {
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $url = $this->adminUrl('/class-store-settings');

        foreach ([$this->admin, $this->teacher] as $not) {
            $this->actAs($not);
            $this->assertContains($this->getJson($url)->getStatusCode(), [401, 403]);
            $this->assertContains($this->putJson($url, ['points_per_buck' => 3])->getStatusCode(), [401, 403]);
        }
        $this->assertSame(1, \App\Support\ClassStoreSettings::for($this->school->id)['points_per_buck']);

        $this->actAs($super);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.points_per_buck', 1)
            ->assertJsonPath('data.paper_bucks_enabled', false)
            ->assertJsonPath('data.bucks_from', null)
            ->assertJsonPath('data.bucks_from_source', 'not started')
            ->assertJsonPath('data.timezone', self::ZONE);

        Log::spy();
        $this->putJson($url, ['points_per_buck' => 3])->assertOk()->assertJsonPath('data.points_per_buck', 3)->assertJsonPath('data.paper_bucks_enabled', false);
        // A field the request omits stays as it was; the form-encoded "true" is coerced.
        $this->put($url, ['paper_bucks_enabled' => 'true', 'bucks_from' => '2026-09-06'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.points_per_buck', 3)->assertJsonPath('data.paper_bucks_enabled', true)->assertJsonPath('data.bucks_from', '2026-09-06');
        $this->putJson($url, ['bucks_from' => null])->assertOk()->assertJsonPath('data.bucks_from', null);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'Class store settings changed' && $c['actor_user_id'] === $super->id)->atLeast()->times(3);

        foreach ([[], ['points_per_buck' => 0], ['points_per_buck' => 101], ['points_per_buck' => 'x'], ['bucks_from' => '2026-02-30'], ['bucks_from' => 'soon'], ['paper_bucks_enabled' => 'maybe']] as $bad) {
            $this->putJson($url, $bad)->assertStatus(422);
        }
        $this->assertSame(3, \App\Support\ClassStoreSettings::for($this->school->id)['points_per_buck']);
        $this->assertTrue(\App\Support\ClassStoreSettings::paperEnabled($this->school->id));
    }

    #[Test]
    public function the_rate_and_the_paper_switch_reach_the_teachers_screen(): void
    {
        MasjidPointsSetting::withoutMasjidScope()->updateOrCreate(['masjid_id' => $this->school->id], ['points_per_buck' => 4, 'paper_bucks_enabled' => true]);

        $this->assertSame(['points_per_buck' => 4, 'paper_bucks_enabled' => true], $this->getJson($this->teacherUrl('/bucks'))->json('data.settings'));
    }

    // ------------------------------------------------------------- a parent

    #[Test]
    public function a_parent_reads_their_own_childs_balance_and_history_in_the_narrow_shape(): void
    {
        $this->credit($this->amira, 10, '2026-10-04');
        ClassStoreLedgerHelper::redeem($this, $this->amira, $this->prize(['title' => 'Kite', 'cost_bucks' => 4]), null, 0, 'private note about Amira');
        MasjidPointsSetting::withoutMasjidScope()->updateOrCreate(['masjid_id' => $this->school->id], ['points_per_buck' => 2]);

        $res = $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk();

        $this->assertSame(6, $res->json('meta.balance'));
        $this->assertSame(2, $res->json('meta.points_per_buck'));
        $rows = $res->json('data.data');
        $this->assertSame(['redeemed', 'earned'], array_column($rows, 'kind'));
        $this->assertSame(['id', 'kind', 'amount', 'week_start', 'prize_title', 'occurred_at', 'is_reversed'], array_keys($rows[0]));
        $this->assertSame('Kite', $rows[0]['prize_title']);

        // The teacher's working stays the teacher's: no note, no author, no prize id, no breakdown.
        $body = $res->getContent();
        foreach (['private note', 'created_by', 'prize_id', 'breakdown', 'note', 'retained_until', 'dedupe'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    #[Test]
    public function a_parent_cannot_reach_another_familys_child_a_group_wide_view_or_any_write(): void
    {
        $this->credit($this->yusuf, 99);

        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->yusuf->id.'/bucks'))->assertForbidden();
        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/999999/bucks'))->assertNotFound();
        // No group-wide variant exists, so there is nothing to rank.
        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/bucks'))->assertNotFound();
        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/prizes'))->assertNotFound();

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->assertContains(
                $this->asParent($this->amiraParent)->{$verb.'Json'}($this->familyUrl('/members/'.$this->amira->id.'/bucks'), ['amount' => 5])->getStatusCode(),
                [404, 405],
                strtoupper($verb).' on the family bucks route'
            );
        }

        // And a parent has no way into the teacher's realm at all.
        $this->asParent($this->amiraParent)->getJson($this->teacherUrl('/bucks'))->assertUnauthorized();
        $this->asParent($this->amiraParent)->postJson($this->redeemUrl($this->amira), ['prize_id' => $this->prize()->id])->assertUnauthorized();
        $this->assertSame(99, $this->balanceOf($this->yusuf));
    }

    #[Test]
    public function a_school_without_the_store_shows_a_parent_nothing_and_the_family_write_list_is_unchanged(): void
    {
        $this->storeOn(null, false);

        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertForbidden();

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/family') && str_contains($route->uri(), 'bucks')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri().' must be a GET and nothing else');
            }
        }
    }

    #[Test]
    public function a_parent_reads_their_childs_record_without_consent_and_after_the_child_has_left(): void
    {
        // The edge has NO consent at all, exactly as the fixture leaves it.
        $this->assertNull(GroupMembership::query()->where('contact_id', $this->amiraParent->id)->value('consent_granted_at'));
        $this->credit($this->amira, 7);

        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk()->assertJsonPath('meta.balance', 7);

        $this->amira->forceFill(['left_on' => now()->subDay()->toDateString()])->save();
        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk()->assertJsonPath('meta.balance', 7);
    }

    #[Test]
    public function a_reversed_entry_is_marked_for_a_parent_too_and_the_balance_is_the_net(): void
    {
        $this->credit($this->amira, 10);
        $spent = ClassStoreLedgerHelper::redeem($this, $this->amira, $this->prize(['cost_bucks' => 4]));
        $this->postJson($this->teacherUrl('/prize-entries/'.$spent->id.'/reverse'))->assertCreated();

        $res = $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk();

        $this->assertSame(10, $res->json('meta.balance'));
        $rows = collect($res->json('data.data'))->keyBy('id');
        $this->assertTrue($rows[$spent->id]['is_reversed']);
        $this->assertSame(['reversal', 'redeemed', 'earned'], collect($res->json('data.data'))->pluck('kind')->all());
    }
}

/** Test glue: redeem straight through the service, as a teacher, for setup. */
final class ClassStoreLedgerHelper
{
    public static function redeem(TestCase $t, GroupMembership $m, Prize $p, ?Group $group = null, int $unused = 0, ?string $note = null): PrizeLedgerEntry
    {
        $group ??= Group::withoutGlobalScopes()->findOrFail($m->group_id);
        $teacher = User::query()->where('type', 'Teacher')->firstOrFail();

        return \App\Support\ClassStore::redeem($group, $m, $p, $teacher, null, $note)['entry'];
    }
}
