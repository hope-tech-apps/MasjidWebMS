<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-003.4 (W6): the two new tenant-scoped models, Prize and PrizeLedgerEntry, cannot cross a
 * school. MySQL has no row-level security, so this is the only backstop; the mechanism itself
 * is proved for every BelongsToMasjid model by TenantScopingCoverageTest. What is here is the
 * cross-tenant TEST that check demands, written against real rows in two schools.
 */
class ClassStoreTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;
    private Group $classA;
    private Group $classB;
    private GroupMembership $studentA;
    private GroupMembership $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();

        $this->a = Masjid::create([
            'name' => 'School A '.uniqid(), 'email' => 'a-'.uniqid().'@test.local', 'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
        $this->b = Masjid::create([
            'name' => 'School B '.uniqid(), 'email' => 'b-'.uniqid().'@test.local', 'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $school) {
            $class = Group::factory()->create(['masjid_id' => $school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3']);
            $contact = Contact::factory()->create(['masjid_id' => $school->id, 'email' => null]);
            $student = GroupMembership::create([
                'masjid_id' => $school->id, 'group_id' => $class->id, 'contact_id' => $contact->id, 'role' => GroupMembership::ROLE_MEMBER,
            ]);
            $this->{'class'.strtoupper($key)} = $class;
            $this->{'student'.strtoupper($key)} = $student;
        }
    }

    private function entry(Masjid $school, Group $class, GroupMembership $student, int $amount): PrizeLedgerEntry
    {
        // Unbound, so the explicit masjid_id is honoured (the creating hook only overrides when bound).
        return PrizeLedgerEntry::create([
            'masjid_id' => $school->id, 'group_id' => $class->id, 'group_membership_id' => $student->id,
            'kind' => PrizeLedgerEntry::KIND_EARNED, 'amount' => $amount,
        ]);
    }

    #[Test]
    public function school_a_cannot_read_update_or_delete_school_bs_prizes_or_ledger(): void
    {
        $prizeB = Prize::create(['masjid_id' => $this->b->id, 'group_id' => null, 'title' => 'B Sticker', 'cost_bucks' => 3]);
        $prizeA = Prize::create(['masjid_id' => $this->a->id, 'group_id' => null, 'title' => 'A Sticker', 'cost_bucks' => 3]);
        $entryB = $this->entry($this->b, $this->classB, $this->studentB, 40);
        $entryA = $this->entry($this->a, $this->classA, $this->studentA, 7);

        app(TenantContext::class)->set($this->a->id);

        // Reads: B's rows are simply not there.
        $this->assertNull(Prize::query()->find($prizeB->id));
        $this->assertNull(PrizeLedgerEntry::query()->find($entryB->id));
        $this->assertSame([$prizeA->id], Prize::query()->pluck('id')->all());
        $this->assertSame([$entryA->id], PrizeLedgerEntry::query()->pluck('id')->all());
        $this->assertSame(7, (int) PrizeLedgerEntry::query()->sum('amount'), 'a balance can never include the other school');
        $this->assertCount(0, Prize::query()->availableTo($this->classB)->get(), "B's shelf is invisible from A");

        // Writes: an update or delete aimed at B's rows touches nothing.
        $this->assertSame(0, Prize::query()->whereKey($prizeB->id)->update(['cost_bucks' => 1]));
        $this->assertSame(0, Prize::query()->whereKey($prizeB->id)->delete());

        // A create is stamped with the bound tenant whatever the caller says.
        $forged = Prize::create(['masjid_id' => $this->b->id, 'title' => 'Forged', 'cost_bucks' => 1]);
        $this->assertSame($this->a->id, (int) $forged->masjid_id);
        $forgedEntry = PrizeLedgerEntry::create([
            'masjid_id' => $this->b->id, 'group_id' => $this->classA->id, 'group_membership_id' => $this->studentA->id,
            'kind' => PrizeLedgerEntry::KIND_EARNED, 'amount' => 1,
        ]);
        $this->assertSame($this->a->id, (int) $forgedEntry->masjid_id);

        // And B's data is exactly as it was.
        app(TenantContext::class)->forgetTenant();
        $this->assertSame(3, (int) Prize::query()->whereKey($prizeB->id)->value('cost_bucks'));
        $this->assertSame(40, (int) PrizeLedgerEntry::query()->where('group_membership_id', $this->studentB->id)->sum('amount'));
    }
}
