<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreHifzEntryRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use App\Support\GroupAudience;
use App\Support\HifzProgress;
use App\Support\QuranIndex;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ḥifẓ tracking — the ḥalaqa's daily record (PLAN T-014).
 *
 * ## What this models
 *
 * Every ḥifẓ classroom, from a full-time academy to a Sunday-morning circle,
 * runs the same cycle: a student recites SABAK (today's new lesson), SABQI
 * (recent memorisation under active revision) and MANZIL (the long rotation over
 * what is already consolidated). The teacher hears each portion and records
 * where it was, how it went, and how many mistakes there were. Today that lives
 * in a notebook. This is that notebook, with two properties a notebook cannot
 * have: it is private per family, and the student's position is derived rather
 * than re-copied.
 *
 * ONLY SABAK ADVANCES A STUDENT. Revision entries never move the position — that
 * is the domain rule the whole progress endpoint turns on, and it is enforced in
 * App\Support\HifzProgress, not here.
 *
 * PROGRESS IS A POSITION, NEVER A PERCENTAGE. Every payload below reports surah,
 * ayah and juz; nothing computes "62% memorised", because that is not a number
 * any ḥalaqa or ijāza recognises.
 *
 * ## THE PRIVACY RULE IS THE SAME ONE T-013 ESTABLISHED
 *
 * A ḥifẓ record is a child's academic record, so it reaches the ḥalaqa's
 * LEADERS, the STUDENT, and THAT student's own GUARDIANS — never another
 * guardian in the same ḥalaqa, never the whole tenant, and never as a class-wide
 * ranking of who has memorised most. Refused at the endpoint (403) AND inside
 * the listing query, so a forbidden entry is never fetched, never counted in a
 * paginator total, and never folded into a memorisation aggregate. The decision
 * lives in App\Support\GroupAudience (mayReceiveHifzAbout / readableHifzQuery),
 * never inline here — and it is literally the same code path the behaviour
 * awards use, so the two cannot drift apart. See .claude/rules/groups.md.
 *
 * There is deliberately no group-wide progress endpoint. A leader's listing is a
 * list of per-student rows they are already entitled to; a "top memorisers"
 * board is the same public shaming T-013 refused, aimed at Qur'an.
 *
 * ## The two gates, mirroring the feed
 *
 *   - WRITING (record / strike) is `permission:manage contacts`, exactly like
 *     the roster endpoints beside it: the accountable administrator acts, and
 *     the entry records WHICH account heard it (`heard_by_user_id`) or corrected
 *     it (`corrected_by_user_id`). An admin who is not on the roster can
 *     therefore record and NOT read the record back — the feed's deliberate
 *     read/write asymmetry, unchanged here because hearing a recitation is
 *     teaching, which is administration of the ḥalaqa.
 *   - READING additionally requires standing in the group, decided by
 *     GroupAudience. `view contacts` is held by every masjid admin, so gating a
 *     child's memorisation record on it alone would publish it to the whole
 *     tenant, which .claude/rules/groups.md obligation 4 forbids.
 *
 * Tenant isolation is not hand-rolled: the `tenant` middleware binds
 * TenantContext and BelongsToMasjid auto-scopes Group, GroupMembership and
 * HifzEntry — a foreign organization's id anywhere in the chain is a MISS (404),
 * never a filtered row. findOrFail stays OUTSIDE every try/catch so the JSON
 * renderer turns it into a clean 404. See .claude/rules/tenant-scoping.md.
 */
class HifzEntriesController extends Controller
{
    public function __construct(private GroupAudience $audience)
    {
    }

    /**
     * GET .../groups/{group_id}/hifz[?kind=&from=&to=&membership_id=&per_page=]
     *
     * The ḥalaqa's recitation log, newest first, PRE-FILTERED to what this
     * caller may read. A leader sees the ḥalaqa; anybody else sees only their
     * own record and their own wards' — which is why this same endpoint is safe
     * to hand to a parent, and why there is no separate "my child" route to keep
     * in sync.
     *
     * `membership_id` narrows to one student. It is a filter over an already
     * constrained query, NOT an access decision: passing another family's
     * membership id yields an empty page rather than a leak, and the dedicated
     * per-student route below is the one that answers 403 instead.
     */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $entries = $this->applyFilters($this->readableEntries($request->user(), $group), $request)
            ->with($this->readEagerLoads())
            ->orderByDesc('recited_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 25))
            ->through(fn (HifzEntry $entry) => $this->serialize($entry));

        return response()->json([
            'status' => 'success',
            'data' => $entries,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/members/{membership_id}/hifz[?kind=&from=&to=]
     *
     * ONE student's recitation diary. The endpoint answers 403 for a caller who
     * is not a leader, that student, or that student's guardian — and the
     * listing is additionally constrained by the same rule, so the two can never
     * disagree. Both gates are deliberate: the 403 is honest to a parent who
     * mistyped an id, and the query constraint is what makes the honesty safe.
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $this->authorizeSubject($request->user(), $group, $membership);

        $entries = $this->applyFilters($this->readableEntries($request->user(), $group), $request)
            ->where('group_membership_id', $membership->id)
            ->with($this->readEagerLoads())
            ->orderByDesc('recited_at')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 25))
            ->through(fn (HifzEntry $entry) => $this->serialize($entry));

        return response()->json([
            'status' => 'success',
            'data' => $entries,
            'meta' => $this->meta() + ['student' => $this->student($membership)],
        ], Response::HTTP_OK);
    }

    /**
     * GET .../groups/{group_id}/members/{membership_id}/hifz/progress[?window=]
     *
     * WHERE THIS STUDENT IS — the report a teacher opens before the ḥalaqa and a
     * parent reads about their own child: current position in the muṣḥaf, how
     * much is memorised, which juz are complete, and what has actually been
     * revised lately.
     *
     * Every figure is DERIVED from the entries; nothing is stored. See
     * App\Support\HifzProgress for why, and for why the position comes from the
     * LATEST sabak rather than the furthest.
     *
     * NO from/to here, unlike the listings, and that is deliberate: memorisation
     * is cumulative, so a position "between March and June" would describe a
     * student who does not exist. `window` bounds the REVISION block only —
     * "what has been revised in the last N days" — which is the one part of this
     * report that is genuinely about a period.
     */
    public function progress(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->findOrFail($membership_id);

        $this->authorizeSubject($request->user(), $group, $membership);

        $summary = HifzProgress::summarize(
            // Constrained by the audience rule FIRST, then narrowed to this
            // student: the aggregate can only ever be computed over rows the
            // caller was already entitled to.
            $this->readableEntries($request->user(), $group)
                ->where('group_membership_id', $membership->id),
            $this->revisionWindow($request)
        );

        return response()->json([
            'status' => 'success',
            'data' => ['student' => $this->student($membership)] + $summary,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/hifz
     *
     * Record one recitation. The subject must be a PARTICIPANT of THIS ḥalaqa: a
     * guardian edge names a relationship, not a person who recites, and a
     * membership from another group (or tenant) is invisible to the scoped
     * lookup. Both are 422 rather than 404 — the id arrived in the payload, not
     * the path, so no resource is being addressed, and a 404 would confirm which
     * ids exist elsewhere.
     *
     * The range has already been checked against the muṣḥaf itself by
     * StoreHifzEntryRequest — real ayahs, running forwards — so nothing here has
     * to re-litigate it.
     *
     * masjid_id is intentionally absent from the payload: the BelongsToMasjid
     * creating hook stamps it from the bound tenant.
     */
    public function store(StoreHifzEntryRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $membership = $group->memberships()
            ->participants()
            ->find($request->integer('membership_id'));

        if ($membership === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'That id names no participant of this group, so no recitation can be recorded for them.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $entry = HifzEntry::create([
                'group_id' => $group->id,
                'group_membership_id' => $membership->id,
                // The AUTHENTICATED account heard it, never a client-supplied
                // teacher — the same call T-013 made for awarded_by_user_id.
                'heard_by_user_id' => $request->user()?->id,
                'kind' => $request->input('kind'),
                'from_surah' => $request->integer('from_surah'),
                'from_ayah' => $request->integer('from_ayah'),
                'to_surah' => $request->integer('to_surah'),
                'to_ayah' => $request->integer('to_ayah'),
                'quality' => $request->input('quality'),
                'major_mistakes' => $request->integer('major_mistakes'),
                'minor_mistakes' => $request->integer('minor_mistakes'),
                'note' => $request->input('note'),
                'recited_at' => $request->filled('recited_at') ? $request->date('recited_at') : now(),
            ]);

            return response()->json([
                'status' => 'success',
                // The writer sees what they just wrote: they supplied it a
                // moment ago, so echoing it discloses nothing new.
                'data' => $this->serialize($entry->load($this->readEagerLoads())),
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE .../groups/{group_id}/hifz/{entry_id} — strike a mis-recorded entry.
     *
     * A teacher tapped the wrong student, or typed 2:255 when they meant 2:225.
     * CORRECTION MATTERS MORE HERE THAN ANYWHERE ELSE IN THE MODULE, because the
     * position is derived: a wrong sabak entry does not merely sit in a log, it
     * moves the child's recorded place in the muṣḥaf until it is struck.
     *
     * The entry leaves every listing, every total and every derivation at once
     * through the ordinary soft-delete scope, and `corrected_by_user_id` records
     * who made the correction — a change to a child's academic record is itself
     * accountable. There is no update endpoint on purpose: striking and
     * re-recording leaves an audit trail where an in-place edit would quietly
     * rewrite what a teacher said they heard.
     *
     * Administration, so `manage contacts` alone, with no read gate — the same
     * call as revoking an award. Idempotent: striking an already-struck entry
     * reports it struck rather than erroring, because the caller's intent is
     * already true.
     */
    public function destroy(Request $request, $masjid_id, $group_id, $entry_id)
    {
        $group = Group::findOrFail($group_id);
        $entry = $group->hifzEntries()->withTrashed()->findOrFail($entry_id);

        if (! $entry->isCorrected()) {
            // Saved BEFORE the soft delete, and separately: runSoftDelete()
            // writes only deleted_at/updated_at, so a dirty attribute riding
            // along on delete() would be silently dropped.
            $entry->forceFill(['corrected_by_user_id' => $request->user()?->id])->save();
            $entry->delete();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $entry->id,
                // deleted_at IS the correction clock; the model instance carries
                // it whether it was already struck or was struck just now.
                'corrected_at' => optional($entry->deleted_at)->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The caller's readable slice of this ḥalaqa's entries, or a 403.
     *
     * 403, not 404, for the same reason as the feed, the threads and the awards:
     * the group itself is addressable by this admin (they can see it in the
     * groups list and manage its roster), so pretending it holds no records
     * would be a lie. 404 stays reserved for an id belonging to another
     * organization.
     */
    private function readableEntries(?User $user, Group $group): Builder
    {
        $query = $this->audience->readableHifzQuery($user, $group);

        if ($query === null) {
            abort(403, 'You are not entitled to this group\'s hifz records.');
        }

        return $query;
    }

    /**
     * Refuse a student's record to a caller who is not a leader, that student,
     * or that student's guardian.
     *
     * THIS IS THE ENDPOINT HALF OF THE PRIVACY GUARANTEE; readableHifzQuery() is
     * the listing half. Another guardian in the same ḥalaqa is exactly who both
     * halves exist to refuse.
     */
    private function authorizeSubject(?User $user, Group $group, GroupMembership $subject): void
    {
        if ($this->audience->mayReceiveHifzAbout($user, $group, $subject)) {
            return;
        }

        abort(403, 'You are not entitled to this student\'s hifz record.');
    }

    /**
     * Kind, date-range and student filters, applied over an ALREADY constrained
     * query — never as a substitute for one. `from`/`to` read `recited_at`,
     * which is when the recitation happened, not when it was typed in. An
     * unrecognized `kind` matches nothing rather than everything (see
     * HifzEntry::scopeOfKind) — a typo must not silently widen a listing.
     */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->recitedBetween($request->query('from'), $request->query('to'))
            ->when(
                // is_string, not filled(): `?kind[]=sabak` would otherwise reach
                // a string cast. A malformed filter must be inert, not fatal.
                is_string($request->query('kind')) && $request->query('kind') !== '',
                fn (Builder $q) => $q->ofKind((string) $request->query('kind'))
            )
            ->when(
                $request->filled('membership_id'),
                fn (Builder $q) => $q->where('group_membership_id', $request->integer('membership_id'))
            );
    }

    /**
     * How many days of revision the progress report covers. Bounded so a client
     * cannot ask for a window that makes "recently revised" meaningless, and
     * defaulted from config rather than hardcoded — a full-time academy and a
     * weekend circle rotate on very different cadences.
     */
    private function revisionWindow(Request $request): int
    {
        $default = max(1, (int) config('groups.hifz.revision_window_days', 30));

        if (! $request->filled('window')) {
            return $default;
        }

        return max(1, min(365, $request->integer('window')));
    }

    /** @return array<int,string> */
    private function readEagerLoads(): array
    {
        return [
            'membership.contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS,
            'heardBy:id,name',
        ];
    }

    /**
     * One entry as an entitled reader sees it.
     *
     * The student is included: anyone entitled to see an entry is entitled to
     * know whose recitation it was — that is what the record IS, and the
     * audience rule already guaranteed they may know.
     *
     * The range is served as two DESCRIBED coordinates (number, name, ayah,
     * juz) rather than four bare integers, so a client never has to carry its
     * own copy of the muṣḥaf to render "An-Naba 1 - 40, juz 30". `juz` is
     * derived on the way out, which is why no juz column exists to disagree
     * with it.
     *
     * @return array<string,mixed>
     */
    private function serialize(HifzEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'group_id' => $entry->group_id,
            'student' => $entry->membership ? $this->student($entry->membership) : null,
            'kind' => $entry->kind(),
            'from' => QuranIndex::describe((int) $entry->from_surah, (int) $entry->from_ayah),
            'to' => QuranIndex::describe((int) $entry->to_surah, (int) $entry->to_ayah),
            'ayahs' => $entry->ayahCount(),
            'quality' => $entry->quality(),
            'major_mistakes' => (int) $entry->major_mistakes,
            'minor_mistakes' => (int) $entry->minor_mistakes,
            'note' => $entry->note,
            'recited_at' => optional($entry->recited_at)->toIso8601String(),
            'heard_by' => $entry->heardBy
                ? ['id' => $entry->heardBy->id, 'name' => $entry->heardBy->name]
                : null,
            'corrected_at' => optional($entry->deleted_at)->toIso8601String(),
            'created_at' => optional($entry->created_at)->toIso8601String(),
        ];
    }

    /**
     * The student a record concerns, as the membership edge that names them.
     *
     * @return array<string,mixed>
     */
    private function student(GroupMembership $membership): array
    {
        $contact = $membership->contact;

        return [
            'membership_id' => $membership->id,
            'contact' => $contact ? [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                // The child's own face. Null when unchosen — the client draws
                // initials rather than showing somebody else's avatar.
                'avatar' => $contact->avatar,
            ] : null,
        ];
    }

    /**
     * Vertical-aware labelling, same source as the other group controllers:
     * what a group is CALLED comes from the tenant's terminology pack, never a
     * hardcoded string — "Halaqat" for a masjid, "Classrooms" for a school. See
     * .claude/rules/verticals.md.
     *
     * The kind and quality vocabularies ride along so a client renders the
     * pickers from the server's constants instead of its own copy.
     *
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
            'kinds' => HifzEntry::KINDS,
            'qualities' => HifzEntry::QUALITIES,
            'surah_count' => count(QuranIndex::SURAHS),
            'total_ayahs' => QuranIndex::TOTAL_AYAHS,
            'max_note_length' => (int) config('groups.hifz.max_note_length', 0),
            'max_mistakes' => (int) config('groups.hifz.max_mistakes', 0),
        ];
    }
}
