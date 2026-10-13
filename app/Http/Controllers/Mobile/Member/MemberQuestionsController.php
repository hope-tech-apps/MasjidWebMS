<?php

namespace App\Http\Controllers\Mobile\Member;

use App\Http\Controllers\Controller;
use App\Support\MemberQuestions;
use Illuminate\Http\Request;

class MemberQuestionsController extends Controller
{
    public function index(Request $request)
    {
        $contact=$request->user(); MemberQuestions::assertAdult($contact);
        return response()->json(['status'=>'success','data'=>MemberQuestions::options($contact->masjid_id,member:true)+['answers'=>MemberQuestions::answers($contact)]]);
    }
    public function update(Request $request)
    {
        $data=$request->validate([
            'occupation_id'=>['nullable','integer','min:1'], 'occupation_text'=>['nullable','string','max:120'],
            'help_ids'=>['sometimes','array','list'], 'help_ids.*'=>['required','integer','min:1','distinct'], 'help_text'=>['nullable','string','max:200'],
        ]);
        MemberQuestions::replace($request->user(),$data);
        return $this->index($request);
    }
}
