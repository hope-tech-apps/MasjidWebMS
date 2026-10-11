<?php

namespace App\Jobs;

use App\Mail\DutyRotaMail;
use App\Models\DutyRotaMail as MailRecord;
use App\Models\{DutyRota, DutyRotaAssignment, Masjid};
use App\Support\{DutyRotaMode, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Crypt, DB, Mail};
use Throwable;

class SendDutyRotaMail implements ShouldQueue
{
    use Queueable;
    // A delivery crash may lose a notice, but must never repeat one. Office can explicitly reissue.
    public int $tries=1;
    public function __construct(public int $mailId, public int $schoolId, public string $encryptedContent) {}
    public function handle(): void
    {
        app(TenantContext::class)->runWithout(function () {
            $row=MailRecord::where('masjid_id',$this->schoolId)->find($this->mailId);
            if (! $row || $row->status!=='queued') return;
            $rota=DutyRota::where('masjid_id',$this->schoolId)->find($row->rota_id);
            if (! DutyRotaMode::enabled(Masjid::find($this->schoolId)) || ! $rota?->active) { $this->failed(new \RuntimeException('Rota stopped')); return; }
            if ($row->kind==='reminder') {
                $a=DutyRotaAssignment::where('masjid_id',$this->schoolId)->find($row->assignment_id);
                $calendar=\App\Support\SchoolDateAuthority::for($this->schoolId);
                if (! $a || $a->released_at || $a->duty_on < $calendar->today() || ! in_array($a->duty_on,$calendar->openDaysBetween($a->duty_on,$a->duty_on),true)) { $this->failed(new \RuntimeException('Assignment no longer due')); return; }
            }
            // Atomic queue claim: duplicate job execution cannot deliver a second time.
            if (! DB::table('duty_rota_mails')->where('id',$row->id)->where('status','queued')->update(['status'=>'sending'])) return;
            try {
                $data=json_decode(Crypt::decryptString($this->encryptedContent),true,512,JSON_THROW_ON_ERROR);
                Mail::to($data['address'])->send(new DutyRotaMail($data['orgName'],$data['orgEmail'],$data['subject'],$data['body']));
                $row->forceFill(['status'=>'sent','sent_at'=>now()])->save();
                if ($row->kind==='pick') \App\Models\DutyRotaFamily::where('masjid_id',$this->schoolId)->whereKey($row->family_id)->update(['link_sent_at'=>now()]);
                if ($row->assignment_id) DutyRotaAssignment::where('masjid_id',$this->schoolId)->whereKey($row->assignment_id)->update(['reminder_sent_at'=>now()]);
            } catch (Throwable $e) { $this->failed($e); throw $e; }
        });
    }
    public function failed(?Throwable $exception): void
    {
        DB::table('duty_rota_mails')->where('masjid_id',$this->schoolId)->where('id',$this->mailId)->whereIn('status',['queued','sending'])->update(['status'=>'failed','failed_at'=>now()]);
    }
}
