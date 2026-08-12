<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\DonationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for DonationSubscription — the cross-tenant test
 * .claude/rules/tenant-scoping.md makes mandatory for every BelongsToMasjid
 * model, which this one shipped without.
 *
 * The only place the model appeared in the suite before this file was
 * ZakatDesignationTest, which reads it through `withoutMasjidScope()` — the
 * documented bypass, correct there because a public checkout runs UNBOUND, but
 * it means no test had ever exercised the guard itself.
 *
 * What makes this model worth more than a scope assertion: a standing commitment
 * is LIVE MONEY at Stripe, and the one mutation the admin surface offers —
 * cancel — reaches out of the database and stops a real subscription on a
 * connected account. So the refusals below assert three things together, because
 * any one of them alone would let a cross-tenant cancel look like it was
 * refused:
 *
 *   1. the HTTP status,
 *   2. the neighbour's row still `active` with no `canceled_at`, and
 *   3. that NO outbound Stripe call was attempted.
 *
 * Stripe is mocked exactly the way DonationFlowTest and RegistrationCheckoutTest
 * mock it, and for the reason .claude/rules/stripe-payments.md gives: the ONLY
 * thing stubbed is the outbound seam (DonationService::cancelStripeSubscription).
 * Everything else — the tenant scope, the status transition, the timestamp —
 * runs for real.
 */
class DonationSubscriptionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $adminA;

    private Fund $fundA;
    private Fund $fundB;

    private DonationSubscription $subA;
    private DonationSubscription $subB;

    /** Every outbound Stripe cancel the code attempted: [subscriptionId, account]. */
    private array $stripeCancels = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        config(['services.stripe.currency' => 'usd']);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid('acct_A');
        $this->masjidB = $this->makeMasjid('acct_B');

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->fundA = $this->makeFund($this->masjidA);
        $this->fundB = $this->makeFund($this->masjidB);

        // Seeded UNBOUND — a commitment is created in the public checkout
        // context, so the explicit masjid_id is honored.
        $this->subA = $this->makeSubscription($this->masjidA, $this->fundA, 'sub_A_live');
        $this->subB = $this->makeSubscription($this->masjidB, $this->fundB, 'sub_B_live');
    }

    private function makeMasjid(string $stripeAccount): Masjid
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
            'crm_enabled' => true,
            'stripe_account_id' => $stripeAccount,
            'stripe_charges_enabled' => true,
        ]);
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

    private function makeSubscription(
        Masjid $masjid,
        Fund $fund,
        ?string $stripeId,
        string $status = 'active',
        ?int $contactId = null,
    ): DonationSubscription {
        return DonationSubscription::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'contact_id' => $contactId,
            'fund_id' => $fund->id,
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'interval' => 'month',
            'status' => $status,
            'stripe_subscription_id' => $stripeId,
            'stripe_customer_id' => 'cus_' . uniqid(),
            'idempotency_key' => 'sub_' . uniqid(),
        ]);
    }

    /**
     * Bind a partial DonationService whose ONLY mocked method is the outbound
     * Stripe cancel. Every other line — the tenant scope, the status write, the
     * canceled_at stamp — runs for real. Mirrors DonationFlowTest.
     */
    private function stubStripeCancel(?\Throwable $throws = null): void
    {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('cancelStripeSubscription')
            ->andReturnUsing(function (string $id, string $account) use ($throws) {
                $this->stripeCancels[] = [$id, $account];

                if ($throws) {
                    throw $throws;
                }
            });

        $this->app->instance(DonationService::class, $service);
    }

    private function recurringUrl(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/recurring-donations";
    }

    // =============================================================== model layer

    #[Test]
    public function subscription_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, DonationSubscription::count());
        $this->assertSame($this->subA->id, DonationSubscription::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_subscription(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(DonationSubscription::find($this->subB->id));
        $this->assertNull(DonationSubscription::where('uuid', $this->subB->uuid)->first());
        $this->assertNull(
            DonationSubscription::where('stripe_subscription_id', 'sub_B_live')->first()
        );
    }

    #[Test]
    public function subscription_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $subscription = DonationSubscription::create([
            // A caller trying to open a standing gift in masjid B.
            'masjid_id' => $this->masjidB->id,
            'fund_id' => $this->fundA->id,
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'interval' => 'month',
            'status' => 'pending',
            'idempotency_key' => 'sub_planted_' . uniqid(),
        ]);

        $this->assertSame($this->masjidA->id, $subscription->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_subscriptions(): void
    {
        // The public checkout and the Stripe webhook both run unbound and resolve
        // a commitment by its Stripe id across tenants — that is what this state
        // is for, and ZakatDesignationTest's documented bypass relies on it.
        $this->assertSame(2, DonationSubscription::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, DonationSubscription::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_charges_relation_never_reaches_another_tenants_donations(): void
    {
        // A commitment's charges are joined on the Stripe subscription id, a
        // STRING rather than a foreign key. If that string ever repeated across
        // organizations, this relation would be the join that leaked — so it has
        // to be the tenant scope, not the key, that keeps them apart.
        $this->bookCharge($this->masjidA, $this->fundA, 'sub_A_live', 5000);
        $this->bookCharge($this->masjidB, $this->fundB, 'sub_A_live', 9999);

        $this->tenant->set($this->masjidA->id);

        $charges = DonationSubscription::find($this->subA->id)->donations()->get();

        $this->assertSame([5000], $charges->pluck('charged_amount')->all());
    }

    private function bookCharge(Masjid $masjid, Fund $fund, string $stripeSubId, int $cents): Donation
    {
        return Donation::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'type' => 'recurring',
            'source' => 'stripe',
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => 'usd',
            'status' => 'succeeded',
            'stripe_subscription_id' => $stripeSubId,
            'idempotency_key' => 'inv_' . uniqid(),
        ]);
    }

    // =========================================================== HTTP: read side

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->recurringUrl($this->masjidA))->assertStatus(401);
    }

    #[Test]
    public function index_lists_only_the_admins_own_commitments(): void
    {
        $this->bookCharge($this->masjidA, $this->fundA, 'sub_A_live', 5000);
        $this->bookCharge($this->masjidB, $this->fundB, 'sub_B_live', 9999);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->recurringUrl($this->masjidA))->assertOk();

        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->subA->id, $rows[0]['id']);
        $this->assertSame(1, $rows[0]['donations_count']);
    }

    #[Test]
    public function show_cannot_read_another_masjids_commitment(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->recurringUrl($this->masjidA) . "/{$this->subB->id}")
            ->assertStatus(404);
    }

    #[Test]
    public function naming_another_masjid_in_the_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->recurringUrl($this->masjidB) . "/{$this->subB->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function a_commitment_has_no_update_or_delete_verb(): void
    {
        // Rows are opened by the public checkout and advanced by webhooks; the
        // only admin mutation is cancel. Nothing can edit or destroy one over
        // HTTP, in this masjid or any other.
        Sanctum::actingAs($this->adminA);

        $url = $this->recurringUrl($this->masjidA) . "/{$this->subA->id}";

        $this->putJson($url, ['intended_amount' => 1])->assertStatus(405);
        $this->deleteJson($url)->assertStatus(405);

        $this->assertDatabaseHas('donation_subscriptions', [
            'id' => $this->subA->id,
            'intended_amount' => 5000,
            'status' => 'active',
        ]);
    }

    // ========================================================== HTTP: the cancel

    #[Test]
    public function cancel_stops_the_admins_own_commitment_at_stripe_and_locally(): void
    {
        $this->stubStripeCancel();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->recurringUrl($this->masjidA) . "/{$this->subA->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        // Exactly one outbound call, for A's subscription, on A's account.
        $this->assertSame([['sub_A_live', 'acct_A']], $this->stripeCancels);

        $row = DonationSubscription::withoutMasjidScope()->find($this->subA->id);
        $this->assertSame('canceled', $row->status);
        $this->assertNotNull($row->canceled_at);
    }

    #[Test]
    public function cancel_cannot_stop_another_masjids_commitment(): void
    {
        $this->stubStripeCancel();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->recurringUrl($this->masjidA) . "/{$this->subB->id}/cancel")
            ->assertStatus(404);

        // Nothing was attempted at Stripe — a refused request that had already
        // reached the connected account would have stopped a donor's real gift
        // whatever the status code said afterwards.
        $this->assertSame([], $this->stripeCancels);

        $row = DonationSubscription::withoutMasjidScope()->find($this->subB->id);
        $this->assertSame('active', $row->status);
        $this->assertNull($row->canceled_at);
    }

    #[Test]
    public function cancel_in_another_masjids_route_is_a_403_and_touches_nothing(): void
    {
        $this->stubStripeCancel();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->recurringUrl($this->masjidB) . "/{$this->subB->id}/cancel")
            ->assertStatus(403);

        $this->assertSame([], $this->stripeCancels);
        $this->assertSame(
            'active',
            DonationSubscription::withoutMasjidScope()->find($this->subB->id)->status
        );
    }

    #[Test]
    public function cancelling_an_already_canceled_commitment_calls_stripe_once(): void
    {
        $this->stubStripeCancel();

        Sanctum::actingAs($this->adminA);

        $url = $this->recurringUrl($this->masjidA) . "/{$this->subA->id}/cancel";

        $this->postJson($url)->assertOk();
        $first = DonationSubscription::withoutMasjidScope()->find($this->subA->id)->canceled_at;

        // A double-clicked button must not re-enter the connected account.
        $this->postJson($url)->assertOk()->assertJsonPath('data.status', 'canceled');

        $this->assertCount(1, $this->stripeCancels);
        $this->assertEquals(
            $first,
            DonationSubscription::withoutMasjidScope()->find($this->subA->id)->canceled_at
        );
    }

    #[Test]
    public function a_stripe_failure_still_cancels_the_commitment_locally(): void
    {
        // Documented behavior, and the safer half of the trade: if Stripe is
        // unreachable the admin's intent is still recorded, and the
        // customer.subscription.deleted webhook reconciles later.
        $this->stubStripeCancel(new \RuntimeException('stripe unreachable'));

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->recurringUrl($this->masjidA) . "/{$this->subA->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertSame([['sub_A_live', 'acct_A']], $this->stripeCancels);
        $this->assertSame(
            'canceled',
            DonationSubscription::withoutMasjidScope()->find($this->subA->id)->status
        );
    }

    #[Test]
    public function cancelling_a_pending_commitment_with_no_stripe_id_calls_nothing(): void
    {
        $this->stubStripeCancel();

        $pending = $this->makeSubscription($this->masjidA, $this->fundA, null, 'pending');

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->recurringUrl($this->masjidA) . "/{$pending->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertSame([], $this->stripeCancels);
    }

    #[Test]
    public function a_super_admin_cannot_cancel_across_the_masjid_named_in_the_route(): void
    {
        // The operator role is the one principal that legitimately touches every
        // organization, so it is the one whose binding must be checked against
        // the route rather than assumed. Getting this wrong stops a stranger's
        // recurring gift at Stripe, which no status code can undo.
        $this->stubStripeCancel();

        $super = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($super);

        $this->postJson($this->recurringUrl($this->masjidA) . "/{$this->subB->id}/cancel")
            ->assertStatus(404);

        $this->assertSame([], $this->stripeCancels);
        $this->assertSame(
            'active',
            DonationSubscription::withoutMasjidScope()->find($this->subB->id)->status
        );

        // The same operator, on B's own route, may cancel it — the confinement
        // is to the ROUTE, not to a role.
        $this->postJson($this->recurringUrl($this->masjidB) . "/{$this->subB->id}/cancel")
            ->assertOk();

        $this->assertSame([['sub_B_live', 'acct_B']], $this->stripeCancels);
    }

    #[Test]
    public function a_donor_named_on_another_masjids_commitment_is_never_disclosed(): void
    {
        // `show` eager-loads the contact, so the leak this guards is PII, not an
        // id: the neighbour's donor name and email ride along with the row.
        $donorB = Contact::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'first_name' => 'Bilal',
            'last_name' => 'Haddad',
            'email' => 'bilal@example.test',
        ]);

        $sub = $this->makeSubscription($this->masjidB, $this->fundB, 'sub_B_two', 'active', $donorB->id);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->recurringUrl($this->masjidA) . "/{$sub->id}")
            ->assertStatus(404);

        $this->assertStringNotContainsString('bilal@example.test', $response->content());
        $this->assertStringNotContainsString('Haddad', $response->content());
    }
}
