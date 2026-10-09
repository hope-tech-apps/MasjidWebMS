<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{ClassSubject, CurriculumWeek, Group, GroupMembership, LessonPlan, Masjid, SubjectNote, SubjectPiece, SubjectPieceMark};
use App\Support\{ClassSubjectMode, GradeLevel, SchoolSettings, SubjectFence, SubjectWorkPage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Teacher writes and office reads share the same tenant/class/subject resolution and payload. */
class SubjectWorkController extends Controller
{
    private function context(Request $request, int $groupId, int $subjectId, bool $lock = false): array
    {
        if ($lock) {
            // The existing subject/roster writers use this mutex order too. No range locks.
            // A consistent SELECT before this lock could freeze an old InnoDB read view.
            $org = Masjid::whereKey(app(\App\Support\TenantContext::class)->get())->lockForUpdate()->firstOrFail();
            abort_unless(SchoolSettings::classSubjectWork($org), 404);
            $group = Group::where('masjid_id', $org->id)->whereKey($groupId)->lockForUpdate()->firstOrFail();
        } else {
            $group = Group::findOrFail($groupId);
            abort_unless(ClassSubjectMode::workEnabled($group->masjid_id), 404);
        }
        abort_unless($group->teachesStudents(), 404);
        $subject = ClassSubject::where('group_id', $group->id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($subjectId);
        if ($request->user()->type === 'Teacher') {
            abort_if($subject->hidden_at !== null, 404);
            $limits = SubjectFence::limitsForWithClassSubjects($request->user(), (int) $group->id);
            abort_unless(SubjectFence::allowsWork($limits, (int) $subject->id), 403);
        }
        return [$group, $subject];
    }

    private function refuseSharing(Request $request): void
    {
        $request->validate(['shared_with_family' => 'sometimes|declined', 'marks.*.shared_with_family' => 'sometimes|declined']);
    }

    public function page(Request $request, $masjid_id, $group_id, $subject_id)
    {
        [$group, $subject] = $this->context($request, (int) $group_id, (int) $subject_id);
        $request->validate(['week_no' => 'sometimes|integer|min:1|max:255', 'grade_label' => 'required_with:week_no|nullable|string|max:32', 'guide_subject' => 'nullable|string|max:64']);
        return response()->json(['status' => 'success', 'data' => SubjectWorkPage::data($group, $subject, $request)]);
    }

    public function notes(Request $request, $masjid_id, $group_id, $subject_id)
    {
        [, $subject] = $this->context($request, (int) $group_id, (int) $subject_id);
        return response()->json(['status' => 'success', 'data' => SubjectWorkPage::notes($subject)]);
    }

    public function createNote(Request $request, $masjid_id, $group_id, $subject_id)
    {
        $note = DB::transaction(function () use ($request, $group_id, $subject_id) {
            [$group, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $this->refuseSharing($request);
            $fields = $request->validate(['body' => 'required|string', 'group_membership_id' => 'nullable|integer|min:1']);
            if (isset($fields['group_membership_id'])) $this->students($group, [$fields['group_membership_id']]);
            return SubjectNote::create(['masjid_id' => $group->masjid_id, 'class_subject_id' => $subject->id,
                'group_membership_id' => $fields['group_membership_id'] ?? null, 'body' => $fields['body'], 'author_user_id' => $request->user()->id]);
        });
        return response()->json(['status' => 'success', 'data' => $note->fresh()], 201);
    }

    public function updateNote(Request $request, $masjid_id, $group_id, $subject_id, $note_id)
    {
        $note = DB::transaction(function () use ($request, $group_id, $subject_id, $note_id) {
            [, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $note = SubjectNote::where('class_subject_id', $subject->id)->lockForUpdate()->findOrFail($note_id);
            $this->refuseSharing($request);
            $fields = $request->validate(['body' => 'required|string']);
            $note->update($fields);
            return $note;
        });
        return response()->json(['status' => 'success', 'data' => $note->fresh()]);
    }

    public function deleteNote(Request $request, $masjid_id, $group_id, $subject_id, $note_id)
    {
        DB::transaction(function () use ($request, $group_id, $subject_id, $note_id) {
            [, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $note = SubjectNote::where('class_subject_id', $subject->id)->lockForUpdate()->findOrFail($note_id);
            $this->refuseSharing($request);
            $note->delete();
        });
        return response()->json(['status' => 'success']);
    }

    public function createPiece(Request $request, $masjid_id, $group_id, $subject_id)
    {
        $piece = DB::transaction(function () use ($request, $group_id, $subject_id) {
            [$group, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $this->refuseSharing($request);
            $fields = $request->validate(['title' => 'required|string|max:255', 'detail' => 'nullable|string']);
            return SubjectPiece::create(['masjid_id' => $group->masjid_id, 'class_subject_id' => $subject->id,
                'source' => 'own', 'created_by_user_id' => $request->user()->id] + $fields);
        });
        return response()->json(['status' => 'success', 'data' => $piece->fresh()], 201);
    }

    public function updatePiece(Request $request, $masjid_id, $group_id, $subject_id, $piece_id)
    {
        $piece = DB::transaction(function () use ($request, $group_id, $subject_id, $piece_id) {
            [, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $piece = SubjectPiece::where('class_subject_id', $subject->id)->where('source', 'own')->lockForUpdate()->findOrFail($piece_id);
            $this->refuseSharing($request);
            $piece->update($request->validate(['title' => 'required|string|max:255', 'detail' => 'nullable|string']));
            return $piece;
        });
        return response()->json(['status' => 'success', 'data' => $piece->fresh()]);
    }

    public function deletePiece(Request $request, $masjid_id, $group_id, $subject_id, $piece_id)
    {
        return DB::transaction(function () use ($request, $group_id, $subject_id, $piece_id) {
            [, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $piece = SubjectPiece::where('class_subject_id', $subject->id)->where('source', 'own')->lockForUpdate()->findOrFail($piece_id);
            $this->refuseSharing($request);
            $fields = $request->validate(['mark_count' => 'required|integer|min:0']);
            $count = SubjectPieceMark::where('subject_piece_id', $piece->id)->count();
            if ($count !== (int) $fields['mark_count']) return response()->json(['status' => 'error', 'message' => 'The number of marks changed. Confirm deletion again.', 'mark_count' => $count], 409);
            $piece->delete();
            return response()->json(['status' => 'success', 'mark_count' => $count]);
        });
    }

    /** Resolve all named students before writing anything; departed history cannot be edited. */
    private function students(Group $group, array $ids)
    {
        $students = GroupMembership::where('group_id', $group->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($students->count() === count($ids), 404);
        if ($students->contains(fn ($s) => $s->left_on !== null || ! in_array($s->role, GroupMembership::PARTICIPANT_ROLES, true))) {
            throw ValidationException::withMessages(['group_membership_id' => ['Choose current students of this class.']]);
        }
        return $students;
    }

    public function saveMarks(Request $request, $masjid_id, $group_id, $subject_id)
    {
        $piece = DB::transaction(function () use ($request, $group_id, $subject_id) {
            [$group, $subject] = $this->context($request, (int) $group_id, (int) $subject_id, true);
            $this->refuseSharing($request);
            $fields = $request->validate([
                'source' => 'required|in:guide,plan,own', 'piece_id' => 'nullable|integer|min:1',
                'guide_subject' => 'nullable|string|max:64', 'grade_label' => 'nullable|string|max:32', 'week_no' => 'nullable|integer|min:1|max:255',
                'lesson_plan_id' => 'nullable|integer|min:1', 'marks' => 'present|array',
                'marks.*.group_membership_id' => 'required|integer|min:1|distinct',
                'marks.*.level' => 'nullable|integer|min:1|max:4', 'marks.*.comment' => 'nullable|string',
            ]);
            $students = $this->students($group, array_column($fields['marks'], 'group_membership_id'));
            [$piece, $snapshot, $identity] = $this->resolvePiece($subject, $fields);
            $grade = $piece?->grade_label ?? ($identity['grade_label'] ?? null);
            if ($fields['source'] === 'guide' && $students->contains(fn ($s) => GradeLevel::key($s->grade_label) !== GradeLevel::key($grade))) {
                throw ValidationException::withMessages(['marks' => ['A curriculum entry can only be marked for students of its grade.']]);
            }
            $meaningful = collect($fields['marks'])->contains(fn ($m) => ($m['level'] ?? null) !== null || filled($m['comment'] ?? null));
            if ($piece === null && ! $meaningful) return null;
            if ($piece === null) {
                // createOrFirst catches the unique violation inside a savepoint, then reads the winner
                // from the WRITE connection. Never lock a missing guide/plan range in InnoDB.
                try {
                    $piece = SubjectPiece::query()->createOrFirst($identity, ['masjid_id' => $group->masjid_id,
                        'source' => $fields['source'], 'created_by_user_id' => $request->user()->id] + $snapshot);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // InnoDB REPEATABLE READ can hide a competing commit from createOrFirst's
                    // consistent read. The duplicate proves this UNIQUE tuple exists; a current
                    // read of that winner is safe. There is no speculative missing-range lock.
                    $piece = SubjectPiece::where($identity)->lockForUpdate()->firstOrFail();
                }
            }
            // Two statements however many students: one upsert on the (piece, student) key, one delete.
            $keep = []; $clear = [];
            foreach ($fields['marks'] as $mark) {
                $level = $mark['level'] ?? null; $comment = filled($mark['comment'] ?? null) ? $mark['comment'] : null;
                if ($level === null && $comment === null) $clear[] = (int) $mark['group_membership_id'];
                else $keep[(int) $mark['group_membership_id']] = ['masjid_id' => $group->masjid_id, 'subject_piece_id' => $piece->id,
                    'group_membership_id' => (int) $mark['group_membership_id'], 'level' => $level, 'comment' => $comment,
                    'marked_by_user_id' => $request->user()->id];
            }
            if ($keep !== []) SubjectPieceMark::upsert(array_values($keep), ['subject_piece_id', 'group_membership_id'], ['level', 'comment', 'marked_by_user_id']);
            if ($clear !== []) SubjectPieceMark::where('subject_piece_id', $piece->id)->whereIn('group_membership_id', $clear)->delete();
            if ($meaningful) $piece->touch();
            return $piece;
        });
        return response()->json(['status' => 'success', 'data' => ['piece_id' => $piece?->id]]);
    }

    /** Live words are consulted only for the very first meaningful save. */
    private function resolvePiece(ClassSubject $subject, array $fields): array
    {
        if (isset($fields['piece_id'])) {
            $piece = SubjectPiece::where('class_subject_id', $subject->id)->where('source', $fields['source'])->findOrFail($fields['piece_id']);
            return [$piece, [], []];
        }
        if ($fields['source'] === 'guide') {
            if (empty($fields['grade_label']) || empty($fields['week_no'])) {
                throw ValidationException::withMessages(['grade_label' => ['Choose a curriculum grade and entry number.']]);
            }
            $followed = $subject->followedGuideSubjects();
            $name = $fields['guide_subject'] ?? (count($followed) === 1 ? $followed[0] : null);
            if ($name === null) throw ValidationException::withMessages(['guide_subject' => ['Choose the curriculum subject for this entry.']]);
            $piece = SubjectPiece::where('class_subject_id', $subject->id)->where('source', 'guide')->where('guide_subject', $name)->where('week_no', $fields['week_no'])
                ->get()->first(fn ($p) => GradeLevel::key($p->grade_label) === GradeLevel::key($fields['grade_label']));
            if ($piece) return [$piece, [], []];
            if (! in_array($name, $followed, true)) throw ValidationException::withMessages(['guide_subject' => ['Choose a curriculum subject this class subject follows.']]);
            $entries = CurriculumWeek::where('subject', $name)->where('week_no', $fields['week_no'])->get()
                ->filter(fn ($g) => GradeLevel::key($g->grade_label) === GradeLevel::key($fields['grade_label']));
            if ($entries->count() !== 1) throw ValidationException::withMessages(['week_no' => ['Choose an unambiguous curriculum entry for this grade.']]);
            $entry = $entries->first();
            $this->snapshotFits(['guide_subject' => $name, 'title' => $entry->focus, 'grade_label' => $entry->grade_label, 'standard_code' => $entry->standard_code]);
            return [null, ['title' => $entry->focus, 'detail' => $entry->assessment_note, 'quarter' => $entry->quarter, 'standard_code' => $entry->standard_code],
                ['class_subject_id' => $subject->id, 'guide_subject' => $name, 'grade_label' => $entry->grade_label, 'week_no' => $entry->week_no]];
        }
        if ($fields['source'] === 'plan' && isset($fields['lesson_plan_id'])) {
            $plan = LessonPlan::where('group_id', $subject->group_id)->where('class_subject_id', $subject->id)->findOrFail($fields['lesson_plan_id']);
            $piece = SubjectPiece::where('class_subject_id', $subject->id)->where('source', 'plan')->where('lesson_plan_id', $plan->id)->first();
            if ($piece) return [$piece, [], []];
            $title = SubjectWorkPage::planTitle($plan);
            $this->snapshotFits(['title' => $title]);
            return [null, ['title' => $title, 'detail' => null], ['class_subject_id' => $subject->id, 'lesson_plan_id' => $plan->id]];
        }
        throw ValidationException::withMessages(['piece_id' => ['Choose an own piece, saved piece, or linked lesson plan.']]);
    }

    private function snapshotFits(array $fields): void
    {
        foreach (['guide_subject' => 64, 'title' => 255, 'grade_label' => 32, 'standard_code' => 32] as $field => $max) {
            if (isset($fields[$field]) && mb_strlen($fields[$field]) > $max) {
                throw ValidationException::withMessages([$field => ["The source {$field} is longer than {$max} characters. Shorten it before marking."]]);
            }
        }
    }
}
