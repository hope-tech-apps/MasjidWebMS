<?php

use App\Support\SubjectKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seed the Subjects list for Al-Razi School (org 14) and Burlington Islamic
 * Sunday School (org 18) (T-001.3 / T-001.4 / T-001.5; owner, 2026-09-28, B3:
 * "seed the Subjects list on prod: Qur'an, Islamic Studies, Arabic Language for
 * every Al-Razi grade and BISS, alongside Al-Razi's existing guide subjects").
 *
 * ## What it writes
 *
 *   - BISS (18): Qur'an, Islamic Studies, Arabic Language, every grade.
 *   - Al-Razi (14): those three, plus the subjects of Al-Razi's own weekly guide
 *     (`curriculum_weeks`) EXCEPT the combined "Qur’an & Islamic Studies" column,
 *     every grade.
 *
 * The three names are the ones the report card already uses
 * (ReportCardTemplate::CORE) and the school's own document names
 * (RECON-PLAN section 5.1), so Arabic is a subject at every grade by name and
 * Islamic Studies is separate from Arabic (T-001.4, T-001.5). The guide's own
 * combined weekly column is NOT touched, split or hidden: splitting it would be
 * authoring Islamic content, and only a school-supplied revision may replace it.
 * Lesson plans therefore list the combined column beside the three separate
 * subjects, and the standards search only ever finds rows under the combined one.
 * No standard is created for any of the three.
 *
 * `grade_labels` is NULL, "every grade": a grade nobody listed never loses a
 * subject, and no label normaliser is needed for the seed.
 *
 * ## Guarded, one transaction, idempotent
 *
 *   1. Each org is seeded only if its row exists, is a school, is not trashed,
 *      and its NAME says what the id is supposed to be ("razi" for 14, "sunday
 *      school" for 18). Anywhere else (a fresh database, a test run, staging
 *      where an id is some other organisation) it writes nothing and, when a row
 *      exists but is not the school, logs one warning.
 *   2. Everything for both orgs is one transaction.
 *   3. It only INSERTS. A subject the office already has, under any spelling
 *      (SubjectKey), is left exactly as it is, so running it twice, or after the
 *      office has started editing, changes nothing the office decided.
 *   4. It logs one WARNING line saying how many rows it wrote per school:
 *      production runs LOG_LEVEL=warning, and a data change that leaves no line
 *      is a change nobody can find.
 *
 * Written with the query builder, so no model event runs from inside a migration
 * (the models' `saving` hooks compute `name_key`; this does it itself with the
 * same SubjectKey).
 *
 * ## Reversible, and it refuses to lose what the office typed
 *
 * `down()` deletes only the rows this migration wrote AND nobody has since
 * touched: the seeded name, position and NULL grades, with `updated_at` still
 * equal to `created_at` (an edit through the Subjects screen moves it). An edited
 * or office-created row is left, which is also what makes the create-table
 * migration's `down()` refuse: rolling back the whole W3 batch stops rather than
 * destroying the office's list.
 *
 * Ships only after the owner's yes (B3). Hold it out of a deploy that has not had
 * one; the code around it works with an empty list.
 */
return new class extends Migration
{
    /** [masjid id, a word its name must contain]. */
    private const SCHOOLS = [
        14 => 'razi',
        18 => 'sunday school',
    ];

    /** The three the school exists for, in the report card's own words, first in the list. */
    private const CORE = [
        "Qur'an" => 0,
        'Islamic Studies' => 1,
        'Arabic Language' => 2,
    ];

    /** Al-Razi's own guide subjects follow the core, alphabetically (equal position, name breaks the tie). */
    private const GUIDE_POSITION = 10;

    /** The guide's combined weekly column, by key. Never seeded as a subject of its own. */
    private const COMBINED = ['quran & islamic studies', 'quran and islamic studies'];

    public function up(): void
    {
        $plans = [];

        foreach (self::SCHOOLS as $id => $word) {
            if ($this->isSchool($id, $word)) {
                $plans[$id] = $this->planFor($id);
            }
        }

        if ($plans === []) {
            return;
        }

        $wrote = [];

        DB::transaction(function () use ($plans, &$wrote): void {
            $now = now();

            foreach ($plans as $masjidId => $subjects) {
                $wrote[$masjidId] = 0;

                foreach ($subjects as $name => $position) {
                    $key = SubjectKey::for($name);

                    if (DB::table('school_subjects')->where('masjid_id', $masjidId)->where('name_key', $key)->exists()) {
                        continue;
                    }

                    DB::table('school_subjects')->insert([
                        'masjid_id' => $masjidId,
                        'name' => $name,
                        'name_key' => $key,
                        'grade_labels' => null,
                        'position' => $position,
                        // One instant for both: `down()` reads "still equal" as "not edited since".
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $wrote[$masjidId]++;
                }
            }
        });

        Log::warning('School subjects seeded', ['rows_written_by_masjid' => $wrote]);
    }

    public function down(): void
    {
        foreach (self::SCHOOLS as $id => $word) {
            if (! $this->isSchool($id, $word)) {
                continue;
            }

            foreach ($this->planFor($id) as $name => $position) {
                DB::table('school_subjects')
                    ->where('masjid_id', $id)
                    ->where('name_key', SubjectKey::for($name))
                    ->where('name', $name)
                    ->where('position', $position)
                    ->whereNull('grade_labels')
                    ->whereColumn('updated_at', 'created_at')
                    ->delete();
            }
        }
    }

    /**
     * The subjects to seed for a school, `name => position`. Al-Razi's guide is
     * read from its own rows; nothing is invented.
     *
     * @return array<string,int>
     */
    private function planFor(int $masjidId): array
    {
        $plan = self::CORE;
        $taken = array_map(fn (string $n) => SubjectKey::for($n), array_keys($plan));

        if ($masjidId !== 14) {
            return $plan;
        }

        $guide = DB::table('curriculum_weeks')
            ->where('masjid_id', $masjidId)
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject');

        foreach ($guide as $subject) {
            $name = SubjectKey::clean((string) $subject);
            $key = SubjectKey::for($name);

            if ($name === null || in_array($key, self::COMBINED, true) || in_array($key, $taken, true)) {
                continue;
            }

            $plan[$name] = self::GUIDE_POSITION;
            $taken[] = $key;
        }

        return $plan;
    }

    /** The org exists, is a live school, and its name says it is the school this id stands for. */
    private function isSchool(int $id, string $word): bool
    {
        $row = DB::table('masjids')->where('id', $id)->first(['id', 'name', 'org_type', 'deleted_at']);

        $ok = $row !== null
            && $row->deleted_at === null
            && $row->org_type === 'school'
            && stripos((string) $row->name, $word) !== false;

        if (! $ok && $row !== null) {
            Log::warning('School subjects not seeded: the organisation is not the expected school', [
                'masjid_id' => $id,
                'org_type' => $row->org_type,
            ]);
        }

        return $ok;
    }
};
