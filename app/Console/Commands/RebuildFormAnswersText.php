<?php

namespace App\Console\Commands;

use App\Support\FormAnswersText;
use Illuminate\Console\Command;

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
 * in chunks by primary key, without touching `updated_at`.
 */
class RebuildFormAnswersText extends Command
{
    protected $signature = 'forms:rebuild-answers-text
        {--form= : Only the responses of this form id}
        {--all : Recompute every row from its form as it is now, not only the rows with no text yet}';

    protected $description = 'Fill (or with --all, recompute) the searchable answers text of stored form responses.';

    public function handle(): int
    {
        $form = $this->option('form');

        if ($form !== null && (! is_numeric($form) || (int) $form < 1)) {
            $this->error('--form takes a form id.');

            return self::INVALID;
        }

        if (! FormAnswersText::columnExists()) {
            $this->error('form_responses.answers_text does not exist yet: run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $written = FormAnswersText::fill(
            $form !== null ? (int) $form : null,
            (bool) $this->option('all'),
        );

        $this->info("Wrote the answers text of {$written} form response(s).");

        return self::SUCCESS;
    }
}
