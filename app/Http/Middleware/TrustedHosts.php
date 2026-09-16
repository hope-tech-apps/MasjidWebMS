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
        $host = explode(':', $host, 2)[0];

        return rtrim($host, '.');
    }

    /**
     * One log line per unknown host per interval.
     *
     * Rate-limited because this IP already takes unsolicited scanner traffic
     * (`GET /crossdomain.xml` and friends in nginx's access log), and a line per
     * request would bury the thing the operator is reading the log FOR: a
     * legitimate hostname nobody put on the list.
     *
     * `Cache::add` is the whole lock — it writes only if the key is absent, so
     * two concurrent workers produce one line, not two.
     *
     * @param  array<int, string>  $allowed
     */
    private function report(Request $request, string $host, array $allowed): void
    {
        $interval = max(0, (int) config('trusted_hosts.log_interval', 3600));
        $key = 'trusted-hosts:seen:'.sha1($host);

        if ($interval > 0 && ! Cache::add($key, true, $interval)) {
            return;
        }

        Log::warning('Request carried a Host header this deployment does not serve.', [
            'host' => $host,
            'allowed' => $allowed,
            'enforced' => (bool) config('trusted_hosts.enforce'),
            'path' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
    }
}
