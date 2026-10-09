<?php

namespace Tests\Feature;

use App\Models\{Masjid, MasjidUser, User, SchoolYear, SchoolClosure, Contact, Group, GroupMembership, GroupStaff, ReportCard};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

/** SQL literals captured by running these request shapes with cached main's implementations. */
class SchoolCalendarOffQueryDifferentialTest extends TestCase
{
    use RefreshDatabase;

    public static function requests(): array
    {
        $shapes = ['get0', 'get1', 'get3', 'year-create', 'year-update', 'year-delete', 'closure-create', 'closure-delete', 'closure-update', 'card-prepare', 'card-save', 'card-list', 'capabilities'];
        return array_combine($shapes, array_map(fn ($shape) => [$shape], $shapes));
    }

    #[Test, DataProvider('requests')]
    public function off_executes_exactly_the_literal_main_sql_list(string $shape): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-08 16:00:00', 'UTC'));
        $org = Masjid::create(['name' => 'SQL School', 'email' => 'sql@example.invalid', 'phone' => '+15550008800', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
        $org->forceFill(['capability_overrides' => ['school_calendar' => true]])->save();
        $user = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550008801']);
        Sanctum::actingAs($user);
        $url = '/api/admin/masjids/'.$org->id.'/school-calendar';
        $years = $shape === 'get0' || $shape === 'year-create' ? 0 : ($shape === 'get3' ? 3 : 1);
        $year = null;
        for ($i = 0; $i < $years; $i++) {
            $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => '2026-2027', 'first_day' => (2026 + $i).'-10-11', 'last_day' => (2026 + $i).'-10-25']);
        }
        if (in_array($shape, ['closure-delete', 'closure-update'])) {
            $closure = SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-18', 'reason' => 'Staff day']);
        }
        if (str_starts_with($shape, 'card-')) {
            $user->forceFill(['type' => 'Teacher'])->save();
            MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $user->id, 'role' => 'teacher', 'is_default' => true]);
            $group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
            $group->staff()->attach($user->id, ['masjid_id' => $org->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);
            $contact = Contact::factory()->create(['masjid_id' => $org->id]);
            $member = GroupMembership::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => 'Pre-K']);
            $url = '/api/teacher/masjids/'.$org->id.'/groups/'.$group->id.'/members/'.$member->id.'/report-card';
            Sanctum::actingAs($user, ['staff']);
            if ($shape === 'card-save') {
                $card = (new \App\Services\Schools\ReportCardService)->prepare($member, 'report_card', '2026-2027', 2);
                $mark = $card->marks->first();
            }
        }
        DB::flushQueryLog(); DB::enableQueryLog();
        $response = match ($shape) {
            'get0', 'get1', 'get3' => $this->getJson($url),
            'capabilities' => $this->getJson('/api/admin/masjids/'.$org->id.'/capabilities'),
            'year-create' => $this->postJson($url.'/years', ['label' => '2026-2027', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25']),
            'year-update' => $this->putJson($url.'/years/'.$year->id, ['label' => 'Edited year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25']),
            'year-delete' => $this->deleteJson($url.'/years/'.$year->id),
            'closure-create' => $this->postJson($url.'/closures', ['school_year_id' => $year->id, 'closed_on' => '2026-10-18', 'reason' => 'Staff day']),
            'closure-delete' => $this->deleteJson($url.'/closures/'.$closure->id),
            'closure-update' => $this->putJson($url.'/closures/'.$closure->id, ['reason' => 'Holiday']),
            'card-prepare' => $this->getJson($url.'?school_year=2026-2027&term=2'),
            'card-list' => $this->getJson('/api/teacher/masjids/'.$org->id.'/groups/'.$group->id.'/report-cards?school_year=2026-2027&term=2'),
            'card-save' => $this->putJson($url, ['school_year' => '2026-2027', 'term' => 2, 'marks' => [['id' => $mark->id, 'level' => 3, 'comment' => 'Saved mark']], 'teacher_comment' => 'Saved']),
        };
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $response->assertStatus($shape === 'year-create' || $shape === 'closure-create' ? 201 : 200);
        $file = base_path('tests/fixtures/calendar-baseline/sql/'.$shape.'.json');
        if (getenv('CAPTURE_CALENDAR_SQL') === '1') {
            file_put_contents($file, json_encode($sql, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        $expected = json_decode(file_get_contents($file), true);
        // Main reaches validation/dispatch before loading the school on these
        // shapes. One request-memoised nonlocking PK read is allowed there.
        $lookupAt = match ($shape) {
            'year-create', 'year-update', 'year-delete', 'closure-create' => 0,
            'card-prepare', 'card-save' => 7,
            // The list asks whether to offer the school's own years; nothing has loaded the school before it.
            'card-list' => 8,
            // Main's own class-subject dispatch reads the switches before it loads the school here.
            'capabilities' => 0,
            default => null, // Calendar response reuses main's own school read.
        };
        // The one permitted switch read sits exactly there, in either form: the
        // calendar's own, or the narrow one class subjects makes, whose row the
        // calendar reuses. Anywhere else, or twice, fails the comparison below.
        $shared = 'select "id", "org_type", "capability_overrides", "deleted_at" from "masjids" where "masjids"."id" = ? limit 1';
        if ($lookupAt !== null) {
            array_splice($expected, $lookupAt, 0, [($sql[$lookupAt] ?? null) === $shared ? $shared : 'select * from "masjids" where "masjids"."id" = ? and "masjids"."deleted_at" is null limit 1']);
        }
        $this->assertSame($expected, $sql, $shape.' OFF SQL differs from main plus its permitted read');
    }
}
