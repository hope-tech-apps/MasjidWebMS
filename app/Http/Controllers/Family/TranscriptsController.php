<?php

namespace App\Http\Controllers\Family;

use App\Models\{Masjid, TranscriptShareRequest};
use App\Services\Schools\{Transcripts, TranscriptPdf, TranscriptShares};
use App\Support\TranscriptMode;
use Illuminate\Http\Request;

class TranscriptsController extends FamilyController
{
    private function child($masjid_id,$group_id,$membership_id)
    {
        $school=Masjid::findOrFail($masjid_id); abort_unless(TranscriptMode::enabled($school),404);
        $group=$this->group($group_id); $child=$this->subject($group,$membership_id);
        abort_unless($child->role==='member',404); return [$school,$child];
    }
    public function show($masjid_id,$group_id,$membership_id)
    {
        [$school,$child]=$this->child($masjid_id,$group_id,$membership_id);
        return response()->json(['status'=>'success','data'=>app(Transcripts::class)->live($school,$child->contact),
            'shares'=>TranscriptShareRequest::where('contact_id',$child->contact_id)->whereIn('status',['awaiting_family','pending'])->get()->map(fn($share)=>['id'=>$share->id,'receiving_school'=>Masjid::whereKey($share->receiving_masjid_id)->value('name'),'status'=>$share->status])]);
    }
    public function pdf($masjid_id,$group_id,$membership_id)
    {
        [$school,$child]=$this->child($masjid_id,$group_id,$membership_id);
        return response(app(TranscriptPdf::class)->render(app(Transcripts::class)->live($school,$child->contact)),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="unofficial-transcript.pdf"','Cache-Control'=>'private, no-store']);
    }
    public function consent(Request $request,$masjid_id,$group_id,$membership_id,$share_id)
    {
        [, $child]=$this->child($masjid_id,$group_id,$membership_id);
        $share=TranscriptShareRequest::where('contact_id',$child->contact_id)->findOrFail($share_id);
        $request->validate(['consent'=>['required','accepted']]);
        app(TranscriptShares::class)->consent($share,(int)$this->contact()->id,null,null);
        return response()->json(['status'=>'success','data'=>['status'=>'pending']]);
    }
    public function withdraw($masjid_id,$group_id,$membership_id,$share_id)
    {
        [, $child]=$this->child($masjid_id,$group_id,$membership_id);
        $share=TranscriptShareRequest::where('contact_id',$child->contact_id)->findOrFail($share_id);
        app(TranscriptShares::class)->withdraw($share,null,(int)$this->contact()->id);
        return response()->json(['status'=>'success','data'=>['status'=>'withdrawn']]);
    }
}
