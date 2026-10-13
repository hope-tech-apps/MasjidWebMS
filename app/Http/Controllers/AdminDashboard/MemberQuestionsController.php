<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Contact, MemberQuestionAnswer, MemberQuestionHelp, MemberQuestionOption, Service};
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
        foreach (['occupation','help'] as $question) {
            $rows=$request->input($question);
            if (!is_array($rows)) continue;
            foreach ($rows as &$row) if (is_array($row) && array_key_exists('active',$row)) $row['active']=filter_var($row['active'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            unset($row); $request->merge([$question=>$rows]);
        }
        $data=$request->validate([
            'occupation'=>['present','array','max:100','list'], 'help'=>['present','array','max:100','list'],
            'occupation.*.id'=>['nullable','integer'], 'help.*.id'=>['nullable','integer'],
            'occupation.*.label'=>['required','string','max:120'], 'help.*.label'=>['required','string','max:120'],
            'occupation.*.active'=>['required','boolean'], 'help.*.active'=>['required','boolean'],
        ]);
        MemberQuestions::editOptions(app(TenantContext::class)->get(),$data);
        return $this->options();
    }
    /** Choice labels include inactive options, so old answers remain readable. */
    public function show($masjid_id, $contact_id)
    {
        $contact=Contact::withTrashed()->findOrFail($contact_id); $org=(int)$contact->masjid_id;
        $answer=MemberQuestionAnswer::where('contact_id',$contact->id)->first();
        $occupation=$answer?->occupation_id === null ? null : MemberQuestionOption::find($answer->occupation_id);
        $help=$answer === null ? collect() : MemberQuestionOption::where('question','help')->whereIn('id',MemberQuestionHelp::where('answer_id',$answer->id)->select('option_id'))->orderBy('position')->orderBy('id')->get(['id','label']);
        $interests=Service::where('masjid_id',$org)->whereIn('id',DB::table('contact_service_interests')->where('masjid_id',$org)->where('contact_id',$contact->id)->select('service_id'))->orderBy('id')->get(['id','title']);
        return response()->json(['status'=>'success','data'=>[
            'occupation'=>$occupation === null ? null : ['id'=>$occupation->id,'label'=>$occupation->label],
            'occupation_text'=>$answer?->occupation_text, 'help'=>$help, 'help_text'=>$answer?->help_text, 'interests'=>$interests,
        ]]);
    }
    public function filters()
    {
        $org=app(TenantContext::class)->get();
        return response()->json(['status'=>'success','data'=>MemberQuestions::options($org,true)+['interests'=>Service::where('masjid_id',$org)->orderBy('id')->get(['id','title'])]]);
    }
}
