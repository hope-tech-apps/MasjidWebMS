<?php

namespace App\Console\Commands;

use App\Support\Environment;
use App\Support\ScrubStrategies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turn a freshly loaded copy of the PRODUCTION database into a safe staging
 * dataset — and refuse, loudly, to be anything else.
 *
 * ## The shape of the thing
 *
 * This command holds only the MECHANISM. The policy — which table loses its
 * rows, which column is nulled, which is rewritten and how — lives in
 * `config/staging_scrub.php`, which is a transcription of
 * `artifacts/t040-pii-inventory.md`. That separation is deliberate: the
 * inventory is reviewed by reading a data map, not by reading control flow, and
 * `tests/Feature/StagingScrubCoverageTest` can walk the config against the real
 * schema only because the config is data.
 *
 * ## Why four guards and not one
 *
 * The failure this command exists to prevent is not "it ran badly", it is "it
 * ran against production". That is a one-way door: a scrubbed `contacts` table
 * cannot be un-scrubbed, and this repository has already learned once
 * (`.claude/rules/` — the tenancy canary that called deleteAllMedia) that a
 * destructive path with one condition on it is a destructive path with none.
 * So four independent conditions must ALL hold, each of which a different kind
 * of mistake would violate:
 *
 *  1. `APP_ENV=staging` — catches running it on the wrong BOX. Checked twice:
 *     the value the framework booted with AND `config('app.env')`, which is what
 *     a `config:cache` artefact froze. Production IS config-cached, so a box
 *     whose shell says staging while its compiled config says production must
 *     refuse rather than pick a winner.
 *  2. the default connection's database name contains `staging` — catches a
 *     staging box whose `.env` still points DB_DATABASE at production, which is
 *     precisely the mistake DECISIONS.md rejected a shared managed cluster to
 *     avoid.
 *  3. `DB_HOST` must not name DigitalOcean's managed cluster — the only place
 *     production data lives. Staging runs MySQL on its own box, so a managed
 *     hostname here means the connection is wrong no matter what it is called.
 *  4. `--i-understand-this-destroys-personal-data` — catches shell history,
 *     tab-completion and a scheduler entry someone copied. This is deliberately
 *     stricter than Laravel's `ConfirmableTrait`, which this repository does not
 *     use: a typed "yes" at a prompt is one keystroke, and the shape here
 *     (environment check + explicit flag + loud refusal) is the one
 *     `DatabaseSeeder` and `demo:seed-school` already establish.
 *
 * Each refusal names the guard that failed, because an operator who cannot tell
 * WHICH condition is unmet will start disabling them one at a time.
 *
 * `--dry-run` is exempt from guard 4 and only from guard 4. It writes nothing,
 * so demanding the destroy flag to print a plan would just train people to type
 * the flag out of habit before the run that matters.
 *
 * ## Why raw DB::table() everywhere
 *
 * Eloquent would be actively wrong here, in three separate ways:
 *
 *  - **The tenant scope must not apply.** `BelongsToMasjid` adds a global scope
 *    filtering by the bound tenant. If a tenant happened to be bound, the scrub
 *    would silently clean ONE masjid and leave every other organisation's
 *    personal data sitting on staging. `DB::table()` has no global scopes.
 *  - **Soft-deleted rows must be scrubbed too.** A `forceDelete`d-but-not-really
 *    contact is still a person. `SoftDeletes` would hide them; the query builder
 *    does not filter `deleted_at` at all.
 *  - **Casts and model events must not fire.** `encrypted` casts would try to
 *    decrypt with production's key; `updating` hooks would stamp `updated_by`
 *    and fire notifications. Neither is wanted while rewriting three hundred
 *    columns.
 *
 * ## Transactions and chunking
 *
 * One transaction per table, and every statement inside it chunked by primary
 * key. The whole run is DDL-free (no TRUNCATE — MySQL treats that as DDL, which
 * commits the surrounding transaction and cannot be rolled back), so a table
 * that fails half way leaves that table exactly as it was and the failure names
 * the table AND the column.
 */
class StagingScrub extends Command
{
    /**
     * The flag is spelled out rather than named `--force` on purpose: an
     * operator reading their own shell history should be able to see what they
     * consented to without looking the command up.
     */
    protected $signature = 'staging:scrub
                            {--i-understand-this-destroys-personal-data : Required for a real run. Confirms that this database is a disposable copy and that irreversibly rewriting every personal-data column in it is the intent.}
                            {--dry-run : Print the plan — per table, rows affected and the action on each column — and touch nothing.}
                            {--chunk= : Rows per batched UPDATE (default: config staging_scrub.chunk).}';

    protected $description = 'Scrub a production copy loaded into the staging database. Refuses to run anywhere but staging.';

    /** Every table this command writes to is keyed on a plain auto-increment `id`. */
    private const KEY_COLUMN = 'id';

    /** Set by handle() so every helper agrees on the dialect. */
    private string $driver = 'sqlite';

    private int $chunk = 5000;

    /** @var array<string, string> run-scoped values the strategies need (the one bcrypt hash). */
    private array $context = [];

    public function handle(): int
    {
        $failures = $this->guardFailures();

        if ($failures !== []) {
            $this->components->error('staging:scrub refused to run.');

            foreach ($failures as $guard => $why) {
                $this->line("  <fg=red>guard `{$guard}` failed:</> {$why}");
            }

            return self::FAILURE;
        }

        $this->driver = DB::connection()->getDriverName();
        $this->chunk = (int) ($this->option('chunk') ?: config('staging_scrub.chunk', 5000));

        if ($this->chunk < 1) {
            $this->components->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        // One hash for every staging admin, computed once. See the `password`
        // strategy; bcrypting per row would dominate the run.
        $this->context['password_hash'] = Hash::make((string) config('staging_scrub.staging_password'));

        // Everything that can be known before a byte is written is checked
        // here, so a config typo is a refusal rather than a half-scrubbed table.
        try {
            $this->validateConfigAgainstSchema();

            $drops = $this->planDrops();
            $tables = $this->planTables();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }


        if ($this->option('dry-run')) {
            $this->printPlan($drops, $tables);

            return self::SUCCESS;
        }

        $this->components->info('Scrubbing '.$this->databaseName().' — this rewrites personal data irreversibly.');

        $deleted = $this->runDrops($drops);
        $updated = $this->runTables($tables);

        $this->newLine();
        $this->components->info('Verifying.');

        if (! $this->verify()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Done. %s rows deleted across %d tables; %s column writes across %d tables.',
            number_format($deleted),
            count($drops),
            number_format($updated),
            count($tables),
        ));

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, string> guard name => why it failed (empty when all pass)
     */
    private function guardFailures(): array
    {
        $failures = [];

        // GUARD 1 — the box. `staging` is set in staging's .env and nowhere
        // else; production is `production` and the suite is `testing`.
        //
        // BOTH names are checked, and they are genuinely two facts.
        // `app()->environment()` is the value the framework booted with;
        // `Environment::name()` reads `config('app.env')`, which is what a
        // `config:cache` artefact froze — and production is config-cached. A box
        // whose shell says `staging` while its compiled config still says
        // `production` is precisely the stale-bootstrap-cache shape that has bitten
        // this project before, and it must refuse rather than pick a winner.
        // `Environment::name()` also falls back to `production` when APP_ENV is
        // blank, so a misconfigured box fails toward refusing
        // (`.claude/rules/environments.md`).
        $booted = (string) app()->environment();
        $configured = Environment::name();

        if ($booted !== 'staging' || $configured !== 'staging') {
            $failures['app-env-is-staging'] = sprintf(
                'APP_ENV is `%s` (booted) / `%s` (config). Both must be `staging`; this command only ever runs on the staging box.',
                $booted,
                $configured,
            );
        }

        // GUARD 2 — the database. A staging box whose DB_DATABASE still points
        // at production is the exact failure DECISIONS.md (2026-09-10) rejected
        // a second schema on the managed cluster in order to avoid. basename()
        // so a sqlite path like /var/lib/masjids_staging.sqlite also passes.
        $database = $this->databaseName();

        if (! str_contains(strtolower(basename($database)), 'staging')) {
            $failures['database-name-contains-staging'] = sprintf(
                'The default connection `%s` points at database `%s`, whose name does not contain "staging".',
                (string) config('database.default'),
                $database === '' ? '(unset)' : $database,
            );
        }

        // GUARD 3 — the host. Production's data lives on DigitalOcean's managed
        // MySQL cluster and nowhere else; staging runs MySQL locally on its own
        // droplet. A managed hostname here means the connection is production's
        // whatever the database happens to be called.
        $host = $this->connectionHost();

        if ($host !== '' && str_contains(strtolower($host), 'ondigitalocean.com')) {
            $failures['db-host-is-not-the-managed-cluster'] = sprintf(
                'DB_HOST is `%s`, which is the managed cluster that holds production.',
                $host,
            );
        }

        // GUARD 4 — the human. Exempt for --dry-run, which writes nothing:
        // requiring the flag to print a plan only teaches people to type it
        // reflexively before the run that does matter.
        if (! $this->option('dry-run') && ! $this->option('i-understand-this-destroys-personal-data')) {
            $failures['explicit-destroy-flag'] = 'Pass --i-understand-this-destroys-personal-data to confirm, or --dry-run to see the plan first.';
        }

        return $failures;
    }

    private function databaseName(): string
    {
        $connection = (string) config('database.default');

        return (string) config("database.connections.{$connection}.database", '');
    }

    private function connectionHost(): string
    {
        $connection = (string) config('database.default');

        // Read the resolved connection first, then the raw env, so neither a
        // config cache nor a missing connection key can hide a managed host.
        return (string) (config("database.connections.{$connection}.host") ?: env('DB_HOST', ''));
    }

    /*
    |--------------------------------------------------------------------------
    | Config validation — everything that can be checked before writing a byte
    |--------------------------------------------------------------------------
    */

    /**
     * Fail on the PLAN, never half way through a table.
     *
     * A config typo that is only discovered on the four-hundredth UPDATE leaves
     * the database in a state where some columns are scrubbed and some are not,
     * and no operator can tell which without reading the log line by line.
     */
    private function validateConfigAgainstSchema(): void
    {
        $neverWrite = (array) config('staging_scrub.never_write', []);

        foreach ($this->writeTargets() as [$table, $column, $kind]) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("config/staging_scrub.php names table `{$table}` ({$kind}), which does not exist in this schema.");
            }

            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("config/staging_scrub.php names `{$table}.{$column}` ({$kind}), which does not exist in this schema.");
            }

            // THE GENERATED-COLUMN TRAP. `masjids.active_owner_user_id` and
            // `masjids.active_stripe_account_id` are MySQL VIRTUAL generated
            // columns backing conditional unique indexes; an UPDATE naming one
            // is an error, not a no-op. They follow their source column, so
            // scrubbing the source is both necessary and sufficient.
            if (isset($neverWrite["{$table}.{$column}"])) {
                throw new RuntimeException(
                    "config/staging_scrub.php targets `{$table}.{$column}` ({$kind}), which is listed under never_write: "
                    .$neverWrite["{$table}.{$column}"]
                );
            }
        }

        // Every table that keeps its rows must expose the key the fakes are
        // derived from. Without it a scrub of a UNIQUE column would have to
        // write a constant, which aborts the UPDATE on the second row.
        foreach ($this->tablesThatKeepTheirRows() as $table) {
            if (! Schema::hasColumn($table, self::KEY_COLUMN)) {
                throw new RuntimeException(
                    "config/staging_scrub.php scrubs columns on `{$table}`, which has no `".self::KEY_COLUMN.'` column. '
                    .'Every fake is derived from that key, so there is nothing row-unique to build one from.'
                );
            }
        }

        // A NOT NULL column cannot be nulled: MySQL in strict mode aborts the
        // transaction, and sqlite raises a constraint violation. Catch it here
        // rather than at row 1 of the table.
        foreach ($this->nullTargets() as [$table, $column, $kind]) {
            if ($this->isDropped($table)) {
                continue;
            }

            if (! $this->columnIsNullable($table, $column)) {
                throw new RuntimeException(
                    "config/staging_scrub.php would null `{$table}.{$column}` ({$kind}), but that column is NOT NULL. "
                    .'Use an `anonymise` strategy (fixed:… for a forced state) or drop the table\'s rows instead.'
                );
            }
        }

        foreach ((array) config('staging_scrub.anonymise', []) as $table => $columns) {
            foreach ($columns as $column => $spec) {
                $strategy = is_array($spec) ? ($spec['strategy'] ?? '') : $spec;

                if (! is_string($strategy) || $strategy === '') {
                    throw new RuntimeException("config/staging_scrub.php has no strategy for `{$table}.{$column}`.");
                }

                if (in_array($strategy, ScrubStrategies::PHP_STRATEGIES, true)) {
                    continue;
                }

                // Compiling it now surfaces an unknown strategy name on the plan.
                ScrubStrategies::expression($strategy, $table, $column, $this->keyFor($table), $this->driver, $this->context);
            }
        }
    }

    /**
     * Every (table, column) this command would write, across all three maps.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function writeTargets(): array
    {
        $targets = $this->nullTargets();

        foreach ((array) config('staging_scrub.anonymise', []) as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $targets[] = [$table, (string) $column, 'anonymise'];
            }
        }

        return $targets;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function nullTargets(): array
    {
        $targets = [];

        foreach ((array) config('staging_scrub.encrypted_null', []) as $table => $columns) {
            foreach ($columns as $column) {
                $targets[] = [$table, (string) $column, 'encrypted_null'];
            }
        }

        foreach ((array) config('staging_scrub.null_columns', []) as $table => $columns) {
            foreach ($columns as $column) {
                $targets[] = [$table, (string) $column, 'null_columns'];
            }
        }

        return $targets;
    }

    /**
     * The distinct tables that lose column content but keep their rows.
     *
     * @return list<string>
     */
    private function tablesThatKeepTheirRows(): array
    {
        $tables = array_column($this->writeTargets(), 0);

        return array_values(array_unique(array_filter($tables, fn (string $t) => ! $this->isDropped($t))));
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (bool) ($definition['nullable'] ?? true);
            }
        }

        return true;
    }

    private function isDropped(string $table): bool
    {
        return array_key_exists($table, (array) config('staging_scrub.drop_rows', []));
    }

    /**
     * The primary key that chunking walks and that every fake is derived from.
     *
     * Every table this command writes to has a plain auto-increment `id`. A
     * table without one cannot be handled by this command at all: there would be
     * nothing row-unique to build a fake out of, so a UNIQUE index on the column
     * being scrubbed would abort the UPDATE on the second row. That is checked
     * on the plan (see validateConfigAgainstSchema) rather than discovered here.
     */
    private function keyFor(string $table): string
    {
        return self::KEY_COLUMN;
    }

    /*
    |--------------------------------------------------------------------------
    | Planning
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array{table: string, reason: string, rows: int}>
     */
    private function planDrops(): array
    {
        $plan = [];

        foreach ((array) config('staging_scrub.drop_rows', []) as $table => $reason) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("config/staging_scrub.php drops table `{$table}`, which does not exist in this schema.");
            }

            $plan[] = ['table' => $table, 'reason' => (string) $reason, 'rows' => DB::table($table)->count()];
        }

        return $plan;
    }

    /**
     * One entry per table that keeps its rows but loses column content.
     *
     * @return list<array{table: string, rows: int, ops: list<array{column: string, action: string, kind: string, where: ?string}>}>
     */
    private function planTables(): array
    {
        /** @var array<string, list<array{column: string, action: string, kind: string, where: ?string}>> $byTable */
        $byTable = [];

        foreach ($this->nullTargets() as [$table, $column, $kind]) {
            if ($this->isDropped($table)) {
                // Listed in encrypted_null for completeness — the whole table's
                // rows are deleted, so the column is already covered.
                continue;
            }

            $byTable[$table][] = ['column' => $column, 'action' => 'NULL', 'kind' => $kind, 'where' => null];
        }

        foreach ((array) config('staging_scrub.anonymise', []) as $table => $columns) {
            if ($this->isDropped($table)) {
                continue;
            }

            foreach ($columns as $column => $spec) {
                $byTable[$table][] = [
                    'column' => (string) $column,
                    'action' => is_array($spec) ? (string) $spec['strategy'] : (string) $spec,
                    'kind' => 'anonymise',
                    'where' => is_array($spec) ? ($spec['where'] ?? null) : null,
                ];
            }
        }

        $plan = [];

        foreach ($byTable as $table => $ops) {
            $plan[] = ['table' => $table, 'rows' => DB::table($table)->count(), 'ops' => $ops];
        }

        return $plan;
    }

    private function printPlan(array $drops, array $tables): void
    {
        $this->components->info('DRY RUN — nothing below has been written. Database: '.$this->databaseName());

        $this->newLine();
        $this->line('<options=bold>Rows to delete</>');
        $this->table(
            ['table', 'rows', 'why'],
            array_map(
                static fn (array $d) => [$d['table'], number_format($d['rows']), Str::limit($d['reason'], 90)],
                $drops,
            ),
        );

        $this->newLine();
        $this->line('<options=bold>Columns to rewrite</>');

        $rows = [];

        foreach ($tables as $t) {
            foreach ($t['ops'] as $i => $op) {
                $rows[] = [
                    $i === 0 ? $t['table'] : '',
                    $i === 0 ? number_format($t['rows']) : '',
                    $op['column'],
                    $op['action'].($op['where'] ? "  (only where {$op['where']})" : ''),
                ];
            }
        }

        $this->table(['table', 'rows', 'column', 'action'], $rows);

        $this->newLine();
        $this->components->info(sprintf(
            '%d tables would be emptied (%s rows); %d columns would be rewritten across %d tables.',
            count($drops),
            number_format(array_sum(array_column($drops, 'rows'))),
            array_sum(array_map(static fn (array $t) => count($t['ops']), $tables)),
            count($tables),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    */

    private function runDrops(array $drops): int
    {
        $total = 0;

        foreach ($drops as $drop) {
            $table = $drop['table'];

            // DELETE, never TRUNCATE. TRUNCATE is DDL on MySQL: it commits the
            // surrounding transaction, cannot be rolled back, and is refused
            // outright on a table another table's foreign key references. The
            // config lists these tables CHILD FIRST so no key ever has to be
            // disabled.
            $deleted = $this->guarded($table, null, fn () => DB::transaction(fn () => DB::table($table)->delete()));

            $total += $deleted;
            $this->line(sprintf('  <fg=gray>deleted</> %-32s %s', $table, number_format($deleted)));
        }

        return $total;
    }

    private function runTables(array $tables): int
    {
        $total = 0;

        foreach ($tables as $plan) {
            $table = $plan['table'];
            $key = $this->keyFor($table);

            // One transaction per table. The run is DDL-free, so a table that
            // fails rolls back whole and the operator can re-run after fixing
            // the config: every strategy is deterministic, so re-running a
            // table that already succeeded writes the same bytes.
            $written = DB::transaction(function () use ($plan, $table, $key) {
                $count = 0;

                foreach ($plan['ops'] as $op) {
                    $count += $this->guarded(
                        $table,
                        $op['column'],
                        fn () => $this->applyColumn($table, $key, $op),
                    );
                }

                return $count;
            });

            $total += $written;
            $this->line(sprintf('  <fg=gray>scrubbed</> %-31s %d columns, %s writes', $table, count($plan['ops']), number_format($written)));
        }

        return $total;
    }

    /**
     * Apply one column's action across the table, chunked by primary key.
     */
    private function applyColumn(string $table, string $key, array $op): int
    {
        $column = $op['column'];
        $action = $op['action'];

        if (in_array($action, ScrubStrategies::PHP_STRATEGIES, true)) {
            return $this->applyJsonColumn($table, $key, $column, $action, $op['where']);
        }

        $value = $action === 'NULL'
            ? null
            : DB::raw(ScrubStrategies::expression($action, $table, $column, $key, $this->driver, $this->context));

        // `fixed:` forces a state (stripe_charges_enabled=0, an SMS sender back
        // to `unregistered`, a NOT NULL user_agent) and therefore writes every
        // row. Everything else PRESERVES NULL: a column that was empty in
        // production stays empty, so "does this contact have portal login?"
        // still answers correctly on staging.
        $preserveNull = $action !== 'NULL' && ! str_starts_with($action, 'fixed:');

        return $this->overChunks($table, $key, function (int $lo, int $hi) use ($table, $key, $column, $value, $preserveNull, $op) {
            $query = DB::table($table)->whereBetween($key, [$lo, $hi]);

            if ($preserveNull) {
                $query->whereNotNull($column);
            }

            if ($op['where'] !== null) {
                $query->whereRaw($op['where']);
            }

            return $query->update([$column => $value]);
        });
    }

    /**
     * The two json strategies, applied row by row.
     *
     * There is no portable SQL that walks an arbitrary document replacing leaf
     * values, and `form_responses.data` is the whole reason this command cannot
     * be a SQL script: its keys are authored by each tenant, so the only scrub
     * that both removes the answers and leaves the admin table renderable is a
     * structural one in PHP.
     */
    private function applyJsonColumn(string $table, string $key, string $column, string $strategy, ?string $where): int
    {
        return $this->overChunks($table, $key, function (int $lo, int $hi) use ($table, $key, $column, $strategy, $where) {
            $query = DB::table($table)
                ->whereBetween($key, [$lo, $hi])
                ->whereNotNull($column)
                ->select($key, $column);

            if ($where !== null) {
                $query->whereRaw($where);
            }

            $written = 0;

            foreach ($query->get() as $row) {
                $raw = $row->{$column};

                if (! is_string($raw) || $raw === '') {
                    continue;
                }

                $decoded = json_decode($raw, true);

                // A column that is not valid json cannot be walked. It is still
                // free text that may hold personal data, so it is replaced
                // wholesale rather than skipped.
                $scrubbed = json_last_error() === JSON_ERROR_NONE
                    ? ($strategy === 'json_replace'
                        ? ScrubStrategies::jsonReplace($decoded)
                        : ScrubStrategies::jsonContacts($decoded))
                    : [ScrubStrategies::JSON_PLACEHOLDER];

                DB::table($table)
                    ->where($key, $row->{$key})
                    ->update([$column => json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

                $written++;
            }

            return $written;
        });
    }

    /**
     * Walk the table in primary-key ranges.
     *
     * Ranges rather than OFFSET: the rows being updated are the rows being
     * scanned, so an offset walk would skip or repeat rows as the result set
     * shifts under it. Chunking at all is what keeps a single UPDATE from
     * locking `contacts` or `group_messages` whole on the staging box's
     * single-core MySQL.
     */
    private function overChunks(string $table, string $key, callable $work): int
    {
        $min = DB::table($table)->min($key);
        $max = DB::table($table)->max($key);

        if ($min === null || $max === null) {
            return 0;
        }

        $written = 0;

        for ($lo = (int) $min; $lo <= (int) $max; $lo += $this->chunk) {
            $written += (int) $work($lo, min($lo + $this->chunk - 1, (int) $max));
        }

        return $written;
    }

    /**
     * Run one unit of work, and make any failure name the table and column.
     *
     * Without this a strict-mode data-truncation error reads
     * "SQLSTATE[22001]: String data, right truncated" and nothing else — which
     * of three hundred columns it was is left as an exercise.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function guarded(string $table, ?string $column, callable $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            $where = $column === null ? $table : "{$table}.{$column}";

            throw new RuntimeException("staging:scrub failed on `{$where}`: ".$e->getMessage(), 0, $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    */

    /**
     * Re-read what was just written and prove it.
     *
     * The scrub is a large pile of UPDATE statements, and an UPDATE that
     * matched no rows looks exactly like an UPDATE that worked. This pass is
     * the difference between "the command exited 0" and "no production email
     * address is in this database":
     *
     *  1. every column anonymised with the `email` strategy must end in
     *     `.invalid`, and every `phone` column must carry the fictional prefix;
     *  2. and independently of the config — because the config is the thing
     *     that could be wrong — every text column of `contacts` and `users` is
     *     swept for an `@` that does not end in `.invalid`. That catches a
     *     column nobody thought to list, which is the failure mode the config
     *     cannot catch by construction.
     */
    private function verify(): bool
    {
        $problems = [];
        $checked = 0;

        foreach ((array) config('staging_scrub.anonymise', []) as $table => $columns) {
            if ($this->isDropped($table)) {
                continue;
            }

            foreach ($columns as $column => $spec) {
                $strategy = is_array($spec) ? (string) $spec['strategy'] : (string) $spec;
                $where = is_array($spec) ? ($spec['where'] ?? null) : null;

                $pattern = match ($strategy) {
                    'email' => '%@'.ScrubStrategies::EMAIL_DOMAIN,
                    'phone' => ScrubStrategies::PHONE_PREFIX.'%',
                    default => null,
                };

                if ($pattern === null) {
                    continue;
                }

                $checked++;

                $query = DB::table($table)->whereNotNull($column)->where($column, 'not like', $pattern);

                if ($where !== null) {
                    $query->whereRaw($where);
                }

                $escaped = $query->count();

                if ($escaped > 0) {
                    $problems[] = "{$table}.{$column}: {$escaped} value(s) do not match `{$pattern}`";
                }
            }
        }

        // The independent sweep. `contacts` and `users` are the two tables that
        // hold a real person's identity in this schema, so any `@` left in
        // either that is not on a `.invalid` domain is a leak, whatever column
        // it is hiding in.
        foreach (['contacts', 'users'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($this->textColumns($table) as $column) {
                $checked++;

                $leaked = DB::table($table)
                    ->where($column, 'like', '%@%')
                    ->where($column, 'not like', '%.invalid')
                    ->count();

                if ($leaked > 0) {
                    $problems[] = "{$table}.{$column}: {$leaked} value(s) contain `@` but do not end in `.invalid`";
                }
            }
        }

        if ($problems !== []) {
            $this->components->error('VERIFICATION FAILED — personal data survived the scrub. This database is NOT safe to use as staging.');

            foreach ($problems as $problem) {
                $this->line("  <fg=red>{$problem}</>");
            }

            return false;
        }

        $this->line("  <fg=gray>verified</> {$checked} column(s): every address ends in .invalid, every phone is +1 555-01xx.");

        return true;
    }

    /**
     * String-ish columns only. A `LIKE '%@%'` against an integer or a timestamp
     * is harmless but pointless, and on MySQL it forces a conversion per row.
     *
     * @return list<string>
     */
    private function textColumns(string $table): array
    {
        $textual = ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string', 'json'];

        $columns = [];

        foreach (Schema::getColumns($table) as $definition) {
            $type = strtolower((string) ($definition['type_name'] ?? $definition['type'] ?? ''));

            foreach ($textual as $candidate) {
                if (str_starts_with($type, $candidate)) {
                    $columns[] = (string) $definition['name'];
                    break;
                }
            }
        }

        return $columns;
    }
}
