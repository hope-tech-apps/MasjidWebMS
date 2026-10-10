<?php

use App\Models\{ClassSubject, Contact, Group, GroupMembership, GroupStaff, Masjid, MasjidUser, SubjectNote, SubjectPiece, SubjectPieceMark, User};
use App\Support\{CapabilityWriter, ClassSubjectMode, PerformanceLevel, SchoolSettings, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Route};

class SubjectSharingOffMemoProbe
{
    public function handle($request, $next)
    {
        $response = $next($request);
        $count = count(DB::getQueryLog());
        expect(ClassSubjectMode::sharingEnabled($request->route('masjid_id')))->toBeFalse();
        expect(count(DB::getQueryLog()))->toBe($count);
        return $response;
    }
}

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    fake()->seed(80424);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-09 16:00:00', 'UTC'));
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => 'sharing@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'capability_overrides' => ['class_subjects' => true, 'class_subject_work' => true, 'class_subject_sharing' => true]]);
    $this->org->forceFill(['capability_overrides' => ['class_subjects' => true, 'class_subject_work' => true, 'class_subject_sharing' => true]])->save();
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'name' => 'Practice Class', 'slug' => 'practice-class', 'kind' => 'class']);
    $this->subject = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Science', 'position' => 0]);
    $this->otherSubject = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Arabic', 'position' => 1]);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'email' => 'teacher@example.invalid', 'phone' => '+15555550101']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Practice Office', 'email' => 'office@example.invalid', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::withOfficeSubjectChoice(fn () => GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => null, 'class_subject_ids' => null]));
    $this->parent = Contact::factory()->create(['masjid_id' => $this->org->id, 'first_name' => 'Practice', 'last_name' => 'Guardian', 'login_email' => 'guardian@example.invalid', 'login_enabled_at' => now()]);
    $this->otherParent = Contact::factory()->create(['masjid_id' => $this->org->id, 'login_email' => 'another@example.invalid', 'login_enabled_at' => now()]);
    $this->child = function (?Contact $guardian = null) {
        $contact = Contact::factory()->create(['masjid_id' => $this->org->id, 'first_name' => 'Practice', 'last_name' => 'Student']);
        $member = GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => '1st']);
        GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => ($guardian ?? $this->parent)->id, 'role' => 'guardian', 'guardian_of_contact_id' => $contact->id, 'provenance' => 'confirmed']);
        return $member;
    };
    $this->one = ($this->child)(); $this->two = ($this->child)(); $this->foreignChild = ($this->child)($this->otherParent);
    $this->piece = SubjectPiece::create(['masjid_id' => $this->org->id, 'class_subject_id' => $this->subject->id, 'source' => 'own', 'title' => 'Practice piece']);
    $this->teacherToken = $this->teacher->createToken('practice', ['staff'])->plainTextToken;
    $this->officeToken = $this->office->createToken('practice', ['staff'])->plainTextToken;
    $this->familyToken = $this->parent->createToken('practice', ['family'])->plainTextToken;
    $this->otherToken = $this->otherParent->createToken('practice', ['family'])->plainTextToken;
    $this->childToken = $this->parent->createStudentHandoffToken($this->one->id)->plainTextToken;
    $this->base = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}/subjects/{$this->subject->id}";
    $this->familyBase = "/api/family/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->subjectsUrl = fn ($member = null) => $this->familyBase.'/members/'.($member ?? $this->one)->id.'/subjects';
    $this->asToken = function ($token) { Auth::forgetGuards(); app(TenantContext::class)->forgetTenant(); return $this->withToken($token); };
    $this->saveMark = function ($shared = true, $version = 'loaded', $level = 3, $comment = 'Practice comment', $member = null) {
        $member ??= $this->one;
        $stored = SubjectPieceMark::where('subject_piece_id', $this->piece->id)->where('group_membership_id', $member->id)->first();
        return ($this->asToken)($this->teacherToken)->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $this->piece->id, 'marks' => [[
            'group_membership_id' => $member->id, 'level' => $level, 'comment' => $comment, 'shared_with_family' => $shared,
            'updated_at' => $version === 'loaded' ? $stored?->updated_at?->toISOString() : $version,
        ]]]);
    };
    $this->note = fn ($shared = true, $member = null) => ($this->asToken)($this->teacherToken)->postJson($this->base.'/notes', ['body' => 'Practice note', 'shared_with_family' => $shared, 'group_membership_id' => $member?->id]);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('sharing OFF preserves literal 8b6d8f09 SQL and payloads with work on and off', function () {
    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'subjects') || str_contains($route->getActionName(), 'Family\\GroupsController') || str_contains($route->getActionName(), 'StudentSessionController')) $route->middleware(SubjectSharingOffMemoProbe::class);
    }
    \App\Support\StudentAge::forget();
    $actual = [];
    foreach ([true, false] as $work) {
        $this->org->forceFill(['capability_overrides' => ['class_subjects' => $work, 'class_subject_work' => $work, 'class_subject_sharing' => false]])->save();
        $calls = [
            'teacher-page' => [$this->teacherToken, 'GET', $this->base.'/work', [], $work ? 200 : 404],
            'mark-save' => [$this->teacherToken, 'PUT', $this->base.'/marks', ['source' => 'own', 'piece_id' => $this->piece->id, 'marks' => [['group_membership_id' => $this->one->id, 'level' => 3, 'comment' => 'Private comment', 'updated_at' => null]]], $work ? 200 : 404],
            'family-class' => [$this->familyToken, 'GET', $this->familyBase, [], 200],
            'family-child' => [$this->childToken, 'GET', $this->familyBase.'/members/'.$this->one->id.'/student/me', [], 200],
        ];
        foreach ($calls as $name => [$token, $method, $url, $data, $status]) {
            ($this->asToken)($token);
            DB::flushQueryLog(); DB::enableQueryLog();
            $response = $this->json($method, $url, $data)->assertStatus($status);
            $actual[($work ? 'work-on' : 'both-off').'/'.$name] = ['sql' => array_column(DB::getQueryLog(), 'query'), 'payload' => $response->json()];
            DB::disableQueryLog();
        }
        if ($work) ($this->saveMark)(true)->assertUnprocessable();
    }
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/subject-sharing-off-8b6d8f09.json')), true));
});
