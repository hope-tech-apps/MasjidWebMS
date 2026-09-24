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
 * The renderer answers {ok, scanned, deleted, remaining, cursor}; while `remaining` is
 * true it is called again with `after: cursor` (at most MAX_CALLS).
 *
 * NEVER FAILS A SAVE. It runs on the queue (PurgeRendererCache, PurgeRendererCacheAgain,
 * scheduled by RendererPurgeScheduler), and every failure is caught and logged at
 * `warning` — production runs LOG_LEVEL=warning, so anything quieter is discarded
 * (.claude/rules/shipping.md). With no configuration it does nothing at all.
 */
final class RendererCachePurge
{
    /**
     * Calls per pass. The renderer deletes at most 800 keys a call and pages by cursor, so
     * one pass reaches 1,600 keys of one organisation; production held 45 page keys in its
     * live build on 2026-09-24. A pass cannot spend more than this however many keys exist.
     */
    public const MAX_CALLS = 2;

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
        $deleted = 0;
        $after = null;

        for ($call = 1; $call <= self::MAX_CALLS; $call++) {
            // The cursor from the previous call rides in the SIGNED body, so the renderer
            // resumes after the last key it deleted instead of re-listing from the start.
            $body = json_encode(
                $after === null ? ['v' => 1, 'org' => $organisationId] : ['v' => 1, 'org' => $organisationId, 'after' => $after],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
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
            $after = is_string($answer['cursor'] ?? null) ? $answer['cursor'] : null;
            if (($answer['remaining'] ?? false) !== true || $after === null) {
                return ['ok' => true, 'deleted' => $deleted, 'calls' => $call];
            }
        }

        Log::warning('Renderer cache purge stopped with entries remaining', [
            'masjid_id' => $organisationId,
            'origin' => $origin,
            'deleted' => $deleted,
        ]);

        return ['ok' => false, 'deleted' => $deleted, 'calls' => $call - 1];
    }
}
