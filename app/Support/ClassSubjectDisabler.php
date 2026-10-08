<?php

namespace App\Support;

use App\Models\{ClassSubject, GroupStaff, Masjid, SchoolSubject};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A deliberate return to legacy authority, preserving every stored ID and link. */
final class ClassSubjectDisabler
{
    private static bool $disabling = false;

    public static function assertAllowed(Masjid $org): void
    {
        if (! self::$disabling && (SchoolSettings::classSubjects($org) || ! empty(($org->capability_overrides ?? [])[ClassSubjectInitializer::MARKER]))) {
            throw ValidationException::withMessages(['capability' => ['Use class-subjects:disable --masjid='.$org->id.' to review legacy access before disabling.']]);
        }
    }

    public static function run(Masjid $org, bool $dryRun = false, array $accept = []): array
    {
        return app(TenantContext::class)->runWithout(function () use ($org, $dryRun, $accept) {
            if ($dryRun) return self::report(Masjid::whereKey($org->id)->firstOrFail(), $accept);
            return DB::transaction(function () use ($org, $accept) {
                $locked = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $report = self::report($locked, $accept, true);
                if ($report['blocked'] !== [] || ! $report['enabled']) return $report;
                foreach ($report['assignments'] as $row) {
                    DB::table('group_staff')->where('masjid_id', $locked->id)->where('id', $row['id'])
                        ->update(['subjects' => $row['legacy'] === null ? null : json_encode($row['legacy'])]);
                }
                self::$disabling = true;
                try { CapabilityWriter::apply($locked, ['class_subjects' => false], null); }
                finally { self::$disabling = false; }
                return $report;
            });
        });
    }

    /** Shared report; the dry-run branch invokes only ordinary SELECTs. */
    private static function report(Masjid $org, array $accept, bool $lock = false): array
    {
        if ($org->orgType() !== Masjid::ORG_TYPE_SCHOOL) throw ValidationException::withMessages(['masjid' => ['Class subjects disabling requires a school.']]);
        $report = ['enabled' => SchoolSettings::classSubjects($org), 'assignments' => [], 'blocked' => []];
        if (! $report['enabled']) {
            if ($accept !== []) $report['blocked'][] = 'The school is already OFF; no unrestricted acceptance applies.';
            return $report;
        }
        $subjects = ClassSubject::where('masjid_id', $org->id)->get()->groupBy('group_id');
        $guide = \App\Models\CurriculumWeek::where('masjid_id', $org->id)->distinct()->pluck('subject')
            ->merge(SchoolSubject::where('masjid_id', $org->id)->pluck('name'))
            ->map(fn ($name) => SubjectKey::for($name))->unique()->all();
        $work = [];
        foreach (ClassSubjectSavedWork::TABLES as $table) {
            $work[$table] = DB::table($table)->where('masjid_id', $org->id)
                ->select('group_id', 'subject', 'subject_key', 'class_subject_id')->distinct()->get()->groupBy('group_id');
        }
        $rows = GroupStaff::where('masjid_id', $org->id)->orderBy('id')->get();
        if ($lock) $rows = $rows->map(fn ($row) => GroupStaff::where('masjid_id', $org->id)->whereKey($row->id)->lockForUpdate()->firstOrFail());
        $inexpressible = [];
        foreach ($rows as $row) {
            [$expressible, $legacy] = self::legacyChoice($row, $subjects->get($row->group_id, collect()), $guide, $work);
            $accepted = ! $expressible && in_array((int) $row->id, $accept, true);
            if (! $expressible) {
                $inexpressible[] = (int) $row->id;
                if (! $accepted) $report['blocked'][] = "Assignment #{$row->id}: this restriction cannot be expressed in legacy subjects without widening; explicitly accept unrestricted access.";
            }
            $report['assignments'][] = ['id' => (int) $row->id, 'class_id' => (int) $row->group_id, 'teacher_id' => (int) $row->user_id,
                'ids' => $row->class_subject_ids, 'legacy' => $legacy, 'expressible' => $expressible, 'accepted' => $accepted];
        }
        // Reject stale, foreign-school and unnecessary acceptances, as well as omissions.
        foreach (array_diff($accept, $inexpressible) as $id) $report['blocked'][] = "Assignment #{$id}: acceptance does not name an inexpressible restriction in this school.";
        return $report;
    }

    /** Equality of grants, not equality of the holder IDs used during activation. */
    private static function legacyChoice(GroupStaff $row, $subjects, array $guide, array $work): array
    {
        if ($row->class_subjects_mapped_at === null && $row->class_subject_ids_edited_at === null) return [false, null];
        $ids = $row->class_subject_ids;
        if (! SubjectFence::validStoredIds($ids) || $ids === [] || ($ids !== null && array_diff($ids, $subjects->pluck('id')->all()) !== [])) return [false, null];
        $selected = $ids === null ? $subjects : $subjects->whereIn('id', $ids);
        $onKeys = $selected->flatMap(fn ($s) => $s->matchingKeys())->unique()->sort()->values()->all();
        $onGuide = $selected->flatMap(fn ($s) => $s->curriculumKeys())->unique()->all();
        $onTools = [];
        foreach (['hifdh', 'arabic_letters', 'english_letters'] as $tool) $onTools[$tool] = $selected->contains('tool', $tool);

        // These are main's readers: GroupStaff::teaches for the route middleware,
        // SubjectKey::keysFor / SubjectFence::allows for named work and curriculum.
        // Both alphabets and Arabic notes share teacher.teaches:arabic in main.
        $candidates = [null];
        foreach (range(1, 7) as $mask) {
            $candidate = [];
            foreach (GroupStaff::SUBJECTS as $bit => $key) if ($mask & (1 << $bit)) $candidate[] = $key;
            $candidates[] = $candidate;
        }
        foreach ($candidates as $legacy) {
            $assignment = (new GroupStaff)->forceFill(['subjects' => $legacy]);
            $oldTools = ['hifdh' => $assignment->teaches('quran'), 'arabic_letters' => $assignment->teaches('arabic'), 'english_letters' => $assignment->teaches('arabic')];
            if ($onTools !== $oldTools) continue;
            // Arabic notes follow the Arabic holder and the same legacy Arabic gate.
            if (($ids === null) !== ($legacy === null)) continue;
            if ($legacy !== null) {
                $oldKeys = SubjectKey::keysFor($legacy); sort($oldKeys);
                if ($onKeys !== $oldKeys) continue;
            }
            $equal = true;
            foreach ($work as $table => $classes) foreach ($classes->get($row->group_id, collect()) as $piece) {
                $general = $table === 'lesson_plans' && SubjectKey::clean($piece->subject) === null;
                $on = SubjectFence::allowsWork($ids === null ? null : ['class_subject_ids' => array_map('intval', $ids)], $piece->class_subject_id === null ? null : (int) $piece->class_subject_id, $general);
                $off = $general || SubjectFence::allows($legacy, SubjectKey::for($piece->subject));
                if ($on !== $off) { $equal = false; break 2; }
            }
            if (! $equal) continue;
            // Main without a class grants the whole-school guide and catalogue. Class-scoped
            // reads additionally use its legacy key fence; both must agree.
            foreach ($guide as $key) {
                $on = in_array($key, $onGuide, true);
                if (! $on || $on !== SubjectFence::allows($legacy, $key)) { $equal = false; break; }
            }
            if ($equal) return [true, $legacy];
        }
        return [false, null];
    }
}
