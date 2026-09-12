<?php

namespace App\Support;

use App\Models\ArabicLetterProgress;
use App\Models\AssignmentScore;
use App\Models\AttendanceRecord;
use App\Models\BehaviorAward;
use App\Models\GroupMembership;
use App\Models\HifzEntry;
use App\Models\ReportCard;

/**
 * WHAT A CHILD'S ROSTER ROW IS HOLDING — one definition, for every caller that
 * might delete one.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT A PRIVATE METHOD
 * ---------------------------------------------------------------------------
 *
 * Six tables hang off `group_memberships.id`: the register, assignment scores,
 * report cards, ḥifẓ entries, behaviour awards and Arabic letter progress. Until
 * 2026_09_09_040000 those foreign keys were ON DELETE CASCADE, so any verb that
 * removed a roster row also destroyed a term of a child's academic history —
 * reproduced on a seeded child: one attendance row and one report card, one
 * delete, both gone. That migration made the six keys RESTRICT, and
 * `GroupMembershipsController::destroy()` grew a check that counts them first so
 * the office gets a sentence naming what is in the way rather than a constraint
 * violation.
 *
 * The migration returns early on SQLite (it cannot rebuild those tables safely),
 * so the CI suite runs against the ORIGINAL cascading keys. That is the whole
 * reason this list must exist exactly once: on the CI engine the database will
 * happily delete the history, so a second caller that forgot one of the six
 * tables — or that never checked at all — would pass every test and quietly
 * erase a child's marks on production MySQL, or die on a 1451 there. A private
 * method on one controller is an invitation to that second, forgetful copy.
 *
 * The counts are LABELLED because they are read out to a human. "42 register
 * marks, 2 report cards" is a sentence an office can act on; a boolean is not.
 */
final class AcademicRecordsHeld
{
    /**
     * How many academic records point at this roster row, by what they are.
     *
     * @return array<string, int>
     */
    public static function counts(GroupMembership $membership): array
    {
        $id = $membership->id;

        return [
            'register marks' => AttendanceRecord::where('group_membership_id', $id)->count(),
            'marks' => AssignmentScore::where('group_membership_id', $id)->count(),
            'report cards' => ReportCard::where('group_membership_id', $id)->count(),
            'ḥifẓ entries' => HifzEntry::where('group_membership_id', $id)->count(),
            'behaviour points' => BehaviorAward::where('group_membership_id', $id)->count(),
            'letter progress' => ArabicLetterProgress::where('group_membership_id', $id)->count(),
        ];
    }

    /** Whether this row holds any history at all. */
    public static function any(array $held): bool
    {
        return array_sum($held) > 0;
    }

    /**
     * The held records as a phrase, naming only the kinds that are actually
     * there: "42 register marks, 2 report cards".
     */
    public static function describe(array $held): string
    {
        $parts = [];

        foreach ($held as $label => $n) {
            if ($n > 0) {
                $parts[] = $n . ' ' . $label;
            }
        }

        return implode(', ', $parts);
    }
}
