<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a GitHub `repository_dispatch` — the portal's half of the app-provisioning
 * contract. A dispatch triggers the matching workflow in a mobile repo (running
 * on a self-hosted runner) to scaffold + build + upload a masjid's app.
 *
 * Contract (the workflows consume exactly this):
 *   POST https://api.github.com/repos/{owner}/{repo}/dispatches
 *   Authorization: Bearer {token}
 *   Accept: application/vnd.github+json
 *   { "event_type": "scaffold-masjid", "client_payload": { ... } }
 *
 * GitHub returns 204 No Content on success. The token is a fine-grained/org PAT
 * with `contents: read` + `dispatch` (or classic `repo`) scope, stored in
 * config/env (services.github.dispatch_token), NEVER hardcoded or echoed.
 *
 * Fail-soft on config: when the token isn't wired, dispatch() returns a clear
 * error array rather than throwing, so the controller can mark the job `failed`
 * with a helpful detail instead of 500ing.
 */
class GithubDispatchService
{
    /** The repository_dispatch event type both mobile workflows listen for. */
    public const EVENT_TYPE = 'scaffold-masjid';

    /**
     * Dispatch $clientPayload to $repo (full "owner/repo").
     *
     * @param  string  $repo           e.g. "hope-tech-apps/burlington-masjid-iOS".
     * @param  array   $clientPayload  the contract client_payload for this job.
     * @return array{ok:bool, status?:int, error?:string}
     */
    public function dispatch(string $repo, array $clientPayload): array
    {
        $token = config('services.github.dispatch_token');

        // Guard gracefully — never crash (or leak) when the dispatch token is
        // not configured. The org secret is wired by the operator.
        if (empty($token)) {
            return [
                'ok' => false,
                'error' => 'GitHub dispatch token (GITHUB_DISPATCH_TOKEN) is not configured; cannot dispatch the provisioning workflow.',
            ];
        }

        if (trim($repo) === '') {
            return [
                'ok' => false,
                'error' => 'No GitHub repository is configured for this platform.',
            ];
        }

        $url = 'https://api.github.com/repos/' . ltrim($repo, '/') . '/dispatches';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                // GitHub requires a User-Agent; identify the control plane.
                'User-Agent' => 'MasjidWebMS-Provisioning',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->timeout(30)->post($url, [
                'event_type' => self::EVENT_TYPE,
                'client_payload' => $clientPayload,
            ]);
        } catch (\Throwable $e) {
            Log::error('GitHub repository_dispatch threw', [
                'repo' => $repo,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'GitHub dispatch request failed: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            Log::error('GitHub repository_dispatch failed', [
                'repo' => $repo,
                'status' => $response->status(),
                // GitHub's error envelope (message + docs url); never the token.
                'body' => $response->json() ?? $response->body(),
            ]);

            return [
                'ok' => false,
                'status' => $response->status(),
                'error' => 'GitHub rejected the dispatch (HTTP ' . $response->status() . ').',
            ];
        }

        return ['ok' => true, 'status' => $response->status()];
    }
}
