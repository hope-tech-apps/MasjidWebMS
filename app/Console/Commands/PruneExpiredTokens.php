<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/**
 * Delete personal access tokens the guard that reads them ALREADY refuses,
 * plus a grace period. Replaces `sanctum:prune-expired --hours=24`.
 *
 * ---------------------------------------------------------------------
 * WHY THE STOCK COMMAND WAS WRONG HERE
 * ---------------------------------------------------------------------
 *
 * `Laravel\Sanctum\Console\Commands\PruneExpired` deletes every row whose
 * `created_at` is older than `config('sanctum.expiration')` (480 minutes, the
 * STAFF lifetime) plus the grace, whatever kind of token it is. But a parent's
 * token carries no `expires_at`: its 30-day life is a property of the `family`
 * guard's own driver (AppServiceProvider::registerFamilyGuard, which builds a
 * Sanctum guard with `config('family.session.expiration_minutes')`). The stock
 * sweep therefore deleted every parent and app-member token about 32 hours
 * after sign-in, days before the guard that reads them would have refused it.
 *
 * ---------------------------------------------------------------------
 * THE TOKEN KINDS (verified against the code, 2026-09-29)
 * ---------------------------------------------------------------------
 *
 * | kind            | tokenable_type | name                        | abilities     | expires_at   | minted by                              | read by (guard, provider)          | lifetime rule                                   |
 * |-----------------|----------------|-----------------------------|---------------|--------------|----------------------------------------|------------------------------------|-------------------------------------------------|
 * | staff           | User           | login-token                 | staff         | null         | AuthController (login)                 | `sanctum`/`api` (driver sanctum,   | config('sanctum.expiration'), 480 min, from     |
 * |                 |                |                             |               |              |                                        | provider users)                    | created_at                                      |
 * | family (parent) | Contact        | family-token                | family        | null         | Contact::createFamilyToken             | `family` (driver sanctum-family,   | config('family.session.expiration_minutes'),    |
 * |                 |                |                             |               |              |                                        | provider contacts)                 | 43200 min = 30 days, from created_at            |
 * | member (app)    | Contact        | member-token[:login-email]  | member        | null         | Contact::createMemberToken             | `family` (routes/api.php member    | same as family: the member realm authenticates  |
 * |                 |                |                             |               |              |                                        | groups use auth:family +           | through auth:family and EnsureMemberToken only  |
 * |                 |                |                             |               |              |                                        | member.token)                      | narrows by ability, it never extends a life     |
 * | student hand-off| Contact        | student-handoff             | student:{id}  | now + 60 min | Contact::createStudentHandoffToken     | `family` (student route group)     | the guard's 30 days, SHORTENED by its expires_at|
 *
 * Every guard applies the same two tests (Laravel\Sanctum\Guard::isValidAccessToken):
 * `created_at` newer than the guard's lifetime, and `expires_at` not past. A guard
 * also refuses a token whose tokenable is not an instance of its provider's
 * model (`hasValidProvider`), which is why a Contact token can never be read by
 * the staff guards and a User token can never be read by `family`.
 *
 * ---------------------------------------------------------------------
 * WHAT DECIDES A TOKEN'S KIND: tokenable_type, NOT abilities or name
 * ---------------------------------------------------------------------
 *
 * `tokenable_type` is the only column a guard actually consults, so it is the
 * only column that says which lifetime governs the row. `abilities` and `name`
 * are labels: no guard reads them, they are `['*']`-able, older rows carry a
 * different name (`member-token` vs `member-token:login-email`), and a row with
 * garbled abilities is still accepted or refused by exactly the same rule. A
 * sweep keyed on them could mistake a live token for a stale kind and delete it.
 * They are used here only to LABEL the counts in the report.
 *
 * The lifetimes are not copied into this file. They are read from the same
 * `auth.guards` definitions the guards are built from (driver `sanctum` uses
 * `sanctum.expiration`, driver `sanctum-family` uses
 * `family.session.expiration_minutes`), so changing either config value moves
 * this sweep with it.
 *
 * A token of an UNRECOGNISED kind (a tokenable model no guard's provider names)
 * gets the LONGEST lifetime of any guard, and if any guard has none (null or 0,
 * which Sanctum treats as "never expires by age") it gets no age rule at all.
 * This command never deletes a row some guard could still accept.
 *
 * ---------------------------------------------------------------------
 * WHAT IS DELETED
 * ---------------------------------------------------------------------
 *
 *  (a) any token, of any kind, whose `expires_at` is older than now - grace;
 *  (b) per kind, a token whose `created_at` is older than now - (that kind's
 *      lifetime + grace).
 *
 * Deletion is by id in bounded chunks (`--chunk`, default 500), keyed on id so a
 * dry run and a real run walk the same rows. Counts per kind are printed AND
 * logged at info: `schedule:run` discards stdout, so the log line is the only
 * evidence a scheduled run leaves. A run that deletes nothing is normal.
 */
class PruneExpiredTokens extends Command
{
    protected $signature = 'tokens:prune-expired
        {--hours=24 : Hours to keep a token AFTER its guard would already refuse it}
        {--chunk=500 : Rows deleted per query}
        {--dry-run : Count what would be deleted and delete nothing}';

    protected $description = 'Prune API tokens that their own guard already refuses (staff, parent, member, hand-off), plus a grace period.';

    private const KINDS = ['staff', 'family', 'member', 'student-handoff', 'contact-other', 'unrecognised'];

    public function handle(): int
    {
        // At least an hour of grace: a negative value would delete tokens a guard
        // still accepts, and this command's one promise is that it never does.
        $graceMinutes = max(1, (int) $this->option('hours')) * 60;
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        // One instant for the whole sweep, so a long run applies one cutoff.
        $now = now();

        $rules = $this->ageRules();
        $known = array_merge(...array_map(fn (array $rule) => $rule['types'], $rules)) ?: [];

        $model = Sanctum::$personalAccessTokenModel;

        $candidates = function () use ($model, $now, $graceMinutes, $rules, $known): Builder {
            return $model::query()->where(function (Builder $q) use ($now, $graceMinutes, $rules, $known) {
                // (a) Every guard refuses a past expires_at, whatever the kind.
                $q->where('expires_at', '<', $now->copy()->subMinutes($graceMinutes));

                // (b) Per kind, created_at older than that kind's lifetime + grace.
                foreach ($rules as $rule) {
                    if ($rule['minutes'] === null) {
                        continue; // this kind never expires by age
                    }

                    $cutoff = $now->copy()->subMinutes($rule['minutes'] + $graceMinutes);

                    $q->orWhere(fn (Builder $r) => $r
                        ->whereIn('tokenable_type', $rule['types'])
                        ->where('created_at', '<', $cutoff));
                }

                // Unrecognised tokenable: the longest lifetime of any guard.
                $longest = $this->longestLifetime();

                if ($longest !== null) {
                    $cutoff = $now->copy()->subMinutes($longest + $graceMinutes);

                    $q->orWhere(fn (Builder $r) => $r
                        ->whereNotIn('tokenable_type', $known)
                        ->where('created_at', '<', $cutoff));
                }
            });
        };

        $counts = array_fill_keys(self::KINDS, 0);
        $lastId = 0;

        do {
            $rows = $candidates()
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'tokenable_type', 'name', 'abilities']);

            if ($rows->isEmpty()) {
                break;
            }

            $lastId = (int) $rows->last()->id;

            if (! $dryRun) {
                $model::query()->whereIn('id', $rows->pluck('id')->all())->delete();
            }

            foreach ($rows as $row) {
                $counts[$this->kindOf($row)]++;
            }
        } while ($rows->count() === $chunk);

        $total = array_sum($counts);
        $verb = $dryRun ? 'would prune' : 'pruned';

        $this->table(['kind', $dryRun ? 'would delete' : 'deleted'], array_map(
            fn (string $kind) => [$kind, $counts[$kind]],
            self::KINDS
        ));
        $this->info("tokens:prune-expired {$verb} {$total} token(s); grace {$graceMinutes} minutes.");

        Log::info('tokens:prune-expired', [
            'dry_run' => $dryRun,
            'grace_minutes' => $graceMinutes,
            'total' => $total,
            'deleted_by_kind' => $counts,
        ]);

        return self::SUCCESS;
    }

    /**
     * Label a row for the report. Labels never decide what is deleted.
     */
    private function kindOf(object $row): string
    {
        $type = (string) $row->tokenable_type;

        if ($type === (new User)->getMorphClass()) {
            return 'staff';
        }

        if ($type !== (new Contact)->getMorphClass()) {
            return 'unrecognised';
        }

        $abilities = array_map('strval', (array) $row->abilities);

        if ($row->name === 'student-handoff'
            || collect($abilities)->contains(fn (string $a) => str_starts_with($a, 'student:'))) {
            return 'student-handoff';
        }

        if (in_array(Contact::FAMILY_TOKEN_ABILITIES[0], $abilities, true)) {
            return 'family';
        }

        if (in_array(Contact::MEMBER_TOKEN_ABILITIES[0], $abilities, true)) {
            return 'member';
        }

        return 'contact-other';
    }

    /**
     * One rule per tokenable model a token guard's provider names.
     *
     * A model read by several guards takes the longest of their lifetimes (a
     * guard that accepts it later is the one that must not be pre-empted). A
     * guard with no provider can read any model, so its lifetime joins every
     * rule.
     *
     * @return array<int, array{types: array<int, string>, minutes: int|null}>
     */
    private function ageRules(): array
    {
        $guards = $this->tokenGuards();
        $models = array_values(array_unique(array_filter(array_column($guards, 'model'))));

        $rules = [];

        foreach ($models as $modelClass) {
            $lifetimes = [];

            foreach ($guards as $guard) {
                if ($guard['model'] === null || is_a($modelClass, $guard['model'], true)) {
                    $lifetimes[] = $guard['minutes'];
                }
            }

            $rules[] = [
                'types' => [(new $modelClass)->getMorphClass()],
                'minutes' => $this->longest($lifetimes),
            ];
        }

        return $rules;
    }

    private function longestLifetime(): ?int
    {
        return $this->longest(array_column($this->tokenGuards(), 'minutes'));
    }

    /**
     * @param  array<int, int|null>  $lifetimes
     */
    private function longest(array $lifetimes): ?int
    {
        // No guard at all, or any unbounded one: no age rule (null). Deleting
        // nothing by age is the safe direction.
        if ($lifetimes === [] || in_array(null, $lifetimes, true)) {
            return null;
        }

        return max($lifetimes);
    }

    /**
     * Every guard that reads personal access tokens, with the lifetime its
     * driver applies.
     *
     * @return array<int, array{model: string|null, minutes: int|null}>
     */
    private function tokenGuards(): array
    {
        $found = [];

        foreach ((array) config('auth.guards', []) as $guard) {
            $driver = $guard['driver'] ?? null;

            $minutes = match ($driver) {
                'sanctum' => config('sanctum.expiration'),
                'sanctum-family' => config('family.session.expiration_minutes', 43200),
                default => false,
            };

            if ($minutes === false) {
                continue; // not a token guard
            }

            $provider = $guard['provider'] ?? null;

            $found[] = [
                'model' => $provider ? config("auth.providers.{$provider}.model") : null,
                // Sanctum treats null and 0 alike: `! $this->expiration`.
                'minutes' => $minutes ? (int) $minutes : null,
            ];
        }

        return $found;
    }
}
