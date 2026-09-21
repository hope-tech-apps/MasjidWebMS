<?php

use App\Models\Masjid;
use App\Support\CapabilityLedger;
use App\Support\SchoolSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Switch the three weekly-school settings ON for Burlington Islamic Sunday
 * School (org 18), the organisation they were made for (owner, 2026-09-21;
 * DECISIONS.md 2026-09-21). Every other organisation keeps today's behaviour:
 * the settings default OFF and nothing here touches another row.
 *
 * A DATA migration rather than a command, so shipping the code is what turns
 * them on and nobody has to remember a step on the server. Only a SuperAdmin
 * may change them afterwards, through the switch panel (PATCH
 * .../capabilities/{key}).
 *
 * WHAT IT DOES, exactly:
 *   1. Reads masjids row 18. If there is none, or it is not a school whose name
 *      says "Sunday School" (a fresh database, a test run, any environment where
 *      18 is some other organisation), it writes NOTHING and logs one warning.
 *      Staging's org 18 is also BISS, so staging gets the same result.
 *   2. For each of report_card_core_subjects, short_lesson_plan and
 *      simple_marking that capability_overrides does NOT already name, sets it
 *      to true. A key already there is a SuperAdmin's decision and is left
 *      alone, true or false. So running it twice changes nothing the second
 *      time.
 *   3. For each key it set, appends one masjid_capability_changes row
 *      (enabled false -> true, override_before NULL, actor NULL: no person
 *      flipped it) and the usual `Organisation capability changed` warning.
 *
 * Written with the query builder, not Masjid::save(), so no model event (the
 * mobile menu cache flush, observers) runs from inside a migration. None of the
 * three settings is read by the mobile app.
 *
 * down() takes back only what up() gave: a key still true AND recorded by a
 * NULL-actor ledger row from this migration is removed, with its own ledger row.
 */
return new class extends Migration
{
    private const MASJID_ID = 18;

    private const KEYS = [
        SchoolSettings::REPORT_CARD_CORE_SUBJECTS,
        SchoolSettings::SHORT_LESSON_PLAN,
        SchoolSettings::SIMPLE_MARKING,
    ];

    public function up(): void
    {
        $masjid = $this->biss();

        if ($masjid === null) {
            return;
        }

        DB::transaction(function () use ($masjid) {
            $overrides = $this->overrides();
            $set = [];

            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $overrides)) {
                    continue;
                }

                $overrides[$key] = true;
                $set[] = $key;
            }

            if ($set === []) {
                return;
            }

            DB::table('masjids')->where('id', self::MASJID_ID)->update([
                'capability_overrides' => json_encode($overrides),
            ]);

            foreach ($set as $key) {
                CapabilityLedger::record($masjid, $key, false, true, null, null);
            }
        });
    }

    public function down(): void
    {
        $masjid = $this->biss();

        if ($masjid === null) {
            return;
        }

        DB::transaction(function () use ($masjid) {
            $overrides = $this->overrides();
            $removed = [];

            foreach (self::KEYS as $key) {
                $ours = DB::table('masjid_capability_changes')
                    ->where('masjid_id', self::MASJID_ID)
                    ->where('capability', $key)
                    ->whereNull('actor_user_id')
                    ->whereNull('override_before')
                    ->exists();

                if ($ours && ($overrides[$key] ?? null) === true) {
                    unset($overrides[$key]);
                    $removed[] = $key;
                }
            }

            if ($removed === []) {
                return;
            }

            DB::table('masjids')->where('id', self::MASJID_ID)->update([
                'capability_overrides' => $overrides === [] ? null : json_encode($overrides),
            ]);

            foreach ($removed as $key) {
                CapabilityLedger::record($masjid, $key, true, false, true, null);
            }
        });
    }

    /** Org 18 when it is the Sunday school, otherwise null (and one warning). */
    private function biss(): ?Masjid
    {
        $row = DB::table('masjids')->where('id', self::MASJID_ID)->first(['id', 'name', 'org_type', 'deleted_at']);

        $isBiss = $row !== null
            && $row->deleted_at === null
            && $row->org_type === 'school'
            && stripos((string) $row->name, 'sunday school') !== false;

        if (! $isBiss) {
            if ($row !== null) {
                Log::warning('Sunday school settings not switched on: organisation 18 is not the Sunday school', [
                    'masjid_id' => self::MASJID_ID,
                    'org_type' => $row->org_type,
                ]);
            }

            return null;
        }

        return Masjid::withoutGlobalScopes()->find(self::MASJID_ID);
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        $raw = DB::table('masjids')->where('id', self::MASJID_ID)->value('capability_overrides');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
};
