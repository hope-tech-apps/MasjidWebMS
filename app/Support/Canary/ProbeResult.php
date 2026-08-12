<?php

namespace App\Support\Canary;

/**
 * What one probe got back, reduced to the facts the canary reasons about.
 *
 * The raw body is deliberately NOT retained past construction. This object ends
 * up in a log line and in `--json` output, and public API bodies carry real
 * organisations' content; the canary needs a row count and the set of tenant
 * ids, not the rows.
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

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    public function isThrottled(): bool
    {
        return $this->status === 429;
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
