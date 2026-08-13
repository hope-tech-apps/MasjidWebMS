<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Registration;
use App\Models\Section;
use App\Models\User;
use App\Enums\SectionType;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `crm_enabled = false` BLINDED THE ORGANISATION WHILE THE PUBLIC KEPT SELLING
 * FOR IT.
 *
 * `masjids.crm_enabled` gates the CRM route group in routes/admin.php through
 * `App\Http\Middleware\EnsureCrmEnabled`, and the offering, fee-plan and
 * registration admin surfaces are inside that group. No `/api/v1` endpoint
 * consulted the flag. Measured on this branch before the fix, with
 * `crm_enabled = false`, acting as the masjid's OWN MasjidAdmin:
 *
 *     PUBLIC  GET  /api/v1/offerings/{slug}             200, registration_state "open"
 *     PUBLIC  POST /api/v1/offerings/{slug}/quote       200, amount_due_minor 15000
 *     PUBLIC  POST /api/v1/offerings/{slug}/register    200, a live checkout_url
 *                                                       -> 1 Contact + 1 Registration written
 *     ADMIN   GET  /api/admin/masjids/{id}/offerings                    403
 *     ADMIN   GET  .../offerings/{id}/registrations                     403
 *
 * Money taken and rows written for an organisation whose staff are locked out of
 * every screen that could show them either.
 *
 * ## The rule this file pins, and why it is this one
 *
 * REGISTRATION IS INSIDE THE CRM, SO THE PUBLIC SURFACE ASKS THE CRM'S QUESTION.
 * The gate stays on the admin routes and moves ONTO the public ones — it is not
 * lifted off the admin side.
 *
 * The alternative reading — "registration is independent of the CRM, so the
 * ADMIN gate is wrong" — is refused for a measurable reason, not a stylistic
 * one: `AdminDashboard\RegistrationsController@index` eager-loads `contact` and
 * `registrants.contact`, and `show` adds `formResponse`. The roster IS the
 * member directory — names, emails, phone numbers, intake answers. Ungating it
 * would open the very rows `EnsureCrmEnabled` exists to close, through a
 * different door. `the_roster_is_the_member_directory_which_is_why_the_admin_gate_stays`
 * below measures that rather than asserting it.
 *
 * And registration writes into the CRM at every step: `register()`
 * find-or-creates `Contact` rows, `confirm()` materialises `group_memberships`,
 * and the charge runs on the connected account whose onboarding endpoints sit
 * behind this same gate. `.claude/rules/auth-permissions.md`: "the whole CRM
 * (member directory + money path) is OFF by default".
 *
 * ## Why a 404 and not a 403
 *
 * `EnsureCrmEnabled` aborts 403 "The CRM is not enabled for this masjid." That
 * belongs to an authenticated admin. An anonymous caller gets the SAME 404 a
 * missing offering gets, because `crm_enabled` is an internal provisioning fact
 * about an organisation's account and publishing it — even as a distinct status
 * code — tells strangers something about its standing with the platform.
 */
class PublicRegistrationCrmGateTest extends TestCase
{
    use RefreshDatabase;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // /api/v1 never runs the tenant middleware.
        app(TenantContext::class)->forgetTenant();

        // CRM OFF — the column's own default, and the state the whole file is about.
        $this->masjid = $this->makeMasjid(['crm_enabled' => false]);
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    // ------------------------------------------- the measured reproduction

    #[Test]
    public function the_public_surface_stops_selling_for_an_organisation_whose_crm_is_off(): void
    {
        [, $plan] = $this->makeOffering(['slug' => 'blind-spot']);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->getJson('/api/v1/offerings/blind-spot', $headers)
            ->assertStatus(404, 'the public read still publishes an offering the organisation cannot see');

        $this->postJson('/api/v1/offerings/blind-spot/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(404, 'quote still prices for an organisation whose CRM is off');

        $this->postJson('/api/v1/offerings/blind-spot/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Karim', 'email' => 'aisha@test.local'],
            'data' => ['full_name' => 'Aisha Karim'],
        ], $headers)->assertStatus(404, 'register still takes sign-ups for an organisation whose CRM is off');

        // The point of the whole exercise: no money in flight, and nothing
        // written into a member directory nobody can open.
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    #[Test]
    public function turning_the_crm_on_is_what_makes_the_offering_sellable(): void
    {
        // The other half of the reproduction: the 404s above must be about the
        // FLAG and nothing else, or this file would pass for the wrong reason.
        [, $plan] = $this->makeOffering(['slug' => 'switchable']);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->getJson('/api/v1/offerings/switchable', $headers)->assertStatus(404);

        $this->masjid->forceFill(['crm_enabled' => true])->save();

        $this->getJson('/api/v1/offerings/switchable', $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.registration_state', 'open');

        $this->postJson('/api/v1/offerings/switchable/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    #[Test]
    public function the_refusal_is_the_same_404_a_missing_offering_gets(): void
    {
        $this->makeOffering(['slug' => 'real-program']);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $missing = $this->getJson('/api/v1/offerings/no-such-thing', $headers)->assertStatus(404);
        $gated = $this->getJson('/api/v1/offerings/real-program', $headers)->assertStatus(404);

        // Identical bodies. A caller cannot tell "no such offering" from "that
        // organisation has no CRM", so this endpoint is not a way to read an
        // organisation's account standing off the platform.
        $this->assertSame($missing->json('message'), $gated->json('message'));
        $this->assertSame($missing->getContent(), $gated->getContent());
    }

    #[Test]
    public function a_page_section_stops_rendering_the_offering_too(): void
    {
        // The half a standalone-endpoint fix would miss. An `offering` page
        // section inlines the IDENTICAL payload through
        // SectionContentBinder::bindOffering -> OfferingPublicPayload::forId, so
        // gating only GET /api/v1/offerings/{slug} would leave the organisation's
        // own website still showing the program, its prices and its Register
        // button while its staff got 403 from every admin screen.
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

        // The page itself is NOT CRM content and keeps serving — this fix must
        // not have widened into the rest of the public site.
        $content = $this->getJson('/api/v1/pages/programs', $headers)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        $this->assertArrayHasKey('offering', $content);
        $this->assertNull(
            $content['offering'],
            'a published page still renders the offering of an organisation whose CRM is off'
        );
        // The section's own presentation fields survive — the renderer draws
        // nothing rather than the page losing a block.
        $this->assertSame('Register now', $content['title']);

        // And it comes back when the CRM does.
        $this->masjid->forceFill(['crm_enabled' => true])->save();

        $content = $this->getJson('/api/v1/pages/programs', $headers)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        $this->assertSame('sectioned', $content['offering']['slug']);
    }

    // ------------------------------------- why the gate stays on the admin

    #[Test]
    public function the_roster_is_the_member_directory_which_is_why_the_admin_gate_stays(): void
    {
        // The reason the OTHER fix was refused, measured rather than asserted.
        // With the CRM on, the roster endpoint serves full Contact rows; taking
        // `crm` off the offerings routes so the org "can at least see what is
        // being sold" would publish exactly the member-directory rows the gate
        // exists to close, through a second door.
        $this->masjid->forceFill(['crm_enabled' => true])->save();

        [$offering, $plan] = $this->makeOffering(['slug' => 'roster-proof']);

        $registration = app(\App\Services\Registrations\RegistrationService::class)->register(
            $offering->fresh(),
            $plan,
            Contact::factory()->create([
                'masjid_id' => $this->masjid->id,
                'email' => 'parent@test.local',
                'phone' => '+15555550123',
            ]),
            ['full_name' => 'Roster Proof']
        );

        $this->assertInstanceOf(Registration::class, $registration);

        Sanctum::actingAs($this->admin);

        $row = $this->getJson(
            "/api/admin/masjids/{$this->masjid->id}/offerings/{$offering->id}/registrations"
        )->assertStatus(200)->json('data.data.0');

        // A contact record, in full, on the roster screen.
        $this->assertSame('parent@test.local', $row['contact']['email']);
        $this->assertSame('+15555550123', $row['contact']['phone']);

        // And with the CRM off, that door is shut — as is the offerings list.
        $this->masjid->forceFill(['crm_enabled' => false])->save();

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/offerings")->assertStatus(403);
        $this->getJson(
            "/api/admin/masjids/{$this->masjid->id}/offerings/{$offering->id}/registrations"
        )->assertStatus(403);
    }

    // -------------------------------------------------- scope of the gate

    #[Test]
    public function the_rest_of_the_public_site_is_untouched_by_the_crm_flag(): void
    {
        // A masjid without a CRM still has a website. The gate is the
        // REGISTRATION surface's question alone — widening it to
        // PublicTenant::exists() would have taken prayer times, announcements,
        // pages, the contact form and the zakat calculator down with it.
        Page::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'About',
            'slug' => 'about',
            'is_active' => true,
            'order' => 1,
        ]);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->assertFalse((bool) $this->masjid->fresh()->crm_enabled);

        foreach ([
            '/api/v1/announcements',
            '/api/v1/services',
            '/api/v1/pages',
            '/api/v1/pages/menu',
            '/api/v1/pages/about',
            '/api/v1/gallery',
        ] as $path) {
            $this->getJson($path, $headers)
                ->assertStatus(200, "{$path} stopped serving an organisation whose CRM is merely off");
        }
    }

    #[Test]
    public function a_headerless_request_is_still_a_400_and_not_a_404(): void
    {
        // The 400/404 split is a response contract the sibling public
        // controllers already hold (no organisation named vs. that organisation
        // is not available), and the CRM clause must not have collapsed it.
        $this->makeOffering(['slug' => 'contract']);

        $this->getJson('/api/v1/offerings/contract')->assertStatus(400);
        $this->getJson('/api/v1/offerings/contract', ['masjid-id' => '0'])->assertStatus(400);
    }

    // ============================= helpers =============================

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            // Onboarded, so nothing here is refused by clause 5 of the
            // registration-state decider instead of by the CRM gate.
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
