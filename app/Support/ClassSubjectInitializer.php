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

    public static function ready(Masjid $org): bool
    {
        if (empty(($org->capability_overrides ?? [])[self::MARKER])) return false;
        $ids = Group::withoutMasjidScope()->withTrashed()->where('masjid_id', $org->id)->where('kind', Group::KIND_CLASS)->pluck('id');
        return ! Group::withoutMasjidScope()->withTrashed()->whereIn('id', $ids)->whereNull('class_subjects_initialized_at')->exists()
            && ! GroupStaff::withoutMasjidScope()->where('masjid_id', $org->id)->whereIn('group_id', $ids)->whereNull('class_subjects_mapped_at')->exists();
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
                $report = [];
                foreach (Group::withTrashed()->where('masjid_id', $org->id)->where('kind', Group::KIND_CLASS)->orderBy('id')->get() as $group) {
                    $report[] = $dryRun ? self::previewGroup($group) : self::initializeGroup($group);
                }
                if ($dryRun) {
                    DB::rollBack();
                    return $report;
                }
                $overrides = $locked->capability_overrides ?? [];
                if (empty($overrides[self::MARKER])) {
                    $overrides[self::MARKER] = now()->toISOString();
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
            ? ClassSubject::where('group_id', $group->id)->get()
            : collect($fields)->map(fn ($row, $position) => (new ClassSubject($row))->forceFill([
                'id' => $position + 1, 'name_key' => SubjectKey::for($row['name']),
            ]));
        $staff = GroupStaff::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->whereNull('class_subjects_mapped_at')->get();
        foreach ($staff as $row) self::mapLegacy($group, $row->subjects, $subjects);
        return ['class' => $group->name, 'subjects_added' => $fields === null ? 0 : count($fields), 'assignments_mapped' => $staff->count()];
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
                ClassSubject::create(['masjid_id' => $group->masjid_id, 'group_id' => $group->id, 'position' => $position] + $fields);
                $seeded++;
            }
            Group::withoutTimestamps(fn () => $group->forceFill(['class_subjects_initialized_at' => now()])->save());
        }
        $mapped = 0;
        foreach (GroupStaff::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->whereNull('class_subjects_mapped_at')->get() as $staff) {
            GroupStaff::withoutTimestamps(fn () => $staff->forceFill(['class_subject_ids' => self::mapLegacy($group, $staff->subjects), 'class_subjects_mapped_at' => now()])->save());
            $mapped++;
        }
        return ['class' => $group->name, 'subjects_added' => $seeded, 'assignments_mapped' => $mapped];
    }

    public static function mapLegacy(Group $group, ?array $legacy, ?\Illuminate\Support\Collection $candidates = null): ?array
    {
        if ($legacy === null || $legacy === []) return null;
        $subjects = $candidates ?? ClassSubject::where('masjid_id', $group->masjid_id)->where('group_id', $group->id)->get();
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

    public static function startingList(Group $group): array
    {
        $grades = $group->memberships()->participants()->current()->pluck('grade_label')->all();
        if ($grades === []) $grades = $group->subject_seed_grades ?? [];
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
