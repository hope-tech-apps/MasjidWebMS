<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A student's AGE, worked out on read from the date of birth on their contact,
 * or, when there is none, from the age their family gave.
 *
 * TWO SOURCES, ONE ORDER (`shown()`):
 *
 *  1. the DATE OF BIRTH the office typed. Exact, and it always wins;
 *  2. the AGE THE FAMILY GAVE, with the day they gave it (a registration form
 *     that asks "how old is your child?"). The whole years since that day are
 *     added, so it is not a year out of date by the next autumn. It can still
 *     be one short: a child who was 6 in September and had a birthday in
 *     October is shown as 6 until the following September. So the office's
 *     roster is told which rows came from it (`given`), and says so.
 *
 * The age is never stored: a stored age is wrong within a year. And it is not an
 * accessor on Contact, which would publish it wherever a contact is serialised.
 * A caller that means to show an age asks here, and passes the SCHOOL's today
 * (`SchoolCalendar::for($masjidId)->today()`, read once per request), because a
 * birthday turns over at the school's midnight, not the server's.
 *
 *  - `forRoster()`      the whole numbers for one roster, for students in a
 *                       class only. The teacher's class.
 *  - `forRosterShown()` the same, with whether each came from the age a family
 *                       gave. The office roster.
 *  - `shown()`          one contact's whole number and where it came from.
 *  - `of()`             one contact's whole number from the date of birth alone.
 *  - `fromDate()`       the arithmetic alone, for a caller that already holds
 *                       the day.
 *
 * The date itself is read in ONE place, `Contact::dateOfBirthOrNull()`, which
 * turns a value that cannot be decrypted into "no date on file" plus one ERROR
 * line. So one bad row never breaks a roster and is never silent.
 */
final class StudentAge
{
    public const TABLE = 'contacts';
    public const COLUMN = 'date_of_birth';
    public const GIVEN_COLUMN = 'age_given';

    private const RECHECK_SECONDS = 30;

    /** @var array<string, array{0: bool, 1: int}> column => [exists, when it was asked] */
    private static array $seen = [];

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
     * The age to SHOW for one contact, and where it came from:
     * `['age' => whole years or null, 'given' => bool]`.
     *
     * `given` is true only when the number comes from the age the family gave
     * (no readable date of birth on file). A caller that may not read that
     * column yet passes `$withGiven: false` (givenColumnExists()).
     *
     * An age given on a day that is, on the school's clock, still tomorrow is
     * shown as it was given: a registration taken late in the evening must not
     * blank the age until the school's midnight.
     *
     * @return array{age: int|null, given: bool}
     */
    public static function shown(?Contact $contact, string $todayYmd, bool $withGiven = true): array
    {
        $age = self::of($contact, $todayYmd);

        if ($age !== null) {
            return ['age' => $age, 'given' => false];
        }

        $fromGiven = $withGiven ? self::fromGiven($contact?->ageGivenOrNull(), $todayYmd) : null;

        return ['age' => $fromGiven, 'given' => $fromGiven !== null];
    }

    /**
     * The age a family gave, brought up to `$todayYmd`: the age as given plus
     * the whole years since the day they gave it. Null when there is none, or
     * when today is not a real day.
     *
     * @param  array{age: int, on: string}|null  $given  Contact::ageGivenOrNull()
     */
    public static function fromGiven(?array $given, string $todayYmd): ?int
    {
        if ($given === null || self::parts($todayYmd) === null) {
            return null;
        }

        return $given['age'] + (self::fromDate($given['on'], $todayYmd) ?? 0);
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
        return array_map(
            fn (array $shown): ?int => $shown['age'],
            self::forRosterShown($group, $memberships, $todayYmd),
        );
    }

    /**
     * forRoster(), with where each age came from:
     * `membership id => ['age' => whole years or null, 'given' => bool]`.
     *
     * For the office roster, which says which ages are the ones families gave.
     * The same students, the same single query of its own and the same today;
     * the age a family gave is read in that query too, when its column is
     * there, and is used only for a student with no readable date of birth.
     *
     * @param  iterable<GroupMembership>  $memberships
     * @return array<int, array{age: int|null, given: bool}>
     */
    public static function forRosterShown(Group $group, iterable $memberships, ?string $todayYmd = null): array
    {
        if (! $group->teachesStudents() || ! self::columnExists()) {
            return [];
        }

        $students = collect($memberships)
            ->filter(fn (GroupMembership $m): bool => $m->role === GroupMembership::ROLE_MEMBER);

        if ($students->isEmpty()) {
            return [];
        }

        $withGiven = self::givenColumnExists();

        $contacts = Contact::query()
            ->whereKey($students->pluck('contact_id')->unique()->values())
            ->get(array_merge(['id', 'masjid_id', self::COLUMN], $withGiven ? [self::GIVEN_COLUMN] : []))
            ->keyBy('id');

        $todayYmd ??= SchoolCalendar::for((int) $group->masjid_id)->today();

        return $students
            ->mapWithKeys(fn (GroupMembership $m): array => [
                (int) $m->getKey() => self::shown($contacts->get($m->contact_id), $todayYmd, $withGiven),
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
        return self::has(self::COLUMN, $fresh);
    }

    /**
     * The same question, with the same memory and the same `$fresh`, for the
     * column that holds the age a family gave. It arrives in a later migration
     * than the date of birth, so each is asked about on its own: a roster read
     * between that deploy's checkout and its migrate shows the ages from dates
     * of birth and reads nothing else.
     */
    public static function givenColumnExists(bool $fresh = false): bool
    {
        return self::has(self::GIVEN_COLUMN, $fresh);
    }

    private static function has(string $column, bool $fresh): bool
    {
        $now = now()->getTimestamp();
        $seen = self::$seen[$column] ?? null;

        if (! $fresh && $seen !== null && ($seen[0] || $now - $seen[1] < self::RECHECK_SECONDS)) {
            return $seen[0];
        }

        try {
            $exists = Schema::hasColumn(self::TABLE, $column);
        } catch (Throwable $e) {
            if ($fresh) {
                throw $e;
            }

            return false;
        }

        self::$seen[$column] = [$exists, $now];

        return $exists;
    }

    /** Forget what was seen: for a test that drops or adds a column, and for nothing else. */
    public static function forget(): void
    {
        self::$seen = [];
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
