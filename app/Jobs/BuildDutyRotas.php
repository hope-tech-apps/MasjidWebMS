<?php

namespace App\Jobs;

use App\Models\{DutyRota, Masjid};
use App\Support\{DutyRotaMode, DutyRotaService, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildDutyRotas implements ShouldQueue
{
    use Queueable;
    public function __construct(public int $schoolId, public int $formId) {}
    public function handle(): void
    {
        app(TenantContext::class)->runWithout(function () {
            if (! DutyRotaMode::enabled(Masjid::find($this->schoolId))) return;
            foreach (DutyRota::where('masjid_id',$this->schoolId)->where('source_form_id',$this->formId)->where('active',true)->orderBy('id')->get() as $rota) app(DutyRotaService::class)->build($rota);
        });
    }
}
