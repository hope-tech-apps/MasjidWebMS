<?php

declare(strict_types=1);

namespace App\Services\Schools;

use App\Models\{Contact, SchoolAdmissionMail, SchoolReenrolmentAnswer, SchoolReenrolmentDeposit};
use App\Support\{ContactIdentity, SchoolAdmissionsMode};
use Illuminate\Validation\ValidationException;

/** Called in the existing contact-merge transaction after its school lock, even when the grant is OFF. */
final class SchoolAdmissionsRecords
{
    public static function merge(Contact $source, Contact $target): void
    {
        if (! SchoolAdmissionsMode::recordsHeld((int) $source->masjid_id)) return;
        abort_unless((int) $source->masjid_id === (int) $target->masjid_id, 404);
        $answers = SchoolReenrolmentAnswer::where('student_contact_id', $source->id)->get();
        if (SchoolReenrolmentAnswer::where('student_contact_id', $target->id)->whereIn('reenrolment_id', $answers->pluck('reenrolment_id'))->exists()) {
            throw ValidationException::withMessages(['target_contact_id' => 'These pupil records both have re-enrolment records for the same year. Keep them separate so neither answer is lost.']);
        }
        SchoolReenrolmentAnswer::where('student_contact_id', $source->id)->update(['student_contact_id' => $target->id]);
        SchoolReenrolmentDeposit::where('student_contact_id', $source->id)->update(['student_contact_id' => $target->id]);
        // A different email must not inherit the old adult's act or a queued reminder grant.
        if (! ContactIdentity::changed($source, $target)) {
            SchoolReenrolmentAnswer::where('answered_by_contact_id', $source->id)->update(['answered_by_contact_id' => $target->id]);
            SchoolAdmissionMail::where('guardian_contact_id', $source->id)->update(['guardian_contact_id' => $target->id]);
        }
        foreach (SchoolAdmissionMail::where('kind', 'reminder')->get() as $mail) {
            $children = $mail->children ?? []; $changed = false;
            foreach ($children as &$child) if ((int) $child['student_contact_id'] === (int) $source->id) { $child['student_contact_id'] = (int) $target->id; $changed = true; }
            unset($child);
            if ($changed) $mail->update(['children' => $children]);
        }
    }
}
