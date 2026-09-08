<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer boundary around a child's attendance.
 *
 * MySQL has no row-level security, so `BelongsToMasjid` on `attendance_records`
 * is the only thing keeping one school's register out of another school's
 * queries — and .claude/rules/tenant-scoping.md makes a cross-tenant test
 * mandatory for every new tenant-scoped model. TenantContext is bound directly
 * here, the way ResolveMasjidTenant binds it per request.
 *
 * The stakes are higher than for most scoped tables: a register says which named
 * children were and were not in a room on a given morning. A leak here is a leak
 * about minors.
 */
class AttendanceRecordTenantIsolationTest extends TestCase
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
    public function the_scope_hides_another_schools_register(): void
    {
        $mine = $this->mark($this->schoolA, $this->studentA, 'present');
        $foreign = $this->mark($this->schoolB, $this->studentB, 'absent');

        $this->tenant->set($this->schoolA->id);

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertNull(AttendanceRecord::find($foreign->id));
        $this->assertNotNull(AttendanceRecord::find($mine->id));
    }

    #[Test]
    public function another_schools_register_cannot_be_updated_or_deleted(): void
    {
        $foreign = $this->mark($this->schoolB, $this->studentB, 'absent');

        $this->tenant->set($this->schoolA->id);

        $this->assertSame(0, AttendanceRecord::where('id', $foreign->id)->update(['status' => 'present']));
        $this->assertSame(0, AttendanceRecord::where('id', $foreign->id)->delete());

        // The row is untouched, read back with the scope lifted.
        $this->tenant->forgetTenant();
        $this->assertSame('absent', AttendanceRecord::find($foreign->id)->status);
    }

    #[Test]
    public function create_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->schoolA->id);

        // A payload that TRIES to file this mark under the other school.
        $record = AttendanceRecord::create([
            'masjid_id' => $this->schoolB->id,
            'group_id' => $this->studentA->group_id,
            'group_membership_id' => $this->studentA->id,
            'session_date' => '2026-09-08',
            'status' => 'present',
        ]);

        $this->assertSame(
            $this->schoolA->id,
            (int) $record->masjid_id,
            'the bound tenant must win over a masjid_id carried in from a request body'
        );
    }

    /**
     * Seed a register row for a named school, UNBOUND — the creating hook would
     * otherwise stamp whatever tenant happens to be bound over the one we mean.
     */
    private function mark(Masjid $school, GroupMembership $student, string $status): AttendanceRecord
    {
        return $this->tenant->runWithout(fn () => AttendanceRecord::create([
            'masjid_id' => $school->id,
            'group_id' => $student->group_id,
            'group_membership_id' => $student->id,
            'session_date' => '2026-09-08',
            'status' => $status,
        ]));
    }

    private function enrol(Masjid $school, string $suffix): GroupMembership
    {
        $class = Group::factory()->create([
            'masjid_id' => $school->id,
            'name' => 'Pre-K & Kindergarten',
            'slug' => 'pre-k-kindergarten-'.$suffix,
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
            'grade_label' => 'Pre-K',
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
