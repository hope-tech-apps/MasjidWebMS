<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Arabic\MarkDrillRequest;
use App\Http\Requests\Admin\Arabic\MasterAllDrillsRequest;
use App\Http\Requests\Admin\Arabic\SaveDailyNoteRequest;
use App\Http\Requests\Admin\Arabic\SetClassStageRequest;
use App\Models\ArabicDailyNote;
use App\Models\ArabicLetterProgress;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\Letters\CurriculumRegistry;
use App\Support\Letters\LetterTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The letter tracker, from the teacher's side.
 *
 * Gated by the CONTACTS permissions like the rest of the classroom module: the
 * roster, the behaviour points and the ḥifẓ diary all sit there, and letter
 * progress is the same kind of record about the same children. Minting a new
 * permission would change the seeded set `RolePermissionBridgeTest` pins.
 *
 * Every read is assembled by `LetterTracker`, and every scope decision is the
 * curriculum's — so the class overview, one child's card and the parent's view
 * cannot disagree about what counts.
 *
 * ## Two alphabets, four routes, no new routes
 *
 * `?alphabet=` on the reads and `alphabet` in the mark body pick the track;
 * absent means Arabic, so every client written before the English track keeps
 * working unchanged. The alternative — a parallel `/english-letters` prefix —
 * would have doubled the route table, the permission entries and the family
 * realm's counted write list to serve the same four questions about the same
 * children.
 *
 * `setStage` is the exception and takes no track: see below.
 */
class ArabicLettersController extends Controller
{
    /** The whole class at a glance, plus the stage ladder. */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $tracker = new LetterTracker(CurriculumRegistry::fromInput($request->query('alphabet')));

        $group = Group::findOrFail($group_id);

        $students = $group->memberships()
            ->participants()->current()
            ->with('contact')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tracker->classOverview($group, $students),
        ], Response::HTTP_OK);
    }

    /** One student's tracker: every letter, its shapes, and every drill. */
    public function show(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $tracker = new LetterTracker(CurriculumRegistry::fromInput($request->query('alphabet')));

        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        return response()->json([
            'status' => 'success',
            'data' => $tracker->forStudent($group, $membership),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Mark one drill for one student.
     *
     * An UPSERT against (student, alphabet, drill), which the unique index
     * enforces — so a teacher tapping twice on a slow connection cannot mint a
     * second cell, and the two tracks cannot overwrite each other.
     */
    public function mark(MarkDrillRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $tracker = LetterTracker::for(
            (string) ($request->validated('alphabet') ?? CurriculumRegistry::ALPHABET_ARABIC)
        );
        $curriculum = $tracker->curriculum();

        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->current()->findOrFail($membership_id);

        $drillId = (string) $request->validated('drill_id');
        $stage = $tracker->stageFor($group);

        // Judged against the CLASS'S stage on the NAMED alphabet. A drill from
        // further up the qāʿidah is not part of this room's denominator, so
        // marking it would put a tick in a cell no progress bar counts and no
        // screen shows — and a drill from the OTHER alphabet is not part of this
        // track at all, so `ba` sent as English and `a` sent as Arabic are both
        // refused here rather than quietly stored where nothing will ever read
        // them.
        if (! $curriculum->isValidDrill($drillId, $stage)) {
            return response()->json([
                'status' => 'error',
                'message' => 'That drill is not part of what this class is working on ('
                    .$curriculum->label().' — '.$tracker->stagePayload($stage)['label'].').',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $row = ArabicLetterProgress::firstOrNew([
            'group_membership_id' => $membership->id,
            'alphabet' => $curriculum->alphabetId(),
            'drill_id' => $drillId,
        ]);

        $row->group_id = $group->id;
        $row->moveTo((string) $request->validated('status'), Auth::id());

        // Only when the key is PRESENT. An absent `note` means the client did
        // not speak about it — every client written before this field existed
        // sends no note, and treating that as "clear it" would erase a teacher's
        // words the first time an older screen marked a drill. A present null or
        // empty string IS a deliberate clear, which a teacher must be able to do.
        if ($request->exists('note')) {
            $note = $request->validated('note');
            $row->note = ($note === null || trim((string) $note) === '') ? null : trim((string) $note);
        }

        $row->save();

        return response()->json([
            'status' => 'success',
            'data' => $tracker->forStudent($group, $membership->load('contact')),
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Mark EVERY drill on one track mastered for one student, in one write.
     *
     * For the child who arrives already knowing their letters (BISS teachers,
     * 2026-09-21). It is the single mark repeated, not a different kind of
     * record: the same `arabic_letter_progress` cells, each moved through
     * `moveTo()`, so `mastered_at` and `marked_by_user_id` mean exactly what
     * they mean when a teacher taps one drill at a time.
     *
     * What it deliberately does NOT do:
     *   - touch a drill that is already mastered. Its first-mastery date and the
     *     name of whoever marked it are history, and re-stamping them would say
     *     this teacher marked it today;
     *   - touch a note. A note is the teacher's words about the child, and this
     *     is a statement about progress;
     *   - reach past the class's stage. "All" is the stage's syllabus, which is
     *     the progress denominator — a drill from further up the qāʿidah would be
     *     a tick no bar counts and no screen shows, the same reason `mark`
     *     refuses one.
     *
     * One transaction, so a failure part-way cannot leave a child two-thirds
     * "mastered" by a button that reported an error.
     */
    public function masterAll(MasterAllDrillsRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $tracker = LetterTracker::for(
            (string) ($request->validated('alphabet') ?? CurriculumRegistry::ALPHABET_ARABIC)
        );
        $curriculum = $tracker->curriculum();

        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->current()->findOrFail($membership_id);

        $drills = $curriculum->syllabus($tracker->stageFor($group));
        $userId = Auth::id();

        $changed = DB::transaction(function () use ($drills, $membership, $group, $curriculum, $userId): int {
            $existing = ArabicLetterProgress::query()
                ->where('group_membership_id', $membership->id)
                ->where('alphabet', $curriculum->alphabetId())
                ->whereIn('drill_id', $drills)
                ->get()
                ->keyBy('drill_id');

            $changed = 0;

            foreach ($drills as $drillId) {
                $row = $existing[$drillId] ?? new ArabicLetterProgress([
                    'group_membership_id' => $membership->id,
                    'alphabet' => $curriculum->alphabetId(),
                    'drill_id' => $drillId,
                ]);

                if ($row->exists && $row->isMastered()) {
                    continue;
                }

                $row->group_id = $group->id;
                $row->moveTo(ArabicCurriculum::STATUS_MASTERED, $userId);
                $row->save();
                $changed++;
            }

            return $changed;
        });

        return response()->json([
            'status' => 'success',
            'message' => $changed === 0
                ? 'Everything on this track was already mastered.'
                : "Marked {$changed} ".($changed === 1 ? 'drill' : 'drills').' mastered.',
            'data' => $tracker->forStudent($group, $membership->load('contact')),
            'meta' => $this->meta() + ['changed' => $changed],
        ], Response::HTTP_OK);
    }

    /**
     * Set how far through the qāʿidah this CLASS is working.
     *
     * The stage belongs to the room, not to thirty children each carrying a
     * number that has to agree with where they sit. Moving it BACK does not
     * delete anything — a drill mastered at a later stage keeps its row and
     * reappears intact when the class moves forward again.
     *
     * ARABIC ONLY. `groups.arabic_stage` is the qāʿidah's ladder and nothing
     * else's; the English track has a single stage, so there is nothing to set,
     * and accepting the call would write a value that silently moves the class's
     * ARABIC denominator from an English screen.
     */
    public function setStage(SetClassStageRequest $request, $masjid_id, $group_id)
    {
        if ($request->validated('alphabet') === CurriculumRegistry::ALPHABET_ENGLISH) {
            return response()->json([
                'status' => 'error',
                'message' => 'The English track has a single stage, so there is nothing to set. '
                    .'This setting is the qāʿidah\'s, and changing it here would move the class\'s Arabic progress.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tracker = LetterTracker::for(CurriculumRegistry::ALPHABET_ARABIC);

        $group = Group::findOrFail($group_id);
        $group->arabic_stage = (string) $request->validated('stage');
        $group->save();

        $students = $group->memberships()->participants()->current()->with('contact')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'This class is now working on '
                .ArabicCurriculum::STAGE_LABELS[$group->arabicStage()].'.',
            'data' => $tracker->classOverview($group->fresh(), $students),
        ], Response::HTTP_OK);
    }

    /**
     * Every daily Arabic note written about one child, newest first.
     *
     * Read-only, and scoped the same way `mark` is: the group is resolved from
     * the route and the membership from the group, so a membership id belonging
     * to another class resolves to a 404 rather than to somebody else's child.
     */
    public function dailyNotes(Request $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->current()->findOrFail($membership_id);

        $notes = ArabicDailyNote::where('group_membership_id', $membership->id)
            ->with('markedBy:id,name')
            ->orderByDesc('session_date')
            ->get()
            ->map(fn (ArabicDailyNote $n) => [
                'id' => $n->id,
                'session_date' => $n->session_date?->toDateString(),
                'note' => $n->note,
                'marked_by' => $n->markedBy?->name,
                'updated_at' => $n->updated_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $notes,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Write (or correct) the note for one child on one day.
     *
     * An UPSERT on (student, day), which the unique index enforces. A teacher
     * writing twice about the same day is correcting themselves, not recording
     * two days — without the upsert the second save is a duplicate and the
     * screen shows whichever row the database happened to return first.
     *
     * `marked_by_user_id` is re-stamped on every write, so the name on the note
     * is whoever last touched it rather than whoever opened the day. That is the
     * honest answer to "who says this", and it matches how a drill mark behaves.
     */
    public function saveDailyNote(SaveDailyNoteRequest $request, $masjid_id, $group_id, $membership_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->current()->findOrFail($membership_id);

        // whereDate(), NOT firstOrNew() on a raw date string. `session_date` is
        // cast to `date`, so the INSERT stores midnight while a firstOrNew()
        // lookup compares the string as given — the row never matches, and the
        // second save collides with the unique index instead of editing. This
        // module has been bitten by exactly that twice before; the register
        // escaped it only by passing a Carbon on both sides. Pinned by
        // `the_daily_note_is_an_upsert_so_a_second_save_corrects_rather_than_duplicates`.
        $on = Carbon::parse((string) $request->validated('session_date'))->startOfDay();

        $note = ArabicDailyNote::where('group_membership_id', $membership->id)
            ->whereDate('session_date', $on->toDateString())
            ->first() ?? new ArabicDailyNote([
                'group_membership_id' => $membership->id,
            ]);

        $note->session_date = $on;
        $note->group_id = $group->id;
        $note->note = trim((string) $request->validated('note'));
        $note->marked_by_user_id = Auth::id();
        $note->save();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $note->id,
                'session_date' => $note->session_date?->toDateString(),
                'note' => $note->note,
                'marked_by' => Auth::user()?->name,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Remove a day's note.
     *
     * A hard delete, not a soft one, and not an empty string. "Nobody wrote
     * about this day" is the absence of a row — the same rule the register and
     * the gradebook hold in this module, where blank is never a status and never
     * a zero. A soft-deleted note would leave the day looking written-about to
     * anything that forgets the scope.
     */
    public function deleteDailyNote(Request $request, $masjid_id, $group_id, $membership_id, $note_id)
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->current()->findOrFail($membership_id);

        $note = ArabicDailyNote::where('group_membership_id', $membership->id)->findOrFail($note_id);
        $note->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => (int) $note_id],
        ], Response::HTTP_OK);
    }

    /**
     * What the SERVER says the limits are, so the screen does not carry its own
     * copy of them.
     *
     * This is not ceremony. `TeacherClass.vue` hardcoded the hifz quality list
     * for two and a half weeks, one of its four values existed nowhere in PHP,
     * and the single outcome that changes what happens next for a child —
     * `repeat` — was unreachable from the teacher's screen the whole time.
     * Nothing failed loudly; the option simply was not there. A note length is a
     * smaller thing to get wrong than a missing outcome, but it fails the same
     * quiet way: a `maxlength` the screen invented, higher than the validator's,
     * turns into a 422 the teacher reads as the app losing what she typed.
     *
     * Only what the screen actually reads. The statuses are deliberately NOT
     * here: the screen needs their LABELS and their cycle order, which are its
     * own business, and shipping a list nothing consumes is how a payload grows
     * a field that quietly stops matching. The drift that list would have
     * guarded against is pinned by `TeacherLetterStatusFallbackTest` instead,
     * which reads the screen.
     *
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        return [
            'max_note_length' => (int) config('groups.arabic.max_note_length', 1000),
            'max_daily_note_length' => (int) config('groups.arabic.max_daily_note_length', 2000),
        ];
    }
}
