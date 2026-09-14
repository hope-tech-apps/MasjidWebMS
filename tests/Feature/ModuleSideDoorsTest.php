<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Jobs\SendBroadcastJob;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\MobileAppUser;
use App\Models\Notification;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Services\Assistant\ToolRegistry;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A switched-off module must stay switched off through every OTHER door that
 * writes the same data (DECISIONS.md 2026-09-16).
 *
 * The `capability:<key>` route gates close a module's own admin screens. These
 * are the side doors that reach the same rows without passing those gates:
 *
 *  - the Broadcasts composer's announcement and push channels, at compose time
 *    AND at delivery (a send scheduled before the switch was flipped);
 *  - the Manara Assistant's announcement, event and flyer tools, offered and
 *    executed;
 *  - the admin header search;
 *  - public intake: the website and app contact forms, and program sign-up
 *    (the endpoints and the offering page section).
 *
 * Every refusal follows the ORGANISATION, with no SuperAdmin bypass, and
 * nothing public stops READING: a gate decides what is offered, never what is
 * readable. The deploy-window half (a config that does not know the modules)
 * lives in ModulesFailOpenTest.
 */
class ModuleSideDoorsTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSED_MESSAGE = 'This organisation is not taking messages here right now.';

    private Masjid $masjid;

    private User $admin;

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

        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    // ------------------------------------------------------------ broadcasts

    #[Test]
    public function the_composer_refuses_the_announcement_channel_when_announcements_are_off(): void
    {
        $this->switchOff($this->masjid, 'announcements');
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload(['channels' => ['announcement', 'signage']]))
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                '"' . config('capabilities.announcements.label') . '" is switched off for this organisation, so the Announcements feed channel is unavailable.'
            );

        // All-or-nothing: the signage leg did not go out on its own either.
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
        $this->assertSame(0, BroadcastDelivery::withoutMasjidScope()->count());
        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function the_composer_refuses_the_push_channel_when_notifications_are_off(): void
    {
        $this->registerDevices($this->masjid, 2);
        $this->switchOff($this->masjid, 'push_notifications');
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload(['channels' => ['push'], 'image' => null]))
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                '"' . config('capabilities.push_notifications.label') . '" is switched off for this organisation, so the Push notification channel is unavailable.'
            );

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
        $this->assertSame(0, Notification::count());
    }

    #[Test]
    public function a_super_admin_is_refused_the_same_channel(): void
    {
        // No bypass: the composer agrees with the organisation's own menu.
        $this->switchOff($this->masjid, 'announcements');
        Sanctum::actingAs($this->superAdmin());

        $this->submit($this->payload(['channels' => ['announcement']]))->assertStatus(403);

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function channels_whose_module_is_on_still_send(): void
    {
        // Only the switched-off channel's module decides; signage belongs to
        // none, and push's module is still on.
        $this->switchOff($this->masjid, 'announcements');
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload(['channels' => ['signage', 'push'], 'image' => null]))
            ->assertStatus(202);

        $this->assertSame(1, Broadcast::withoutMasjidScope()->count());
        $this->assertSame(1, Notification::count());
    }

    #[Test]
    public function a_send_scheduled_before_the_switch_is_skipped_when_it_runs(): void
    {
        Queue::fake();
        $this->registerDevices($this->masjid, 2);
        Sanctum::actingAs($this->admin);

        $this->submit($this->payload([
            'channels' => ['announcement', 'push'],
            'scheduled_at' => Carbon::now()->addDay()->toIso8601String(),
        ]))->assertStatus(202);

        // The SuperAdmin switches both off while the send is waiting.
        $this->switchOff($this->masjid, 'announcements');
        $this->switchOff($this->masjid, 'push_notifications');

        $broadcast = Broadcast::withoutMasjidScope()->first();

        // Run the job the way the worker would: unbound tenant context.
        app(TenantContext::class)->forgetTenant();
        (new SendBroadcastJob($broadcast->id))->handle(app(BroadcastDispatcher::class));

        // Nothing written into either module…
        $this->assertSame(0, Announcement::where('masjid_id', $this->masjid->id)->count());
        $this->assertSame(0, Notification::where('masjid_id', $this->masjid->id)->count());

        // …and the admin can read why, on each channel.
        $deliveries = BroadcastDelivery::withoutMasjidScope()->get()->keyBy('channel');
        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $deliveries['announcement']->status);
        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $deliveries['push']->status);
        $this->assertStringContainsString('switched off', (string) $deliveries['announcement']->note);
        $this->assertStringContainsString('switched off', (string) $deliveries['push']->note);
    }

    // ------------------------------------------------------------- assistant

    #[Test]
    public function the_assistant_stops_offering_and_running_a_switched_off_modules_tools(): void
    {
        $registry = app(ToolRegistry::class);
        $moduleTools = [
            'list_announcements', 'create_announcement', 'update_announcement',
            'list_events', 'create_event', 'update_event',
            'list_flyer_templates', 'draft_flyer', 'list_flyers',
        ];

        $offered = array_keys($registry->availableFor($this->admin, $this->masjid));
        foreach ($moduleTools as $name) {
            $this->assertContains($name, $offered);
        }
        $this->assertTrue($registry->execute('list_announcements', [], $this->admin, $this->masjid)['ok']);

        $this->switchOff($this->masjid, 'announcements');
        $this->switchOff($this->masjid, 'events');
        $this->switchOff($this->masjid, 'flyer_studio');
        $masjid = $this->masjid->fresh();

        $offered = array_keys($registry->availableFor($this->admin, $masjid));
        foreach ($moduleTools as $name) {
            $this->assertNotContains($name, $offered, "{$name} is still offered with its module switched off");
        }
        // Tools that belong to no module are untouched.
        $this->assertContains('update_theme', $offered);
        $this->assertContains('request_feature', $offered);

        // No SuperAdmin bypass.
        $this->assertNotContains('create_announcement', array_keys($registry->availableFor($this->superAdmin(), $masjid)));

        // Re-checked at execution, for a switch flipped mid-conversation — and
        // it says why, rather than blaming a permission.
        $result = $registry->execute('create_event', [
            'title' => 'Family night', 'details' => 'Dinner', 'place' => 'Hall',
            'start' => Carbon::now()->addDay()->toDateTimeString(),
        ], $this->admin, $masjid);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('switched off', $result['error']);
        $this->assertSame(0, \App\Models\Event::count());
    }

    // ---------------------------------------------------------------- search

    #[Test]
    public function header_search_hides_switched_off_records_from_the_organisations_admins_only(): void
    {
        Announcement::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Eid prayer times',
            'summary' => 'Eid prayer times',
            'details' => 'Eid prayer times',
            'text' => 'Eid prayer times',
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => Carbon::now()->addWeek()->toDateString(),
        ]);
        MasjidAbout::create([
            'masjid_id' => $this->masjid->id,
            'about' => 'We have prayed Eid here since 1990.',
            'mission' => 'Serve',
            'vision' => 'Grow',
        ]);

        $url = "/api/admin/masjids/{$this->masjid->id}/search?search_for=Eid";

        Sanctum::actingAs($this->admin);
        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonCount(1, 'data.masjidAbout');

        $this->switchOff($this->masjid, 'announcements');
        $this->switchOff($this->masjid, 'about_us');

        // Keys kept, contents empty.
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.announcements', [])
            ->assertJsonPath('data.masjidAbout', []);

        // The owner still finds them.
        Sanctum::actingAs($this->superAdmin());
        $this->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonCount(1, 'data.masjidAbout');
    }

    // ----------------------------------------------------------- contact-us

    #[Test]
    public function the_website_contact_form_is_refused_with_a_sentence_and_nothing_is_written(): void
    {
        // Switched off exactly as the product does it: the SuperAdmin panel's
        // own endpoint, form-encoded.
        Sanctum::actingAs($this->superAdmin());
        $this->patch(
            "/api/admin/masjids/{$this->masjid->id}/capabilities/contact_requests",
            ['enabled' => '0'],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->postJson('/api/v1/contact-us', $this->contactPayload('web-device'), [
            'masjid-id' => (string) $this->masjid->id,
        ])
            ->assertStatus(403)
            ->assertExactJson(['status' => 'error', 'message' => self::REFUSED_MESSAGE]);

        $this->assertSame(0, ContactUsMessage::count());
        $this->assertSame(0, ContactUsAccount::count());
        $this->assertSame(0, MobileAppUser::count());
        Mail::assertNothingQueued();

        // And it takes messages again the moment the switch is back on.
        $this->patch(
            "/api/admin/masjids/{$this->masjid->id}/capabilities/contact_requests",
            ['enabled' => '1'],
            ['Accept' => 'application/json']
        )->assertOk();

        $this->postJson('/api/v1/contact-us', $this->contactPayload('web-device'), [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertOk();

        $this->assertSame(1, ContactUsMessage::count());
    }

    #[Test]
    public function the_app_contact_form_is_refused_with_the_same_sentence_not_a_500(): void
    {
        // The mobile controller's catch (\Exception) would turn a thrown
        // refusal into a 500; this pins that it RETURNS the 403 instead.
        MobileAppUser::create([
            'device_id' => 'app-device',
            'masjid_id' => $this->masjid->id,
            'user_agent' => 'test',
        ]);

        $this->switchOff($this->masjid, 'contact_requests');

        $this->postJson(
            "/api/mobile/masjids/{$this->masjid->id}/contact-us",
            $this->contactPayload('app-device')
        )
            ->assertStatus(403)
            ->assertExactJson(['status' => 'error', 'message' => self::REFUSED_MESSAGE]);

        $this->assertSame(0, ContactUsMessage::count());
        $this->assertSame(0, ContactUsAccount::count());
        Mail::assertNothingQueued();

        $this->switchOn($this->masjid, 'contact_requests');

        $this->postJson(
            "/api/mobile/masjids/{$this->masjid->id}/contact-us",
            $this->contactPayload('app-device')
        )->assertOk();

        $this->assertSame(1, ContactUsMessage::count());
    }

    #[Test]
    public function one_organisations_switch_does_not_close_another_organisations_contact_form(): void
    {
        $other = $this->makeMasjid();
        $this->switchOff($this->masjid, 'contact_requests');

        $this->postJson('/api/v1/contact-us', $this->contactPayload('other-device'), [
            'masjid-id' => (string) $other->id,
        ])->assertOk();

        $this->assertSame(1, ContactUsMessage::count());
    }

    // -------------------------------------------------------------- programs

    #[Test]
    public function public_program_sign_up_closes_with_the_same_404_as_a_missing_offering(): void
    {
        [, $plan] = $this->makeOffering(['slug' => 'weekend-school']);
        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->getJson('/api/v1/offerings/weekend-school', $headers)->assertStatus(200);

        // The baseline is taken while Programs is still on, so it is a genuinely
        // missing offering and not the module refusal.
        $missing = $this->getJson('/api/v1/offerings/no-such-thing', $headers)->assertStatus(404);

        $this->switchOff($this->masjid, 'programs');

        $gated = $this->getJson('/api/v1/offerings/weekend-school', $headers)->assertStatus(404);
        $this->assertSame($missing->getContent(), $gated->getContent());

        $this->postJson('/api/v1/offerings/weekend-school/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This offering is not available.');

        $this->postJson('/api/v1/offerings/weekend-school/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Karim', 'email' => 'aisha@test.local'],
            'data' => ['full_name' => 'Aisha Karim'],
        ], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This offering is not available.');

        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertDatabaseCount('contacts', 0);

        $this->switchOn($this->masjid, 'programs');
        $this->getJson('/api/v1/offerings/weekend-school', $headers)->assertStatus(200);
    }

    #[Test]
    public function the_offering_page_section_inlines_nothing_while_programs_are_off(): void
    {
        [$offering] = $this->makeOffering(['slug' => 'sectioned']);

        $page = Page::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Programs',
            'slug' => 'programs',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $this->masjid->id,
            'section_type' => 'offering',
            'title' => 'Registration',
            'content' => array_merge(SectionType::OFFERING->defaultContent(), [
                'offering_id' => $offering->id,
                'title' => 'Register now',
            ]),
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->switchOff($this->masjid, 'programs');

        // The page keeps serving; only the program leaves it.
        $content = $this->getJson('/api/v1/pages/programs', $headers)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        $this->assertArrayHasKey('offering', $content);
        $this->assertNull($content['offering']);
        $this->assertSame('Register now', $content['title']);

        $this->switchOn($this->masjid, 'programs');

        $content = $this->getJson('/api/v1/pages/programs', $headers)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        $this->assertSame('sectioned', $content['offering']['slug']);
    }

    // ----------------------------------------------------------------- reads

    #[Test]
    public function public_and_app_reads_never_follow_a_module(): void
    {
        foreach (Masjid::MODULE_KEYS as $key) {
            $this->switchOff($this->masjid, $key);
        }

        $headers = ['masjid-id' => (string) $this->masjid->id];

        foreach (['/api/v1/announcements', '/api/v1/gallery'] as $path) {
            $this->getJson($path, $headers)
                ->assertStatus(200, "{$path} stopped serving because a module was switched off");
        }

        foreach (['announcements', 'events', 'gallery', 'notifications', 'contact-reasons'] as $read) {
            $this->getJson("/api/mobile/masjids/{$this->masjid->id}/{$read}")
                ->assertStatus(200, "the app's {$read} read stopped serving because a module was switched off");
        }
    }

    // ============================= helpers =============================

    private function switchOff(Masjid $masjid, string $key): void
    {
        $overrides = $masjid->capability_overrides ?? [];
        $overrides[$key] = false;
        $masjid->forceFill(['capability_overrides' => $overrides])->save();
    }

    /** Back to the catalogue default (on, for every module and org type). */
    private function switchOn(Masjid $masjid, string $key): void
    {
        $overrides = $masjid->capability_overrides ?? [];
        unset($overrides[$key]);
        $masjid->forceFill(['capability_overrides' => $overrides])->save();
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Side Door Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            // Onboarded, so the program refusals below are the module's and
            // not the registration-state decider's.
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ])->fresh();
    }

    private function registerDevices(Masjid $masjid, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            MobileAppUser::create([
                'masjid_id' => $masjid->id,
                'device_id' => 'device-' . $masjid->id . '-' . $i,
                'onesignal_subscription_id' => 'sub-' . $masjid->id . '-' . $i,
                'user_agent' => 'test-device',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'starts_on' => Carbon::now()->toDateString(),
            'ends_on' => Carbon::now()->addDays(3)->toDateString(),
            'audience' => 'everyone',
            'channels' => ['announcement'],
            'image' => UploadedFile::fake()->image('notice.jpg', 400, 300),
        ], $overrides);
    }

    /** POST the composer as multipart (the image makes it a file upload). */
    private function submit(array $payload)
    {
        $image = $payload['image'] ?? null;
        unset($payload['image']);

        return $this->call(
            'POST',
            "/api/admin/masjids/{$this->masjid->id}/broadcasts",
            $payload,
            [],
            $image ? ['image' => $image] : [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /** @return array<string, string> */
    private function contactPayload(string $deviceId): array
    {
        return [
            'device_id' => $deviceId,
            'name' => 'Someone',
            'email' => 'someone@example.invalid',
            'phone' => '+15550000009',
            'reason_text' => 'General enquiry',
            'message' => 'Assalamu alaikum, is there class on Sunday?',
        ];
    }

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(array $state = []): array
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create($state);

        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }
}
