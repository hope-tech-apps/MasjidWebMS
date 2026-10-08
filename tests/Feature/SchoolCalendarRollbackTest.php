<?php

use App\Models\{Masjid, SchoolYear, SchoolTerm};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('checks every feature structure before any rollback DDL', function (string $held) {
    $org = Masjid::create(['name'=>'Rollback School '.uniqid(),'email'=>uniqid().'@example.invalid','phone'=>'+1'.random_int(1000000000,9999999999),'country_id'=>'1','city_id'=>'1','address'=>'1 Test St','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $year = SchoolYear::create(['masjid_id'=>$org->id,'label'=>'Test year','first_day'=>'2026-10-11','last_day'=>'2026-10-25']);
    if ($held === 'terms') SchoolTerm::create(['masjid_id'=>$org->id,'school_year_id'=>$year->id,'name'=>'Term','starts_on'=>'2026-10-11','ends_on'=>'2026-10-25','position'=>1]);
    if ($held === 'weekdays') $year->update(['meeting_weekdays'=>[]]);
    if ($held === 'system') $year->update(['term_system'=>'quarters']);
    if ($held === 'switch') $org->forceFill(['capability_overrides'=>['school_calendar_terms'=>true]])->save();
    $migration = require base_path('database/migrations/2026_10_08_200000_add_school_calendar_configuration.php');
    try { $migration->down(); $this->fail('Rollback should refuse'); }
    catch (RuntimeException $e) { expect($e->getMessage())->toBe('School calendar configuration is in use. Keep the schema and switch compatible schools off before rolling back.'); }
    expect(Schema::hasTable('school_terms'))->toBeTrue()->and(Schema::hasColumn('school_years','meeting_weekdays'))->toBeTrue()->and(Schema::hasColumn('school_years','term_system'))->toBeTrue();
})->with(['terms','weekdays','system','switch']);
