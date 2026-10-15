<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Services\FamilyLedger\{FamilyLedger, TuitionCharges};
use App\Support\TenantContext;
use Illuminate\Console\Command;

class TuitionChargesCommand extends Command
{
    protected $signature = 'tuition:charges';
    protected $description = 'Post today\'s school-local tuition charges, never collect payments';
    public function handle(): int
    {
        return app(TenantContext::class)->runWithout(function () {
            $count = 0; $failures = 0;
            foreach (Masjid::where('org_type', 'school')->get() as $school) {
                if (! FamilyLedger::enabled($school)) continue;
                try { $count += app(TuitionCharges::class)->post($school); }
                catch (\Throwable $e) { $failures++; \Illuminate\Support\Facades\Log::warning('Tuition charge sweep failed.', ['school_id' => $school->id, 'exception_class' => $e::class]); }
            }
            $this->line("tuition:charges: {$count} charges posted; {$failures} schools failed.");
            return $failures ? self::FAILURE : self::SUCCESS;
        });
    }
}
