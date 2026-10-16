<?php

namespace Tests\Feature;

use App\Models\{Contact, FeePlan, Offering, TuitionChild, TuitionLine, TuitionSchedule, User};
use App\Services\FamilyLedger\{FamilyLedger, TuitionCharges};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

/**
 * A family saw one "amount owed" figure and could not tell what it was for. owed_items breaks it
 * down, and is only trustworthy if its parts always add up to the figure shown beside them.
 */
class FamilyTuitionOwedItemsTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;

    private function setupLedger(int $count = 1): array
    {
        $school = $this->makeMasjid();
        $school->forceFill(['stripe_account_id' => 'acct_practice', 'stripe_charges_enabled' => true, 'capability_overrides' => ['tuition_ledger' => true, 'school_calendar' => true, 'school_calendar_terms' => true]])->save();
        $year = $this->year($school); $class = $this->classroom($school); $children = []; $parent = null;
        for ($i = 0; $i < $count; $i++) {
            $child = $this->enrol($class, Contact::factory()->create(['masjid_id' => $school->id, 'first_name' => 'Pupil'.chr(65 + $i), 'last_name' => 'Practice']));
            $parent = $this->guardian($child, $parent); $children[] = $child->contact_id;
        }
        $actor = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15555550107', 'name' => 'Practice Clerk', 'email' => 'clerk@example.invalid']);
        $ledger = app(FamilyLedger::class);
        $account = $ledger->open($school, $children, [$parent->id], $actor->id);
        TuitionChild::where('account_id', $account->id)->update(['starts_on' => '2026-08-24']);
        $offering = Offering::factory()->create(['masjid_id' => $school->id, 'group_id' => $class->id]);
        $plan = FeePlan::create(['masjid_id' => $school->id, 'offering_id' => $offering->id, 'kind' => 'one_time', 'amount_minor' => 12345, 'currency' => 'usd', 'label' => 'Practice tuition', 'is_active' => true]);
        TuitionSchedule::create(['masjid_id' => $school->id, 'fee_plan_id' => $plan->id, 'school_year_id' => $year->id, 'cadence' => 'monthly', 'due_day' => 9, 'sibling_percent' => 10, 'group_ids' => [$class->id], 'grades' => [], 'created_by_user_id' => $actor->id]);
        return compact('school', 'year', 'class', 'children', 'parent', 'actor', 'account', 'ledger');
    }

    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }

    private function url(array $x): string { return "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}"; }

    private function sum(array $items): int { return array_sum(array_column($items, 'amount_minor')); }

    #[Test]
    public function owed_items_name_what_the_amount_owed_today_is_made_of_and_add_up_to_it(): void
    {
        $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC')); $x = $this->setupLedger(2);
        // The scheduled October tuition for two children: the second carries a sibling discount.
        app(TuitionCharges::class)->post($x['school']);
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC'));
        [$a, $b] = $x['children']; $actor = $x['actor']->id; $ledger = $x['ledger'];
        $ledger->office($x['account'], 'charge', 20000, '2026-09-01', 'staff note', $actor, child: $a, description: 'Registration');
        $ledger->office($x['account'], 'charge', 50000, '2026-09-15', 'staff note', $actor, child: $b, description: 'Books');
        // A part payment goes to the oldest charge first.
        $ledger->office($x['account'], 'office_payment', 5000, '2026-10-01', 'staff note', $actor, 'cash');
        // Neither a charge nor a payment dated after today is part of what is owed today.
        $ledger->office($x['account'], 'charge', 30000, '2026-11-01', 'staff note', $actor, child: $a, description: 'Field trip');
        $ledger->office($x['account'], 'office_payment', 7000, '2026-10-20', 'staff note', $actor, 'check');

        $this->family($x['parent']);
        $data = $this->getJson($this->url($x))->assertOk()->json('data');
        $this->assertSame(20000 + 50000 - 5000 + 12345 + 12345 - 1234, $data['balance_minor']);
        $this->assertSame($data['balance_minor'], $this->sum($data['owed_items']));
        $this->assertSame(['Registration', 'Books', 'Practice tuition', 'Practice tuition'], array_column($data['owed_items'], 'description'));
        $this->assertSame([15000, 50000], array_column(array_slice($data['owed_items'], 0, 2), 'amount_minor'));
        $this->assertEqualsCanonicalizing([12345, 11111], array_column(array_slice($data['owed_items'], 2), 'amount_minor'));
        $this->assertSame(['PupilA', 'PupilB'], array_column(array_slice($data['owed_items'], 0, 2), 'child'));
        $this->assertSame(['2026-09-01', '2026-09-15', '2026-10-09', '2026-10-09'], array_column($data['owed_items'], 'due_on'));
        $this->assertNotContains('Field trip', array_column($data['owed_items'], 'description'));
        foreach ($data['owed_items'] as $item) $this->assertSame(['line_id', 'kind', 'description', 'child', 'amount_minor', 'due_on'], array_keys($item));
        // The list endpoint carries the same breakdown.
        $index = $this->getJson("/api/family/masjids/{$x['school']->id}/tuition")->assertOk()->json('data.accounts.0');
        $this->assertSame($data['owed_items'], $index['owed_items']);
    }

    #[Test]
    public function a_payment_covering_whole_charges_leaves_only_the_unpaid_ones_and_voids_drop_out(): void
    {
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC')); $x = $this->setupLedger();
        $actor = $x['actor']->id; $ledger = $x['ledger'];
        $ledger->office($x['account'], 'charge', 20000, '2026-09-01', 'staff note', $actor, description: 'Registration');
        $wrong = $ledger->office($x['account'], 'charge', 99900, '2026-09-02', 'staff note', $actor, description: 'Entered by mistake');
        $ledger->office($x['account'], 'charge', 50000, '2026-09-15', 'staff note', $actor, description: 'Books');
        $ledger->void($wrong, 'typo', $actor);
        $ledger->office($x['account'], 'office_payment', 20000, '2026-10-01', 'staff note', $actor, 'cash');
        $bounced = $ledger->office($x['account'], 'office_payment', 10000, '2026-10-02', 'staff note', $actor, 'check');

        $this->assertSame([['Books', 40000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $ledger->owed($x['account'], '2026-10-15')));
        // A reversed payment puts its amount back on the charges it had paid.
        $ledger->void($bounced, 'returned check', $actor);
        $owed = $ledger->owed($x['account'], '2026-10-15');
        $this->assertSame([['Books', 50000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $owed));
        $this->assertSame($ledger->balance($x['account'], '2026-10-15'), $this->sum($owed));
        $this->assertNull($owed[0]['child_contact_id']);
    }

    private function card(array $x, string $kind, int $amount, string $date, string $key): void
    {
        $x['ledger']->append($x['account'], ['kind' => $kind, 'amount_minor' => $amount, 'effective_on' => $date, 'source' => 'stripe_webhook', 'recorded_by_label' => 'Verified Stripe webhook', 'method' => $kind === 'card_payment' ? 'stripe' : null, 'idempotency_key' => $key]);
    }

    #[Test]
    public function a_card_refund_reopens_the_oldest_charge_it_had_paid_instead_of_being_listed_as_owed(): void
    {
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC')); $x = $this->setupLedger();
        $actor = $x['actor']->id; $ledger = $x['ledger'];
        $ledger->office($x['account'], 'charge', 10000, '2026-09-01', 'staff note', $actor, description: 'September tuition');
        $october = $ledger->office($x['account'], 'charge', 10000, '2026-10-01', 'staff note', $actor, description: 'October tuition');
        $this->card($x, 'card_payment', -20000, '2026-10-02', 'card:practice');
        $this->card($x, 'refund', 3000, '2026-10-03', 'refund:practice:3000');

        // A part refund reopens part of the oldest charge the payment had covered, as due() does.
        $owed = $ledger->owed($x['account'], '2026-10-15');
        $this->assertSame([['October tuition', 'charge', 3000]], array_map(fn ($i) => [$i['description'], $i['kind'], $i['amount_minor']], $owed));
        $this->card($x, 'refund', 7000, '2026-10-04', 'refund:practice:10000');

        $this->family($x['parent']);
        $data = $this->getJson($this->url($x))->assertOk()->json('data');
        $this->assertSame(10000, $data['balance_minor']);
        $this->assertSame([['October tuition', 'charge', 10000]], array_map(fn ($i) => [$i['description'], $i['kind'], $i['amount_minor']], $data['owed_items']));
        $this->assertNotContains('refund', array_column($data['owed_items'], 'kind'));
        $this->assertSame([[$october->id, 10000]], array_map(fn ($d) => [$d['line_id'], $d['amount_minor']], $data['due']));
    }

    #[Test]
    public function voiding_a_charge_dated_after_today_does_not_lower_what_is_owed_today(): void
    {
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC')); $x = $this->setupLedger();
        $actor = $x['actor']->id; $ledger = $x['ledger'];
        $past = $ledger->office($x['account'], 'charge', 5000, '2026-09-10', 'staff note', $actor, description: 'Entered by mistake');
        $ledger->office($x['account'], 'charge', 20000, '2026-09-01', 'staff note', $actor, description: 'Registration');
        $trip = $ledger->office($x['account'], 'charge', 30000, '2026-11-01', 'staff note', $actor, description: 'Field trip');
        // The reversal takes the date of what it reverses when that is later than today, never earlier.
        $this->assertSame('2026-11-01', $ledger->void($trip, 'cancelled', $actor)->effective_on->toDateString());
        $this->assertSame('2026-10-15', $ledger->void($past, 'typo', $actor)->effective_on->toDateString());

        $this->family($x['parent']);
        $data = $this->getJson($this->url($x))->assertOk()->json('data');
        $this->assertSame(20000, $data['balance_minor']);
        $this->assertSame([['Registration', 20000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $data['owed_items']));

        // Once the trip's date arrives the charge and its reversal cancel out.
        $this->travelTo(now()->parse('2026-11-02 16:00:00 UTC'));
        $this->assertSame(20000, $ledger->balance($x['account'], '2026-11-02'));
        $this->assertSame([['Registration', 20000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $ledger->owed($x['account'], '2026-11-02')));
    }

    #[Test]
    public function an_older_reversal_dated_before_its_entry_is_still_named_so_the_parts_add_up(): void
    {
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC')); $x = $this->setupLedger();
        $actor = $x['actor']->id; $ledger = $x['ledger'];
        $ledger->office($x['account'], 'charge', 20000, '2026-09-01', 'staff note', $actor, description: 'Registration');
        $later = $ledger->office($x['account'], 'office_payment', 5000, '2026-10-20', 'staff note', $actor, 'check');
        // A row written before voids took their original's date: dated today, reversing a later payment.
        $ledger->append($x['account'], ['kind' => 'void', 'amount_minor' => 5000, 'effective_on' => '2026-10-15', 'original_line_id' => $later->id, 'source' => 'office', 'recorded_by_label' => 'Practice Clerk', 'idempotency_key' => 'void:'.$later->id]);

        $owed = $ledger->owed($x['account'], '2026-10-15');
        $this->assertSame(25000, $ledger->balance($x['account'], '2026-10-15'));
        $this->assertSame(25000, $this->sum($owed));
        $this->assertSame([['Registration', 20000], ['Correction', 5000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $owed));
    }

    #[Test]
    public function an_account_in_credit_or_settled_owes_nothing_item_by_item(): void
    {
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC')); $x = $this->setupLedger();
        $actor = $x['actor']->id; $ledger = $x['ledger'];
        $ledger->office($x['account'], 'charge', 20000, '2026-09-01', 'staff note', $actor, description: 'Registration');
        $ledger->office($x['account'], 'office_payment', 20000, '2026-09-05', 'staff note', $actor, 'cash');
        $this->family($x['parent']);
        $this->getJson($this->url($x))->assertOk()->assertJsonPath('data.balance_minor', 0)->assertJsonPath('data.owed_items', []);

        $ledger->office($x['account'], 'credit', 3000, '2026-10-01', 'staff note', $actor);
        $this->getJson($this->url($x))->assertOk()->assertJsonPath('data.balance_minor', -3000)->assertJsonPath('data.owed_items', []);

        // A new charge is met by the credit before anything is shown as owed.
        $ledger->office($x['account'], 'charge', 5000, '2026-10-10', 'staff note', $actor, description: 'Books');
        $data = $this->getJson($this->url($x))->assertOk()->json('data');
        $this->assertSame(2000, $data['balance_minor']);
        $this->assertSame([['Books', 2000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $data['owed_items']));
    }

    #[Test]
    public function owed_items_never_read_another_familys_or_another_schools_entries(): void
    {
        $this->travelTo(now()->parse('2026-10-15 16:00:00 UTC')); $x = $this->setupLedger();
        $actor = $x['actor']->id; $ledger = $x['ledger'];
        $ledger->office($x['account'], 'charge', 20000, '2026-09-01', 'staff note', $actor, description: 'Registration');

        // Another family at the same school, with its own account and charge.
        $otherChild = $this->enrol($x['class'], Contact::factory()->create(['masjid_id' => $x['school']->id, 'first_name' => 'PupilZ', 'last_name' => 'Elsewhere']));
        $otherParent = $this->guardian($otherChild, Contact::factory()->create(['masjid_id' => $x['school']->id, 'first_name' => 'Other', 'last_name' => 'Guardian', 'email' => 'other-guardian@example.invalid', 'login_email' => null, 'login_enabled_at' => null]));
        $other = $ledger->open($x['school'], [$otherChild->contact_id], [$otherParent->id], $actor);
        $ledger->office($other, 'charge', 77700, '2026-09-01', 'staff note', $actor, child: $otherChild->contact_id, description: 'Other family charge');

        // A row in another school that names this account must not count either.
        $elsewhere = $this->makeMasjid('b');
        DB::table('tuition_lines')->insert(['masjid_id' => $elsewhere->id, 'account_id' => $x['account']->id, 'recorded_by_label' => 'Practice', 'kind' => 'charge', 'amount_minor' => 55500, 'currency' => 'usd', 'effective_on' => '2026-09-01', 'reason' => '', 'description' => 'Other school charge', 'source' => 'office', 'idempotency_key' => 'practice:elsewhere', 'created_at' => now()]);
        app(TenantContext::class)->forgetTenant();

        $this->family($x['parent']);
        $accounts = $this->getJson("/api/family/masjids/{$x['school']->id}/tuition")->assertOk()->json('data.accounts');
        $this->assertCount(1, $accounts);
        $this->assertSame(20000, $accounts[0]['balance_minor']);
        $this->assertSame([['Registration', 20000]], array_map(fn ($i) => [$i['description'], $i['amount_minor']], $accounts[0]['owed_items']));
        $ids = TuitionLine::withoutMasjidScope()->where('account_id', $x['account']->id)->where('masjid_id', $x['school']->id)->pluck('id')->all();
        foreach ($accounts[0]['owed_items'] as $item) $this->assertContains($item['line_id'], $ids);
        $this->getJson("/api/family/masjids/{$x['school']->id}/tuition/{$other->id}")->assertForbidden();
    }
}
