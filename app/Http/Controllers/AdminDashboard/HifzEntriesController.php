<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\CorrectHifzEntryRequest;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 *   - WRITING (record / correct / strike / reword a note) is `permission:manage contacts`, exactly like
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
     * GET .../masjids/{masjid_id}/quran-surahs
     *
     * The sūrah index: number, name, and how many āyāt each holds. Reference
     * data, identical for every tenant and every caller, which is why it is not
     * group-scoped and carries no authorization beyond being signed in.
     *
     * It exists so a recitation can be recorded by PICKING A SŪRAH BY NAME
     * instead of typing its number. Hifz.ts states the rule this satisfies —
     * "so a client never carries its own copy" — and a client-side table of 114
     * names and counts would be exactly that copy, free to drift from the one
     * StoreHifzEntryRequest validates against.
     *
     * The āyah counts are what make the form bound its own inputs: a teacher
     * cannot ask for āyah 8 of Al-Fātiḥah before the request is ever sent.
     */
    public function surahs()
    {
        return response()->json([
            'status' => 'success',
            'data' => collect(QuranIndex::SURAHS)
                ->map(fn (array $s, int $number): array => [
                    'number' => $number,
                    'name' => $s['name'],
                    'ayahs' => $s['ayahs'],
                ])
                ->values(),
        ], Response::HTTP_OK);
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
            ->participants()->current()
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
                'recited_at' => $request->filled('recited_at') ? $this->heardAt($request) : now(),
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
     * PUT .../groups/{group_id}/hifz/{entry_id} — rewrite or clear the NOTE.
     *
     * PUT because that is the verb every update in the teacher realm uses (class
     * files, the daily Arabic note); it replaces ONE field, not the entry.
     *
     * The owner, 2026-10-07: "The quran teacher should be able to view and edit
     * their notes on the students hifdh." The note is the one field of an entry
     * that is the teacher's commentary rather than what was heard, so it is the
     * one field edited in place. The portion, the kind, the quality, the
     * mistakes and the day are still corrected by striking and re-recording
     * (destroy): that is what keeps a child's position honest, and nothing this
     * method writes is read by HifzProgress.
     *
     * WHO: whoever may record and strike here, no narrower. The account that
     * heard the recitation is not required. Someone who may strike the whole
     * entry may certainly reword its note, and on the day this shipped every
     * note in production had been typed under one login for a class another
     * teacher leads, so "only the writer" would have refused the very teacher
     * who asked. The edit is accountable all the same: a WARNING line (the
     * level production keeps) names the entry and the account, never the words.
     *
     * `note` must be PRESENT. An absent key is a client that is not speaking
     * about the note (the rule TeacherArabicAndHifzNotesTest pins for every note
     * in this module), which here leaves nothing to do, so it is a 422 and not a
     * silent success. Present and blank is the deliberate clear.
     *
     * A struck entry is a MISS (404): it has left every listing, and a note on
     * it would be written where nobody reads.
     */
    public function updateNote(Request $request, $masjid_id, $group_id, $entry_id)
    {
        $group = Group::findOrFail($group_id);

        $validated = $request->validate([
            'note' => 'present|nullable|string|max:' . (int) config('groups.hifz.max_note_length', 1000),
        ]);

        $note = trim((string) ($validated['note'] ?? ''));
        $note = $note === '' ? null : $note;

        try {
            // One lookup, under a row lock, as in correct(): a strike landing
            // between a lookup and the save would otherwise be given a note and
            // answered as a success.
            $result = DB::transaction(function () use ($group, $entry_id, $note) {
                $entry = $group->hifzEntries()->whereKey((int) $entry_id)->lockForUpdate()->first();

                if ($entry === null) {
                    return null;
                }

                $before = $entry->note;
                $entry->update(['note' => $note]);

                return [$entry, $before];
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($result === null) {
            throw (new ModelNotFoundException())->setModel(HifzEntry::class, [(int) $entry_id]);
        }

        [$entry, $before] = $result;

        if ($entry->note !== $before) {
            Log::warning('Hifdh note edited', [
                'masjid_id' => $entry->masjid_id,
                'group_id' => $entry->group_id,
                'entry_id' => $entry->id,
                'by_user_id' => $request->user()?->id,
                'heard_by_user_id' => $entry->heard_by_user_id,
                'had_note' => filled($before),
                'has_note' => filled($entry->note),
            ]);
        }

        return response()->json([
            'status' => 'success',
            // The writer sees what they just wrote, as in store().
            'data' => $this->serialize($entry->load($this->readEagerLoads())),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/hifz/{entry_id}/correct — correct a recorded line.
     *
     * The owner, 2026-10-07: "Will the teacher be able to edit the date as well or
     * really all aspects of their entry? ... we will need this."
     *
     * WHY THIS IS NOT "STRIKE AND RECORD AGAIN" DONE FOR THE TEACHER. That was the
     * first build, and the pre-ship review broke it: HifzProgress reads a child's
     * position from the LAST sabak by (recited_at, id). A re-recorded line gets a
     * new, higher id, so correcting the quality of the EARLIER of two lines heard
     * at the same moment (every backdated line of a day shares one) made it the
     * later one, and the child's position moved BACKWARDS though no āyah had
     * changed. A correction must keep the entry's place in that order, which
     * means keeping the entry.
     *
     * SO THE ENTRY IS CORRECTED IN PLACE, AND THE HISTORY IS KEPT BESIDE IT. In
     * one transaction: the line as it stood is written as a STRUCK copy
     * (soft-deleted, `corrected_by_user_id` = who corrected it), then the entry
     * itself takes the corrected values. The struck copy is what "strike and
     * record again" used to leave behind, so the audit trail this module was
     * built around is the same; the live entry keeps its id, who heard it, and
     * (unless the day is changed) the moment it was heard.
     *
     * Only the NOTE changed: no struck copy. The note is commentary, edited in
     * place by design (updateNote), and it is logged the same way.
     * Nothing changed: nothing is written.
     *
     * NEVER the student: there is no `membership_id` on this request. A line
     * recorded for the wrong child is struck and recorded for the right one.
     *
     * Same gate as record and strike. A struck entry is a MISS (404).
     */
    public function correct(CorrectHifzEntryRequest $request, $masjid_id, $group_id, $entry_id)
    {
        $group = Group::findOrFail($group_id);

        $heard = [
            'kind' => $request->input('kind'),
            'from_surah' => $request->integer('from_surah'),
            'from_ayah' => $request->integer('from_ayah'),
            'to_surah' => $request->integer('to_surah'),
            'to_ayah' => $request->integer('to_ayah'),
            'quality' => $request->input('quality'),
        ];

        // The mistakes ride along only when a client speaks about them.
        foreach (['major_mistakes', 'minor_mistakes'] as $count) {
            if ($request->has($count)) {
                $heard[$count] = $request->integer($count);
            }
        }

        // ABSENT means "the day was not changed": the entry keeps the moment it
        // was heard, to the second, which is also what keeps its place in the
        // order HifzProgress reads.
        if ($request->filled('recited_at')) {
            $heard['recited_at'] = $this->heardAt($request);
        }

        $note = trim((string) $request->input('note', ''));
        $note = $note === '' ? null : $note;

        try {
            $result = DB::transaction(function () use ($group, $entry_id, $heard, $note, $request) {
                // THE ONE LOOKUP, UNDER A ROW LOCK. Everything below is decided
                // from this row and nothing read earlier, for two reasons the
                // pre-ship review reproduced with a lookup made outside:
                //   - two corrections of one line each copied the ORIGINAL as
                //     "the line as it stood", so the first correction's version
                //     was in no row at all once the second had saved;
                //   - a strike that landed between the lookup and the save was
                //     corrected anyway, by id, and answered as a success.
                // Locked, the second correction waits and then sees the first;
                // a struck line is simply not found (the soft-delete scope).
                $entry = $group->hifzEntries()->whereKey((int) $entry_id)->lockForUpdate()->first();

                if ($entry === null) {
                    return null;
                }

                // The line as it stood, taken before anything is filled.
                $was = $entry->replicate();
                $noteBefore = $entry->note;

                $entry->fill($heard);
                $changed = array_keys($entry->getDirty());
                $struckCopyId = null;

                if ($changed !== []) {
                    // replicate() leaves the timestamps out; a copy of the old line
                    // was first recorded when the line was.
                    $was->created_at = $entry->created_at;
                    $was->corrected_by_user_id = $request->user()?->id;
                    $was->save();
                    $was->delete();
                    $struckCopyId = $was->id;
                }

                $entry->note = $note;
                $noteChanged = $entry->isDirty('note');

                if ($changed !== [] || $noteChanged) {
                    $entry->save();
                }

                return [$entry, $changed, $noteChanged, $struckCopyId, $noteBefore];
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($result === null) {
            // Outside the try: the JSON renderer turns this into the same clean
            // 404 a findOrFail miss is.
            throw (new ModelNotFoundException())->setModel(HifzEntry::class, [(int) $entry_id]);
        }

        [$entry, $changed, $noteChanged, $struckCopyId, $noteBefore] = $result;

        if ($changed !== []) {
            // Field NAMES only: what was heard about a child is not log text.
            Log::warning('Hifdh entry corrected', [
                'masjid_id' => $entry->masjid_id,
                'group_id' => $entry->group_id,
                'entry_id' => $entry->id,
                'struck_copy_id' => $struckCopyId,
                'by_user_id' => $request->user()?->id,
                'heard_by_user_id' => $entry->heard_by_user_id,
                'changed' => $changed,
                'note_changed' => $noteChanged,
            ]);
        } elseif ($noteChanged) {
            Log::warning('Hifdh note edited', [
                'masjid_id' => $entry->masjid_id,
                'group_id' => $entry->group_id,
                'entry_id' => $entry->id,
                'by_user_id' => $request->user()?->id,
                'heard_by_user_id' => $entry->heard_by_user_id,
                'had_note' => filled($noteBefore),
                'has_note' => filled($entry->note),
            ]);
        }

        return response()->json([
            'status' => 'success',
            // The row that was locked and saved, never a re-read by id: fresh()
            // ignores the soft-delete scope and would hand back a struck line.
            'data' => $this->serialize($entry->load($this->readEagerLoads())),
            'meta' => $this->meta() + ['changed' => $changed, 'note_changed' => $noteChanged],
        ], Response::HTTP_OK);
    }

    /**
     * `recited_at` as the instant it names, in the application's time zone.
     *
     * A string with an offset ("2026-10-05T00:30:00-04:00") parses to a moment
     * that carries that offset, and Eloquent writes a datetime's WALL TIME into
     * a column that has no zone: 00:30 was stored for an instant that is 04:30
     * UTC, four hours and, near midnight, a day out. Converted first, the wall
     * time written is the application's own.
     */
    private function heardAt(Request $request): \Carbon\CarbonInterface
    {
        return $request->date('recited_at')->setTimezone(config('app.timezone'));
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
     * accountable. WHAT WAS HEARD is never rewritten WITHOUT A TRACE, on purpose:
     * an in-place edit that left nothing behind would quietly rewrite what a
     * teacher said they heard. So a correction (correct) keeps the old line as a
     * struck copy beside the corrected entry, and the one field edited with no
     * copy is the note, which is commentary and moves nothing (updateNote).
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
            abort(403, 'You are not entitled to this group\'s Hifdh records.');
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

        abort(403, 'You are not entitled to this student\'s Hifdh record.');
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
            // Derived from the range, so a client can say "all of An-Naba"
            // rather than "An-Naba 1 - 40" without its own copy of the counts.
            'whole_surah' => $entry->isWholeSurah(),
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
