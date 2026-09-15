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
 *
 * ------------------------------------------------------------------------------
 * The flag is not the only precondition: OPENING IT DROPS THE OWNERSHIP FALLBACK
 * ------------------------------------------------------------------------------
 *
 * With the gate open, grantsFor() stops consulting `masjids.user_id` entirely
 * and a grant exists only where a `masjid_user` ROW exists. The INVITED staff
 * realms are safe there — TeachersController, AdministratorsController,
 * LunchStaffController and TeamController each write the row as they create the
 * login, which is why a second office admin who owns nothing binds today. THE
 * OWNER IS NOT. `OnboardingController@provision` and `MasjidsController@store`
 * set `masjids.user_id` and write no membership; the only rows owners have are
 * the ones S2's migration wrote, for the masjids that were live AT MIGRATION
 * TIME. Every organisation provisioned since has an owner with no row, kept
 * working solely by the ownership fallback this flag removes.
 *
 * So flipping the flag on such an environment does not "enable multi-tenant
 * admin"; it 403s those owners out of their own organisation on every screen,
 * with the same message as a genuine cross-tenant refusal. That is a lockout,
 * not a leak — it fails closed, as designed — but to the person it happens to
 * it is indistinguishable from a broken account.
 *
 * Before the flag may be turned on anywhere, including staging:
 *
 *   1. the owner paths above write the membership row as they set `user_id`,
 *   2. a backfill has been re-run so no live owned masjid is missing one, and
 *   3. the two have been COUNTED against each other and agree. A resolver
 *      cannot check this for you — it only ever sees one user, and the user it
 *      is about to refuse looks exactly like a stranger.
 */
final class TenantResolver
{
    /**
     * Admin routes that are genuinely not about ONE masjid, and are therefore
     * the only ones a multi-tenant admin may reach with the context left
     * unbound: their own account surface plus the organisation list the S5
     * switcher will be built from.
     *
     * Consulted only where resolve() has SEVERAL grants and no `{masjid_id}`,
     * so a single-membership admin binds on these routes exactly as they did
     * before S3 and this list changes nothing for them. It is NOT, however,
     * dead until the gate opens: with the gate shut an OWNER has exactly one
     * grant, but a non-owner staff principal (a Teacher, a second office
     * MasjidAdmin, lunch staff) falls through to staffMemberships(), and two
     * live membership rows for one of those people reach this branch today.
     *
     * It is deliberately an allowlist of literals rather than "any route
     * without {masjid_id}": the design's alternative was to default-bind, which
     * would silently mis-scope EnsureCrmEnabled and turn ImpactMetrics::
     * withTenant()'s guard into a 500. Every endpoint on it must therefore
     * touch NO `BelongsToMasjid` model — unbound means no filter, so an
     * allowlisted route that reads a scoped table reads every organisation's.
     * The ones here act on the caller's own `users` row or on nothing.
     *
     * A ROUTE MISSING FROM HERE IS A 403 THE DAY THE GATE OPENS, so the list is
     * derived rather than guessed: routes/admin.php has exactly one
     * `auth:sanctum|admin|tenant` group, and inside it every endpoint a
     * MasjidAdmin can reach either carries `{masjid_id}` or is one of these.
     * (Everything else without `{masjid_id}` — /users, /search, /onboarding,
     * /admins, the content catalogues — additionally carries `super`, so a
     * MasjidAdmin is refused a layer earlier and never reaches this branch.
     * They stay listed where the design named them; allowlisting an endpoint
     * the caller cannot reach costs nothing.) Re-derive this list, do not
     * extend it from memory, whenever a non-`{masjid_id}` admin route is added.
     */
    private const UNSCOPED_ADMIN_ROUTES = [
        'api/admin/user',
        'api/admin/logout',
        'api/admin/profile',
        // BOTH spellings on purpose. `2fa/*` matches enroll/confirm/
        // recovery-codes but NOT the bare segment, and the bare segment is
        // `DELETE /api/admin/2fa` — the one that turns two-factor OFF. With
        // only the wildcard listed, a principal holding two live memberships
        // could enrol a second factor and then be 403'd out of every way to
        // remove it: a self-inflicted lockout, from an asymmetry in a list
        // rather than from any decision about their access. TwoFactorController
        // ::disable touches only the caller's own `users` row, so unbound here
        // is as safe as it already is on the three wildcard endpoints.
        'api/admin/2fa',
        'api/admin/2fa/*',
        'api/admin/masjids',
        'api/admin/masjids/timezones',
        'api/admin/search',
    ];

    /**
     * @param  User  $user  the authenticated staff principal
     * @param  int|null  $routeMasjidId  the ROUTE's {masjid_id} — never client input
     * @param  string|null  $path  request path; read ONLY on the several-grants branch, to test
     *                             UNSCOPED_ADMIN_ROUTES. A caller that already has a route masjid
     *                             may omit it — that branch returns before the path is consulted,
     *                             which is why AuthController can ask "may this user act on N?"
     *                             one candidate at a time and get the verdict the route will give.
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
        //
        // This is S5's ordinary case, but it is NOT gate-only: an owner has one
        // grant with the gate shut, while a non-owner staff principal resolves
        // through staffMemberships(), which can already return two rows today
        // (see UNSCOPED_ADMIN_ROUTES). Treat this branch as live code.
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
     * ------------------------------------------------------------------------
     * It stays PRIVATE, and S4's `memberships[]` is right not to want it
     * ------------------------------------------------------------------------
     *
     * The design has `/api/admin/user` publish `memberships[]` and S5 build the
     * switcher from it, so this method — the gated grant set — looks like the
     * accessor that endpoint should call. Exposing it was considered and
     * rejected: it answers only half the question. It says WHICH organisations
     * are grants; it does not run the route-match branch in resolve() above,
     * which is where "the id in the URL is answered yes or no, never
     * substituted" actually lives. A caller holding this list would be one
     * refactor away from concluding that holding a grant is the same as being
     * able to act on it.
     *
     * `AuthController::grantedMemberships()` instead asks resolve() once per
     * candidate masjid, exactly as a route would ask it, and keeps only the
     * verdicts that bind. That is slower and strictly more honest: the switcher
     * then cannot offer an organisation the very next request will 403, which
     * is the failure that puts organisation A's name above organisation B's
     * rows. Publish the list through the resolver's own verdict, never by
     * re-reading `masjid_user` and never through a second accessor here.
     *
     * One property of the rows leaks out through that path and is worth naming
     * where it is produced: a grant MAY BE AN UNSAVED `MasjidUser`
     * (membershipFromOwnership() below), so `id`, `created_at` and `updated_at`
     * can be NULL. Anything downstream keys on `masjid_id`; a switcher keyed on
     * the membership `id` collapses every such admin onto the key `null`.
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
