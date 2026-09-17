<?php

namespace App\Http\Middleware;

use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a request whose Host header is not one this deployment answers to.
 *
 * ==========================================================================
 * THE DEFECT
 * ==========================================================================
 *
 * Production nginx is `default_server` on :80 and :443 (verified on the box,
 * 2026-09-15), so every Host header lands on this application, and the origin
 * is reachable without going through Cloudflare. Laravel resolves `url()`,
 * `asset()` and `route()` against that header. Measured against production the
 * same day, read-only:
 *
 *     curl -H 'Host: evil.example' https://masjid.hopetechapps.com/account-deletion
 *     => <form method="POST" action="https://evil.example/account-deletion"
 *
 * That is the page on which a member types their email address and then a
 * mailed confirmation code, served by us, over our certificate, posting to a
 * host of the caller's choosing.
 *
 * Where the same header reaches a CACHE the blast radius stops being the one
 * caller: every public payload is stored under a key holding an organisation id
 * and no host, so the first request to warm an entry decides what every later
 * caller is served for the whole TTL. App\Support\SiteUrl is the fix for that
 * half — the payload builders no longer read the request at all. This
 * middleware is the other half: it stops the forged Host reaching anything.
 *
 * ==========================================================================
 * WHY NOT Illuminate\Http\Middleware\TrustHosts
 * ==========================================================================
 *
 * Three reasons, each of which cost more than this class does.
 *
 *  1. IT IS INERT UNDER TESTS. `shouldSpecifyTrustedHosts()` is
 *     `! environment('local') && ! runningUnitTests()`, so the framework's
 *     middleware does nothing whatsoever in the suite. A Feature test asserting
 *     "a forged Host is refused" would pass against an application that has
 *     never registered it. The protection this project can rely on is the
 *     protection its tests can fail on.
 *
 *  2. IT TAKES REGULAR EXPRESSIONS, and its default is
 *     `^(.+\.)?masjid\.hopetechapps\.com$` — every subdomain that exists now or
 *     ever will. We know our hostnames by name; an exact list cannot be widened
 *     by accident.
 *
 *  3. IT HAS NO MIDDLE SETTING. Symfony answers an untrusted host with a 400
 *     the moment the list is set, and the list cannot be fully known from the
 *     code — health checks and uptime monitors reach an origin by IP, sending a
 *     Host nobody wrote down. See config/trusted_hosts.php on why this ships
 *     observing rather than refusing.
 *
 * ==========================================================================
 * FAILING OPEN, ON PURPOSE, IN TWO PLACES
 * ==========================================================================
 *
 *  - An EMPTY allowlist passes everything. That is the unconfigured case — a
 *    developer machine with no APP_URL — and a security middleware that bricks
 *    a fresh checkout gets deleted rather than fixed.
 *  - `enforce` false passes everything and logs. The rollout is: ship, read the
 *    log, then set TRUSTED_HOSTS_ENFORCE=true.
 *
 * Neither weakens the payload fix, which does not consult this middleware.
 */
class TrustedHosts
{
    /** One row: how many new hosts were logged in the current interval. */
    private const BUDGET_KEY = 'trusted-hosts:logged';

    /**
     * The most bytes of any caller-chosen string that go into a log line. 253
     * is the longest name DNS can carry, so a longer Host is not a name anyone
     * could own and nothing of ours is lost by cutting it.
     */
    private const MAX_LOGGED_BYTES = 253;

    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->normalise((string) $request->getHost());
        $allowed = $this->allowedHosts();

        if ($allowed === [] || in_array($host, $allowed, true)) {
            return $next($request);
        }

        $this->report($request, $host, $allowed);

        if (! config('trusted_hosts.enforce')) {
            return $next($request);
        }

        // 400, not 404 or 421: the request is malformed at the HTTP level — it
        // names a host this server does not serve — and a body is deliberately
        // terse. Naming the hosts we DO serve would hand a scanner the list.
        abort(Response::HTTP_BAD_REQUEST, 'Bad Request');
    }

    /**
     * The hosts this deployment answers to, assembled from the settings that
     * already define them rather than restated. Read at request time so config
     * load order is irrelevant; see config/trusted_hosts.php.
     *
     * @return array<int, string>
     */
    private function allowedHosts(): array
    {
        $hosts = array_merge(
            [SiteUrl::host()],
            array_keys((array) config('portal.hosts', [])),
            (array) config('trusted_hosts.extra', []),
        );

        $hosts = array_map(fn ($host): string => $this->normalise((string) $host), $hosts);

        return array_values(array_unique(array_filter(
            $hosts,
            static fn (string $host): bool => $host !== '',
        )));
    }

    /**
     * Lower-case, port stripped, trailing dot removed.
     *
     * The trailing dot matters: `masjid.hopetechapps.com.` is the same name to
     * DNS and a different string to `in_array`, and it is one of the first
     * things a scanner tries. The port matters because `getHost()` is
     * port-free but a configured host copied out of a URL may not be.
     */
    private function normalise(string $host): string
    {
        $host = strtolower(trim($host));

        // Only a trailing `:digits` is a port. Splitting on the first colon
        // would turn an IPv6 literal (`[2001:db8::1]`) into `[2001`, and the log
        // line would then name a host nobody can look up.
        if (! str_starts_with($host, '[')) {
            $host = (string) preg_replace('/:\d+$/', '', $host);
        }

        return rtrim($host, '.');
    }

    /**
     * One log line per unknown host per interval, and a ceiling on all of them.
     *
     * Rate-limited because this IP already takes unsolicited scanner traffic
     * (`GET /crossdomain.xml` and friends in nginx's access log), and a line per
     * request would bury the thing the operator is reading the log FOR: a
     * legitimate hostname nobody put on the list.
     *
     * AT `warning`, AND THAT LEVEL IS LOAD-BEARING. Production runs
     * LOG_LEVEL=warning, so anything quieter is discarded before it reaches
     * laravel.log — an observing mode whose observations are thrown away is the
     * failure this project has already paid for once (a Log::info under that
     * level left no trace). TrustedHostsLogOnlyTest writes through a real file
     * channel set to `warning` and reads the line back.
     *
     * The host, the path and the method are the caller's, so each is cut to
     * MAX_LOGGED_BYTES in the line (production nginx lets about 8 KB of each
     * through). A cut value ends in `...[N bytes]`.
     *
     * Nothing on this path may turn into a 500. In observing mode this
     * middleware has promised to pass the request, so:
     *
     *  - a cache that cannot take the marker logs anyway. The rate limit is a
     *    convenience; a duplicate line is cheap, a missed host is what this
     *    exists to find.
     *  - a log that cannot be written is swallowed. An unopenable laravel.log
     *    (re-created by root, a full disk) would otherwise fail every request
     *    with an unlisted Host, including a hostname we serve on purpose until
     *    TRUSTED_HOSTS names it. The rest of the app cannot log either in that
     *    state, so this loses nothing an operator could have read.
     *
     * @param  array<int, string>  $allowed
     */
    private function report(Request $request, string $host, array $allowed): void
    {
        $interval = max(0, (int) config('trusted_hosts.log_interval', 3600));

        try {
            if ($interval > 0 && ! $this->claimLogLine($host, $interval)) {
                return;
            }
        } catch (\Throwable) {
            // Fall through to the log line, un-rate-limited. See above.
        }

        $this->warn('Request carried a Host header this deployment does not serve.', [
            'host' => $this->capped($host),
            'allowed' => $allowed,
            'enforced' => (bool) config('trusted_hosts.enforce'),
            'path' => $this->capped($request->path()),
            'method' => $this->capped($request->method()),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Whether this request should write the line for `$host`.
     *
     * THE HOST IS CHOSEN BY THE CALLER, so whatever this stores is too.
     * Production's cache is the `database` store on the shared MySQL, which
     * deletes an expired row only when that same key is read again. A marker
     * keyed by the host itself therefore left one permanent row, and one log
     * line, per invented Host: a client sending a new random name on every
     * request wrote without limit. Two bounds replace that:
     *
     *  - THE MARKER KEY SPACE IS FIXED, AND SO IS EACH MARKER. A host's marker
     *    lives in one of 65,536 slots (the first four hex digits of its sha1)
     *    and holds the full sha1, never the name: the name can be 8 KB long.
     *    The table can never hold more than that many 40-byte markers.
     *    A slot that holds a DIFFERENT host (a collision: about a 2% chance
     *    that any two of the 49 names production saw in two weeks share one)
     *    is overwritten and the line is written. A collision can repeat a
     *    line; it never hides a host.
     *
     *  - A BUDGET PER INTERVAL. At most `log_budget` new hosts are written
     *    per interval, counted in one row. The first host over the budget
     *    writes one "logging paused" line instead, and every later one costs
     *    two reads and no write until the interval ends. The paused line tells
     *    the reader that the log is incomplete for that interval: see
     *    deploy/TRUSTED-HOSTS-ENFORCEMENT.md.
     *
     * `Cache::add` on an empty slot is the lock: of two workers reporting the
     * same new host at once, one writes the line. On the collision path two
     * workers can both write it; that is a duplicate, not a loss.
     */
    private function claimLogLine(string $host, int $interval): bool
    {
        $fingerprint = sha1($host);
        $slot = 'trusted-hosts:seen:'.substr($fingerprint, 0, 4);
        $seen = Cache::get($slot);

        if ($seen === $fingerprint) {
            return false;
        }

        $budget = max(1, (int) config('trusted_hosts.log_budget', 200));

        if ((int) Cache::get(self::BUDGET_KEY, 0) > $budget) {
            return false;
        }

        // `add` opens the interval if none is open (the database store drops
        // an expired row when it is read, so the old count is not reused);
        // `increment` is atomic in that store.
        Cache::add(self::BUDGET_KEY, 0, $interval);
        $written = Cache::increment(self::BUDGET_KEY);

        if (is_numeric($written) && (int) $written > $budget) {
            if ((int) $written === $budget + 1) {
                $this->warn('Unknown-Host logging paused: more new hostnames than trusted_hosts.log_budget this interval. Hosts after this one are not logged until the interval ends.', [
                    'budget' => $budget,
                    'interval_seconds' => $interval,
                    'first_unlogged_host' => $this->capped($host),
                    'enforced' => (bool) config('trusted_hosts.enforce'),
                ]);
            }

            return false;
        }

        if ($seen === null) {
            return Cache::add($slot, $fingerprint, $interval);
        }

        Cache::put($slot, $fingerprint, $interval);

        return true;
    }

    /** At most MAX_LOGGED_BYTES of `$value`, cut on a character boundary. */
    private function capped(string $value): string
    {
        if (strlen($value) <= self::MAX_LOGGED_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::MAX_LOGGED_BYTES, 'UTF-8').'...['.strlen($value).' bytes]';
    }

    /** @param  array<string, mixed>  $context */
    private function warn(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            // See report(): observing must not refuse by accident.
        }
    }
}
