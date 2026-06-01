<?php

namespace Tests\Feature\Splash;

use App\Models\Masjid;
use App\Models\SplashAnnouncement;
use App\Services\OnesignalInAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for OnesignalInAppMessageService.
 *
 * Contract (.claude/rules/onesignal-iam.md):
 *   - sync() POSTs when there's no onesignal_iam_id, PUTs when one exists.
 *   - sync() returns the new id (caller persists it) or null on any failure mode.
 *   - sync() is fail-soft: 5xx / missing-config / thrown exception must not bubble.
 *   - remove() DELETEs only when the row has an id AND config is set, and never throws.
 *
 * Http::fake() is mandatory — no test in this suite hits the real OneSignal API.
 */
class OnesignalInAppMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        parent::setUp();

        // Default to a fully configured OneSignal so each test only has to
        // override what it specifically exercises (e.g. the "missing env" case).
        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);
    }

    protected function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    #[Test]
    public function sync_posts_to_create_endpoint_when_no_iam_id_yet(): void
    {
        Http::fake([
            'https://onesignal.com/api/v1/apps/*/in_app_messages' => Http::response(['id' => 'iam_new_001'], 200),
        ]);

        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $this->makeMasjid()->id]);

        $id = app(OnesignalInAppMessageService::class)->sync($splash);

        $this->assertSame('iam_new_001', $id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/apps/app-id-test/in_app_messages'));
    }

    #[Test]
    public function sync_puts_to_update_endpoint_when_iam_id_is_present(): void
    {
        Http::fake([
            'https://onesignal.com/api/v1/apps/*/in_app_messages/*' => Http::response(['id' => 'iam_existing_123'], 200),
        ]);

        $splash = SplashAnnouncement::factory()
            ->withIamId('iam_existing_123')
            ->create(['masjid_id' => $this->makeMasjid()->id]);

        app(OnesignalInAppMessageService::class)->sync($splash);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/in_app_messages/iam_existing_123'));
    }

    #[Test]
    public function sync_returns_id_from_response_body_for_caller_to_persist(): void
    {
        Http::fake([
            '*' => Http::response(['id' => 'iam_returned_by_onesignal'], 200),
        ]);

        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $this->makeMasjid()->id]);

        $this->assertSame(
            'iam_returned_by_onesignal',
            app(OnesignalInAppMessageService::class)->sync($splash)
        );
    }

    #[Test]
    public function sync_fails_soft_on_5xx_response(): void
    {
        // Fail-soft contract: a OneSignal 5xx must NOT throw and must NOT
        // prevent the local DB row from persisting. The caller treats null /
        // the existing id as "no change" and the admin still gets HTTP 200.
        Http::fake([
            '*' => Http::response(['error' => 'oops'], 500),
        ]);

        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $this->makeMasjid()->id]);

        $id = app(OnesignalInAppMessageService::class)->sync($splash);

        $this->assertNull($id); // no prior id + failed call -> null
        $this->assertDatabaseHas('splash_announcements', ['id' => $splash->id]);
    }

    #[Test]
    public function sync_fails_soft_when_credentials_missing(): void
    {
        config([
            'onesignal.api_url' => null,
            'onesignal.app_id' => null,
            'onesignal.app_rest_api_key' => null,
        ]);
        Http::fake(); // any unexpected call would be recorded; we assert none were made

        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $this->makeMasjid()->id]);

        $id = app(OnesignalInAppMessageService::class)->sync($splash);

        $this->assertNull($id);
        Http::assertNothingSent();
    }

    #[Test]
    public function sync_sends_masjid_id_tag_filter_in_payload(): void
    {
        // The whole point of IAMs in this codebase is per-masjid targeting via
        // the `masjid_id` tag filter. Regressing this would broadcast every
        // splash to every device in every masjid.
        Http::fake(['*' => Http::response(['id' => 'iam_x'], 200)]);

        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $masjid->id]);

        app(OnesignalInAppMessageService::class)->sync($splash);

        Http::assertSent(function (Request $r) use ($masjid) {
            $data = $r->data();
            return collect($data['filters'] ?? [])->contains(fn ($f) =>
                ($f['field'] ?? null) === 'tag'
                && ($f['key'] ?? null) === 'masjid_id'
                && ($f['value'] ?? null) === (string) $masjid->id
            );
        });
    }

    #[Test]
    public function sync_omits_cta_action_when_label_or_url_missing(): void
    {
        // Payload conventions: actions[] is only added if BOTH cta_label and
        // cta_url are non-empty. A half-configured CTA would render a button
        // with no destination on mobile.
        Http::fake(['*' => Http::response(['id' => 'iam_x'], 200)]);

        $splash = SplashAnnouncement::factory()->create([
            'masjid_id' => $this->makeMasjid()->id,
            'cta_label' => null,
            'cta_url' => null,
        ]);

        app(OnesignalInAppMessageService::class)->sync($splash);

        Http::assertSent(fn (Request $r) => !isset($r->data()['contents']['en']['actions']));
    }

    #[Test]
    public function remove_calls_delete_when_iam_id_present_and_configured(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $splash = SplashAnnouncement::factory()
            ->withIamId('iam_to_delete')
            ->create(['masjid_id' => $this->makeMasjid()->id]);

        app(OnesignalInAppMessageService::class)->remove($splash);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/in_app_messages/iam_to_delete'));
    }

    #[Test]
    public function remove_is_a_noop_when_iam_id_is_missing(): void
    {
        Http::fake();

        $splash = SplashAnnouncement::factory()->create([
            'masjid_id' => $this->makeMasjid()->id,
            'onesignal_iam_id' => null,
        ]);

        app(OnesignalInAppMessageService::class)->remove($splash);

        Http::assertNothingSent();
    }
}
