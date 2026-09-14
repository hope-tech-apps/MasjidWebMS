<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Jobs\SendBroadcastJob;
use App\Jobs\SendPrayerSyncJob;
use App\Mail\DonationReceiptMail;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\FeePlan;
use App\Models\Fund;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\MobileAppUser;
use App\Models\Notification;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Prayer;
use App\Models\Section;
use App\Models\Service;
use App\Models\User;
use App\Services\Assistant\ToolRegistry;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Stripe\DonationService;
use App\Services\Stripe\StripeConnectService;
use App\Support\GivingSwitch;
use App\Support\PrayerPushes;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsDonationSubscriptions;
use Tests\TestCase;

/**
 * A switched-off module must stay switched off through every OTHER door that
 * writes the same data (DECISIONS.md 2026-09-16, and wave 2 of the switches).
 *
 * The `capability:<key>` route gates close a module's own admin screens. These
 * are the side doors that reach the same rows without passing those gates:
 *
 *  - the Broadcasts composer's announcement and push channels, at compose time
 *    AND at delivery (a send scheduled before the switch was flipped);
 *  - the Manara Assistant's announcement, event, flyer and iqama tools, offered
 *    and executed;
 *  - the admin header search;
 *  - public intake: the website and app contact forms, program sign-up (the
 *    endpoints and the offering page section), the website appointment form, and
 *    the app's donation checkout and the funds list that feeds it;
 *  - Manara's own prayer pushes: the backstop, the daily refresh, and the
 *    refresh an iqama save sends.
 *
 * Every refusal follows the ORGANISATION, with no SuperAdmin bypass, and
 * nothing public stops READING: a gate decides what is offered, never what is
 * readable.
 *
 * Two doors deliberately never close. Money that reaches the Stripe webhook has
 * already moved, so it is booked, receipted and emailed for a switched-off
 * organisation exactly as for any other, and only noted in the log
 * (App\Support\GivingSwitch). Stripe Connect, the forms-card Stop button, zakat
 * prices and fee plans do not follow Giving at all.
 *
 * The deploy-window half (a config that does not know the modules) lives in
 * ModulesFailOpenTest.
 */
class ModuleSideDoorsTest extends TestCase
{
    use RefreshDatabase, SeedsDonationSubscriptions;

    private const REFUSED_MESSAGE = 'This organisation is not taking messages here right now.';

    private const DONATIONS_REFUSED = 'This organisation is not taking donations in the app right now.';

    private const APPOINTMENTS_REFUSED = 'This organisation is not taking appointment requests here right now.';

    private const WEBHOOK_SECRET = 'whsec_side_doors_test';

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

        config([
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
        ]);

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    #[Test]
    public function the_assistant_stops_offering_and_running_the_iqama_tools_when_prayer_times_are_off(): void
    {
        // An iqama row exists, so a schedule that ran would have somewhere to write.
        IqamaTimeSetting::create([
            'masjid_id' => $this->masjid->id,
            'fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10,
        ]);

        $registry = app(ToolRegistry::class);
        $iqamaTools = ['list_iqama_times', 'set_iqama_schedule'];

        $offered = array_keys($registry->availableFor($this->admin, $this->masjid));
        foreach ($iqamaTools as $name) {
            $this->assertContains($name, $offered);
        }

        $this->switchOff($this->masjid, 'prayer_times');
        $masjid = $this->masjid->fresh();

        // Neither the organisation's admin nor a SuperAdmin is offered them.
        foreach ([$this->admin, $this->superAdmin()] as $user) {
            $offered = array_keys($registry->availableFor($user, $masjid));

            foreach ($iqamaTools as $name) {
                $this->assertNotContains($name, $offered, "{$name} is offered to a {$user->type} with Prayer times off");
            }

            $today = Carbon::now()->toDateString();
            $result = $registry->execute('set_iqama_schedule', [
                'entries' => [
                    ['salah' => 'fajr', 'start_date' => $today, 'end_date' => $today, 'time' => '05:30'],
                ],
            ], $user, $masjid);

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('switched off', $result['error']);
        }

        $this->assertDatabaseCount('iqama_time_ranges', 0);
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

    #[Test]
    public function header_search_drops_services_for_the_organisations_admins_only(): void
    {
        Service::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Eid nikah service',
            'summary' => 'Eid nikah service',
            'description' => 'Eid nikah service',
            'text' => 'Eid nikah service',
        ]);

        $url = "/api/admin/masjids/{$this->masjid->id}/search?search_for=Eid";

        Sanctum::actingAs($this->admin);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.services');

        $this->switchOff($this->masjid, 'services');

        // The result would link to the Services screen, which is hidden and refuses.
        $this->getJson($url)->assertOk()->assertJsonPath('data.services', []);

        Sanctum::actingAs($this->superAdmin());
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.services');
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

    // ------------------------------------------------- appointment requests

    #[Test]
    public function the_appointment_request_form_is_refused_with_a_sentence_and_nothing_is_written(): void
    {
        $headers = ['masjid-id' => (string) $this->masjid->id];
        $this->switchOff($this->masjid, 'appointment_requests');

        $this->postJson('/api/v1/appointment-requests', $this->appointmentPayload(), $headers)
            ->assertStatus(403)
            ->assertExactJson(['status' => 'error', 'message' => self::APPOINTMENTS_REFUSED]);

        // A bot that fills the honeypot is told the same thing, never "received":
        // the refusal is asked before the honeypot is.
        $this->postJson('/api/v1/appointment-requests', $this->appointmentPayload([
            'website' => 'https://spam.example.invalid',
        ]), $headers)
            ->assertStatus(403)
            ->assertExactJson(['status' => 'error', 'message' => self::APPOINTMENTS_REFUSED]);

        $this->assertDatabaseCount('appointment_requests', 0);

        // And it takes requests again the moment the switch is back on.
        $this->switchOn($this->masjid, 'appointment_requests');

        $this->postJson('/api/v1/appointment-requests', $this->appointmentPayload(), $headers)
            ->assertOk();

        $this->assertDatabaseCount('appointment_requests', 1);
    }

    #[Test]
    public function one_organisations_switch_does_not_close_another_organisations_appointment_form(): void
    {
        $other = $this->makeMasjid();
        $this->switchOff($this->masjid, 'appointment_requests');

        $this->postJson('/api/v1/appointment-requests', $this->appointmentPayload(), [
            'masjid-id' => (string) $other->id,
        ])->assertOk();

        $this->assertDatabaseHas('appointment_requests', ['masjid_id' => $other->id]);
        $this->assertDatabaseMissing('appointment_requests', ['masjid_id' => $this->masjid->id]);
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

    // ------------------------------------------------------ giving (the app)

    #[Test]
    public function the_app_opens_no_gift_one_time_or_monthly_while_giving_is_off(): void
    {
        $fund = $this->makeFund($this->masjid);

        // Stripe is never reached: neither kind of checkout is even started.
        $donations = Mockery::mock(DonationService::class)->makePartial();
        $donations->shouldAllowMockingProtectedMethods();
        $donations->shouldNotReceive('createDonationCheckout');
        $donations->shouldNotReceive('createSubscriptionCheckout');
        $donations->shouldNotReceive('createCheckoutSession');
        $this->app->instance(DonationService::class, $donations);

        $this->switchOff($this->masjid, 'giving');

        $url = "/api/mobile/masjids/{$this->masjid->id}/donations/checkout";

        foreach ([false, true] as $recurring) {
            $this->postJson($url, ['fund_id' => $fund->id, 'amount' => 5000, 'recurring' => $recurring])
                ->assertStatus(403)
                ->assertExactJson(['status' => 'error', 'message' => self::DONATIONS_REFUSED]);
        }

        $this->assertDatabaseCount('donations', 0);
        $this->assertDatabaseCount('donation_subscriptions', 0);
    }

    #[Test]
    public function the_app_is_offered_no_funds_while_giving_is_off_and_every_fund_the_moment_it_is_back_on(): void
    {
        $this->makeFund($this->masjid);
        $url = "/api/mobile/masjids/{$this->masjid->id}/funds";

        // Warms the five-minute cache, which the switch must neither read nor clear.
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');

        $this->switchOff($this->masjid, 'giving');

        $this->getJson($url)
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => []]);

        $this->switchOn($this->masjid, 'giving');

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function one_organisations_giving_switch_does_not_close_another_organisations_app_giving(): void
    {
        $other = $this->makeMasjid();
        $otherFund = $this->makeFund($other);

        $donations = Mockery::mock(DonationService::class)->makePartial();
        $donations->shouldAllowMockingProtectedMethods();
        $donations->shouldReceive('createCheckoutSession')
            ->andReturn(['id' => 'cs_other_org', 'url' => 'https://checkout.stripe.test/cs_other_org', 'payment_intent' => 'pi_other_org']);
        $this->app->instance(DonationService::class, $donations);

        $this->switchOff($this->masjid, 'giving');

        $this->getJson("/api/mobile/masjids/{$other->id}/funds")->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/mobile/masjids/{$other->id}/donations/checkout", [
            'fund_id' => $otherFund->id,
            'amount' => 5000,
        ])->assertStatus(201);

        $this->assertDatabaseHas('donations', ['masjid_id' => $other->id, 'status' => 'pending']);
    }

    #[Test]
    public function stripe_connect_the_forms_card_stop_button_zakat_and_fee_plans_never_follow_giving(): void
    {
        // No test here may reach the live Stripe API; the status refresh is
        // answered from this double (the ConnectLinkedStatusTest arrangement).
        $connect = Mockery::mock(StripeConnectService::class)->makePartial();
        $connect->shouldAllowMockingProtectedMethods();
        $connect->shouldReceive('retrieveAccount')->andReturnUsing(
            fn (string $id) => ['id' => $id, 'charges_enabled' => true, 'payouts_enabled' => true]
        );
        $connect->shouldNotReceive('createAccount');
        $connect->shouldNotReceive('createAccountLink');
        $this->app->instance(StripeConnectService::class, $connect);

        // A child whose form card payments charge through this organisation.
        $child = $this->makeMasjid(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        $child->forceFill(['forms_card_via_masjid_id' => $this->masjid->id])->save();

        [$offering] = $this->makeOffering();

        $this->switchOff($this->masjid, 'giving');
        Sanctum::actingAs($this->admin);
        $id = $this->masjid->id;

        $this->getJson("/api/admin/masjids/{$id}/connect/status")
            ->assertOk()
            ->assertJsonPath('data.charges_enabled', true);
        $this->getJson("/api/admin/masjids/{$id}/zakat-settings")->assertOk();
        $this->getJson("/api/admin/masjids/{$id}/offerings/{$offering->id}/fee-plans")->assertOk();

        // The holder can still withdraw consent: the forms-card Stop button.
        $this->deleteJson("/api/admin/masjids/{$id}/connect/forms-card-for/{$child->id}")->assertOk();
        $this->assertNull($child->fresh()->forms_card_via_masjid_id);
    }

    // --------------------------------------------- giving (money arriving)

    #[Test]
    public function a_gift_arriving_while_giving_is_off_is_booked_receipted_emailed_and_noted_once(): void
    {
        Log::spy();
        $fund = $this->makeFund($this->masjid);
        $donation = Donation::factory()->create([
            'masjid_id' => $this->masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => 10000,
            'charged_amount' => 10000,
            'status' => 'pending',
        ]);

        // A checkout page opened before the flip, paid after it.
        $this->switchOff($this->masjid, 'giving');

        // A card gift raises both events for the same money.
        $paymentIntent = 'pi_' . uniqid();
        $this->postWebhook($this->checkoutCompletedEvent($donation, $paymentIntent))->assertOk();
        $this->postWebhook($this->paymentIntentSucceededEvent($donation, $paymentIntent))->assertOk();

        $this->assertSame('succeeded', $donation->fresh()->status);
        $this->assertDatabaseCount('donations', 1);

        $receipts = DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->get();
        $this->assertCount(1, $receipts);
        $this->assertSame(1, (int) $receipts->first()->serial_number);

        Mail::assertSent(DonationReceiptMail::class, 1);
        // A masjid keeps the religious wording (Letterhead::religiousOrg).
        Mail::assertSent(DonationReceiptMail::class, fn (DonationReceiptMail $mail) => $mail->religiousOrg === true);

        $this->assertArrivalNoted('gift', $donation->id, 1);
    }

    #[Test]
    public function a_school_gift_receipt_email_from_the_webhook_leaves_out_the_masjid_wording(): void
    {
        // A school a SuperAdmin switched Giving on for. The mail's flag defaults to
        // true, so a sender that dropped the argument would pass every other test.
        $school = $this->makeMasjid(['org_type' => 'school']);
        $school->forceFill(['capability_overrides' => ['giving' => true]])->save();
        $this->assertFalse($school->fresh()->moduleIsOff('giving'));

        $fund = $this->makeFund($school);
        $donation = Donation::factory()->create([
            'masjid_id' => $school->id,
            'fund_id' => $fund->id,
            'intended_amount' => 10000,
            'charged_amount' => 10000,
            'status' => 'pending',
        ]);

        $this->postWebhook($this->checkoutCompletedEvent($donation, 'pi_' . uniqid()))->assertOk();

        $this->assertSame('succeeded', $donation->fresh()->status);
        $this->assertCount(1, DonationReceipt::withoutMasjidScope()->where('donation_id', $donation->id)->get());

        Mail::assertSent(DonationReceiptMail::class, 1);
        Mail::assertSent(DonationReceiptMail::class, fn (DonationReceiptMail $mail) => $mail->religiousOrg === false);
        Mail::assertNotSent(DonationReceiptMail::class, fn (DonationReceiptMail $mail) => $mail->religiousOrg === true);
    }

    #[Test]
    public function a_monthly_charge_redelivered_while_giving_is_off_books_one_gift_and_is_noted_once(): void
    {
        Log::spy();
        $fund = $this->makeFund($this->masjid);
        $subscription = $this->seedDonationSubscription($this->masjid, $fund, [
            'status' => 'active',
            'stripe_subscription_id' => 'sub_side_doors',
        ]);

        $this->switchOff($this->masjid, 'giving');

        $invoice = $this->invoicePaidEvent($subscription, 'in_side_doors');
        $this->postWebhook($invoice)->assertOk();

        // Stripe sends the same invoice again under a NEW event id: the event dedup
        // does not catch it, the invoice dedup does, and it returns the same row.
        $this->postWebhook(array_merge($invoice, ['id' => 'evt_' . uniqid()]))->assertOk();

        $this->assertDatabaseCount('donations', 1);
        $booked = Donation::withoutMasjidScope()->where('stripe_invoice_id', 'in_side_doors')->sole();
        $this->assertSame('succeeded', $booked->status);

        $this->assertArrivalNoted('gift', $booked->id, 1);
    }

    #[Test]
    public function a_monthly_gift_started_while_giving_is_off_is_linked_and_noted_once(): void
    {
        Log::spy();
        $fund = $this->makeFund($this->masjid);
        $subscription = $this->seedDonationSubscription($this->masjid, $fund, [
            'status' => 'pending',
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'stripe_checkout_session_id' => 'cs_sub_side_doors',
        ]);

        $this->switchOff($this->masjid, 'giving');

        $event = [
            'id' => 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_sub_side_doors',
                    'object' => 'checkout.session',
                    'mode' => 'subscription',
                    'status' => 'complete',
                    'payment_status' => 'paid',
                    'subscription' => 'sub_started_while_off',
                    'customer' => 'cus_started_while_off',
                    'client_reference_id' => $subscription->uuid,
                    'metadata' => ['donation_subscription_uuid' => $subscription->uuid],
                    'customer_details' => ['email' => 'monthly-donor@example.invalid', 'name' => 'Yusuf Ali'],
                ],
            ],
        ];

        $this->postWebhook($event)->assertOk();
        // A retry under a new event id.
        $this->postWebhook(array_merge($event, ['id' => 'evt_' . uniqid()]))->assertOk();

        $this->assertSame('sub_started_while_off', $subscription->fresh()->stripe_subscription_id);
        $this->assertDatabaseCount('donations', 0);

        $this->assertArrivalNoted('monthly_gift_started', $subscription->id, 1);
    }

    #[Test]
    public function money_arriving_while_giving_is_on_notes_nothing(): void
    {
        Log::spy();
        $fund = $this->makeFund($this->masjid);
        $donation = Donation::factory()->create([
            'masjid_id' => $this->masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => 10000,
            'charged_amount' => 10000,
            'status' => 'pending',
        ]);

        $paymentIntent = 'pi_' . uniqid();
        $this->postWebhook($this->checkoutCompletedEvent($donation, $paymentIntent))->assertOk();
        $this->postWebhook($this->paymentIntentSucceededEvent($donation, $paymentIntent))->assertOk();

        $this->assertSame('succeeded', $donation->fresh()->status);
        Log::shouldNotHaveReceived('warning', fn ($message) => $message === GivingSwitch::ARRIVAL_MESSAGE);
    }

    // ---------------------------------------------------------- prayer pushes

    #[Test]
    public function manara_sends_no_prayer_pushes_for_an_organisation_whose_prayer_times_are_off(): void
    {
        Queue::fake();

        $now = Carbon::parse('2026-09-13 10:30:00', 'UTC');
        Carbon::setTestNow($now);

        $off = $this->masjid;
        $on = $this->makeMasjid();

        // Identical in every way the backstop reads: offsets, today's adhan, and
        // one DARK subscribed device (active devices are never targeted, so a
        // fresh one would make both sides pass on an empty send).
        foreach ([$off, $on] as $org) {
            IqamaTimeSetting::create([
                'masjid_id' => $org->id,
                'fajr' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0,
            ]);
            Prayer::create([
                'masjid_id' => $org->id,
                'date' => $now->format('Y-m-d'),
                'prayers_data' => ['fajr' => $now->toIso8601String()],
            ]);
            MobileAppUser::create([
                'masjid_id' => $org->id,
                'device_id' => "dark-handset-{$org->id}",
                'onesignal_subscription_id' => "dark-sub-{$org->id}",
                'user_agent' => 'test',
                'last_active_at' => $now->copy()->subDays(PrayerPushes::STALE_DAYS + 25),
            ]);
        }

        $this->switchOff($off, 'prayer_times');

        $this->artisan('prayers:send-due')->assertExitCode(0);

        $pushedTo = collect(Http::recorded())
            ->flatMap(fn ($pair) => $pair[0]->data()['include_subscription_ids'] ?? [])
            ->unique()
            ->values()
            ->all();

        $this->assertSame(["dark-sub-{$on->id}"], $pushedTo);

        $this->artisan('prayers:daily-resync')->assertExitCode(0);

        Queue::assertPushed(SendPrayerSyncJob::class, fn (SendPrayerSyncJob $job) => $job->masjidId === $on->id);
        Queue::assertNotPushed(SendPrayerSyncJob::class, fn (SendPrayerSyncJob $job) => $job->masjidId === $off->id);
    }

    #[Test]
    public function a_super_admin_saving_iqama_times_while_prayer_times_are_off_wakes_no_phones(): void
    {
        Queue::fake();
        $this->registerDevices($this->masjid, 2);

        $url = "/api/admin/masjids/{$this->masjid->id}/iqama";
        $payload = [
            'iqama_type' => 'minutes_after_adhan',
            'fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10,
        ];

        // Baseline, switched on: a save wakes the organisation's phones.
        Sanctum::actingAs($this->admin);
        $this->postJson($url, $payload)->assertOk();
        Queue::assertPushed(SendPrayerSyncJob::class, 1);

        $this->switchOff($this->masjid, 'prayer_times');

        // Only a SuperAdmin passes the gate now. The save lands; no phone wakes.
        Sanctum::actingAs($this->superAdmin());
        $this->postJson($url, array_merge($payload, ['fajr' => 25]))->assertOk();

        Queue::assertPushed(SendPrayerSyncJob::class, 1);
        $this->assertSame(25, (int) IqamaTimeSetting::where('masjid_id', $this->masjid->id)->value('fajr'));
    }

    // ----------------------------------------------------------------- reads

    #[Test]
    public function public_and_app_reads_never_follow_a_module(): void
    {
        $id = $this->masjid->id;
        $headers = ['masjid-id' => (string) $id];

        // Taken while every module is still on, to compare the TV board against.
        $board = $this->getJson("/api/mobile/masjids/{$id}/tv-config")->assertStatus(200);

        foreach (Masjid::MODULE_KEYS as $key) {
            $this->switchOff($this->masjid, $key);
        }

        // The public reads cache, and an answer cached before the switch would make
        // every assertion below trivially true.
        Cache::flush();

        foreach (['/api/v1/announcements', '/api/v1/gallery', '/api/v1/settings', '/api/v1/services'] as $path) {
            $this->getJson($path, $headers)
                ->assertStatus(200, "{$path} stopped serving because a module was switched off");
        }

        foreach ([
            'announcements', 'events', 'gallery', 'notifications', 'contact-reasons',
            'prayers/settings', 'donation-link', 'services',
            // Still 200 while Giving is off; what it lists is pinned above.
            'funds',
        ] as $read) {
            $this->getJson("/api/mobile/masjids/{$id}/{$read}")
                ->assertStatus(200, "the app's {$read} read stopped serving because a module was switched off");
        }

        // Nothing live is a 204, never a refusal.
        $this->assertContains(
            $this->getJson("/api/mobile/masjids/{$id}/splash")->getStatusCode(),
            [200, 204],
            'the splash read stopped serving because a module was switched off'
        );

        $after = $this->getJson("/api/mobile/masjids/{$id}/tv-config")->assertStatus(200);
        $this->assertSame($board->json('data.show_prayer_panel'), $after->json('data.show_prayer_panel'));
        $this->assertSame($board->json('data.donate_url'), $after->json('data.donate_url'));
    }

    // ============================= helpers =============================

    private function switchOff(Masjid $masjid, string $key): void
    {
        $overrides = $masjid->capability_overrides ?? [];
        $overrides[$key] = false;
        $masjid->forceFill(['capability_overrides' => $overrides])->save();
    }

    /** Back to the catalogue default (on, for a masjid, for every module). */
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

    private function makeFund(Masjid $masjid): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => 'General',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
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

    /** @return array<string, string> */
    private function appointmentPayload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'Amal Yusuf',
            'phone' => '+15550001111',
            'email' => 'amal@example.invalid',
            'date_of_birth' => '1980-04-12',
            'reason' => 'Persistent cough for two weeks',
            'preferred_window' => 'Weekday mornings',
        ], $overrides);
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

    /** The arrival warning for one object, counted. Needs Log::spy() first. */
    private function assertArrivalNoted(string $kind, int $id, int $times): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === GivingSwitch::ARRIVAL_MESSAGE
                && ($context['kind'] ?? null) === $kind
                && ($context['id'] ?? null) === $id
                && ($context['masjid_id'] ?? null) === $this->masjid->id)
            ->times($times);
    }

    /** Post a Stripe-signed event to the webhook, signing exactly like Stripe. */
    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [], [], [],
            [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );
    }

    /** @return array<string, mixed> a paid one-time checkout, with the donor's email */
    private function checkoutCompletedEvent(Donation $donation, string $paymentIntent): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_' . uniqid(),
                    'object' => 'checkout.session',
                    'mode' => 'payment',
                    'status' => 'complete',
                    'payment_status' => 'paid',
                    'payment_intent' => $paymentIntent,
                    'client_reference_id' => $donation->uuid,
                    'metadata' => ['donation_uuid' => $donation->uuid],
                    'customer_details' => ['email' => 'donor-' . uniqid() . '@example.invalid', 'name' => 'Amina Rahman'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> with the charge expanded, so nothing is fetched from Stripe */
    private function paymentIntentSucceededEvent(Donation $donation, string $paymentIntent): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'payment_intent.succeeded',
            'account' => $this->masjid->stripe_account_id,
            'data' => [
                'object' => [
                    'id' => $paymentIntent,
                    'object' => 'payment_intent',
                    'metadata' => ['donation_uuid' => $donation->uuid],
                    'latest_charge' => [
                        'id' => 'ch_' . uniqid(),
                        'object' => 'charge',
                        'balance_transaction' => [
                            'id' => 'txn_' . uniqid(),
                            'object' => 'balance_transaction',
                            'fee' => 320,
                            'net' => 9680,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> a paid recurring invoice with no charge id, so the fee formula is used */
    private function invoicePaidEvent(\App\Models\DonationSubscription $subscription, string $invoiceId): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'invoice.payment_succeeded',
            'account' => $this->masjid->stripe_account_id,
            'data' => [
                'object' => [
                    'id' => $invoiceId,
                    'object' => 'invoice',
                    'subscription' => $subscription->stripe_subscription_id,
                    'customer' => $subscription->stripe_customer_id,
                    'amount_paid' => $subscription->charged_amount,
                    'subscription_details' => [
                        'metadata' => ['donation_subscription_uuid' => $subscription->uuid],
                    ],
                ],
            ],
        ];
    }
}
