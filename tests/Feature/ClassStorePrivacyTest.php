<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Models\User;
use App\Support\ClassStore;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoreFixture;
use Tests\TestCase;

/**
 * T-003.4 (W6): WHO MAY READ A BALANCE, and that nothing crosses a school.
 *
 * A child's Manara Bucks are as private as their behaviour points, and for the same reason:
 * a class-wide tally is the public shaming this module exists to refuse. The rule is one
 * decision in one place (GroupAudience::readablePrizeLedgerQuery), applied at QUERY level so a
 * forbidden row is never fetched and cannot surface in a page, a paginator total or a SUM.
 * The endpoint 403 and the query constraint are BOTH required: the 403 is honest to a parent
 * who mistyped an id, and the constraint is what makes the honesty safe. So each half has its
 * own test here, and the security ones are the ones the mutation run breaks on purpose.
 */
class ClassStorePrivacyTest extends TestCase
{
    use BuildsClassStoreFixture;
    use RefreshDatabase;

    private Contact $familyWithBoth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreSchools();
        $this->storeOn();

        // Distinct amounts, so a leak of the wrong child's rows changes a number.
        $this->credit($this->amira, 10);
        $this->credit($this->amira, 5);
        $this->credit($this->yusuf, 100);

        // One parent who is the guardian of BOTH children.
        $this->familyWithBoth = Contact::factory()->create(['masjid_id' => $this->school->id]);
        $this->familyWithBoth->forceFill(['login_email' => 'both-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();
        foreach ([$this->amiraContact, $this->yusufContact] as $ward) {
            GroupMembership::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $this->familyWithBoth->id,
                'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $ward->id,
            ]);
        }

        app(TenantContext::class)->set($this->school->id);
    }

    protected function tearDown(): void
    {
        $this->thaw();

        parent::tearDown();
    }

    private function audience(): GroupAudience
    {
        return app(GroupAudience::class);
    }

    // --------------------------------------------- the query half, per person

    #[Test]
    public function the_readable_ledger_is_exactly_the_callers_own_students_and_nobody_elses(): void
    {
        $audience = $this->audience();
        $ids = fn (?\Illuminate\Contracts\Auth\Authenticatable $who) => $audience->readablePrizeLedgerQuery($who, $this->class)?->orderBy('id')->pluck('group_membership_id')->unique()->sort()->values()->all();
        $both = [$this->amira->id, $this->yusuf->id];
        sort($both);

        // The class's teacher: the whole class, because they run its store.
        $this->assertSame($both, $ids($this->teacher));
        // A parent: their own child, and never another family's.
        $this->assertSame([$this->amira->id], $ids($this->amiraParent));
        $this->assertSame([$this->yusuf->id], $ids($this->yusufParent));
        // A parent of two children in the class reads both, each as its own.
        $this->assertSame($both, $ids($this->familyWithBoth));
    }

    #[Test]
    public function a_stranger_has_no_readable_ledger_at_all_not_an_empty_one(): void
    {
        $audience = $this->audience();

        $otherClass = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $otherTeacher = $this->teacherOf($this->school, $otherClass);
        $officeAdmin = $this->admin;
        $foreignSchool = $this->newSchool('Elsewhere');
        $foreignAdmin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $foreignSchool->user_id = $foreignAdmin->id;
        $foreignSchool->save();
        $unrelated = Contact::factory()->create(['masjid_id' => $this->school->id]);
        $unrelated->forceFill(['login_email' => 'nobody-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();

        // null means "not in this class": the controllers answer 403, never a 200 with nothing in it.
        $this->assertNull($audience->readablePrizeLedgerQuery($otherTeacher, $this->class), "another class's teacher");
        $this->assertNull($audience->readablePrizeLedgerQuery($officeAdmin, $this->class), 'an administrator who is not on the roster stands nowhere');
        $this->assertNull($audience->readablePrizeLedgerQuery($foreignAdmin, $this->class), "another school's administrator");
        $this->assertNull($audience->readablePrizeLedgerQuery($unrelated, $this->class), 'a family with no child here');
        $this->assertNull($audience->readablePrizeLedgerQuery(null, $this->class), 'nobody');
    }

    #[Test]
    public function a_parents_page_total_and_sum_can_only_be_their_own_childs(): void
    {
        $audience = $this->audience();
        $mine = $audience->readablePrizeLedgerQuery($this->amiraParent, $this->class);

        $this->assertSame(2, $mine->clone()->paginate(50)->total(), "a paginator total must not count another family's rows");
        $this->assertSame(15, (int) $mine->clone()->sum('amount'), "an aggregate must not include another family's bucks");
        $this->assertSame([$this->amira->id => 15], ClassStore::balances($mine, [$this->amira->id, $this->yusuf->id]));
        $this->assertSame([], ClassStore::balances($mine, [$this->yusuf->id]), "asking for another child's balance by id yields nothing");
    }

    #[Test]
    public function the_same_child_in_another_class_is_a_separate_ledger_not_read_from_this_one(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $there = $this->enrol($this->school, $other, $this->amiraContact);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $other->id, 'contact_id' => $this->amiraParent->id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $this->amiraContact->id,
        ]);
        $this->credit($there, 777);
        $otherTeacher = $this->teacherOf($this->school, $other);

        $audience = $this->audience();
        // This class's teacher never sees the 777, and the other class's never sees the 15.
        $this->assertSame(15, (int) $audience->readablePrizeLedgerQuery($this->teacher, $this->class)->where('group_membership_id', $this->amira->id)->sum('amount'));
        $this->assertSame(0, $audience->readablePrizeLedgerQuery($this->teacher, $this->class)->where('group_membership_id', $there->id)->count());
        $this->assertSame(777, (int) $audience->readablePrizeLedgerQuery($otherTeacher, $other)->sum('amount'));
        // The parent reads each class through its own group.
        $this->assertSame(15, (int) $audience->readablePrizeLedgerQuery($this->amiraParent, $this->class)->sum('amount'));
        $this->assertSame(777, (int) $audience->readablePrizeLedgerQuery($this->amiraParent, $other)->sum('amount'));
    }

    // ------------------------------------------------------- the endpoint half

    #[Test]
    public function a_parent_is_refused_another_familys_child_at_the_endpoint_with_a_403(): void
    {
        app(TenantContext::class)->forgetTenant();

        $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->yusuf->id.'/bucks'))
            ->assertForbidden();
        $this->asParent($this->yusufParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))
            ->assertForbidden();

        $own = $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk();
        $this->assertSame(15, $own->json('meta.balance'));
        $this->assertStringNotContainsString('100', (string) json_encode($own->json('meta')), "Yusuf's 100 is not in Amira's page");
    }

    #[Test]
    public function a_parent_with_two_children_reads_each_one_on_its_own_page(): void
    {
        app(TenantContext::class)->forgetTenant();

        $a = $this->asParent($this->familyWithBoth)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk();
        $y = $this->asParent($this->familyWithBoth)->getJson($this->familyUrl('/members/'.$this->yusuf->id.'/bucks'))->assertOk();

        $this->assertSame(15, $a->json('meta.balance'));
        $this->assertSame(100, $y->json('meta.balance'));
        // No page ever carries both, so nothing is summed, ranked or compared across children.
        $this->assertSame(2, $a->json('data.total'));
        $this->assertSame(1, $y->json('data.total'));
        $this->assertNotContains($this->yusuf->id, array_column(array_column($a->json('data.data'), 'student') ?: [[]], 'membership_id'));
    }

    #[Test]
    public function the_teacher_realm_and_the_family_realm_do_not_open_each_others_doors(): void
    {
        app(TenantContext::class)->forgetTenant();

        // A parent's token on the teacher's store, and a teacher's staff token on the family's.
        $this->asParent($this->amiraParent)->getJson($this->teacherUrl('/bucks'))->assertUnauthorized();
        $this->actAs($this->teacher);
        $this->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertUnauthorized();
    }

    #[Test]
    public function an_office_administrator_has_no_route_to_one_childs_balance(): void
    {
        app(TenantContext::class)->forgetTenant();
        $this->actAs($this->admin);

        foreach ([
            '/groups/'.$this->class->id.'/members/'.$this->amira->id.'/bucks',
            '/groups/'.$this->class->id.'/bucks',
            '/groups/'.$this->class->id.'/prize-entries',
            '/prize-entries',
            '/bucks',
        ] as $path) {
            $this->assertSame(404, $this->getJson($this->adminUrl($path))->getStatusCode(), "the office must have no way to {$path}");
        }
    }

    #[Test]
    public function the_offices_totals_decision_checks_the_bound_school_not_just_the_users_type(): void
    {
        $other = $this->newSchool('Elsewhere');
        $otherClass = app(TenantContext::class)->runWithout(fn () => Group::factory()->create([
            'masjid_id' => $other->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 9',
        ]));
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);

        // Bound to this school: its own administrator and its class's teacher read the totals.
        $this->assertTrue($this->audience()->mayReceiveClassStoreTotals($this->admin, $this->class));
        $this->assertTrue($this->audience()->mayReceiveClassStoreTotals($this->teacher, $this->class));

        // Another school's class, handed to the decision from this school's request: never, whatever
        // the caller's users.type says. (It used to grant on the type alone.)
        $this->assertFalse($this->audience()->mayReceiveClassStoreTotals($this->admin, $otherClass));
        $this->assertFalse($this->audience()->mayReceiveClassStoreTotals($super, $otherClass));

        // No school bound at all: nobody, the class's own teacher included.
        app(TenantContext::class)->forgetTenant();
        $this->assertFalse($this->audience()->mayReceiveClassStoreTotals($this->admin, $this->class));
        $this->assertFalse($this->audience()->mayReceiveClassStoreTotals($super, $this->class));
        $this->assertFalse($this->audience()->mayReceiveClassStoreTotals($this->teacher, $this->class));

        // A SuperAdmin bound to that school (from its URL) reads its totals, and not this one's.
        app(TenantContext::class)->set($other->id);
        $this->assertTrue($this->audience()->mayReceiveClassStoreTotals($super, $otherClass));
        $this->assertFalse($this->audience()->mayReceiveClassStoreTotals($super, $this->class));
    }

    // ------------------------------------------------------------ across schools

    #[Test]
    public function a_teacher_of_school_a_cannot_touch_school_bs_store_by_any_id(): void
    {
        app(TenantContext::class)->forgetTenant();
        $b = $this->newSchool('School B');
        $this->storeOn($b);
        $classB = Group::factory()->create(['masjid_id' => $b->id, 'kind' => Group::KIND_CLASS, 'name' => 'B class']);
        $kidB = $this->enrol($b, $classB, Contact::factory()->create(['masjid_id' => $b->id, 'email' => null]));
        $this->credit($kidB, 50);
        $prizeB = $this->prize(['title' => 'B prize', 'cost_bucks' => 1, 'stock' => 4], $b);
        $entryB = ClassStore::redeem($classB, $kidB, $prizeB, $this->teacherOf($b, $classB))['entry'];
        $prizeA = $this->prize(['title' => 'A prize', 'cost_bucks' => 1]);
        $this->credit($this->amira, 30);

        $this->actAs($this->teacher);

        // B's URL: not this teacher's school at all.
        $this->getJson($this->teacherUrl('/bucks', $b, $classB))->assertForbidden();
        $this->postJson($this->teacherUrl('/members/'.$kidB->id.'/prizes/redeem', $b, $classB), ['prize_id' => $prizeB->id, 'request_id' => 'rq-'.uniqid()])->assertForbidden();

        // A's URL with B's ids: the tenant scope makes them not exist.
        $this->postJson($this->teacherUrl('/members/'.$kidB->id.'/prizes/redeem'), ['prize_id' => $prizeA->id, 'request_id' => 'rq-'.uniqid()])->assertNotFound();
        $this->postJson($this->teacherUrl('/members/'.$this->amira->id.'/prizes/redeem'), ['prize_id' => $prizeB->id, 'request_id' => 'rq-'.uniqid()])->assertStatus(422)->assertJsonPath('reason', 'prize_unknown');
        $this->postJson($this->teacherUrl('/prize-entries/'.$entryB->id.'/reverse'))->assertNotFound();
        $this->putJson($this->teacherUrl('/prizes/'.$prizeB->id), ['cost_bucks' => 1])->assertNotFound();
        $this->getJson($this->teacherUrl('/members/'.$kidB->id.'/bucks'))->assertNotFound();

        $this->assertSame(3, $prizeB->fresh()->stock, "B's shelf is exactly as B left it (one taken by B's own redemption)");
        $this->assertSame(49, $this->balanceOf($kidB));
        $this->assertSame(45, $this->balanceOf($this->amira), 'her 15 from setUp and the 30 this test added, untouched');
        $this->assertSame(1, PrizeLedgerEntry::withoutMasjidScope()->where('group_membership_id', $kidB->id)->where('kind', 'redeemed')->count());
    }

    #[Test]
    public function a_parent_of_school_b_reads_nothing_of_school_as_children(): void
    {
        app(TenantContext::class)->forgetTenant();
        $b = $this->newSchool('School B');
        $this->storeOn($b);
        $classB = Group::factory()->create(['masjid_id' => $b->id, 'kind' => Group::KIND_CLASS, 'name' => 'B class']);
        $kidContact = Contact::factory()->create(['masjid_id' => $b->id, 'email' => null]);
        $this->enrol($b, $classB, $kidContact);
        $parentB = $this->guardianOf($b, $classB, $kidContact);

        // B's parent, with B's token, in A's class and A's school.
        $this->asParent($parentB)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertStatus(403);
        $this->asParent($parentB)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks', $b))->assertStatus(404);
    }

    #[Test]
    public function the_ledger_payloads_carry_no_contact_detail_of_a_child_or_a_guardian(): void
    {
        app(TenantContext::class)->forgetTenant();
        $this->actAs($this->teacher);

        $body = $this->getJson($this->teacherUrl('/bucks'))->getContent()
            .$this->getJson($this->teacherUrl('/members/'.$this->amira->id.'/bucks'))->getContent();

        foreach ([(string) $this->amiraParent->login_email, (string) $this->yusufParent->login_email, 'phone', 'guardian', 'login_'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    #[Test]
    public function a_prize_title_is_the_only_free_text_a_parent_can_read_from_the_teacher(): void
    {
        app(TenantContext::class)->forgetTenant();
        $prize = $this->prize(['title' => 'Bookmark', 'description' => 'PRIVATE-DESCRIPTION-OF-THE-PRIZE']);
        ClassStore::redeem($this->class, $this->amira, $prize, $this->teacher, null, 'PRIVATE-TEACHER-NOTE');

        $body = $this->asParent($this->amiraParent)->getJson($this->familyUrl('/members/'.$this->amira->id.'/bucks'))->assertOk()->getContent();

        $this->assertStringContainsString('Bookmark', $body);
        $this->assertStringNotContainsString('PRIVATE-TEACHER-NOTE', $body);
        $this->assertStringNotContainsString('PRIVATE-DESCRIPTION', $body);
        $this->assertNotNull(Prize::query()->find($prize->id));
    }
}
