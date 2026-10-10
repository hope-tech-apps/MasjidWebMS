<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The calendar switch uses the same audited live writer, inside its organisation mutex. */
final class SchoolCalendarCapabilityWriter
{
    public static function apply(Masjid $org, array $changes, ?int $actor): array
    {
        if ($changes === []) {
            throw new InvalidArgumentException('apply() needs at least one capability.');
        }

        foreach ($changes as $key => $value) {
            CapabilityWriter::assertWritable((string) $key);

            if (! is_bool($value)) {
                // resolve() and this take real booleans only; the request
                // coerces "1"/"0"/"true"/"false" (DECISIONS.md, Studio S1).
                throw new InvalidArgumentException("Capability '{$key}' must be a boolean.");
            }
        }

        if (($changes['giving'] ?? null) === false) {
            $refusal = GivingSwitch::refusalToSwitchOff($org);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['capability' => [$refusal]]);
            }
        }

        $keys = array_values(array_filter(
            array_keys((array) config('capabilities')),
            fn ($key) => array_key_exists($key, $changes)
        ));

        $committed = false;
        $switch = function () use ($org, $changes, $actor, $keys, &$committed) {
            $locked = Masjid::query()->whereKey($org->getKey())->lockForUpdate()->firstOrFail();
            // A request that carries both switches still answers to the class-subject guards.
            SchoolSettings::assertSubjectWorkChange($locked, $changes);
            if (($changes['class_subjects'] ?? false) === true) ClassSubjectInitializer::assertReady($locked);
            if (($changes['class_subjects'] ?? null) === false) ClassSubjectDisabler::assertAllowed($locked);
            // Only the two switches that stay out of the panel while off skip a
            // hidden OFF -> OFF write, as main's writer does for class subjects.
            // Every other key stores its override and its audit row, no-ops included.
            $unchangedHidden = [];
            foreach ([SchoolSettings::SCHOOL_CALENDAR_TERMS, 'class_subjects', 'class_subject_work', 'class_subject_sharing', 'class_subject_report_summary'] as $hidden) {
                if (($changes[$hidden] ?? null) === false && ! $locked->hasCapability($hidden)) {
                    $unchangedHidden[] = $hidden;
                    unset($changes[$hidden]);
                }
            }
            $keys = array_values(array_diff($keys, $unchangedHidden));
            if (array_key_exists(SchoolSettings::SCHOOL_CALENDAR_TERMS, $changes)) SchoolCalendarSwitch::configure($locked, $changes[SchoolSettings::SCHOOL_CALENDAR_TERMS]);
            $overrides = is_array($locked->capability_overrides) ? $locked->capability_overrides : [];
            $flips = [];

            foreach ($keys as $key) {
                $flips[$key] = [
                    'before' => $locked->hasCapability($key),
                    'override_before' => array_key_exists($key, $overrides) ? (bool) $overrides[$key] : null,
                ];
                $overrides[$key] = $changes[$key];
            }

            if ($flips !== []) {
                $locked->capability_overrides = $overrides;
                $locked->updated_by = $actor;
                $locked->save();
            }

            $changed = [];
            $unchanged = $unchangedHidden;

            foreach ($flips as $key => ['before' => $before, 'override_before' => $overrideBefore]) {
                $after = $locked->hasCapability($key);
                CapabilityLedger::record($locked, $key, $before, $after, $overrideBefore, $actor);

                if ($after === $before) {
                    $unchanged[] = $key;
                } else {
                    $changed[] = $key;
                }
            }

            // /menu and the family's switchers are derived from the switches. A
            // child's switches build a profile inside its PARENT's /menu, hence
            // the family form. After commit: before it, another request could
            // rebuild the entry from rows that are about to vanish.
            DB::afterCommit(function () use ($locked, &$committed) {
                // From here the switch IS saved: a failure below must never be reported
                // as "nothing changed, try again".
                $committed = true;
                MobileCache::flushFamily($locked);
            });

            return ['changed' => $changed, 'unchanged' => $unchanged];
        };

        // A deliberate operator switch fails promptly if an office edit holds a lock.
        $mysql = DB::getDriverName() === 'mysql';
        $previous = $mysql ? (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout') : null;
        try {
            if ($mysql) DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
            return DB::transaction($switch);
        } catch (QueryException $e) {
            if ($committed || ! in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) throw $e;
            throw ValidationException::withMessages(['capability' => 'The school calendar is being edited. Try again in a few moments.']);
        } finally {
            if ($mysql) DB::statement('SET SESSION innodb_lock_wait_timeout = '.$previous);
        }
    }

}
