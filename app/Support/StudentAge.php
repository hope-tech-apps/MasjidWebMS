<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A student's AGE, worked out on read from the date of birth on their contact.
 *
 * The age is never stored: a stored age is wrong within a year. And it is not an
 * accessor on Contact, which would publish it wherever a contact is serialised.
 * A caller that means to show an age asks here, and passes the SCHOOL's today
 * (`SchoolCalendar::for($masjidId)->today()`, read once per request), because a
 * birthday turns over at the school's midnight, not the server's.
 *
 *  - `forRoster()`  the whole numbers for one roster, for students in a class
 *                   only. The office roster and the teacher's class.
 *  - `of()`         one contact's whole number.
 *  - `fromDate()`   the arithmetic alone, for a caller that already holds the day.
 *
 * The date itself is read in ONE place, `Contact::dateOfBirthOrNull()`, which
 * turns a value that cannot be decrypted into "no date on file" plus one ERROR
 * line. So one bad row never breaks a roster and is never silent.
 */
final class StudentAge
{
    public const TABLE = 'contacts';
    public const COLUMN = 'date_of_birth';

    private const RECHECK_SECONDS = 30;

    /** @var array{0: bool, 1: int}|null [exists, when it was asked] */
    private static ?array $seen = null;

    /**
     * Whole years old on `$todayYmd` ('Y-m-d', the school's today), or null.
     *
     * Null for: no contact, no date on file, a column that was not selected, a
     * date in the future, and a value that cannot be read
     * (Contact::dateOfBirthOrNull()).
     */
    public static function of(?Contact $contact, string $todayYmd): ?int
    {
        return self::fromDate($contact?->dateOfBirthOrNull(), $todayYmd);
    }

    /**
     * The arithmetic: whole years between two 'Y-m-d' days, or null when either
     * is not a real day or the birth day is after today.
     *
     * A child born on 29 February turns a year older on 1 March in a year that
     * has no 29th.
     */
    public static function fromDate(?string $bornYmd, string $todayYmd): ?int
    {
        $born = $bornYmd === null ? null : self::parts($bornYmd);
        $today = self::parts($todayYmd);

        if ($born === null || $today === null) {
            return null;
        }

        // Canonical 'Y-m-d' strings order the same way the days do.
        if ($bornYmd > $todayYmd) {
            return null;
        }

        [$bornYear, $bornMonth, $bornDay] = $born;
        [$year, $month, $day] = $today;

        $hadBirthdayThisYear = $month > $bornMonth || ($month === $bornMonth && $day >= $bornDay);

        return $year - $bornYear - ($hadBirthdayThisYear ? 0 : 1);
    }

    /**
     * The ages on one roster: `membership id => whole years`, for STUDENTS IN A
     * CLASS and for nobody else.
     *
     * "A student in a class" is role `member` in a group that
     * `teachesStudents()`. A guardian entry, a legacy `leader` row and a member
     * of a ḥalaqa, a team or a general group are simply absent from the answer,
     * and the caller reads absent as null. That matters because a contact can
     * carry a date from a class and also sit on another kind of roster, where
     * no age is to be shown.
     *
     * The dates are read HERE, in one query of their own that names only the
     * students, rather than by widening the roster's own contact columns. So
     * the contacts a roster payload serialises never hold the value at all, and
     * for anything that is not a class the column is not read.
     *
     * `$todayYmd` is the school's today; leave it out and it is read from the
     * school's calendar, once.
     *
     * @param  iterable<GroupMembership>  $memberships
     * @return array<int, int|null>
     */
    public static function forRoster(Group $group, iterable $memberships, ?string $todayYmd = null): array
    {
        if (! $group->teachesStudents() || ! self::columnExists()) {
            return [];
        }

        $students = collect($memberships)
            ->filter(fn (GroupMembership $m): bool => $m->role === GroupMembership::ROLE_MEMBER);

        if ($students->isEmpty()) {
            return [];
        }

        $contacts = Contact::query()
            ->whereKey($students->pluck('contact_id')->unique()->values())
            ->get(['id', 'masjid_id', self::COLUMN])
            ->keyBy('id');

        $todayYmd ??= SchoolCalendar::for((int) $group->masjid_id)->today();

        return $students
            ->mapWithKeys(fn (GroupMembership $m): array => [
                (int) $m->getKey() => self::of($contacts->get($m->contact_id), $todayYmd),
            ])
            ->all();
    }

    /**
     * Whether `migrate` has added the column yet.
     *
     * bin/deploy makes the new code live BEFORE it runs `php artisan migrate`.
     * For that window a roster read that selected the column would answer 500
     * for every class in the school. So the read sites select it, and the
     * birth-date routes write it, only once it is there; until then a roster
     * shows no ages, exactly as it did before this existed.
     *
     * Memoised per process as App\Support\FormAnswersText is: a column that
     * exists is remembered for good, one that is missing is asked again after
     * RECHECK_SECONDS, and a question that could not be answered is "not yet"
     * for that call only.
     *
     * `$fresh` asks the database now, whatever was remembered. For the two
     * callers where a stale "not yet" would LOSE something rather than merely
     * hide an age for half a minute: clearing a date (which must not answer
     * "removed" having removed nothing) and a merge (which must not destroy the
     * absorbed record's date unread).
     */
    public static function columnExists(bool $fresh = false): bool
    {
        $now = now()->getTimestamp();

        if (! $fresh && self::$seen !== null && (self::$seen[0] || $now - self::$seen[1] < self::RECHECK_SECONDS)) {
            return self::$seen[0];
        }

        try {
            $exists = Schema::hasColumn(self::TABLE, self::COLUMN);
        } catch (Throwable $e) {
            if ($fresh) {
                throw $e;
            }

            return false;
        }

        self::$seen = [$exists, $now];

        return $exists;
    }

    /** Forget what was seen: for a test that drops or adds the column, and for nothing else. */
    public static function forget(): void
    {
        self::$seen = null;
    }

    /** @return array{0:int,1:int,2:int}|null year, month, day of a real 'Y-m-d' day */
    private static function parts(string $ymd): ?array
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $ymd, $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return checkdate($month, $day, $year) ? [$year, $month, $day] : null;
    }
}
