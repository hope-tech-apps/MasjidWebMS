<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every authenticated family read endpoint shares (T-015e).
 *
 * ---------------------------------------------------------------------------
 * Why these controllers exist at all, rather than reusing the admin ones
 * ---------------------------------------------------------------------------
 *
 * The DECISION is shared — every subclass asks `App\Support\GroupAudience` and
 * nothing else, which is what .claude/rules/groups.md requires ("the ONLY place
 * that answers may this caller receive this disclosure"). What is NOT shared is
 * the payload, and deliberately so:
 *
 *   - the admin payloads are pinned byte-for-byte by GroupFeedTest,
 *     GroupMessagingTest, BehaviorAwardsTest and HifzTrackingTest, and the
 *     family realm must not be able to move a staff response by editing a
 *     parent's;
 *   - a parent's payload is a NARROWER disclosure. It carries no roster, no
 *     other family's child, no `notes` off the contact record, no
 *     `behavior_skill_id` provenance and no staff-only affordances. Least
 *     disclosure is a property of the payload (.claude/rules/groups.md), so the
 *     payload is built by hand for the audience that receives it.
 *
 * ---------------------------------------------------------------------------
 * THE REALM IS READ-ONLY
 * ---------------------------------------------------------------------------
 *
 * There is no POST, PUT, PATCH or DELETE anywhere under it. A parent replying in
 * a thread is T-015f, which needs `group_messages.author_contact_id` and a
 * dual-principal `group_thread_reads` — the read-bookmark table's `user_id` is
 * NOT NULL and points at `users`, so this slice cannot write one and does not
 * pretend to (no `unread` flag is served; see the threads controller).
 * Withdrawing consent is T-015h. Both are deliberately absent rather than
 * half-built.
 */
abstract class FamilyController extends Controller
{
    /** Hard ceiling on ?per_page= so one request cannot drain a group's records. */
    private const MAX_PER_PAGE = 100;

    public function __construct(protected GroupAudience $audience)
    {
    }

    /**
     * The authenticated parent.
     *
     * `family.active` has already established this, and `request()->user()`
     * would do. It is re-checked here because a controller in this realm must
     * fail closed on its own evidence rather than trusting its middleware stack
     * to be mounted the way the route file says it is — the same call
     * MeController makes and for the same reason: the failure it prevents is a
     * null reaching the reads below as a 500 and a stack trace instead of a
     * refusal.
     */
    protected function contact(): Contact
    {
        $contact = request()->user();

        if (! $contact instanceof Contact) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        return $contact;
    }

    /**
     * One group of the BOUND tenant, or a 404.
     *
     * No hand-written `where('masjid_id', …)`: `Group` is BelongsToMasjid and
     * `family.tenant` has bound the context, so another organisation's id is a
     * MISS rather than a filtered row. .claude/rules/tenant-scoping.md forbids
     * re-implementing that filter by hand — a filter can be forgotten, the scope
     * cannot.
     */
    protected function group($groupId): Group
    {
        return Group::findOrFail($groupId);
    }

    /**
     * Refuse a disclosure this parent is not entitled to.
     *
     * 403 rather than 404, matching the staff surface: the id may well name a
     * real group, and pretending it does not exist would be a lie that makes the
     * API harder to reason about. 404 stays reserved for what it means
     * everywhere else — an id belonging to another organisation, which the
     * tenant scope makes invisible before any of this runs.
     */
    protected function authorizeDisclosure(Group $group, string $disclosure): void
    {
        if ($this->audience->mayReceive($this->contact(), $group, $disclosure)) {
            return;
        }

        abort(Response::HTTP_FORBIDDEN, $disclosure === GroupAudience::DISCLOSURE_MEDIA
            ? 'You are not entitled to the images in this group.'
            : 'You are not entitled to read this group\'s feed.');
    }

    /**
     * The participant membership `$membershipId` names in `$group`, if this
     * parent may read records about that person — 404 for an id that is not in
     * this group, 403 for a child that is not theirs.
     *
     * The 403/404 split is honest and safe at the same time, which is the point
     * the behaviour and ḥifẓ modules already make: the 403 is honest to a parent
     * who mistyped an id, and the QUERY constraint underneath (every listing
     * runs through `readableAwardsQuery` / `readableHifzQuery`) is what makes the
     * honesty cost nothing — a forbidden row is never fetched, so it cannot
     * surface in a page, a total or an aggregate even if a controller forgot to
     * ask.
     */
    protected function subject(Group $group, $membershipId): GroupMembership
    {
        $membership = $group->memberships()->findOrFail($membershipId);

        if (! $this->audience->mayReceiveAwardsAbout($this->contact(), $group, $membership)) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this student\'s record.');
        }

        return $membership;
    }

    /**
     * One student, as a parent sees them: the membership id they address the
     * per-child endpoints with, and a name.
     *
     * @return array<string,mixed>
     */
    protected function student(GroupMembership $membership): array
    {
        $contact = $membership->contact;

        return [
            'membership_id' => (int) $membership->id,
            'contact' => $contact ? [
                'id' => (int) $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ] : null,
        ];
    }

    /**
     * Vertical-aware labelling, the same source the staff controllers use: what
     * a group is CALLED comes from the tenant's terminology pack
     * ("Halaqat"/"Classrooms"/"Teams"), never a hardcoded string. A parent app
     * that said "Classroom" to a masjid would be the same bug
     * (.claude/rules/verticals.md).
     *
     * @return array<string,mixed>
     */
    protected function meta(array $extra = []): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
        ] + $extra;
    }

    /**
     * Page size: the caller's ?per_page= clamped to 1..MAX_PER_PAGE.
     *
     * Two defects this closes, both on the surface that serves children's
     * behaviour and hifz records:
     *
     *  - `?per_page[]=5` reached LengthAwarePaginator as an array and raised
     *    "Unsupported operand types: int / array" — a 500 from an authenticated
     *    parent route, on demand.
     *  - `?per_page=1000000` was honoured, so one request drained a whole
     *    group's feed or thread list in a single page.
     *
     * The staff-side AppointmentRequestsController closed exactly this and
     * argued it from disclosure; the same argument applies here with more force,
     * and these five call sites are the ones it did not cover.
     */
    protected function perPage(Request $request, int $default): int
    {
        $requested = $request->query('per_page');

        // Non-scalar (?per_page[]=) is not a number and must not be cast; fall
        // back rather than 500.
        $requested = is_scalar($requested) ? (int) $requested : $default;

        if ($requested < 1) {
            $requested = $default;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
