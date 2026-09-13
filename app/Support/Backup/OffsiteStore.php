<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The three requests the off-site copy needs — PUT an object, HEAD an object,
 * DELETE an object — against an S3-compatible store, signed by S3Signer.
 *
 * NOTHING HERE THROWS. Every method returns a result array whose `ok` is the
 * only thing a caller reads. That is not defensive habit, it is the contract the
 * brief for this work states outright: the local set has already been written
 * and VERIFIED by the time anything in this file runs, and a network failure
 * must never turn a successful backup into a failed one. A shipper that can take
 * down `backup:run` is a shipper that will eventually be switched off, and then
 * the local backup goes with it.
 *
 * BUT A FAILURE IS NEVER SILENT EITHER. Every failure comes back with a sentence
 * naming the status code and the store's own error body, `backup:run` puts it in
 * its one log line, and `backup:check` grades it — because the failure mode this
 * whole task exists to close is not "the backup broke", it is "the backup broke
 * and the sentence saying so went to /dev/null".
 *
 * WHY THE BODY IS A STREAM. A set is ~12.5 MB today and the database half grows
 * with the platform. Reading a half into a PHP string to upload it would put the
 * whole backup through `memory_limit`, and the day it did not fit the failure
 * would be an OOM inside the nightly cron rather than a message. The handle is
 * opened read-only and handed to the HTTP client, which streams it.
 *
 * WHY THERE IS NO RETRY LOOP IN HERE. A PUT of a whole object is idempotent —
 * the same key, the same bytes, the same content hash — so retrying is safe, and
 * the retry belongs to the caller that knows whether it is mid-set. OffsiteShipper
 * does it, once, and gives up rather than hammering a store that is down.
 */
final class OffsiteStore
{
    public function __construct(private readonly OffsiteTarget $target) {}

    /**
     * Does this object exist, and how big is the store's copy?
     *
     * `found: false` and `ok: false` are DIFFERENT ANSWERS and callers must not
     * collapse them. "The store says this object is not there" is evidence.
     * "I could not ask the store" is the absence of evidence, and this codebase
     * has one rule about that, written in BackupRun's coverage check: a claim
     * nobody could check is not one to write down.
     *
     * @return array{ok: bool, found: bool, bytes: ?int, status: ?int, error: ?string}
     */
    public function head(string $key): array
    {
        $result = $this->send('HEAD', $key, null, S3Signer::EMPTY_PAYLOAD_SHA256, []);

        if ($result['error'] !== null && $result['status'] === null) {
            return ['ok' => false, 'found' => false, 'bytes' => null, 'status' => null, 'error' => $result['error']];
        }

        $status = (int) $result['status'];

        if ($status === 404 || $status === 403) {
            // 403 is included deliberately. A bucket configured to deny
            // ListBucket answers a HEAD for a missing key with 403 rather than
            // 404, so treating it as "unknown" would make a correctly-locked-down
            // bucket permanently unverifiable. A 403 on the PUT that follows is
            // still a hard failure, so a genuinely wrong credential cannot hide
            // behind this.
            return ['ok' => true, 'found' => false, 'bytes' => null, 'status' => $status, 'error' => null];
        }

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'found' => false, 'bytes' => null, 'status' => $status, 'error' => $result['error']];
        }

        $length = $result['length'];

        return [
            'ok' => true,
            'found' => true,
            'bytes' => $length === null ? null : (int) $length,
            'status' => $status,
            'error' => null,
        ];
    }

    /**
     * Upload one file under one key.
     *
     * `$sha256` is signed as `x-amz-content-sha256`, and the store recomputes it
     * over what it actually received: a body truncated in flight is REJECTED,
     * not stored short. The shipper passes the hash the manifest already
     * recorded for that half, so the number that says a local set is intact is
     * the same number that gates the upload. There is no second definition of
     * "the right bytes" anywhere in this slice.
     *
     * @return array{ok: bool, status: ?int, error: ?string}
     */
    public function put(string $key, string $file, string $sha256, string $contentType): array
    {
        if (! is_file($file)) {
            return ['ok' => false, 'status' => null, 'error' => sprintf('%s is not a file.', $file)];
        }

        $bytes = (int) filesize($file);

        if ($bytes > $this->target->maxObjectBytes) {
            return ['ok' => false, 'status' => null, 'error' => sprintf(
                '%s is %d bytes, over the %d-byte single-request ceiling. Multipart upload is deliberately not '
                .'implemented here: half a multipart that nobody completed is a bucket full of orphaned parts that '
                .'bill monthly and restore nothing. Raise BACKUP_OFFSITE_MAX_OBJECT_BYTES only up to 5 GiB; past '
                .'that this needs multipart written properly.',
                basename($file),
                $bytes,
                $this->target->maxObjectBytes,
            )];
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return ['ok' => false, 'status' => null, 'error' => sprintf('%s could not be opened for reading.', $file)];
        }

        try {
            $headers = ['content-length' => (string) $bytes, 'content-type' => $contentType];

            if ($this->target->encryption !== null) {
                $headers['x-amz-server-side-encryption'] = $this->target->encryption;
            }

            $result = $this->send('PUT', $key, $handle, $sha256, $headers);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($result['error'] !== null && $result['status'] === null) {
            return ['ok' => false, 'status' => null, 'error' => $result['error']];
        }

        $status = (int) $result['status'];

        return $status >= 200 && $status < 300
            ? ['ok' => true, 'status' => $status, 'error' => null]
            : ['ok' => false, 'status' => $status, 'error' => $result['error']];
    }

    /**
     * Remove one object. Used only to take back the halves of a set whose
     * upload did not finish — see OffsiteShipper, where the reason it matters is
     * written down.
     *
     * @return array{ok: bool, status: ?int, error: ?string}
     */
    public function delete(string $key): array
    {
        $result = $this->send('DELETE', $key, null, S3Signer::EMPTY_PAYLOAD_SHA256, []);

        if ($result['error'] !== null && $result['status'] === null) {
            return ['ok' => false, 'status' => null, 'error' => $result['error']];
        }

        $status = (int) $result['status'];

        // S3 answers a DELETE of a key that was never there with 204.
        return ($status >= 200 && $status < 300) || $status === 404
            ? ['ok' => true, 'status' => $status, 'error' => null]
            : ['ok' => false, 'status' => $status, 'error' => $result['error']];
    }

    /**
     * @param  resource|null  $body
     * @param  array<string, string>  $headers
     * @return array{status: ?int, length: ?string, error: ?string}
     */
    private function send(string $method, string $key, $body, string $payloadSha256, array $headers): array
    {
        $signed = S3Signer::sign(
            method: $method,
            canonicalPath: $this->target->canonicalPath($key),
            headers: ['host' => $this->target->host()] + $headers,
            payloadSha256: $payloadSha256,
            accessKey: $this->target->accessKey(),
            secretKey: $this->target->secretKey(),
            region: $this->target->region,
        );

        // WHY content-type IS SIGNED HERE AND SENT BY withBody() RATHER THAN BY US.
        //
        // `Http::withBody($body, $type)` sets a `Content-Type` header of its own,
        // and Laravel MERGES header arrays rather than replacing entries
        // (array_merge_recursive). Passing our lowercase `content-type` as well
        // would leave two case-variant spellings of one header in the same
        // array, and PSR-7 combines those into `application/gzip, application/gzip`.
        // The signature covers the single value, so the store would answer
        // SignatureDoesNotMatch — a message that reads like a bad secret — on
        // every upload, forever, at 02:41, into a log nobody reads. SigV4
        // lower-cases header names in the canonical request and the store does
        // the same when it verifies, so signing `content-type` while `withBody()`
        // sends `Content-Type` is correct.
        $outgoing = $signed;
        $contentType = (string) ($headers['content-type'] ?? 'application/octet-stream');

        if ($body !== null) {
            unset($outgoing['content-type']);
        }

        try {
            $request = Http::withHeaders($outgoing)
                ->timeout($this->target->timeout)
                // Guzzle adds `Expect: 100-continue` to large bodies of its own
                // accord. It is not a signed header so it does not break the
                // signature, but a store that does not answer the continue
                // leaves the upload stalled until the timeout — which reads as
                // "the network is slow tonight" for as long as nobody looks.
                ->withOptions(['expect' => false]);

            // `put()` rather than `send('PUT', ...)`: the verb helpers are what
            // pass the pending body through to Guzzle, and a bare send() with no
            // options would upload a set half of ZERO BYTES with a signature
            // computed over the real hash — rejected by the store, which is the
            // lucky version of that mistake.
            $response = $body === null
                ? $request->send($method, $this->target->urlFor($key))
                : $request->withBody($body, $contentType)->put($this->target->urlFor($key));

            return [
                'status' => $response->status(),
                'length' => $response->header('Content-Length') === '' ? null : $response->header('Content-Length'),
                'error' => $response->successful() ? null : sprintf(
                    'the store answered %d: %s',
                    $response->status(),
                    // S3 errors are XML naming the actual fault (SignatureDoesNotMatch,
                    // NoSuchBucket, AccessDenied). Truncated, because it goes in
                    // a log line that has to stay one line.
                    Str::limit(trim(preg_replace('/\s+/', ' ', (string) $response->body()) ?? ''), 300) ?: '(no body)',
                ),
            ];
        } catch (Throwable $e) {
            // A DNS failure, a refused connection, an expired TLS certificate,
            // a timeout. No status, because no answer.
            return ['status' => null, 'length' => null, 'error' => sprintf(
                '%s %s could not be sent: %s',
                $method,
                $this->target->urlFor($key),
                $e->getMessage(),
            )];
        }
    }
}
