<?php

namespace App\Support\Guides;

use Anthropic\Client;
use GuzzleHttp\Client as HttpClient;
use RuntimeException;

/** One stateless, tool-free turn. No tenant or principal can enter this service. */
class GuideAskService
{
    public function __construct(private GuideReleases $releases) {}

    public function available(?array $manifest, array $books): bool
    {
        return config('guide_ask.enabled') && trim((string) config('services.anthropic.key')) !== ''
            && $this->hasText($manifest, $books);
    }

    public function hasText(?array $manifest, array $books): bool
    {
        if (! $manifest || ! $books) return false;
        foreach ($books as $book) {
            $meta = $manifest['books'][$book] ?? [];
            if (($meta['ask'] ?? null) !== $book.'/ask.txt' || ! $this->releases->file($manifest['version'], $meta['ask'])) return false;
        }
        return true;
    }

    public function contact(string $reader): string { return (string) config('guide_ask.contacts.'.$reader); }
    public function fallback(string $reader): string { return "I don't know that one. Please reach out to ".$this->contact($reader).' and ask.'; }
    public function failure(string $reader): string { return 'That did not work. Try again, or reach out to '.$this->contact($reader).'.'; }
    public function resting(string $reader, string $cap = 'organisation'): string { if ($cap === 'platform') return "The guide's question box is resting for now. Please reach out to ".$this->contact($reader).'.'; return "The guide's question box is resting for today. Try again tomorrow, or reach out to ".$this->contact($reader).'.'; }

    /** Limits and retention belong to the HTTP boundary, never to a grading run. */
    public function answer(array $manifest, array $books, string $reader, string $question, ?string $model = null): array
    {
        if (trim((string) config('services.anthropic.key')) === '' || ! $this->hasText($manifest, $books) || ! isset(config('guide_ask.readers')[$reader])) throw new RuntimeException('Guide ask unavailable');
        $instruction = file_get_contents(resource_path('guides/ask-instructions.txt'));
        if ($instruction === false) throw new RuntimeException('Guide ask unavailable');
        $texts = []; $sources = [];
        foreach ($books as $book) {
            $meta = $manifest['books'][$book];
            $path = $this->releases->file($manifest['version'], $meta['ask']);
            $text = $path ? @file_get_contents($path) : false;
            if ($text === false || strlen($text) !== ($meta['ask_bytes'] ?? null) || ! hash_equals($meta['ask_sha256'] ?? '', hash('sha256', $text))) throw new RuntimeException('Guide ask unavailable');
            $texts[] = $text;
            foreach ($meta['tasks'] as $task) $sources[] = ['kind' => 'tasks', 'book' => $book, 'id' => $task['id'], 'title' => $task['title']];
            foreach (GuideAskText::parse($text, $meta['ask'])['blocks'] as $block) {
                if ($block['kind'] === 'Common question') $sources[] = ['kind' => 'questions', 'book' => $book, 'id' => $block['id'], 'title' => $block['title']];
            }
        }
        $stable = strtr($instruction, ['{reader}' => (string) config('guide_ask.readers.'.$reader), '{contact}' => $this->contact($reader)])."\n\n".implode("\n\n", $texts);
        $response = $this->client()->messages->create(
            model: $model ?? (string) config('guide_ask.model'),
            maxTokens: (int) config('guide_ask.max_tokens'),
            system: [['type' => 'text', 'text' => $stable, 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $question]],
            // Raw service normalizes options with defaults, so set retries on EACH call.
            requestOptions: ['maxRetries' => 0],
        );
        if ($response->stopReason !== 'end_turn') throw new RuntimeException('Guide answer incomplete');
        $parts = [];
        foreach ($response->content as $block) if ($block->type === 'text') $parts[] = $block->text;
        $answer = trim(implode("\n", $parts));
        if ($answer === '') throw new RuntimeException('Guide answer empty');
        $fallback = $this->fallback($reader);
        $normalized = preg_replace('/^[\s"\'“”‘’«»]+|[\s"\'“”‘’«»]+$/u', '', $answer);
        $unknown = $normalized === rtrim($fallback, '.') || str_starts_with($normalized, $fallback);
        if ($unknown) $answer = $fallback;
        $links = $unknown ? ['tasks' => [], 'questions' => []] : $this->links($answer, $sources);
        $usage = $response->usage;
        return ['answer' => $answer, 'unknown' => $unknown, 'tasks' => $links['tasks'], 'questions' => $links['questions'], 'usage' => [
            'input' => $usage->inputTokens, 'output' => $usage->outputTokens,
            'cache_creation' => $usage->cacheCreationInputTokens ?? 0, 'cache_read' => $usage->cacheReadInputTokens ?? 0,
        ]];
    }

    /** Resolve longest overlapping title spans, then preserve manifest/book order. */
    private function links(string $answer, array $sources): array
    {
        $occurrences = [];
        foreach ($sources as $index => $source) {
            $pattern = '/(?<![\\p{L}\\p{N}_])'.preg_quote($source['title'], '/').'(?![\\p{L}\\p{N}_])/iu';
            preg_match_all($pattern, $answer, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$text, $start]) $occurrences[] = ['index' => $index, 'start' => $start, 'end' => $start + strlen($text)];
        }
        usort($occurrences, fn ($a, $b) => ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']) ?: $a['start'] <=> $b['start']);
        $spans = []; $matched = [];
        foreach ($occurrences as $hit) {
            foreach ($spans as $span) {
                // Identical titles in multiple permitted books may share the same span.
                if ($span['start'] === $hit['start'] && $span['end'] === $hit['end']) continue;
                if ($hit['start'] < $span['end'] && $hit['end'] > $span['start']) continue 2;
            }
            $spans[] = $hit; $matched[$hit['index']] = true;
        }
        $links = ['tasks' => [], 'questions' => []];
        foreach ($sources as $index => $source) if (isset($matched[$index])) {
            $kind = $source['kind']; unset($source['kind']); $links[$kind][] = $source;
        }
        return $links;
    }

    /** Same SDK/key as the Assistant. Its PSR-18 transport needs its OWN timeout. */
    protected function client(): Client
    {
        $timeout = (float) config('guide_ask.timeout');
        if ($timeout <= 0) throw new RuntimeException('Guide timeout must be positive');
        return new Client(apiKey: (string) config('services.anthropic.key'), requestOptions: [
            'timeout' => $timeout, 'maxRetries' => 0,
            'transporter' => new HttpClient(['timeout' => $timeout, 'connect_timeout' => min(5, $timeout), 'allow_redirects' => false]),
        ]);
    }
}
