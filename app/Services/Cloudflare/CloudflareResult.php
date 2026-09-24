<?php

namespace App\Services\Cloudflare;

/**
 * What one CloudflareService call came to, as a value the caller branches on.
 *
 * Nothing Cloudflare-shaped escapes the service: no response object, no
 * exception. A missing token, a refused scope, a rate limit, an outage and a
 * record that belongs to somebody else are all ordinary answers here, because
 * the attacher runs from a five-minute schedule and must record what happened
 * and try again, never crash (.claude/rules/environments.md: an integration
 * with no credentials no-ops with a shaped result).
 *
 * `ok` is true for the outcomes that let the caller carry on: `ok`, `created`,
 * `adopted` and `absent` (a read that found nothing is a successful read).
 * `error` holds Cloudflare's own words, already stripped of the token.
 */
final class CloudflareResult
{
    public const OK = 'ok';
    public const CREATED = 'created';
    public const ADOPTED = 'adopted';
    public const ABSENT = 'absent';
    public const CONFLICT = 'conflict';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNAUTHORIZED = 'unauthorized';
    public const RATE_LIMITED = 'rate_limited';
    public const TRANSIENT = 'transient';
    public const REJECTED = 'rejected';

    public const OUTCOMES = [
        self::OK, self::CREATED, self::ADOPTED, self::ABSENT, self::CONFLICT,
        self::NOT_CONFIGURED, self::UNAUTHORIZED, self::RATE_LIMITED, self::TRANSIENT, self::REJECTED,
    ];

    private const SUCCESSFUL = [self::OK, self::CREATED, self::ADOPTED, self::ABSENT];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $outcome,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly ?int $http_status = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function of(string $outcome, array $data = [], ?string $error = null, ?int $httpStatus = null): self
    {
        return new self(in_array($outcome, self::SUCCESSFUL, true), $outcome, $data, $error, $httpStatus);
    }

    public static function notConfigured(): self
    {
        return self::of(self::NOT_CONFIGURED, [], 'CLOUDFLARE_STUDIO_TOKEN is not set, so nothing was sent to Cloudflare.');
    }

    public function is(string ...$outcomes): bool
    {
        return in_array($this->outcome, $outcomes, true);
    }
}
