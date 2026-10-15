<?php

namespace App\Services\Student;

use App\Models\{Contact, StudentAccount};
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\{DB, Schema};

/** Child login erasure must also remove the separate tokenable's credentials. */
final class StudentCredentials
{
    public static function erase(Contact $contact): void
    {
        if (Schema::hasTable('student_accounts')) {
            $accounts=StudentAccount::withoutMasjidScope()->where('masjid_id',$contact->masjid_id)->where('contact_id',$contact->id)->lockForUpdate()->get();
            foreach($accounts as $account) {
                $account->delete();
            }
        }
        if (Schema::hasTable('student_sign_in_logs')) {
            DB::table('student_sign_in_logs')->where('masjid_id',$contact->masjid_id)->where('contact_id',$contact->id)->delete();
        }
    }

    public static function refuseAcademicMerge(Contact $contact): void
    {
        if (Schema::hasTable('student_hand_ins') && DB::table('student_hand_ins')->where('masjid_id',$contact->masjid_id)->where('student_contact_id',$contact->id)->exists()) {
            throw new HttpResponseException(response()->api(422,'This student has hand-ins kept as academic records. Keep this contact so their work and its history remain traceable.'));
        }
    }
}
