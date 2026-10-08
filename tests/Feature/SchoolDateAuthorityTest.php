<?php

use App\Models\{Masjid, SchoolYear, SchoolClosure};
use App\Support\{SchoolDateAuthority, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('expands configured weekdays over the year boundary and DST, omits closures from open dates and keeps them coming up', function (array $weekdays, string $first, string $last, array $expected) {
    $org = Masjid::create(['name' => 'Authority '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000,9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    $org->forceFill(['capability_overrides' => ['school_calendar_terms' => true]])->save();
    $year = SchoolYear::create(['masjid_id' => $org->id, 'label' => 'Test', 'first_day' => $first, 'last_day' => $last, 'meeting_weekdays' => $weekdays]);
    SchoolClosure::create(['masjid_id' => $org->id, 'school_year_id' => $year->id, 'closed_on' => $expected[1], 'reason' => 'Staff day']);
    $this->travelTo(\Carbon\Carbon::parse($first.' 14:00:00', 'UTC'));
    $calendar = SchoolDateAuthority::for($org->id);
    expect($calendar->meetingDays($year))->toBe($expected)
        ->and($calendar->openDaysBetween($first,$last))->toBe(array_values(array_diff($expected,[$expected[1]])))
        ->and($calendar->upcoming()[1])->toBe(['date' => $expected[1], 'closed' => true, 'reason' => 'Staff day'])
        ->and($calendar->isMeetingDay($expected[1]))->toBeTrue();
    app(TenantContext::class)->set($org->id + 100);
    expect(SchoolDateAuthority::for($org->id)->openDaysBetween($first,$last))->toBe([]);
})->with([
    [[0], '2026-12-27', '2027-01-10', ['2026-12-27','2027-01-03','2027-01-10']],
    [[1,3], '2026-12-28', '2027-01-06', ['2026-12-28','2026-12-30','2027-01-04','2027-01-06']],
    [[1,2,3,4,5], '2026-12-28', '2027-01-08', ['2026-12-28','2026-12-29','2026-12-30','2026-12-31','2027-01-01','2027-01-04','2027-01-05','2027-01-06','2027-01-07','2027-01-08']],
    [[0], '2027-03-07', '2027-03-21', ['2027-03-07','2027-03-14','2027-03-21']],
    [[1,3], '2027-03-08', '2027-03-17', ['2027-03-08','2027-03-10','2027-03-15','2027-03-17']],
    [[1,2,3,4,5], '2027-03-12', '2027-03-16', ['2027-03-12','2027-03-15','2027-03-16']],
]);

it('keeps unconfigured null, configured empty and dormant OFF weekdays distinct', function () {
    $org = Masjid::create(['name'=>'Null School '.uniqid(),'email'=>uniqid().'@example.invalid','phone'=>'+1'.random_int(1000000000,9999999999),'country_id'=>'1','city_id'=>'1','address'=>'1 Test St','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $year = SchoolYear::create(['masjid_id'=>$org->id,'label'=>'Test year','first_day'=>'2026-10-11','last_day'=>'2026-10-25','meeting_weekdays'=>null]);
    $org->forceFill(['capability_overrides'=>['school_calendar_terms'=>true]])->save();
    expect($year->fresh()->meeting_weekdays)->toBeNull()->and(SchoolDateAuthority::for($org->id)->meetingDays($year))->toBe(['2026-10-11','2026-10-18','2026-10-25']);
    $year->update(['meeting_weekdays'=>[]]);
    expect($year->fresh()->meeting_weekdays)->toBe([])->and(SchoolDateAuthority::for($org->id)->meetingDays($year))->toBe([]);
    $org->forceFill(['capability_overrides'=>['school_calendar_terms'=>false]])->save();
    expect(SchoolDateAuthority::for($org->id)->meetingDays($year))->toBe(['2026-10-11','2026-10-18','2026-10-25']);
});
