<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Who may be told what about a group (PLAN T-005b).
 *
 * This class answers exactly one question — "may this authenticated caller
 * receive THIS disclosure about THIS group?" — and it is the only place that
 * answers it, so the feed listing, a single post, and an image download cannot
 * drift apart.
 *
 * .claude/rules/groups.md, obligation 4: a group-scoped read is visible to the
 * group's leaders and to a contact's own guardians, "never to the whole tenant
 * because they happen to be a Contact". These rosters hold children, so holding
 * `permission:view contacts` — which every masjid admin holds — is deliberately
 * NOT enough to be READ INTO a group's story. Administering a roster and being
 * disclosed to are different things, and this slice keeps them different:
 *
 *   - WRITING a post is gated by `permission:manage contacts`, exactly like the
 *     roster endpoints it sits beside. That is the accountable administrator.
 *   - READING is gated by this class: you must be IN the group.
 *
 * ## Which person is the caller?
 *
 * A Contact cannot authenticate anywhere in this application — there is no
 * congregant guard, and the parent/teacher app is T-015. The only principal on
 * these routes is an admin `users` row. So the caller's PERSON is resolved by
 * matching their login email to a Contact of the bound tenant:
 *
 *   - the tenant must be bound (it always is on /masjids/{id}/... — see
 *     .claude/rules/tenant-scoping.md), so the lookup can never reach across
 *     organizations;
 *   - the match is case-insensitive on a non-empty email;
 *   - it must resolve to EXACTLY ONE contact. Two contacts sharing an email is
 *     an ambiguity about who is asking, and an ambiguity about identity resolves
 *     to no identity — the safe direction when the content is photographs of
 *     children.
 *
 * This is an identity BRIDGE, not an escalation: a masjid admin who wanted into
 * a group could simply add themselves to its roster, which they may already do.
 * What the bridge buys is that the decision is expressed against a person in the
 * group, so it stays correct on the day contacts do get their own login.
 */
class GroupAudience
{
    /** Reading the group's posts: titles, bodies, who wrote them. */
    public const DISCLOSURE_FEED = GroupMembership::CONSENT_FEED;

    /** Receiving the images attached to those posts. */
    public const DISCLOSURE_MEDIA = GroupMembership::CONSENT_MEDIA;

    public function __construct(private TenantContext $tenant)
    {
    }

    /**
     * The contact ids this authenticated user speaks for in the bound tenant.
     *
     * @return array<int,int>
     */
    public function identitiesFor(?User $user): array
    {
        if ($user === null || ! $this->tenant->hasTenant()) {
            return [];
        }

        $email = Str::lower(trim((string) $user->email));

        if ($email === '') {
            return [];
        }

        // Contact is BelongsToMasjid + SoftDeletes, so this reads only the bound
        // tenant's live contacts. LOWER() on both sides rather than relying on
        // the column collation: production is utf8mb4_bin (case-SENSITIVE) while
        // the test suite runs SQLite, and an identity check must not depend on
        // which one it is talking to.
        $matches = Contact::query()
            ->whereNotNull('email')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->pluck('id');

        // Ambiguous identity is no identity.
        return $matches->count() === 1 ? [(int) $matches->first()] : [];
    }

    /**
     * May this user receive `$disclosure` about `$group`?
     *
     * Three ways in, and no fourth:
     *   1. a LEADER membership — the teacher of the class;
     *   2. a MEMBER membership — the participant themselves, who needs nobody's
     *      consent to be shown their own group;
     *   3. a GUARDIAN edge in this group whose RECORDED CONSENT covers this
     *      disclosure. No record means no consent, so a guardian who has never
     *      consented receives neither the feed nor an image of their child.
     */
    public function mayReceive(?User $user, Group $group, string $disclosure): bool
    {
        $contactIds = $this->identitiesFor($user);

        if ($contactIds === []) {
            return false;
        }

        $memberships = $group->memberships()
            ->whereIn('contact_id', $contactIds)
            ->get();

        foreach ($memberships as $membership) {
            if (in_array($membership->role, GroupMembership::PARTICIPANT_ROLES, true)) {
                return true;
            }

            if ($membership->consentCovers($disclosure)) {
                return true;
            }
        }

        return false;
    }
}
