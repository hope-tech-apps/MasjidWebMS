<?php

namespace App\Services\Student;

use App\Models\{Contact, GroupMembership, Masjid, StudentSignInSetting};
use App\Support\{GradeLevel, SchoolDateAuthority};
use Illuminate\Support\Facades\DB;

final class StudentAccess
{
    public static function enabled(?Masjid $school): bool
    {
        return $school !== null && $school->deleted_at === null && $school->isSchool() && $school->hasCapability('student_sign_in');
    }

    public static function memberships(int $school, int $contact)
    {
        $today = SchoolDateAuthority::for($school)->today();
        return GroupMembership::where('masjid_id',$school)->where('contact_id',$contact)->where('role','member')
            ->where(fn ($q) => $q->whereNull('left_on')->orWhereDate('left_on','>',$today))
            ->whereDate(DB::raw("COALESCE(joined_at, CASE WHEN moved_from_group_id IS NOT NULL THEN COALESCE(moved_on, created_at) ELSE created_at END)"),'<=',$today)
            ->whereHas('group', fn ($q) => $q->where('masjid_id',$school)->where('kind','class')->where('is_active',true));
    }

    public static function fromGrade(int $school): string
    {
        return StudentSignInSetting::where('masjid_id',$school)->value('from_grade') ?? '6th';
    }

    public static function eligible(int $school, int $contact): bool
    {
        if (! Contact::where('masjid_id',$school)->whereKey($contact)->exists()) return false;
        $rank = fn ($label) => array_search(GradeLevel::key($label), array_map([GradeLevel::class,'key'],GradeLevel::LEVELS),true);
        $from = $rank(self::fromGrade($school));
        $grades = self::memberships($school,$contact)->pluck('grade_label');
        return $from !== false && $grades->isNotEmpty() && $grades->every(fn ($g) => ($r=$rank($g)) !== false && $r >= $from);
    }

    public static function refusal(int $school): string
    {
        $key=GradeLevel::key(self::fromGrade($school));
        $label=ctype_digit((string)$key) ? 'Grade '.$key : self::fromGrade($school);
        return 'Students below '.$label.' cannot sign in.';
    }
}
