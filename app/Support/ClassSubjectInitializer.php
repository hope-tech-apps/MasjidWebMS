<?php

namespace App\Support;

use App\Models\ClassSubject;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\SchoolSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClassSubjectInitializer
{
    // Private metadata in the existing, private JSON. Needed even for a school with no classes.
    public const MARKER = '_class_subjects_initialized_at';

    public static function aliases(string $key): array
    {
        return match ($key) {
            'ela', 'english language arts' => ['ela', 'english language arts'],
            'arabic', 'arabic language' => ['arabic', 'arabic language'],
            default => [$key],
        };
    }

    public static function defaultTool(string $key): ?string
    {
        return match ($key) {
            'quran' => 'hifdh',
            'arabic', 'arabic language' => 'arabic_letters',
            'ela', 'english language arts' => 'english_letters',
            default => null,
        };
    }

    /** Compare the legacy source with the baseline, including writes that bypass model events. */
    public static function needsMapping(GroupStaff $staff): bool
    {
        return $staff->class_subjects_mapped_at === null || $staff->class_subject_legacy_snapshot === null
            || ! self::sameLegacy($staff->subjects, $staff->class_subject_legacy_snapshot);
    }

    public static function sameLegacy(mixed $before, mixed $after): bool
    {
        return self::legacyValue($before) === self::legacyValue($after);
    }

    private static function legacyValue(mixed $value): mixed
    {
        if ($value === null || $value === []) return null;
        if (! is_array($value)) return $value;
        $value = array_values(array_unique($value));
        sort($value);
        return $value;
    }

    private static function baselineConflict(GroupStaff $staff, ?array $proposed): bool
    {
        if (($staff->class_subjects_mapped_at === null && $staff->class_subject_ids === null) || $staff->class_subject_legacy_snapshot !== null) return false;
        $stored = $staff->class_subject_ids;
        if (! SubjectFence::validStoredIds($stored)) return true;
        if ($stored === null || $proposed === null) return $stored !== $proposed;
        $stored = array_map('intval', $stored);
        sort($stored); sort($proposed);
        return $stored !== $proposed;
    }

    private static function baselineMessage(Group $group): string
    {
        return "Class {$group->name}: this assignment predates legacy tracking and its stored subjects differ from the legacy subjects. Confirm the intended class subject assignment before activation; no restriction was changed.";
    }

    public static function ready(Masjid $org): bool
    {
        if (empty(($org->capability_overrides ?? [])[self::MARKER])) return false;
        $groups = self::featureGroups($org)->get();
        foreach ($groups as $group) {
            if ($group->class_subjects_initialized_at === null) return false;
            $validIds = ClassSubject::withoutMasjidScope()->where('masjid_id', $org->id)->where('group_id', $group->id)->pluck('id')->all();
            foreach (GroupStaff::withoutMasjidScope()->where('masjid_id', $org->id)->where('group_id', $group->id)->get() as $staff) {
                if (self::needsMapping($staff)) return false;
                $ids = $staff->class_subject_ids;
                if (! SubjectFence::validStoredIds($ids) || ($ids !== null && array_diff($ids, $validIds) !== [])) return false;
            }
        }
        return true;
    }

    /** Include historical subject authorities after a kind change; ordinary groups stay legacy. */
    private static function featureGroups(Masjid $org): \Illuminate\Database\Eloquent\Builder
    {
        return Group::withoutMasjidScope()->where('masjid_id', $org->id)->where(fn ($q) => $q
            ->where('kind', Group::KIND_CLASS)->orWhereNotNull('class_subjects_initialized_at')
            ->orWhereIn('id', ClassSubject::withoutMasjidScope()->where('masjid_id', $org->id)->select('group_id'))
            ->orWhereIn('id', GroupStaff::withoutMasjidScope()->where('masjid_id', $org->id)
                ->where(fn ($staff) => $staff->whereNotNull('class_subject_ids')->orWhereNotNull('class_subjects_mapped_at')->orWhereNotNull('class_subject_legacy_snapshot'))->select('group_id')));
    }

    public static function assertReady(Masjid $org): void
    {
        if (! self::ready($org)) {
            throw ValidationException::withMessages(['capability' => ['Initialize this school\'s class subjects with class-subjects:initialize before switching them on.']]);
        }
    }

    /** All-or-nothing per school, including a dry run (whose transaction is rolled back). */
    public static function run(Masjid $org, bool $dryRun = false, bool $enable = false): array
    {
        return app(TenantContext::class)->runWithout(function () use ($org, $dryRun, $enable) {
            DB::beginTransaction();
            try {
                // First statement: PK mutex before any consistent read under InnoDB.
                $locked = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
                $groups = self::featureGroups($org)->orderBy('id')->get();
                // Preflight every class and every restriction before writing anything.
                $report = $groups->map(fn ($group) => self::previewGroup($group))->all();
                $blocked = collect($report)->contains(fn ($row) => $row['blocked'] !== []);
                if ($dryRun || $blocked) {
                    DB::rollBack();
                    return $report;
                }
                foreach ($groups as $i => $group) {
                    $report[$i] = array_replace($report[$i], self::initializeGroup($group));
                }
                $overrides = $locked->capability_overrides ?? [];
                if (empty($overrides[self::MARKER])) {
                    $overrides[self::MARKER] = ['at' => now()->toISOString(), 'overrides_were_null' => $locked->capability_overrides === null];
                    // Private initialization must not change an existing OFF payload's timestamp.
                    Masjid::withoutTimestamps(fn () => $locked->forceFill(['capability_overrides' => $overrides])->save());
                }
                if ($enable) self::enable($locked);
                DB::commit();
                return $report;
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        });
    }

    private static function previewGroup(Group $group): array
    {
        $fields = $group->class_subjects_initialized_at === null ? self::startingList($group) : null;
        $subjects = $fields === null
            ? ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->get()
            : collect($fields)->map(fn ($row, $position) => (new ClassSubject($row))->forceFill([
                'id' => $position + 1, 'name_key' => SubjectKey::for($row['name']),
            ]));
        $report = ['class' => $group->name, 'class_id' => $group->id, 'subjects_added' => count($fields ?? []),
            'assignments_mapped' => 0, 'creates' => $fields ?? [], 'assignments' => [], 'losses' => [], 'blocked' => []];
        $work = ClassSubjectSavedWork::counts((int) $group->masjid_id, (int) $group->id);
        $owned = $subjects->flatMap(fn ($subject) => $subject->matchingKeys())->all();
        $report['orphaned_work'] = array_diff_key($work, array_fill_keys($owned, true));
        foreach ($report['creates'] as &$create) {
            $create['attaches_saved_work'] = array_intersect_key($work, array_fill_keys(self::aliases(SubjectKey::for($create['name'])), true));
        }
        unset($create);
        foreach (GroupStaff::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->orderBy('user_id')->get() as $staff) {
            $mapping = self::needsMapping($staff);
            if ($mapping) $report['assignments_mapped']++;
            $ids = $mapping ? null : $staff->class_subject_ids;
            if (! $mapping && (! SubjectFence::validStoredIds($ids) || ($ids !== null && array_diff($ids, $subjects->pluck('id')->all()) !== []))) {
                $report['blocked'][] = "Teacher #{$staff->user_id}: Class {$group->name}: assigned subjects do not belong to this class. Correct the assignment before activation.";
                $ids = [];
            }
            $partial = [];
            if ($mapping && $staff->subjects !== null && $staff->subjects !== []) {
                foreach ($staff->subjects as $legacy) {
                    try {
                        $partial = [...$partial, ...self::mapLegacy($group, [$legacy], $subjects)];
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $messages) foreach ($messages as $message) {
                            $report['blocked'][] = "Teacher #{$staff->user_id}: {$message}";
                        }
                    }
                }
                $ids = array_values(array_unique($partial));
            }
            $selected = $ids === null ? $subjects : $subjects->whereIn('id', $ids);
            if (self::baselineConflict($staff, $ids)) {
                $stored = $staff->class_subject_ids;
                $existing = $stored === null ? $subjects : $subjects->whereIn('id', is_array($stored) ? $stored : []);
                $report['blocked'][] = "Teacher #{$staff->user_id}: ".self::baselineMessage($group)
                    .' Existing assignment: ['.$existing->pluck('name')->implode(', ').']; proposed: ['.$selected->pluck('name')->implode(', ').'].';
            }
            $report['assignments'][] = ['teacher_id' => $staff->user_id, 'legacy' => $staff->subjects,
                'names' => $selected->pluck('name')->all(), 'will_map' => $mapping];
            $legacy = $staff->subjects ?: null;
            $oldAllows = fn ($subject) => $legacy === null || $legacy === [] || in_array($subject, $legacy, true);
            foreach (['hifdh' => ['quran', 'Hifdh'], 'arabic_letters' => ['arabic', 'Arabic letters and daily notes'],
                'english_letters' => ['arabic', 'English letters']] as $tool => [$oldSubject, $label]) {
                if ($oldAllows($oldSubject) && ! $selected->contains('tool', $tool)) {
                    $report['losses'][] = "LOSS Teacher #{$staff->user_id}: {$label}";
                }
            }
            if ($ids !== null) {
                $keys = $selected->flatMap(fn ($s) => $s->matchingKeys())->unique()->all();
                foreach (['Gradebook' => $group->assignments(), 'Lesson plans' => $group->lessonPlans()] as $label => $work) {
                    foreach ($work->select('subject')->distinct()->pluck('subject') as $name) {
                        if ($label === 'Lesson plans' && SubjectKey::clean($name) === null) continue;
                        if (SubjectFence::allows($legacy, SubjectKey::for($name)) && ! in_array(SubjectKey::for($name), $keys, true)) {
                            $report['losses'][] = "LOSS Teacher #{$staff->user_id}: {$label} ({$name})";
                        }
                    }
                }
                if ($legacy === null || $legacy === []) $report['losses'][] = "LOSS Teacher #{$staff->user_id}: class-wide grade weights";
                $guideKeys = $selected->flatMap(fn ($s) => $s->curriculumKeys())->unique()->all();
                foreach (CurriculumWeek::where('masjid_id', $group->masjid_id)->distinct()->pluck('subject') as $name) {
                    if (($legacy === null || $legacy === [] || SubjectFence::allows($legacy, SubjectKey::for($name)) || SubjectKey::staffKeys(SubjectKey::for($name)) === [])
                        && ! in_array(SubjectKey::for($name), $guideKeys, true)) {
                        $report['losses'][] = "LOSS Teacher #{$staff->user_id}: pacing guide ({$name})";
                    }
                }
            }
        }
        return $report;
    }

    private static function enable(Masjid $org): void
    {
        if (! $org->hasCapability('class_subjects')) {
            CapabilityWriter::apply($org, ['class_subjects' => true], null);
        }
    }

    /** Caller holds the school mutex when initializing a school or creating a class. */
    public static function initializeGroup(Group $group): array
    {
        $group = Group::withTrashed()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        $seeded = 0;
        if ($group->class_subjects_initialized_at === null) {
            foreach (self::startingList($group) as $position => $fields) {
                // The preflight reports these attachments to names explicitly selected by setup.
                (new ClassSubject(['masjid_id' => $group->masjid_id, 'group_id' => $group->id, 'position' => $position] + $fields))->saveAttachingOrphanedWork();
                $seeded++;
            }
            Group::withoutTimestamps(fn () => $group->forceFill(['class_subjects_initialized_at' => now()])->save());
        }
        $mapped = 0;
        // A restore may run inside an older transaction: locking reads bypass its stale read view.
        foreach (self::currentStaff($group) as $staff) {
            if (! self::needsMapping($staff)) continue;
            $ids = self::mapLegacy($group, $staff->subjects);
            if (self::baselineConflict($staff, $ids)) {
                throw ValidationException::withMessages(['class_subjects' => ["Teacher #{$staff->user_id}: ".self::baselineMessage($group)]]);
            }
            $fields = ['class_subject_legacy_snapshot' => $staff->subjects ?: []];
            // An older, coherent mapping needs only a baseline; preserve its IDs and stamp.
            if ($staff->class_subjects_mapped_at === null || $staff->class_subject_legacy_snapshot !== null) {
                $fields += ['class_subject_ids' => $ids, 'class_subjects_mapped_at' => now()];
            }
            GroupStaff::withoutTimestamps(fn () => $staff->resolveClassSubjectAssignment($fields));
            $mapped++;
        }
        return ['class' => $group->name, 'subjects_added' => $seeded, 'assignments_mapped' => $mapped];
    }

    public static function mapLegacy(Group $group, ?array $legacy, ?\Illuminate\Support\Collection $candidates = null): ?array
    {
        if ($legacy === null || $legacy === []) return null;
        $subjects = $candidates ?? self::currentSubjects($group);
        $ids = [];
        foreach ($legacy as $name) {
            $match = match ($name) {
                'quran' => $subjects->firstWhere('tool', 'hifdh'),
                'arabic' => $subjects->firstWhere('tool', 'arabic_letters'),
                'islamic_studies' => $subjects->first(fn ($s) => in_array('islamic studies', $s->matchingKeys(), true)),
                default => null,
            };
            if ($match === null) {
                throw ValidationException::withMessages(['class_subjects' => ["Class {$group->name}: legacy subject {$name} cannot be mapped. Add the subject to the school list or resolve its tool before initialization."]]);
            }
            $ids[] = (int) $match->id;
        }
        return array_values(array_unique($ids));
    }

    /** Discover without range locks; only existing primary keys take current row locks. */
    public static function currentStaff(Group $group): \Illuminate\Support\Collection
    {
        $ids = GroupStaff::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->orderBy('id')->pluck('id');
        return $ids->map(fn ($id) => GroupStaff::withoutMasjidScope()->whereKey($id)->lockForUpdate()->first())
            ->filter(fn ($row) => $row !== null && (int) $row->masjid_id === (int) $group->masjid_id && (int) $row->group_id === (int) $group->id)->values();
    }

    private static function currentSubjects(Group $group): \Illuminate\Support\Collection
    {
        $ids = ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->orderBy('id')->pluck('id');
        return $ids->map(fn ($id) => ClassSubject::withoutMasjidScope()->whereKey($id)->lockForUpdate()->first())
            ->filter(fn ($row) => $row !== null && (int) $row->masjid_id === (int) $group->masjid_id && (int) $row->group_id === (int) $group->id)->values();
    }

    public static function startingList(Group $group, bool $currentGradesOnly = false): array
    {
        $grades = $group->memberships()->participants()->current()->pluck('grade_label')->all();
        if ($grades === [] && ! $currentGradesOnly) $grades = $group->subject_seed_grades ?? [];
        $keys = array_map(fn ($g) => GradeLevel::key($g), $grades);
        $all = $keys === [] || in_array(null, $keys, true);
        $applies = fn ($g) => $all || in_array(GradeLevel::key($g), $keys, true);
        $guide = CurriculumWeek::where('masjid_id', $group->masjid_id)->select('grade_label', 'subject')->distinct()->orderBy('subject')->get()
            ->filter(fn ($row) => $applies($row->grade_label))->pluck('subject')->unique()->values();
        $names = [];
        foreach (SchoolSubject::where('masjid_id', $group->masjid_id)->orderBy('position')->orderBy('name')->get() as $subject) {
            if ($all || empty($subject->grade_labels) || collect($subject->grade_labels)->contains($applies)) $names[] = $subject->name;
        }
        $out = []; $seen = []; $tools = [];
        foreach ([...$names, ...$guide] as $name) {
            $name = SubjectKey::clean($name);
            if ($name === null) continue;
            $key = SubjectKey::for($name);
            $aliases = self::aliases($key);
            if (array_intersect($aliases, $seen) !== []) continue;
            $seen = [...$seen, ...$aliases];
            $matches = $guide->filter(fn ($g) => in_array(SubjectKey::for($g), $aliases, true));
            $tool = self::defaultTool($key);
            if ($tool !== null && isset($tools[$tool])) {
                throw ValidationException::withMessages(['class_subjects' => ["Class {$group->name}: subjects {$tools[$tool]} and {$name} both hold {$tool}. Resolve this before initialization."]]);
            }
            if ($tool !== null) $tools[$tool] = $name;
            $out[] = ['name' => $name, 'guide_subject' => $matches->count() === 1 ? $matches->first() : null, 'tool' => $tool];
        }
        return $out;
    }
}
