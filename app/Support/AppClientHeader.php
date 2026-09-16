<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What build is calling — read off one header, or not at all.
 *
 * The R1 apps send
 *
 *     X-Manara-App: ios/1.0/47
 *     X-Manara-App: android/1.0.0/14
 *
 * platform, marketing version, build number. Nothing before R1 sends it, so
 * ABSENT is the common case and is not an error: it is the pre-R1 reading that
 * `app-telemetry:builds` prints as `pre-R1` and that
 * App\Http\Middleware\CountLegacyFeaturesHit counts as `untagged`.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PATTERN IS STRICT, AND WHY A BAD HEADER IS SILENTLY IGNORED
 * ---------------------------------------------------------------------------
 * This value is CLIENT-SUPPLIED and reaches a database column, so it is treated
 * as hostile input the whole way: an anchored pattern, a fixed alphabet, and a
 * length that cannot exceed the column
 * (`mobile_app_users.app_version`/`app_build` are varchar(20),
 * `app_platform` varchar(10)). Anything that does not match — a header with
 * four segments, a version with a space, a 200-character build string, a
 * platform nobody ships — yields null and the request proceeds untouched.
 *
 * Refusing the REQUEST over a malformed telemetry header would be the wrong
 * trade by a wide margin: this rides on device registration and the heartbeat,
 * and the heartbeat is what keeps the server-side prayer backstop from
 * double-notifying a phone whose local schedule is fine. A header nobody reads
 * must never be able to stop a prayer notification from being correct.
 *
 * Nothing in this application AUTHORISES on this value. It answers "what is out
 * there", which is a question you are allowed to get a lie to.
 */
final class AppClientHeader
{
    /** The header the R1 clients send. Named for the product, not the vendor. */
    public const HEADER = 'X-Manara-App';

    /**
     * `ios` or `android` — the two platforms that have an app. tvOS has its own
     * client and no device row, so it is deliberately absent rather than
     * accepted-and-unused.
     */
    public const PLATFORMS = ['ios', 'android'];

    /**
     * Anchored, and capped at the column widths. `A-Za-z0-9.-` covers every
     * shape either store accepts for a version or a build (`1.0`, `1.0.0`,
     * `47`, `2026.09.1-rc1`) and nothing that needs escaping on the way to SQL.
     */
    private const PATTERN = '/^(ios|android)\/([0-9A-Za-z.\-]{1,20})\/([0-9A-Za-z.\-]{1,20})$/';

    /**
     * The column widths, restated where the write happens: `app_platform`
     * varchar(10), `app_version` and `app_build` varchar(20). Also the field
     * order everything here iterates in.
     */
    private const LIMITS = [
        'app_platform' => 10,
        'app_version' => 20,
        'app_build' => 20,
    ];

    /**
     * @return array{app_platform: string, app_version: string, app_build: string}|null
     */
    public static function parse(Request $request): ?array
    {
        return self::parseValue($request->header(self::HEADER));
    }

    /**
     * The same read, off a raw string — so the parser can be tested and reused
     * without inventing a Request.
     *
     * @return array{app_platform: string, app_version: string, app_build: string}|null
     */
    public static function parseValue(?string $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        // Trim only the outer whitespace a proxy may add. No lower-casing and
        // no other normalisation: `IOS/1.0/47` is not a header this application
        // ships, and quietly accepting a spelling nobody sends would make the
        // reading look broader than the evidence behind it.
        $value = trim($value);

        if ($value === '' || ! preg_match(self::PATTERN, $value, $m)) {
            return null;
        }

        return [
            'app_platform' => $m[1],
            'app_version' => $m[2],
            'app_build' => $m[3],
        ];
    }

    /** Did this request come from a build that tags itself? */
    public static function isTagged(Request $request): bool
    {
        return self::parse($request) !== null;
    }

    /**
     * The column widths this class enforces, for a test to assert against the
     * schema rather than against a number typed twice.
     *
     * There are deliberately NO validation rules here any more. There were —
     * the two device-registration requests carried `string|max:|in:` for these
     * three fields — and they were removed: resolve() below is already the
     * floor that keeps an unusable value out of a column, so the rules bought
     * nothing but the ability to REFUSE a request over a counter. See
     * StoreMobileAppUserRequest.
     *
     * @return array<string, int>
     */
    public static function limits(): array
    {
        return self::LIMITS;
    }

    /**
     * What this request says the handset is running: the BODY first, the header
     * second, and only the fields that actually have a value.
     *
     * The return is deliberately sparse rather than a fixed three keys. It is
     * merged straight into an update, so a key that is missing here is a column
     * that is not written — which is the one rule this whole slice rests on:
     *
     *     AN ABSENT VALUE NEVER NULLS A STORED ONE.
     *
     * A device that reported `ios/1.0/47` last week and calls the heartbeat
     * today from a reinstalled pre-R1 build keeps its recorded build. Otherwise
     * the "how many phones are still on the old list" reading — the evidence
     * S3b turns on — would drift downwards every time somebody downgraded, and
     * it would drift SILENTLY.
     *
     * An explicit `null` in the body counts as absent for the same reason. No
     * caller has any business erasing this, and the alternative is a client bug
     * that empties a column nobody is watching.
     *
     * Body over header on purpose: the body is the value the APP chose to send
     * about itself, while the header can be rewritten by anything between the
     * two. Neither is trusted for anything but counting.
     *
     * A body value that breaks the platform list or the column width is DROPPED
     * here, not refused and not truncated. This is the ONLY gate on all three
     * verbs — registration, re-point and heartbeat — and it is the floor that
     * keeps an oversized string out of a varchar(20) on MySQL, which SQLite
     * would have taken silently (memory: sqlite-hides-mysql-column-limits).
     *
     * No verb validates these fields, on purpose. Every one of the three is a
     * call a phone has to complete to work at all: a refused registration is a
     * new handset stuck on its splash screen, a refused re-point is a member
     * who cannot switch organisation, a refused heartbeat is a live phone the
     * prayer backstop treats as dark and double-notifies. A telemetry field
     * nobody authorises on must not be able to cause any of those.
     *
     * @return array<string, string> a subset of app_platform/app_version/app_build
     */
    public static function resolve(Request $request): array
    {
        $fromHeader = self::parse($request) ?? [];
        $resolved = [];

        foreach (self::LIMITS as $field => $max) {
            $body = $request->input($field);

            if (is_string($body) && trim($body) !== '') {
                $value = trim($body);

                if (mb_strlen($value) <= $max
                    && ($field !== 'app_platform' || in_array($value, self::PLATFORMS, true))) {
                    $resolved[$field] = $value;

                    continue;
                }

                // An unusable body value does not fall through to the header:
                // the two would then disagree about the same handset and the
                // record would say whichever the app got wrong LEAST.
                continue;
            }

            if (isset($fromHeader[$field])) {
                $resolved[$field] = $fromHeader[$field];
            }
        }

        return $resolved;
    }
}
