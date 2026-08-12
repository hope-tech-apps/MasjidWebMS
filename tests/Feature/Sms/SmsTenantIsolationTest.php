<?php

namespace Tests\Feature\Sms;

use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Models\SmsSuppression;
use App\Services\Sms\SmsConsentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-tenant guarantee for the two models T-009 adds.
 *
 * .claude/rules/tenant-scoping.md makes this mandatory rather than optional:
 * MySQL has no row-level security, so the BelongsToMasjid global scope plus a
 * test like this one IS the isolation guarantee.
 *
 * There is a second, SMS-specific claim in here. Consent and suppression are
 * per-organisation on purpose: STOP is a reply to ONE registered number, each
 * masjid sends from its own, and telling masjid B it may not text somebody
 * because they unsubscribed from masjid A would be both wrong and unenforceable.
 * So a suppression must be invisible AND inert across the tenant boundary — and
 * so must a sender identity, since borrowing another organisation's registered
 * number is the exact behaviour that gets a whole provider account suspended.
 */
class SmsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    private function makeMasjid(): Masjid
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
    public function the_global_scope_hides_another_tenants_suppressions(): void
    {
        SmsSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidA->id,
            'phone_e164' => '+16135550111',
            'suppressed_at' => Carbon::now(),
        ]);
        SmsSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidB->id,
            'phone_e164' => '+16135550222',
            'suppressed_at' => Carbon::now(),
        ]);

        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertSame(['+16135550111'], SmsSuppression::pluck('phone_e164')->all());
    }

    #[Test]
    public function an_opt_out_from_one_organisation_does_not_silence_another(): void
    {
        app(SmsConsentService::class)->suppress($this->masjidA->id, '+16135550111');

        $consent = app(SmsConsentService::class);

        $this->assertTrue($consent->isSuppressed($this->masjidA->id, '+16135550111'));
        // Consent is per organisation, and so is the STOP that withdraws it.
        $this->assertFalse($consent->isSuppressed($this->masjidB->id, '+16135550111'));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_over_client_input(): void
    {
        app(TenantContext::class)->set($this->masjidA->id);

        $suppression = SmsSuppression::create([
            // A caller trying to write into somebody else's tenant.
            'masjid_id' => $this->masjidB->id,
            'phone_e164' => '+16135550111',
            'suppressed_at' => Carbon::now(),
        ]);

        $this->assertSame($this->masjidA->id, $suppression->masjid_id);
    }

    #[Test]
    public function the_global_scope_hides_another_tenants_sender_identity(): void
    {
        MasjidSmsSender::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidB->id,
            'phone_number' => '+16135550100',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
            'approved_at' => Carbon::now(),
        ]);

        app(TenantContext::class)->set($this->masjidA->id);

        // Masjid A cannot see — and therefore cannot send from — B's registered
        // number. Borrowing one is what gets a fleet's account suspended.
        $this->assertNull(MasjidSmsSender::query()->first());
        $this->assertSame(1, MasjidSmsSender::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_documented_bypass_still_crosses_tenants_for_system_code(): void
    {
        // The inbound webhook runs unbound and must resolve any tenant's sender
        // from the number a message was sent to.
        MasjidSmsSender::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidB->id,
            'phone_number' => '+16135550100',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
        ]);

        app(TenantContext::class)->set($this->masjidA->id);

        $sender = MasjidSmsSender::withoutMasjidScope()
            ->where('phone_number', '+16135550100')
            ->first();

        $this->assertNotNull($sender);
        $this->assertSame($this->masjidB->id, $sender->masjid_id);
    }
}
