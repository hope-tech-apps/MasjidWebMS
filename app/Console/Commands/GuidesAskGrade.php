<?php

namespace App\Console\Commands;

use App\Support\Guides\GuideAskService;
use App\Support\Guides\GuideReleases;
use Illuminate\Console\Command;
use Throwable;
use Symfony\Component\Console\Output\OutputInterface;

class GuidesAskGrade extends Command
{
    protected $signature = 'guides:ask-test {test-set : External JSON grading set}
        {--model= : Override the configured guide model} {--limit= : Required maximum number of questions (1-100)} {--yes : Confirm calls to the paid API}
        {--input-price= : USD per million uncached input tokens} {--output-price= : USD per million output tokens}
        {--cache-read-price= : USD per million cached input tokens} {--cache-write-price= : USD per million cache creation tokens}';
    protected $description = 'Grade the installed guide through the real answer path, without limits or storage';

    public function handle(GuideReleases $releases, GuideAskService $ask): int
    {
        try {
            $bytes = @file_get_contents($this->argument('test-set'));
            $set = $bytes === false ? null : json_decode($bytes, true);
            $manifest = $releases->current();
            if (! $manifest || ! is_array($set['questions'] ?? null) || ! array_is_list($set['questions']) || ! $set['questions']) throw new \RuntimeException;
            if (isset($set['written_for_release']) && $set['written_for_release'] !== $manifest['version']) {
                $this->error('The test set names a different release. Select that installed release first.'); return self::FAILURE;
            }
            $limit = $this->option('limit');
            if ($limit === null || ! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100) throw new \RuntimeException;
            $questions = array_slice($set['questions'], 0, (int) $limit);
            $prices = [];
            foreach (['input', 'output', 'cache-read', 'cache-write'] as $key) {
                $value = $this->option($key.'-price');
                if ($value !== null && (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0)) throw new \RuntimeException;
                $prices[$key] = $value === null ? null : (float) $value;
            }
            foreach ($questions as $q) {
                $books = $q['guides'] ?? null; $reader = $q['who'] ?? null;
                $valid = match ($reader) { 'office' => in_array($books, [['admin'], ['admin', 'school']], true), 'teacher' => $books === ['teacher'], 'lunch' => $books === ['lunch'], default => false };
                if (! $valid || ! is_string($q['q'] ?? null) || trim($q['q']) === '' || ! in_array($q['expect'] ?? null, ['unknown', 'answer'], true) || ! $ask->hasText($manifest, $books)) throw new \RuntimeException;
                if ($q['expect'] === 'answer' && (! is_array($q['tasks'] ?? null) || ! $q['tasks'])) throw new \RuntimeException;
                foreach ([...($q['tasks'] ?? []), ...($q['must_say'] ?? []), ...($q['must_not_say'] ?? [])] as $phrase) if (! is_string($phrase)) throw new \RuntimeException;
            }
        } catch (Throwable) {
            $this->error('Invalid test set, options, or unavailable guide text.'); return self::FAILURE;
        }
        $model = $this->option('model') ?? (string) config('guide_ask.model');
        $this->output->writeln('Model: '.$model.'; '.count($questions).' question(s); this calls the paid API.', OutputInterface::OUTPUT_RAW);
        if (! $this->option('yes') && (! $this->input->isInteractive() || ! $this->confirm('Call the paid API for these questions?', false))) {
            $this->error('No calls made. Confirm interactively or pass --yes.'); return self::FAILURE;
        }
        $passed = 0; $failed = []; $totals = ['input' => 0, 'output' => 0, 'cache_creation' => 0, 'cache_read' => 0]; $hits = 0; $creations = 0;
        foreach ($questions as $index => $q) {
            // Questions and answers are printed here only; no logger or retention path.
            $this->output->writeln(($index + 1).'. '.$q['q'], OutputInterface::OUTPUT_RAW);
            try {
                $result = $ask->answer($manifest, $q['guides'], $q['who'], $q['q'], $model);
                $this->output->writeln($result['answer'], OutputInterface::OUTPUT_RAW);
                $reason = null;
                if ($q['expect'] === 'unknown' && ! $result['unknown']) $reason = 'expected unknown';
                elseif ($q['expect'] === 'answer' && $result['unknown']) $reason = 'unexpected unknown';
                elseif ($q['expect'] === 'answer' && ! array_intersect($q['tasks'], array_column([...$result['tasks'], ...$result['questions']], 'id'))) $reason = 'no source matched';
                foreach ($q['must_say'] ?? [] as $phrase) if ($reason === null && ! str_contains($result['answer'], $phrase)) $reason = 'missing phrase "'.str_replace(["\r", "\n"], ['\\r', '\\n'], $phrase).'"';
                // Words whose presence makes an otherwise sourced answer wrong (steps borrowed from another screen).
                foreach ($q['must_not_say'] ?? [] as $phrase) if ($reason === null && str_contains($result['answer'], $phrase)) $reason = 'forbidden phrase "'.str_replace(["\r", "\n"], ['\\r', '\\n'], $phrase).'"';
                $this->line('Usage: '.json_encode($result['usage']));
                foreach ($totals as $key => $value) $totals[$key] += $result['usage'][$key];
                if ($result['usage']['cache_read'] > 0) $hits++;
                if ($result['usage']['cache_creation'] > 0) $creations++;
            } catch (Throwable) { $reason = 'model request failed'; $this->line('Model request failed. Usage: unavailable.'); }
            $this->output->writeln($reason === null ? 'PASS' : 'FAIL: '.$reason, OutputInterface::OUTPUT_RAW);
            if ($reason === null) $passed++; else $failed[] = $index + 1;
        }
        $this->line($passed.'/'.count($questions).' passed');
        $this->line('Failed questions: '.($failed ? implode(', ', $failed) : 'none'));
        $this->line('Tokens: '.json_encode($totals).'; cache creations: '.$creations.'; cache hits: '.$hits);
        $this->line('cache_creation_input_tokens: '.$totals['cache_creation'].'; cache_read_input_tokens: '.$totals['cache_read']);
        $cost = 'not computed';
        if (! in_array(null, $prices, true)) {
            $amount = ($totals['input'] * $prices['input'] + $totals['output'] * $prices['output'] + $totals['cache_read'] * $prices['cache-read'] + $totals['cache_creation'] * $prices['cache-write']) / 1000000;
            $cost = sprintf('$%.6f (estimate using supplied prices)', $amount);
        }
        $this->line('Estimated cost: '.$cost);
        return $passed === count($questions) ? self::SUCCESS : self::FAILURE;
    }
}
