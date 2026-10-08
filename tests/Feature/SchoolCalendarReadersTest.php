<?php

namespace Tests\Feature;

use App\Models\{Masjid, MasjidUser, User, SchoolYear, SchoolClosure, SchoolTerm, Contact, Group, GroupMembership, GroupStaff, Form, Page, Section, AttendanceRecord};
use App\Support\{TenantContext, FormOptionSources, FormSchema};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

/** Real HTTP readers; SQL and payload literals captured from cached main reader code. */
class SchoolCalendarReadersTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $org;
    private SchoolYear $year;
    private Group $group;
    private GroupMembership $member;
    private User $teacher;
    private Contact $parent;
    private Form $form;
    private Page $page;
    private \App\Models\FeePlan $feePlan;
    private \App\Models\FormResponse $formResponse;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->forgetTenant();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 16:00:00', 'UTC'));
        $this->org = Masjid::create(['name' => 'Reader School', 'email' => 'readers@example.invalid', 'phone' => '+15550007700', 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'timezone' => 'America/New_York']);
        $this->org->forceFill(['capability_overrides' => ['school_calendar' => true], 'assistant_enabled' => true])->save();
        $this->year = SchoolYear::create(['masjid_id' => $this->org->id, 'label' => '2026-2027', 'first_day' => '2026-10-12', 'last_day' => '2026-12-18', 'meeting_weekdays' => [1,2,3,4,5], 'term_system' => 'semesters']);
        SchoolTerm::create(['masjid_id' => $this->org->id, 'school_year_id' => $this->year->id, 'name' => 'Autumn', 'starts_on' => '2026-10-12', 'ends_on' => '2026-11-13', 'position' => 1]);
        SchoolClosure::create(['masjid_id' => $this->org->id, 'school_year_id' => $this->year->id, 'closed_on' => '2026-10-19', 'reason' => 'Staff day']);
        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15550007701']);
        MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Class A', 'slug' => 'class-a']);
        $this->group->staff()->attach($this->teacher->id, ['masjid_id' => $this->org->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);
        $child = Contact::factory()->create(['masjid_id' => $this->org->id, 'first_name' => 'Test', 'last_name' => 'Student']);
        $this->member = GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $child->id, 'role' => 'member', 'joined_at' => '2026-10-01']);
        $this->parent = Contact::factory()->create(['masjid_id' => $this->org->id]);
        $this->parent->forceFill(['login_email' => 'reader-parent@example.invalid', 'login_enabled_at' => now()])->save();
        $this->form = Form::create(['masjid_id' => $this->org->id, 'slug' => 'reader-form', 'name' => 'School days', 'schema' => ['sections' => [['id' => 'days', 'fields' => [['name' => 'days', 'label' => 'Days', 'type' => 'checkboxGroup', 'optionsSource' => 'school_meeting_days', 'minSelections' => 2, 'maxSelections' => 2]]]]]]);
        $offering = \App\Models\Offering::factory()->create(['masjid_id' => $this->org->id, 'intake_form_id' => $this->form->id, 'name' => 'School program', 'slug' => 'school-program']);
        $this->feePlan = \App\Models\FeePlan::factory()->free()->create(['masjid_id' => $this->org->id, 'offering_id' => $offering->id]);
        $this->formResponse = \App\Models\FormResponse::create(['masjid_id' => $this->org->id, 'form_id' => $this->form->id, 'data' => ['days' => ['2026-10-13','2026-10-19']], 'respondent_name' => 'Test Family', 'status' => 'new', 'submitted_at' => now()]);
        $this->formResponse->forceFill(['uuid' => '00000000-0000-4000-8000-000000000001'])->save();
        $this->page = Page::create(['masjid_id' => $this->org->id, 'slug' => 'reader-page', 'title' => 'School days', 'is_active' => true, 'order' => 1]);
        $section = Section::create(['masjid_id' => $this->org->id, 'section_type' => 'form', 'title' => 'School days', 'content' => ['form_id' => $this->form->id], 'is_active' => true]);
        $this->page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);
    }

    private function enable(): void
    {
        $this->org->forceFill(['capability_overrides' => ['school_calendar' => true, 'school_calendar_terms' => true]])->save();
    }

    private function authenticate(string $shape): void
    {
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        if (str_starts_with($shape, 'family')) {
            $this->withHeader('Authorization', 'Bearer '.$this->parent->createFamilyToken()->plainTextToken);
        } elseif (! str_starts_with($shape, 'form-') && ! str_starts_with($shape, 'offering-')) {
            $this->teacher->forceFill(['type' => str_starts_with($shape, 'office') || str_starts_with($shape, 'board') ? 'SuperAdmin' : 'Teacher'])->save();
            Sanctum::actingAs($this->teacher, ['staff']);
        }
    }

    private function read(string $shape, bool $authenticated = false): \Illuminate\Testing\TestResponse
    {
        if (! $authenticated) $this->authenticate($shape);
        $id = $this->org->id; $group = $this->group->id;
        if (str_starts_with($shape, 'family')) return $this->getJson('/api/family/masjids/'.$id.'/'.($shape === 'family-me' ? 'me' : 'school-calendar'));
        if (str_starts_with($shape, 'offering-')) {
            return $shape === 'offering-page'
                ? $this->getJson('/api/v1/offerings/school-program', ['masjid-id' => (string) $id])
                : $this->postJson('/api/v1/offerings/school-program/register', ['fee_plan_id' => $this->feePlan->id, 'payer' => ['name' => 'Test Family', 'email' => 'reader-family@example.invalid'], 'data' => ['days' => ['2026-10-26']]], ['masjid-id' => (string) $id]);
        }
        if (str_starts_with($shape, 'form-')) {
            return match ($shape) {
                'form-page' => $this->getJson('/api/v1/pages/reader-page', ['masjid-id' => (string) $id]),
                'form-count' => $this->postJson('/api/v1/forms/'.$this->form->id.'/responses', ['data' => ['days' => ['2026-10-26']]], ['masjid-id' => (string) $id]),
                'form-closed' => $this->postJson('/api/v1/forms/'.$this->form->id.'/responses', ['data' => ['days' => ['2026-10-19', '2026-10-26']]], ['masjid-id' => (string) $id]),
            };
        }
        $responses = '/api/admin/masjids/'.$id.'/forms/'.$this->form->id.'/responses';
        return match ($shape) {
            'office-form-index' => $this->getJson($responses),
            'office-registration' => $this->postJson('/api/admin/masjids/'.$id.'/offerings/'.$this->feePlan->offering_id.'/registrations', ['fee_plan_id' => $this->feePlan->id, 'payer_contact_id' => $this->parent->id, 'data' => ['days' => ['2026-10-26']]]),
            'office-form-update' => $this->putJson($responses.'/'.$this->formResponse->id, ['admin_notes' => 'Checked']),
            'office-form-show' => $this->getJson($responses.'/'.$this->formResponse->id),
            'office-form-search' => $this->getJson($responses.'?search=Tuesday'),
            'office-form-roster' => $this->getJson($responses.'/roster'),
            'office-form-export' => $this->get($responses.'/export'),
            'office-form-roster-export' => $this->get($responses.'/roster/export'),
            'office-form-insights' => $this->getJson('/api/admin/masjids/'.$id.'/forms/'.$this->form->id.'/insights'),
            'office-calendar' => $this->getJson('/api/admin/masjids/'.$id.'/school-calendar'),
            'teacher-calendar' => $this->getJson('/api/teacher/masjids/'.$id.'/school-calendar'),
            'teacher-header' => $this->getJson('/api/teacher/user'),
            'board' => $this->getJson('/api/admin/masjids/'.$id.'/attendance?from=2026-10-12&to=2026-10-16'),
            'board-member' => $this->getJson('/api/admin/masjids/'.$id.'/attendance/members/'.$this->member->id.'?from=2026-10-12&to=2026-10-16'),
            'office-lessons' => $this->getJson('/api/admin/masjids/'.$id.'/groups/'.$group.'/lesson-plans?from=2026-10-12&to=2026-10-18'),
            'teacher-lessons' => $this->getJson('/api/teacher/masjids/'.$id.'/groups/'.$group.'/lesson-plans?from=2026-10-12&to=2026-10-18'),
            'teacher-register', 'teacher-closed-register' => $this->getJson('/api/teacher/masjids/'.$id.'/groups/'.$group.'/attendance?date='.($shape === 'teacher-register' ? '2026-10-13' : '2026-10-19')),
        };
    }

    public static function offShapes(): array
    {
        return array_map(fn ($s) => [$s], ['office-calendar', 'teacher-calendar', 'family-calendar', 'board', 'board-member', 'office-lessons', 'teacher-lessons', 'teacher-register', 'teacher-closed-register', 'form-page', 'form-count', 'form-closed', 'office-form-index', 'office-form-show', 'office-form-search', 'office-form-roster', 'office-form-export', 'office-form-roster-export', 'office-form-insights', 'offering-page', 'offering-register', 'office-registration', 'office-form-update']);
    }

    #[Test, DataProvider('offShapes')]
    public function off_sql_and_payload_match_main_with_dormant_configuration(string $shape): void
    {
        // Authentication token creation, fixture-only role changes are outside the trace.
        $this->authenticate($shape);
        DB::flushQueryLog(); DB::enableQueryLog();
        $response = $this->read($shape, true);
        $data = str_contains($shape, 'export') ? $response->streamedContent() : $response->json();
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        $response->assertStatus((str_starts_with($shape, 'form-') && $shape !== 'form-page') || in_array($shape, ['offering-register', 'office-registration'], true) ? 422 : 200);
        $file = base_path('tests/fixtures/calendar-readers/'.$shape.'.json');
        if (getenv('CAPTURE_READER_SQL') === '1') file_put_contents($file, json_encode(['sql' => $sql, 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $literal = json_decode(file_get_contents($file), true);
        // No data timestamps vary: the fixture clock is fixed.
        $expected = $literal['sql'];
        $at = match ($shape) {
            'teacher-calendar' => 2,
            'teacher-lessons', 'teacher-register', 'teacher-closed-register' => 4,
            'form-page' => 6,
            'form-count', 'form-closed' => 2,
            default => null,
        };
        if ($at !== null && getenv('CAPTURE_READER_SQL') !== '1') array_splice($expected, $at, 0, ['select * from "masjids" where "masjids"."id" = ? and "masjids"."deleted_at" is null limit 1']);
        $this->assertSame($expected, $sql, $shape.' OFF statements changed');
        $this->assertSame($literal['data'], $data, $shape.' OFF response changed');
    }

    #[Test, DataProvider('offShapes')]
    public function enabled_real_endpoints_do_not_reload_calendars_per_row_rule_or_validator(string $shape): void
    {
        $this->enable();
        $schema = $this->form->schema;
        for ($i = 1; $i <= 20; $i++) {
            $field = $schema['sections'][0]['fields'][0]; $field['name'] = 'other_days_'.$i;
            $schema['sections'][0]['fields'][] = $field;
        }
        $this->form->update(['schema' => $schema]);
        $this->authenticate($shape);
        DB::flushQueryLog(); DB::enableQueryLog();
        $response = $this->read($shape, true);
        if (str_contains($shape, 'export')) $response->streamedContent();
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        $response->assertStatus((str_starts_with($shape, 'form-') && $shape !== 'form-page') || in_array($shape, ['offering-register', 'office-registration'], true) ? 422 : 200);
        foreach (['school_years','school_closures','school_terms'] as $table) {
            $this->assertLessThanOrEqual(1, count(array_filter($sql, fn ($q) => str_contains($q, 'from "'.$table.'"'))), $shape.' reloads '.$table);
        }
        $this->assertFalse(request()->attributes->has(\App\Support\SchoolCalendarReaders::ATTRIBUTE));
    }

    #[Test, DataProvider('calendars')]
    public function five_day_calendar_and_twelve_successive_days_show_read_only_terms(string $shape): void
    {
        $this->enable();
        $data = $this->read($shape)->assertOk()->json('data');
        $this->assertSame([1,2,3,4,5], $data['years'][0]['meeting_weekdays']);
        $this->assertSame(1, $data['years'][0]['meeting_weekday']);
        $this->assertSame(['2026-10-12','2026-10-13','2026-10-14','2026-10-15','2026-10-16'], array_slice($data['years'][0]['meeting_days'], 0, 5));
        $this->assertSame('semesters', $data['years'][0]['term_system']);
        $this->assertSame(['id','name','starts_on','ends_on','position'], array_keys($data['years'][0]['terms'][0]));
        $this->assertSame('Autumn', $data['years'][0]['terms'][0]['name']);
        if ($shape !== 'office-calendar') {
            $this->assertCount(12, $data['upcoming']);
            $this->assertSame(['2026-10-13','2026-10-14','2026-10-15','2026-10-16','2026-10-19'], array_slice(array_column($data['upcoming'], 'date'), 0, 5));
            $this->assertSame(['date' => '2026-10-19', 'closed' => true, 'reason' => 'Staff day'], $data['upcoming'][4]);
        }
    }

    public static function calendars(): array { return [['office-calendar'],['teacher-calendar'],['family-calendar']]; }

    #[Test]
    public function attendance_columns_today_hint_and_unchanged_mark_denominators(): void
    {
        $this->enable();
        AttendanceRecord::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'group_membership_id' => $this->member->id, 'session_date' => '2026-10-11', 'status' => 'present', 'marked_by_user_id' => $this->teacher->id]);
        $data = $this->read('board')->assertOk()->json('data');
        $this->assertSame(['2026-10-12','2026-10-13','2026-10-14','2026-10-15','2026-10-16'], array_column($data['days'], 'date'));
        $this->assertTrue($data['today']['school_day']['meeting_day']);
        $this->assertTrue($this->read('teacher-register')->assertOk()->json('data.school_day.meeting_day'));
        $this->read('teacher-closed-register')->assertOk()->assertJsonPath('data.students', [])->assertJsonPath('data.school_day.closed', true);
    }

    #[Test, DataProvider('lessons')]
    public function lesson_weeks_exclude_closures_and_unrelated_years(string $shape): void
    {
        $this->enable();
        SchoolClosure::create(['masjid_id' => $this->org->id, 'school_year_id' => $this->year->id, 'closed_on' => '2026-10-14', 'reason' => 'Planning day']);
        SchoolYear::create(['masjid_id' => $this->org->id, 'label' => 'Next', 'first_day' => '2027-01-03', 'last_day' => '2027-02-28', 'meeting_weekdays' => [0]]);
        $this->read($shape)->assertOk()->assertJsonPath('data.meeting_weekdays', [1,2,4,5]);
    }
    public static function lessons(): array { return [['teacher-lessons'],['office-lessons']]; }

    #[Test]
    public function forms_offer_open_days_label_closed_answers_and_use_plural_days(): void
    {
        $this->enable();
        $options = $this->read('form-page')->assertOk()->json('data.sections.0.content.form.schema.sections.0.fields.0.options');
        $this->assertSame(['2026-10-14','2026-10-15','2026-10-16','2026-10-20'], array_slice(array_column($options, 'value'), 0, 4));
        $this->assertSame('Wednesday, October 14, 2026', $options[0]['label']);
        $this->read('form-count')->assertUnprocessable()->assertJsonPath('data.days', ['Pick exactly 2 days.']);
        $this->read('form-closed')->assertUnprocessable();
        app(TenantContext::class)->forgetTenant();
        $labels = collect(FormOptionSources::resolve($this->form, ['optionsSource' => 'school_meeting_days'], FormOptionSources::LABEL, ['2025-01-01']))->keyBy('value');
        $this->assertSame('No school — Staff day', $labels['2026-10-19']['detail']);
        $this->assertSame('Tuesday, October 13, 2026', $labels['2026-10-13']['label']);
        $this->assertSame('Wednesday, January 1, 2025', $labels['2025-01-01']['label']);
    }

    #[Test]
    public function calendar_links_keep_working_for_five_day_schools(): void
    {
        $this->enable();
        $this->read('teacher-header')->assertOk()->assertJsonPath('data.school_calendar_published', true);
        $this->read('family-me')->assertOk()->assertJsonPath('data.school_calendar_published', true);
    }
    #[Test]
    public function on_default_register_and_lesson_week_follow_the_school_clock_at_dst_boundaries(): void
    {
        $this->enable(); $this->authenticate('teacher-register');
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 04:30:00', 'UTC'));
        $base = '/api/teacher/masjids/'.$this->org->id.'/groups/'.$this->group->id;
        $this->getJson($base.'/attendance')->assertOk()->assertJsonPath('data.session_date', '2026-11-01');
        $this->getJson($base.'/lesson-plans')->assertOk()->assertJsonPath('data.from', '2026-10-26');
        $this->year->update(['first_day' => '2026-03-02', 'last_day' => '2026-03-20']);
        $this->travelTo(\Carbon\Carbon::parse('2026-03-09 03:30:00', 'UTC'));
        $this->getJson($base.'/attendance')->assertOk()->assertJsonPath('data.session_date', '2026-03-08');
        $this->getJson($base.'/lesson-plans')->assertOk()->assertJsonPath('data.from', '2026-03-02');
    }

    #[Test]
    public function weekend_on_dates_equal_legacy_dates_and_null_does_not_become_empty(): void
    {
        $this->enable();
        $this->year->update(['first_day' => '2026-10-11', 'last_day' => '2026-12-20', 'meeting_weekdays' => null]);
        $on = $this->read('teacher-calendar')->assertOk()->json('data');
        $this->assertSame([0], $on['years'][0]['meeting_weekdays']);
        $this->assertNull($this->year->fresh()->meeting_weekdays);
        $this->org->forceFill(['capability_overrides' => ['school_calendar' => true]])->save();
        $off = $this->read('teacher-calendar')->assertOk()->json('data');
        $this->assertSame($off['years'][0]['meeting_days'], $on['years'][0]['meeting_days']);
        $this->assertSame($off['upcoming'], $on['upcoming']);
        $this->assertArrayNotHasKey('terms', $off['years'][0]);
        $this->year->update(['meeting_weekdays' => []]); $this->enable();
        $this->read('teacher-calendar')->assertOk()->assertJsonPath('data.years.0.meeting_weekdays', [])->assertJsonPath('data.years.0.meeting_days', []);
    }

    #[Test]
    public function year_boundary_within_a_week_and_two_years_in_the_request_use_only_open_dates(): void
    {
        $this->enable();
        $this->year->terms()->delete();
        $this->year->update(['last_day' => '2026-10-14', 'meeting_weekdays' => [1,3]]);
        $this->year->closures()->delete();
        SchoolClosure::create(['masjid_id' => $this->org->id, 'school_year_id' => $this->year->id, 'closed_on' => '2026-10-14', 'reason' => 'Planning day']);
        SchoolYear::create(['masjid_id' => $this->org->id, 'label' => 'Next year', 'first_day' => '2026-10-15', 'last_day' => '2026-10-31', 'meeting_weekdays' => [4,6]]);
        $this->read('teacher-lessons')->assertOk()->assertJsonPath('data.meeting_weekdays', [1,4,6]);
        $on = $this->read('teacher-calendar')->assertOk()->json('data');
        $this->assertSame(['2026-10-14','2026-10-15','2026-10-17','2026-10-22','2026-10-24','2026-10-29','2026-10-31'], array_column($on['upcoming'], 'date'));
    }

    #[Test]
    public function forty_column_fallback_and_actual_marks_remain_unchanged_when_on(): void
    {
        $this->enable(); $this->authenticate('board');
        AttendanceRecord::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'group_membership_id' => $this->member->id, 'session_date' => '2026-10-11', 'status' => 'present', 'marked_by_user_id' => $this->teacher->id]);
        $base = '/api/admin/masjids/'.$this->org->id.'/attendance';
        $data = $this->getJson($base.'?from=2026-10-11&to=2026-10-16')->assertOk()->json('data');
        $this->assertSame('2026-10-11', $data['days'][0]['date']);
        $this->assertSame(1, $data['students'][0]['totals']['registers']);
        $this->assertSame(1, $data['students'][0]['totals']['present']);
        $this->getJson($base.'?from=2026-10-12&to=2026-12-18')->assertOk()->assertJsonPath('data.meta.grid_omitted', true)->assertJsonPath('data.meta.column_cap', 40)->assertJsonPath('data.students', []);
    }

    #[Test]
    public function register_closure_refusal_and_makeup_acceptance_are_not_tightened(): void
    {
        $this->enable(); $this->authenticate('teacher-register');
        $base = '/api/teacher/masjids/'.$this->org->id.'/groups/'.$this->group->id.'/attendance';
        $body = ['session_date' => '2026-10-11', 'marks' => [['membership_id' => $this->member->id, 'status' => 'present']]];
        $this->putJson($base, $body)->assertOk();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-20 16:00:00', 'UTC'));
        $body['session_date'] = '2026-10-19';
        $this->putJson($base, $body)->assertUnprocessable()->assertJsonPath('data.session_date.0', 'There was no school on Monday, October 19, 2026 (Staff day), so there is no register to take.');
        $this->assertSame(1, AttendanceRecord::count());
    }

    #[Test]
    public function remaining_date_year_end_and_report_card_consumers_keep_working_on(): void
    {
        $this->enable();
        $calendar = \App\Support\SchoolCalendar::for($this->org->id);
        $this->assertSame(['2026-12-19'], \App\Support\BucksExpiry::allCutoffs($this->group, $calendar));
        $this->assertSame('2026-10-13', $calendar->today());
        $this->assertSame('2026-10-11', \App\Support\PointsWeek::containing(now(), 'America/New_York')->startDate());
        foreach (['2026-10-11' => 'present', '2026-10-12' => 'late', '2026-10-13' => 'absent'] as $day => $status) AttendanceRecord::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'group_membership_id' => $this->member->id, 'session_date' => $day, 'status' => $status, 'marked_by_user_id' => $this->teacher->id]);
        $service = new \App\Services\Schools\ReportCardService;
        $card = $service->prepare($this->member, 'report_card', '2026-2027', 1);
        $service->publish($card);
        $this->assertSame([2,1,1], [$card->days_present, $card->days_absent, $card->days_late]);
        $this->assertSame(1, $card->term);
    }

    #[Test]
    public function all_on_readers_share_one_year_closure_and_term_load_per_request(): void
    {
        $this->enable();
        for ($i = 0; $i < 4; $i++) {
            $year = SchoolYear::create(['masjid_id' => $this->org->id, 'label' => 'Future '.($i + 1), 'first_day' => (2027 + $i).'-10-11', 'last_day' => (2027 + $i).'-10-25', 'meeting_weekdays' => [1,2,3,4,5]]);
            SchoolTerm::create(['masjid_id' => $this->org->id, 'school_year_id' => $year->id, 'name' => 'Term', 'starts_on' => (2027 + $i).'-10-11', 'ends_on' => (2027 + $i).'-10-25', 'position' => 1]);
        }
        \Illuminate\Support\Facades\Route::get('/api/_reader-cache', function () {
            $calendar = \App\Support\SchoolCalendarReaders::for($this->org->id);
            $this->assertSame($calendar, \App\Support\SchoolCalendarReaders::for($this->org->id));
            FormOptionSources::resolve($this->form, ['optionsSource' => 'school_meeting_days'], FormOptionSources::OFFER);
            FormOptionSources::resolve($this->form, ['optionsSource' => 'school_meeting_days'], FormOptionSources::LABEL);
            FormSchema::for($this->form)->validator(['days' => ['2026-10-26']])->fails();
            return response()->json(\App\Support\SchoolCalendarReaderPayload::reader($calendar));
        });
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson('/api/_reader-cache')->assertOk()->assertJsonCount(5, 'years');
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        foreach (['school_years','school_closures','school_terms'] as $table) $this->assertCount(1, array_filter($sql, fn ($q) => str_contains($q, 'from "'.$table.'"')));
        $this->assertFalse(request()->attributes->has(\App\Support\SchoolCalendarReaders::ATTRIBUTE));
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson('/api/_reader-cache')->assertOk();
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        $this->assertCount(1, array_filter($sql, fn ($q) => str_contains($q, 'from "school_years"')));
    }

    #[Test]
    public function on_calendar_readers_remain_tenant_isolated(): void
    {
        $this->enable();
        $this->authenticate('teacher-calendar');
        $this->getJson('/api/teacher/masjids/999999/school-calendar')->assertForbidden();
        $this->authenticate('family-calendar');
        $this->getJson('/api/family/masjids/999999/school-calendar')->assertForbidden();
        app(TenantContext::class)->set(999999);
        $calendar = \App\Support\SchoolDateAuthority::for($this->org->id);
        $this->assertSame([], $calendar->labelledDays());
        $this->assertSame([], $calendar->upcoming());
    }

    #[Test]
    public function an_http_authority_cache_does_not_cross_a_tenant_binding(): void
    {
        $this->enable();
        \Illuminate\Support\Facades\Route::get('/api/_reader-tenant-cache', function () {
            $calendar = \App\Support\SchoolCalendarReaders::for($this->org->id);
            $this->assertNotEmpty($calendar->labelledDays());
            app(TenantContext::class)->set(999999);
            return response()->json(['days' => \App\Support\SchoolCalendarReaders::for($this->org->id)->labelledDays()]);
        });
        $this->getJson('/api/_reader-tenant-cache')->assertOk()->assertJsonPath('days', []);
    }

    #[Test]
    public function on_form_readers_label_existing_answers_without_rewriting_them(): void
    {
        $this->enable();
        $before = $this->formResponse->fresh()->getAttributes();
        $index = $this->read('office-form-index')->assertOk()->json();
        $this->assertStringContainsString('Tuesday, October 13, 2026', json_encode($index));
        $this->assertStringContainsString('No school', json_encode($index));
        $search = $this->read('office-form-search')->assertOk();
        $this->assertSame(1, $search->json('data.total'));
        $export = $this->read('office-form-export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Tuesday, October 13, 2026', $export);
        $roster = $this->read('office-form-roster')->assertOk()->json();
        $this->assertStringContainsString('Tuesday, October 13, 2026', json_encode($roster));
        $this->read('office-form-insights')->assertOk();
        $this->assertSame($before, $this->formResponse->fresh()->getAttributes());
        // The coordinator mail deliberately omits choose-any fields. A choose-one
        // school day is named in words even when it is subsequently closed.
        $schema = $this->form->schema; $schema['sections'][0]['fields'][0]['type'] = 'radio';
        unset($schema['sections'][0]['fields'][0]['minSelections'], $schema['sections'][0]['fields'][0]['maxSelections']);
        $this->form->update(['schema' => $schema]);
        $this->formResponse->update(['data' => ['days' => '2026-10-13']]);
        app(TenantContext::class)->forgetTenant();
        $people = \App\Support\FormNotifier::people($this->form->fresh(), $this->formResponse->fresh());
        $this->assertStringContainsString('Tuesday, October 13, 2026', json_encode($people));
    }

    #[Test]
    public function offering_intake_uses_the_same_resolved_school_days_and_plural_validation(): void
    {
        $this->enable();
        $page = $this->read('offering-page')->assertOk()->json();
        $this->assertStringContainsString('Wednesday, October 14, 2026', json_encode($page));
        $registration = $this->read('offering-register')->assertUnprocessable();
        $this->assertStringContainsString('Pick exactly 2 days.', json_encode($registration->json()));
        $this->assertStringContainsString('Pick exactly 2 days.', json_encode($this->read('office-registration')->assertUnprocessable()->json()));
    }

}
