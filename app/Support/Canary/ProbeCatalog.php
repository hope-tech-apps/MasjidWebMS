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
        $prefixes = (array) ($this->config['prefixes'] ?? []);
        $skip = (array) ($this->config['skip'] ?? []);
        $globals = (array) ($this->config['global_endpoints'] ?? []);

        $found = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            if (isset($found[$uri]) || in_array($uri, $skip, true)) {
                continue;
            }

            if (! $this->hasPrefix($uri, $prefixes)) {
                continue;
            }

            // READ-ONLY, enforced here and asserted again before dispatch.
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // A route that also answers a write verb is not a read endpoint.
            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== []) {
                continue;
            }

            $params = $route->parameterNames();

            if (array_diff($params, ['masjid_id']) !== []) {
                continue;
            }

            if (! $this->middlewareIsProbeable($route)) {
                continue;
            }

            $found[$uri] = [
                'uri' => $uri,
                'global' => in_array($uri, $globals, true),
                'params' => $params,
            ];
        }

        ksort($found);

        return array_values($found);
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

    private function middlewareIsProbeable(Route $route): bool
    {
        $allowed = (array) ($this->config['throttle_allowlist'] ?? []);

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                // A closure or class-string middleware the canary cannot reason
                // about. Leave the endpoint alone rather than guess.
                return false;
            }

            if (Str::startsWith($middleware, 'auth')) {
                return false;
            }

            if (Str::startsWith($middleware, 'throttle:')) {
                if (! in_array(Str::after($middleware, 'throttle:'), $allowed, true)) {
                    return false;
                }

                continue;
            }

            if (in_array($middleware, self::NON_PUBLIC, true)) {
                return false;
            }
        }

        return true;
    }
}
