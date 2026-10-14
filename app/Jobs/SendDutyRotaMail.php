<?php

namespace App\Jobs;

use App\Mail\DutyRotaMail;
use App\Models\DutyRotaMail as MailRecord;
use App\Models\{DutyRota, DutyRotaFamily, DutyRotaAssignment, Masjid};
use App\Support\{DutyRotaMode, DutyRotaService, DutyRotaMailService, SchoolDateAuthority, SchoolCalendarReaders, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Mail};
use Throwable;

class SendDutyRotaMail implements ShouldQueue
{
    use Queueable;
    // A delivery crash may lose a notice, but must never repeat one. Office can explicitly reissue.
    public int $tries=1;
    public function __construct(public int $mailId, public int $schoolId, public array $held=[]) {}
    public function handle(): void
    {
        app(TenantContext::class)->runWithout(function () {
            $row=MailRecord::where('masjid_id',$this->schoolId)->find($this->mailId);
            if (! $row || $row->status!=='queued') return;
            $rota=DutyRota::where('masjid_id',$this->schoolId)->find($row->rota_id);
            if (! DutyRotaMode::enabled(Masjid::find($this->schoolId)) || ! $rota?->active) { $this->failed(new \RuntimeException('Rota stopped')); return; }
            try {
                app(DutyRotaService::class)->locked($rota,function($r) use($row) {
                    $row->refresh(); if ($row->status!=='queued') return;
                    if (! $r->active) { $this->failed(new \RuntimeException('Rota stopped')); return; }
                    SchoolCalendarReaders::forget($this->schoolId);
                    $calendar=SchoolDateAuthority::for($this->schoolId);
                    $family=DutyRotaFamily::where('masjid_id',$this->schoolId)->where('rota_id',$r->id)->findOrFail($row->family_id);
                    $held=DutyRotaAssignment::where('masjid_id',$this->schoolId)->where('rota_id',$r->id)->where('family_id',$family->id)->whereNull('released_at')->pluck('duty_on')->all();
                    if ($row->kind==='pick' && ($family->withdrawn || count($held)>=$r->turns_owed)) {
                        $row->forceFill(['status'=>'dropped','failed_at'=>now(),'failure_reason'=>'Family no longer needs a day'])->save(); return;
                    }
                    $a=null;
                    if ($row->kind==='reminder') {
                        $a=DutyRotaAssignment::where('masjid_id',$this->schoolId)->where('rota_id',$r->id)->where('family_id',$family->id)->find($row->assignment_id);
                        if (! $a || $a->released_at || $a->duty_on < $calendar->today() || ! in_array($a->duty_on,$calendar->openDaysBetween($a->duty_on,$a->duty_on),true)) { $this->failed(new \RuntimeException('Assignment no longer due')); return; }
                    }
                    if (! in_array(config('app.env'),['local','testing'],true) && \App\Support\SchoolMailTransport::unsafe(Mail::mailer()->getSymfonyTransport())) {
                        $this->failed(new \RuntimeException('mail is not configured for delivery')); return;
                    }
                    // Releases need these same mutexes. Sending inside makes a release after handoff wait.
                    if (! DB::table('duty_rota_mails')->where('id',$row->id)->where('status','queued')->update(['status'=>'sending'])) return;
                    $data=app(DutyRotaMailService::class)->content($r,$family,$row->kind,$row->kind==='confirmation'?$this->held:$held,$a);
                    Mail::to($data['address'])->send(new DutyRotaMail($data['orgName'],$data['orgEmail'],$data['subject'],$data['body']));
                    $row->forceFill(['status'=>'sent','sent_at'=>now()])->save();
                    if ($row->kind==='pick') $family->forceFill(['link_sent_at'=>now()])->save();
                    if ($a) $a->forceFill(['reminder_sent_at'=>now()])->save();
                },1); // A transaction retry must never repeat an external send.
            } catch (Throwable $e) {
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode()===404) { $this->failed(new \RuntimeException('Rota stopped')); return; }
                $this->failed($e); throw $e;
            }
        });
    }
    public function failed(?Throwable $exception): void
    {
        $reason=match($exception?->getMessage()) {
            'Rota stopped','Assignment no longer due','mail is not configured for delivery'=>$exception->getMessage(),
            default=>'Delivery failed',
        };
        DB::table('duty_rota_mails')->where('masjid_id',$this->schoolId)->where('id',$this->mailId)->whereIn('status',['queued','sending'])->update(['status'=>'failed','failed_at'=>now(),'failure_reason'=>$reason]);
    }
}
