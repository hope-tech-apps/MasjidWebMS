<?php

namespace App\Support\Canary;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Decides what the canary probes, by READING THE ROUTER — not a list.
 *
 * ## Why discovery and not a hand-written list
 *
 * `.claude/rules/tenant-scoping.md` has required a cross-tenant Feature test
 * per model using the tenant traits since before either production hole
 * shipped. The requirement was silently unmet for six models. The rule was not
 * wrong; it depended on a person remembering, and people do not.
 *
 * A route table cannot forget a route. Every public GET collection endpoint
 * that exists is probed by the next scheduled run, including the one merged
 * this afternoon by someone who never read this file. That is the property that
 * makes this a canary rather than another audit.
 *
 * ## What is excluded, and why each exclusion is safe
 *
 *  - **Anything that is not a GET.** Structural, not a policy: the canary runs
 *    against production continuously and must not write. See TenancyCanary.
 *  - **Authenticated routes.** They need a credential the canary does not have
 *    and must not carry; the admin tree's isolation is `ResolveMasjidTenant` +
 *    `BelongsToMasjid`, a different mechanism with its own tests.
 *  - **Routes with parameters other than `{masjid_id}`.** A `{slug}` or `{id}`
 *    is a specific row, and guessing one either 404s (noise) or reads a record
 *    the canary has no business reading. Collections are where a fail-open
 *    scope shows up as *more rows*, which is the signal.
 *  - **Routes behind a named limiter the canary is not allowed to spend.**
 *    `throttle:device` is 10 per HOUR per IP. A canary that consumes it every
 *    hour has created an outage in the name of watching for one.
 *
 * ## The refusals are REPORTED, and that is new
 *
 * Every reason above is a decision not to look at a route that exists. Until
 * 2026-08-13 that decision was invisible: `TenancyCanary::blind_spots` is built
 * from `notProbed` (budget truncation) and `unreached` (probed and never
 * answered), and a route this class REFUSED is in neither, so it was not in the
 * plan, not in `blind_spots`, not in coverage and not in the verdict. Measured
 * on this branch: a total swap with no masjid filter at all on an `{id}` route,
 * then `--all` -> `exit=0 clean findings=0 blind_spots=[]`, and the uri
 * mentioned anywhere in the run payload: **false**. On-call read `clean`,
 * `endpoints_reached: 31/31`, and nothing said six public GET routes were never
 * looked at — among them `/api/v1/offerings/{slug}`, which publishes a
 * program's name, its seats and its PRICES, and which is refused twice over (a
 * `{slug}` AND `throttle:registration-quote`).
 *
 * So `declined()` returns them, by uri, with the reason, and the command names
 * them on every run at every verdict level. It is REPORTING and not probing,
 * and it deliberately does not degrade the run: these are standing structural
 * facts about the route table rather than events in a run, and a canary that
 * goes amber every night for something no operator can act on tonight is the
 * failure this whole design is a reaction to. `TenancyCanary::plan()` carries
 * the argument for why the `{id}` routes are still not probed.
 *
 * The docblock above used to argue the parameter exclusion on the grounds that
 * "collections are where a fail-open scope shows up as more rows". That is true
 * of the count detector and **false of the row-ownership detector**, whose whole
 * purpose is to catch a leak where the row count is exactly right — a
 * `findOrFail($id)` with no `masjid_id` filter serves exactly one row, the
 * correct number, belonging to somebody else. The exclusion stands on cost and
 * on the limiters, not on that sentence.
 *
 * ## What is refused and COUNTED — the census
 *
 * Writes, authenticated routes and everything outside `canary.prefixes` are not
 * in `declined()`, and until 2026-08-13 they were in nothing else either. The
 * argument for that was real and it was answering the wrong question: listing a
 * hundred admin URIs beside the eight refusals that are this canary's own blind
 * spots WOULD bury them. But the reader of a run has no way to discover that the
 * eight are eight OUT OF 333.
 *
 * Measured on this route table, 2026-08-13:
 *
 *     routes in the application                    364
 *     probed by a `--all` run                       31
 *     public GET refused by this class               8   (declined(), by uri)
 *     write-verb routes under canary.prefixes       12   ALL of them unauthenticated
 *     authenticated GETs under canary.prefixes       0
 *     outside canary.prefixes entirely             313   api/admin 288, api/family 14
 *
 * `routes_not_planned` — the eight — sat next to `endpoints_reached: 31/31` and
 * `blind_spots: []` and read as though it were the whole boundary. It is 8 of
 * 333. The seven unauthenticated POSTs on the graded `api/v1` prefix are READS
 * that happen to be shaped as writes — `offerings/{slug}/quote`,
 * `zakat/calculate`, `registrations/{uuid}/checkout` — and the 302 routes under
 * `api/admin` and `api/family` are where a school's classrooms, its guardians
 * and its children's records will live.
 *
 * So `census()` returns the arithmetic — counts and groups, never a hundred
 * URIs — and the command reports it at every verdict level. It is a standing
 * property of the route table, not an event in a run, so like `declined()` it
 * degrades nothing: it exists so that `clean` cannot be read as a claim about
 * the application when it is a claim about 31 routes out of 364.
 */
final class ProbeCatalog
{
    /**
     * Middleware aliases that mean "not a public, unauthenticated endpoint".
     * `tenant`/`family.*` bind a tenant from a credential, which is the admin
     * and parent realms — out of scope here by construction.
     */
    private const NON_PUBLIC = [
        'admin', 'super', 'tenant', 'crm', 'assistant',
        'family.active', 'family.tenant', 'family.guest',
        'signed', 'verified', 'role', 'permission', 'role_or_permission',
    ];

    /** @var array<string,array{uri:string,global:bool,params:array<int,string>}>|null */
    private ?array $planned = null;

    /** @var array<string,string> */
    private array $declined = [];

    /** @var array<string,mixed> */
    private array $census = [];

    /**
     * @param  array<string,mixed>  $config  the `canary` config array
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $config,
    ) {
    }

    /**
     * Every endpoint the canary is willing to probe, as
     * ['uri' => string, 'global' => bool, 'params' => string[]].
     *
     * @return array<int,array{uri:string,global:bool,params:array<int,string>}>
     */
    public function endpoints(): array
    {
        $this->scan();

        return array_values($this->planned ?? []);
    }

    /**
     * Every public GET on a probed prefix that this class REFUSED to plan, and
     * why — the canary's own blind spots, as opposed to the run's.
     *
     * Reported rather than silently dropped. See the class docblock for the
     * measurement that made this necessary, and for why writes and authenticated
     * routes are refused without being listed here.
     *
     * @return array<string,string> uri => reason(s)
     */
    public function declined(): array
    {
        $this->scan();

        return $this->declined;
    }

    /**
     * Every route in the application that this canary will never probe, as
     * arithmetic rather than as a list of URIs.
     *
     * `declined()` above is the canary's blind spots INSIDE the surface it
     * looks at. This is the surface it does not look at at all, and it is two
     * orders of magnitude larger. See the class docblock for the measurement and
     * for why it is counts and groups rather than 313 URIs.
     *
     * @return array<string,mixed>
     */
    public function census(): array
    {
        $this->scan();

        return $this->census;
    }

    /**
     * One pass over the router, producing both halves of the same decision.
     *
     * Kept as one pass with one set of predicates on purpose: the planned list
     * and the refused list have to be complements, and two separate walks with
     * two copies of the rules is how a route ends up in neither. The census is
     * taken in the SAME pass and from the SAME predicates for exactly that
     * reason one level up: a route counted as "outside the prefixes" by a second
     * walk with its own copy of `hasPrefix` is a route that can be planned and
     * counted as unwatched at once, or neither.
     */
    private function scan(): void
    {
        if ($this->planned !== null) {
            return;
        }

        $prefixes = (array) ($this->config['prefixes'] ?? []);
        $skip = (array) ($this->config['skip'] ?? []);
        $globals = (array) ($this->config['global_endpoints'] ?? []);

        $found = [];
        $declined = [];

        // Counted per ROUTE, not per uri: `POST api/mobile/user` and
        // `PUT api/mobile/user` are two routes on one uri, and two things
        // nothing probes.
        $total = 0;
        $underPrefixes = 0;
        $writeVerbs = [];
        $credentialed = [];
        $outside = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            $total++;

            if (! $this->hasPrefix($uri, $prefixes)) {
                $group = $this->group($uri);
                $outside[$group] = ($outside[$group] ?? 0) + 1;

                continue;
            }

            $underPrefixes++;

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $writes = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods);

            // READ-ONLY, enforced here and asserted again before dispatch. A
            // route that also answers a write verb is not a read endpoint
            // either. Both land in the census: a POST is not something a
            // read-only prober may ever touch, and that is a reason to leave it
            // alone rather than a reason to stop saying it is unwatched.
            if (! in_array('GET', $methods, true) || $writes !== []) {
                $writeVerbs[] = implode('|', $methods).' '.$uri.
                    ($this->needsACredential($route) ? '' : '  [unauthenticated]');

                continue;
            }

            // A credential this canary does not have and must not carry. The
            // admin tree's isolation is `ResolveMasjidTenant` + `BelongsToMasjid`
            // with its own tests; that makes it somebody else's mechanism, not
            // a watched one.
            if ($this->needsACredential($route)) {
                $credentialed[] = $uri;

                continue;
            }

            if (isset($found[$uri]) || isset($declined[$uri])) {
                continue;
            }

            $params = $route->parameterNames();
            $reasons = [];

            if (in_array($uri, $skip, true)) {
                $reasons[] = 'named in canary.skip — a GET that writes, or otherwise unsafe to probe';
            }

            $otherParams = array_diff($params, ['masjid_id']);

            if ($otherParams !== []) {
                $reasons[] = 'takes {'.implode('}, {', $otherParams).'} — a specific row, and this canary '.
                    'plans collections only';
            }

            $limiter = $this->unspendableLimiter($route);

            if ($limiter !== null) {
                $reasons[] = 'behind throttle:'.$limiter.', which is not in canary.throttle_allowlist — '.
                    'a canary that eats a scarce bucket on a schedule is an availability bug it introduced itself';
            }

            if ($this->hasUnreadableMiddleware($route)) {
                $reasons[] = 'carries middleware this canary cannot reason about (a closure or class-string), '.
                    'so it is left alone rather than guessed at';
            }

            if ($reasons !== []) {
                $declined[$uri] = implode('; ', $reasons);

                continue;
            }

            $found[$uri] = [
                'uri' => $uri,
                'global' => in_array($uri, $globals, true),
                'params' => $params,
            ];
        }

        ksort($found);
        ksort($declined);

        $this->planned = $found;
        $this->declined = $declined;

        sort($writeVerbs);
        sort($credentialed);
        // Biggest group first: the two names an operator needs out of this are
        // the two largest, and alphabetical order buries `api/admin` behind
        // whatever gets added later.
        arsort($outside);

        $this->census = [
            'routes_total' => $total,
            'planned' => count($found),
            'never_probed' => $total - count($found),
            'probed_prefixes' => array_values(array_map(static fn ($p) => (string) $p, $prefixes)),
            'under_probed_prefixes' => $underPrefixes,
            // The eight. Their URIs and reasons are in
            // `coverage.routes_not_planned`; the count is here so the four
            // numbers can be read as one sentence.
            'public_get_refused' => count($declined),
            // Named individually because there are twelve of them, they are on
            // the surface the verdict claims, and several are READS that happen
            // to be shaped as writes.
            'write_verb_routes' => $writeVerbs,
            'credentialed_routes' => count($credentialed),
            // Grouped, not listed: 288 admin URIs in an hourly log line is how
            // the four numbers above stop being read.
            'outside_probed_prefixes' => $outside,
        ];
    }

    /**
     * The reporting bucket for a route the canary never looks at: `api/admin`,
     * `api/family`, `storage`. Two segments under `api/`, one otherwise —
     * enough to tell the parent portal from the admin tree, which is the
     * distinction an operator reading this actually needs.
     */
    private function group(string $uri): string
    {
        $segments = explode('/', $uri);

        if (($segments[0] ?? '') === 'api' && isset($segments[1])) {
            return 'api/'.$segments[1];
        }

        return $segments[0] === '' ? '/' : $segments[0];
    }

    /** @param array<int,string> $prefixes */
    private function hasPrefix(string $uri, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($uri === $prefix || Str::startsWith($uri, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The route binds a tenant from a credential, or demands one. A different
     * mechanism with its own tests; not this canary's blind spot.
     */
    private function needsACredential(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (Str::startsWith($middleware, 'auth') || in_array($middleware, self::NON_PUBLIC, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first named limiter on this route that the canary is not allowed to
     * spend, or null.
     */
    private function unspendableLimiter(Route $route): ?string
    {
        $allowed = (array) ($this->config['throttle_allowlist'] ?? []);

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, 'throttle:')) {
                continue;
            }

            $limiter = Str::after($middleware, 'throttle:');

            if (! in_array($limiter, $allowed, true)) {
                return $limiter;
            }
        }

        return null;
    }

    /**
     * A closure or class-string middleware. Leave the endpoint alone rather than
     * guess — and say that is what happened.
     */
    private function hasUnreadableMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                return true;
            }
        }

        return false;
    }
}
