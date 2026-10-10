<?php

namespace App\Support;

use App\Models\GroupStaff;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Only access specific to the subject they're teaching" (owner, 2026-09-21),
 * applied to the GRADEBOOK and to LESSON PLANS (2026-09-29, W3).
 *
 * Until now the promise held only for the Arabic letters and hifdh, through the
 * `teacher.teaches:` middleware. Grades sat outside both fences, so a BISS teacher
 * limited to Qur'an could list, set, edit and mark Arabic work. This is the same
 * rule for work that carries a subject.
 *
 * ## The rule
 *
 * A teacher whose assignment to a class lists subjects (`group_staff.subjects`)
 * is LIMITED to them; NULL or an empty list is unrestricted, exactly as
 * GroupStaff::teaches() reads it. A limited teacher may touch a piece of work
 * only when its subject maps to a staff subject they teach
 * (SubjectKey::staffKeys). So a Qur'an-only teacher gets "Qur'an" and the
 * school's combined "Qur'an & Islamic Studies" column, and does not get Arabic
 * Language, Islamic Studies alone, or Mathematics. Work with NO subject belongs to
 * no staff subject, so it is hidden from a limited teacher and they cannot create
 * it: a limited teacher must always name the subject, which is what stops "leave
 * the subject blank" from being a way round the fence.
 *
 * ## Who is limited
 *
 * Only a signed-in TEACHER: the office reads the same controller through the
 * admin realm (read-only) and must see everything, so an admin who also holds a
 * group_staff row is never fenced when acting as the office.
 *
 * ## LESSON PLANS are looser on one point, deliberately
 *
 * A plan with no subject is "the day's general plan", first-class since the
 * feature shipped (BISS uses no pacing guide and writes only those). Fencing it
 * would strand every existing general plan. So a limited teacher keeps the general
 * plan and is fenced only on plans that name a subject. Recorded in DECISIONS.md.
 */
final class SubjectFence
{
    /**
     * What `$user` is limited to in `$groupId`: a list of staff subjects, or NULL
     * for everything.
     *
     * @return list<string>|array{class_subject_ids:list<int>,keys:list<string>}|null
     */
    public static function limitsFor(?User $user, int $groupId): ?array
    {
        if (ClassSubjectMode::responseForGroup($groupId)) {
            return self::limitsForWithClassSubjects($user, $groupId);
        }

        if ($user === null || $user->type !== 'Teacher') {
            return null;
        }

        return self::assigned($groupId, (int) $user->getKey());
    }

    public static function limitsForWithClassSubjects(?User $user, int $groupId): ?array
    {
        if ($user === null || $user->type !== 'Teacher') {
            return null;
        }

        $group = \App\Models\Group::find($groupId);
        if ($group === null) return ['class_subject_ids' => [], 'keys' => []];
        return self::limitsForIds(self::assignedIds($groupId, (int) $user->getKey()), $group);
    }

    /** ON only: kind changes never erase an existing subject authority. */
    public static function usesClassSubjects(\App\Models\Group $group): bool
    {
        return $group->teachesStudents() || \App\Models\ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->exists();
    }

    /**
     * The staff subjects one assignment lists, NULL when it lists none (which
     * means all). The single reader of `group_staff.subjects`, shared with the
     * `my_subjects` field of the teacher's class payload.
     *
     * @return list<string>|null
     */
    public static function assigned(int $groupId, int $userId): ?array
    {
        $subjects = GroupStaff::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->value('subjects');

        if (is_string($subjects)) {
            $subjects = json_decode($subjects, true);
        }

        return is_array($subjects) && $subjects !== [] ? array_values($subjects) : null;
    }

    /** The stored ID list may express all explicitly; malformed values never do. */
    public static function validStoredIds(mixed $ids): bool
    {
        if ($ids === null) return true;
        if (! is_array($ids) || ! array_is_list($ids)) return false;
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) return false;
            if ((int) $id < 1 || (string) (int) $id !== ltrim((string) $id, '0')) return false;
        }
        return true;
    }

    /** Only NULL means all. Empty, unresolved and unmapped restrictions fail closed. */
    public static function assignedIds(int $groupId, int $userId): ?array
    {
        $row = GroupStaff::where('group_id', $groupId)->where('user_id', $userId)->first();
        if ($row === null) return [];
        if (\Illuminate\Support\Facades\DB::connection()->transactionLevel() > 0) {
            $row = GroupStaff::whereKey($row->id)->lockForUpdate()->first();
            if ($row === null) return [];
        }
        return self::assignedIdsForRow($row);
    }

    /** Effective ON authority for a current assignment, shared by the fence and disable. */
    public static function assignedIdsForRow(GroupStaff $row): ?array
    {
        // A late OFF INSERT has default NULL IDs without ever choosing all. Its
        // provenance is absent; no legacy read or automatic translation follows.
        if ($row->class_subjects_mapped_at === null && $row->class_subject_ids_edited_at === null) return [];
        if ($row->class_subject_ids === null) return null;
        if (! self::validStoredIds($row->class_subject_ids)) return [];
        return \App\Models\ClassSubject::where('masjid_id', $row->masjid_id)->where('group_id', $row->group_id)
            ->whereIn('id', $row->class_subject_ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Resolve once per fence, never once per assignment, plan or curriculum row. */
    public static function limitsForIds(?array $ids, \App\Models\Group $group, bool $curriculum = false): ?array
    {
        if ($ids === null) return null;
        if (! self::validStoredIds($ids)) $ids = [];
        $subjects = \App\Models\ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->whereIn('id', $ids)->get();
        $keys = $subjects->flatMap(fn ($s) => $curriculum ? $s->curriculumKeys() : [$s->name_key])->unique()->values()->all();
        return ['class_subject_ids' => $subjects->pluck('id')->map(fn ($id) => (int) $id)->all(), 'keys' => $keys];
    }

    /** The ON predicate. General lesson plans alone retain shared class access. */
    public static function allowsWork(?array $limits, ?int $classSubjectId, bool $generalPlan = false): bool
    {
        return $limits === null || ($generalPlan && $classSubjectId === null)
            || ($classSubjectId !== null && in_array($classSubjectId, $limits['class_subject_ids'] ?? [], true));
    }

    /** Report subjects absent from the class stay shared; hidden ones require unlimited authority. */
    public static function allowsReportSubject(?array $limits, ?\App\Models\ClassSubject $subject): bool
    {
        return $limits === null || $subject === null
            || ($subject->hidden_at === null && self::allowsWork($limits, (int) $subject->id));
    }

    /** Refuse the whole report save before any line or overall comment is written. */
    public static function assertReportLines(\App\Models\Group $group, \Illuminate\Support\Collection $rows, array $submitted, ?User $user): void
    {
        $limits = self::limitsForWithClassSubjects($user, (int) $group->id);
        if ($limits === null) return;
        $subjects = \App\Models\ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->get();
        $names = $subjects->whereIn('id', $limits['class_subject_ids'])->pluck('name')->implode(', ');
        if ($names === '') $names = 'no subjects';
        $owned = $rows->keyBy('id');
        foreach ($submitted as $row) {
            $line = $owned->get((int) ($row['id'] ?? 0));
            if ($line === null || $line->kind !== \App\Models\ReportCardMark::KIND_ACADEMIC) continue;
            if (! self::allowsReportSubject($limits, ReportCardSubjectWork::match($line->subject, $subjects))) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'status' => 'error', 'message' => "You teach {$names} in this class. {$line->subject} is filled in by its own teacher.",
                ], Response::HTTP_FORBIDDEN));
            }
        }
    }

    /** The SQL form of the same predicate, for lists and grade arithmetic. */
    public static function scopeWork($query, ?array $limits, string $column = 'class_subject_id')
    {
        return $limits === null ? $query : $query->whereIn($column, $limits['class_subject_ids'] ?? []);
    }

    /** Resolve a picker choice, then authorize its immutable ID. No old-name lookup. */
    public static function resolveChoice(\App\Models\Group $group, ?string $name, mixed $id, bool $idSupplied, ?array $limits): \App\Models\ClassSubject
    {
        $subjects = ClassSubjectInitializer::currentSubjects($group);
        $matches = $subjects->filter(fn ($s) => $idSupplied ? (int) $s->id === (int) $id
            : in_array(SubjectKey::for($name), ClassSubjectInitializer::aliases($s->name_key), true));
        $chosen = $matches->count() === 1 ? $matches->first() : null;
        if ($chosen === null || $chosen->hidden_at !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['subject' => ['Choose a visible subject of this class.']]);
        }
        if (! self::allowsWork($limits, (int) $chosen->id)) self::refuse($name);
        return $chosen;
    }

    /** Fields are additive: the legacy `my_subjects` still describes legacy assignments. */
    public static function payload(\App\Models\Group $group, ?User $user): array
    {
        if (! ClassSubjectMode::responseEnabled($group->masjid_id) || ! self::usesClassSubjects($group)) return [];
        $ids = $user?->type === 'Teacher' ? self::assignedIds((int) $group->id, (int) $user->id) : null;
        $subjects = \App\Models\ClassSubject::where('group_id', $group->id)->whereNull('hidden_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('position')->orderBy('id')->get();
        $payload = ['class_subjects_enabled' => true, 'class_subjects' => $subjects, 'my_class_subject_ids' => $ids];
        if (ClassSubjectMode::workEnabled($group->masjid_id)) $payload['class_subject_work_enabled'] = true;
        if (ClassSubjectMode::reportSummaryEnabled($group->masjid_id)) $payload['class_subject_report_summary_enabled'] = true;
        return $payload;
    }

    /**
     * May `$user` change how much each type of work counts in `$groupId`?
     *
     * The weights are a policy of the whole class and move every subject's average,
     * so only someone who is not limited to some subjects may: an unrestricted
     * teacher of the class, or the office (anyone who is not a Teacher, which
     * `limitsFor` never limits). A Teacher limited to some subjects may not.
     */
    public static function mayWeighClass(?User $user, int $groupId): bool
    {
        return self::limitsFor($user, $groupId) === null;
    }

    /** May a teacher with these limits touch work whose subject key is `$subjectKey`? */
    public static function allows(?array $limits, ?string $subjectKey): bool
    {
        if ($limits !== null && array_key_exists('class_subject_ids', $limits)) {
            return self::allowsWithClassSubjects($limits, $subjectKey);
        }

        if ($limits === null) {
            return true;
        }

        return array_intersect(SubjectKey::staffKeys((string) $subjectKey), $limits) !== [];
    }

    public static function allowsWithClassSubjects(?array $limits, ?string $subjectKey): bool
    {
        if ($limits === null) {
            return true;
        }

        if (array_key_exists('class_subject_ids', $limits)) {
            return in_array((string) $subjectKey, self::allowedKeys($limits), true);
        }

        return array_intersect(SubjectKey::staffKeys((string) $subjectKey), $limits) !== [];
    }

    /**
     * The subject keys a query may include for these limits; NULL for no filter.
     * Used as `whereIn('subject_key', ...)`.
     *
     * @return list<string>|null
     */
    public static function allowedKeys(?array $limits): ?array
    {
        if ($limits !== null && array_key_exists('class_subject_ids', $limits)) {
            return self::allowedKeysWithClassSubjects($limits);
        }

        return $limits === null ? null : SubjectKey::keysFor($limits);
    }

    public static function allowedKeysWithClassSubjects(?array $limits): ?array
    {
        if ($limits === null) return null;
        if (array_key_exists('class_subject_ids', $limits)) {
            return $limits['keys'];
        }
        return SubjectKey::keysFor($limits);
    }

    /**
     * Refuse a subject the teacher TYPED and does not teach, in the words the
     * `teacher.teaches:` middleware uses so a teacher hears one sentence for one rule.
     *
     * Only ever for a subject the caller wrote (a new piece of work, a plan being
     * saved, work being moved): the sentence repeats their own words and reveals
     * nothing. Work or a plan that ALREADY EXISTS in another subject is never
     * refused with this, because naming its subject would confirm it is there: the
     * controllers answer the plain 404 that work with no subject, or an id that
     * names nothing, gets.
     */
    public static function refuse(?string $subjectName): never
    {
        $label = SubjectKey::clean($subjectName);

        abort(
            Response::HTTP_FORBIDDEN,
            $label === null
                ? 'You teach only some subjects in this class, so choose the subject this is for.'
                : "You do not teach {$label} in this class."
        );
    }
}
