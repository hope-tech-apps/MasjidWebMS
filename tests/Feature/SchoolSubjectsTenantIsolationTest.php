<?php

namespace Tests\Feature;

use App\Models\ClassGradeWeight;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\SchoolSubject;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `school_subjects` and `class_grade_weights` (T-001.2, T-001.3) are
 * tenant-scoped like every table that carries `masjid_id`: the bound tenant is
 * the only boundary MySQL gives us.
 *
 * The model-layer half, mirroring `TenantIsolationTest`: another organisation's
 * row is invisible, cannot be updated or deleted through the scope, and a
 * client-supplied `masjid_id` loses to the bound tenant. The HTTP half (another
 * school's subject id is a 404 on the office screen, another school's class is
 * refused on the weights route) is in `SchoolSubjectsTest` and
 * `GradebookWeightingTest`.
 */
class SchoolSubjectsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;
    private Masjid $masjidA;
    private Masjid $masjidB;
    private Group $groupA;
    private Group $groupB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->groupA = Group::factory()->create(['masjid_id' => $this->masjidA->id, 'kind' => Group::KIND_CLASS, 'name' => 'A', 'slug' => 'a']);
        $this->groupB = Group::factory()->create(['masjid_id' => $this->masjidB->id, 'kind' => Group::KIND_CLASS, 'name' => 'B', 'slug' => 'b']);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'School '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_change_or_remove_another_schools_subject(): void
    {
        $mine = SchoolSubject::create(['masjid_id' => $this->masjidA->id, 'name' => 'Mathematics']);
        $theirs = SchoolSubject::create(['masjid_id' => $this->masjidB->id, 'name' => 'Mathematics']);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(SchoolSubject::find($theirs->id));
        $this->assertNotNull(SchoolSubject::find($mine->id));
        $this->assertSame(1, SchoolSubject::count());

        $this->assertSame(0, SchoolSubject::where('id', $theirs->id)->update(['position' => 9]));
        $this->assertSame(0, SchoolSubject::where('id', $theirs->id)->delete());

        $this->tenant->forgetTenant();
        $this->assertSame(0, (int) SchoolSubject::withoutMasjidScope()->whereKey($theirs->id)->value('position'));
    }

    #[Test]
    public function the_same_subject_name_is_allowed_in_two_schools_but_not_twice_in_one(): void
    {
        SchoolSubject::create(['masjid_id' => $this->masjidA->id, 'name' => "Qur'an"]);
        SchoolSubject::create(['masjid_id' => $this->masjidB->id, 'name' => "Qur'an"]);

        // The typographic apostrophe a word processor writes is the same subject.
        $this->expectException(\Illuminate\Database\QueryException::class);
        SchoolSubject::create(['masjid_id' => $this->masjidA->id, 'name' => 'Qur’an']);
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->masjidA->id);

        $subject = SchoolSubject::create(['masjid_id' => $this->masjidB->id, 'name' => 'Science']);
        $weight = ClassGradeWeight::create([
            'masjid_id' => $this->masjidB->id, 'group_id' => $this->groupA->id,
            'assignment_type' => 'test', 'weight' => 40,
        ]);

        $this->assertSame($this->masjidA->id, (int) $subject->masjid_id);
        $this->assertSame($this->masjidA->id, (int) $weight->masjid_id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_change_or_remove_another_schools_class_weight(): void
    {
        $mine = ClassGradeWeight::create([
            'masjid_id' => $this->masjidA->id, 'group_id' => $this->groupA->id,
            'assignment_type' => 'test', 'weight' => 40,
        ]);
        $theirs = ClassGradeWeight::create([
            'masjid_id' => $this->masjidB->id, 'group_id' => $this->groupB->id,
            'assignment_type' => 'test', 'weight' => 40,
        ]);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(ClassGradeWeight::find($theirs->id));
        $this->assertNotNull(ClassGradeWeight::find($mine->id));
        $this->assertSame([], ClassGradeWeight::forGroup($this->groupB->id), 'a class of another school reads as unweighted');
        $this->assertSame(0, ClassGradeWeight::where('id', $theirs->id)->update(['weight' => 1]));
        $this->assertSame(0, ClassGradeWeight::where('id', $theirs->id)->delete());

        $this->tenant->forgetTenant();
        $this->assertSame(40, (int) ClassGradeWeight::withoutMasjidScope()->whereKey($theirs->id)->value('weight'));
    }
}
