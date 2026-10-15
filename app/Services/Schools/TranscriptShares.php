<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Models\{Contact, GroupMembership, Masjid, TranscriptIssue, TranscriptReceived, TranscriptShareRequest};
use App\Support\{TenantContext, TranscriptMode};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;

final class TranscriptShares
{
    /** The only cross-tenant request reader. No sender model is serialized to the receiver. */
    public function incoming(int $school,?int $id=null)
    {
        return DB::table('transcript_share_requests')->where('receiving_masjid_id',$school)->whereNotNull('consented_at')
            ->select(['id','masjid_id','contact_id','receiving_masjid_id','status','sending_school','student_name','date_of_birth as student_birth_date','requested_at','requested_by_user_id','consented_at','resolved_at','resolved_by_user_id'])
            ->whereIn('status',['pending','accepted','declined','withdrawn'])->when($id!==null,fn($q)=>$q->where('id',$id));
    }
    public function header(object $request): array
    {
        $header=['id'=>(int)$request->id,'sending_school'=>$request->sending_school,'student_name'=>$request->student_name,'date_of_birth'=>$request->student_birth_date ? substr($request->student_birth_date,0,10):null,'status'=>$request->status,'requested_at'=>$request->requested_at];
        if ($request->status!=='pending') $header['resolved_at']=$request->resolved_at;
        if (in_array($request->status,['accepted','declined'],true)) $header['resolved_by_user_id']=$request->resolved_by_user_id;
        return $header;
    }
    public function request(Masjid $sender,Contact $student,int $receiver,int $actor): TranscriptShareRequest
    {
        return DB::transaction(function () use($sender,$student,$receiver,$actor) {
            $schools=Masjid::whereIn('id',[$sender->id,$receiver])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            TranscriptMode::assertEnabled($schools->get($sender->id));
            $destination=$schools->get($receiver);
            if ($receiver===(int)$sender->id || !TranscriptMode::enabled($destination)) throw ValidationException::withMessages(['receiving_masjid_id'=>['Choose another school with transcripts switched on.']]);
            return TranscriptShareRequest::create(['masjid_id'=>$sender->id,'contact_id'=>$student->id,'receiving_masjid_id'=>$receiver,'sending_school'=>$sender->name,'student_name'=>trim($student->first_name.' '.$student->last_name),'date_of_birth'=>$student->dateOfBirthOrNull(),'status'=>'awaiting_family','requested_by_user_id'=>$actor,'requested_at'=>now()]);
        });
    }
    public function consent(TranscriptShareRequest $request,?int $guardian,?int $actor,?string $evidence): TranscriptShareRequest
    {
        return DB::transaction(function () use($request,$guardian,$actor,$evidence) {
            $schools=Masjid::whereIn('id',[$request->masjid_id,$request->receiving_masjid_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            TranscriptMode::assertEnabled($schools->get($request->masjid_id)); TranscriptMode::assertEnabled($schools->get($request->receiving_masjid_id));
            $locked=TranscriptShareRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->state($locked,'awaiting_family');
            if ($guardian!==null && !GroupMembership::where('contact_id',$guardian)->where('guardian_of_contact_id',$locked->contact_id)->where('role','guardian')->exists()) abort(404);
            if ($guardian===null && trim((string)$evidence)==='') throw ValidationException::withMessages(['evidence'=>['Record where the signed paper consent is held.']]);
            $locked->forceFill(['status'=>'pending','consent_method'=>$guardian===null?'paper':'portal','consented_by_contact_id'=>$guardian,'consented_by_user_id'=>$actor,'consent_evidence'=>$guardian===null?$evidence:null,'consented_at'=>now()])->save();
            return $locked;
        });
    }
    public function withdraw(TranscriptShareRequest $request,?int $actor,?int $guardian=null): TranscriptShareRequest
    {
        return DB::transaction(function () use($request,$actor,$guardian) {
            Masjid::whereIn('id',[$request->masjid_id,$request->receiving_masjid_id])->orderBy('id')->lockForUpdate()->get();
            $locked=TranscriptShareRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status,['awaiting_family','pending'],true)) $this->invalid();
            $locked->forceFill(['status'=>'withdrawn','resolved_by_user_id'=>$actor,'withdrawn_by_contact_id'=>$guardian,'resolved_at'=>now()])->save(); return $locked;
        });
    }
    public function resolve(Masjid $receiver,int $id,int $actor,bool $accept,?int $contactId): ?TranscriptReceived
    {
        $candidate=$this->incoming((int)$receiver->id,$id)->first(); abort_unless($candidate,404);
        $newIssuePath=null; $receivedPath=null;
        try { return DB::transaction(function () use($receiver,$candidate,$actor,$accept,$contactId,&$newIssuePath,&$receivedPath) {
            $schools=Masjid::whereIn('id',[$receiver->id,$candidate->masjid_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            TranscriptMode::assertEnabled($schools->get($receiver->id));
            if ($accept) TranscriptMode::assertEnabled($schools->get($candidate->masjid_id));
            $request=$this->incoming((int)$receiver->id,(int)$candidate->id)->lockForUpdate()->first(); abort_unless($request,404);
            $this->state($request,'pending');
            if (!$accept) { $this->resolved($request,$actor,'declined'); return null; }
            if ($contactId!==null) {
                // Explicitly the receiving school's own student, not only through the tenant scope.
                Contact::where('masjid_id',$receiver->id)->findOrFail($contactId);
                abort_unless(GroupMembership::where('masjid_id',$receiver->id)->where('contact_id',$contactId)->participants()->whereNull('left_on')->exists(),404);
            }
            // The request has both consents. This bounded transfer reads precisely its student and issue,
            // under the sender school lock; no controller gains a general cross-school reader.
            $issue=app(TenantContext::class)->runWithout(function () use($request,$actor,$schools,&$newIssuePath) {
                $issue=TranscriptIssue::where('masjid_id',$request->masjid_id)->where('contact_id',$request->contact_id)->orderByDesc('id')->first();
                if ($issue) return $issue;
                $student=Contact::where('masjid_id',$request->masjid_id)->findOrFail($request->contact_id);
                if ($request->requested_by_user_id===null) throw ValidationException::withMessages(['issue'=>['Ask the sending office to issue an official copy before accepting this request.']]);
                $issued=app(Transcripts::class)->issue($schools->get($request->masjid_id),$student,(int)$request->requested_by_user_id);
                $newIssuePath=$issued->pdf_path; return $issued;
            });
            $bytes=Storage::disk('local')->get($issue->pdf_path);
            if (hash('sha256',$bytes)!==$issue->pdf_sha256) throw new \RuntimeException('The issued transcript file failed its integrity check.');
            $path='transcripts/'.$receiver->id.'/received/'.\Illuminate\Support\Str::uuid().'.pdf';
            $receivedPath=$path;
            if (!Storage::disk('local')->put($path,$bytes)) throw new \RuntimeException('The received transcript could not be saved.');
            try {
                $copy=TranscriptReceived::create(['masjid_id'=>$receiver->id,'contact_id'=>$contactId,'source_request_id'=>$request->id,'origin_school'=>$issue->snapshot['school']['name'],'origin_serial'=>$issue->serial,'received_at'=>now(),'accepted_by_user_id'=>$actor,'snapshot'=>$issue->snapshot,'pdf_path'=>$path,'pdf_sha256'=>$issue->pdf_sha256]);
                $this->resolved($request,$actor,'accepted',['issued_transcript_id'=>$issue->id]); return $copy;
            } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        }); } catch (\Throwable $e) {
            foreach ([$newIssuePath,$receivedPath] as $path) if ($path!==null) Storage::disk('local')->delete($path);
            throw $e;
        }
    }
    private function resolved(object $request,int $actor,string $status,array $extra=[]): void
    {
        DB::table('transcript_share_requests')->where('id',$request->id)->where('masjid_id',$request->masjid_id)->where('receiving_masjid_id',$request->receiving_masjid_id)->update($extra+['status'=>$status,'resolved_by_user_id'=>$actor,'resolved_at'=>now(),'updated_at'=>now()]);
    }
    private function state(object $request,string $status): void { if ($request->status!==$status) $this->invalid(); }
    private function invalid(): never { throw ValidationException::withMessages(['status'=>['This transcript request has already changed. Refresh its history before continuing.']]); }
}
