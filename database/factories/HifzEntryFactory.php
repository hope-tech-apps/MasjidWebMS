<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\HifzEntry;
use App\Models\Masjid;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HifzEntry>
 */
class HifzEntryFactory extends Factory
{
    protected $model = HifzEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Default to existing rows (mirrors BehaviorAwardFactory). Tests
            // pass masjid_id + group_id + group_membership_id explicitly — the
            // membership especially, since it names the student and there is no
            // sensible default for "which child".
            'masjid_id' => Masjid::first()?->id,
            'group_id' => Group::first()?->id,
            'group_membership_id' => null,
            'heard_by_user_id' => null,
            'kind' => HifzEntry::KIND_SABAK,
            // Surat an-Naba, ayahs 1-10: a real range in the juz nearly every
            // beginner starts with, so a factory row is never a coordinate that
            // QuranIndex would reject.
            'from_surah' => 78,
            'from_ayah' => 1,
            'to_surah' => 78,
            'to_ayah' => 10,
            'quality' => HifzEntry::QUALITY_GOOD,
            'major_mistakes' => 0,
            'minor_mistakes' => 0,
            'note' => null,
            // Left null so HifzEntry's creating hook stamps `now()` — the same
            // path production takes.
            'recited_at' => null,
            'corrected_by_user_id' => null,
        ];
    }
}
