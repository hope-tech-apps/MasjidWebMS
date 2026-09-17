<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MobileAppFeature;
use App\Models\Service;
use App\Models\User;
use App\Services\Broadcast\EmailSuppressionService;
use App\Services\Stripe\StripeConnectService;
use App\Support\MobileCache;
use App\Support\SiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No public payload's URLs may be a function of the request that built them.
 *
 * ==========================================================================
 * THE DEFECT THIS SUITE IS THE EXECUTABLE STATEMENT OF
 * ==========================================================================
 *
 * `url()`, `asset()` and `route()` resolve against the INCOMING request's Host
 * header. Production nginx is `default_server` on :80 and :443, so any Host
 * reaches the app, and the origin answers without passing through Cloudflare.
 * Verified read-only against production on 2026-09-15:
 *
 *     curl -H 'Host: evil.example' https://masjid.hopetechapps.com/account-deletion
 *     => <form method="POST" action="https://evil.example/account-deletion"
 *
 * Every public payload is then wrapped in `Cache::remember` under a key holding
 * an organisation id and NO HOST (App\Support\MobileCache), so the first caller
 * to warm an entry decides the URLs served to every later caller for the TTL —
 * five minutes for announcements, ten for services and features. One
 * unauthenticated GET poisons an organisation; repeating it each time the entry
 * expires holds it indefinitely.
 *
 * ==========================================================================
 * WHY EACH TEST GOES THROUGH THE HTTP ENDPOINT AND THE CACHE
 * ==========================================================================
 *
 * A test that calls App\Support\MobileMedia directly passes on a broken system:
 * it never sets a Host, and it never touches the cache that is the actual blast
 * radius. So each case here:
 *
 *   1. forgets the key, so the payload is genuinely REBUILT rather than read
 *      back out of an entry an earlier assertion filled (the mistake that made
 *      an earlier host-invariance test on the menu endpoint vacuous);
 *   2. issues the request with `Host: evil.example` through the real route;
 *   3. asserts the configured host appears and the forged one does not — in the
 *      RESPONSE, and then in the CACHE ENTRY the request left behind, which is
 *      what the next caller is served.
 *
 * Step 3's second half is the one that matters. A response can be corrected on
 * the way out; a poisoned cache entry cannot.
 */
class HostHeaderUrlIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** The Host an attacker chooses. Never the configured one. */
    private const FORGED_HOST = 'evil.example';

    private const CONFIGURED_URL = 'https://masjid.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // A configured host that is NOT the test client's default, so "the URL
        // is on the configured host" cannot be satisfied by accident.
        config(['app.url' => self::CONFIGURED_URL]);

        // The middleware must not refuse the forged request before the payload
        // is built: these cases prove the BUILDERS are host-invariant, which is
        // the property that has to hold on every host the door legitimately
        // admits. TrustedHostsMiddlewareTest covers the door.
        config(['trusted_hosts.enforce' => false]);

        Storage::fake('public');
        Cache::flush();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    /**
     * A GET issued as though it arrived with an attacker-chosen Host header.
     *
     * AN ABSOLUTE URL, NOT `withHeader('Host', ...)`, AND THE DIFFERENCE IS THE
     * WHOLE TEST. `MakesHttpRequests::prepareUrlForRequest()` rewrites a path
     * through `url()`, and Symfony's `Request::create()` then overwrites
     * HTTP_HOST from the URI it was handed — so a `Host:` header set on a
     * relative request is silently discarded, and every assertion below would
     * pass against the unfixed code. Handing the forged host in the URI is what
     * actually puts it on the request. `the_test_harness_really_does_forge_the_host`
     * is the control that keeps this honest.
     */
    private function getAsForgedHost(string $path): \Illuminate\Testing\TestResponse
    {
        $response = $this->getJson('https://'.self::FORGED_HOST.$path);

        $this->assertArrivedOnHost(self::FORGED_HOST);

        return $response;
    }

    /**
     * The assertion both halves share: this string carries our host and not
     * theirs.
     */
    /**
     * The belt to the absolute-URI braces: assert the request the application
     * actually handled arrived on `$expected`.
     *
     * The URI form is what DELIVERS the forged host today. This asserts it was
     * still the host when the payload was built — a future middleware that
     * normalised the host back (a canonical-redirect, a proxy-header trust) would
     * leave every other assertion in this file passing for the wrong reason,
     * because a payload built on the RIGHT host trivially contains the right
     * host. Same idea as AppMenuTenantIsolationTest's check on the menu endpoint.
     */
    private function assertArrivedOnHost(string $expected): void
    {
        $this->assertSame(
            $expected,
            app('request')->getHost(),
            'the request the app handled did not arrive on '.$expected.
            ' — the other assertions in this case are passing for the wrong reason'
        );
    }

    /**
     * `json_encode` escapes forward slashes, so a URL reaches an assertion as
     * `https:\/\/masjid.test\/...`. Every assertion and precondition in this
     * file reads its subject through here — an assertion that fails for the
     * wrong reason gets "fixed" by weakening it, which is how a real one quietly
     * stops holding.
     */
    private function readable(string $subject): string
    {
        return str_replace('\/', '/', $subject);
    }

    private function assertOnConfiguredHost(string $subject, string $what): void
    {
        $subject = $this->readable($subject);

        $this->assertStringNotContainsString(
            self::FORGED_HOST,
            $subject,
            $what.' carried the forged Host — a caller chose the address served to everyone else'
        );
        $this->assertStringContainsString(
            self::CONFIGURED_URL,
            $subject,
            $what.' is not on the configured host'
        );
    }

    /** What the NEXT caller will be served: the raw cache entry, serialised. */
    private function cachedPayload(int $masjidId, string $resource): string
    {
        $entry = Cache::get(MobileCache::masjidKey($masjidId, $resource));

        $this->assertNotNull(
            $entry,
            "the request did not leave a {$resource} cache entry — this test is not exercising the cached path"
        );

        return json_encode($entry);
    }

    #[Test]
    public function the_announcements_payload_and_its_cache_entry_stay_on_the_configured_host(): void
    {
        $masjid = $this->makeMasjid();

        // No media: the placeholder path, which the review confirmed is the
        // reachable one — most live announcements have no image row.
        Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => 'Imageless notice',
            'summary' => 'summary',
            'details' => 'details',
            'text' => 'text',
            'start_date' => '2026-08-28',
            'end_date' => '2026-09-28',
        ]);

        MobileCache::flushMasjid($masjid->id, MobileCache::ANNOUNCEMENTS);

        $body = $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/announcements")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'mobile-assets/placeholder',
            $this->readable($body),
            'precondition: the placeholder path was not taken'
        );
        $this->assertOnConfiguredHost($body, 'the announcements response');
        $this->assertOnConfiguredHost(
            $this->cachedPayload($masjid->id, MobileCache::ANNOUNCEMENTS),
            'the announcements CACHE ENTRY'
        );
    }

    #[Test]
    public function the_services_payload_and_its_cache_entry_stay_on_the_configured_host(): void
    {
        $masjid = $this->makeMasjid();

        // Both the icon and the image envelope fall back here.
        Service::create([
            'masjid_id' => $masjid->id,
            'title' => 'Imageless service',
            'description' => 'description',
            'summary' => 'summary',
            'text' => 'text',
        ]);

        MobileCache::flushMasjid($masjid->id, MobileCache::SERVICES);

        $body = $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/services")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'mobile-assets/placeholder',
            $this->readable($body),
            'precondition: the placeholder path was not taken'
        );
        $this->assertOnConfiguredHost($body, 'the services response');
        $this->assertOnConfiguredHost(
            $this->cachedPayload($masjid->id, MobileCache::SERVICES),
            'the services CACHE ENTRY'
        );
    }

    #[Test]
    public function the_features_payload_and_its_cache_entry_stay_on_the_configured_host(): void
    {
        $masjid = $this->makeMasjid();

        $feature = MobileAppFeature::create(['name' => 'Qur\'an', 'key' => 'quran']);
        $masjid->features()->attach($feature->id, ['is_available' => 1]);

        MobileCache::flushMasjid($masjid->id, MobileCache::FEATURES);

        $body = $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/features")
            ->assertOk()
            ->getContent();

        $this->assertOnConfiguredHost($body, 'the features response');
        $this->assertOnConfiguredHost(
            $this->cachedPayload($masjid->id, MobileCache::FEATURES),
            'the features CACHE ENTRY'
        );
    }

    #[Test]
    public function the_icon_fallback_file_url_stays_on_the_configured_host(): void
    {
        // The OTHER branch of iconPlaceholderUrl: a per-feature SVG that exists
        // on the public disk, so the drawer shows the right glyph. It was the
        // second `url()` call in MobileMedia and is only reachable when the file
        // is present, which is why it gets its own case.
        Storage::disk('public')->put('icons/alqurann.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $masjid = $this->makeMasjid();
        $feature = MobileAppFeature::create(['name' => 'Qur\'an', 'key' => 'quran']);
        $masjid->features()->attach($feature->id, ['is_available' => 1]);

        MobileCache::flushMasjid($masjid->id, MobileCache::FEATURES);

        $body = $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/features")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'storage/icons/alqurann.svg',
            $this->readable($body),
            'precondition: the per-feature icon branch was not taken'
        );
        $this->assertOnConfiguredHost($body, 'the per-feature icon URL');
        $this->assertOnConfiguredHost(
            $this->cachedPayload($masjid->id, MobileCache::FEATURES),
            'the features CACHE ENTRY carrying a per-feature icon'
        );
    }

    #[Test]
    public function a_poisoning_attempt_leaves_a_cache_entry_the_next_caller_can_trust(): void
    {
        // The whole attack, in one case, in the order it actually happens.
        //
        // 1. The attacker warms a cold entry with a chosen Host.
        // 2. An ordinary phone asks with no Host of its own.
        // 3. It is served the attacker's entry — and that entry must be clean.
        //
        // This is the case that would still fail if somebody "fixed" the
        // response on the way out instead of fixing the builder.
        $masjid = $this->makeMasjid();

        Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => 'Imageless notice',
            'summary' => 'summary',
            'details' => 'details',
            'text' => 'text',
            'start_date' => '2026-08-28',
            'end_date' => '2026-09-28',
        ]);

        MobileCache::flushMasjid($masjid->id, MobileCache::ANNOUNCEMENTS);

        $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/announcements")->assertOk();

        // Deliberately NOT flushed: the second caller must read what the first
        // one left, which is the point of the endpoint being cached at all.
        $victim = $this->getJson(self::CONFIGURED_URL."/api/mobile/masjids/{$masjid->id}/announcements")
            ->assertOk()
            ->getContent();

        $this->assertOnConfiguredHost($victim, "the second caller's announcements");
    }

    #[Test]
    public function the_account_deletion_page_posts_to_the_configured_host(): void
    {
        // Not cached, and the highest-consequence instance: this is the page on
        // which a member types an email address and then a mailed code. A form
        // action on a chosen host, served over our certificate, is a phishing
        // page with a correct-looking script.
        $html = $this->get('https://'.self::FORGED_HOST.'/account-deletion')
            ->assertOk()
            ->getContent();

        $this->assertArrivedOnHost(self::FORGED_HOST);
        $this->assertStringContainsString('<form method="POST"', $html, 'precondition: the page rendered no form');
        $this->assertOnConfiguredHost($html, 'the account-deletion page');
    }

    #[Test]
    public function an_uploaded_lunch_flyer_url_is_stored_on_the_configured_host(): void
    {
        // THE DURABLE ONE, and the reason this audit went past the mobile media.
        //
        // Every other instance expires with a ten-minute cache entry. This URL is
        // WRITTEN TO A COLUMN — meal_menus.flyer_image_url — and served from it
        // for as long as the menu exists, on the PUBLIC ordering page
        // (Api\V1\JummahLunchOrdersController). It is built from whichever Host
        // the ADMIN's browser happened to send, and this deploy answers to three
        // hostnames, so an admin who opened the SPA on manara.hopetechapps.com
        // instead of masjid.hopetechapps.com pinned a customer-facing image to
        // the other one permanently.
        //
        // No attacker needed. That is what makes it worth a case of its own.
        $masjid = $this->makeMasjid();

        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]));

        // `create()` rather than `image()`: it reports the mime without writing
        // bytes and needs no GD, which is what the droplet runs (tests/CLAUDE.md).
        $url = $this->post(
            'https://'.self::FORGED_HOST."/api/admin/masjids/{$masjid->id}/jummah-lunch/flyer",
            ['flyer' => UploadedFile::fake()->create('flyer.jpg', 20, 'image/jpeg')]
        )->assertCreated()->json('data.url');

        $this->assertArrivedOnHost(self::FORGED_HOST);
        $this->assertIsString($url, 'the upload returned no URL');
        $this->assertOnConfiguredHost($url, 'the lunch flyer URL the upload returned');

        // ...and then the round trip into the COLUMN, which is what the public
        // ordering page actually reads. The returned URL being right is what
        // SiteUrl guarantees; the column being right is a separate fact, and the
        // two can come apart the moment a second write path is added. So the
        // menu is saved the way the SPA saves it — the upload's URL posted back
        // on the create — and the assertion is on the stored value.
        $menuId = $this->post(
            'https://'.self::FORGED_HOST."/api/admin/masjids/{$masjid->id}/jummah-lunch/menus",
            [
                'title' => 'Friday lunch',
                'service_date' => '2026-09-18',
                'flyer_image_url' => $url,
            ]
        )->assertSuccessful()->json('data.id');

        $stored = MealMenu::withoutGlobalScopes()->findOrFail($menuId)->flyer_image_url;

        $this->assertOnConfiguredHost(
            (string) $stored,
            'meal_menus.flyer_image_url as STORED — the value the public ordering page serves'
        );
    }

    #[Test]
    public function the_unsubscribe_links_minted_while_sending_are_on_the_configured_host(): void
    {
        // THE LONGEST-LIVED INSTANCE IN THE APPLICATION.
        //
        // A cache entry expires in ten minutes; a column can be rewritten; an
        // email is in somebody's inbox permanently. And `List-Unsubscribe` is
        // POSTED AUTOMATICALLY by Gmail and Yahoo with no action by the
        // recipient, so a wrong host there is an automatic request to whoever
        // chose it — and, for the recipient, an unsubscribe that silently never
        // happens.
        //
        // The send path is SYNCHRONOUS for anything not scheduled into the
        // future: BroadcastsController -> BroadcastComposer::send() ->
        // dispatcher->dispatch() all run inside the admin's HTTP request. So
        // these URLs were minted from the admin's Host header. That is why this
        // case resolves the service INSIDE a request rather than calling it from
        // the test's own context, where there is no forged host to be wrong
        // about — the whole defect lives in the request binding.
        // Under `/api/`, because routes/web.php ends in a catch-all `/{any}`
        // constrained to `^(?!api).*$` that hands every other path to the SPA —
        // and it is registered BEFORE anything a test adds, so a route at the
        // web root is never reached and the assertion fails on HTML instead of
        // the defect. No middleware group: TrustedHosts is global (prepended),
        // which is the only middleware this case needs.
        Route::get('/api/__mint-unsubscribe-urls', function (EmailSuppressionService $service) {
            return response()->json($service->urls(1, 'someone@example.test', 7));
        });

        $urls = $this->getJson('https://'.self::FORGED_HOST.'/api/__mint-unsubscribe-urls')
            ->assertOk()
            ->json();

        $this->assertArrivedOnHost(self::FORGED_HOST);

        $this->assertOnConfiguredHost($urls['page'], 'the unsubscribe PAGE link put into an email');
        $this->assertOnConfiguredHost($urls['one_click'], 'the List-Unsubscribe one-click URL Gmail POSTs automatically');
    }

    #[Test]
    public function the_test_harness_really_does_forge_the_host(): void
    {
        // THE CONTROL. Every other case here asserts that a forged Host does NOT
        // appear in a payload — an assertion that also passes when the harness
        // never delivered the forged Host in the first place, which is the
        // default behaviour of Laravel's test client for a relative URI.
        //
        // So: one place where the forged Host is expected to come STRAIGHT BACK.
        // The SPA shell builds its font and icon links with `asset()`, which is
        // deliberately left following the request — those are same-origin
        // decoration consumed inside the response that generated them, and
        // pinning them would put every bundle on manara.hopetechapps.com
        // cross-origin against SecurityHeaders' `default-src 'self'`, which is
        // the failure this repo already recorded as "assets pinned to one host".
        //
        // If this assertion ever fails, the other cases have stopped testing
        // anything and must be fixed before they are believed.
        $html = $this->get('https://'.self::FORGED_HOST.'/some-spa-route')
            ->assertOk()
            ->getContent();

        $this->assertArrivedOnHost(self::FORGED_HOST);
        $this->assertStringContainsString(
            self::FORGED_HOST,
            $html,
            'the forged Host did not reach URL generation — every other case in this file is now vacuous'
        );
    }

    #[Test]
    public function the_about_payload_and_its_cache_entry_stay_on_the_configured_host(): void
    {
        // One of the five cached keys MobileMedia's docblock names as the flush
        // list, and one of the two the first version of this suite did not
        // drive. An About row with no media takes all three placeholders.
        $masjid = $this->makeMasjid();

        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'about',
            'mission' => 'mission',
            'vision' => 'vision',
        ]);

        MobileCache::flushMasjid($masjid->id, MobileCache::ABOUT);

        $body = $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/about")
            ->assertOk()
            ->getContent();

        // Three envelopes, each carrying original_url AND preview_url.
        $this->assertSame(
            6,
            substr_count($this->readable($body), self::CONFIGURED_URL.'/mobile-assets/placeholder.png'),
            'precondition: about_image, mission_icon and vision_icon did not all take the placeholder'
        );
        $this->assertOnConfiguredHost($body, 'the about response');
        $this->assertOnConfiguredHost(
            $this->cachedPayload($masjid->id, MobileCache::ABOUT),
            'the about CACHE ENTRY'
        );
    }

    #[Test]
    public function the_donation_link_payload_and_its_cache_entry_stay_on_the_configured_host(): void
    {
        // The fifth cached key. A link with no banner row takes the placeholder,
        // and the Donate screen force-unwraps it.
        $masjid = $this->makeMasjid();

        DonationLink::create([
            'masjid_id' => $masjid->id,
            'link' => 'https://give.example.org/masjid',
        ]);

        MobileCache::flushMasjid($masjid->id, MobileCache::DONATION_LINK);

        $body = $this->getAsForgedHost("/api/mobile/masjids/{$masjid->id}/donation-link")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'mobile-assets/placeholder',
            $this->readable($body),
            'precondition: the placeholder path was not taken'
        );
        $this->assertOnConfiguredHost($body, 'the donation-link response');
        $this->assertOnConfiguredHost(
            $this->cachedPayload($masjid->id, MobileCache::DONATION_LINK),
            'the donation-link CACHE ENTRY'
        );
    }

    #[Test]
    public function the_provisioning_callback_handed_to_the_runner_is_on_the_configured_host(): void
    {
        // Not cached, but it outlives the request by minutes and carries a
        // secret with it: the self-hosted runner POSTs this job's progress to
        // `callback_url` with the job's bearer token. It was route(), so the
        // super admin's Host decided where that token went.
        $masjid = $this->makeMasjid();

        config([
            'services.github.dispatch_token' => 'test-dispatch-token',
            'services.github.ios_repo' => 'hope-tech-apps/ios-test',
            'services.github.android_repo' => 'hope-tech-apps/android-test',
        ]);
        Http::fake(['api.github.com/*' => Http::response('', 204)]);

        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]));

        $this->postJson(
            'https://'.self::FORGED_HOST."/api/admin/masjids/{$masjid->id}/provision-apps",
            ['platforms' => ['android']]
        )->assertStatus(201);

        $this->assertArrivedOnHost(self::FORGED_HOST);

        $sent = Http::recorded();
        $this->assertCount(1, $sent, 'precondition: exactly one dispatch should have been sent');

        $callback = $sent[0][0]->data()['client_payload']['callback_url'] ?? null;

        $this->assertIsString($callback, 'the dispatch carried no callback_url');
        $this->assertSame(self::CONFIGURED_URL.'/api/provisioning/callback', $callback);
    }

    #[Test]
    public function the_stripe_onboarding_return_urls_are_on_the_configured_host(): void
    {
        // Stripe stores these in the Account Link and sends the admin's browser
        // to them later — to the PUBLIC landing, which needs no token. They were
        // route(), so the Host the admin's request arrived on decided them.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $masjid = $this->makeMasjid();
        $masjid->forceFill(['crm_enabled' => true])->save();

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        $captured = [];
        $service = Mockery::mock(StripeConnectService::class);
        $service->shouldReceive('createOnboardingLink')
            ->once()
            ->andReturnUsing(function (Masjid $org, string $refresh, string $return) use (&$captured): string {
                $captured = ['refresh' => $refresh, 'return' => $return];

                return 'https://connect.stripe.test/setup/abc';
            });
        $this->app->instance(StripeConnectService::class, $service);

        Sanctum::actingAs($admin->fresh());

        $this->postJson('https://'.self::FORGED_HOST."/api/admin/masjids/{$masjid->id}/connect/onboarding")
            ->assertOk()
            ->assertJsonPath('data.onboarding_url', 'https://connect.stripe.test/setup/abc');

        $this->assertArrivedOnHost(self::FORGED_HOST);

        $this->assertSame(self::CONFIGURED_URL."/connect/{$masjid->id}/refresh", $captured['refresh'] ?? null);
        $this->assertSame(self::CONFIGURED_URL."/connect/{$masjid->id}/return", $captured['return'] ?? null);
    }

    #[Test]
    public function site_url_upgrades_an_http_app_url_when_https_is_forced(): void
    {
        // url() honours AppServiceProvider's URL::forceScheme('https'); a raw
        // APP_URL does not. Moving a builder from url() to SiteUrl must not start
        // emitting http:// on a box that forces https but still has an http://
        // APP_URL.
        config(['app.url' => 'http://masjid.test', 'app.force_https' => true]);
        $this->assertSame('https://masjid.test/account-deletion', SiteUrl::to('account-deletion'));

        config(['app.force_https' => false]);
        $this->assertSame('http://masjid.test/account-deletion', SiteUrl::to('account-deletion'));

        // Never the other way: an https:// APP_URL stays https:// either way.
        config(['app.url' => 'https://masjid.test']);
        $this->assertSame('https://masjid.test/account-deletion', SiteUrl::to('account-deletion'));
    }

    #[Test]
    public function site_url_ignores_a_trailing_slash_in_the_configured_url(): void
    {
        // A double slash after the host is a different URL to a cache, an ETag
        // and a string comparison, and APP_URL is hand-edited on three boxes.
        config(['app.url' => 'https://masjid.test/']);

        $this->assertSame('https://masjid.test/mobile-assets/placeholder.png', SiteUrl::to('mobile-assets/placeholder.png'));
        $this->assertSame('https://masjid.test/mobile-assets/placeholder.png', SiteUrl::to('/mobile-assets/placeholder.png'));
        $this->assertSame('https://masjid.test', SiteUrl::to());
    }
}
