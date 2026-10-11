<?php

namespace Tests\Feature;

use App\Models\{Group, Masjid, SchoolYear, TimetableClassDay, TimetableClassRoom, TimetableDay, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, TimetableRoom, User};
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
    private function makeMasjid(string $suffix): Masjid
    {
        return Masjid::create(['name'=>'Practice School '.$suffix,'email'=>$suffix.'@example.invalid','phone'=>'+1555555020'.($suffix==='a'?'2':'3'),'country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    }
}
