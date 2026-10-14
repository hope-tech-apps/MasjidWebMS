<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\ReportCardCriteria;
use Illuminate\Http\Request;

/** Both office and class-teacher routes use the same authority and revision checks. */
class ReportCardCriteriaController extends Controller
{
    public function show(Request $request, $masjid_id)
    {
        return response()->json(['status'=>'success','data'=>ReportCardCriteria::editor(Masjid::findOrFail($masjid_id),$request->user())]);
    }

    public function update(Request $request, $masjid_id)
    {
        $request->merge(['next_term'=>filter_var($request->input('next_term',false),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)]);
        $bands=$request->input('bands');
        if (is_array($bands)) {
            foreach ($bands as &$band) if (is_array($band) && array_key_exists('is_default',$band)) $band['is_default']=filter_var($band['is_default'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            unset($band);
            $request->merge(['bands'=>$bands]);
        }
        $data=$request->validate(['bands'=>['required','array'],'revision'=>['required','integer','min:0'],'next_term'=>['required','boolean']]);
        return response()->json(['status'=>'success','data'=>ReportCardCriteria::save(Masjid::findOrFail($masjid_id),$data['bands'],$request->user(),(bool)$data['next_term'],(int)$data['revision'])]);
    }
}
