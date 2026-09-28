<?php

namespace App\Services;

/**
 * What OneSignalProvisioningService::ensureApp() did, or would do (pretend).
 *
 * `outcome` is one of the constants below. `hasKey` says whether the
 * organisation's app has a REST key on file after the call: an app created
 * whose key could not be minted keeps sends where they were
 * (MasjidAppPublishing::hasOwnOnesignalApp() needs both), and a retry mints it.
 * The key itself is never part of a result.
 */
final class OneSignalResult
{
    public const CREATED = 'created';
    public const EXISTS = 'exists';
    public const PLATFORM_ADDED = 'platform_added';
    public const KEY_MINTED = 'key_minted';
    public const NOT_CONFIGURED = 'not_configured';
    public const REFUSED_LIVE_ORG = 'refused_live_org';
    public const HAS_AUDIENCE = 'has_audience';
    public const MISSING_APNS = 'missing_apns';
    public const MISSING_FCM = 'missing_fcm';
    public const REJECTED = 'rejected';
    public const TRANSIENT = 'transient';

    /** Outcomes after which the organisation has (or, pretending, would have) its app. */
    public const SUCCESSES = [self::CREATED, self::EXISTS, self::PLATFORM_ADDED, self::KEY_MINTED];

    /**
     * @param  list<string>  $platforms  what the app is configured for after the call
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $appId = null,
        public readonly bool $hasKey = false,
        public readonly array $platforms = [],
        public readonly ?int $httpStatus = null,
        public readonly string $message = '',
        public readonly bool $pretended = false,
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->outcome, self::SUCCESSES, true);
    }
}
