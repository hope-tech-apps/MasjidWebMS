<?php

use App\Models\{ClassAssignment, ClassSubject, Group, GroupStaff, LessonPlan, Masjid, MasjidUser, SchoolSubject, User};
use App\Support\{CapabilityWriter, ClassSubjectInitializer, SubjectFence, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550101']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => ['arabic']]);
    foreach (['Arabic', 'Science', 'ELA', "Qur'an"] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    $this->base = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->activate = fn () => ClassSubjectInitializer::run($this->org, false, true);
    $this->work = fn ($subject) => ClassAssignment::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'title' => 'Practice', 'subject' => $subject, 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10]);
    $this->plan = fn ($subject) => LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'body' => 'Practice', 'subject' => $subject, 'session_date' => '2026-10-08']);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());


class Review6LegacySqlMode
{
    public static bool $legacy = false;
    public function handle($request, $next)
    {
        if (self::$legacy && preg_match('~/masjids/(\d+)~', $request->path(), $match)) \App\Support\ClassSubjectMode::rememberResponseMode((int) $match[1], false);
        return $next($request);
    }
}

dataset('off SQL endpoints', ['assignment create', 'assignment update', 'assignment delete', 'score save', 'plan create', 'plan update', 'plan delete', 'plan by day save', 'plan by day delete', 'teacher update', 'teacher invite', 'group create', 'group update', 'hifdh create', 'hifdh note', 'hifdh correct', 'hifdh delete', 'arabic mark', 'english mark', 'arabic master all', 'english master all', 'letters stage', 'note save', 'note delete', 'assignment list', 'plan list']);

it('preserves literal main statement order with at most one primary key capability select', function (string $endpoint) {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-08 12:00:00 UTC'));
    \Illuminate\Support\Facades\Mail::fake();
    $this->staff->update(['subjects' => null]);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    $assignment = ($this->work)('Arabic'); $plan = ($this->plan)('Arabic');
    $hifdhBody = ['group_membership_id' => $member->id, 'kind' => 'sabak', 'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 10, 'quality' => 'good', 'recited_at' => '2026-10-08'];
    $entry = \App\Models\HifzEntry::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id] + $hifdhBody);
    $note = \App\Models\ArabicDailyNote::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'group_membership_id' => $member->id, 'session_date' => '2026-10-08', 'note' => 'Practice']);
    $officeBase = "/api/admin/masjids/{$this->org->id}";
    $workBody = ['title' => 'Practice', 'assigned_on' => '2026-10-08', 'subject' => 'Arabic', 'scale' => 'points', 'points_possible' => 10];
    $planBody = ['session_date' => '2026-10-09', 'body' => 'Practice', 'subject' => 'Arabic'];
    [$verb, $url, $body] = match ($endpoint) {
        'assignment create' => ['post', $this->base.'/assignments', $workBody],
        'assignment update' => ['put', $this->base.'/assignments/'.$assignment->id, $workBody + ['description' => 'Edited']],
        'assignment delete' => ['delete', $this->base.'/assignments/'.$assignment->id, []],
        'score save' => ['put', $this->base.'/assignments/'.$assignment->id.'/scores', ['scores' => [['membership_id' => $member->id, 'status' => 'scored', 'points_earned' => 5]]]],
        'plan create' => ['post', $this->base.'/lesson-plans', $planBody],
        'plan update' => ['put', $this->base.'/lesson-plans/'.$plan->id, $planBody],
        'plan delete' => ['delete', $this->base.'/lesson-plans/'.$plan->id, []],
        'plan by day save' => ['put', $this->base.'/lesson-plans', $planBody],
        'plan by day delete' => ['delete', $this->base.'/lesson-plans?date=2026-10-08&subject=Arabic', []],
        'teacher update' => ['put', $officeBase.'/teachers/'.$this->teacher->id, ['name' => 'Practice', 'phone' => '', 'class_ids' => [$this->group->id], 'class_subjects' => [$this->group->id => ['arabic']]]],
        'teacher invite' => ['post', $officeBase.'/teachers', ['name' => 'Practice', 'email' => 'invited@example.invalid', 'phone' => '', 'class_ids' => [$this->group->id], 'class_subjects' => [$this->group->id => ['arabic']]]],
        'group create' => ['post', $officeBase.'/groups', ['name' => 'New Class', 'kind' => 'class']],
        'group update' => ['put', $officeBase.'/groups/'.$this->group->id, ['name' => 'Edited Class']],
        'hifdh create' => ['post', $this->base.'/hifz', ['membership_id' => $member->id] + $hifdhBody],
        'hifdh note' => ['put', $this->base.'/hifz/'.$entry->id, ['note' => 'Edited']],
        'hifdh correct' => ['post', $this->base.'/hifz/'.$entry->id.'/correct', $hifdhBody + ['correction_reason' => 'Practice correction', 'note' => 'Edited']],
        'hifdh delete' => ['delete', $this->base.'/hifz/'.$entry->id, []],
        'arabic mark' => ['put', $this->base.'/members/'.$member->id.'/letters', ['drill_id' => 'ba', 'status' => 'mastered']],
        'english mark' => ['put', $this->base.'/members/'.$member->id.'/letters', ['alphabet' => 'english', 'drill_id' => 'a.upper', 'status' => 'mastered']],
        'arabic master all' => ['put', $this->base.'/members/'.$member->id.'/letters/master-all', []],
        'english master all' => ['put', $this->base.'/members/'.$member->id.'/letters/master-all', ['alphabet' => 'english']],
        'letters stage' => ['put', $this->base.'/letters/stage', ['stage' => 'short_vowels']],
        'note save' => ['put', $this->base.'/members/'.$member->id.'/arabic-notes', ['session_date' => '2026-10-08', 'note' => 'Edited']],
        'note delete' => ['delete', $this->base.'/members/'.$member->id.'/arabic-notes/'.$note->id, []],
        'assignment list' => ['get', $this->base.'/assignments', []],
        'plan list' => ['get', $this->base.'/lesson-plans?from=2026-10-01&to=2026-10-31', []],
    };
    app(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(Review6LegacySqlMode::class);
    $run = function (bool $legacy) use ($verb, $url, $body) {
        Review6LegacySqlMode::$legacy = $legacy;
        app(TenantContext::class)->forgetTenant();
        app('auth')->forgetGuards();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (app('router')->getRoutes() as $route) $route->flushController();
        $actor = User::findOrFail(str_contains($url, '/api/admin/') ? $this->office->id : $this->teacher->id);
        Sanctum::actingAs($actor, ['staff']);
        DB::beginTransaction(); DB::flushQueryLog(); DB::enableQueryLog();
        try { $response = $this->{$verb.'Json'}($url, $body); $sql = array_column(DB::getQueryLog(), 'query'); }
        finally { DB::disableQueryLog(); DB::rollBack(); Review6LegacySqlMode::$legacy = false; }
        expect($response->status(), $response->getContent())->toBeIn([200, 201]);
        return $sql;
    };
    // The forced OFF branch is the literal origin code pinned by the unchanged
    // source contracts. No feature select precedes it. Freeze its statement list
    // separately so the baseline cannot drift with a feature change.
    $main = $run(true);
    $fixture = base_path('tests/fixtures/ClassSubjects/off-sql.json');
    $literals = json_decode(file_get_contents($fixture), true);
    expect($main)->toBe($literals[$endpoint]);
    $off = $run(false);
    $capability = 'select "id", "org_type", "capability_overrides" from "masjids" where "masjids"."id" = ? limit 1';
    $extra = array_values(array_filter($off, fn ($sql) => $sql === $capability));
    $withoutExtra = array_values(array_filter($off, fn ($sql) => $sql !== $capability));
    file_put_contents(base_path('artifacts/review6-sql-counts.jsonl'), json_encode(['endpoint' => $endpoint, 'main' => count($main), 'off' => count($off), 'extra' => count($extra), 'index' => array_search($capability, $off, true)])."\n", FILE_APPEND);
    expect(count($extra))->toBeLessThanOrEqual(1);
    expect($withoutExtra)->toBe($main);
})->with('off SQL endpoints');
