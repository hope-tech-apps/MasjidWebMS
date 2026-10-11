<?php

use App\Models\{DutyRota, Form, FormResponse, Masjid, SchoolYear, User};
use App\Support\{DutyRotaService, TenantContext};
use Laravel\Sanctum\Sanctum;

function dutySchool(bool $on=true): Masjid {
    $s=Masjid::create(['name'=>'Practice School '.Masjid::count(),'email'=>'practice'.Masjid::count().'@example.invalid','phone'=>'+1555555'.str_pad((string)(100+Masjid::count()),4,'0',STR_PAD_LEFT),'country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    $s->forceFill(['capability_overrides'=>['family_duty_rota'=>$on,'school_calendar'=>true]])->save(); return $s;
}
function dutySetup(array $attributes=[]): array {
    $s=dutySchool(); app(TenantContext::class)->set($s->id);
    $y=SchoolYear::create(['label'=>'Practice year','first_day'=>'2026-10-04','last_day'=>'2026-11-29']);
    $f=Form::create(['masjid_id'=>$s->id,'name'=>'Practice registration','slug'=>'practice-registration','is_active'=>true,'schema'=>['sections'=>[['id'=>'family','fields'=>[['name'=>'days','type'=>'checkboxGroup','optionsSource'=>'school_meeting_days','minSelections'=>2,'maxSelections'=>2]]]]]]);
    $r=app(DutyRotaService::class)->save($s,null,array_merge(['school_year_id'=>$y->id,'name'=>'Cleaning','capacity'=>2,'turns_owed'=>2,'source_form_id'=>$f->id,'source_field'=>'days','reminder_lead'=>3,'reminder_line'=>'Cleaning starts at 1:30 PM after class.','active'=>true],$attributes));
    return [$s,$y,$f,$r];
}
function dutyResponse(Form $f,string $email,array $days,string $time='2026-10-01 10:00:00'): FormResponse {
    return FormResponse::create(['masjid_id'=>$f->masjid_id,'form_id'=>$f->id,'respondent_name'=>ucfirst(trim(explode('@',$email)[0])).' Family','respondent_email'=>$email,'respondent_phone'=>'+15555550102','data'=>['days'=>$days],'status'=>'new','submitted_at'=>$time]);
}
function dutyOffice(): User { $u=User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Office','phone'=>'+15555550109']); Sanctum::actingAs($u,['staff']); return $u; }
function dutyUrl(Masjid $s,DutyRota $r): string { return "/api/admin/masjids/{$s->id}/duty-rotas/{$r->id}"; }
function dutyPublic(Masjid $s,DutyRota $r): string { return "/api/v1/schools/{$s->id}/duty-rotas/{$r->id}"; }
