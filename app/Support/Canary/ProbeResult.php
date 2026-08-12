<?php

namespace App\Support\Canary;

/**
 * What one probe got back, reduced to the facts the canary reasons about.
 *
 * The raw body is deliberately NOT retained past construction. This object ends
 * up in a log line and in `--json` output, and public API bodies carry real
 * organisations' content; the canary needs a row count, the set of tenant ids,
 * the set of record ids and a hash — never the rows.
 *
 * ==========================================================================
 * EVERY STATUS CLASS HAS A PREDICATE, BECAUSE THE GAP WAS THE BUG
 * ==========================================================================
 *
 * This class used to expose exactly two: `isSuccessful()` (2xx) and
 * `isServerError()` (5xx). 3xx and 4xx fell into a gap that NO caller read, and
 * the run-level verdict was built out of those two predicates — so an origin
 * that answered 301 to every probe, or 404 to every probe, produced a run with
 * 36/36 probes, 23 passing checks, zero findings, and exit 0. Measured.
 *
 * That is one config line away in production: the site's nginx server block
 * answers plain HTTP with `return 301 https://$host$request_uri`, so a
 * `CANARY_BASE_URL` written `http://` makes every probe a redirect and every run
 * green, permanently and silently.
 *
 * So: a predicate per class, plus `isAnswered()` for "an HTTP response came back
 * at all", and every caller must decide what a status OUTSIDE its interest means
 * rather than inheriting "not a failure, therefore a pass".
 */
final class ProbeResult
{
    /**
     * @param  int  $status  HTTP status, or 0 when the request never completed
     * @param  int|null  $recordCount  rows the answer exposed, null when not countable
     * @param  string  $countBasis  how recordCount was derived, for the report
     * @param  array<int,int>  $masjidIds  distinct `masjid_id` values found in the body
     * @param  int  $bytes  response body size
     * @param  int  $durationMs
     * @param  string|null  $transportError  set when the request could not be made at all
     * @param  int|null  $rateLimitRemaining  from X-RateLimit-Remaining, when the app sent it
     * @param  string|null  $fingerprint  hash of the canonicalised payload, null when there was none
     * @param  array<string,array<int,int>>  $recordIds  primary keys of the rows the answer
     *                                                   returned, bucketed by the key path they
     *                                                   sat under. See ResponseFacts::recordIdentity().
     */
    public function __construct(
        public readonly Probe $probe,
        public readonly int $status,
        public readonly ?int $recordCount,
        public readonly string $countBasis,
        public readonly array $masjidIds,
        public readonly int $bytes,
        public readonly int $durationMs,
        public readonly ?string $transportError = null,
        public readonly ?int $rateLimitRemaining = null,
        public readonly ?string $fingerprint = null,
        public readonly array $recordIds = [],
    ) {
    }

    public static function failed(Probe $probe, string $error, int $durationMs): self
    {
        return new self($probe, 0, null, 'unavailable', [], 0, $durationMs, $error);
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 3xx. NOT a refusal and NOT an answer: the edge redirected and the
     * application was never asked. A canary that counts this as "correctly
     * refused" certifies an origin it never reached.
     */
    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /**
     * 4xx. The contract for a request that names no organisation, and the only
     * status the tenant-less check may read as a genuine refusal.
     */
    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    public function isThrottled(): bool
    {
        return $this->status === 429;
    }

    /** An HTTP response came back at all — as opposed to a dead socket. */
    public function isAnswered(): bool
    {
        return $this->transportError === null && $this->status > 0;
    }

    /** What to print in a check's detail: the status, or that there wasn't one. */
    public function statusLabel(): string
    {
        return $this->isAnswered() ? (string) $this->status : 'no response';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'records' => $this->recordCount,
            'count_basis' => $this->countBasis,
            'masjid_ids' => $this->masjidIds,
            'bytes' => $this->bytes,
            'duration_ms' => $this->durationMs,
            'transport_error' => $this->transportError,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
