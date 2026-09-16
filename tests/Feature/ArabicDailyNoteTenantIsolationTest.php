<?php

namespace Tests\Feature;

use App\Models\ArabicDailyNote;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer boundary around a teacher's daily note on a child's Arabic.
 *
 * MySQL has no row-level security, so `BelongsToMasjid` on `arabic_daily_notes`
 * is the only thing keeping one school's notes out of another school's queries,
 * and .claude/rules/tenant-scoping.md makes a cross-tenant test mandatory for
 * every new tenant-scoped model. TenantContext is bound directly here, the way
 * ResolveMasjidTenant binds it per request.
 *
 * The stakes match the register's and are arguably higher: this is free text a
 * teacher wrote about a named child's progress. A leak is not a leak of a status
 * code, it is a leak of somebody's words about somebody's daughter.
 */
class ArabicDailyNoteTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;
    private Masjid $schoolA;
    private Masjid $schoolB;
    private GroupMembership $studentA;
    private GroupMembership $studentB;

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

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->schoolA = $this->makeMasjid();
        $this->schoolB = $this->makeMasjid();

        $this->studentA = $this->enrol($this->schoolA, 'a');
        $this->studentB = $this->enrol($this->schoolB, 'b');
    }

    #[Test]
    public function the_scope_hides_another_schools_daily_notes(): void
    {
        $mine = $this->note($this->schoolA, $this->studentA, 'Read the first line unaided.');
        $foreign = $this->note($this->schoolB, $this->studentB, 'Needed help with ḥarf jīm.');

        $this->tenant->set($this->schoolA->id);

        $this->assertSame(1, ArabicDailyNote::count());
        $this->assertNull(ArabicDailyNote::find($foreign->id));
        $this->assertNotNull(ArabicDailyNote::find($mine->id));
    }

    #[Test]
    public function another_schools_daily_note_cannot_be_updated_or_deleted(): void
    {
        $foreign = $this->note($this->schoolB, $this->studentB, 'Needed help with ḥarf jīm.');

        $this->tenant->set($this->schoolA->id);

        $this->assertSame(0, ArabicDailyNote::where('id', $foreign->id)->update(['note' => 'overwritten']));
        $this->assertSame(0, ArabicDailyNote::where('id', $foreign->id)->delete());

        // The row is untouched, read back with the scope lifted.
        $this->tenant->forgetTenant();
        $this->assertSame('Needed help with ḥarf jīm.', ArabicDailyNote::find($foreign->id)->note);
    }

    #[Test]
    public function create_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->schoolA->id);

        // A payload that TRIES to file this note under the other school.
        $note = ArabicDailyNote::create([
            'masjid_id' => $this->schoolB->id,
            'group_id' => $this->studentA->group_id,
            'group_membership_id' => $this->studentA->id,
            'session_date' => '2026-09-16',
            'note' => 'Filed under the wrong school on purpose.',
        ]);

        $this->assertSame(
            $this->schoolA->id,
            (int) $note->masjid_id,
            'the bound tenant must win over a masjid_id carried in from a request body'
        );
    }

    #[Test]
    public function one_student_may_hold_only_one_note_per_day(): void
    {
        // The unique key is what makes the upsert an EDIT rather than a second
        // row. Without it a teacher correcting themselves silently produces two
        // notes for one day and the screen shows whichever came back first.
        $this->note($this->schoolA, $this->studentA, 'First attempt.', '2026-09-16');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->note($this->schoolA, $this->studentA, 'Second attempt, same day.', '2026-09-16');
    }

    /**
     * Seed a note for a named school, UNBOUND — the creating hook would
     * otherwise stamp whatever tenant happens to be bound over the one we mean.
     */
    private function note(Masjid $school, GroupMembership $student, string $text, string $on = '2026-09-16'): ArabicDailyNote
    {
        return $this->tenant->runWithout(fn () => ArabicDailyNote::create([
            'masjid_id' => $school->id,
            'group_id' => $student->group_id,
            'group_membership_id' => $student->id,
            'session_date' => $on,
            'note' => $text,
        ]));
    }

    private function enrol(Masjid $school, string $suffix): GroupMembership
    {
        $class = Group::factory()->create([
            'masjid_id' => $school->id,
            'name' => '1st & 2nd Grade',
            'slug' => 'first-second-grade-'.$suffix,
            'kind' => Group::KIND_CLASS,
        ]);

        $child = Contact::factory()->create([
            'masjid_id' => $school->id,
            'first_name' => 'Child',
            'last_name' => strtoupper($suffix),
        ]);

        return GroupMembership::create([
            'masjid_id' => $school->id,
            'group_id' => $class->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'grade_label' => '2nd',
        ]);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'org_type' => 'school',
        ], $overrides));
    }
}
