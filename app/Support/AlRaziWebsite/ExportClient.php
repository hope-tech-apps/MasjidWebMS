<?php

namespace App\Support\AlRaziWebsite;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reads the Al-Razi school website's submissions through its read-only export,
 * a Supabase Edge Function (`export-submissions`).
 *
 *   GET  <url>?table=registrations|careers&after=<cursor>&limit=200&insurance=0|1
 *        -> {"rows": [...], "next": "<cursor>" | null}
 *   POST <url>/sign  {"paths": [{"bucket", "path"}, …]}   (at most 50)
 *        -> {"urls": {"<bucket>/<path>": "<signed url, 600 s>"}}
 *
 * Both carry `Authorization: Bearer <ALRAZI_EXPORT_TOKEN>`. The function holds
 * the database key; this side holds only a token that can read what the export
 * chooses to return, which never includes an SSN or the SSN card.
 *
 * Built on ANY configuration and never throws for a blank one
 * (.claude/rules/environments.md): `isConfigured()` is the question a caller
 * asks, and it is false on every box but production. A URL that is not https is
 * NOT configured: the bearer token and children's records never cross the wire in
 * the clear. `hasInsecureUrl()` lets the caller say why.
 *
 * Failures throw ExportFailed, whose message is written here and carries an HTTP
 * status at most — never a response body, which could echo a child's record into
 * the log.
 */
class ExportClient
{
    /** The export's own page ceiling. */
    public const PAGE_LIMIT = 200;

    /** The export signs at most this many paths per request. */
    public const SIGN_BATCH = 50;

    /** download(): the callback ran on the file; its result is the second element. */
    public const DOWNLOAD_OK = 'ok';

    /** download(): the file is larger than the ceiling. It was never read. */
    public const DOWNLOAD_TOO_LARGE = 'too_large';

    /** download(): refused (another origin), not 200, or empty. Worth retrying. */
    public const DOWNLOAD_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly int $timeout = 30,
    ) {
    }

    public static function fromConfig(): self
    {
        $config = (array) config('services.alrazi_export', []);

        return new self(
            is_string($config['url'] ?? null) ? trim($config['url']) : null,
            is_string($config['token'] ?? null) ? trim($config['token']) : null,
            max(1, (int) ($config['timeout'] ?? 30)),
        );
    }

    public function isConfigured(): bool
    {
        return $this->hasUrlAndToken() && preg_match('#^https://#i', (string) $this->url) === 1;
    }

    /**
     * Both are set, but the URL is not https — so isConfigured() is false and
     * nothing is requested. The caller warns: this is a mistake, not a staging box.
     */
    public function hasInsecureUrl(): bool
    {
        return $this->hasUrlAndToken() && ! $this->isConfigured();
    }

    /**
     * One page of one table, strictly after $after.
     *
     * @return array{rows: list<array<string,mixed>>, next: ?string}
     */
    public function page(string $table, ?string $after, bool $includeInsurance = false, int $limit = self::PAGE_LIMIT): array
    {
        $this->assertConfigured();

        $query = [
            'table' => $table,
            'limit' => max(1, min(self::PAGE_LIMIT, $limit)),
            'insurance' => $includeInsurance ? 1 : 0,
        ];

        if ($after !== null && $after !== '') {
            $query['after'] = $after;
        }

        $body = $this->json(fn () => $this->request()->get($this->base(), $query), "read {$table}");

        $rows = $body['rows'] ?? null;
        $next = $body['next'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || ! ($next === null || is_string($next))) {
            throw new ExportFailed("The export returned a malformed page of {$table}.");
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new ExportFailed("The export returned a malformed row in {$table}.");
            }
        }

        return ['rows' => $rows, 'next' => $next === '' ? null : $next];
    }

    /**
     * Signed download URLs for the given objects, keyed "<bucket>/<path>". An object
     * the export declines to sign is simply absent.
     *
     * @param  list<array{bucket: string, path: string}>  $objects
     * @return array<string,string>
     */
    public function sign(array $objects): array
    {
        $this->assertConfigured();

        $urls = [];

        foreach (array_chunk($objects, self::SIGN_BATCH) as $batch) {
            $paths = array_map(fn (array $o) => ['bucket' => $o['bucket'], 'path' => $o['path']], $batch);

            $body = $this->json(
                fn () => $this->request()->post($this->base() . '/sign', ['paths' => $paths]),
                'sign documents'
            );

            $signed = $body['urls'] ?? null;

            if (! is_array($signed)) {
                throw new ExportFailed('The export returned a malformed signing response.');
            }

            foreach ($signed as $key => $url) {
                if (is_string($key) && is_string($url) && $url !== '') {
                    $urls[$key] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Download one signed URL to a private temporary file and hand that file to
     * $use, refusing anything larger than $maxBytes.
     *
     * Only a URL on the export's own origin is fetched (a signed Storage URL lives
     * on the same Supabase host as the function), so a compromised or confused
     * export cannot point this server at an arbitrary address. The bearer token is
     * NOT sent: the signature is the credential.
     *
     * The body streams to disk (Http::sink), never into memory, and its size is
     * checked BEFORE anything reads or sniffs it; $use never sees an oversized
     * file. The temporary file — a child's document — is deleted in `finally`
     * whatever happens, including when $use throws, so the caller never owns it.
     *
     * @template T
     * @param  callable(string $path, int $bytes): T  $use
     * @return array{0: string, 1: T|null}  [DOWNLOAD_OK, $use's result] | [DOWNLOAD_TOO_LARGE|DOWNLOAD_UNAVAILABLE, null]
     */
    public function download(string $signedUrl, int $maxBytes, callable $use): array
    {
        $this->assertConfigured();

        // Supabase can answer with a path ("/storage/v1/object/sign/…"); it is
        // relative to the project, which is the export's own origin.
        if (str_starts_with($signedUrl, '/') && ! str_starts_with($signedUrl, '//')) {
            $signedUrl = $this->origin() . $signedUrl;
        }

        if (! $this->sameOrigin($signedUrl)) {
            return [self::DOWNLOAD_UNAVAILABLE, null];
        }

        // tempnam() creates the file 0600, so no other local user can read it.
        $tmp = tempnam(sys_get_temp_dir(), 'alrazi-');

        if ($tmp === false) {
            throw new ExportFailed('No temporary file could be created for a document download.');
        }

        try {
            try {
                $response = Http::timeout($this->timeout)
                    ->connectTimeout(min(10, $this->timeout))
                    ->withOptions(['allow_redirects' => false])
                    ->sink($tmp)
                    ->get($signedUrl);
            } catch (ConnectionException) {
                throw new ExportFailed('A document download could not connect.');
            }

            if ($response->status() !== 200) {
                return [self::DOWNLOAD_UNAVAILABLE, null];
            }

            clearstatcache(true, $tmp);
            $bytes = @filesize($tmp);

            if ($bytes === false || $bytes === 0) {
                return [self::DOWNLOAD_UNAVAILABLE, null];
            }

            if ($bytes > $maxBytes) {
                return [self::DOWNLOAD_TOO_LARGE, null];
            }

            return [self::DOWNLOAD_OK, $use($tmp, $bytes)];
        } finally {
            @unlink($tmp);
        }
    }

    /** The key the export uses for one object in its "urls" map. */
    public static function objectKey(string $bucket, string $path): string
    {
        return $bucket . '/' . $path;
    }

    // ------------------------------------------------------------------ internals

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout))
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @param  callable(): \Illuminate\Http\Client\Response  $send
     * @return array<string,mixed>
     */
    private function json(callable $send, string $what): array
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            throw new ExportFailed("Could not connect to the export to {$what}.");
        }

        if (! $response->successful()) {
            throw new ExportFailed("The export refused to {$what} (HTTP {$response->status()}).");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ExportFailed("The export's answer to {$what} was not JSON.");
        }

        return $body;
    }

    private function base(): string
    {
        return rtrim((string) $this->url, '/');
    }

    private function origin(): string
    {
        $parts = parse_url($this->base());

        return strtolower($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function sameOrigin(string $url): bool
    {
        $a = parse_url($this->base());
        $b = parse_url($url);

        if (! is_array($a) || ! is_array($b)) {
            return false;
        }

        return strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
            && ($a['port'] ?? null) === ($b['port'] ?? null)
            && ! isset($b['user'])
            && ! isset($b['pass']);
    }

    private function hasUrlAndToken(): bool
    {
        return $this->url !== null && $this->url !== ''
            && $this->token !== null && $this->token !== '';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ExportFailed('The school website export is not configured.');
        }
    }
}
