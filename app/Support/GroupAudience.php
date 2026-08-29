<?php

namespace App\Support;

use App\Models\BehaviorAward;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Who may be told what about a group (PLAN T-005b).
 *
 * This class answers exactly one question — "may this authenticated caller
 * receive THIS disclosure about THIS group?" — and it is the only place that
 * answers it, so the feed listing, a single post, an image download, a
 * messaging thread (T-005c), a child's behaviour record (T-013) and their ḥifẓ
 * record (T-014) cannot drift apart.
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
 * Two kinds of principal reach this class, and `identitiesFor()` is the only
 * method that can tell them apart.
 *
 * A CONTACT (the `family` guard, T-015d/e) needs no resolution: it already IS
 * the person the roster names, so it resolves to its own id, subject to the
 * liveness and tenant checks documented on that method. THIS GRANTED NO NEW
 * AUTHORITY — every rule below is unchanged, and a parent gets exactly the
 * standing their `group_memberships` rows describe and nothing else.
 *
 * A STAFF `users` row has no such edge, so the caller's PERSON is resolved by
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
 *
 * ## Why every signature says ?Authenticatable and not ?User (T-015b)
 *
 * DO NOT NARROW THESE BACK TO `?App\Models\User`. The parameter type is the
 * whole point of the slice.
 *
 * `docs/t015-parent-identity-design.md` gives a `Contact` its own Sanctum guard
 * so a parent can read their own child's record. Every one of the methods below
 * had to be reachable with that parent as the caller, and there are THIRTEEN of
 * them — not one seam. While they said `?User`, a family principal could not be
 * PASSED to the disclosure logic at all: the refusal happened at the PHP type
 * boundary, in a TypeError, which is not a decision this class ever made and
 * not one anybody could test. Widening moves the refusal INTO the class, where
 * it is a resolved-to-nobody answer like any other, and unblocks T-015c/T-015e.
 *
 * Widening the type changed no rule. Every method here reasons in CONTACT IDS,
 * which `identitiesFor()` hands it; only `identitiesFor()` ever touches the
 * principal object, and it narrows explicitly with `instanceof` before reading
 * anything model-specific. So a staff caller's behaviour is byte-identical to
 * before, and the existing group, messaging, behaviour and ḥifẓ suites are the
 * proof — they were not modified.
 *
 * ## T-015e: the Contact branch, and what it deliberately did NOT touch
 *
 * `identitiesFor()` now resolves an authenticated parent. That is the WHOLE of
 * the slice inside this class — `standingIn`, `mayReceive`, `mayReceiveThread`,
 * `mayReceiveRecordAbout`, `readableThreadsQuery`, `readableAwardsQuery`,
 * `readableHifzQuery` and `constrainToOwnStudents` are untouched, so every
 * disclosure rule in .claude/rules/groups.md still holds BY CONSTRUCTION rather
 * than by a second implementation that agrees today:
 *
 *   - a guardian reads the feed only where a recorded consent covers it;
 *   - a guardian reads participant threads, awards and ḥifẓ ONLY about their own
 *     ward — "guardian here" never meant "guardian of this child", and another
 *     family's child in the same group is precisely who that excludes;
 *   - the listing queries are constrained at QUERY level, so a forbidden row is
 *     never fetched and cannot surface in a page, a paginator total or an
 *     aggregate.
 *
 * A principal that is neither a `User` nor a live `Contact` still resolves to NO
 * identity and therefore NO standing anywhere here. That is deliberate: an actor
 * this class cannot place in the roster must be refused, never assumed.
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
     * Does this principal LEAD `$group` as a staff login (`group_staff`)?
     *
     * THE teacher authorization primitive — the login-side counterpart of the
     * Contact `leader` membership. True ONLY for a `User` named in this group's
     * `group_staff`; never for a Contact, and NEVER through the email→Contact
     * bridge in identitiesFor(). That independence is deliberate: a teacher holds
     * no self-asserted Contact membership to confirm, so routing them through the
     * Contact path would either grant nothing (nothing to confirm) or leak
     * standing to a Contact that merely shares their email.
     *
     * Reads through the tenant-scoped `GroupStaff` model, so a staff row in
     * another masjid cannot grant standing here even if a group id collided.
     */
    public function isLeaderOf(?Authenticatable $principal, Group $group): bool
    {
        if (! $principal instanceof User) {
            return false;
        }

        return GroupStaff::query()
            ->where('group_id', $group->getKey())
            ->where('user_id', $principal->getKey())
            ->exists();
    }

    /**
     * The ids of the groups this staff user leads, within the bound tenant — the
     * "only my classes" set that scopes every teacher read (Group::scopeLedBy
     * runs the same relation the other way). Tenant-scoped by
     * GroupStaff::BelongsToMasjid, so it can never name a group outside the bound
     * masjid.
     *
     * @return array<int,int>
     */
    public function leaderGroupIdsFor(?Authenticatable $principal): array
    {
        // ?Authenticatable, not User, to keep every GroupAudience signature
        // uniform (GroupAudienceForeignPrincipalTest pins this — the T-015b
        // widening that lets a Contact principal flow through). Only a User can
        // lead a class; anything else names no classes.
        if (! $principal instanceof User) {
            return [];
        }

        return GroupStaff::query()
            ->where('user_id', $principal->getKey())
            ->pluck('group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The contact ids this authenticated principal speaks for in the bound
     * tenant.
     *
     * ONE OF EXACTLY TWO METHODS IN THIS CLASS THAT TOUCH THE PRINCIPAL OBJECT,
     * and the only one that asks WHO the caller is. Every rule below takes the
     * contact ids this returns and reasons in those, which is exactly why
     * widening all thirteen signatures to `?Authenticatable` (T-015b) needed one
     * `instanceof` and no rule changes.
     *
     * The second is `membershipsFor()`, which asks a different question — which
     * of the caller's roster rows their credential CARRIES — and narrows a
     * parent-portal principal to their guardian edges. It is deliberately not
     * folded in here: this method answers identity, that one answers scope, and
     * collapsing them would make "who is this?" depend on which group is being
     * asked about.
     *
     * @return array<int,int>
     */
    public function identitiesFor(?Authenticatable $principal): array
    {
        if ($principal === null || ! $this->tenant->hasTenant()) {
            return [];
        }

        // ------------------------------------------------ the parent (T-015e)
        //
        // A contact does not need bridging: it IS the person the roster names.
        // `group_memberships.contact_id`, `guardian_of_contact_id`,
        // `behavior_awards.group_membership_id` and every guardian edge already
        // point at contacts, and every other method in this class reasons in
        // contact ids — so an authenticated parent resolves to their OWN id and
        // nothing else. This branch adds an AUTHENTICATION fact and changes no
        // AUTHORIZATION rule: what a parent may then read is decided entirely by
        // the roster rules below, which are byte-identical to what they were
        // when only staff could reach them.
        //
        // The three liveness checks are not decoration, and each closes a way a
        // dead credential could still resolve to a live person:
        //
        //   - REVOKED. `family.active` already refuses a revoked contact on
        //     every family request, but this class is also called from tests, from
        //     future console/report paths, and from any endpoint that might one
        //     day be mounted without that middleware. Disclosure must fail
        //     closed on its own evidence, not on its caller's.
        //   - TRASHED / NEVER ENABLED. `Contact` soft-deletes, and
        //     `ContactsController::merge` FORCE-deletes the absorbed side —
        //     which DB-cascades `group_memberships` with no model events. A
        //     bare `contact_id` check would happily resolve a merged-away
        //     parent, or a roster row that was never given a login at all.
        //   - CROSS-TENANT. Compared against the BOUND tenant independently of
        //     `family.tenant`, because tenant isolation in this application is
        //     the bound context and nothing else (.claude/rules/tenant-scoping.md);
        //     a second, independent check is what makes a single forgotten
        //     middleware not a cross-organisation read of children's records.
        //
        // `familyLoginIsActive()` is the first two, and it is deliberately the
        // SAME method `App\Http\Middleware\EnsureFamilyLoginActive` calls —
        // liveness has one definition in this application, so revocation cannot
        // mean one thing at the door and another at the disclosure. It is
        // stricter than docs/t015-parent-identity-design.md §4 by one clause
        // (that snippet omitted `login_enabled_at`); stricter is the safe
        // direction and keeps the two doors identical.
        //
        // ------------------------------------------------------------------
        // WHO MAY BE GIVEN A LOGIN IS NOT DECIDED HERE — and must not be
        // ------------------------------------------------------------------
        //
        // This branch resolves whoever `login_enabled_at` was set on. It does
        // NOT check that they are an adult or a guardian, and it CANNOT: the
        // schema has no such flag, and a `member` row is an adult volunteer on
        // a masjid's team exactly as often as it is a child in a classroom.
        //
        // The consequence docs §7 refuses is that ENABLING A LOGIN ON A CHILD'S
        // CONTACT ROW WOULD BE A STUDENT LOGIN. It used to follow from
        // `standingIn()` setting `feed = true` outright for any participant, so
        // such a login read the whole class feed — every classmate's photograph,
        // with nobody's consent — plus the participant threads about themselves,
        // which are where a teacher and a guardian discuss a safeguarding
        // concern. Measured, and pinned at the time as a hazard.
        //
        // THAT NO LONGER FOLLOWS. `membershipsFor()` drops a family principal's
        // own participant rows, so a credential reads through guardian edges
        // only: a contact who is nobody's guardian resolves to no standing in
        // any group, and one who is a guardian reads their wards and nothing
        // about themselves. `FamilyAccessService::enable()` independently still
        // refuses a contact who holds no guardian edge over a live ward, so the
        // two halves agree — the door and the disclosure rule both say the same
        // thing, rather than the door being the only thing saying it.
        //
        // A student login remains its own task: it is a DIFFERENT standing
        // computation (own-record-only, no group feed, no participant threads),
        // and what is here is the fail-closed placeholder — a family credential
        // gets NOTHING from a participant row, rather than the narrow slice a
        // student one eventually should.
        if ($principal instanceof Contact) {
            return $principal->familyLoginIsActive()
                && (int) $principal->masjid_id === (int) $this->tenant->get()
                    ? [(int) $principal->id]
                    : [];
        }

        // THE NARROWING. `$principal` is only typed as Authenticatable, so the
        // email bridge below — which reads a `users` column — must not be
        // reached by an actor that is not a `users` row.
        //
        // Everything that is neither a `User` nor a `Contact` resolves to NO
        // identity, which every other method here turns into no standing. That
        // is the safe direction: the content behind these decisions is
        // photographs of children and their academic records, so an
        // unrecognized principal is refused rather than assumed.
        if (! $principal instanceof User) {
            return [];
        }

        $email = Str::lower(trim((string) $principal->email));

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
    public function mayReceive(?Authenticatable $principal, Group $group, string $disclosure): bool
    {
        // A staff login that LEADS this class receives every disclosure about it,
        // exactly as a Contact `leader` membership does below — a teacher sees
        // their own class's feed and images. Resolved from group_staff, not from
        // a Contact row, and it fires ONLY for a User in this group's staff, so
        // every existing Contact/guardian/admin path is byte-identical.
        if ($this->isLeaderOf($principal, $group)) {
            return true;
        }

        foreach ($this->membershipsFor($principal, $group) as $membership) {
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
    public function mayReceiveThread(?Authenticatable $principal, Group $group, GroupThread $thread): bool
    {
        if ($thread->isGroupWide()) {
            return $this->mayReceive($principal, $group, self::DISCLOSURE_FEED);
        }

        $standing = $this->standingIn($principal, $group);

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
    public function readableThreadsQuery(?Authenticatable $principal, Group $group): ?Builder
    {
        $standing = $this->standingIn($principal, $group);

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
    public function mayReceiveAwardsAbout(?Authenticatable $principal, Group $group, GroupMembership $subject): bool
    {
        return $this->mayReceiveRecordAbout($principal, $group, $subject);
    }

    /**
     * May this user read the ḥifẓ record of `$subject` — one PARTICIPANT
     * membership in `$group`? (T-014)
     *
     * THE SAME RULE, deliberately not a second one: leaders of the ḥalaqa, the
     * student, and that student's own guardians. A memorisation record is a
     * child's academic record, and .claude/rules/groups.md obligation 4 does not
     * become weaker because the subject is Qur'an rather than behaviour —
     * another guardian in the same ḥalaqa is refused here exactly as they are
     * for an award. It delegates to the shared decision below so the two
     * surfaces cannot drift apart; the separate name exists so callers read
     * clearly and so a future slice that genuinely needs a different rule has an
     * obvious place to put it.
     */
    public function mayReceiveHifzAbout(?Authenticatable $principal, Group $group, GroupMembership $subject): bool
    {
        return $this->mayReceiveRecordAbout($principal, $group, $subject);
    }

    /**
     * May this user read one specific ḥifẓ entry? Delegates to the rule above so
     * a single entry and a listing cannot disagree about the same row.
     */
    public function mayReceiveHifzEntry(?Authenticatable $principal, Group $group, HifzEntry $entry): bool
    {
        $subject = $entry->membership;

        return $subject !== null && $this->mayReceiveHifzAbout($principal, $group, $subject);
    }

    /**
     * WHO MAY READ A RECORD ABOUT ONE STUDENT — the single decision behind both
     * behaviour awards (T-013) and ḥifẓ entries (T-014).
     *
     * Three ways in, and no fourth:
     *
     *   1. a LEADER of the group — the teacher who keeps the record;
     *   2. the student THEMSELVES, when the subject membership is one of the
     *      caller's own participant memberships;
     *   3. a GUARDIAN of that student, i.e. a guardian edge in this group whose
     *      ward is the subject's contact.
     *
     * ANOTHER GUARDIAN IN THE SAME GROUP IS EXACTLY WHO THIS EXCLUDES, and it is
     * why the two modules share one implementation rather than two that agree
     * today.
     */
    private function mayReceiveRecordAbout(?Authenticatable $principal, Group $group, GroupMembership $subject): bool
    {
        // A record is kept about a person. A guardian row is a relationship, so
        // it is never a subject — refusing here means a mis-targeted record can
        // never be read by anyone, rather than quietly by the wrong person.
        if (! in_array($subject->role, GroupMembership::PARTICIPANT_ROLES, true)) {
            return false;
        }

        // The subject must belong to the group being asked about, or the
        // caller's standing in THIS group would be answering for another one.
        if ((int) $subject->group_id !== (int) $group->id) {
            return false;
        }

        $standing = $this->standingIn($principal, $group);

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
    public function mayReceiveAward(?Authenticatable $principal, Group $group, BehaviorAward $award): bool
    {
        $subject = $award->membership;

        return $subject !== null && $this->mayReceiveAwardsAbout($principal, $group, $subject);
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
    public function readableAwardsQuery(?Authenticatable $principal, Group $group): ?Builder
    {
        // getQuery(): the relation's underlying Eloquent builder, group
        // constraint already applied — this method promises a Builder.
        return $this->constrainToOwnStudents($principal, $group, $group->behaviorAwards()->getQuery());
    }

    /**
     * The ḥifẓ entries of `$group` this user may read, as a constrained query —
     * or null when the caller has no standing in the ḥalaqa at all. (T-014)
     *
     * The listing half of the privacy guarantee, and the reason a summary is
     * safe to compute: a forbidden entry is never fetched, so another family's
     * child cannot surface in a page, in a paginator total, or inside a
     * memorisation aggregate. Same constraint as the awards listing, from the
     * same code.
     */
    public function readableHifzQuery(?Authenticatable $principal, Group $group): ?Builder
    {
        return $this->constrainToOwnStudents($principal, $group, $group->hifzEntries()->getQuery());
    }

    /**
     * Narrow a query over records-about-students to the ones this caller may
     * read, or null when they have no standing in the group at all.
     *
     * Query-shaped for the reason readableThreadsQuery() is: the listing must
     * filter with the SAME decision mayReceiveRecordAbout() makes row by row, in
     * one place, so a forbidden row is never even fetched — a guardian cannot
     * learn what another family's child was marked for, or how much they have
     * memorised, by counting rows, reading a paginator total, or summing an
     * aggregate. The clauses:
     *
     *   - leader   -> every record in the group;
     *   - anyone
     *     else     -> only records whose subject membership names the caller's
     *                 own contact or one of their wards.
     *
     * Null (rather than an empty query) distinguishes "not in this group" —
     * which the controllers answer with 403, mirroring the feed and the threads
     * — from "in the group with nothing to see", which is an empty 200.
     *
     * Every model passed here MUST expose its subject as a `membership`
     * relation to a `group_memberships` row; BehaviorAward and HifzEntry both
     * do, on purpose.
     */
    private function constrainToOwnStudents(?Authenticatable $principal, Group $group, Builder $query): ?Builder
    {
        $standing = $this->standingIn($principal, $group);

        if (! $standing['in_group']) {
            return null;
        }

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
                // Belt and braces with the audience rule above: a record
                // mis-attached to a guardian edge must not become readable
                // because that guardian's own contact is in the target list.
                ->whereIn('role', GroupMembership::PARTICIPANT_ROLES);
        });
    }

    /**
     * The rows in `$group` this principal actually speaks THROUGH.
     *
     * The second — and last — method in this class that touches the principal
     * object, and it is not an identity question: `identitiesFor()` answers WHO
     * the caller is, and this answers WHICH OF THEIR ROSTER ROWS THEIR
     * CREDENTIAL CARRIES. Sharing it between `mayReceive()` and `standingIn()`
     * is what keeps the feed decision and the thread/record decisions from
     * disagreeing about the same caller.
     *
     * ------------------------------------------------------------------------
     * A PARENT PORTAL CREDENTIAL SPEAKS FOR WARDS, AND FOR NOBODY ELSE
     * ------------------------------------------------------------------------
     *
     * A `Contact` principal is somebody signed in to the PARENT PORTAL, and the
     * only thing that portal was issued for is the children its holder is
     * recorded as a guardian of. So their own `leader`/`member` rows are
     * dropped here: through the family guard they buy no feed, no participant
     * thread about themselves, no award, no ḥifẓ record, and no standing in a
     * group they are merely a participant of (which therefore 403s, exactly as
     * a group they are not in at all does).
     *
     * WHY THIS EXISTS. `standingIn()` sets `feed = true` outright for ANY
     * participant — correct for a STAFF caller, who is the person themselves
     * and needs nobody's consent to be shown their own group. Applied to a
     * parent's credential it meant a login granted for one child's records also
     * carried whatever class its holder happened to be enrolled on, including
     * the participant threads about them: measured, a login-enabled participant
     * read the whole class feed, an attachment's bytes and the safeguarding
     * thread about themselves, with no consent recorded anywhere.
     *
     * `FamilyAccessService` used to answer that by REFUSING A CREDENTIAL to
     * anybody holding a participant edge, and a `GroupMembership::created` hook
     * destroyed one the moment its holder gained a roster row. Both are gone.
     * They controlled what a credential may READ by controlling WHO MAY HOLD
     * one, which comes apart on the two most ordinary adults a school has — a
     * parent in the adult ḥalaqa, and a teacher who is also a parent — and the
     * hook handed an anonymous caller the power to burn a working credential.
     * Scoping the read refuses nobody, destroys nothing, and is re-evaluated
     * from live roster rows on every request, so no ordering of two admin acts
     * can walk around it.
     *
     * WHAT THIS IS NOT: it is not the student-login standing computation. That
     * would give a student their OWN records through their own participant row;
     * this gives a family credential nothing at all through one. A student login
     * is still its own task, and this narrowing is the fail-closed placeholder
     * until it exists.
     *
     * A `User` (staff) principal is untouched — every rule below holds for them
     * exactly as it did — and anything that is neither resolves to no identity
     * one call up, and so to no memberships here.
     *
     * PUBLIC because `Http\Controllers\Family\GroupsController` asks the same
     * question — "is this parent in this group, and through which rows?" — to
     * decide its listing, its 403 and what it serializes. It asked it of the
     * `group_memberships` table directly, which was a SECOND definition of
     * standing outside this class: the one thing `GroupAudience` exists to
     * prevent, and the shape every drift bug in this area has had. One rule, one
     * implementation, two callers.
     *
     * ------------------------------------------------------------------------
     * ONLY A CONFIRMED ROW SPEAKS (provenance, 2026-08-13)
     * ------------------------------------------------------------------------
     *
     * A roster row now records WHOSE AUTHORITY it exists on
     * (`GroupMembership::PROVENANCES`), and this one `confirmed()` clause is the
     * whole of how the read side honours it. It sits here rather than in the
     * eight methods above for the reason this class exists at all: `mayReceive`,
     * `standingIn` and therefore `mayReceiveThread`, `readableThreadsQuery`,
     * `mayReceiveRecordAbout`, `readableAwardsQuery`, `readableHifzQuery` and
     * `Family\GroupsController` all resolve standing THROUGH here, so one filter
     * makes the rule true of every disclosure surface by construction instead of
     * by eight implementations that agree today. That is the same argument
     * T-015e made for putting the parent branch in `identitiesFor()` alone.
     *
     * A `self_asserted` row is a claim a public form made with no session, no
     * token and no proof of control of any address. It is a ROSTER FACT — the
     * person is listed, they count towards capacity, a teacher records their
     * behaviour and ḥifẓ — and it is not a grant, so it buys its HOLDER nothing
     * here: no feed, no thread, no award, no ḥifẓ record, no standing at all in
     * the group (which therefore 403s, exactly as a group they are not in does).
     *
     * WHAT THIS REFUSES THAT USED TO WORK, said plainly. A family that signs up
     * through the public form reads nothing in the parent portal until the
     * office confirms the claim; and a STAFF caller whose contact was put on a
     * roster by a public registration is no longer read INTO that group by it.
     * The second is the point: `identitiesFor()` bridges a staff login to a
     * Contact, so before this clause an anonymous POST naming a masjid admin's
     * address wrote them a `member` row and handed them a class feed —
     * photographs of children — that .claude/rules/groups.md obligation 4 exists
     * to keep from the whole tenant. The office's way through is one click on
     * the roster screen, in bulk, and the claims are listed there rather than
     * discovered.
     *
     * SUBJECT PROVENANCE IS NOT CONSULTED, and must not be. This filters the
     * CALLER's own rows. A child enrolled by a pending claim is still a child on
     * the roster: their teacher — whose leader row is confirmed — still reads and
     * writes their records, which is what lets a school take a register on the
     * first morning of a camp rather than after 200 confirmations.
     *
     * @return \Illuminate\Support\Collection<int,GroupMembership>
     */
    public function membershipsFor(?Authenticatable $principal, Group $group): Collection
    {
        $contactIds = $this->identitiesFor($principal);

        if ($contactIds === []) {
            return collect();
        }

        $memberships = $group->memberships()
            ->confirmed()
            ->whereIn('contact_id', $contactIds)
            ->get();

        if (! $principal instanceof Contact) {
            return $memberships;
        }

        return $memberships
            ->filter(fn (GroupMembership $membership): bool => $membership->isGuardian()
                && $membership->guardian_of_contact_id !== null)
            ->values();
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
    private function standingIn(?Authenticatable $principal, Group $group): array
    {
        $none = [
            'in_group' => false,
            'leader' => false,
            'feed' => false,
            'participant_contact_ids' => [],
            'ward_contact_ids' => [],
        ];

        // A staff login that LEADS this class (group_staff) has full leader
        // standing directly, independent of any Contact membership. It mirrors
        // exactly what a Contact `leader` produces below — in_group, leader and
        // feed all true, with no participant/ward contacts — which every
        // downstream rule (readableThreadsQuery, constrainToOwnStudents,
        // mayReceiveRecordAbout) already reads as "sees the whole class". This
        // MUST NOT route through membershipsFor()/confirmed(): a teacher has no
        // roster row to confirm. Fires only for a User in this group's staff, so
        // the Contact/guardian branches below stay byte-identical.
        if ($this->isLeaderOf($principal, $group)) {
            return [
                'in_group' => true,
                'leader' => true,
                'feed' => true,
                'participant_contact_ids' => [],
                'ward_contact_ids' => [],
            ];
        }

        $memberships = $this->membershipsFor($principal, $group);

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
