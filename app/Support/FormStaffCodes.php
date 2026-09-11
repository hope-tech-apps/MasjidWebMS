<?php

namespace App\Support;

use App\Models\Form;
use App\Models\FormStaffCode;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The public side of a staff member's cash code (DECISIONS.md 2026-09-11; festival
 * brief, blocker 3): checking a code typed at the gate, the failure limiter in front
 * of that check, and the short-lived signed token a phone swaps its code for.
 *
 * ## Why a token, and not the code on every entry
 *
 * Every phone at the venue is behind one wifi NAT, so anything keyed by IP is shared
 * by every staff member AND every attendee there. The code needs a failure limiter —
 * it is the only thing between the public page and a registration its holder owes
 * cash for — but if every cash entry carried the code, one attendee typing junk codes
 * would lock every staff phone out of cash entry for fifteen minutes. So a phone
 * presents its code ONCE (POST /api/v1/forms/{id}/staff-session) and sends the token
 * it gets back with each entry. A token is signed with APP_KEY, so it cannot be
 * guessed, and a token-bearing request never touches the failure buckets at all —
 * nor, once it checks out, the per-connection flood guard every other staff request
 * meets on the route (throttleBucket()).
 *
 * ## The failure limiter
 *
 * Checked BEFORE any lookup — a locked-out caller learns nothing and costs no query —
 * and hit by every failure, whatever its cause. The route limiter asks it first too
 * (throttleBucket()), so a locked-out caller learns nothing from the limit headers
 * either. Two buckets (config forms.staff_code_failures):
 *
 *   device   ip|form|device_id: one phone's own mistakes. A neighbour on the same
 *            wifi with its own device_id is not caught by them.
 *   form     every failure on the form, from anywhere: device_id is the client's to
 *            choose, so without a ceiling a guesser would simply rotate it.
 *
 * An admin clears a form's lockout with clearLockout().
 *
 * ## One refusal
 *
 * Unknown, revoked, expired, another form's, another masjid's, codes switched off on
 * the form, a code bound to another phone, a malformed code, a forged or stale token:
 * every one of them is refusedResponse(), byte for byte. A caller who could tell
 * "revoked" from "never existed" would be told which of their guesses were once real.
 *
 * ## The plaintext goes nowhere
 *
 * The only derivative of a code that leaves this class is FormStaffCode::hashFor()
 * (an HMAC keyed on APP_KEY) — including the throttle bucket, which lands in the
 * cache. Logs carry the code's id and two-character hint, never the code.
 *
 * Pinned by tests/Feature/FormStaffCodeTest.php.
 */
final class FormStaffCodes
{
    /** The one answer to every staff credential that did not check out. */
    public const REFUSED = 'That staff code was not accepted.';

    /**
     * A code is eight symbols; pasted with spaces and dashes it is a little longer.
     * Anything past this is not a code, and is refused without being hashed.
     */
    public const MAX_TYPED_LENGTH = 32;

    /** The request carries a code to check… */
    public const CODE = 'code';

    /** …or the token a code was exchanged for. */
    public const TOKEN = 'token';

    private const TOKEN_VERSION = 'v1';

    // ------------------------------------------------------------ the code

    /** The spelling a code is hashed in (FormStaffCode::normalise()). */
    public static function normalise(string $code): string
    {
        return FormStaffCode::normalise($code);
    }

    /** The code's keyed digest (FormStaffCode::hashFor()) — the only thing any key may be derived from. */
    public static function hash(string $code): string
    {
        return FormStaffCode::hashFor($code);
    }

    /**
     * Which staff credential a request carries, if any. A token wins over a code:
     * the renderer sends one or the other, and a phone holding a token has already
     * been through the code check.
     */
    public static function presented(Request $request): ?string
    {
        if (filled($request->input('staff_token'))) {
            return self::TOKEN;
        }

        if (filled($request->input('staff_code'))) {
            return self::CODE;
        }

        return null;
    }

    /**
     * A submission's staff credential, checked: a code through attempt() and its
     * limiter, a token through verifyToken(), which never touches the limiter.
     */
    public static function check(Form $form, int $masjidId, Request $request): StaffCodeCheck
    {
        return match (self::presented($request)) {
            self::TOKEN => ($code = self::verifyToken($form, $masjidId, $request->input('staff_token'), $request->input('device_id'))) !== null
                ? StaffCodeCheck::accepted($code)
                : StaffCodeCheck::refused(),
            self::CODE => self::attempt($form, $masjidId, $request->input('staff_code'), $request->input('device_id'), $request->ip()),
            default => StaffCodeCheck::refused(),
        };
    }

    /**
     * Check a code typed at the gate, behind the failure limiter.
     *
     * In order: the limiter (locked out, and nothing else runs); the lookup; then the
     * device binding. The first phone to present a live code owns it until an admin
     * releases it (FormStaffCode::bindToDevice()), so a code read over someone's
     * shoulder is refused on any other phone. Every failure is counted.
     *
     * The code and device_id are taken as they came off the request — neither is
     * validated upstream — so a malformed one is refused here, the same way as a
     * wrong one.
     */
    public static function attempt(Form $form, int $masjidId, mixed $code, mixed $deviceId, ?string $ip): StaffCodeCheck
    {
        $device = self::deviceId($deviceId);
        $buckets = self::failureBuckets((int) $form->id, $ip, $device);
        $full = self::fullBucket($buckets);

        if ($full !== null) {
            return StaffCodeCheck::lockedOut(RateLimiter::availableIn($full));
        }

        $found = self::lookup($form, $masjidId, $code);

        if ($found !== null && $device !== null && $found->bindToDevice($device)) {
            return StaffCodeCheck::accepted($found);
        }

        if ($found !== null && $found->bound_device_id !== null) {
            // A real, live code on a phone that is not its own: shared, read over a
            // shoulder, or a staff member who changed phones. Worth finding in a log
            // that runs at warning — by id and hint, never the code.
            Log::warning('A staff code was presented from a device it is not bound to.', [
                'masjid_id' => $masjidId,
                'form_id' => $form->id,
                'staff_code_id' => $found->id,
                'code_hint' => $found->code_hint,
            ]);
        }

        self::recordFailure($buckets, $form, $masjidId, $ip);

        return StaffCodeCheck::refused();
    }

    /**
     * An admin's "clear lockout": every failure bucket on the form, gone at once.
     *
     * The buckets are keyed on a per-form generation, so moving the form to a new one
     * retires them all without having to know which connections and devices failed.
     */
    public static function clearLockout(Form $form): void
    {
        Cache::forever(self::generationKey((int) $form->id), Str::random(16));
    }

    // ----------------------------------------------------------- the token

    /**
     * Mint the token a checked code is exchanged for.
     *
     * It says which form, which code and when it stops working — signed, so none of
     * the three can be altered — and nothing else: not the holder, not the code. It
     * lasts forms.staff_token_ttl_minutes (12 hours covers the event day), and never
     * past the code's own expiry.
     *
     * @return array{token: string, expires_at: CarbonInterface}
     */
    public static function issueToken(FormStaffCode $code): array
    {
        $ttl = now()->addMinutes(max(1, (int) config('forms.staff_token_ttl_minutes', 720)));
        $expires = $code->expires_at !== null && $code->expires_at->lt($ttl) ? $code->expires_at : $ttl;

        $formId = (int) $code->form_id;
        $codeId = (int) $code->getKey();
        $expiry = $expires->getTimestamp();

        return [
            'token' => implode('.', [self::TOKEN_VERSION, $formId, $codeId, $expiry, self::signature($formId, $codeId, $expiry)]),
            'expires_at' => Carbon::createFromTimestamp($expiry, config('app.timezone')),
        ];
    }

    /**
     * A token's claims when it is genuine and in date, else null. No database: a
     * forged or stale token costs one HMAC.
     *
     * @return array{form_id: int, code_id: int, expires: int}|null
     */
    public static function readToken(mixed $token): ?array
    {
        if (! is_string($token) || strlen($token) > 255) {
            return null;
        }

        $pattern = '/^' . preg_quote(self::TOKEN_VERSION, '/') . '\.([1-9][0-9]{0,17})\.([1-9][0-9]{0,17})\.([1-9][0-9]{0,11})\.([0-9a-f]{64})\z/';

        if (preg_match($pattern, $token, $m) !== 1) {
            return null;
        }

        [$formId, $codeId, $expiry] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if (! hash_equals(self::signature($formId, $codeId, $expiry), $m[4]) || $expiry <= now()->getTimestamp()) {
            return null;
        }

        return ['form_id' => $formId, 'code_id' => $codeId, 'expires' => $expiry];
    }

    /**
     * The live code behind a token presented on this form from this phone, or null.
     *
     * Everything that can change after the exchange is asked again: the code revoked
     * or expired, codes switched off on the form, or the phone released by an admin
     * and the code claimed by another — the old phone's token goes with its binding.
     * Hand-filtered by the header's masjid and the URL's form: this runs unbound.
     */
    public static function verifyToken(Form $form, int $masjidId, mixed $token, mixed $deviceId): ?FormStaffCode
    {
        return $form->takesStaffCodes() ? self::tokenCode($token, (int) $form->id, $masjidId, $deviceId) : null;
    }

    // -------------------------------------------------------- the route limiter

    /**
     * How the route limiter charges a request carrying a staff credential, or null when
     * it carries none (the public limit applies instead).
     *
     *   verified  a genuine token for a live code on this form, at the header's masjid,
     *             from the phone the code is bound to: verifyToken()'s own check, in the
     *             one query a signed token already cost. Nothing a stranger sends can
     *             pass it, so it is charged to its code's hourly bucket ALONE. The
     *             per-connection flood guard every other staff request meets is one a
     *             stranger on the venue wifi can fill with junk codes at will, and it
     *             would then stop every phone behind the same address from recording
     *             cash, the holder's hourly allowance spent on each refused try.
     *   bucket    the per-code bucket: the code's HMAC digest, so a code and its token
     *             share one hourly limit however it is presented. Keyed by the HMAC,
     *             never a bare hash: throttle keys land in the cache, which is the
     *             database by default, and a sha256 of eight characters is reversed by
     *             anyone with a copy. Null for a token that did not check out (forged,
     *             stale, another phone's, a released phone's): it writes nothing, and
     *             charging it to its code would let a phone an admin has released spend
     *             its holder's hour. Null, too, for a typed code attempt() would refuse
     *             (acceptableCode(): unknown, revoked, expired, switched off, bound to
     *             another phone, or typed by a caller the failure limiter has locked out).
     *             Charged to its code, every refused try would spend the holder's hour,
     *             so anyone who knew a code could stop its holder recording cash by typing
     *             it on other phones, which is what the device binding exists to refuse.
     *             And the per-code limit's X-RateLimit headers would tell a real code from
     *             a made-up one, and a revoked code from one that never existed. Every
     *             refusal meets the per-connection guard alone, with the same headers.
     *
     * @return array{verified: bool, bucket: ?string}|null
     */
    public static function throttleBucket(Request $request, int|string|null $formId): ?array
    {
        switch (self::presented($request)) {
            case self::TOKEN:
                $code = self::tokenCode(
                    $request->input('staff_token'),
                    (int) $formId,
                    (int) $request->header('masjid-id'),
                    $request->input('device_id')
                );

                return ['verified' => $code !== null, 'bucket' => $code?->code_hash];

            case self::CODE:
                $code = self::acceptableCode(
                    (int) $formId,
                    (int) $request->header('masjid-id'),
                    $request->input('staff_code'),
                    $request->input('device_id'),
                    $request->ip()
                );

                return ['verified' => false, 'bucket' => $code?->code_hash];

            default:
                return null;
        }
    }

    // ------------------------------------------------------------ responses

    /**
     * The one refusal, whatever went wrong: a field bag on `staff_code`, the shape
     * the renderer already reads 422s in. Built only here, so it cannot drift.
     */
    public static function refusedResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'data' => ['staff_code' => [self::REFUSED]],
        ], 422);
    }

    /**
     * A caller the failure limiter has locked out, told the real wait: the Retry-After
     * header, and the same minutes in words (TryAgainIn, as every other 429 says it). A
     * staff member told "a few minutes" of a fifteen-minute lockout keeps retrying into it
     * instead of switching to another phone.
     */
    public static function lockedOutResponse(int $retryAfterSeconds): JsonResponse
    {
        $seconds = max(1, $retryAfterSeconds);

        return response()->json([
            'status' => 'error',
            'message' => 'Too many staff code attempts. ' . TryAgainIn::words($seconds),
        ], 429, ['Retry-After' => (string) $seconds]);
    }

    // -------------------------------------------------------------- helpers

    /**
     * The live code behind a genuine token presented for $formId at $masjidId, from the
     * phone the code is bound to, or null.
     *
     * Everything that can change after the exchange is asked again: the code revoked
     * or expired, or the phone released by an admin and the code claimed by another —
     * the old phone's token goes with its binding. Hand-filtered by the header's masjid
     * and the URL's form: this runs unbound. Shared by verifyToken() and
     * throttleBucket(), so the check and the limiter agree on what a good token is. One
     * query, and only for a token whose signature holds.
     */
    private static function tokenCode(mixed $token, int $formId, int $masjidId, mixed $deviceId): ?FormStaffCode
    {
        $claims = self::readToken($token);
        $device = self::deviceId($deviceId);

        if ($claims === null || $device === null || $claims['form_id'] !== $formId) {
            return null;
        }

        $code = FormStaffCode::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('form_id', $formId)
            ->whereKey($claims['code_id'])
            ->first();

        if ($code === null || ! $code->isUsable() || ! is_string($code->bound_device_id) || ! hash_equals($code->bound_device_id, $device)) {
            return null;
        }

        return $code;
    }

    /**
     * The live code a typed staff_code names, when attempt() would accept it from this
     * phone right now, or null: attempt()'s checks in its order, reading only. The failure
     * limiter first, so a locked-out caller costs no query and learns nothing; then the
     * lookup; then the binding (unbound, which this phone would claim, or bound to this
     * phone). Nothing is counted or claimed here. Hand-filtered by the header's masjid and
     * the URL's form: the route limiter runs before the controller, unbound.
     */
    private static function acceptableCode(int $formId, int $masjidId, mixed $code, mixed $deviceId, ?string $ip): ?FormStaffCode
    {
        $device = self::deviceId($deviceId);

        if ($device === null || $formId <= 0 || $masjidId <= 0 || self::fullBucket(self::failureBuckets($formId, $ip, $device)) !== null) {
            return null;
        }

        $form = Form::query()->where('masjid_id', $masjidId)->whereKey($formId)->first();
        $found = $form !== null ? self::lookup($form, $masjidId, $code) : null;

        if ($found === null) {
            return null;
        }

        return $found->bound_device_id === null || hash_equals((string) $found->bound_device_id, $device) ? $found : null;
    }

    private static function lookup(Form $form, int $masjidId, mixed $code): ?FormStaffCode
    {
        // Codes switched off is a refusal like any other, and counted like one.
        if (! $form->takesStaffCodes() || ! is_string($code) || mb_strlen($code) > self::MAX_TYPED_LENGTH) {
            return null;
        }

        return FormStaffCode::findUsable($masjidId, (int) $form->id, $code);
    }

    /**
     * @return array<string, array{key: string, max: int}>  scope => limiter key and failures allowed per window
     */
    private static function failureBuckets(int $formId, ?string $ip, ?string $device): array
    {
        $prefix = 'form-code-fail:' . $formId . ':' . self::generation($formId) . ':';

        return [
            // Hashed to bound the key's length — the database cache's key column is
            // 255 and device_id can be that long on its own — not for secrecy:
            // nothing secret goes in.
            'device' => ['key' => $prefix . hash('sha256', ($ip ?? '') . '|' . ($device ?? '')), 'max' => self::limit('per_device', 5)],
            'form' => ['key' => $prefix . 'form', 'max' => self::limit('per_form', 50)],
        ];
    }

    /**
     * The key of the first failure bucket that is full, or null. Reads the limiter; counts
     * nothing.
     *
     * @param  array<string, array{key: string, max: int}>  $buckets
     */
    private static function fullBucket(array $buckets): ?string
    {
        foreach ($buckets as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket['key'], $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return null;
    }

    /** @param  array<string, array{key: string, max: int}>  $buckets */
    private static function recordFailure(array $buckets, Form $form, int $masjidId, ?string $ip): void
    {
        $decaySeconds = self::limit('decay_minutes', 15) * 60;

        foreach ($buckets as $scope => $bucket) {
            if (RateLimiter::hit($bucket['key'], $decaySeconds) === $bucket['max']) {
                // Once, on the failure that trips it: a gate that stops taking codes
                // should be findable in a log that runs at warning.
                Log::warning('Staff code attempts are locked out.', [
                    'masjid_id' => $masjidId,
                    'form_id' => $form->id,
                    'scope' => $scope,
                    'ip' => $ip,
                    'minutes' => $decaySeconds / 60,
                ]);
            }
        }
    }

    private static function generation(int $formId): string
    {
        return (string) Cache::get(self::generationKey($formId), '0');
    }

    private static function generationKey(int $formId): string
    {
        return 'form-code-fail-generation:' . $formId;
    }

    private static function limit(string $key, int $default): int
    {
        return max(1, (int) config('forms.staff_code_failures.' . $key, $default));
    }

    /** The renderer's device_id as a string, or null when there is nothing to bind a code to. */
    private static function deviceId(mixed $deviceId): ?string
    {
        if (is_int($deviceId)) {
            $deviceId = (string) $deviceId;
        }

        if (! is_string($deviceId)) {
            return null;
        }

        $deviceId = trim($deviceId);

        // The width of form_staff_codes.bound_device_id and form_responses.device_id.
        return $deviceId !== '' && strlen($deviceId) <= 255 ? $deviceId : null;
    }

    /**
     * Prefixed, so this MAC can never collide with any other APP_KEY HMAC in the app
     * (a code's digest, the replay guard's): the messages differ whatever the inputs.
     */
    private static function signature(int $formId, int $codeId, int $expiry): string
    {
        return hash_hmac('sha256', "form-staff-token|{$formId}|{$codeId}|{$expiry}", (string) config('app.key'));
    }
}
