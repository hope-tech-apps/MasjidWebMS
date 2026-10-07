<?php

namespace App\Console\Commands;

use App\Support\FormAnswersText;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Write `form_responses.answers_text`, the text the Form Responses search reads
 * (App\Support\FormAnswersText), for rows that are already stored.
 *
 * The model writes it on every save and the migration that added the column filled the
 * rows that existed, so nothing needs this day to day. It is for the two cases neither
 * covers:
 *
 *  - a form's questions changed. The text follows the schema as it was when each row was
 *    last saved, so answers to a question declared afterwards (an imported form whose old
 *    rows already carry the key), or to one since removed, are out of step until
 *    `--form=<id> --all` recomputes that form's rows;
 *  - rows whose text is NULL, which only a write that went round the model can leave (a
 *    restored dump, a hand-run UPDATE; `staging:scrub` nulls the text and fills it again
 *    itself). Without options the command fills exactly those.
 *
 * Safe to run at any time and to run again: it writes only rows whose text would change,
 * in chunks by primary key, without touching `updated_at`. Organisation and inclusive
 * ID bounds limit a repair; progress prints the next ID, and skips require a repeat.
 */
class RebuildFormAnswersText extends Command
{
    protected $signature = 'forms:rebuild-answers-text
        {--form= : Only the responses of this form id}
        {--masjid= : Only this organisation id}
        {--from-id= : Start at this response id, inclusive}
        {--to-id= : Stop at this response id, inclusive (defaults to the current maximum)}
        {--chunk=500 : Rows read per chunk, from 1 to 1000}
        {--all : Recompute every row from its form as it is now, not only the rows with no text yet}';

    protected $description = 'Fill (or with --all, recompute) the searchable answers text of stored form responses.';

    public function handle(): int
    {
        $bounds = [];
        foreach (['form', 'masjid', 'from-id', 'to-id', 'chunk'] as $option) {
            $value = $this->option($option);
            if ($value !== null && (! preg_match('/^[1-9][0-9]*$/D', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false)) {
                $this->error("--{$option} takes a positive integer.");
                return self::INVALID;
            }
            $bounds[$option] = $value === null ? null : (int) $value;
        }
        if ($bounds['chunk'] > 1000 || ($bounds['from-id'] !== null && $bounds['to-id'] !== null
            && $bounds['from-id'] > $bounds['to-id'])) {
            $this->error('Use --chunk=1..1000 and --from-id <= --to-id.');
            return self::INVALID;
        }

        if (! FormAnswersText::columnExists()) {
            $this->error('form_responses.answers_text does not exist yet: run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $next = $bounds['from-id'] ?? 1;
        $retry = null;
        $started = microtime(true);
        try {
            // Snapshot the ceiling so continuous submissions cannot prolong a repair.
            $bounds['to-id'] ??= DB::table(FormAnswersText::TABLE)
                ->when($bounds['masjid'] !== null, fn ($q) => $q->where('masjid_id', $bounds['masjid']))
                ->when($bounds['form'] !== null, fn ($q) => $q->where('form_id', $bounds['form']))
                ->max('id') ?? 0;
            $this->line("Range {$next}..{$bounds['to-id']}; chunk {$bounds['chunk']}.");
            $written = app(TenantContext::class)->runWithout(function () use ($bounds, &$next, &$retry): int {
                return FormAnswersText::fill(
                    $bounds['form'], (bool) $this->option('all'), $bounds['chunk'],
                    $bounds['masjid'], $next, $bounds['to-id'],
                    function (int $last, int $written, array $skipped) use (&$next, &$retry): void {
                        $next = $last + 1;
                        if ($skipped !== []) {
                            $retry = min($retry ?? PHP_INT_MAX, min($skipped));
                        }
                        $this->line("Through response {$last}; wrote {$written}; next ID {$next}; concurrent skips ".count($skipped).'.');
                    },
                );
            });
        } catch (Throwable $e) {
            $code = $e instanceof QueryException ? ' SQLSTATE '.($e->errorInfo[0] ?? 'unknown') : '';
            $this->error('Rebuild stopped: '.get_class($e).$code.'.');
            $this->line('Resume with the same filters and --from-id='.($retry ?? $next).($bounds['to-id'] !== null ? ' --to-id='.$bounds['to-id'] : '').'.');
            return self::FAILURE;
        }

        $this->info("Wrote the answers text of {$written} form response(s).");
        $this->line(sprintf('Elapsed %.3f seconds.', microtime(true) - $started));
        if ($retry !== null) {
            $this->warn("Concurrent changes skipped. Repeat the same filters with --from-id={$retry} --to-id={$bounds['to-id']}.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
