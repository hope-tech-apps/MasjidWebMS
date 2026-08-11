<?php

namespace App\Support;

use App\Models\BehaviorAward;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Who may be told what about a group (PLAN T-005b).
 *
 * This class answers exactly one question — "may this authenticated caller
 * receive THIS disclosure about THIS group?" — and it is the only place that
 * answers it, so the feed listing, a single post, an image download, a
 * messaging thread (T-005c) and a child's behaviour record (T-013) cannot drift
 * apart.
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

    /**
     * May this user read `$thread` in `$group`? (T-005c)
     *
     * Two shapes, and the thread's scope picks between them:
     *
     *   - GROUP-WIDE thread: announcement-discussion. Same audience as the
     *     feed, so it IS the feed disclosure — one decision, not a second one
     *     that could drift.
     *   - PARTICIPANT thread: a private conversation about ONE member. Readable
     *     by the group's leaders, by that member themselves, and by a guardian
     *     whose edge names that member as their ward. Nobody else — another
     *     guardian in the same group is exactly who this scope exists to
     *     exclude.
     *
     * Consent is deliberately NOT consulted on a participant thread. The
     * feed/media consent scopes gate BROADCASTS of a child's data to the
     * guardian audience; a participant thread is a conversation the guardian is
     * a named party to about their own ward, not a broadcast — requiring feed
     * consent here would block a parent from discussing their own child with
     * the teacher, which inverts what consent protects.
     *
     * An unrecognized stored scope lands in the participant branch
     * (GroupThread::threadScope() degrades that way on purpose), and a thread
     * whose target membership has been removed from the roster has a null
     * target — both fail CLOSED to leaders-only.
     */
    public function mayReceiveThread(?User $user, Group $group, GroupThread $thread): bool
    {
        if ($thread->isGroupWide()) {
            return $this->mayReceive($user, $group, self::DISCLOSURE_FEED);
        }

        $standing = $this->standingIn($user, $group);

        if ($standing['leader']) {
            return true;
        }

        $target = $thread->aboutMembership?->contact_id;

        if ($target === null) {
            return false;
        }

        return in_array((int) $target, $standing['participant_contact_ids'], true)
            || in_array((int) $target, $standing['ward_contact_ids'], true);
    }

    /**
     * The threads of `$group` this user may read, as a constrained query —
     * or null when the caller has no standing in the group at all.
     *
     * Query-shaped so the LIST endpoint filters with the same decision
     * mayReceiveThread() makes row-by-row, in one place; a thread the listing
     * shows is a thread show() will serve, and vice versa. The clauses:
     *
     *   - feed disclosure  -> group-wide threads;
     *   - leader           -> every non-group-wide thread as well (including
     *     one with an unrecognized stored scope — fail closed to the teachers);
     *   - otherwise        -> participant threads whose target membership names
     *     the caller's own contact or one of their wards. Consent is not
     *     consulted, for the reason documented on mayReceiveThread().
     *
     * Null (rather than an empty query) distinguishes "not in this group" —
     * which the controller answers with 403, mirroring the feed — from "in the
     * group with nothing to see", which is an empty 200.
     */
    public function readableThreadsQuery(?User $user, Group $group): ?Builder
    {
        $standing = $this->standingIn($user, $group);

        if (! $standing['in_group']) {
            return null;
        }

        // getQuery(): the relation's underlying Eloquent builder, group
        // constraint already applied. The relation object itself only
        // DECORATES the builder (fluent calls return the relation), and this
        // method promises a Builder to its callers.
        return $group->threads()->getQuery()->where(function (Builder $query) use ($standing): void {
            $granted = false;

            if ($standing['feed']) {
                $query->orWhere('scope', GroupThread::SCOPE_GROUP);
                $granted = true;
            }

            if ($standing['leader']) {
                $query->orWhere('scope', '!=', GroupThread::SCOPE_GROUP);
                $granted = true;
            } else {
                $targets = array_merge(
                    $standing['participant_contact_ids'],
                    $standing['ward_contact_ids']
                );

                if ($targets !== []) {
                    $query->orWhere(function (Builder $participant) use ($targets): void {
                        $participant->where('scope', GroupThread::SCOPE_PARTICIPANT)
                            ->whereHas('aboutMembership', function (Builder $membership) use ($targets): void {
                                $membership->whereIn('contact_id', $targets);
                            });
                    });
                    $granted = true;
                }
            }

            // Unreachable while every membership row is a participant or a
            // warded guardian edge, but a WHERE group with no clauses would
            // constrain NOTHING — the one failure mode this class must never
            // have — so it is pinned shut rather than assumed.
            if (! $granted) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /**
     * May this user read the behaviour record of `$subject` — one PARTICIPANT
     * membership in `$group`? (T-013)
     *
     * A CHILD'S BEHAVIOUR RECORD IS PRIVATE. Three ways in, and no fourth:
     *
     *   1. a LEADER of the group — the teacher who keeps the record;
     *   2. the student THEMSELVES, when the subject membership is one of the
     *      caller's own participant memberships;
     *   3. a GUARDIAN of that student, i.e. a guardian edge in this group whose
     *      ward is the subject's contact.
     *
     * ANOTHER GUARDIAN IN THE SAME GROUP IS EXACTLY WHO THIS EXCLUDES. "Guardian
     * here" never meant "guardian of this child" — the same reasoning that made
     * guardianship an explicit edge in the first place, and the same rule
     * mayReceiveThread() applies to a participant thread. There is deliberately
     * no branch that widens this to the group, because a class-wide view of who
     * has how many points is the public shaming this module exists to refuse.
     *
     * CONSENT IS NOT CONSULTED, for the same reason as a participant thread:
     * the feed/media scopes gate BROADCASTS of a child's data to the guardian
     * audience, and a parent reading their own child's record is not a
     * broadcast. Requiring feed consent here would lock a parent out of the one
     * record that is most obviously theirs to see.
     *
     * Fails closed: a subject with no resolvable contact, a caller with no
     * identity, or a guardian edge with no ward all land on false.
     */
    public function mayReceiveAwardsAbout(?User $user, Group $group, GroupMembership $subject): bool
    {
        // An award is given to a person. A guardian row is a relationship, so it
        // is never a subject — refusing here means a mis-targeted award can
        // never be read by anyone, rather than quietly by the wrong person.
        if (! in_array($subject->role, GroupMembership::PARTICIPANT_ROLES, true)) {
            return false;
        }

        // The subject must belong to the group being asked about, or the
        // caller's standing in THIS group would be answering for another one.
        if ((int) $subject->group_id !== (int) $group->id) {
            return false;
        }

        $standing = $this->standingIn($user, $group);

        if ($standing['leader']) {
            return true;
        }

        $student = (int) $subject->contact_id;

        return in_array($student, $standing['participant_contact_ids'], true)
            || in_array($student, $standing['ward_contact_ids'], true);
    }

    /**
     * May this user read one specific award? Delegates to the rule above so a
     * single award and a listing cannot disagree about the same row.
     *
     * A null membership cannot happen while the FK cascades, but an award whose
     * subject could not be resolved is refused rather than assumed readable —
     * the one failure direction this class must never have.
     */
    public function mayReceiveAward(?User $user, Group $group, BehaviorAward $award): bool
    {
        $subject = $award->membership;

        return $subject !== null && $this->mayReceiveAwardsAbout($user, $group, $subject);
    }

    /**
     * The awards of `$group` this user may read, as a constrained query — or
     * null when the caller has no standing in the group at all.
     *
     * Query-shaped for the same reason as readableThreadsQuery(): the listing
     * must filter with the SAME decision mayReceiveAwardsAbout() makes row by
     * row, in one place, so a forbidden award is never even fetched — a guardian
     * cannot learn what another family's child was marked for by counting rows,
     * reading a paginator total, or summing an aggregate. The clauses:
     *
     *   - leader   -> every award in the group;
     *   - anyone
     *     else     -> only awards whose subject membership names the caller's
     *                 own contact or one of their wards.
     *
     * Null (rather than an empty query) distinguishes "not in this group" —
     * which the controller answers with 403, mirroring the feed and the threads
     * — from "in the group with nothing to see", which is an empty 200.
     */
    public function readableAwardsQuery(?User $user, Group $group): ?Builder
    {
        $standing = $this->standingIn($user, $group);

        if (! $standing['in_group']) {
            return null;
        }

        // getQuery(): the relation's underlying Eloquent builder, group
        // constraint already applied — this method promises a Builder.
        $query = $group->behaviorAwards()->getQuery();

        if ($standing['leader']) {
            return $query;
        }

        $targets = array_merge(
            $standing['participant_contact_ids'],
            $standing['ward_contact_ids']
        );

        if ($targets === []) {
            // A guardian edge with no ward is the only way to reach this. It
            // grants nothing, and an unconstrained WHERE would grant everything
            // — so it is pinned shut rather than assumed unreachable.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('membership', function (Builder $membership) use ($targets): void {
            $membership->whereIn('contact_id', $targets)
                // Belt and braces with the audience rule above: an award
                // mis-attached to a guardian edge must not become readable
                // because that guardian's own contact is in the target list.
                ->whereIn('role', GroupMembership::PARTICIPANT_ROLES);
        });
    }

    /**
     * The caller's whole footing in one group, resolved once: which of their
     * contact identities hold memberships, in what roles, over which wards.
     * Shared by the thread decisions above so the single-thread check and the
     * list constraint cannot disagree about who the caller is.
     *
     * @return array{
     *     in_group: bool,
     *     leader: bool,
     *     feed: bool,
     *     participant_contact_ids: array<int,int>,
     *     ward_contact_ids: array<int,int>,
     * }
     */
    private function standingIn(?User $user, Group $group): array
    {
        $none = [
            'in_group' => false,
            'leader' => false,
            'feed' => false,
            'participant_contact_ids' => [],
            'ward_contact_ids' => [],
        ];

        $contactIds = $this->identitiesFor($user);

        if ($contactIds === []) {
            return $none;
        }

        $memberships = $group->memberships()
            ->whereIn('contact_id', $contactIds)
            ->get();

        if ($memberships->isEmpty()) {
            return $none;
        }

        $leader = false;
        $feed = false;
        $participantContactIds = [];
        $wardContactIds = [];

        foreach ($memberships as $membership) {
            if (in_array($membership->role, GroupMembership::PARTICIPANT_ROLES, true)) {
                // A participant IS the person: they hold the feed disclosure
                // outright, and participant threads about themselves.
                $feed = true;
                $participantContactIds[] = (int) $membership->contact_id;
                $leader = $leader || $membership->role === GroupMembership::ROLE_LEADER;

                continue;
            }

            if ($membership->isGuardian() && $membership->guardian_of_contact_id !== null) {
                $wardContactIds[] = (int) $membership->guardian_of_contact_id;
                // The feed remains consent-gated for guardians — only the
                // participant-thread channel about their own ward is not.
                $feed = $feed || $membership->consentCovers(self::DISCLOSURE_FEED);
            }
        }

        return [
            'in_group' => true,
            'leader' => $leader,
            'feed' => $feed,
            'participant_contact_ids' => array_values(array_unique($participantContactIds)),
            'ward_contact_ids' => array_values(array_unique($wardContactIds)),
        ];
    }
}
