<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\LessonPlan;
use App\Models\LessonPlanResource;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `lesson_plan_resources` (T-004.1) is tenant-scoped like every table that
 * carries `masjid_id`: the bound tenant is the only boundary MySQL gives us.
 *
 * The model-layer half, mirroring `TenantIsolationTest`: another organisation's
 * link is invisible, cannot be updated or deleted through the scope, and a
 * client-supplied `masjid_id` loses to the bound tenant. The HTTP half (another
 * class's file, another school's file, both a 422) is in `LessonPlanAttachmentsTest`.
 */
class LessonPlanResourceTenantIsolationTest extends TestCase
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

    private function makeLink(Masjid $masjid, Group $group): LessonPlanResource
    {
        $plan = LessonPlan::create([
            'masjid_id' => $masjid->id, 'group_id' => $group->id,
            'session_date' => now()->toDateString(), 'body' => 'Plan.',
        ]);
        $file = GroupResource::create([
            'masjid_id' => $masjid->id, 'group_id' => $group->id, 'title' => 'File',
            'original_name' => 'f.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'disk' => 'local', 'path' => 'group-resources/'.uniqid().'.pdf',
        ]);

        return LessonPlanResource::create([
            'masjid_id' => $masjid->id, 'lesson_plan_id' => $plan->id,
            'group_resource_id' => $file->id, 'position' => 0,
        ]);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_lesson_plan_resource(): void
    {
        $mine = $this->makeLink($this->masjidA, $this->groupA);
        $theirs = $this->makeLink($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(LessonPlanResource::find($theirs->id));
        $this->assertNotNull(LessonPlanResource::find($mine->id));
        $this->assertSame(1, LessonPlanResource::count());

        // Nor changed or removed through the scope.
        $this->assertSame(0, LessonPlanResource::where('id', $theirs->id)->update(['position' => 9]));
        $this->assertSame(0, LessonPlanResource::where('id', $theirs->id)->delete());

        $this->tenant->forgetTenant();
        $this->assertSame(0, (int) LessonPlanResource::withoutMasjidScope()->whereKey($theirs->id)->value('position'));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $plan = LessonPlan::create([
            'masjid_id' => $this->masjidA->id, 'group_id' => $this->groupA->id,
            'session_date' => now()->toDateString(), 'body' => 'Plan.',
        ]);
        $file = GroupResource::create([
            'masjid_id' => $this->masjidA->id, 'group_id' => $this->groupA->id, 'title' => 'File',
            'original_name' => 'f.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'disk' => 'local', 'path' => 'group-resources/'.uniqid().'.pdf',
        ]);

        $this->tenant->set($this->masjidA->id);

        $link = LessonPlanResource::create([
            'masjid_id' => $this->masjidB->id, 'lesson_plan_id' => $plan->id,
            'group_resource_id' => $file->id, 'position' => 0,
        ]);

        $this->assertSame($this->masjidA->id, $link->fresh()->masjid_id);
    }
}
