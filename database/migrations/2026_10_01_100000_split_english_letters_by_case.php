<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T-004.2 — the English tracker learns capitals and lower case separately.
 *
 * A DATA migration over `arabic_letter_progress`: each English mark stored under
 * a bare letter id (`a`) becomes TWO marks, `a.upper` and `a.lower`, both
 * carrying what the child had (status, note, marked_by, mastered_at, timestamps).
 * The legacy row is then removed, because after this migration a bare letter is
 * not a drill and would only sit there being counted by nothing and exported as
 * noise.
 *
 * ## Why copy to BOTH cases, and keep the original mastered date
 *
 * Owner question B2's default (RECON-PLAN §5.2): a child who was marked
 * "mastered" on `a` on the 14th was shown the pair "Aa" and was marked on the
 * pair. Starting them from zero would erase a term of recorded progress; marking
 * only one case would invent a distinction the teacher never made. So both cases
 * inherit the mark, and `mastered_at` is copied unchanged (`mastered_at` is a
 * ledger: the date a parent reads must not move to the day of the deploy). Nine
 * production rows are `not_started` yet still carry a `mastered_at`; they are
 * copied as they are.
 *
 * ## Prod waits for the owner
 *
 * This rewrites production rows, and B2 has not been answered. `bin/deploy` runs
 * migrations automatically, so this file must NOT ship until the owner says yes;
 * see DECISIONS.md.
 *
 * ## The ids are `x.upper` / `x.lower`, never `A` / `a`
 *
 * Production's `drill_id` is `utf8mb4_unicode_ci`: case-INSENSITIVE, so `A` and
 * `a` are one key under the unique index (group_membership_id, alphabet,
 * drill_id). The suffix form cannot collide. SQLite (the suite) compares case
 * SENSITIVELY, so a test that passed with `A`/`a` would say nothing about
 * production; the ids themselves are what is pinned.
 *
 * ## Rules this file follows (.claude/rules/migrations.md, RECON-PLAN §3.1)
 *
 *  - only `DB::table()`, no raw SQL, so it is driver-neutral;
 *  - idempotent: an `x.upper` / `x.lower` that already exists is left alone, and a
 *    second run finds no legacy row to convert;
 *  - pre-flight: an English row whose id is neither a bare letter nor a `x.case`
 *    pair aborts BEFORE anything is written, naming the ids;
 *  - one transaction, so a failure part-way leaves the table as it was;
 *  - `down()` refuses rather than lose data. It can merge the two cases back into
 *    one only where they agree on everything that matters; a child marked
 *    differently on the two cases (which the split exists to allow) cannot be
 *    folded into one mark without discarding one of them, so it throws.
 *
 * Only English rows are read or written; the Arabic qāʿidah rows are untouched.
 */
return new class extends Migration
{
    private const TABLE = 'arabic_letter_progress';
    private const ALPHABET = 'english';
    private const CASES = ['upper', 'lower'];

    public function up(): void
    {
        $rows = DB::table(self::TABLE)->where('alphabet', self::ALPHABET)->get();

        $unrecognised = $rows
            ->filter(fn ($r) => ! $this->isLegacy($r->drill_id) && ! $this->isSplit($r->drill_id))
            ->pluck('drill_id')->unique()->values()->all();

        if ($unrecognised !== []) {
            throw new RuntimeException(
                'split_english_letters_by_case: English rows with drill ids that are neither a letter nor letter.upper/lower: '
                .implode(', ', array_slice($unrecognised, 0, 20))
                .'. Nothing was changed; correct or remove those rows, then migrate again.'
            );
        }

        // Keyed lower-case: production compares drill ids case-insensitively, so
        // the existence check must too or it would miss a row the index would
        // reject.
        $present = [];
        foreach ($rows as $r) {
            $present[$r->group_membership_id.'|'.strtolower($r->drill_id)] = true;
        }

        DB::transaction(function () use ($rows, &$present): void {
            foreach ($rows->filter(fn ($r) => $this->isLegacy($r->drill_id)) as $legacy) {
                $letter = strtolower($legacy->drill_id);

                foreach (self::CASES as $case) {
                    $id = $letter.'.'.$case;
                    $key = $legacy->group_membership_id.'|'.$id;

                    if (isset($present[$key])) {
                        continue;
                    }

                    $copy = (array) $legacy;
                    unset($copy['id']);
                    $copy['drill_id'] = $id;

                    DB::table(self::TABLE)->insert($copy);
                    $present[$key] = true;
                }

                DB::table(self::TABLE)->where('id', $legacy->id)->delete();
            }
        });
    }

    public function down(): void
    {
        $rows = DB::table(self::TABLE)->where('alphabet', self::ALPHABET)->get();

        $byLetter = [];
        foreach ($rows->filter(fn ($r) => $this->isSplit($r->drill_id)) as $r) {
            [$letter, $case] = explode('.', $r->drill_id, 2);
            $byLetter[$r->group_membership_id.'|'.$letter][$case] = $r;
        }

        $legacyPresent = [];
        foreach ($rows->filter(fn ($r) => $this->isLegacy($r->drill_id)) as $r) {
            $legacyPresent[$r->group_membership_id.'|'.strtolower($r->drill_id)] = true;
        }

        $diverged = [];
        foreach ($byLetter as $key => $pair) {
            $upper = $pair['upper'] ?? null;
            $lower = $pair['lower'] ?? null;

            if ($upper === null || $lower === null
                || $upper->status !== $lower->status
                || $upper->note !== $lower->note
                || $upper->mastered_at !== $lower->mastered_at
                || isset($legacyPresent[$key])) {
                $diverged[] = $key;
            }
        }

        if ($diverged !== []) {
            throw new RuntimeException(
                'split_english_letters_by_case cannot be rolled back without losing data: capitals and lower case differ '
                .'(or only one case exists) for membership|letter '.implode(', ', array_slice($diverged, 0, 20))
                .(count($diverged) > 20 ? ' and '.(count($diverged) - 20).' more' : '')
                .'. Nothing was changed.'
            );
        }

        DB::transaction(function () use ($byLetter): void {
            foreach ($byLetter as $key => $pair) {
                $copy = (array) $pair['upper'];
                unset($copy['id']);
                $copy['drill_id'] = explode('|', $key, 2)[1];

                DB::table(self::TABLE)->insert($copy);
                DB::table(self::TABLE)->whereIn('id', [$pair['upper']->id, $pair['lower']->id])->delete();
            }
        });
    }

    private function isLegacy(string $drillId): bool
    {
        return preg_match('/^[a-zA-Z]$/', $drillId) === 1;
    }

    private function isSplit(string $drillId): bool
    {
        return preg_match('/^[a-z]\.(upper|lower)$/', $drillId) === 1;
    }
};
