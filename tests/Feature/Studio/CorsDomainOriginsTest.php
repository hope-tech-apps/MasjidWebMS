<?php

namespace Tests\Feature\Studio;

use App\Http\Middleware\HandleCorsWithDomains;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use Fruitcake\Cors\CorsService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * S9: CORS admits the static CORS_ALLOWED_ORIGINS list plus the origin of every
 * `masjid_domains` row confirmed serving our own site (R3), and nothing else.
 *
 * The static list is production's shape (several origins, so Fruitcake echoes
 * the Origin and sends `Vary: Origin`). A probe route under api/* stands in for
 * the API, so no controller's own cache or query muddies what is counted here.
 */
class CorsDomainOriginsTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    private const STATIC = ['https://burlingtonmasjid.com', 'https://www.burlingtonmasjid.com', 'https://sundayschool.burlingtonmasjid.com'];

    private const PROBE = '/api/s9-cors-probe';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cors.allowed_origins' => self::STATIC]);
        Cache::forget(MasjidDomain::CORS_ORIGINS_CACHE_KEY);

        // What the rest of the stack sees as the CORS list during the request.
        Route::match(['GET', 'POST'], ltrim(self::PROBE, '/'), fn () => response()->json([
            'cors_allowed_origins' => config('cors.allowed_origins'),
        ]));
    }

    private function preflight(?string $origin, string $path = self::PROBE): TestResponse
    {
        $server = ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'];

        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return $this->call('OPTIONS', $path, [], [], [], $server);
    }

    private function getFrom(?string $origin, string $path = self::PROBE): TestResponse
    {
        return $this->call('GET', $path, [], [], [], $origin === null ? [] : ['HTTP_ORIGIN' => $origin]);
    }

    private function confirmed(Masjid $org, string $host, string $status = MasjidDomain::STATUS_MANUAL, array $attributes = []): MasjidDomain
    {
        $cloudflare = $status === MasjidDomain::STATUS_ACTIVE
            ? ['verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now()]
            : [];

        return $this->makeDomain($org, $host, $status, $cloudflare + $attributes + ['serving_confirmed_at' => now()]);
    }

    private function assertAdmits(string $origin, string $why): void
    {
        $this->assertSame($origin, $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'), "{$why}: preflight");
        $this->assertSame($origin, $this->getFrom($origin)->headers->get('Access-Control-Allow-Origin'), "{$why}: GET");
    }

    private function assertRefuses(string $origin, string $why): void
    {
        $this->assertFalse($this->preflight($origin)->headers->has('Access-Control-Allow-Origin'), "{$why}: preflight");
        $this->assertFalse($this->getFrom($origin)->headers->has('Access-Control-Allow-Origin'), "{$why}: GET");
    }

    #[Test]
    public function a_preflight_from_a_new_origin_gets_no_acao_until_its_row_is_confirmed_serving(): void
    {
        $row = $this->makeDomain($this->makeOrg(), 'new.example.org', MasjidDomain::STATUS_MANUAL);

        $this->assertRefuses('https://new.example.org', 'before serving is confirmed');

        $row->update(['serving_confirmed_at' => now()]);

        $this->assertAdmits('https://new.example.org', 'once serving is confirmed');

        // The static list is still the base, and a stranger is still a stranger.
        $this->assertAdmits('https://burlingtonmasjid.com', 'a static origin');
        $this->assertRefuses('https://example.org', 'an origin nobody configured');
    }

    #[Test]
    public function a_pending_provisioning_or_awaiting_nameservers_row_admits_nothing(): void
    {
        $org = $this->makeOrg();

        foreach (MasjidDomain::NON_TERMINAL as $status) {
            // Even with the timestamp set: a host still being set up is not trusted.
            $this->makeDomain($org, str_replace('_', '-', $status) . '.example.org', $status, ['serving_confirmed_at' => now()]);
        }

        $this->confirmed($org, 'control.example.org');
        $this->assertAdmits('https://control.example.org', 'the control row');

        foreach (MasjidDomain::NON_TERMINAL as $status) {
            $this->assertRefuses('https://' . str_replace('_', '-', $status) . '.example.org', $status);
        }
    }

    #[Test]
    public function an_active_row_without_serving_confirmed_at_admits_nothing(): void
    {
        $org = $this->makeOrg();
        $row = $this->makeDomain($org, 'active.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(),
        ]);

        // Cloudflare saying the domain is live is not us seeing our site on it.
        $this->assertRefuses('https://active.example.org', 'active, unconfirmed');

        $row->update(['serving_confirmed_at' => now()]);
        $this->assertAdmits('https://active.example.org', 'active, confirmed');
    }

    #[Test]
    public function a_failed_or_reserved_row_admits_nothing(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'failed.example.org', MasjidDomain::STATUS_FAILED, ['serving_confirmed_at' => now()]);
        $this->makeDomain($org, 'reserved.example.org', MasjidDomain::STATUS_RESERVED, [
            'source' => MasjidDomain::SOURCE_IMPORTED, 'serving_confirmed_at' => now(),
        ]);
        $this->confirmed($org, 'control.example.org');

        $this->assertAdmits('https://control.example.org', 'the control row');
        $this->assertRefuses('https://failed.example.org', 'failed');
        $this->assertRefuses('https://reserved.example.org', 'reserved');
    }

    #[Test]
    public function a_trashed_orgs_confirmed_row_admits_nothing(): void
    {
        $gone = $this->makeOrg();
        $this->confirmed($gone, 'gone.example.org', MasjidDomain::STATUS_ACTIVE);
        $this->confirmed($this->makeOrg(), 'kept.example.org');

        $this->assertAdmits('https://gone.example.org', 'before the organisation is trashed');

        $gone->delete();

        // Trashing an organisation does not touch masjid_domains, so the cached
        // list lets the host through until it expires, and never after.
        $this->travel(MasjidDomain::CORS_ORIGINS_TTL + 1)->seconds();

        $this->assertRefuses('https://gone.example.org', 'after the organisation is trashed');
        $this->assertAdmits('https://kept.example.org', "another organisation's row");
    }

    #[Test]
    public function a_request_with_no_origin_or_a_static_origin_reads_neither_the_table_nor_the_cache(): void
    {
        $this->confirmed($this->makeOrg(), 'client.example.org');

        $keys = [];
        Event::listen(
            [RetrievingKey::class, CacheHit::class, CacheMissed::class, WritingKey::class, KeyWritten::class],
            function ($event) use (&$keys) {
                $keys[] = $event->key;
            }
        );

        $tableQueries = function (): int {
            return collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'masjid_domains'))->count();
        };

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getFrom(null)->assertOk();
        $this->preflight(null);
        $this->getFrom('https://www.burlingtonmasjid.com')->assertOk();
        $this->preflight('https://sundayschool.burlingtonmasjid.com');
        // A path CORS does not cover is not decided on origins at all.
        $this->getFrom('https://client.example.org', '/s9-not-an-api-path');

        $this->assertSame(0, $tableQueries(), 'a request the static list answers read masjid_domains');
        $this->assertNotContains(MasjidDomain::CORS_ORIGINS_CACHE_KEY, $keys, 'a request the static list answers read the cache');

        // The control: the two detectors do see a request that needs the table.
        $this->getFrom('https://client.example.org')->assertOk();

        $this->assertGreaterThan(0, $tableQueries(), 'the query log detector is blind');
        $this->assertContains(MasjidDomain::CORS_ORIGINS_CACHE_KEY, $keys, 'the cache detector is blind');
    }

    #[Test]
    public function the_static_origins_are_still_admitted_with_the_same_vary_header(): void
    {
        $this->confirmed($this->makeOrg(), 'client.example.org');

        $headers = fn (Response $response): array => [
            'acao' => $response->headers->get('Access-Control-Allow-Origin'),
            // One header or several, as the names a cache keys on.
            'vary' => array_map('trim', explode(',', implode(',', $response->headers->all('vary')))),
            'methods' => $response->headers->get('Access-Control-Allow-Methods'),
            'status' => $response->getStatusCode(),
        ];

        // Production's several-origin list, and the one-origin shape Fruitcake
        // answers differently (a fixed ACAO and no Vary), which a merge would change.
        foreach ([self::STATIC, ['https://sundayschool.burlingtonmasjid.com']] as $static) {
            config(['cors.allowed_origins' => $static]);

            foreach ([...$static, null] as $origin) {
                foreach (['OPTIONS', 'GET'] as $method) {
                    $server = $origin === null ? [] : ['HTTP_ORIGIN' => $origin];

                    if ($method === 'OPTIONS') {
                        $server['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
                    }

                    $request = fn () => Request::create(self::PROBE, $method, [], [], [], $server);
                    $next = fn () => response('ok');

                    $before = (new HandleCors(app(), new CorsService()))->handle($request(), $next);
                    $after = (new HandleCorsWithDomains(app(), new CorsService()))->handle($request(), $next);

                    $case = count($static) . ' static, ' . $method . ' from ' . ($origin ?? 'no Origin');
                    $this->assertSame($headers($before), $headers($after), $case);

                    if ($origin !== null && count($static) > 1) {
                        $this->assertSame($origin, $headers($after)['acao'], $case);
                        $this->assertContains('Origin', $headers($after)['vary'], $case);
                    }
                }
            }
        }

        // And through the real stack, so the registration is what is pinned.
        config(['cors.allowed_origins' => self::STATIC]);
        $response = $this->getFrom('https://www.burlingtonmasjid.com');
        $this->assertSame('https://www.burlingtonmasjid.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertContains('Origin', $headers($response->baseResponse)['vary']);
    }

    #[Test]
    public function a_wildcard_list_is_unchanged_and_reads_nothing(): void
    {
        config(['cors.allowed_origins' => ['*']]);
        $this->confirmed($this->makeOrg(), 'client.example.org');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('*', $this->getFrom('https://anyone.example')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('*', $this->preflight('https://client.example.org')->headers->get('Access-Control-Allow-Origin'));

        $this->assertSame(0, collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'masjid_domains'))->count());
    }

    #[Test]
    public function a_db_error_falls_back_to_the_static_list_with_a_2xx_and_a_logged_warning(): void
    {
        Log::spy();
        Schema::drop('masjid_domains');

        $this->getFrom('https://client.example.org')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->assertTrue($this->preflight('https://client.example.org')->isSuccessful());
        $this->getFrom('https://burlingtonmasjid.com')->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://burlingtonmasjid.com');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'CORS could not read masjid_domains'))
            ->twice();
    }

    #[Test]
    public function the_cache_key_is_forgotten_on_save(): void
    {
        $org = $this->makeOrg();
        $first = $this->confirmed($org, 'first.example.org');

        // Warm the cache: this list is now held for five minutes...
        $this->assertAdmits('https://first.example.org', 'the first row');
        $this->assertSame(['https://first.example.org'], Cache::get(MasjidDomain::CORS_ORIGINS_CACHE_KEY));

        // ...yet a newly confirmed host works at once,
        $this->confirmed($org, 'second.example.org');
        $this->assertAdmits('https://second.example.org', 'a row confirmed after the cache was warm');

        // a row that stops being trusted stops at once,
        $first->update(['status' => MasjidDomain::STATUS_FAILED]);
        $this->assertRefuses('https://first.example.org', 'a row that failed after the cache was warm');

        // and so does a removed one.
        MasjidDomain::query()->where('host', 'second.example.org')->sole()->delete();
        $this->assertRefuses('https://second.example.org', 'a row deleted after the cache was warm');
    }

    #[Test]
    public function the_merged_list_is_seen_by_the_cors_decision_only(): void
    {
        $this->confirmed($this->makeOrg(), 'client.example.org');

        $response = $this->getFrom('https://client.example.org')->assertOk();

        // Admitted by CORS...
        $this->assertSame('https://client.example.org', $response->headers->get('Access-Control-Allow-Origin'));
        // ...while the code behind it (the lunch page's Stripe return trusts this
        // config) still reads the static list, and so does the next request.
        $this->assertSame(self::STATIC, $response->json('cors_allowed_origins'));
        $this->assertSame(self::STATIC, config('cors.allowed_origins'));

        // A preflight never reaches the stack; the list is put back all the same.
        $this->assertSame('https://client.example.org', $this->preflight('https://client.example.org')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(self::STATIC, config('cors.allowed_origins'));
    }
}
