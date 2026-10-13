<?php

namespace App\Support;

use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The two areas a login reaches through a role an office gave it at ONE organisation:
 * the teacher area (routes/teacher.php) and the lunch area (routes/lunch.php).
 *
 * One person, one address, one password, can be lunch staff at one organisation and a
 * teacher at another. `users.type` cannot say that (it is one value for everything),
 * so for these two kinds the `masjid_user` row says it: a row whose role is `teacher`
 * or `lunch-staff` is what its login IS at that organisation (MasjidUser::kindFor()).
 *
 * This class is the gate half of that rule, and the only place the switch is read:
 *
 *   - admits() is the whole decision of TeacherMiddleware and LunchStaffMiddleware.
 *     A login whose own kind is the area's kind is admitted exactly as before, with no
 *     query. A login of the OTHER scoped kind is admitted only while the switch is on
 *     and only if it holds a live row of this area's kind somewhere; the gate then
 *     records the kind the request is acting as (User::actAs()), and every later read
 *     of `$user->type` in that request answers with it.
 *   - Which ORGANISATION the request may act on is not decided here. `tenant`
 *     (ResolveMasjidTenant, TenantResolver) binds the organisation in the URL only if
 *     the login's row THERE is of the kind being acted as, switch on or off.
 *
 * An administrator, a SuperAdmin and a member are never admitted by a row: the second
 * path is for the two scoped kinds only. One global role per login (spatie teams are
 * off) is why: an administrator made a teacher at another school would carry
 * `manage contacts` into its classes.
 */
final class StaffArea
{
    /** The API prefix of each area, and the kind it admits. */
    public const KIND_FOR_AREA = [
        'teacher' => 'Teacher',
        'lunch' => User::TYPE_LUNCH_STAFF,
    ];

    /**
     * Whether a login may hold a different role at each organisation.
     *
     * Off (the shipped default): no login is admitted to an area outside its own kind,
     * the office doors refuse the cross-kind attach with the sentences they always
     * had, sign-in never lands outside the login's own kind and no payload carries
     * `areas`. It does NOT switch off the resolver's kind filter: a row of the other
     * kind must never bind in the home area, in either state.
     */
    public static function open(): bool
    {
        return (bool) config('tenancy.roles_per_organisation', false);
    }

    /**
     * May this login enter the area that admits $kind? Sets what it is acting as.
     *
     * It starts by clearing the acting kind. In production the guard builds a fresh
     * model for every request, so there is nothing to clear; `Sanctum::actingAs()`
     * hands one instance to every request of a test.
     */
    public static function admits(User $user, string $kind): bool
    {
        $user->actAs(null);

        $home = $user->homeType();

        if ($home === $kind) {
            return true;
        }

        if (! self::open() || ! in_array($home, OrganisationAccess::SCOPED_TYPES, true)) {
            return false;
        }

        // Read and compared here, not with a SQL test on `role`: MySQL would match
        // `teacher ` or `Teacher` where the resolver's kindFor() does not, and the
        // gate must not admit to an area that then binds nowhere for a reason nobody
        // can see.
        $holds = self::liveRows($user)->contains(
            fn (MasjidUser $row): bool => $row->kindFor($home) === $kind
        );

        if (! $holds) {
            return false;
        }

        $user->actAs($kind);

        return true;
    }

    /**
     * The login's rows at organisations that still exist, lowest organisation first.
     *
     * The same read, and the same soft-delete guard, as TenantResolver's: a row at an
     * archived organisation gives no area, exactly as it binds no tenant.
     *
     * @return Collection<int, MasjidUser>
     */
    public static function liveRows(User $user): Collection
    {
        return $user->memberships()
            ->whereHas('masjid')
            ->orderBy('masjid_id')
            ->get();
    }

    /** Whether the login's live rows give it more than one kind. */
    public static function isMixed(User $user): bool
    {
        $home = $user->homeType();

        return in_array($home, OrganisationAccess::SCOPED_TYPES, true)
            && self::liveRows($user)
                ->map(fn (MasjidUser $row): ?string => $row->kindFor($home))
                ->unique()
                ->count() > 1;
    }

    /**
     * The `areas[]` of a login that holds roles of more than one kind, or null.
     *
     * One entry per live row: which area it opens (the API prefix) and at which
     * organisation. Null for every login with one kind of role, for every login that
     * is not of a scoped kind, and always while the switch is off: the payload key is
     * then ABSENT, so a one-role login's serialized user is what it always was.
     *
     * @return list<array{area: string, masjid_id: int, masjid: array{id: int, name: string, org_type: ?string}}>|null
     */
    public static function areasFor(User $user): ?array
    {
        $home = $user->homeType();

        if (! self::open() || ! in_array($home, OrganisationAccess::SCOPED_TYPES, true)) {
            return null;
        }

        $rows = self::liveRows($user);

        if ($rows->map(fn (MasjidUser $row): ?string => $row->kindFor($home))->unique()->count() < 2) {
            return null;
        }

        // Named only now, so a login that turns out to hold one kind costs one read.
        $rows->load('masjid');

        return $rows
            ->filter(fn (MasjidUser $row): bool => $row->masjid !== null)
            ->map(fn (MasjidUser $row): array => [
                'area' => (string) array_search($row->kindFor($home), self::KIND_FOR_AREA, true),
                'masjid_id' => (int) $row->masjid_id,
                'masjid' => [
                    'id' => (int) $row->masjid->id,
                    'name' => $row->masjid->name,
                    'org_type' => $row->masjid->org_type,
                ],
            ])
            ->values()
            ->all();
    }
}
