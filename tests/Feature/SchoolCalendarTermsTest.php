<?php

namespace Tests\Feature;

use App\Models\{Masjid, MasjidUser, SchoolYear, SchoolClosure, SchoolTerm, User};
use App\Support\{CapabilityWriter, SchoolDateAuthority, SchoolSettings, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolCalendarTermsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-08 16:00:00', 'UTC'));
        $this->school = $this->makeMasjid();
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550001001']);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        Sanctum::actingAs($this->admin);
    }

    private function makeMasjid(): Masjid
    {
        $org = Masjid::create(['name' => 'Test School '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
        $org->forceFill(['capability_overrides' => ['school_calendar' => true]])->save();
        return $org;
    }

    private function url(string $suffix = ''): string { return '/api/admin/masjids/'.$this->school->id.'/school-calendar'.$suffix; }
    private function enable(): void { CapabilityWriter::apply($this->school, ['school_calendar_terms' => true], $this->admin->id); }
    private function year(array $extra = []): SchoolYear
    {
        return app(TenantContext::class)->runWithout(fn () => SchoolYear::create($extra + ['masjid_id' => $this->school->id, 'label' => 'Test year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25']));
    }
    private function weekdays(): array { return ['label' => 'Day school', 'first_day' => '2026-10-12', 'last_day' => '2026-10-23', 'meeting_weekdays' => [5, 3, 1, 2, 4], 'term_system' => 'semesters']; }

    /** Submit the complete modal draft through the year endpoint. */
    private function saveDraftTerms(SchoolYear $year, array $terms): \Illuminate\Testing\TestResponse
    {
        return $this->putJson($this->url('/years/'.$year->id), ['label'=>$year->label, 'first_day'=>$year->first_day->toDateString(), 'last_day'=>$year->last_day->toDateString(), 'meeting_weekdays'=>SchoolDateAuthority::weekdays($year), 'term_system'=>$year->term_system, 'terms'=>$terms]);
    }

    #[Test]
    public function schema_is_nullable_and_switch_on_initializes_legacy_years_atomically(): void
    {
        $year = $this->year();
        $this->assertNull($year->fresh()->meeting_weekdays);
        $this->assertFalse(SchoolSettings::calendarTerms($this->school->fresh()));
        $timestamp = $year->updated_at->toDateTimeString();
        $this->travel(1)->hours();
        $this->enable();
        $this->assertSame($timestamp, $year->fresh()->updated_at->toDateTimeString());
        $this->assertSame([0], $year->fresh()->meeting_weekdays);
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.years.0.meeting_days', ['2026-10-11', '2026-10-18', '2026-10-25']);
    }

    #[Test]
    public function office_year_closures_and_terms_round_trip(): void
    {
        $this->enable();
        $r = $this->post($this->url('/years'), $this->weekdays(), ['Accept' => 'application/json'])->assertCreated();
        $id = $r->json('data.years.0.id');
        $r->assertJsonPath('data.years.0.meeting_weekdays', [1,2,3,4,5])->assertJsonPath('data.years.0.term_system', 'semesters');
        $closed = $this->postJson($this->url('/closures'), ['school_year_id' => $id, 'closed_on' => '2026-10-13', 'reason' => 'Staff day'])->assertCreated();
        $closure = $closed->json('data.years.0.closures.0.id');
        $term = $this->saveDraftTerms(SchoolYear::findOrFail($id), [['name' => 'First term', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16', 'position' => 1]])->assertOk();
        $tid = $term->json('data.years.0.terms.0.id');
        $this->saveDraftTerms(SchoolYear::findOrFail($id), [['id'=>$tid, 'name' => 'Autumn', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-15', 'position' => 1]])->assertOk()->assertJsonPath('data.years.0.terms.0.name', 'Autumn');
        $later = ['name' => 'Later', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-23', 'position' => 3];
        $this->saveDraftTerms(SchoolYear::findOrFail($id), [['id'=>$tid, 'name'=>'Autumn', 'starts_on'=>'2026-10-12', 'ends_on'=>'2026-10-15', 'position'=>1], $later])->assertOk();
        $this->saveDraftTerms(SchoolYear::findOrFail($id), [['id'=>SchoolTerm::where('position',3)->firstOrFail()->id]+$later])->assertOk()->assertJsonPath('data.years.0.terms.0.position', 3);
        $this->deleteJson($this->url("/closures/$closure"))->assertOk();
        $this->assertContains('2026-10-13', SchoolDateAuthority::for($this->school->id)->openDaysBetween('2026-10-12', '2026-10-16'));
    }

    public static function badYears(): array
    {
        return [
            'absent' => [['meeting_weekdays' => '__absent'], 'meeting_weekdays', 'Choose at least one meeting day.'],
            'null' => [['meeting_weekdays' => null], 'meeting_weekdays', 'Choose at least one meeting day.'],
            'empty' => [['meeting_weekdays' => []], 'meeting_weekdays', 'Choose at least one meeting day.'],
            'first off' => [['meeting_weekdays' => [2,5]], 'first_day', 'The first day must be one of the days the school meets.'],
            'last off' => [['meeting_weekdays' => [1,2]], 'last_day', 'The last day must be one of the days the school meets.'],
            'order' => [['last_day' => '2026-10-05'], 'last_day', 'The last day cannot be before the first day.'],
            'length' => [['last_day' => '2027-10-22'], 'last_day', 'A school year cannot run longer than a year.'],
        ];
    }
    #[Test, DataProvider('badYears')]
    public function enabled_year_rules_refuse_in_words(array $change, string $field, string $message): void
    {
        $this->enable(); $data = array_replace($this->weekdays(), $change);
        if (($data['meeting_weekdays'] ?? null) === '__absent') unset($data['meeting_weekdays']);
        $this->postJson($this->url('/years'), $data)->assertUnprocessable()->assertJsonPath("data.$field.0", $message);
        $this->assertSame(0, SchoolYear::count());
    }

    #[Test]
    public function removals_and_switch_off_never_silently_strand_data(): void
    {
        $this->enable(); $year = $this->year(['first_day' => '2026-10-12', 'last_day' => '2026-10-19', 'meeting_weekdays' => [1,2], 'term_system' => null]);
        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-10-13', 'reason' => 'Staff day'])->assertCreated();
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Test year', 'first_day' => '2026-10-12', 'last_day' => '2026-10-19', 'meeting_weekdays' => [1], 'term_system' => null])->assertUnprocessable()->assertJsonPath('data.meeting_weekdays.0', 'Remove the no-school days on removed weekdays first: Tuesday, October 13, 2026.');
        try { CapabilityWriter::apply($this->school->fresh(), ['school_calendar_terms' => false], $this->admin->id); $this->fail('Unsafe switch-off succeeded'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('This school year meets on more than one day of the week. Change it to one meeting day before switching dated terms off.', $e->errors()['capability'][0]); }
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()));
    }

    #[Test]
    public function safe_switch_off_keeps_terms_and_both_generic_toggle_paths_use_the_writer(): void
    {
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550001002']); Sanctum::actingAs($super);
        $year = $this->year();
        $base = '/api/admin/masjids/'.$this->school->id.'/capabilities';
        $this->patchJson($base.'/school_calendar_terms', ['enabled' => true])->assertOk();
        $this->assertSame([0], $year->fresh()->meeting_weekdays);
        $this->saveDraftTerms($year, [['name' => 'Term', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1]])->assertOk();
        $this->patchJson($base, ['capabilities' => ['school_calendar_terms' => false]])->assertOk();
        $this->assertFalse(SchoolSettings::calendarTerms($this->school->fresh()));
        $this->assertSame(1, SchoolTerm::count());
    }

    #[Test]
    public function term_model_scope_and_stamping_and_routes_keep_tenants_apart(): void
    {
        $this->enable(); $mine = $this->year(); $other = $this->makeMasjid();
        $foreign = $this->year(['masjid_id' => $other->id]);
        $term = app(TenantContext::class)->runWithout(fn () => SchoolTerm::create(['masjid_id' => $other->id, 'school_year_id' => $foreign->id, 'name' => 'Foreign', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1]));
        app(TenantContext::class)->set($this->school->id);
        $this->assertNull(SchoolTerm::find($term->id));
        $this->assertSame(0, SchoolTerm::whereKey($term->id)->update(['name' => 'Wrong']));
        $this->assertSame(0, SchoolTerm::whereKey($term->id)->delete());
        $stamped = SchoolTerm::create(['masjid_id' => $other->id, 'school_year_id' => $mine->id, 'name' => 'Mine', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1]);
        $this->assertSame($this->school->id, (int) $stamped->masjid_id);
        $body = ['name' => 'Wrong', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 2];
        $this->putJson($this->url('/years/'.$foreign->id), ['label'=>'Foreign', 'first_day'=>'2027-10-10', 'last_day'=>'2027-10-24', 'meeting_weekdays'=>[0], 'terms'=>[]])->assertNotFound();
        $this->saveDraftTerms($mine, [['id'=>$term->id]+$body])->assertUnprocessable()->assertJsonPath('data', ['terms.0.id'=>['Wrong: That term does not belong to this school year.']]);
        $this->assertNotNull(app(TenantContext::class)->runWithout(fn () => SchoolTerm::find($term->id)));
        $this->getJson('/api/admin/masjids/'.$other->id.'/school-calendar')->assertForbidden();
    }
    public static function offRequests(): array
    {
        return [
            'office GET with dormant config' => ['GET', '', [], true, 200, null],
            'office edit ignores extra fields' => ['PUT', '/years/101', ['label' => 'Test year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25', 'meeting_weekdays' => [], 'term_system' => 'invalid'], true, 200, null],
            'closure wrong weekday' => ['POST', '/closures', ['school_year_id' => 101, 'closed_on' => '2026-10-12', 'reason' => 'Staff day'], true, 422, ['status' => 'failed', 'data' => ['closed_on' => ['Monday, October 12, 2026 is not a Sunday, the day this school meets.']]]],
            'year matching-weekday rule' => ['POST', '/years', ['label' => 'Next', 'first_day' => '2027-01-03', 'last_day' => '2027-01-09', 'meeting_weekdays' => [0,6]], false, 422, ['status' => 'failed', 'data' => ['last_day' => ['The last day must be a Sunday, the day the year starts on (Sunday, January 3, 2027).']]]],
            'closure edit reason only' => ['PUT', '/closures/201', ['reason' => 'Staff day', 'closed_on' => '2026-10-12'], true, 200, null],
        ];
    }

    /** Literals derived from frozen origin/main controller, requests and SchoolCalendarPayload. */
    #[Test, DataProvider('offRequests')]
    public function off_real_request_shapes_keep_complete_bodies_and_rows(string $method, string $suffix, array $body, bool $dormant, int $status, ?array $expected): void
    {
        $year = $this->year($dormant ? ['meeting_weekdays' => [1,2,3,4,5], 'term_system' => 'semesters'] : []);
        $year->forceFill(['id' => 101])->save();
        $closure = app(TenantContext::class)->runWithout(fn () => SchoolClosure::create(['masjid_id' => $this->school->id, 'school_year_id' => 101, 'closed_on' => '2026-10-18', 'reason' => 'Staff day']));
        $closure->forceFill(['id' => 201])->save();
        if ($dormant) app(TenantContext::class)->runWithout(fn () => SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => 101, 'name' => 'Dormant', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1]));
        $expected ??= ['status' => 'success', 'data' => ['timezone' => 'America/New_York', 'today' => '2026-10-08', 'years' => [
            ['id' => 101, 'label' => 'Test year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25', 'meeting_weekday' => 0, 'meeting_days' => ['2026-10-11','2026-10-18','2026-10-25'], 'closures' => [['id' => 201, 'closed_on' => '2026-10-18', 'reason' => 'Staff day']]],
        ]]];
        // Form encoding is what ApiService sends; GET carries no body.
        $response = $method === 'GET' ? $this->getJson($this->url()) : $this->call($method, $this->url($suffix), $body, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertStatus($status); $this->assertSame($expected, $response->json());
        $this->assertSame([['id' => 101, 'masjid_id' => $this->school->id, 'label' => 'Test year', 'first_day' => '2026-10-11 00:00:00', 'last_day' => '2026-10-25 00:00:00', 'created_at' => '2026-10-08 16:00:00', 'updated_at' => '2026-10-08 16:00:00', 'meeting_weekdays' => $dormant ? '[1,2,3,4,5]' : null, 'term_system' => $dormant ? 'semesters' : null]], array_map(fn ($r) => (array) $r, \Illuminate\Support\Facades\DB::table('school_years')->get()->all()));
        $this->assertSame([['id' => 201, 'masjid_id' => $this->school->id, 'school_year_id' => 101, 'closed_on' => '2026-10-18 00:00:00', 'reason' => 'Staff day', 'created_at' => '2026-10-08 16:00:00', 'updated_at' => '2026-10-08 16:00:00']], array_map(fn ($r) => (array) $r, \Illuminate\Support\Facades\DB::table('school_closures')->get()->all()));
    }

    // With the switch ON the readers now use the date authority (slice C: SchoolCalendarReadersTest).
    public static function readerRealms(): array { return [['teacher', false], ['family', false]]; }
    #[Test, DataProvider('readerRealms')]
    public function readers_remain_legacy_while_off(string $realm, bool $on): void
    {
        $year = $this->year(['meeting_weekdays' => [0,1,2,3,4,5], 'term_system' => 'quarters']);
        $year->forceFill(['id' => 101])->save();
        if ($on) $this->school->forceFill(['capability_overrides' => ['school_calendar' => true, 'school_calendar_terms' => true]])->save();
        if ($realm === 'teacher') {
            $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15550001009']);
            MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
            Sanctum::actingAs($teacher, ['staff']);
        } else {
            $parent = \App\Models\Contact::factory()->create(['masjid_id' => $this->school->id]);
            $parent->forceFill(['login_email' => 'parent@example.invalid', 'login_enabled_at' => now()])->save();
            \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
            $this->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken);
        }
        $r = $this->getJson('/api/'.$realm.'/masjids/'.$this->school->id.'/school-calendar')->assertOk();
        $this->assertSame(['status' => 'success', 'data' => ['timezone' => 'America/New_York', 'today' => '2026-10-08', 'years' => [['id' => 101, 'label' => 'Test year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25', 'meeting_weekday' => 0, 'meeting_days' => ['2026-10-11','2026-10-18','2026-10-25'], 'closures' => []]], 'upcoming' => [['date' => '2026-10-11','closed' => false,'reason' => null],['date' => '2026-10-18','closed' => false,'reason' => null],['date' => '2026-10-25','closed' => false,'reason' => null]]]], $r->json());
    }

    public static function yearDeleteHolds(): array { return [['closure'], ['attendance'], ['answer']]; }

    #[Test, DataProvider('yearDeleteHolds')]
    public function enabled_year_deletion_retains_existing_refusals_and_does_not_delete_its_terms(string $hold): void
    {
        $this->enable(); $year = $this->year();
        $term = SchoolTerm::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'name'=>'Term','starts_on'=>'2026-10-11','ends_on'=>'2026-10-25','position'=>1]);
        if ($hold === 'closure') SchoolClosure::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'closed_on'=>'2026-10-18','reason'=>'Staff day']);
        if ($hold === 'attendance') {
            $group = \App\Models\Group::factory()->create(['masjid_id'=>$this->school->id,'slug'=>'delete-test','kind'=>'class']);
            $child = \App\Models\Contact::factory()->create(['masjid_id'=>$this->school->id]);
            $member = \App\Models\GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$group->id,'contact_id'=>$child->id,'role'=>'member']);
            \App\Models\AttendanceRecord::create(['masjid_id'=>$this->school->id,'group_id'=>$group->id,'group_membership_id'=>$member->id,'session_date'=>'2026-10-18','status'=>'present']);
        }
        if ($hold === 'answer') {
            $form = \App\Models\Form::create(['masjid_id'=>$this->school->id,'slug'=>'calendar-delete-test','name'=>'Test form',
                'schema'=>['sections'=>[['id'=>'help','title'=>'Help','fields'=>[
                    ['name'=>'parentName','label'=>'Name','type'=>'text','required'=>true],
                    ['name'=>'cleaning','label'=>'Days','type'=>'checkboxGroup','optionsSource'=>'school_meeting_days'],
                ]]]], 'settings'=>['identity'=>['name'=>'parentName']]]);
            $this->postJson('/api/v1/forms/'.$form->id.'/responses',['data'=>['parentName'=>'Test participant','cleaning'=>['2026-10-18']]],['masjid-id'=>(string)$this->school->id])->assertOk();
        }
        $message = match ($hold) { 'closure'=>'1 no-school day', 'attendance'=>'1 attendance mark inside its dates', 'answer'=>'1 form answer naming its days' };
        $this->deleteJson($this->url('/years/'.$year->id))->assertUnprocessable()->assertJsonPath('data.year.0','This school year cannot be deleted while it has '.$message.'.');
        $this->assertNotNull($year->fresh()); $this->assertNotNull($term->fresh());
    }

    public static function badTerms(): array
    {
        return [
            [['starts_on' => '2026-10-10'], 'starts_on', 'A term must be inside its school year.'],
            [['ends_on' => '2026-10-26'], 'ends_on', 'A term must be inside its school year.'],
            [['ends_on' => '2026-10-11'], 'ends_on', 'A term cannot end before it starts.'],
            [['starts_on' => '2026-10-15','ends_on' => '2026-10-20','position' => 2], 'starts_on', 'Term dates must not overlap.'],
            [['starts_on' => '2026-10-20','ends_on' => '2026-10-21','position' => 1], 'position', 'That term number is already in use.'],
            [['starts_on' => '2026-10-11','ends_on' => '2026-10-11','position' => 3], 'position', 'Term numbers must follow date order.'],
        ];
    }
    #[Test, DataProvider('badTerms')]
    public function terms_refuse_bad_ranges_overlap_positions_and_order_in_words(array $change, string $field, string $message): void
    {
        $this->enable(); $year = $this->year();
        $this->saveDraftTerms($year, [['name' => 'First', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16', 'position' => 1]])->assertOk();
        $first = SchoolTerm::firstOrFail();
        $response = $this->saveDraftTerms($year, [['id'=>$first->id, 'name'=>'First', 'starts_on'=>'2026-10-12', 'ends_on'=>'2026-10-16', 'position'=>1], array_replace(['name' => 'Second', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13', 'position' => 2], $change)])->assertUnprocessable();
        $this->assertContains('Second: '.$message, $response->json('data')['terms.1.'.$field]);
        $this->assertSame(1, SchoolTerm::count());
        $this->deleteJson($this->url('/years/'.$year->id))->assertOk()->assertJsonPath('data.years', []);
        $this->assertSame(0, SchoolTerm::count());
    }

    #[Test]
    public function post_validation_year_and_closure_races_repeat_the_checks_under_lock(): void
    {
        $this->enable(); $year = $this->year(['first_day' => '2026-10-12','last_day' => '2026-10-23','meeting_weekdays' => [1,2,3,4,5]]);
        $checked = false;
        $this->app->afterResolving(\App\Http\Requests\Admin\SchoolCalendar\StoreSchoolClosureRequest::class, function ($request) use ($year, &$checked) {
            $checked = (fn () => $this->validator !== null)->call($request);
            SchoolYear::whereKey($year->id)->update(['meeting_weekdays' => [1,5]]);
        });
        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-10-13','reason' => 'Staff day'])->assertUnprocessable()->assertJsonPath('data.closed_on.0','This date is no longer inside its school year or on one of the days the school meets. Reload the calendar and try again.');
        $this->assertTrue($checked); $this->assertSame(0, SchoolClosure::count());
        $this->app->afterResolving(\App\Http\Requests\Admin\SchoolCalendar\StoreSchoolYearRequest::class, fn () => $this->year(['label' => 'Other year','first_day' => '2027-01-04','last_day' => '2027-01-08','meeting_weekdays' => [1,2,3,4,5]]));
        $this->postJson($this->url('/years'), ['label' => 'Next','first_day' => '2027-01-04','last_day' => '2027-01-08','meeting_weekdays' => [1,2,3,4,5]])->assertUnprocessable()->assertJsonPath('data.first_day.0','These dates overlap the Other year school year (Monday, January 4, 2027 to Friday, January 8, 2027).');
    }

    #[Test]
    public function attendance_blocks_closing_and_removing_its_weekday_without_changing_count_rules(): void
    {
        $this->enable(); $year = $this->year(['first_day' => '2026-10-12', 'last_day' => '2026-10-19', 'meeting_weekdays' => [1,2]]);
        app(TenantContext::class)->runWithout(function () {
            $group = \App\Models\Group::factory()->create(['masjid_id' => $this->school->id,'name' => 'Test class','slug' => 'test-class','kind' => 'class']);
            $child = \App\Models\Contact::factory()->create(['masjid_id' => $this->school->id]);
            $member = \App\Models\GroupMembership::create(['masjid_id' => $this->school->id,'group_id' => $group->id,'contact_id' => $child->id,'role' => 'member']);
            \App\Models\AttendanceRecord::create(['masjid_id' => $this->school->id,'group_id' => $group->id,'group_membership_id' => $member->id,'session_date' => '2026-10-13','status' => 'present']);
        });
        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id,'closed_on' => '2026-10-13','reason' => 'Staff day'])->assertUnprocessable()->assertJsonPath('data.closed_on.0','A register was already taken on Tuesday, October 13, 2026 (1 attendance mark), so it cannot become a no-school day. Clear those marks first if there really was no school.');
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Test year','first_day' => '2026-10-12','last_day' => '2026-10-19','meeting_weekdays' => [1]])->assertUnprocessable()->assertJsonPath('data.meeting_weekdays.0','Attendance exists on a removed weekday. Keep that weekday or clear those marks first.');
        $this->assertSame(1, \App\Models\AttendanceRecord::count());
    }

    #[Test]
    public function disabled_grant_is_absent_at_all_serialization_boundaries_including_raw_overrides_and_history(): void
    {
        $super = User::factory()->create(['type' => 'SuperAdmin','phone' => '+15550001005']); Sanctum::actingAs($super);
        $base = '/api/admin/masjids/'.$this->school->id.'/capabilities';
        $original = $this->school->fresh()->append(Masjid::ADMIN_APPENDS)->toArray();
        $this->assertArrayNotHasKey('school_calendar_terms',$original['capabilities']);
        $this->assertStringNotContainsString('school_calendar_terms', $this->getJson($base)->assertOk()->getContent());
        $this->assertStringNotContainsString('school_calendar_terms', $this->getJson('/api/admin/studio/catalogue?org_type=school')->assertOk()->getContent());
        $this->patchJson($base.'/school_calendar_terms', ['enabled' => true])->assertOk();
        $this->patchJson($base.'/school_calendar_terms', ['enabled' => false])->assertOk();
        $this->assertStringNotContainsString('school_calendar_terms', json_encode($this->school->fresh()->append(Masjid::ADMIN_APPENDS)->toArray()));
        $this->assertStringNotContainsString('school_calendar_terms', $this->getJson($base)->assertOk()->getContent());
        $this->assertStringContainsString('school_calendar_terms', $this->getJson($base.'?include_calendar_terms=1')->assertOk()->getContent());
    }

    #[Test]
    public function term_routes_are_absent_when_off_even_for_a_super_admin(): void
    {
        $year = $this->year();
        $super = User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15550001007']); Sanctum::actingAs($super);
        $body = ['name'=>'Term','starts_on'=>'2026-10-11','ends_on'=>'2026-10-25','position'=>1];
        $base = $this->url('/years/'.$year->id.'/terms');
        $this->postJson($base,$body)->assertNotFound();
        $this->putJson($base.'/999',$body)->assertNotFound();
        $this->deleteJson($base.'/999')->assertNotFound();
    }

    #[Test]
    public function switch_off_checks_closures_and_last_day_and_rolls_back_other_changes(): void
    {
        $this->enable(); $year = $this->year(['meeting_weekdays'=>[0]]);
        app(TenantContext::class)->runWithout(fn () => SchoolClosure::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'closed_on'=>'2026-10-12','reason'=>'Dormant incompatible closure']));
        try { CapabilityWriter::apply($this->school->fresh(),['school_calendar_terms'=>false,'events'=>false],$this->admin->id); $this->fail('Off must refuse'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('This calendar cannot be switched off while a no-school day is off the weekday of its year. Remove that no-school day first.',$e->errors()['capability'][0]); }
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()));
        $this->assertArrayNotHasKey('events',$this->school->fresh()->capability_overrides);
        SchoolClosure::query()->delete(); $year->update(['last_day'=>'2026-10-24']);
        try { CapabilityWriter::apply($this->school->fresh(),['school_calendar_terms'=>false],$this->admin->id); $this->fail('Off must refuse'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertSame('This calendar cannot be switched off while a last day is off the weekday of its first day. Correct the last day first.',$e->errors()['capability'][0]); }
    }

    #[Test]
    public function enabled_closure_requests_refuse_nonmeeting_dates_and_year_edits_keep_terms_inside(): void
    {
        $this->enable(); $year = $this->year(['first_day'=>'2026-10-12','last_day'=>'2026-10-23','meeting_weekdays'=>[1,2,3,4,5]]);
        $this->postJson($this->url('/closures'),['school_year_id'=>$year->id,'closed_on'=>'2026-10-24','reason'=>'Staff day'])->assertUnprocessable()->assertJsonPath('data.closed_on.0','The no-school day must be inside its school year.');
        $this->postJson($this->url('/closures'),['school_year_id'=>$year->id,'closed_on'=>'2026-10-17','reason'=>'Staff day'])->assertUnprocessable()->assertJsonPath('data.closed_on.0','The no-school day must be one of the days the school meets.');
        $this->saveDraftTerms($year, [['name'=>'Term','starts_on'=>'2026-10-12','ends_on'=>'2026-10-23','position'=>1]])->assertOk();
        $this->putJson($this->url('/years/'.$year->id),['label'=>'Test year','first_day'=>'2026-10-12','last_day'=>'2026-10-22','meeting_weekdays'=>[1,2,3,4,5]])->assertUnprocessable()->assertJsonPath('data.first_day.0','These dates would leave a term outside the school year. Change or remove that term first.');
        $this->assertSame('2026-10-23',$year->fresh()->last_day->toDateString());
    }

    private function capabilityDifferentialResponses(): array
    {
        // Synthetic catalogue wording keeps baseline fixtures free of client names.
        config(['capabilities.report_card_core_subjects.description' => 'Report cards carry the core subjects at every grade.']);
        $this->school->forceFill(['name'=>'Baseline School','email'=>'baseline@example.invalid','phone'=>'+15550001000'])->save();
        $super = User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15550001008']); Sanctum::actingAs($super);
        $responses = [];
        foreach (['organisation'=>'/api/admin/masjids/'.$this->school->id, 'capabilities'=>'/api/admin/masjids/'.$this->school->id.'/capabilities', 'studio'=>'/api/admin/studio/catalogue?org_type=school'] as $key=>$url) {
            $r = $this->getJson($url);
            $responses[$key] = ['status'=>$r->status(),'body'=>$r->json(),'rows'=>array_map(fn ($row)=>(array)$row,\Illuminate\Support\Facades\DB::table('masjids')->get()->all())];
        }
        return $responses;
    }

    #[Test]
    public function baseline_capability_fixture_capture_never_reports_green(): void
    {
        if (getenv('CALENDAR_CAPTURE_BASELINE') !== '1') { $this->assertTrue(true); return; }
        file_put_contents(base_path('tests/fixtures/calendar-baseline/capability-responses.json'), json_encode($this->capabilityDifferentialResponses(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
        $this->fail('Recorded frozen baseline; this is not a verification run.');
    }

    public static function capabilityRequestShapes(): array { return [['organisation'],['capabilities'],['studio']]; }
    #[Test, DataProvider('capabilityRequestShapes')]
    public function off_capability_request_shapes_match_full_literal_baseline_responses_and_rows(string $shape): void
    {
        $expected = json_decode(file_get_contents(base_path('tests/fixtures/calendar-baseline/capability-responses.json')),true);
        $this->assertSame($expected[$shape],$this->capabilityDifferentialResponses()[$shape]);
    }

    #[Test]
    public function off_year_deletion_with_retained_terms_preserves_the_legacy_request_contract(): void
    {
        $year = $this->year();
        app(TenantContext::class)->runWithout(fn () => SchoolTerm::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'name'=>'Retained term','starts_on'=>'2026-10-11','ends_on'=>'2026-10-25','position'=>1]));
        $this->deleteJson($this->url('/years/'.$year->id))->assertOk()->assertExactJson(['status'=>'success','data'=>['timezone'=>'America/New_York','today'=>'2026-10-08','years'=>[]]]);
    }

    #[Test]
    public function review_reenable_refuses_retained_terms_outside_the_year_and_rolls_back_initialization(): void
    {
        $year = $this->year(['label' => 'Retained year']);
        $this->enable();
        $term = SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'name' => 'Autumn', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-18', 'position' => 1]);
        CapabilityWriter::apply($this->school->fresh(), ['school_calendar_terms' => false], $this->admin->id);
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Retained year', 'first_day' => '2026-10-18', 'last_day' => '2026-10-25'])->assertOk();
        $before = $year->fresh()->getRawOriginal();
        try {
            $this->enable();
            $this->fail('Invalid retained configuration was enabled');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('School year "Retained year", term "Autumn": the term must be inside its school year. Change the retained dates before switching on.', $e->errors()['capability'][0]);
        }
        $this->assertFalse(SchoolSettings::calendarTerms($this->school->fresh()));
        $this->assertSame($before, $year->fresh()->getRawOriginal());
        $this->assertSame('Autumn', $term->fresh()->name);
    }

    #[Test]
    public function review_reenable_refuses_overlapping_or_misordered_retained_terms(): void
    {
        $year = $this->year(['label' => 'Retained year']);
        SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'name' => 'Autumn', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-18', 'position' => 1]);
        $later = SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'name' => 'Winter', 'starts_on' => '2026-10-18', 'ends_on' => '2026-10-25', 'position' => 2]);
        foreach (['overlap', 'order'] as $case) {
            if ($case === 'order') $later->update(['starts_on' => '2026-10-11', 'ends_on' => '2026-10-12']);
            try { $this->enable(); $this->fail('Invalid retained terms were enabled'); }
            catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('School year "Retained year", term "Winter"', $e->errors()['capability'][0]);
            }
            $this->assertFalse(SchoolSettings::calendarTerms($this->school->fresh()));
            $this->assertNull($year->fresh()->meeting_weekdays);
        }
    }

    #[Test]
    public function review_hidden_off_noop_leaves_no_override_or_audit_and_history_filters_after_mains_limit(): void
    {
        $before = $this->school->fresh()->getRawOriginal();
        $result = CapabilityWriter::apply($this->school, ['school_calendar_terms' => false], $this->admin->id);
        $this->assertSame(['changed' => [], 'unchanged' => ['school_calendar_terms']], $result);
        $this->assertSame(0, \App\Models\MasjidCapabilityChange::count());
        $this->assertSame($before, $this->school->fresh()->getRawOriginal());
        CapabilityWriter::apply($this->school, ['school_calendar' => false], $this->admin->id);
        for ($i = 0; $i < 26; $i++) {
            \App\Support\CapabilityLedger::record($this->school, 'school_calendar_terms', false, true, null, $this->admin->id);
        }
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550009901']));
        $response = $this->getJson('/api/admin/masjids/'.$this->school->id.'/capabilities')->assertOk();
        // Review 2: main's LIMIT precedes PHP filtering; hidden rows can occupy that window.
        $this->assertSame([], array_column($response->json('data.history'), 'capability'));
    }

    #[Test]
    public function review_on_terms_are_eager_loaded_for_one_two_and_five_years(): void
    {
        $this->enable();
        foreach ([1, 2, 5] as $count) {
            while (SchoolYear::count() < $count) {
                $n = SchoolYear::count();
                $year = $this->year(['label' => 'Year '.$n, 'first_day' => (2026 + $n).'-10-11', 'last_day' => (2026 + $n).'-10-25', 'meeting_weekdays' => [0]]);
                SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'name' => 'Term '.$n, 'starts_on' => (2026 + $n).'-10-11', 'ends_on' => (2026 + $n).'-10-25', 'position' => 1]);
            }
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $response = $this->getJson($this->url())->assertOk();
            $queries = \Illuminate\Support\Facades\DB::getQueryLog();
            \Illuminate\Support\Facades\DB::disableQueryLog();
            $terms = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from "school_terms"')));
            $this->assertCount(1, $terms, 'One terms SELECT for '.$count.' years');
            $this->assertCount($count, $response->json('data.years'));
            foreach ($response->json('data.years') as $year) $this->assertCount(1, $year['terms']);
        }
    }


    #[Test]
    public function switch_on_after_off_dispatch_leaves_a_valid_legacy_single_day_year(): void
    {
        $switched = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$switched) {
            if ($switched) return;
            $switched = true;
            // A completed enable between OFF dispatch and the org lock cannot
            // initialize this not-yet-inserted year. NULL is valid by construction.
            \Illuminate\Support\Facades\DB::table('masjids')->where('id', $this->school->id)->update(['capability_overrides' => json_encode(['school_calendar' => true, 'school_calendar_terms' => true])]);
        });
        $response = $this->postJson($this->url('/years'), ['label' => 'Concurrent year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25'])->assertCreated();
        $this->assertTrue($switched);
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()));
        $year = SchoolYear::firstOrFail();
        $this->assertNull($year->meeting_weekdays);
        $this->assertArrayNotHasKey('meeting_weekdays', $response->json('data.years.0'), 'The dispatched OFF request keeps its main response');
        $days = ['2026-10-11', '2026-10-18', '2026-10-25'];
        $authority = SchoolDateAuthority::for($this->school->id);
        $this->assertSame($days, $authority->meetingDays($year));
        $this->assertTrue($authority->isMeetingDay('2026-10-18'));
        $this->assertFalse($authority->isMeetingDay('2026-10-19'));
        $this->assertSame([0], $authority->meetingWeekdaysBetween('2026-10-11', '2026-10-25'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.years.0.meeting_weekdays', [0])->assertJsonPath('data.years.0.meeting_days', $days);
        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-10-19', 'reason' => 'Wrong weekday'])->assertUnprocessable();
        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-11-01', 'reason' => 'Outside year'])->assertUnprocessable();
        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-10-18', 'reason' => 'Staff day'])->assertCreated();
        $authority = SchoolDateAuthority::for($this->school->id);
        $this->assertSame(['2026-10-11', '2026-10-25'], $authority->openDaysBetween('2026-10-11', '2026-10-25'));
        $this->assertSame(['date' => '2026-10-18', 'closed' => true, 'reason' => 'Staff day'], $authority->labelledDays()[1]);
        $this->assertSame($authority->labelledDays(), $authority->upcoming());
        $term = ['name' => 'Autumn', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1];
        // Retained child of a NULL year: saving the whole year would explicitly choose [0].
        $stored = SchoolTerm::create(['school_year_id'=>$year->id]+$term);
        $this->assertSame(1, SchoolTerm::count());
        $this->saveDraftTerms($year, [['id'=>$stored->id]+$term, ['name' => 'Overlap', 'position' => 2] + $term])->assertUnprocessable()->assertJsonPath('data', ['terms.1.starts_on'=>['Overlap: Term dates must not overlap.']]);
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Concurrent year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-18', 'meeting_weekdays' => [0]])->assertUnprocessable()->assertJsonPath('data.first_day.0', 'These dates would leave a term outside the school year. Change or remove that term first.');
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Concurrent year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-26', 'meeting_weekdays' => [0]])->assertUnprocessable()->assertJsonValidationErrors('last_day', 'data');
        CapabilityWriter::apply($this->school, ['school_calendar_terms' => false], $this->admin->id);
        $this->assertFalse(SchoolSettings::calendarTerms($this->school->fresh()));
        $this->assertNull($year->fresh()->meeting_weekdays, 'Disable treats NULL as weekly without rewriting it');
    }

    #[Test, DataProvider('inFlightYearEdits')]
    public function only_invalid_in_flight_off_year_edits_are_guarded_after_enable(array $dates, string $field): void
    {
        $year = $this->year();
        SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'name' => 'Autumn', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1]);
        $switched = false;
        $this->app->afterResolving(\App\Http\Requests\Admin\SchoolCalendar\UpdateSchoolYearRequest::class, function () use (&$switched, $year) {
            if ($switched) return;
            $switched = true;
            \Illuminate\Support\Facades\DB::table('masjids')->where('id', $this->school->id)->update(['capability_overrides' => json_encode(['school_calendar' => true, 'school_calendar_terms' => true])]);
            \Illuminate\Support\Facades\DB::table('school_years')->where('id', $year->id)->update(['meeting_weekdays' => '[0]']);
        });
        $response = $this->putJson($this->url('/years/'.$year->id), ['label' => 'Concurrent edit'] + $dates)->assertUnprocessable()->assertJsonValidationErrors($field, 'data');
        if ($dates['first_day'] === '2026-10-12') $response->assertJsonPath('data.first_day.0', 'The first day must be one of the days the school meets. Reopen this year and save the days it meets.');
        $this->assertTrue($switched);
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()), 'Refusing the edit does not undo the completed enable');
        $this->assertSame('2026-10-11', $year->fresh()->first_day->toDateString());
        $this->assertSame('2026-10-25', $year->fresh()->last_day->toDateString());
    }

    #[Test]
    public function null_year_weekday_removal_checks_closures_and_saving_persists_explicit_days(): void
    {
        $this->enable();
        $year = $this->year();
        $closure = SchoolClosure::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'closed_on' => '2026-10-18', 'reason' => 'Staff day']);
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Test year', 'first_day' => '2026-10-12', 'last_day' => '2026-10-26', 'meeting_weekdays' => [1]])->assertUnprocessable()->assertJsonPath('data.meeting_weekdays.0', 'Remove the no-school days on removed weekdays first: Sunday, October 18, 2026.');
        $this->assertNull($year->fresh()->meeting_weekdays);
        $closure->delete();
        $this->putJson($this->url('/years/'.$year->id), ['label' => 'Test year', 'first_day' => '2026-10-11', 'last_day' => '2026-10-25', 'meeting_weekdays' => [0]])->assertOk();
        $this->assertSame([0], $year->fresh()->meeting_weekdays);
    }

    #[Test, DataProvider('nullYearAttendance')]
    public function a_raced_off_null_year_edit_preserves_attendance_but_allows_an_unused_weekday_to_move(bool $marked): void
    {
        $year = $this->year();
        $group = \App\Models\Group::factory()->create(['masjid_id' => $this->school->id]);
        $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->school->id]);
        $member = \App\Models\GroupMembership::create(['masjid_id' => $this->school->id, 'group_id' => $group->id, 'contact_id' => $contact->id, 'role' => 'member']);
        if ($marked) \App\Models\AttendanceRecord::create(['masjid_id' => $this->school->id, 'group_id' => $group->id, 'group_membership_id' => $member->id, 'session_date' => '2026-10-18', 'status' => 'present']);
        $this->app->afterResolving(\App\Http\Requests\Admin\SchoolCalendar\UpdateSchoolYearRequest::class, function () {
            // NULL remains legal: this year could itself have missed enable.
            \Illuminate\Support\Facades\DB::table('masjids')->where('id', $this->school->id)->update(['capability_overrides' => json_encode(['school_calendar' => true, 'school_calendar_terms' => true])]);
        });
        $response = $this->putJson($this->url('/years/'.$year->id), ['label' => 'Moved', 'first_day' => '2026-10-12', 'last_day' => '2026-10-26']);
        if ($marked) {
            $response->assertUnprocessable()->assertJsonPath('data.meeting_weekdays.0', 'Attendance exists on a removed weekday. Keep that weekday or clear those marks first.');
            $this->assertSame('2026-10-11', $year->fresh()->first_day->toDateString());
        } else {
            $response->assertOk();
            $this->assertSame('2026-10-12', $year->fresh()->first_day->toDateString());
            $this->getJson($this->url())->assertOk()->assertJsonPath('data.years.0.meeting_weekdays', [1]);
        }
        $this->assertNull($year->fresh()->meeting_weekdays);
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()));
    }

    public static function nullYearAttendance(): array { return [[true], [false]]; }

    #[Test, DataProvider('validInFlightWrites')]
    public function valid_in_flight_off_writes_remain_valid_after_enable(string $write): void
    {
        $year = $this->year();
        $term = SchoolTerm::create(['masjid_id' => $this->school->id, 'school_year_id' => $year->id, 'name' => 'Autumn', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-25', 'position' => 1]);
        $switched = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$switched, $year) {
            if ($switched) return;
            $switched = true;
            \Illuminate\Support\Facades\DB::table('masjids')->where('id', $this->school->id)->update(['capability_overrides' => json_encode(['school_calendar' => true, 'school_calendar_terms' => true])]);
            \Illuminate\Support\Facades\DB::table('school_years')->where('id', $year->id)->update(['meeting_weekdays' => '[0]']);
        });
        $response = match ($write) {
            'closure' => $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-10-18', 'reason' => 'Staff day'])->assertCreated(),
            'year-update' => $this->putJson($this->url('/years/'.$year->id), ['label' => 'Expanded', 'first_day' => '2026-10-11', 'last_day' => '2026-11-01'])->assertOk(),
            'year-delete' => $this->deleteJson($this->url('/years/'.$year->id))->assertOk(),
        };
        $this->assertTrue($switched);
        $this->assertTrue(SchoolSettings::calendarTerms($this->school->fresh()));
        if ($write === 'year-delete') {
            $this->assertNull($year->fresh());
            $this->assertNull($term->fresh());
        } else {
            $this->assertArrayNotHasKey('meeting_weekdays', $response->json('data.years.0'));
            $this->assertSame([0], $year->fresh()->meeting_weekdays);
            $this->getJson($this->url())->assertOk()->assertJsonPath('data.years.0.meeting_weekdays', [0]);
            $this->assertTrue(SchoolDateAuthority::for($this->school->id)->isMeetingDay('2026-10-18'));
            if ($write === 'closure') $this->assertCount(1, $year->closures()->get());
            if ($write === 'year-update') $this->assertSame('2026-11-01', $year->fresh()->last_day->toDateString());
        }
    }

    public static function validInFlightWrites(): array { return [['closure'], ['year-update'], ['year-delete']]; }

    public static function inFlightYearEdits(): array
    {
        return [
            'shortening strands retained term' => [['first_day' => '2026-10-11', 'last_day' => '2026-10-18'], 'first_day'],
            'changed bounds leave explicit weekdays' => [['first_day' => '2026-10-12', 'last_day' => '2026-10-26'], 'first_day'],
        ];
    }

}
