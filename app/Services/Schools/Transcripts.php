<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Models\{Contact, GroupMembership, Masjid, TranscriptCourse, TranscriptFinal, TranscriptIssue, TranscriptPriorYear, TranscriptSetting};
use App\Support\{GradeLevel, LetterGrades, TranscriptMath as Math, TranscriptMode};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Transcripts
{
    public const METHOD = 'Final letters use the equal-weight average of published term percentages for each course and year, then the school scale. An office or authorised teacher may override a final letter with a staff-only reason. GPA is the sum of credit times letter points divided by graded attempted credits, including failed courses. Weighted GPA adds the school Honors or AP extra. Failed courses earn no credit. Ungraded courses and file-only prior years contribute no GPA. Arithmetic is exact; display rounds half up to two decimals. Cumulative GPA uses all eligible courses directly, never averages rounded yearly GPAs.';
    public const POINTS = ['A+'=>400,'A'=>400,'A−'=>370,'B+'=>330,'B'=>300,'B−'=>270,'C+'=>230,'C'=>200,'C−'=>170,'D+'=>130,'D'=>100,'D−'=>70,'F'=>0];

    public function policy(Masjid $school): array
    {
        $stored=TranscriptSetting::where('masjid_id',$school->id)->first();
        return ['points'=>$stored?->points ?? self::POINTS,'honors_extra'=>$stored?->honors_extra ?? 50,'ap_extra'=>$stored?->ap_extra ?? 100,'failing_letters'=>$stored?->failing_letters ?? ['F']];
    }
    public static function highSchool(?string $grade): bool
    {
        $position=GradeLevel::position($grade);
        return $position!==null && $position>=GradeLevel::position('9th');
    }
    public function live(Masjid $school, Contact $student, bool $staff=false): array
    {
        if ((int)$school->id !== (int)$student->masjid_id) throw new \LogicException('Student and school must agree.');
        $policy=$this->policy($school); $scale=$school->letter_grade_settings['scale'] ?? LetterGrades::DEFAULT_SCALE;
        // Read DECIMAL spellings without TermGrade's legacy float cast. Every join has its own tenant fence.
        $rows=DB::table('term_grades as tg')->join('report_cards as rc','rc.id','=','tg.report_card_id')
            ->join('school_terms as st','st.id','=','tg.school_term_id')->join('school_years as sy','sy.id','=','st.school_year_id')
            ->join('class_subjects as cs','cs.id','=','tg.class_subject_id')->join('group_memberships as gm','gm.id','=','rc.group_membership_id')
            ->where('tg.masjid_id',$school->id)->where('rc.masjid_id',$school->id)->where('st.masjid_id',$school->id)->where('sy.masjid_id',$school->id)->where('cs.masjid_id',$school->id)
            ->where('gm.masjid_id',$school->id)->where('gm.contact_id',$student->id)->whereColumn('cs.group_id','rc.group_id')->where('tg.contact_id',$student->id)->whereNotNull('tg.published_at')->whereNotNull('rc.published_at')->where('rc.type','report_card')
            ->whereColumn('rc.school_term_id','st.id')->orderBy('sy.first_day')->orderBy('st.position')->orderBy('tg.id')
            ->get(['tg.*','st.school_year_id','st.name as term_name','rc.grade_label','sy.label as year_label','sy.first_day','sy.last_day']);
        $settings=TranscriptCourse::where('masjid_id',$school->id)->whereIn('class_subject_id',$rows->pluck('class_subject_id'))->get()->keyBy('class_subject_id');
        $overrides=TranscriptFinal::where('masjid_id',$school->id)->where('contact_id',$student->id)->get()->keyBy(fn($row)=>$row->school_year_id.':'.$row->class_subject_id);
        $years=[]; $all=[];
        foreach ($rows->groupBy('school_year_id') as $yearId=>$yearRows) {
            // Earliest published dated term supplies the student's grade for this academic year.
            $first=$yearRows->first(); if (!self::highSchool($first->grade_label)) continue;
            $courses=[];
            foreach ($yearRows->groupBy('class_subject_id') as $subjectId=>$terms) {
                $values=$terms->whereNotNull('percentage')->map(fn($row)=>Math::hundredths((string)$row->percentage,'percentage'));
                $letter=Math::letter($values->sum(),$values->count(),$scale);
                $override=$overrides->get($yearId.':'.$subjectId); $config=$settings->get($subjectId);
                $course=$this->course($terms->first()->subject,$override?->letter ?? $letter,$config?->credit_hundredths,$config?->level ?? 'regular',$policy);
                $course['calculated_letter']=$letter;
                $course['average_percentage']=$values->isEmpty()?null:Math::ratio($values->sum(),$values->count()*100);
                $course['terms']=$terms->map(fn($row)=>['term'=>$row->term_name,'letter'=>$row->final_letter,'percentage'=>$row->percentage===null?null:Math::decimal(Math::hundredths((string)$row->percentage))])->all();
                if ($staff) $course['staff']=['class_subject_id'=>(int)$subjectId,'school_year_id'=>(int)$yearId,'override_letter'=>$override?->letter,'reason'=>$override?->reason,'by_user_id'=>$override?->overridden_by_user_id,'at'=>$override?->overridden_at?->toIso8601String()];
                $courses[]=$course;
            }
            $years[]=['year'=>$first->year_label,'grade'=>$first->grade_label,'origin'=>$school->name,'starts_on'=>substr($first->first_day,0,10),'ends_on'=>substr($first->last_day,0,10),'courses'=>$courses,'file_only'=>false,'excluded_from_gpa'=>false,'totals'=>Math::totals($courses)];
            $all=array_merge($all,$courses);
        }
        foreach (TranscriptPriorYear::where('masjid_id',$school->id)->where('contact_id',$student->id)->orderBy('year_label')->orderBy('id')->get() as $prior) {
            if (! self::highSchool($prior->grade_label)) continue;
            $courses=array_map(fn($course)=>$this->course($course['name'],$course['letter'],$course['credit_hundredths'],$course['level'],$policy),$prior->courses);
            $year=['year'=>$prior->year_label,'grade'=>$prior->grade_label,'origin'=>'From '.$prior->school_name,'starts_on'=>$prior->starts_on?->toDateString(),'ends_on'=>$prior->ends_on?->toDateString(),'courses'=>$courses,'file_only'=>$courses===[],'excluded_from_gpa'=>$courses===[],'has_attachment'=>$prior->group_resource_id!==null,'totals'=>Math::totals($courses)];
            if ($staff) $year['prior_id']=(int)$prior->id;
            $years[]=$year; $all=array_merge($all,$courses);
        }
        usort($years,fn($a,$b)=>strcmp($a['starts_on'] ?? $a['year'],$b['starts_on'] ?? $b['year']));
        $rosters=GroupMembership::where('masjid_id',$school->id)->where('contact_id',$student->id)->participants()->orderBy('id')->get();
        $withGrade=$rosters->filter(fn($row)=>filled($row->grade_label));
        $headerGrade=$withGrade->whereNull('left_on')->last()?->grade_label ?? $withGrade->last()?->grade_label ?? ($years===[]?null:$years[array_key_last($years)]['grade']);
        $logo=null; $media=$school->getFirstMedia('logos');
        if ($media && in_array($media->mime_type,['image/png','image/jpeg','image/gif'],true) && is_readable($media->getPath())) $logo='data:'.$media->mime_type.';base64,'.base64_encode((string)file_get_contents($media->getPath()));
        return ['designation'=>'Unofficial','school'=>['name'=>$school->name,'address'=>$school->address,'logo'=>$logo],
            'student'=>['name'=>trim($student->first_name.' '.$student->last_name),'date_of_birth'=>$student->dateOfBirthOrNull(),'grade'=>$headerGrade,
                'attended_from'=>$rosters->pluck('joined_at')->filter()->min()?->format('Y-m-d'),'attended_to'=>$rosters->contains(fn($m)=>$m->left_on===null)?null:$rosters->pluck('left_on')->filter()->max()?->format('Y-m-d')],
            'years'=>$years,'cumulative'=>Math::totals($all),'method'=>self::METHOD,'points'=>$policy,'generated_at'=>now()->toIso8601String(),'serial'=>null,'issued_at'=>null,'issued_by'=>null];
    }
    private function course(string $name,?string $letter,?int $credit,string $level,array $policy): array
    {
        if ($letter!==null && !array_key_exists($letter,$policy['points'])) throw ValidationException::withMessages(['points'=>['Add points for every final letter in this student record before calculating a transcript.']]);
        return ['name'=>$name,'letter'=>$letter,'credit_hundredths'=>$credit,'credit'=>$credit===null?null:Math::decimal($credit),'level'=>$level,'passing'=>$letter!==null && !in_array($letter,$policy['failing_letters'],true),
            'points_hundredths'=>$letter===null?0:$policy['points'][$letter],'extra_hundredths'=>match($level){'honors'=>$policy['honors_extra'],'ap'=>$policy['ap_extra'],default=>0}];
    }
    public function issue(Masjid $school,Contact $student,int $actor): TranscriptIssue
    {
        return DB::transaction(function () use($school,$student,$actor) {
            $locked=Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail(); TranscriptMode::assertEnabled($locked);
            $snapshot=$this->live($locked,$student);
            foreach ($snapshot['years'] as $year) foreach($year['courses'] as $course) if ($course['credit_hundredths']===null) throw ValidationException::withMessages(['credits'=>['Set credit for every course before issuing an official transcript.']]);
            $serial=(string)Str::uuid(); $snapshot['designation']='Official'; $snapshot['serial']=$serial; $snapshot['issued_at']=now()->toIso8601String();
            $snapshot['issued_by']=\App\Models\User::withTrashed()->whereKey($actor)->value('name');
            $bytes=app(TranscriptPdf::class)->render($snapshot); $path='transcripts/'.$school->id.'/issued/'.$serial.'.pdf';
            if (!Storage::disk('local')->put($path,$bytes)) throw new \RuntimeException('The transcript PDF could not be saved.');
            try { return TranscriptIssue::create(['masjid_id'=>$school->id,'contact_id'=>$student->id,'serial'=>$serial,'snapshot'=>$snapshot,'pdf_path'=>$path,'pdf_sha256'=>hash('sha256',$bytes),'issued_by_user_id'=>$actor,'issued_at'=>now()]); }
            catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        });
    }
}
