<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Schools\SchoolSubjectRequest;
use App\Models\ClassAssignment;
use App\Models\CurriculumWeek;
use App\Models\SchoolSubject;
use App\Support\GradeLevel;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Subjects screen: the school's own list of subjects, kept by the office
 * (T-001.3, "Add a subject to a grade and/or assignment").
 *
 * In the `crm` route group under the CONTACTS permissions, like the class list it
 * belongs beside: `view contacts` reads, `manage contacts` writes, and no
 * permission is minted (`Permission::count()` stays 8).
 *
 * Tenant isolation follows .claude/rules/tenant-scoping.md for a BelongsToMasjid
 * model: nothing here filters by `$masjid_id`, and a subject id from another
 * school is a 404 from the scoped `findOrFail`.
 *
 * ## Editing the list changes no mark
 *
 * Work stores the subject it was filed under as a SNAPSHOT string, never a foreign
 * key to a row here. So renaming a subject, changing its grades or deleting it
 * moves nothing a parent has read: old work keeps the name it was set under. The
 * response says how many pieces of work carry a name so the screen can tell the
 * office before they rename ("N pieces of work are filed under this name").
 *
 * ## Reference, not a fact to invent
 *
 * `guide_subjects` lists the subjects the school's OWN pacing guide names, so the
 * office can see what the guide calls things. It is read-only, and nothing here
 * ever creates a subject from it: which subjects a school teaches is the office's
 * to say.
 */
class SchoolSubjectsController extends Controller
{
    public function index($masjid_id): JsonResponse
    {
        $subjects = SchoolSubject::query()->orderBy('position')->orderBy('name')->get();
        $keys = $subjects->pluck('name_key')->all();

        // How many pieces of work carry each subject's key (live work only).
        $usage = ClassAssignment::query()
            ->whereIn('subject_key', $keys === [] ? ['-'] : $keys)
            ->selectRaw('subject_key, COUNT(*) as n')
            ->groupBy('subject_key')
            ->pluck('n', 'subject_key');

        return response()->json([
            'status' => 'success',
            'data' => $subjects->map(fn (SchoolSubject $s): array => $this->payload($s) + [
                'work_count' => (int) ($usage[$s->name_key] ?? 0),
            ])->values(),
            'meta' => [
                'grade_levels' => GradeLevel::LEVELS,
                'guide_subjects' => CurriculumWeek::query()
                    ->distinct()->orderBy('subject')->pluck('subject')->values(),
            ],
        ], Response::HTTP_OK);
    }

    public function store(SchoolSubjectRequest $request, $masjid_id): JsonResponse
    {
        // masjid_id is stamped by the BelongsToMasjid creating hook from the
        // bound tenant; it is never taken from the request.
        $subject = SchoolSubject::create([
            'name' => $request->validated('name'),
            'grade_labels' => $request->validated('grade_labels'),
            'position' => (int) ($request->validated('position') ?? 0),
        ]);

        return response()->json(['status' => 'success', 'data' => $this->payload($subject)], Response::HTTP_CREATED);
    }

    public function update(SchoolSubjectRequest $request, $masjid_id, $subject_id): JsonResponse
    {
        $subject = SchoolSubject::findOrFail($subject_id);

        $subject->update([
            'name' => $request->validated('name'),
            'grade_labels' => $request->validated('grade_labels'),
            'position' => (int) ($request->validated('position') ?? $subject->position),
        ]);

        return response()->json(['status' => 'success', 'data' => $this->payload($subject->fresh())], Response::HTTP_OK);
    }

    public function destroy($masjid_id, $subject_id): JsonResponse
    {
        $subject = SchoolSubject::findOrFail($subject_id);
        $subject->delete();

        return response()->json(['status' => 'success', 'data' => ['id' => (int) $subject_id]], Response::HTTP_OK);
    }

    /** @return array{id:int, name:string, grade_labels:list<string>|null, position:int} */
    private function payload(SchoolSubject $s): array
    {
        return [
            'id' => (int) $s->id,
            'name' => $s->name,
            'grade_labels' => $s->grade_labels === [] ? null : $s->grade_labels,
            'position' => (int) $s->position,
        ];
    }
}
