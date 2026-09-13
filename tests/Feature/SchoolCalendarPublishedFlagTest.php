<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `school_calendar_published` — whether the teacher shell and the family portal
 * offer a Calendar link at all.
 *
 * Both shells already load one "who am I" GET on every page: /api/teacher/user
 * and /api/family/masjids/{id}/me. The flag rides on those rather than a new
 * route, so neither realm's counted-writes test moves. It is true once THIS
 * organisation has a school year; another organisation's year never counts.
 */
class SchoolCalendarPublishedFlagTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        app(TenantContext::class)->forgetTenant();

        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();
    }

    #[Test]
    public function the_teacher_shell_is_told_whether_their_school_has_a_calendar(): void
    {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);
        Sanctum::actingAs($teacher, ['staff']);

        $this->getJson('/api/teacher/user')->assertOk()->assertJsonPath('data.school_calendar_published', false);

        $this->yearFor($this->otherSchool);
        $this->getJson('/api/teacher/user')->assertOk()->assertJsonPath('data.school_calendar_published', false);

        $this->yearFor($this->school);
        $this->getJson('/api/teacher/user')->assertOk()->assertJsonPath('data.school_calendar_published', true);
    }

    #[Test]
    public function the_family_portal_is_told_whether_its_school_has_a_calendar(): void
    {
        $parent = Contact::factory()->create(['masjid_id' => $this->school->id]);
        $parent->forceFill(['login_email' => 'parent-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();

        $me = function () use ($parent) {
            Auth::forgetGuards();
            app(TenantContext::class)->forgetTenant();

            return $this->withHeader('Authorization', 'Bearer '.$parent->refresh()->createFamilyToken()->plainTextToken)
                ->getJson("/api/family/masjids/{$this->school->id}/me")
                ->assertOk();
        };

        $me()->assertJsonPath('data.school_calendar_published', false);

        $this->yearFor($this->otherSchool);
        $me()->assertJsonPath('data.school_calendar_published', false);

        $this->yearFor($this->school);
        $me()->assertJsonPath('data.school_calendar_published', true);
    }

    private function yearFor(Masjid $school): void
    {
        app(TenantContext::class)->runWithout(fn () => SchoolYear::create([
            'masjid_id' => $school->id, 'label' => '2026–27',
            'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
        ]));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Flag School '.uniqid(),
            'email' => 'flag'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
