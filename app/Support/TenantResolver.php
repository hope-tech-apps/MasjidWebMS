<?php

namespace App\Support;

use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decides which masjid an authenticated admin's request acts on, from the
 * `masjid_user` membership pivot. S3 of docs/multi-tenant-admin-design.md.
 *
 * ------------------------------------------------------------------------------
 * It fails CLOSED, and that is the entire specification
 * ------------------------------------------------------------------------------
 *
 * MySQL has no row-level security (.claude/rules/tenant-scoping.md), so the
 * bound tenant IS the isolation boundary — and an UNBOUND context adds no
 * filter at all. A resolver that guesses therefore does not degrade to "a bit
 * wrong"; it degrades to one organisation reading another's, or to every
 * organisation at once. Every branch below that cannot name exactly one
 * verified masjid returns `denied` rather than a default, a first row, or an
 * absence.
 *
 * Specifically, NONE of these bind: a user with no membership; a membership
 * whose masjid has been trashed; a `{masjid_id}` the user holds no membership
 * for (even when they hold one somewhere else); several memberships with
 * nothing in the route to choose between them; a masjid_id that arrived in the
 * body, the query string or a header rather than in the route.
 *
 * ------------------------------------------------------------------------------
 * The one-membership gate (config/tenancy.php — S5 lifts it)
 * ------------------------------------------------------------------------------
 *
 * Every admin in production today holds EXACTLY ONE membership, and it names
 * the masjid they own: S0 made `masjids.user_id` unique among live rows and
 * S2's backfill inserted one default row per live owned masjid. While
 * `tenancy.multi_membership` is false, this resolver deliberately looks only at
 * that one grant (soleOwnedMembership() below), so its verdict is identical to
 * the pre-S3 middleware for every user who can exist in production. The
 * multi-membership path is written and tested, but it is unreachable until the
 * flag flips — because a second tenant a user can hold and never leave is worse
 * than no second tenant, and leaving it needs S4's API surface and S5's
 * switcher.
 */
final class TenantResolver
{
    /**
     * Admin routes that are genuinely not about ONE masjid, and are therefore
     * the only ones a multi-tenant admin may reach with the context left
     * unbound: their own account surface plus the organisation list the S5
     * switcher will be built from.
     *
     * MULTI-MEMBERSHIP PATH ONLY — a single-membership admin binds on these
     * routes exactly as they did before S3, so this list changes nothing today.
     * It is deliberately an allowlist of literals rather than "any route
     * without {masjid_id}": the design's alternative was to default-bind, which
     * would silently mis-scope EnsureCrmEnabled and turn ImpactMetrics::
     * withTenant()'s guard into a 500.
     */
    private const UNSCOPED_ADMIN_ROUTES = [
        'api/admin/user',
        'api/admin/logout',
        'api/admin/profile',
        'api/admin/2fa/*',
        'api/admin/masjids',
        'api/admin/masjids/timezones',
        'api/admin/search',
    ];

    /**
     * @param  User  $user  the authenticated staff principal
     * @param  int|null  $routeMasjidId  the ROUTE's {masjid_id} — never client input
     * @param  string|null  $path  request path, consulted only on the gated multi-membership branch
     */
    public function resolve(User $user, ?int $routeMasjidId, ?string $path = null): TenantResolution
    {
        $grants = $this->grantsFor($user);

        // FAIL CLOSED — no verified grant at all. Before S3 this user fell
        // through to an UNBOUND context on any route that named no masjid,
        // which is "see every masjid's rows". Unreachable in production: an
        // admin with no live owned masjid has no membership either, and every
        // production admin has exactly one of each.
        if ($grants->isEmpty()) {
            return TenantResolution::denied('no verified membership');
        }

        if ($routeMasjidId !== null) {
            $match = $grants->first(
                fn (MasjidUser $grant): bool => (int) $grant->masjid_id === $routeMasjidId
            );

            // FAIL CLOSED — the route names a masjid this user holds no
            // verified grant for. Holding a grant SOMEWHERE ELSE does not
            // soften this, and neither does holding a default: the id in the
            // URL is answered yes or no, never substituted.
            return $match === null
                ? TenantResolution::denied('route masjid is outside this user\'s verified memberships')
                : TenantResolution::bind($match, 'route');
        }

        // The route names no masjid and there is exactly one grant, so there is
        // nothing to choose: bind it. With the gate closed this is the ONLY
        // branch a production admin ever reaches, and the grant it binds is the
        // masjid they own — byte-identical to the pre-S3 middleware.
        if ($grants->count() === 1) {
            return TenantResolution::bind($grants->first(), 'sole membership');
        }

        // Several grants and nothing in the route to choose between them.
        // Reachable only with the gate OPEN (S5).
        return $this->isUnscopedAdminRoute($path)
            // Not a tenant-scoped route at all (own account, org list) — unbound
            // is the honest answer, and these endpoints touch no scoped model.
            ? TenantResolution::unbound('several memberships on a route that is not about one masjid')
            // FAIL CLOSED — ambiguous. Picking the default, or the first row,
            // is precisely the silent mis-scoping this slice exists to remove.
            : TenantResolution::denied('several memberships and no masjid in the route');
    }

    /**
     * The verified grants this user may act through — and the one place the
     * one-membership gate is read.
     *
     * @return Collection<int, MasjidUser>
     */
    private function grantsFor(User $user): Collection
    {
        if ($this->multiMembershipEnabled()) {
            return $this->everyLiveMembership($user);
        }

        $owned = $this->soleOwnedMembership($user);

        // A non-owner staff principal — a Teacher — owns no masjid, so the
        // ownership expression above is empty. Their grant is instead their
        // persisted `masjid_user` membership(s) naming a LIVE masjid, written at
        // invite time. This does NOT open the multi-membership path: a teacher
        // with two live memberships still returns two grants here, which
        // resolve() then refuses as ambiguous exactly as it would for an owner.
        // It only lets a single-school teacher (every teacher today) bind.
        return $owned->isNotEmpty() ? $owned : $this->staffMemberships($user);
    }

    /**
     * The live-masjid memberships of a staff principal who owns no masjid.
     *
     * Same shape and soft-delete guard as everyLiveMembership(), but reached on
     * the GATED (single-membership) path for a non-owner. Returning 0 or >1 is
     * deliberate — it lets resolve() fail closed on "no school" and "ambiguous
     * school" without a teacher-specific branch there.
     *
     * @return Collection<int, MasjidUser>
     */
    private function staffMemberships(User $user): Collection
    {
        return $user->memberships()
            ->whereHas('masjid')
            ->orderBy('masjid_id')
            ->get();
    }

    /**
     * S5's grant set: every membership naming a masjid that still exists.
     *
     * `whereHas('masjid')` is load-bearing rather than decorative — Masjid uses
     * SoftDeletes, so the subquery drops memberships whose organisation has
     * been trashed. S2 keeps those rows on purpose (restoring a masjid must
     * bring its admins back), which means the resolver, not the schema, is what
     * has to refuse them. The ordering only makes the collection deterministic
     * for tests and logs; nothing here picks "the first" one.
     *
     * @return Collection<int, MasjidUser>
     */
    private function everyLiveMembership(User $user): Collection
    {
        return $user->memberships()
            ->whereHas('masjid')
            ->orderBy('masjid_id')
            ->get();
    }

    /**
     * THE GATE. Production's grant set: the single organisation this user owns.
     *
     * S5 deletes this method and always calls everyLiveMembership(); nothing
     * else about the resolver changes. Until then it is what makes S3
     * behaviour-neutral, in two steps:
     *
     *   1. The masjid is resolved from `masjids.user_id` using the EXACT
     *      expression the pre-S3 middleware used, so a request binds what it
     *      bound yesterday. It is a trustworthy authority, not a leftover: S0
     *      made it unique among live rows in the database, and `User::masjid()`
     *      is soft-delete scoped, so a trashed organisation resolves to null and
     *      grants nothing.
     *   2. The membership row for that masjid supplies the provenance. When it
     *      is missing the grant is derived from the ownership itself rather than
     *      refused — see membershipFromOwnership().
     *
     * Any OTHER membership the user holds is skipped here. That is the whole
     * gate: the rows exist, they are simply not grants yet.
     *
     * @return Collection<int, MasjidUser>
     */
    private function soleOwnedMembership(User $user): Collection
    {
        $ownedMasjidId = $this->ownedMasjidId($user);

        if ($ownedMasjidId === null) {
            return collect();
        }

        $membership = $user->memberships()
            ->where('masjid_id', $ownedMasjidId)
            ->first();

        return collect([$membership ?? $this->membershipFromOwnership($user, $ownedMasjidId)]);
    }

    /**
     * The masjid this user owns, resolved exactly as the pre-S3 middleware did.
     *
     * Kept verbatim (`masjid_id` attribute first, then the `hasOne`) rather than
     * simplified: `users.masjid_id` was dropped in 2025 and the attribute is
     * always null today, but reproducing the expression is what makes "same
     * binding as before" a fact rather than a claim.
     */
    private function ownedMasjidId(User $user): ?int
    {
        $id = $user->masjid_id ?? $user->masjid?->id;

        return $id === null ? null : (int) $id;
    }

    /**
     * An unsaved MasjidUser standing for "this user owns this masjid".
     *
     * Required for behaviour parity, and it widens nothing. S2's backfill wrote
     * one membership per live owned masjid AT MIGRATION TIME; no code writes one
     * since (provisioning gains that in S4), and no test fixture creates one. So
     * requiring a persisted row before binding would 403 every admin whose
     * masjid was created after the backfill, plus every existing test — a
     * catastrophic behaviour change dressed up as strictness. The grant this
     * returns is the same server-side, DB-enforced fact the middleware already
     * ran on (`masjids.user_id`, unique among live rows); it can only ever name
     * the masjid the user genuinely owns.
     *
     * It is deliberately NOT saved. Writing membership rows from a read path
     * would backfill authorization data during a GET, and the one-default-per-
     * user unique index makes that a request-time constraint violation waiting
     * to happen. S4 owns the write.
     *
     * When the gate opens this fallback disappears with the rest of
     * soleOwnedMembership(), so S5 must ensure every admin has a real row first.
     */
    private function membershipFromOwnership(User $user, int $masjidId): MasjidUser
    {
        return new MasjidUser([
            'masjid_id' => $masjidId,
            'user_id' => $user->getKey(),
            // Advisory only, and identical to what the backfill wrote — the
            // pivot role mirrors the global bridged role and authorizes nothing
            // on its own (.claude/rules/tenant-scoping.md).
            'role' => User::TYPE_ROLE_MAP[$user->type] ?? null,
            'is_default' => true,
        ]);
    }

    private function isUnscopedAdminRoute(?string $path): bool
    {
        return $path !== null && Str::is(self::UNSCOPED_ADMIN_ROUTES, trim($path, '/'));
    }

    /**
     * False in every shipped configuration. See config/tenancy.php for what
     * flipping it costs and what has to land first.
     */
    private function multiMembershipEnabled(): bool
    {
        return (bool) config('tenancy.multi_membership', false);
    }
}
