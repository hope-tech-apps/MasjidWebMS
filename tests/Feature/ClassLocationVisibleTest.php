<?php

use App\Models\{Contact, Group, GroupStaff, Masjid, MasjidUser, SchoolYear, TimetableClassRoom, TimetableRoom, User};
use App\Support\{StudentAge, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    fake()->seed(9165);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-11 16:00:00', 'UTC'));
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->school = Masjid::create(['name'=>'Practice School','email'=>'practice@example.invalid','phone'=>'+15555550600','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    $this->office = User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Office','email'=>'office@example.invalid','phone'=>'+15555550601']);
    $this->teacher = User::factory()->create(['type'=>'Teacher','name'=>'Practice Teacher','email'=>'teacher@example.invalid','phone'=>'+15555550602']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->teacher->id,'role'=>'teacher','is_default'=>true]);
    $this->class = Group::factory()->create(['masjid_id'=>$this->school->id,'name'=>'Practice Class','slug'=>'practice-class','description'=>null,'kind'=>'class']);
    GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$this->teacher->id,'subjects'=>null]);
    $this->admin = "/api/admin/masjids/{$this->school->id}/groups";
    $this->portal = "/api/teacher/masjids/{$this->school->id}/groups";
    $this->enable = function (bool $on, bool $subjects = false) {
        app(TenantContext::class)->forgetTenant();
        $this->school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>$on,'class_subjects'=>$subjects]])->save();
    };
    $this->place = function ($group, $name = 'Room 3') {
        app(TenantContext::class)->forgetTenant();
        $room = TimetableRoom::create(['masjid_id'=>$group->masjid_id,'name'=>$name,'name_key'=>strtolower($name),'active'=>true]);
        TimetableClassRoom::create(['masjid_id'=>$group->masjid_id,'group_id'=>$group->id,'room_id'=>$room->id]);
        return $room;
    };
    $this->read = function ($user, $url) {
        $user->unsetRelations(); StudentAge::forget(); Sanctum::actingAs($user, ['staff']);
        DB::flushQueryLog(); DB::enableQueryLog();
        $response = $this->getJson($url)->assertOk();
        $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        return ['sql'=>$sql,'payload'=>$response->json()];
    };
});
afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('location OFF pins literal 52daab37 office list and page and teacher list and page SQL and payloads', function () {
    // Captured before any implementation at 52daab37; retained rooms must stay dark.
    ($this->place)($this->class);
    $actual = [];
    foreach (['office-list'=>[$this->office,$this->admin], 'office-page'=>[$this->office,$this->admin.'/'.$this->class->id], 'teacher-list'=>[$this->teacher,$this->portal], 'teacher-page'=>[$this->teacher,$this->portal.'/'.$this->class->id]] as $key=>[$user,$url]) {
        $actual[$key] = ($this->read)($user,$url);
    }
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/class-location-off-52daab37.json')), true));
});

foreach (['office','teacher'] as $realm) foreach (['list','page'] as $page) foreach ([false,true] as $located) {
    it("location ON {$realm} {$page} carries only its location name, set ".(int)$located, function () use ($realm,$page,$located) {
        ($this->enable)(true);
        if ($located) ($this->place)($this->class);
        $other = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Other Practice Class']);
        ($this->place)($other,'Private Hall');
        $user = $realm === 'office' ? $this->office : $this->teacher;
        $url = ($realm === 'office' ? $this->admin : $this->portal).($page === 'page' ? '/'.$this->class->id : '');
        $result = ($this->read)($user,$url)['payload'];
        $rows = $page === 'page' ? [$result['data']] : ($realm === 'office' ? $result['data']['data'] : $result['data']);
        $row = collect($rows)->firstWhere('id',$this->class->id);
        expect($row)->toHaveKey('location_name',$located ? 'Room 3' : null);
        expect($row)->not->toHaveKeys(['room_id','location','locations','room_ids']);
        if ($realm === 'teacher') {
            expect(array_column($rows,'id'))->toBe([$this->class->id]);
            expect(json_encode($result))->not->toContain('Private Hall');
        }
    });
}

it('location reads reuse teacher class access and school membership including a two school teacher', function () {
    ($this->enable)(true); ($this->place)($this->class);
    $otherClass = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class']); ($this->place)($otherClass,'Private Hall');
    $otherTeacher = User::factory()->create(['type'=>'Teacher','name'=>'Other Practice Teacher','phone'=>'+15555550604']);
    MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$otherTeacher->id,'role'=>'teacher']);
    GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$otherClass->id,'user_id'=>$otherTeacher->id,'subjects'=>null]);
    Sanctum::actingAs($this->teacher,['staff']);
    $this->getJson($this->portal.'/'.$otherClass->id)->assertForbidden();
    app(TenantContext::class)->forgetTenant();
    $otherSchool = Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550603','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    $otherSchool->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_timetable'=>true]])->save();
    $foreign = Group::factory()->create(['masjid_id'=>$otherSchool->id,'kind'=>'class']); ($this->place)($foreign,'Other School Hall');
    $foreignUrl = "/api/teacher/masjids/{$otherSchool->id}/groups";
    $this->getJson($foreignUrl)->assertForbidden();
    $this->getJson($this->portal.'/'.$foreign->id)->assertNotFound();
    Sanctum::actingAs($this->office,['staff']); $this->getJson($this->admin.'/'.$foreign->id)->assertNotFound();
    app(TenantContext::class)->forgetTenant();
    MasjidUser::create(['masjid_id'=>$otherSchool->id,'user_id'=>$this->teacher->id,'role'=>'teacher']);
    GroupStaff::create(['masjid_id'=>$otherSchool->id,'group_id'=>$foreign->id,'user_id'=>$this->teacher->id,'subjects'=>null]);
    foreach ([[$foreignUrl,$foreign,'Other School Hall'],[$this->portal,$this->class,'Room 3']] as [$base,$group,$name]) {
        foreach ([$base,$base.'/'.$group->id] as $url) {
            $r = ($this->read)($this->teacher,$url)['payload'];
            $row = $url === $base ? $r['data'][0] : $r['data'];
            expect($row['location_name'])->toBe($name);
            expect(json_encode($r))->not->toContain($name === 'Room 3' ? 'Other School Hall' : 'Room 3');
        }
    }
});

it('location names cannot cross the room tenant boundary even with a corrupt assignment', function () {
    ($this->enable)(true);
    app(TenantContext::class)->forgetTenant();
    $other = Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550603','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $room = TimetableRoom::create(['masjid_id'=>$other->id,'name'=>'Foreign Hall','name_key'=>'foreign hall','active'=>true]);
    TimetableClassRoom::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'room_id'=>$room->id]);
    foreach ([$this->office,$this->teacher] as $user) {
        $url = ($user === $this->office ? $this->admin : $this->portal).'/'.$this->class->id;
        expect(($this->read)($user,$url)['payload']['data']['location_name'])->toBeNull();
    }
});

it('location decoration preserves teacher and family token realm fences', function () {
    ($this->enable)(true); ($this->place)($this->class);
    $family = Contact::factory()->create(['masjid_id'=>$this->school->id,'login_email'=>'family@example.invalid','login_enabled_at'=>now()]);
    foreach ([$family->createToken('practice',['family'])->plainTextToken,$this->office->createToken('practice',['staff'])->plainTextToken] as $token) {
        Auth::forgetGuards(); $this->withToken($token);
        foreach ([$this->portal,$this->portal.'/'.$this->class->id] as $url) expect($this->getJson($url)->status())->toBeIn([401,403]);
    }
    Auth::forgetGuards(); $this->withToken($this->teacher->createToken('practice',['staff'])->plainTextToken);
    $this->getJson($this->portal.'/'.$this->class->id)->assertOk()->assertJsonPath('data.location_name','Room 3');
    $this->getJson($this->admin)->assertUnauthorized();
});

it('location lookup adds exactly one statement at two and twelve classes on each page with either subjects mode', function () {
    $counts = [];
    for ($i=2; $i<=12; $i++) {
        ($this->enable)(false);
        app(TenantContext::class)->forgetTenant();
        $g = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class']);
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$g->id,'user_id'=>$this->teacher->id,'subjects'=>null]);
        if (!in_array($i,[2,12])) continue;
        foreach ([false,true] as $subjects) {
            foreach ([[$this->office,$this->admin],[$this->office,$this->admin.'/'.$this->class->id],[$this->teacher,$this->portal],[$this->teacher,$this->portal.'/'.$this->class->id]] as [$user,$url]) {
                ($this->enable)(false,$subjects); ($this->read)($user,$url); $off = ($this->read)($user,$url);
                ($this->enable)(true,$subjects); $on = ($this->read)($user,$url);
                $locations = array_values(array_filter($on['sql'],fn ($sql)=>str_contains($sql,'timetable_class_rooms')));
                expect($locations)->toHaveCount(1);
                expect(array_values(array_filter($on['sql'],fn ($sql)=>!str_contains($sql,'timetable_class_rooms'))))->toBe($off['sql']);
                expect(count($on['sql']))->toBe(count($off['sql'])+1);
                $counts[$i][(int)$subjects][$url] = ['off'=>count($off['sql']),'on'=>count($on['sql'])];
                $payload = $on['payload'];
                if (str_ends_with($url,'/'.$this->class->id)) unset($payload['data']['location_name']);
                elseif ($user === $this->office) foreach ($payload['data']['data'] as &$row) unset($row['location_name']);
                else foreach ($payload['data'] as &$row) unset($row['location_name']);
                unset($row);
                expect($payload)->toBe($off['payload']);
            }
        }
    }
    file_put_contents(base_path('artifacts/class-location-query-counts.json'), json_encode($counts,JSON_PRETTY_PRINT));
});

it('Setup already supplies every active class and its retained usual location without new queries', function () {
    ($this->enable)(true); ($this->place)($this->class);
    app(TenantContext::class)->forgetTenant();
    $empty = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class']);
    Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','is_active'=>false]);
    $year = SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-09-01','last_day'=>'2027-06-01','meeting_weekday'=>0]);
    Sanctum::actingAs($this->office,['staff']);
    $r = $this->getJson("/api/admin/masjids/{$this->school->id}/timetable/years/{$year->id}/setup")->assertOk();
    expect(array_column($r->json('data.classes'),'id'))->toEqualCanonicalizing([$this->class->id,$empty->id]);
    expect($r->json('data.rooms.0.name'))->toBe('Room 3');
});
