<?php

namespace App\Support;

use App\Models\{ClassSubject, CurriculumWeek, Group, GroupMembership, GroupStaff, Masjid, SchoolSubject};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A school crosses from legacy to IDs in one transaction, never in a prepared state. */
final class ClassSubjectInitializer
{
    public const MARKER = '_class_subjects_initialized_at';
    private static bool $activating = false;

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
            'quran' => 'hifdh', 'arabic', 'arabic language' => 'arabic_letters',
            'ela', 'english language arts' => 'english_letters', default => null,
        };
    }

    /** Audit comparison only. Neither value influences an ON fence. */
    public static function sameLegacy(mixed $before, mixed $after): bool
    {
        return $before === $after;
    }

    /** Readiness is a committed activation fact, never an independently prepared state. */
    public static function ready(Masjid $org): bool
    {
        return $org->hasCapability('class_subjects') && ! empty(($org->capability_overrides ?? [])[self::MARKER]);
    }

    public static function assertReady(Masjid $org): void
    {
        if (! self::$activating && ! $org->hasCapability('class_subjects')) {
            throw ValidationException::withMessages(['capability' => ['Use class-subjects:initialize --enable to activate this school atomically.']]);
        }
    }

    private static function featureGroups(Masjid $org)
    {
        // Archived assignments translate too, so restore never needs a second translation.
        return Group::withoutMasjidScope()->withTrashed()->where('masjid_id', $org->id)->where(fn ($q) => $q
            ->where('kind', Group::KIND_CLASS)
            ->orWhereIn('id', ClassSubject::withoutMasjidScope()->where('masjid_id', $org->id)->select('group_id'))
            ->orWhereIn('id', GroupStaff::withoutMasjidScope()->where('masjid_id', $org->id)->select('group_id')));
    }

    public static function run(Masjid $org, bool $dryRun = false, bool $enable = false): array
    {
        if (! $dryRun && ! $enable) throw ValidationException::withMessages(['enable' => ['Initialization only occurs with --enable. Use --dry-run to preview without writing.']]);
        return app(TenantContext::class)->runWithout(function () use ($org, $dryRun) {
            if ($dryRun) {
                $fresh = Masjid::whereKey($org->id)->firstOrFail();
                self::assertSchool($fresh);
                return self::report($fresh)[1];
            }
            return DB::transaction(function () use ($org) {
                // First statement is a current, unique-PK school mutex, before consistent reads.
                $locked = Masjid::whereKey($org->id)->lockForUpdate()->firstOrFail();
                self::assertSchool($locked);
                [$groups, $report] = self::report($locked);
                if (SchoolSettings::classSubjects($locked) || collect($report)->contains(fn ($r) => $r['blocked'] !== [])) return $report;
                foreach ($groups as $group) {
                    $group = Group::withTrashed()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                    self::initializeGroup($group);
                    $subjects = self::currentSubjects($group);
                    foreach (self::currentStaff($group) as $staff) {
                        // Once IDs have authority, neither legacy edits nor holder moves translate them again.
                        if (! self::needsTranslation($staff, $locked->capability_overrides[self::MARKER]['untranslated_staff_ids'] ?? [])) continue;
                        $ids = self::mapLegacy($group, $staff->subjects, $subjects);
                        DB::table('group_staff')->where('masjid_id', $locked->id)->where('id', $staff->id)->update([
                            'class_subject_ids' => $ids === null ? null : json_encode($ids),
                            'class_subjects_mapped_at' => now(),
                            'class_subjects_translated_from' => $staff->getRawOriginal('subjects') ?? 'null',
                        ]);
                    }
                    ClassSubjectSavedWork::linkAtActivation($group, $subjects);
                }
                $overrides = $locked->capability_overrides ?? [];
                $overrides[self::MARKER] = array_replace($overrides[self::MARKER] ?? [], ['at' => now()->toISOString(), 'overrides_were_null' => $overrides[self::MARKER]['overrides_were_null'] ?? ($locked->capability_overrides === null)]);
                Masjid::withoutTimestamps(fn () => $locked->forceFill(['capability_overrides' => $overrides])->save());
                self::$activating = true;
                try { CapabilityWriter::apply($locked, ['class_subjects' => true], null); }
                finally { self::$activating = false; }
                return $report;
            });
        });
    }

    private static function assertSchool(Masjid $org): void
    {
        if ($org->orgType() !== Masjid::ORG_TYPE_SCHOOL) throw ValidationException::withMessages(['masjid' => ['Class subjects activation requires a school.']]);
    }

    /** A mapped row or a new ON office choice already has permanent ID authority. */
    private static function needsTranslation(GroupStaff $staff, array $untranslated = []): bool
    {
        return $staff->class_subjects_mapped_at === null && $staff->class_subject_ids_edited_at === null
            && ! in_array((int) $staff->id, $untranslated, true);
    }

    /** Read each work table once per school, returning counts rather than work rows. */
    private static function previewData(Masjid $org): array
    {
        $work = [];
        $unchecked = 'CASE WHEN class_subject_link_checked_at IS NULL THEN 1 ELSE 0 END';
        foreach (ClassSubjectSavedWork::TABLES as $table) {
            $work[$table] = DB::table($table)->where('masjid_id', $org->id)
                ->select('group_id', 'subject', 'subject_key', 'class_subject_id')
                ->selectRaw($unchecked.' as unexamined, COUNT(*) as n')
                // HEX preserves exact keys even under a PAD SPACE/accent-insensitive collation.
                ->groupBy('group_id', 'subject', 'subject_key', 'class_subject_id', DB::raw($unchecked), DB::raw('HEX(subject_key)'), DB::raw('HEX(subject)'))
                ->get()->map(function ($row) use ($table) {
                    $row->table = $table;
                    $row->unexamined = (bool) $row->unexamined;
                    $row->n = (int) $row->n;
                    $row->class_subject_id = $row->class_subject_id === null ? null : (int) $row->class_subject_id;
                    return $row;
                })->groupBy('group_id');
        }
        return ['work' => $work, 'untranslated' => $org->capability_overrides[self::MARKER]['untranslated_staff_ids'] ?? [],
            'grades' => GroupMembership::where('masjid_id', $org->id)->participants()->current()->get(['group_id', 'grade_label'])->groupBy('group_id'),
            'guide' => CurriculumWeek::where('masjid_id', $org->id)->select('grade_label', 'subject')->distinct()->orderBy('subject')->get(),
            'school' => SchoolSubject::where('masjid_id', $org->id)->orderBy('position')->orderBy('id')->get(),
            'subjects' => ClassSubject::where('masjid_id', $org->id)->get()->groupBy('group_id'),
            'staff' => GroupStaff::where('masjid_id', $org->id)->orderBy('id')->get()->groupBy('group_id')];
    }

    /** Command output consumes one class report at a time; no transaction or write. */
    public static function streamPreview(Masjid $org): \Generator
    {
        [$fresh, $groups, $data] = app(TenantContext::class)->runWithout(function () use ($org) {
            $fresh = Masjid::whereKey($org->id)->firstOrFail();
            self::assertSchool($fresh);
            return [$fresh, self::featureGroups($fresh)->orderBy('id')->get(), self::previewData($fresh)];
        });
        foreach ($groups as $group) {
            yield app(TenantContext::class)->runWithout(fn () => self::previewGroup($group, SchoolSettings::classSubjects($fresh), $data));
        }
    }

    /** Shared plain-read report, recomputed under the school mutex for activation. */
    private static function report(Masjid $org): array
    {
        $groups = self::featureGroups($org)->orderBy('id')->get();
        $data = self::previewData($org);
        return [$groups, $groups->map(fn ($group) => self::previewGroup($group, SchoolSettings::classSubjects($org), $data))->all()];
    }

    private static function previewGroup(Group $group, bool $alreadyOn, array $data): array
    {
        $existing = $data['subjects']->get($group->id, collect());
        $creates = []; $blocked = []; $combined = [];
        if ($existing->isEmpty() && $group->class_subjects_initialized_at === null) {
            try { $plan = self::startingPlan($group, false, $data); $creates = $plan['subjects']; $combined = $plan['combined_columns']; }
            catch (ValidationException $e) { foreach ($e->errors() as $messages) $blocked = [...$blocked, ...$messages]; }
        }
        $subjects = $existing->isNotEmpty() ? $existing : collect($creates)->map(fn ($fields, $i) =>
            (new ClassSubject($fields))->forceFill(['id' => -($i + 1), 'name_key' => SubjectKey::for($fields['name'])]));
        $report = ['class' => $group->name, 'class_id' => $group->id, 'archived' => $group->trashed(), 'subjects_added' => count($creates),
            'assignments_mapped' => 0, 'creates' => $creates, 'assignments' => [], 'losses' => [], 'blocked' => $blocked,
            'saved_work_links' => [], 'orphaned_work' => [], 'combined_columns' => $combined];
        $work = [];
        foreach ($data['work'] as $table => $classes) {
            $work[$table] = $classes->get($group->id, collect())->map(function ($row) use ($subjects) {
                $row->general = $row->table === 'lesson_plans' && SubjectKey::clean($row->subject) === null;
                $row->matched = ClassSubjectSavedWork::matchingSubject($row->subject_key, $subjects);
                $row->linked = $row->class_subject_id ?? ($row->unexamined ? $row->matched?->id : null);
                return $row;
            });
            foreach ($work[$table] as $row) {
                if ($row->general || $row->class_subject_id !== null) continue;
                if ($row->matched === null) $report['orphaned_work'][$row->subject_key] = ($report['orphaned_work'][$row->subject_key] ?? 0) + $row->n;
                elseif ($row->unexamined) $report['saved_work_links'][$row->matched->name] = ($report['saved_work_links'][$row->matched->name] ?? 0) + $row->n;
            }
        }
        $losses = [];
        $loss = function (string $line) use (&$losses): void { $losses[$line] = true; };
        $grades = $data['grades']->get($group->id, collect())->pluck('grade_label')->all();
        if ($grades === []) $grades = $group->subject_seed_grades ?? [];
        $gradeKeys = array_filter(array_map(fn ($grade) => GradeLevel::key($grade), $grades), fn ($key) => $key !== null);
        $guideSubjects = $data['guide']->filter(fn ($row) => in_array(GradeLevel::key($row->grade_label), $gradeKeys, true))->pluck('subject')->unique();
        foreach ($data['staff']->get($group->id, collect()) as $staff) {
            $ids = $staff->class_subjects_mapped_at === null && $staff->class_subject_ids_edited_at === null ? [] : $staff->class_subject_ids;
            if (! $alreadyOn && self::needsTranslation($staff, $data['untranslated'])) {
                $report['assignments_mapped']++;
                $ids = $staff->subjects === null || $staff->subjects === [] ? null : [];
                foreach ($staff->subjects ?? [] as $legacyKey) {
                    try { $ids = [...$ids, ...self::mapLegacy($group, [$legacyKey], $subjects)]; }
                    catch (ValidationException $e) { foreach ($e->errors() as $messages) foreach ($messages as $message) $report['blocked'][] = "Teacher #{$staff->user_id}: {$message}"; }
                }
                if ($ids !== null) $ids = array_values(array_unique($ids));
            }
            $selected = $ids === null ? $subjects : $subjects->whereIn('id', $ids);
            $report['assignments'][] = ['teacher_id' => $staff->user_id, 'legacy' => $staff->subjects,
                'names' => $ids === null ? ['all subjects'] : $selected->pluck('name')->all(), 'will_map' => ! $alreadyOn && self::needsTranslation($staff, $data['untranslated'])];
            if ($alreadyOn || ! self::needsTranslation($staff, $data['untranslated'])) continue;
            $legacy = $staff->subjects ?: null;
            foreach (['hifdh' => ['quran', 'Hifdh'], 'arabic_letters' => ['arabic', 'Arabic letters and daily notes'], 'english_letters' => ['arabic', 'English letters']] as $tool => [$old, $label]) {
                if (($legacy === null || in_array($old, $legacy, true)) && ! $selected->contains('tool', $tool)) $loss("LOSS Teacher #{$staff->user_id}: {$label}");
            }
            if ($ids !== null) {
                if ($legacy === null) $loss("LOSS Teacher #{$staff->user_id}: class-wide grade weights");
                foreach (ClassSubjectSavedWork::TABLES as $table) {
                    foreach ($work[$table] as $row) {
                        if ($row->general) continue;
                        if (SubjectFence::allows($legacy, SubjectKey::for($row->subject)) && ! in_array($row->linked, $ids, true)) $loss("LOSS Teacher #{$staff->user_id}: {$table} ({$row->subject_key})");
                    }
                }
                $guideKeys = $selected->flatMap(fn ($s) => $s->curriculumKeys())->unique()->all();
                foreach ($guideSubjects as $name) {
                    if (SubjectFence::allows($legacy, SubjectKey::for($name)) && ! in_array(SubjectKey::for($name), $guideKeys, true)) $loss("LOSS Teacher #{$staff->user_id}: pacing guide ({$name})");
                }
            }
        }
        $report['losses'] = array_keys($losses);
        return $report;
    }

    /** ON lifecycle only seeds a previously unseeded class; never reads legacy assignments. */
    public static function initializeGroup(Group $group): array
    {
        $group = Group::withTrashed()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        $seeded = 0;
        if ($group->class_subjects_initialized_at === null) {
            if (! ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->exists()) {
                foreach (self::startingList($group) as $position => $fields) {
                    ClassSubject::create(['masjid_id' => $group->masjid_id, 'group_id' => $group->id, 'position' => $position] + $fields);
                    $seeded++;
                }
            }
            // A historical fact only, never a readiness check or assignment authority.
            DB::table('groups')->where('id', $group->id)->update(['class_subjects_initialized_at' => now()]);
        }
        return ['class' => $group->name, 'subjects_added' => $seeded, 'assignments_mapped' => 0];
    }

    /** Only activation calls this translation. Missing or ambiguous subjects block. */
    public static function mapLegacy(Group $group, ?array $legacy, $subjects = null): ?array
    {
        if ($legacy === null || $legacy === []) return null;
        $subjects ??= self::currentSubjects($group); $ids = [];
        foreach ($legacy as $name) {
            $matches = match ($name) {
                'quran' => $subjects->where('tool', 'hifdh'), 'arabic' => $subjects->where('tool', 'arabic_letters'),
                'islamic_studies' => $subjects->filter(fn ($s) => in_array('islamic studies', $s->matchingKeys(), true)), default => collect(),
            };
            if ($matches->count() !== 1) throw ValidationException::withMessages(['class_subjects' => ["Class {$group->name}: legacy subject {$name} cannot be mapped. Resolve the class subject or tool before activation."]]);
            $ids[] = (int) $matches->first()->id;
        }
        return array_values(array_unique($ids));
    }

    /** Nonlocking discovery, followed by current existing-PK reads, avoids tail gap locks. */
    public static function currentStaff(Group $group)
    {
        return GroupStaff::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->orderBy('id')->pluck('id')
            ->map(fn ($id) => GroupStaff::withoutMasjidScope()->whereKey($id)->lockForUpdate()->first())
            ->filter(fn ($r) => $r !== null && (int) $r->masjid_id === (int) $group->masjid_id && (int) $r->group_id === (int) $group->id)->values();
    }

    public static function currentSubjects(Group $group)
    {
        return ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->orderBy('id')->pluck('id')
            ->map(fn ($id) => ClassSubject::withoutMasjidScope()->whereKey($id)->lockForUpdate()->first())
            ->filter(fn ($r) => $r !== null && (int) $r->masjid_id === (int) $group->masjid_id && (int) $r->group_id === (int) $group->id)->values();
    }

    public static function startingList(Group $group, bool $currentGradesOnly = false): array
    {
        return self::startingPlan($group, $currentGradesOnly)['subjects'];
    }

    private static function startingPlan(Group $group, bool $currentGradesOnly = false, ?array $data = null): array
    {
        $grades = $group->memberships()->participants()->current()->pluck('grade_label')->all();
        if ($grades === [] && ! $currentGradesOnly) $grades = $group->subject_seed_grades ?? [];
        $keys = array_map(fn ($g) => GradeLevel::key($g), $grades);
        $all = $keys === [] || in_array(null, $keys, true);
        $applies = fn ($g) => $all || in_array(GradeLevel::key($g), $keys, true);
        $guideRows = ($data['guide'] ?? CurriculumWeek::where('masjid_id', $group->masjid_id)->select('grade_label', 'subject')->distinct()->orderBy('subject')->get())
            ->filter(fn ($row) => $applies($row->grade_label));
        $guide = $guideRows->pluck('subject')->unique()->values();
        $names = [];
        foreach (($data['school'] ?? SchoolSubject::where('masjid_id', $group->masjid_id)->orderBy('position')->orderBy('id')->get()) as $subject) {
            if ($all || empty($subject->grade_labels) || collect($subject->grade_labels)->contains($applies)) $names[] = $subject->name;
        }
        $out = []; $seen = []; $tools = []; $combined = [];
        $existingKeys = $data !== null ? $data['subjects']->get($group->id, collect())->pluck('name_key')->all()
            : ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->pluck('name_key')->all();
        $schoolKeys = array_map(fn ($name) => SubjectKey::for($name), $names);
        $candidateKeys = collect([...$existingKeys, ...array_map(fn ($name) => SubjectKey::for($name), [...$names, ...$guide])])
            ->flatMap(fn ($key) => self::aliases($key))->unique()->all();
        foreach ([...$names, ...$guide] as $name) {
            $name = SubjectKey::clean($name);
            if ($name === null) continue;
            $key = SubjectKey::for($name);
            $parts = preg_split('/\s*(?:&|\band\b|\/|,)\s*/iu', $name);
            if (! in_array($key, $schoolKeys, true) && $guide->contains(fn ($column) => SubjectKey::for($column) === $key) && count($parts) > 1 && collect($parts)->every(fn ($part) => SubjectKey::clean($part) !== null && in_array(SubjectKey::for($part), $candidateKeys, true))) {
                $combined[$name] = ['name' => $name, 'grades' => $guideRows->filter(fn ($row) => SubjectKey::for($row->subject) === $key)->pluck('grade_label')->unique()->values()->all()];
                continue;
            }
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
        return ['subjects' => $out, 'combined_columns' => array_values($combined)];
    }
}
