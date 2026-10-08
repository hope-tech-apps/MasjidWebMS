<?php

use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Expected values are literals derived from origin/main 02d8b3a1:
// subjectsFor() intersects GroupStaff::SUBJECTS (quran, arabic, islamic_studies),
// never input order; supplied maps update EVERY retained row, even omitted keys.
// Unknown map keys are validated but ignored when extracting selected classes.
// TeachersView.vue:585 sends the whole subject map, including unticked classes.
dataset('origin teacher editor shapes', [
    'map absent' => [['a', 'b'], '__absent', ['a' => null, 'b' => null], ['a' => ['arabic'], 'b' => ['quran']], 200],
    'empty map' => [['a', 'b'], [], ['a' => null, 'b' => null], ['a' => null, 'b' => null], 200],
    'partial map' => [['a', 'b'], ['a' => ['islamic_studies']], ['a' => ['islamic_studies'], 'b' => null], ['a' => ['islamic_studies'], 'b' => null], 200],
    'all null' => [['a', 'b'], ['a' => null, 'b' => null], ['a' => null, 'b' => null], ['a' => null, 'b' => null], 200],
    'all boxes unticked' => [['a', 'b'], ['a' => [], 'b' => []], ['a' => null, 'b' => null], ['a' => null, 'b' => null], 200],
    'restricted class unticked with stale map' => [['b'], ['a' => ['arabic'], 'b' => ['quran']], ['b' => ['quran']], ['b' => ['quran']], 200],
    'class unticked with null entry' => [['b'], ['a' => null, 'b' => ['quran']], ['b' => ['quran']], ['b' => ['quran']], 200],
    'one kept with omitted key' => [['b'], ['a' => ['arabic']], ['b' => null], ['b' => null], 200],
    'class added by ticking' => [['a', 'b', 'c'], ['a' => ['arabic'], 'b' => ['quran'], 'c' => null], ['a' => ['arabic'], 'b' => ['quran'], 'c' => null], ['a' => ['arabic'], 'b' => ['quran'], 'c' => null], 200],
    'class added without map entry' => [['a', 'b', 'c'], ['a' => ['arabic']], ['a' => ['arabic'], 'b' => null, 'c' => null], ['a' => ['arabic'], 'b' => null, 'c' => null], 200],
    'class added map absent' => [['a', 'b', 'c'], '__absent', ['a' => null, 'b' => null, 'c' => null], ['a' => ['arabic'], 'b' => ['quran'], 'c' => null], 200],
    'unknown numeric key' => [['a', 'b'], [999 => ['arabic']], ['a' => null, 'b' => null], ['a' => null, 'b' => null], 200],
    'unknown text key' => [['a', 'b'], ['not-a-class' => ['quran']], ['a' => null, 'b' => null], ['a' => null, 'b' => null], 200],
    'padded legacy class key' => [['a', 'b'], ['padded-a' => ['arabic']], ['a' => null, 'b' => null], ['a' => null, 'b' => null], 200],
    'duplicates and canonical ordering' => [['a', 'b'], ['a' => ['arabic', 'quran', 'arabic'], 'b' => ['islamic_studies', 'arabic']], ['a' => ['quran', 'arabic'], 'b' => ['arabic', 'islamic_studies']], ['a' => ['quran', 'arabic'], 'b' => ['arabic', 'islamic_studies']], 200],
    'subjects reordered' => [['a', 'b'], ['a' => ['islamic_studies', 'arabic', 'quran'], 'b' => ['arabic', 'quran']], ['a' => ['quran', 'arabic', 'islamic_studies'], 'b' => ['quran', 'arabic']], ['a' => ['quran', 'arabic', 'islamic_studies'], 'b' => ['quran', 'arabic']], 200],
    'class selection duplicates and reordering' => [['b', 'a', 'b'], ['a' => ['arabic'], 'b' => ['quran']], ['a' => ['arabic'], 'b' => ['quran']], ['a' => ['arabic'], 'b' => ['quran']], 200],
    'null top-level map refused' => [['a', 'b'], null, [], ['a' => ['arabic'], 'b' => ['quran']], 422],
    'scalar top-level map refused' => [['a', 'b'], 'arabic', [], ['a' => ['arabic'], 'b' => ['quran']], 422],
]);

beforeEach(function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-08 12:00:00 UTC'));
    Mail::fake();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Parity School', 'email' => 'school@example.invalid', 'phone' => '+15555550101', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->classes = [];
    foreach (['a', 'b', 'c'] as $key) $this->classes[$key] = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => strtoupper($key)]);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'email' => 'teacher@example.invalid', 'phone' => '+15555550103']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->staffIds = [];
    foreach (['a' => ['arabic'], 'b' => ['quran']] as $key => $subjects) $this->staffIds[$key] = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->classes[$key]->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'subjects' => $subjects, 'assigned_at' => now()])->id;
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550104']);
    Sanctum::actingAs($this->office);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('matches literal origin status body and stored rows for teacher editor requests while OFF', function (array $selection, mixed $map, array $inviteRows, array $updateRows, int $status, string $action, mixed $unknown) {
    $body = ['name' => 'Practice Teacher', 'phone' => '', 'class_ids' => array_map(fn ($key) => $this->classes[$key]->id, $selection), 'class_subject_ids' => $unknown];
    if ($map !== '__absent') {
        $body['class_subjects'] = is_array($map) ? collect($map)->mapWithKeys(fn ($value, $key) => [$key === 'padded-a' ? '0'.$this->classes['a']->id : (isset($this->classes[$key]) ? $this->classes[$key]->id : $key) => $value])->all() : $map;
    }
    $url = "/api/admin/masjids/{$this->org->id}/teachers";
    if ($action === 'invite') {
        $body['email'] = '  NEW@example.invalid  ';
        $response = $this->postJson($url, $body);
        $userId = User::where('email', 'new@example.invalid')->value('id');
        $expectedRows = $inviteRows;
    } else {
        $response = $this->putJson($url.'/'.$this->teacher->id, $body);
        $userId = $this->teacher->id;
        $expectedRows = $updateRows;
    }
    if ($status === 422) {
        $response->assertStatus(422)->assertExactJson(['status' => 'failed', 'data' => ['class_subjects' => ['The class subjects field must be an array.']]]);
    } else {
        $classes = [];
        foreach ($expectedRows as $key => $subjects) $classes[] = ['id' => $this->classes[$key]->id, 'name' => strtoupper($key), 'subjects' => $subjects];
        $response->assertStatus($action === 'invite' ? 201 : 200)->assertExactJson(['status' => 'success', 'message' => $action === 'invite' ? 'Invitation sent to new@example.invalid.' : 'Teacher updated.', 'data' => ['id' => $userId, 'name' => 'Practice Teacher', 'email' => $action === 'invite' ? 'new@example.invalid' : 'teacher@example.invalid', 'classes' => $classes]]);
    }
    $actual = $userId === null ? [] : DB::table('group_staff')->where('user_id', $userId)->orderBy('group_id')->get()->map(fn ($row) => [
        'id' => $row->id,
        'masjid_id' => $row->masjid_id, 'group_id' => $row->group_id, 'user_id' => $row->user_id, 'role' => $row->role,
        'assigned_by_user_id' => $row->assigned_by_user_id, 'assigned_at' => $row->assigned_at, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        'subjects' => $row->subjects === null ? null : json_decode($row->subjects, true),
        'class_subject_ids' => $row->class_subject_ids, 'class_subjects_mapped_at' => $row->class_subjects_mapped_at, 'class_subject_legacy_snapshot' => $row->class_subject_legacy_snapshot,
    ])->all();
    $expected = [];
    $nextId = max($this->staffIds) + 1;
    foreach ($expectedRows as $key => $subjects) {
        $new = $action === 'invite' || ! isset($this->staffIds[$key]);
        $expected[] = ['id' => $new ? $nextId++ : $this->staffIds[$key], 'masjid_id' => $this->org->id, 'group_id' => $this->classes[$key]->id, 'user_id' => $userId, 'role' => 'teacher',
            'assigned_by_user_id' => $new ? $this->office->id : null, 'assigned_at' => '2026-10-08 12:00:00', 'created_at' => '2026-10-08 12:00:00', 'updated_at' => '2026-10-08 12:00:00',
            'subjects' => $subjects, 'class_subject_ids' => null, 'class_subjects_mapped_at' => null, 'class_subject_legacy_snapshot' => null];
    }
    expect($actual)->toBe($expected);
})->with('origin teacher editor shapes')->with(['invite', 'update'])->with([[null], [['unknown-key' => [true]]]]);

it('does not run the OFF Group lifecycle when its parent school is archived', function () {
    $group = $this->classes['c'];
    $group->kind = 'general'; $group->save();
    $this->org->delete();
    DB::flushQueryLog(); DB::enableQueryLog();
    try {
        $group->kind = 'class'; $group->save();
        $queries = DB::getQueryLog();
    } finally { DB::disableQueryLog(); }
    expect($group->fresh()->kind)->toBe('class');
    // Only the cheapest capability read is allowed before the original model save.
    expect(collect($queries)->filter(fn ($q) => str_contains(strtolower($q['query']), 'for update')))->toHaveCount(0);
    expect(collect($queries)->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'select') && ! str_contains($q['query'], 'capability_overrides')))->toHaveCount(0);
});

it('does not query or lock from the OFF model hook when the capability data is loaded', function () {
    $group = $this->classes['c']->setRelation('masjid', $this->org);
    DB::flushQueryLog(); DB::enableQueryLog();
    try {
        $group->name = 'Updated'; $group->save();
        $queries = DB::getQueryLog();
    } finally { DB::disableQueryLog(); }
    expect($queries)->toHaveCount(1);
    expect(strtolower($queries[0]['query']))->toStartWith('update');
});

it('matches literal origin override serialization shapes while OFF', function (mixed $stored, mixed $expected) {
    $this->org->forceFill(['capability_overrides' => $stored])->save();
    expect($this->org->fresh()->attributesToArray()['capability_overrides'])->toBe($expected);
})->with([
    'null' => [null, null],
    'empty array' => [[], []],
    'legacy false override' => [['shop' => false], ['shop' => false]],
    'legacy true override' => [['web_pages' => true], ['web_pages' => true]],
    'unknown legacy key preserved' => [['unknown-old-key' => 'value'], ['unknown-old-key' => 'value']],
    'scalar legacy json preserved' => ['value', 'value'],
]);

it('keeps original array cast dirty detection and encoding failure behavior while OFF', function () {
    DB::table('masjids')->where('id', $this->org->id)->update(['capability_overrides' => '{ "shop": false }']);
    $org = $this->org->fresh();
    $org->capability_overrides = ['shop' => false];
    expect($org->isDirty('capability_overrides'))->toBeFalse();
    expect(fn () => $org->forceFill(['capability_overrides' => ['unknown-old-key' => "\xff"]]))
        ->toThrow(\Illuminate\Database\Eloquent\JsonEncodingException::class);
});

it('matches literal origin fences for every legacy restriction shape while OFF', function (mixed $stored, ?array $limits, array $allowed) {
    $this->classes['a']->setRelation('masjid', $this->org);
    GroupStaff::where('user_id', $this->teacher->id)->where('group_id', $this->classes['a']->id)->update(['subjects' => $stored === null ? null : json_encode($stored)]);
    expect(\App\Support\SubjectFence::limitsFor($this->teacher, $this->classes['a']->id))->toBe($limits);
    foreach (['arabic', 'quran', 'islamic studies', 'science'] as $key) {
        expect(\App\Support\SubjectFence::allows($limits, $key), $key)->toBe(in_array($key, $allowed, true));
    }
})->with([
    'null' => [null, null, ['arabic', 'quran', 'islamic studies', 'science']],
    'empty' => [[], null, ['arabic', 'quran', 'islamic studies', 'science']],
    'Arabic' => [['arabic'], ['arabic'], ['arabic']],
    'Quran' => [['quran'], ['quran'], ['quran']],
    'Islamic studies' => [['islamic_studies'], ['islamic_studies'], ['islamic studies']],
    'request order retained on old reads' => [['arabic', 'quran'], ['arabic', 'quran'], ['arabic', 'quran']],
    'duplicates retained on old reads' => [['arabic', 'arabic'], ['arabic', 'arabic'], ['arabic']],
    'unknown stored enum grants nothing' => [['unknown'], ['unknown'], []],
    'scalar old json becomes unrestricted' => ['arabic', null, ['arabic', 'quran', 'islamic studies', 'science']],
]);
