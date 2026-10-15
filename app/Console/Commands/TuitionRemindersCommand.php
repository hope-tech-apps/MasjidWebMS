<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Services\FamilyLedger\{FamilyLedger, TuitionReminders};
use App\Support\TenantContext;
use Illuminate\Console\Command;

class TuitionRemindersCommand extends Command
{
    protected $signature = 'tuition:remind';
    protected $description = 'Queue configured tuition reminders for confirmed family guardians';
    public function handle(): int
    {
        return app(TenantContext::class)->runWithout(function () {
            $count = 0; $failures = 0;
            foreach (Masjid::where('org_type', 'school')->get() as $school) {
                if (! FamilyLedger::enabled($school)) continue;
                try {
                    \App\Models\TuitionMail::where('masjid_id', $school->id)->where('status', 'sending')->where('attempted_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => \Illuminate\Queue\TimeoutExceededException::class]);
                    $count += app(TuitionReminders::class)->queue($school);
                }
                catch (\Throwable $e) { $failures++; \Illuminate\Support\Facades\Log::warning('Tuition reminder sweep failed.', ['school_id' => $school->id, 'exception_class' => $e::class]); }
            }
            $this->line("tuition:remind: {$count} emails queued; {$failures} schools failed.");
            return $failures ? self::FAILURE : self::SUCCESS;
        });
    }
}
