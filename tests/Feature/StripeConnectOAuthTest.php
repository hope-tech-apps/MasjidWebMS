<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\StripeConnectHistory;
use App\Models\StripeConnectOAuthState;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class StripeConnectOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['app.debug' => false, 'services.stripe.connect_oauth_enabled' => true, 'services.stripe.connect_client_id' => 'ca_test_example', 'services.stripe.secret' => 'test-placeholder']);
        Http::preventStrayRequests();
        Http::fake([
            'connect.stripe.com/oauth/token' => Http::response(['stripe_user_id' => 'acct_test_existing']),
            'connect.stripe.com/oauth/deauthorize' => Http::response(['stripe_user_id' => 'acct_test_existing']),
        ]);
        $connect = Mockery::mock(StripeConnectService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $connect->shouldReceive('retrieveAccount')->andReturnUsing(fn ($id) => ['id' => $id, 'charges_enabled' => true, 'payouts_enabled' => true]);
        $this->app->instance(StripeConnectService::class, $connect);
    }

    public function test_disabled_or_missing_client_hides_door_and_refuses_routes(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        foreach ([['services.stripe.connect_oauth_enabled' => false], ['services.stripe.connect_oauth_enabled' => true, 'services.stripe.connect_client_id' => '']] as $settings) {
            config($settings);
            $this->getJson($this->path($org, 'status'))->assertOk()->assertJsonMissingPath('data.oauth');
            $this->postJson($this->path($org, 'oauth'))->assertNotFound();
            $this->deleteJson($this->path($org, 'oauth'))->assertNotFound();
            $this->get('/connect/oauth/callback')->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_authorize_url_has_configured_host_scope_client_redirect_and_state(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        config(['app.url' => 'https://portal.example.invalid']);
        $url = $this->postJson($this->path($org, 'oauth'))->assertOk()->json('data.authorize_url');
        $this->assertSame('connect.stripe.com', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('ca_test_example', $query['client_id']);
        $this->assertSame('read_write', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('https://portal.example.invalid/connect/oauth/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertSame(1, StripeConnectOAuthState::count());
        Http::assertNothingSent();
    }

    public function test_code_links_refreshes_and_replay_is_refused_without_another_exchange(): void
    {
        $org = $this->makeMasjid();
        $user = $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $this->returnFromStripe($state, ['masjid_id' => 999])->assertOk()->assertSee('Stripe account linked')->assertDontSee('acct_test_existing');
        $this->assertSame('acct_test_existing', $org->fresh()->stripe_account_id);
        $this->assertTrue((bool) $org->fresh()->stripe_charges_enabled);
        $this->assertTrue((bool) $org->fresh()->stripe_payouts_enabled);
        $history = StripeConnectHistory::firstOrFail();
        $this->assertSame($org->id, $history->masjid_id);
        $this->assertSame($user->id, $history->actor_user_id);
        $this->assertSame('link', $history->action);
        $this->assertSame('ting', $history->account_last4);
        $this->returnFromStripe($state)->assertStatus(422);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://connect.stripe.com/oauth/token' && $r['grant_type'] === 'authorization_code' && $r['code'] === 'test-code' && $r->hasHeader('Authorization'));
    }

    public function test_forged_expired_and_wrong_session_user_states_are_refused(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $this->returnFromStripe($state.'forged')->assertStatus(422);
        $this->actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]), 'web');
        $this->returnFromStripe($state)->assertStatus(422);
        $this->browser();
        $this->travel(11)->minutes();
        $this->returnFromStripe($state)->assertStatus(422);
        $this->assertNull($org->fresh()->stripe_account_id);
        $this->assertSame(0, StripeConnectHistory::count());
        Http::assertNothingSent();
    }

    public function test_denial_consumes_state_and_leaves_org_untouched(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $this->get('/connect/oauth/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))
            ->assertStatus(422)->assertSee('Stripe access was not granted');
        $this->returnFromStripe($state)->assertStatus(422);
        $this->assertNull($org->fresh()->stripe_account_id);
        Http::assertNothingSent();
    }

    /** One platform holds one grant per account: deauthorizing on a refusal would cut the holder's working link. */
    public function test_an_account_already_held_by_another_org_is_refused_without_deauthorizing(): void
    {
        $holder = $this->makeMasjid(['stripe_account_id' => 'acct_test_existing', 'stripe_charges_enabled' => true]);
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $this->returnFromStripe($state)->assertStatus(422)->assertSee('already linked to another organisation');
        $this->assertNull($org->fresh()->stripe_account_id);
        $this->assertSame('acct_test_existing', $holder->fresh()->stripe_account_id);
        $this->assertTrue((bool) $holder->fresh()->stripe_charges_enabled);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/deauthorize'));
    }

    public function test_an_account_held_by_an_archived_org_is_refused_without_deauthorizing(): void
    {
        $other = $this->makeMasjid(['stripe_account_id' => 'acct_test_existing']);
        $other->delete();
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $this->returnFromStripe($state)->assertStatus(422)->assertSee('already linked to another organisation');
        $this->assertNull($org->fresh()->stripe_account_id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/deauthorize'));
    }

    public function test_org_cannot_overwrite_link_including_link_created_while_at_stripe(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $org->forceFill(['stripe_account_id' => 'acct_test_original'])->save();
        $this->postJson($this->path($org, 'oauth'))->assertStatus(422)->assertSee('Unlink');
        $this->browser();
        $this->returnFromStripe($state)->assertStatus(422)->assertSee('Unlink');
        $this->assertSame('acct_test_original', $org->fresh()->stripe_account_id);
        Http::assertSentCount(2);
    }

    public function test_explicit_unlink_deauthorizes_clears_flags_and_records_actor(): void
    {
        $org = $this->makeMasjid(['stripe_account_id' => 'acct_test_existing', 'stripe_charges_enabled' => true, 'stripe_payouts_enabled' => true]);
        $user = $this->staff($org);
        $this->deleteJson($this->path($org, 'oauth'))->assertOk();
        $this->assertNull($org->fresh()->stripe_account_id);
        $this->assertFalse((bool) $org->fresh()->stripe_charges_enabled);
        $this->assertFalse((bool) $org->fresh()->stripe_payouts_enabled);
        $this->assertDatabaseHas('stripe_connect_histories', ['action' => 'unlink', 'actor_user_id' => $user->id, 'account_last4' => 'ting']);
        $user->forceDelete();
        $this->assertNull(StripeConnectHistory::firstOrFail()->actor_user_id);
        Http::assertSentCount(1);
    }

    public function test_stripe_failure_never_clears_an_existing_link_and_does_not_expose_code(): void
    {
        $org = $this->makeMasjid(['stripe_account_id' => 'acct_test_existing']);
        $this->staff($org);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error_description' => 'sensitive-test-code'], 500)]);
        $this->deleteJson($this->path($org, 'oauth'))->assertStatus(422)->assertDontSee('sensitive-test-code');
        $this->assertSame('acct_test_existing', $org->fresh()->stripe_account_id);
    }

    public function test_tenant_cannot_read_history_or_state_or_start_unlink_for_another_org(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();
        $user = $this->staff($b);
        $this->state($b);
        StripeConnectHistory::create(['masjid_id' => $b->id, 'action' => 'link', 'account_last4' => 'test', 'actor_user_id' => $user->id]);
        $this->browser();
        $this->staff($a);
        $this->postJson($this->path($b, 'oauth'))->assertForbidden();
        $this->deleteJson($this->path($b, 'oauth'))->assertForbidden();
        app(TenantContext::class)->set($a->id);
        $this->assertSame(0, StripeConnectHistory::count());
        $this->assertSame(0, StripeConnectOAuthState::count());
    }

    public function test_super_admin_can_link_and_history_projection_shows_only_suffix(): void
    {
        $org = $this->makeMasjid();
        $user = User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Example Office', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        Sanctum::actingAs($user, ['*']);
        $state = $this->state($org);
        $this->browser();
        $this->returnFromStripe($state)->assertOk();
        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson($this->path($org, 'status'))->assertOk();
        $response->assertJsonPath('data.oauth.history.0.actor', 'Example Office')
            ->assertJsonPath('data.oauth.history.0.account_last4', 'ting');
        $this->assertStringNotContainsString('acct_', json_encode($response->json('data.oauth.history')));
    }

    public function test_revoked_staff_membership_cannot_complete_a_previously_started_link(): void
    {
        $org = $this->makeMasjid();
        $user = $this->staff($org);
        $state = $this->state($org);
        MasjidUser::where('user_id', $user->id)->delete();
        $this->browser();
        $this->returnFromStripe($state)->assertStatus(422)->assertSee('Your access');
        $this->assertNull($org->fresh()->stripe_account_id);
        Http::assertNothingSent();
    }

    public function test_token_failure_consumes_state_and_does_not_store_an_account(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error_description' => 'sensitive-test-code'], 400)]);
        $this->returnFromStripe($state)->assertStatus(422)->assertDontSee('sensitive-test-code');
        $this->returnFromStripe($state)->assertStatus(422);
        $this->assertNull($org->fresh()->stripe_account_id);
        Http::assertSentCount(1);
    }

    public function test_failed_flag_refresh_rolls_back_and_deauthorizes_the_grant(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $connect = Mockery::mock(StripeConnectService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $connect->shouldReceive('retrieveAccount')->once()->andThrow(new \RuntimeException('provider-private-detail'));
        $this->app->instance(StripeConnectService::class, $connect);
        $this->returnFromStripe($state)->assertStatus(422)->assertDontSee('provider-private-detail');
        $this->assertNull($org->fresh()->stripe_account_id);
        $this->assertDatabaseCount('stripe_connect_histories', 0);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/deauthorize'));
    }

    public function test_form_account_arrangement_blocks_oauth_and_non_admin_staff_have_no_door(): void
    {
        $holder = $this->makeMasjid();
        $org = $this->makeMasjid(['forms_card_via_masjid_id' => $holder->id]);
        $this->staff($org);
        $this->postJson($this->path($org, 'oauth'))->assertStatus(422)->assertSee('form card account link');
        $user = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        Sanctum::actingAs($user, ['*']);
        $this->postJson($this->path($org, 'oauth'))->assertUnauthorized();
        $this->deleteJson($this->path($org, 'oauth'))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_signed_deauthorization_webhook_clears_link_records_once_and_updates_never_restore_it(): void
    {
        $org = $this->makeMasjid(['stripe_account_id' => 'acct_test_existing', 'stripe_charges_enabled' => true, 'stripe_payouts_enabled' => true]);
        $event = ['id' => 'evt_test_oauth_deauthorized', 'type' => 'account.application.deauthorized',
            'created' => now()->timestamp, 'account' => 'acct_test_existing',
            'data' => ['object' => ['id' => 'ca_test_example', 'object' => 'application']]];
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $this->assertNull($org->fresh()->stripe_account_id);
        $this->assertFalse((bool) $org->fresh()->stripe_charges_enabled);
        $this->assertFalse((bool) $org->fresh()->stripe_payouts_enabled);
        $this->assertDatabaseHas('stripe_connect_histories', ['masjid_id' => $org->id, 'action' => 'deauthorized', 'actor_user_id' => null, 'account_last4' => 'ting']);
        $this->assertDatabaseCount('stripe_connect_histories', 1);
        $event['id'] = 'evt_test_oauth_updated';
        $event['type'] = 'account.updated';
        $event['created'] += 60;
        $event['data']['object'] = ['id' => 'acct_test_existing', 'charges_enabled' => true, 'payouts_enabled' => true];
        $this->webhook($event)->assertOk();
        $this->assertNull($org->fresh()->stripe_account_id);
        Http::assertNothingSent();
    }

    public function test_a_deauthorization_created_before_explicit_relink_does_not_clear_the_new_link(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $this->returnFromStripe($state)->assertOk();
        $this->webhook(['id' => 'evt_test_oauth_old_deauthorization', 'type' => 'account.application.deauthorized',
            'created' => now()->subMinute()->timestamp, 'account' => 'acct_test_existing',
            'data' => ['object' => ['id' => 'ca_test_example', 'object' => 'application']]])->assertOk();
        $this->assertSame('acct_test_existing', $org->fresh()->stripe_account_id);
        $this->assertTrue((bool) $org->fresh()->stripe_charges_enabled);
        $this->assertDatabaseCount('stripe_connect_histories', 1);
    }

    public function test_account_read_finishes_before_the_link_transaction_opens(): void
    {
        $org = $this->makeMasjid();
        $this->staff($org);
        $state = $this->state($org);
        $this->browser();
        $baseLevel = \Illuminate\Support\Facades\DB::transactionLevel();
        $connect = Mockery::mock(StripeConnectService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $connect->shouldReceive('retrieveAccount')->once()->andReturnUsing(function ($id) use ($baseLevel) {
            $this->assertSame($baseLevel, \Illuminate\Support\Facades\DB::transactionLevel(), 'No link transaction may hold tenant locks during the Stripe read.');
            return ['id' => $id, 'charges_enabled' => true, 'payouts_enabled' => true];
        });
        $this->app->instance(StripeConnectService::class, $connect);
        $this->returnFromStripe($state)->assertOk();
        $this->assertTrue((bool) $org->fresh()->stripe_charges_enabled);
    }

    private function webhook(array $event)
    {
        config(['services.stripe.connect_webhook_secret' => 'whsec_test_example']);
        $body = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_example');
        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    private function makeMasjid(array $attributes = []): Masjid
    {
        $org = Masjid::create(['name' => 'Example School '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => '1 Example St', 'latitude' => 0, 'longitude' => 0, 'crm_enabled' => true, 'org_type' => 'school']);
        $org->forceFill($attributes)->save();
        return $org->fresh();
    }

    private function staff(Masjid $org): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);
        Sanctum::actingAs($user, ['*']);
        return $user;
    }

    private function state(Masjid $org): string
    {
        $url = $this->postJson($this->path($org, 'oauth'))->assertOk()->json('data.authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        return $query['state'];
    }

    private function browser(): void
    {
        Auth::forgetGuards();
        $this->app->instance('auth.driver', null);
        app(TenantContext::class)->forgetTenant();
    }

    private function returnFromStripe(string $state, array $extra = [])
    {
        return $this->get('/connect/oauth/callback?'.http_build_query(array_merge(['state' => $state, 'code' => 'test-code'], $extra)));
    }

    private function path(Masjid $org, string $suffix): string
    {
        return "/api/admin/masjids/{$org->id}/connect/{$suffix}";
    }
}
