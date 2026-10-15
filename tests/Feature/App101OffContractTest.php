<?php

namespace Tests\Feature;

use App\Models\{Contact, Masjid, User};
use App\Support\{TenantContext, MobileCache};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;

class App101OffContractTest extends TestCase
{
    use RefreshDatabase;
    #[Test]
    public function off_reads_and_deletion_match_109ce744_except_explicit_answer_cleanup(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 12:00:00 UTC'));Storage::fake('public');Cache::flush();$this->seed(RolesAndPermissionsSeeder::class);
        $admin=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550101','name'=>'Practice Admin','email'=>'admin@example.invalid']);
        $org=Masjid::create(['name'=>'Practice','email'=>'office@example.invalid','phone'=>'+15555550100','country_id'=>1,'city_id'=>1,'address'=>'Practice','latitude'=>0,'longitude'=>0,'user_id'=>$admin->id,'crm_enabled'=>true]);
        $member=new Contact();$member->forceFill(['masjid_id'=>$org->id,'first_name'=>'Practice','last_name'=>'Member','email'=>'member@example.invalid','login_email'=>'member@example.invalid','signup_source'=>'app','verified_at'=>now()])->save();
        $token=$member->createMemberToken()->plainTextToken;$out=[];
        $request=function($name,$method,$url,$headers=[]) use (&$out){DB::enableQueryLog();DB::flushQueryLog();$r=$this->{$method.'Json'}($url,$headers)->assertOk();$out[$name]=['body'=>$r->getContent(),'sql'=>array_column(DB::getQueryLog(),'query')];DB::disableQueryLog();};
        foreach(['cold','warm'] as $temperature){
            foreach(['show'=>'/api/mobile/masjids/'.$org->id,'settings'=>'/api/v1/settings','features'=>'/api/mobile/masjids/'.$org->id.'/features'] as $name=>$url){auth()->forgetGuards();app(TenantContext::class)->forgetTenant();$request($temperature.':'.$name,'get',$url,['masjid-id'=>(string)$org->id]);}
        }
        foreach(['index'=>'/api/admin/masjids/'.$org->id.'/contacts','detail'=>'/api/admin/masjids/'.$org->id.'/contacts/'.$member->id] as $name=>$url){auth()->forgetGuards();app(TenantContext::class)->forgetTenant();Sanctum::actingAs($admin);$request('office:'.$name,'get',$url);}
        auth()->forgetGuards();app(TenantContext::class)->forgetTenant();$request('member:interests','get','/api/mobile/masjids/'.$org->id.'/interests',['Authorization'=>'Bearer '.$token]);
        auth()->forgetGuards();app(TenantContext::class)->forgetTenant();\App\Support\CartTables::forget();DB::enableQueryLog();DB::flushQueryLog();$r=$this->deleteJson('/api/mobile/masjids/'.$org->id.'/me',[],['Authorization'=>'Bearer '.$token])->assertOk();$out['member:delete']=['body'=>$r->getContent(),'sql'=>array_column(DB::getQueryLog(),'query')];DB::disableQueryLog();
        // Account deletion now owns answer cleanup in application code. Pin those two
        // statements separately while retaining the original fixture and every legacy query.
        $sql=$out['member:delete']['sql'];
        $added=array_values(array_filter($sql,fn($q)=>str_contains($q,'member_question_answers')));
        $this->assertCount(2,$added);
        $this->assertStringContainsString('sqlite_master',$added[0]);
        $this->assertSame('delete from "member_question_answers" where "masjid_id" = ? and "contact_id" = ?',$added[1]);
        // The absence-email logs are office delivery history kept like group messages: two reads, nothing written.
        $absence=array_values(array_filter($sql,fn($q)=>str_contains($q,'attendance_absence_mail')));
        // Each is guarded by a table check for the moment code is live before its migration has run.
        $this->assertSame([
            'select exists (select 1 from "main".sqlite_master where name = \'attendance_absence_mails\' and type = \'table\') as "exists"',
            'select exists(select * from "attendance_absence_mails" where ("guardian_contact_id" = ?)) as "exists"',
            'select exists (select 1 from "main".sqlite_master where name = \'attendance_absence_mail_children\' and type = \'table\') as "exists"',
            'select exists(select * from "attendance_absence_mail_children" where ("guardian_contact_id" = ? or "student_contact_id" = ?)) as "exists"',
        ],$absence);
        // Issued term grades are academic history kept like the roster: one guarded read.
        $this->assertSame([
            'select exists (select 1 from "main".sqlite_master where name = \'term_grades\' and type = \'table\') as "exists"',
            'select exists(select * from "term_grades" where ("contact_id" = ?)) as "exists"',
        ],array_values(array_filter($sql,fn($q)=>str_contains($q,'term_grades'))));
        $period=array_values(array_filter($sql,fn($q)=>str_contains($q,'period_attendance_records')));
        $this->assertSame([
            'select exists (select 1 from "main".sqlite_master where name = \'period_attendance_records\' and type = \'table\') as "exists"',
            'select exists(select * from "period_attendance_records" where ("student_contact_id" = ?)) as "exists"',
        ],$period);
        // Admissions office records are three guarded existence reads; the original fixture remains verbatim.
        foreach (['school_reenrolment_answers' => ['student_contact_id', 'answered_by_contact_id'], 'school_reenrolment_deposits' => ['student_contact_id'], 'school_admission_mails' => ['guardian_contact_id']] as $table => $columns) {
            $added = array_values(array_filter($sql, fn ($q) => str_contains($q, $table)));
            $condition = implode(' or ', array_map(fn ($c) => '"'.$c.'" = ?', $columns));
            if ($table === 'school_admission_mails') $condition .= " or exists (select 1 from json_each(children) as admission_child where json_extract(admission_child.value, '$.student_contact_id') = ?)";
            $this->assertSame([
                'select exists (select 1 from "main".sqlite_master where name = \''.$table.'\' and type = \'table\') as "exists"',
                'select exists(select * from "'.$table.'" where ('.$condition.')) as "exists"',
            ], $added);
        }
        // Financial records are retained; each new table has a separately pinned guarded read.
        $tuition = array_values(array_filter($sql, fn ($q) => str_contains($q, 'tuition_')));
        $expected = [];
        foreach (['tuition_children' => ['contact_id'], 'tuition_guardians' => ['contact_id'], 'tuition_lines' => ['child_contact_id', 'payer_contact_id'], 'tuition_checkouts' => ['payer_contact_id'], 'tuition_mails' => ['guardian_contact_id']] as $table => $columns) {
            $expected[] = 'select exists (select 1 from "main".sqlite_master where name = \''.$table.'\' and type = \'table\') as "exists"';
            $expected[] = 'select exists(select * from "'.$table.'" where ('.implode(' or ', array_map(fn ($c) => '"'.$c.'" = ?', $columns)).')) as "exists"';
        }
        $this->assertSame($expected, $tuition);
        $out['member:delete']['sql']=array_values(array_filter($sql,fn($q)=>!str_contains($q,'school_reenrolment_') && !str_contains($q,'school_admission_mails') && !str_contains($q,'tuition_') && !str_contains($q,'member_question_answers') && !str_contains($q,'attendance_absence_mail') && !str_contains($q,'term_grades') && !str_contains($q,'period_attendance_records')));
        $this->assertSame(json_decode(file_get_contents(base_path('tests/fixtures/app101-off-109ce744.json')),true),$out);
    }
}
