<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AN OFFBOARDED ORGANISATION IS OFFBOARDED EVERYWHERE.
 *
 * `masjids` SOFT-deletes. Every unauthenticated `/api/v1` endpoint takes its
 * tenant from the `masjid-id` HEADER and hand-filters `masjid_id`, because the
 * tenant middleware never runs there and the `BelongsToMasjid` global scope adds
 * no filter at all (.claude/rules/tenant-scoping.md). Hand-filtering answers
 * "does this row belong to masjid 7?" — and leaves "does masjid 7 still exist?"
 * unasked.
 *
 * The measured consequence on this branch, before the fix, is why this is its
 * own file rather than one more assertion inside an offering test:
 *
 *     $offering = ... under masjid A: free plan, intake form, open window
 *     $masjidA->delete();
 *
 *     GET  /api/v1/offerings/p13            masjid-id: A -> 200
 *     POST /api/v1/offerings/p13/quote      masjid-id: A -> 200
 *     POST /api/v1/offerings/p13/register   masjid-id: A -> 200 {"status":"confirmed"}
 *
 * — a real `registrations` row on a real offering, its answers stored as a
 * `form_response`, and the offering's `registration_count` incremented, all on
 * behalf of an organisation that had been offboarded.
 *
 * WHAT DID NOT HAPPEN, stated precisely because overclaiming a defect is its own
 * kind of wrong: with a PRICED plan the registration is created `pending` and
 * the Stripe leg then FAILS CLOSED one layer down —
 * `RegistrationCheckoutService` resolves the organisation with `Masjid::find()`,
 * which excludes soft-deleted rows, so it throws `orgCannotCollectPayments` and
 * no Checkout Session is opened. `the_money_layer_is_the_last_guard_not_the_first`
 * below pins that. It is real defence in depth, and it was the ONLY thing
 * standing between an offboarded organisation and a charge: every layer above it
 * behaved as though the organisation were live, and what a family got instead
 * was a phantom pending seat on a program nobody is running.
 *
 * The write endpoints predate this branch. The public READ is new, and it is
 * what made the path reachable by a family rather than only by a caller who
 * already knew the API — so the whole path is pinned here, not just the read.
 *
 * ## Why the last test is the important one
 *
 * `every_public_v1_route_refuses_a_deleted_organisation` walks `/api/v1` from
 * the ROUTER rather than from a list somebody maintains, and it decides what to
 * demand by MEASURING each route against a live organisation first. This exact
 * omission has now shipped three times in one month (SearchableTrait's falsy
 * header, the public masjid directory's column leak, this); an inventory that
 * has to be updated by hand is documentation, not a control.
 */
class PublicTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Public `/api/v1` routes that serve NOTHING about the organisation named in
     * the header, and must therefore keep answering.
     *
     * Exactly one, and its claim is falsifiable rather than asserted: the
     * meta-test below checks that each of these returns a BYTE-IDENTICAL body
     * for a live organisation and for a deleted one. If the endpoint ever starts
     * carrying tenant data, the exemption fails instead of hiding it.
     *
     * @var array<string,string>  route uri => why it carries no tenant data
     */
    private const TENANT_FREE = [
        'api/v1/contact-us/reasons' => 'ContactUsReason is a GLOBAL table with no masjid_id; the '
            . 'list is identical for every organisation and for none.',
    ];

    private Masjid $masjid;

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

        // /api/v1 never runs the tenant middleware — nothing here may be quietly
        // scoped by a bound context a real request would not have.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
    }

    // ------------------------------------------- the measured reproduction

    #[Test]
    public function a_soft_deleted_organisation_can_no_longer_be_read_quoted_or_registered_for(): void
    {
        [$offering, $plan] = $this->makeOffering(['slug' => 'p13']);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        // Everything works while the organisation is live — otherwise the
        // assertions below would pass for the wrong reason.
        $this->getJson('/api/v1/offerings/p13', $headers)->assertStatus(200);

        $this->postJson('/api/v1/offerings/p13/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(200);

        // Now offboard it.
        $this->masjid->delete();

        $this->getJson('/api/v1/offerings/p13', $headers)
            ->assertStatus(404, 'the public read still serves an offboarded organisation');

        $this->postJson('/api/v1/offerings/p13/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(404, 'quote still prices for an offboarded organisation');

        $this->postJson('/api/v1/offerings/p13/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Karim', 'email' => 'aisha@test.local'],
            'data' => ['full_name' => 'Aisha Karim'],
        ], $headers)->assertStatus(404, 'register still takes sign-ups for an offboarded organisation');

        // The point of the whole exercise: no row, no seat, nothing in flight.
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertSame(0, (int) Offering::withoutMasjidScope()->find($offering->id)->registration_count);
    }

    #[Test]
    public function the_refusal_is_the_same_404_a_missing_offering_gets(): void
    {
        $this->makeOffering(['slug' => 'live-program']);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        // A slug that does not exist, under a live organisation.
        $missing = $this->getJson('/api/v1/offerings/no-such-thing', $headers)->assertStatus(404);

        $this->masjid->delete();

        // An organisation that does not exist, asked for a slug that does.
        $offboarded = $this->getJson('/api/v1/offerings/live-program', $headers)->assertStatus(404);

        // Identical bodies: this endpoint cannot be used to work out which
        // tenant ids are live, the same reasoning that makes a foreign offering
        // and a missing one indistinguishable.
        $this->assertSame($missing->json('message'), $offboarded->json('message'));
    }

    #[Test]
    public function a_priced_registration_cannot_open_a_checkout_session_for_a_deleted_organisation(): void
    {
        // The money path, and the endpoint furthest from the offering: checkout
        // takes only a uuid, and `findByUuidForMasjid` is masjid-FILTERED, which
        // is (again) not the same as masjid-verified.
        [, $plan] = $this->makeOffering(['slug' => 'paid-program']);   // factory default: one_time, 15000

        $this->assertSame(FeePlan::KIND_ONE_TIME, $plan->kind);
        $this->assertGreaterThan(0, (int) $plan->amount_minor);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $uuid = $this->postJson('/api/v1/offerings/paid-program/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Ahmed', 'email' => 'bilal@test.local'],
            'data' => ['full_name' => 'Bilal Ahmed'],
        ], $headers)->assertStatus(200)->json('data.registration_uuid');

        $this->assertNotNull($uuid);

        $this->masjid->delete();

        $this->postJson("/api/v1/registrations/{$uuid}/checkout", [], $headers)
            ->assertStatus(404, 'a Checkout Session can still be minted against an offboarded organisation');
    }

    #[Test]
    public function the_money_layer_is_the_last_guard_not_the_first(): void
    {
        // The claim being pinned: even with every check above it removed, the
        // Stripe leg refuses an offboarded organisation, because
        // RegistrationCheckoutService resolves it with Masjid::find() and that
        // excludes soft-deleted rows. This is why the defect above wrote rows
        // rather than taking money — worth knowing exactly, and worth keeping.
        [$offering, $plan] = $this->makeOffering(['slug' => 'defence-in-depth']);

        // A live, chargeable organisation, so the refusal below can only be
        // about the DELETE and not about missing Connect onboarding.
        $this->masjid->forceFill([
            'stripe_account_id' => 'acct_test_' . uniqid(),
            'stripe_charges_enabled' => true,
        ])->save();

        $registration = app(\App\Services\Registrations\RegistrationService::class)->register(
            $offering->fresh(),
            $plan,
            \App\Models\Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Defence Depth']
        );

        $this->assertSame(\App\Models\Registration::STATUS_PENDING, $registration->status);

        $this->masjid->delete();

        $this->expectException(\App\Services\Registrations\RegistrationException::class);

        app(\App\Services\Stripe\RegistrationCheckoutService::class)->checkout($registration->fresh());
    }

    #[Test]
    public function a_deleted_organisations_form_stops_accepting_public_submissions(): void
    {
        // The sibling with the same shape: FormSubmissionsController filtered
        // `masjid_id` and never asked whether the masjid was still there, so an
        // offboarded organisation went on collecting submissions, notifying its
        // former coordinators and emailing receipts on its behalf.
        $form = $this->makeForm();

        $headers = ['masjid-id' => (string) $this->masjid->id];
        $body = ['data' => ['full_name' => 'Yusuf Ali']];

        $this->postJson("/api/v1/forms/{$form->id}/responses", $body, $headers)->assertStatus(200);
        $this->assertDatabaseCount('form_responses', 1);

        $this->masjid->delete();

        $this->postJson("/api/v1/forms/{$form->id}/responses", $body, $headers)->assertStatus(404);

        // Still exactly one — the second submission wrote nothing.
        $this->assertDatabaseCount('form_responses', 1);
    }

    #[Test]
    public function a_deleted_organisation_stops_publishing_its_public_content(): void
    {
        // The read-only half of the same omission, fixed in one place:
        // SearchableTrait::filterByMasjid is the single entry point for
        // Announcement, Service, Page and Section on /api/v1.
        Announcement::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Eid prayer',
            'summary' => 'In the main hall.',
            'details' => 'In the main hall.',
            'text' => 'In the main hall.',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        Service::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Nikah services',
            'summary' => 'By appointment.',
            'description' => 'By appointment.',
            'text' => 'By appointment.',
        ]);

        Page::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'About',
            'slug' => 'about',
            'is_active' => true,
            'order' => 1,
        ]);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->getJson('/api/v1/announcements', $headers)->assertStatus(200);
        $this->getJson('/api/v1/services', $headers)->assertStatus(200);
        $this->getJson('/api/v1/pages/about', $headers)->assertStatus(200);

        $this->masjid->delete();

        foreach ([
            '/api/v1/announcements',
            '/api/v1/services',
            '/api/v1/pages',
            '/api/v1/pages/menu',
            '/api/v1/pages/about',
        ] as $path) {
            $this->getJson($path, $headers)
                ->assertStatus(404, "{$path} still publishes an offboarded organisation's content");
        }
    }

    #[Test]
    public function being_unlisted_is_not_being_deleted(): void
    {
        // `listed_at` gates the mobile DIRECTORY and nothing else — unlisting is
        // a discoverability decision, not a revocation (Masjid::scopeListed): an
        // organisation pulled out of the picker must keep serving the people
        // already using it. This fix must not have widened into that.
        [, $plan] = $this->makeOffering(['slug' => 'still-running']);

        // Not mass-assignable on purpose (listing is one SuperAdmin endpoint), so
        // set it the way that endpoint does.
        $this->masjid->forceFill(['listed_at' => now()])->save();
        $this->assertTrue($this->masjid->fresh()->isListed());

        $this->masjid->forceFill(['listed_at' => null])->save();
        $this->assertFalse($this->masjid->fresh()->isListed());

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->getJson('/api/v1/offerings/still-running', $headers)->assertStatus(200);
        $this->postJson('/api/v1/offerings/still-running/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(200);
    }

    // ------------------------------------------------------- the meta-test

    /**
     * Walk `/api/v1` from the ROUTER, measure every route against a LIVE
     * organisation, then delete it and demand that every route which answered
     * now refuses.
     *
     * The live pass is what makes this a control rather than a coincidence: a
     * route is only required to flip if it was demonstrably reachable in the
     * first place, so a wrong request body cannot make an endpoint look guarded.
     * The only exemptions are TENANT_FREE above, and each of those must prove it
     * carries no tenant data by returning the same bytes either way.
     */
    #[Test]
    public function every_public_v1_route_refuses_a_deleted_organisation(): void
    {
        $this->makeOffering(['slug' => 'meta-program']);
        $plan = FeePlan::query()->where('masjid_id', $this->masjid->id)->firstOrFail();
        $form = $this->makeForm();

        Page::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Meta',
            'slug' => 'meta-program',
            'is_active' => true,
            'order' => 1,
        ]);

        Announcement::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Meta announcement',
            'summary' => 'Body.',
            'details' => 'Body.',
            'text' => 'Body.',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);

        $service = Service::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Meta service',
            'summary' => 'Body.',
            'description' => 'Body.',
            'text' => 'Body.',
        ]);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        // Route parameters filled with values that genuinely resolve for THIS
        // organisation, so a refusal after the delete is about the tenant and
        // not about a junk id.
        $params = [
            'slug' => 'meta-program',
            'id' => (string) $service->id,
            'form_id' => (string) $form->id,
            'uuid' => '00000000-0000-4000-8000-000000000000',
        ];

        $bodies = [
            'api/v1/offerings/{slug}/quote' => ['fee_plan_id' => $plan->id],
            'api/v1/offerings/{slug}/register' => [
                'fee_plan_id' => $plan->id,
                'payer' => ['name' => 'Meta Person', 'email' => 'meta@test.local'],
                'data' => ['full_name' => 'Meta Person'],
            ],
            'api/v1/forms/{form_id}/responses' => ['data' => ['full_name' => 'Meta Person']],
            'api/v1/contact-us' => [
                'device_id' => 'meta-device',
                'email' => 'meta@test.local',
                'name' => 'Meta Person',
                'reason_text' => 'General',
                'message' => 'Hello',
            ],
            'api/v1/appointment-requests' => [
                'applicant_name' => 'Meta Person',
                'phone' => '+15555550100',
                'date_of_birth' => '1990-01-01',
                'reason' => 'Checkup',
            ],
            'api/v1/zakat/calculate' => ['cash' => 100000],
        ];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn ($route) => array_intersect(['GET', 'POST'], $route->methods()) !== [])
            ->values();

        $this->assertGreaterThan(10, $routes->count(), 'the /api/v1 route table did not resolve');

        $call = function ($route) use ($params, $bodies, $headers) {
            $path = preg_replace_callback(
                '/\{(\w+)\??\}/',
                fn ($m) => $params[$m[1]] ?? '1',
                $route->uri()
            );

            return in_array('GET', $route->methods(), true)
                ? $this->getJson('/' . $path, $headers)
                : $this->postJson('/' . $path, $bodies[$route->uri()] ?? [], $headers);
        };

        // ---- pass 1: the organisation exists.
        $live = [];

        foreach ($routes as $route) {
            $response = $call($route);
            $live[$route->uri()] = [
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(),
            ];
        }

        $reachable = array_keys(array_filter(
            $live,
            fn ($r) => $r['status'] >= 200 && $r['status'] < 300
        ));

        $this->assertGreaterThanOrEqual(
            8,
            count($reachable),
            'Too few /api/v1 routes answered for a LIVE organisation, so the check below would '
            . 'pass vacuously. Fix the fixtures/bodies in this test, do not relax the floor.'
        );

        // ---- offboard it.
        $this->masjid->delete();

        $stillAnswering = [];
        $tenantFreeDrifted = [];

        foreach ($routes as $route) {
            $uri = $route->uri();

            if (! in_array($uri, $reachable, true)) {
                continue;
            }

            $response = $call($route);

            if (array_key_exists($uri, self::TENANT_FREE)) {
                // The exemption has to earn itself: identical bytes for a live
                // organisation and for none proves it carries no tenant data.
                if ($response->getContent() !== $live[$uri]['body']) {
                    $tenantFreeDrifted[] = $uri;
                }

                continue;
            }

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $stillAnswering[] = implode('|', $route->methods()) . ' ' . $uri;
            }
        }

        $this->assertSame(
            [],
            $tenantFreeDrifted,
            "These routes are exempted in TENANT_FREE as carrying nothing about the organisation, "
            . "and their responses now DIFFER between a live organisation and a deleted one — so "
            . "they do carry tenant data and the exemption is false:\n  "
            . implode("\n  ", $tenantFreeDrifted)
        );

        $this->assertSame(
            [],
            $stillAnswering,
            "These public endpoints answered for a SOFT-DELETED organisation. Each takes its tenant "
            . "from the `masjid-id` header and filters `masjid_id` without ever verifying the "
            . "organisation still exists — the shape that let an offboarded org take registrations "
            . "and open Stripe Checkout Sessions against its connected account. Fix with "
            . "App\\Support\\PublicTenant::exists(); adding an entry to TENANT_FREE is only correct "
            . "if the endpoint genuinely serves nothing about the tenant:\n  "
            . implode("\n  ", $stillAnswering)
        );
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
            // The offering endpoints below are the CRM's public face and now ask
            // `masjids.crm_enabled` as well as "does this organisation still
            // exist" (PublicTenant::crmEnabled). Without this every offering
            // assertion here would pass for the WRONG reason — a 404 about the
            // CRM gate rather than about the soft delete this file is measuring.
            'crm_enabled' => true,
            // Connect onboarding finished, for exactly the same reason and by
            // the same argument the money-layer test below already makes in
            // line: a paid fee plan is withheld from a public payload — and is
            // the same 404 from quote and register — when the organisation
            // cannot collect (OfferingRegistrationState::isPurchasable). Left
            // off, the live-organisation control assertions in this file would
            // 404 about Stripe onboarding and prove nothing about the delete.
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ], $overrides));
    }

    private function makeForm(): Form
    {
        return Form::create([
            'masjid_id' => $this->masjid->id,
            'name' => 'Camp sign-up',
            'slug' => 'camp-sign-up-' . uniqid(),
            'schema' => ['fields' => [
                ['key' => 'full_name', 'type' => 'text', 'label' => 'Name', 'required' => true],
            ]],
            'is_active' => true,
        ]);
    }

    /**
     * An offering pinned to this masjid, with the factory's default ONE-TIME
     * priced plan unless a state says otherwise.
     *
     * @return array{0: Offering, 1: FeePlan}
     */
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
