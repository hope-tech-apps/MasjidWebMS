<?php

namespace App\Support\Renderer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the renderer to drop one organisation's cached pages, so a saved change is
 * served on the next request instead of up to five minutes later.
 *
 * The API holds no Cloudflare credential. It signs a request to the renderer's own
 * POST /__manara/purge, which deletes the keys through its KV binding:
 *
 *     body       {"v":1,"org":<id>}
 *     timestamp  X-Manara-Timestamp: <unix seconds>   (refused beyond ±300 s)
 *     signature  X-Manara-Signature: v1=<hex HMAC-SHA256(secret, "manara-purge|v1|<ts>|<body>")>
 *
 * The renderer answers {ok, scanned, deleted, remaining}; while `remaining` is true it
 * is called again (at most MAX_CALLS), which is safe because a purge is idempotent.
 *
 * NEVER FAILS A SAVE. It runs after the response has been sent (the
 * `renderer.purge` middleware's terminate()), and every failure is caught and logged at
 * `warning` — production runs LOG_LEVEL=warning, so anything quieter is discarded
 * (.claude/rules/shipping.md). With no configuration it does nothing at all.
 */
final class RendererCachePurge
{
    public const MAX_CALLS = 5;

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', "manara-purge|v1|{$timestamp}|{$body}", $secret);
    }

    /**
     * Purge one organisation on every configured renderer. Returns what each origin
     * answered, for tests and logs; callers need not read it.
     *
     * @return array<string, array{ok: bool, deleted: int, calls: int}>
     */
    public function purge(int $organisationId): array
    {
        $secret = RendererConfig::secret();
        if ($secret === null || $organisationId < 1) {
            return [];
        }

        $results = [];
        foreach (RendererConfig::purgeOrigins() as $origin) {
            $results[$origin] = $this->purgeOrigin($origin, $secret, $organisationId);
        }

        return $results;
    }

    /** @return array{ok: bool, deleted: int, calls: int} */
    private function purgeOrigin(string $origin, string $secret, int $organisationId): array
    {
        $body = json_encode(['v' => 1, 'org' => $organisationId], JSON_THROW_ON_ERROR);
        $deleted = 0;

        for ($call = 1; $call <= self::MAX_CALLS; $call++) {
            $timestamp = (string) now()->getTimestamp();

            try {
                $response = Http::timeout(RendererConfig::timeout())
                    ->connectTimeout(min(3, RendererConfig::timeout()))
                    ->withHeaders([
                        'X-Manara-Timestamp' => $timestamp,
                        'X-Manara-Signature' => self::signature($secret, $timestamp, $body),
                    ])
                    ->withBody($body, 'application/json')
                    ->post($origin.'/__manara/purge');
            } catch (Throwable $e) {
                Log::warning('Renderer cache purge failed', [
                    'masjid_id' => $organisationId,
                    'origin' => $origin,
                    'exception' => $e::class,
                ]);

                return ['ok' => false, 'deleted' => $deleted, 'calls' => $call];
            }

            $answer = $response->json();
            if (! $response->successful() || ! is_array($answer) || ($answer['ok'] ?? false) !== true) {
                Log::warning('Renderer cache purge refused', [
                    'masjid_id' => $organisationId,
                    'origin' => $origin,
                    'status' => $response->status(),
                ]);

                return ['ok' => false, 'deleted' => $deleted, 'calls' => $call];
            }

            $deleted += (int) ($answer['deleted'] ?? 0);
            if (($answer['remaining'] ?? false) !== true) {
                return ['ok' => true, 'deleted' => $deleted, 'calls' => $call];
            }
        }

        Log::warning('Renderer cache purge stopped with entries remaining', [
            'masjid_id' => $organisationId,
            'origin' => $origin,
            'deleted' => $deleted,
        ]);

        return ['ok' => false, 'deleted' => $deleted, 'calls' => self::MAX_CALLS];
    }
}
