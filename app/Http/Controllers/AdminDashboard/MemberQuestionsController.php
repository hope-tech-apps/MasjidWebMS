<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Contact, Service};
use App\Support\{MemberQuestions, TenantContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberQuestionsController extends Controller
{
    public function options()
    {
        abort_unless(request()->user()?->type === 'SuperAdmin',403);
        return response()->json(['status'=>'success','data'=>MemberQuestions::options(app(TenantContext::class)->get(),true)]);
    }
    public function updateOptions(Request $request)
    {
        abort_unless($request->user()?->type === 'SuperAdmin',403);
        // The SPA posts JSON; also accept actual form-encoded checkbox strings.
        $rules=['settings'=>['required','array:field,occupation,help']];
        foreach (MemberQuestions::KINDS as $question) {
            $rows=$request->input($question);
            if (is_array($rows)) {
                foreach ($rows as &$row) if (is_array($row) && array_key_exists('active',$row)) $row['active']=filter_var($row['active'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
                unset($row); $request->merge([$question=>$rows]);
            }
            if ($request->has("settings.$question.active")) $request->merge(['settings'=>array_replace_recursive($request->input('settings',[]),[$question=>['active'=>filter_var($request->input("settings.$question.active"),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)]])]);
            $rules[$question]=['present','array','max:100','list'];
            $rules["$question.*.id"]=['nullable','integer'];
            $rules["$question.*.label"]=['required','string','max:120'];
            $rules["$question.*.active"]=['required','boolean'];
            $rules["settings.$question"]=['required','array:active,prompt'];
            $rules["settings.$question.active"]=['required','boolean'];
            $rules["settings.$question.prompt"]=['required','string','max:80'];
        }
        $data=$request->validate($rules);
        MemberQuestions::editOptions(app(TenantContext::class)->get(),$data);
        return $this->options();
    }
    /** Choice labels include inactive options, so old answers remain readable. */
    public function show($masjid_id, $contact_id)
    {
        $contact=Contact::withTrashed()->findOrFail($contact_id); $org=(int)$contact->masjid_id;
        $lists=MemberQuestions::options($org,true); $answers=MemberQuestions::answers($contact); $questions=[]; $selected=[];
        foreach (MemberQuestions::KINDS as $kind) {
            $ids=$kind === 'help' ? $answers['help_ids'] : [$answers[$kind.'_id']];
            $selected[$kind]=array_values(array_map(fn($option)=>['id'=>$option['id'],'label'=>$option['label']],array_filter($lists[$kind],fn($option)=>in_array($option['id'],$ids,true))));
        }
        foreach ($lists['questions'] as $question) {
            $kind=$question['kind'];
            $questions[]=['kind'=>$kind,'prompt'=>$question['prompt'],'answer'=>$selected[$kind],'text'=>$answers[$kind.'_text']];
        }
        $interests=Service::where('masjid_id',$org)->whereIn('id',DB::table('contact_service_interests')->where('masjid_id',$org)->where('contact_id',$contact->id)->select('service_id'))->orderBy('id')->get(['id','title']);
        return response()->json(['status'=>'success','data'=>[
            'questions'=>$questions,'occupation'=>$selected['occupation'][0] ?? null,'occupation_text'=>$answers['occupation_text'],
            'help'=>$selected['help'],'help_text'=>$answers['help_text'],'interests'=>$interests,
        ]]);
    }
    public function filters()
    {
        $org=app(TenantContext::class)->get();
        return response()->json(['status'=>'success','data'=>MemberQuestions::options($org,true)+['interests'=>Service::where('masjid_id',$org)->orderBy('id')->get(['id','title'])]]);
    }
    /** Two aggregates regardless of the number of members; no person identifiers or free text. */
    private function countTables(): array
    {
        $org=app(TenantContext::class)->get(); $settings=MemberQuestions::options($org,true)['settings']; $tables=[];
        foreach (['field','occupation'] as $kind) {
            if (!($settings[$kind]['active'] ?? false)) continue;
            $answers=DB::table('member_question_answers as member_answer')
                ->join('contacts as contact',function($join){$join->on('contact.id','=','member_answer.contact_id')->on('contact.masjid_id','=','member_answer.masjid_id');})
                ->where('member_answer.masjid_id',$org)->whereNull('contact.deleted_at')
                ->select(['member_answer.id','member_answer.'.$kind.'_id']);
            $tables[$kind]=DB::table('member_question_options as option')
                ->leftJoinSub($answers,'answer',fn($join)=>$join->on('answer.'.$kind.'_id','=','option.id'))
                ->where('option.masjid_id',$org)->where('option.question',$kind)
                ->groupBy('option.id','option.label')->orderByDesc('count')->orderBy('option.label')->orderBy('option.id')
                ->select(['option.id','option.label'])->selectRaw('COUNT(answer.id) as count')->get()
                ->map(fn($row)=>['id'=>(int)$row->id,'label'=>$row->label,'count'=>(int)$row->count])->all();
        }
        return $tables;
    }
    public function counts()
    {
        return response()->json(['status'=>'success','data'=>(object)$this->countTables()]);
    }
    public function csv()
    {
        $tables=$this->countTables();
        return response()->streamDownload(function()use($tables){
            $out=\App\Support\SchoolRecordsCsv::open();
            foreach (['field'=>'Members by field','occupation'=>'Members by profession'] as $kind=>$title) {
                if (!array_key_exists($kind,$tables)) continue;
                \App\Support\SchoolRecordsCsv::row($out,[$title]);
                \App\Support\SchoolRecordsCsv::row($out,['Option','Members']);
                foreach ($tables[$kind] as $row) \App\Support\SchoolRecordsCsv::row($out,[\App\Support\SchoolRecordsCsv::text($row['label']),$row['count']]);
                \App\Support\SchoolRecordsCsv::row($out,[]);
            }
            fclose($out);
        },'member-question-counts.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
