<?php

namespace App\Console\Commands;

use App\Models\{Masjid, DutyRota, DutyRotaFamily, DutyRotaAssignment};
use App\Support\{DutyRotaMode, DutyRotaService, DutyRotaMailService, PointsReportSchedule, SchoolDateAuthority, TenantContext};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class SendDutyRotaReminders extends Command
{
    protected $signature='duties:remind';
    protected $description='Queue unsent duty reminders within the school-local lead window';
    public function handle(): int
    {
        return app(TenantContext::class)->runWithout(function () {
            $queued=0; $enabled=0;
            // One narrow read, nothing per school with the switch OFF (also before the new migration).
            $schools=Masjid::select(['id','org_type','capability_overrides','deleted_at'])->where('org_type','school')->get();
            foreach ($schools as $school) {
                if (! DutyRotaMode::enabled($school)) continue; $enabled++;
                $calendar=SchoolDateAuthority::for($school->id); $now=Date::now()->setTimezone($calendar->timezone()); $time=PointsReportSchedule::for($school)['time'];
                if ($now->format('H:i')<$time) continue;
                foreach (DutyRota::where('masjid_id',$school->id)->where('active',true)->orderBy('id')->get() as $rota) {
                    try {
                        app(DutyRotaService::class)->locked($rota,function($r) use(&$queued,$calendar) {
                            $data=app(DutyRotaService::class)->office($r); $last=\Carbon\CarbonImmutable::parse($calendar->today())->addDays($r->reminder_lead)->toDateString();
                            $assignments=DutyRotaAssignment::where('masjid_id',$r->masjid_id)->where('rota_id',$r->id)->whereNull('released_at')->whereNull('reminder_queued_at')->whereBetween('duty_on',[$calendar->today(),$last])->orderBy('id')->lockForUpdate()->get();
                            $families=DutyRotaFamily::where('masjid_id',$r->masjid_id)->where('rota_id',$r->id)->get()->keyBy('id');
                            foreach($assignments as $a) {
                                $f=$families[$a->family_id]; if (! $f->email) continue;
                                $link=app(DutyRotaService::class)->currentLink($r,$f);
                                $held=collect($data['families'])->firstWhere('id',$f->id)['held'];
                                if(app(DutyRotaMailService::class)->queue($r,$f,'reminder',$held,$link,$a)) $queued++;
                            }
                        });
                    } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('Duty rota reminder sweep failed',['rota'=>$rota->id,'exception'=>$e->getMessage()]); }
                }
            }
            if ($enabled) $this->line("duties:remind: {$queued} reminder(s) queued.");
            return self::SUCCESS;
        });
    }
}
