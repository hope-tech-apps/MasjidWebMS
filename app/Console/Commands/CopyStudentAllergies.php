<?php

namespace App\Console\Commands;

use App\Models\{Form, Masjid};
use App\Support\{StudentAllergies, TenantContext};
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CopyStudentAllergies extends Command
{
    protected $signature = 'students:copy-allergies {--masjid=} {--form=} {--field=} {--dry-run}';
    protected $description = 'Preview or copy child allergy answers into matched school student records';

    public function handle(StudentAllergies $service, TenantContext $tenant): int
    {
        $school = Masjid::find($this->option('masjid'));
        if (! StudentAllergies::enabled($school)) { $this->error('Student allergies is not switched on for this school.'); return self::FAILURE; }
        $form = Form::where('masjid_id', $school->id)->find($this->option('form'));
        if (! $form || ! $this->option('field')) { $this->error('Choose a form in this school and a child field.'); return self::FAILURE; }
        $previous = $tenant->get();
        $tenant->set($school->id);
        try {
            $result = $service->copy($form, $this->option('field'), (bool) $this->option('dry-run'), null);
            $this->table(['Count', 'Total'], collect($result['counts'])->map(fn ($value, $key) => [$key === 'blank_answer' ? 'left unchanged: blank answer' : $key, $value])->values()->all());
            // No notes, names or guardian details in command output (often retained as logs).
            $unresolved = array_filter($result['children'], fn ($row) => in_array($row['outcome'], ['unmatched', 'ambiguous', 'too_long', 'too long: enter by hand', 'invalid'], true));
            $this->table(['Response', 'Child entry (zero-based)', 'Outcome'], array_map(fn ($row) => [$row['response_id'], $row['entry_index'], $row['outcome']], $unresolved));
            $this->info($result['message']);
            return self::SUCCESS;
        } catch (ValidationException) {
            $this->error('Choose a child field on a form with first and last names.');
            return self::FAILURE;
        } finally {
            $previous === null ? $tenant->forgetTenant() : $tenant->set($previous);
        }
    }
}
