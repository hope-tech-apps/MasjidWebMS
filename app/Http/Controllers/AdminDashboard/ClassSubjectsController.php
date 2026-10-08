<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\ReorderClassSubjectsRequest;
use App\Http\Requests\Admin\Groups\SaveClassSubjectRequest;
use App\Models\ClassSubject;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Support\ClassSubjectInitializer;
use App\Support\SchoolSettings;
use App\Support\SubjectFence;
use App\Support\SubjectKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassSubjectsController extends Controller
{
    private function group(int $id): Group
    {
        $group = Group::findOrFail($id);
        abort_unless($group->teachesStudents(), 404);
        // This feature is dark even for the platform operator (the generic grant gate bypasses them).
        abort_unless(SchoolSettings::classSubjects(SchoolSettings::org($group->masjid_id)), 403);
        return $group;
    }

    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = $this->group((int) $group_id);
        $query = ClassSubject::where('group_id', $group->id)->orderBy('position')->orderBy('id');
        if ($request->user()->type === 'Teacher') {
            $ids = SubjectFence::assignedIds((int) $group->id, (int) $request->user()->id);
            $query->whereNull('hidden_at')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
        }
        return response()->json(['status' => 'success', 'data' => $query->get(), 'meta' => [
            'guide_subjects' => CurriculumWeek::distinct()->orderBy('subject')->pluck('subject'),
            'tools' => ClassSubject::TOOLS,
        ]]);
    }

    public function show(Request $request, $masjid_id, $group_id, $subject_id)
    {
        $group = $this->group((int) $group_id);
        $subject = ClassSubject::where('group_id', $group->id)->findOrFail($subject_id);
        if ($request->user()->type === 'Teacher') {
            $ids = SubjectFence::assignedIds((int) $group->id, (int) $request->user()->id);
            abort_if($subject->hidden_at !== null || ($ids !== null && ! in_array((int) $subject->id, $ids, true)), 404);
        }
        return response()->json(['status' => 'success', 'data' => $subject]);
    }

    public function store(SaveClassSubjectRequest $request, $masjid_id, $group_id)
    {
        $group = $this->group((int) $group_id);
        $subject = DB::transaction(function () use ($group, $request) {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $fields = $request->validated();
            if (! array_key_exists('tool', $fields)) $fields['tool'] = ClassSubjectInitializer::defaultTool(SubjectKey::for($fields['name']));
            if (! array_key_exists('guide_subject', $fields)) {
                $aliases = ClassSubjectInitializer::aliases(SubjectKey::for($fields['name']));
                $matches = CurriculumWeek::distinct()->pluck('subject')->filter(fn ($name) => in_array(SubjectKey::for($name), $aliases, true));
                $fields['guide_subject'] = $matches->count() === 1 ? $matches->first() : null;
            }
            $position = ClassSubject::where('group_id', $group->id)->max('position');
            $position = $position === null ? 0 : $position + 1;
            if ($position > 65535) throw ValidationException::withMessages(['name' => ['Reorder this class\'s subjects before adding another.']]);
            $this->check($group, $fields);
            return ClassSubject::create(['group_id' => $group->id, 'position' => $position] + $fields)->fresh();
        });
        return response()->json(['status' => 'success', 'data' => $subject], 201);
    }

    public function update(SaveClassSubjectRequest $request, $masjid_id, $group_id, $subject_id)
    {
        $group = $this->group((int) $group_id);
        $subject = DB::transaction(function () use ($group, $request, $subject_id) {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $subject = ClassSubject::where('group_id', $group->id)->findOrFail($subject_id);
            $this->check($group, $request->validated(), $subject);
            $subject->update($request->validated());
            return $subject->fresh();
        });
        return response()->json(['status' => 'success', 'data' => $subject]);
    }

    /** Caller holds the class PK mutex before checking names and tools. */
    private function check(Group $group, array $fields, ?ClassSubject $existing = null): void
    {
        $others = ClassSubject::where('group_id', $group->id)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->get();
        if (isset($fields['name'])) {
            $key = SubjectKey::for($fields['name']);
            // Historical keys must stay unambiguous or another subject could expose old work.
            if ($others->contains(fn ($s) => in_array($key, $s->matchingKeys(), true))) {
                throw ValidationException::withMessages(['name' => ['This class already has a subject using that name or a previous name.']]);
            }
        }
        if (isset($fields['tool']) && $others->contains('tool', $fields['tool'])) {
            throw ValidationException::withMessages(['tool' => ['Another subject in this class already holds that tool. Clear its Holds choice first.']]);
        }
        if (isset($fields['guide_subject']) && ! CurriculumWeek::where('subject', $fields['guide_subject'])->exists()) {
            throw ValidationException::withMessages(['guide_subject' => ['Choose a subject from this school\'s curriculum.']]);
        }
    }

    public function reorder(ReorderClassSubjectsRequest $request, $masjid_id, $group_id)
    {
        $group = $this->group((int) $group_id);
        DB::transaction(function () use ($group, $request): void {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $ids = array_map('intval', $request->validated('subject_ids'));
            $current = ClassSubject::where('group_id', $group->id)->pluck('id')->all();
            if (count($ids) !== count($current) || array_diff($ids, $current) !== []) {
                throw ValidationException::withMessages(['subject_ids' => ['Include every subject of this class once, including hidden subjects.']]);
            }
            foreach ($ids as $position => $id) ClassSubject::whereKey($id)->update(['position' => $position]);
        });
        return $this->index($request, $masjid_id, $group_id);
    }

    public function destroy(Request $request, $masjid_id, $group_id, $subject_id)
    {
        return $this->visibility((int) $group_id, (int) $subject_id, true);
    }

    public function restore(Request $request, $masjid_id, $group_id, $subject_id)
    {
        return $this->visibility((int) $group_id, (int) $subject_id, false);
    }

    private function visibility(int $groupId, int $subjectId, bool $hide)
    {
        $group = $this->group($groupId);
        $subject = DB::transaction(function () use ($group, $subjectId, $hide) {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $subject = ClassSubject::where('group_id', $group->id)->findOrFail($subjectId);
            $subject->update(['hidden_at' => $hide ? ($subject->hidden_at ?? now()) : null]);
            return $subject->fresh();
        });
        return response()->json(['status' => 'success', 'data' => $subject]);
    }
}
