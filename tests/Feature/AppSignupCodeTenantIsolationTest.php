<?php

namespace Tests\Feature;

use App\Models\AppSignupCode;
use App\Models\Contact;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-TENANT isolation for `AppSignupCode`, required of every new
 * BelongsToMasjid model by .claude/rules/tenant-scoping.md.
 *
 * This model matters more than most. Its endpoints are UNAUTHENTICATED and take
 * their tenant from the URL, and the thing on the other side of a mistake is an
 * email: an unbound lookup would search every masjid in the database and could
 * mail a working sign-in code for one organisation to an address that belongs
 * to a person at another. MySQL has no row-level security, so the bound tenant
 * and these tests are the entire backstop.
 */
class AppSignupCodeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeMasjid();
        $this->b = $this->makeMasjid();
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
            'crm_enabled' => true,
        ]);
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        $this->tenant()->forgetTenant();
    }

    // ------------------------------------------- 1. the model layer (the rule)

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_app_signup_code(): void
    {
        // Written UNBOUND, as a console caller would, so masjid_id comes from
        // the payload and both rows genuinely exist.
        $codeA = AppSignupCode::create([
            'masjid_id' => $this->a->id,
            'email' => 'a@test.local',
            'code_hash' => str_repeat('a', 64),
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);
        $codeB = AppSignupCode::create([
            'masjid_id' => $this->b->id,
            'email' => 'b@test.local',
            'code_hash' => str_repeat('b', 64),
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame(2, AppSignupCode::withoutMasjidScope()->count());

        $this->tenant()->set($this->a->id);

        // READ: B's row is a MISS, not a filtered result.
        $this->assertSame(1, AppSignupCode::count());
        $this->assertNotNull(AppSignupCode::find($codeA->id));
        $this->assertNull(AppSignupCode::find($codeB->id));

        // WRITE: a scope covering SELECT but not update/delete would still be a
        // breach.
        $this->assertSame(0, AppSignupCode::where('id', $codeB->id)->update(['attempts' => 5]));
        $this->assertSame(0, AppSignupCode::where('id', $codeB->id)->delete());
        $this->assertSame(0, (int) $codeB->fresh()->attempts);
        $this->assertNotNull(AppSignupCode::withoutMasjidScope()->find($codeB->id));

        // CREATE stamps the BOUND tenant over client input, so a code cannot be
        // planted in another organisation.
        $planted = AppSignupCode::create([
            'masjid_id' => $this->b->id,
            'email' => 'planted@test.local',
            'code_hash' => str_repeat('c', 64),
            'channel' => AppSignupCode::CHANNEL_EMAIL,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertSame($this->a->id, (int) $planted->masjid_id);
    }

    // --------------------------------------------- 2. the unauthenticated door

    #[Test]
    public function a_code_minted_at_one_masjid_cannot_be_redeemed_at_another(): void
    {
        $shared = 'shared-' . uniqid() . '@test.local';

        Mail::fake();

        $this->postJson("/api/mobile/masjids/{$this->a->id}/auth/request-code", ['email' => $shared])
            ->assertStatus(202);

        $code = null;
        Mail::assertSent(\App\Mail\FamilyLoginCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->assertSame(1, AppSignupCode::withoutMasjidScope()->count());
        $this->assertSame($this->a->id, (int) AppSignupCode::withoutMasjidScope()->first()->masjid_id);

        // Presented at B's door. The code is real, unexpired and unconsumed — it
        // simply belongs to another organisation, and B's lookup never sees it.
        $this->asANewRequest();

        $this->postJson("/api/mobile/masjids/{$this->b->id}/auth/verify-code", [
            'email' => $shared,
            'code' => $code,
            'first_name' => 'Test',
            'last_name' => 'Member',
        ])->assertStatus(410);

        // And no contact was conjured in B by the attempt.
        $this->assertSame(0, Contact::withoutMasjidScope()->where('masjid_id', $this->b->id)->count());

        // Non-vacuity: it works at A's door, so the refusal above is the tenant
        // boundary and not a broken fixture.
        $this->asANewRequest();

        $this->postJson("/api/mobile/masjids/{$this->a->id}/auth/verify-code", [
            'email' => $shared,
            'code' => $code,
            'first_name' => 'Test',
            'last_name' => 'Member',
        ])->assertOk();

        $this->assertSame(1, Contact::withoutMasjidScope()->where('masjid_id', $this->a->id)->count());
    }

    #[Test]
    public function requesting_a_code_creates_no_contact(): void
    {
        // The property the whole design rests on: spraying this endpoint with a
        // dictionary of addresses must never write a person into the CRM.
        Mail::fake();

        foreach (['one@test.local', 'two@test.local', 'three@test.local'] as $email) {
            $this->asANewRequest();
            $this->postJson("/api/mobile/masjids/{$this->a->id}/auth/request-code", ['email' => $email])
                ->assertStatus(202);
        }

        $this->assertSame(3, AppSignupCode::withoutMasjidScope()->count());
        $this->assertSame(0, Contact::withoutMasjidScope()->count());
    }
}
