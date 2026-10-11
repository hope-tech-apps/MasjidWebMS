<?php

namespace Tests\Feature;

use App\Models\{Group, Masjid, MasjidUser, SchoolYear, TimetableClassDay, TimetableClassRoom, TimetableDay, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, TimetableRoom, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchoolTimetableTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_timetable_model_hides_foreign_rows_and_stamps_the_tenant(): void
    {
        $a=$this->makeMasjid('a'); $b=$this->makeMasjid('b'); $tenant=app(TenantContext::class);
        $tenant->forgetTenant();
        $year=SchoolYear::create(['masjid_id'=>$b->id,'label'=>'Practice year','first_day'=>'2026-09-07','last_day'=>'2027-06-25']);
        $g=Group::factory()->create(['masjid_id'=>$b->id,'kind'=>'class']);
        $t=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550200']);
        $set=TimetablePeriodSet::create(['masjid_id'=>$b->id,'school_year_id'=>$year->id,'name'=>'Practice Set']);
        $p=TimetablePeriod::create(['masjid_id'=>$b->id,'period_set_id'=>$set->id,'name'=>'Practice Period','starts_at'=>'08:00','ends_at'=>'09:00','kind'=>'teaching','position'=>1]);
        $room=TimetableRoom::create(['masjid_id'=>$b->id,'name'=>'Practice Room','name_key'=>'practice room']);
        $meeting=TimetableMeeting::create(['masjid_id'=>$b->id,'school_year_id'=>$year->id,'group_id'=>$g->id,'weekday'=>1,'period_id'=>$p->id,'kind'=>'activity','activity_name'=>'Practice activity','effective_from'=>'2026-10-10']);
        $rows=[$set,$p,$room,$meeting,TimetableDay::create(['masjid_id'=>$b->id,'school_year_id'=>$year->id,'weekday'=>1,'period_set_id'=>$set->id]),TimetableClassDay::create(['masjid_id'=>$b->id,'school_year_id'=>$year->id,'group_id'=>$g->id,'weekday'=>1,'period_set_id'=>$set->id]),TimetableClassRoom::create(['masjid_id'=>$b->id,'group_id'=>$g->id,'room_id'=>$room->id]),TimetableMeetingTeacher::create(['masjid_id'=>$b->id,'meeting_id'=>$meeting->id,'user_id'=>$t->id])];
        $tenant->set($a->id);
        foreach($rows as $row) {
            $model=$row::class;
            $this->assertNull($model::find($row->id)); $this->assertSame(0,$model::whereKey($row->id)->update(['updated_at'=>now()])); $this->assertSame(0,$model::whereKey($row->id)->delete());
            $attrs=$row->getAttributes(); unset($attrs['id'],$attrs['created_at'],$attrs['updated_at']);
            if($row instanceof TimetableMeetingTeacher) $attrs['user_id']=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550201'])->id;
            $stamped=$model::create($attrs); $this->assertSame($a->id,(int)$stamped->masjid_id);
        }
        $tenant->forgetTenant();
    }
    #[Test]
    public function office_schedule_routes_read_only_the_bound_school_even_for_a_superadmin(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $tenant=app(TenantContext::class); $tenant->forgetTenant();
        $schools=[$this->makeMasjid('a'),$this->makeMasjid('b')]; $worlds=[];
        foreach ($schools as $index=>$school) {
            $school->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_calendar_terms'=>true,'school_timetable'=>true]])->save();
            $prefix=$index===0 ? 'Local' : 'Foreign';
            $year=SchoolYear::create(['masjid_id'=>$school->id,'label'=>$prefix.' year','first_day'=>'2026-09-07','last_day'=>'2027-06-25','meeting_weekdays'=>[1]]);
            $g=Group::factory()->create(['masjid_id'=>$school->id,'kind'=>'class','name'=>$prefix.' class']);
            $t=User::factory()->create(['type'=>'Teacher','name'=>$prefix.' teacher','phone'=>'+1555555070'.$index]);
            MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$t->id,'role'=>'teacher']);
            $set=TimetablePeriodSet::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'name'=>$prefix.' set']);
            $p=TimetablePeriod::create(['masjid_id'=>$school->id,'period_set_id'=>$set->id,'name'=>'P1','starts_at'=>'08:00','ends_at'=>'09:00','kind'=>'teaching','position'=>1]);
            TimetableDay::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'weekday'=>1,'period_set_id'=>$set->id]);
            $room=TimetableRoom::create(['masjid_id'=>$school->id,'name'=>$prefix.' hall','name_key'=>strtolower($prefix).' hall']);
            $m=TimetableMeeting::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'group_id'=>$g->id,'period_id'=>$p->id,'weekday'=>1,'kind'=>'activity','activity_name'=>$prefix.' activity','room_id'=>$room->id,'effective_from'=>'2026-09-07']);
            TimetableMeetingTeacher::create(['masjid_id'=>$school->id,'meeting_id'=>$m->id,'user_id'=>$t->id]);
            $worlds[]=compact('year','g','t','room');
        }
        $super=User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15555550709']);
        \Laravel\Sanctum\Sanctum::actingAs($super,['staff']);
        foreach ($worlds as $i=>$world) {
            $base='/api/admin/masjids/'.$schools[$i]->id.'/timetable/years/'.$world['year']->id.'/schedules/';
            foreach (['teacher/'.$world['t']->id,'class/'.$world['g']->id,'location/'.$world['room']->id,'school'] as $lens) {
                $response=$this->getJson($base.$lens.'?as_of=2026-10-12')->assertOk();
                $this->assertStringNotContainsString($i===0 ? 'Foreign' : 'Local',$response->getContent());
            }
            $other=$worlds[1-$i];
            foreach (['teacher/'.$other['t']->id,'class/'.$other['g']->id,'location/'.$other['room']->id] as $lens) $this->getJson($base.$lens.'?as_of=2026-10-12')->assertNotFound();
            $this->getJson(str_replace('/years/'.$world['year']->id,'/years/'.$other['year']->id,$base).'school?as_of=2026-10-12')->assertNotFound();
        }
        $tenant->forgetTenant();
    }
    private function makeMasjid(string $suffix): Masjid
    {
        return Masjid::create(['name'=>'Practice School '.$suffix,'email'=>$suffix.'@example.invalid','phone'=>'+1555555020'.($suffix==='a'?'2':'3'),'country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    }
}
