<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressions for the defects three adversarial reviews found in the work built
 * on 2026-08-12, each fixed the same day.
 *
 * These are grouped in one file ON PURPOSE: they have nothing in common as
 * features, and everything in common as a CLASS of mistake — a guard that looks
 * present and is bypassable by the shape of the input rather than its value. A
 * falsy "0", an array where a string was assumed, a route segment that
 * int-casts the same but hashes differently. Keeping them together is what makes
 * that pattern visible to whoever reads this next.
 */
class AdversarialFindingsRegressionTest extends TestCase
{
    use RefreshDatabase;

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
    }

    // ------------------------------------------------- the sign-in throttle bypass

    /**
     * The per-address sign-in limiter is salted with the masjid, and the masjid
     * came from the RAW route string while the tenant was bound with `(int)`.
     * So "1", "01", "001", "1abc" and "1.0" all resolved to masjid 1 and each
     * minted a FRESH bucket: measured at 12 codes issued to one parent against a
     * documented ceiling of 5.
     *
     * What a parent experiences when this regresses: a stranger who knows their
     * address sends them an unbounded stream of "your sign-in code" emails from
     * their child's school.
     *
     * This test covers only the NON-NUMERIC half, which whereNumber() stops at
     * the router. Zero-padded spellings are digits and still route — the test
     * below is the one that pins them, and it is the more important of the two.
     */
    #[Test]
    public function a_non_numeric_masjid_segment_cannot_reach_the_family_sign_in_endpoint(): void
    {
        $masjid = $this->makeMasjid();

        $this->postJson("/api/family/masjids/{$masjid->id}/auth/request-code", ['email' => 'x@test.local'])
            ->assertStatus(202);

        // whereNumber() stops these at the router.
        foreach (['1abc', '1.0', '1x', '1%20'] as $spelling) {
            $this->postJson("/api/family/masjids/{$spelling}/auth/request-code", ['email' => 'x@test.local'])
                ->assertStatus(404, "masjid segment '{$spelling}' reached the sign-in endpoint");
        }
    }

    /**
     * `whereNumber()` alone is NOT the fix, and it is worth being exact about
     * why: "01" and "001" ARE digits, so they route perfectly happily. What
     * stops them minting a fresh sign-in allowance is that the limiter key now
     * casts the segment with `(int)` — the same way ResolveFamilyGuestTenant
     * binds the tenant — so every spelling of the same organisation shares one
     * bucket.
     *
     * This is the assertion that would have caught the original defect: the
     * ceiling is enforced across spellings, not per spelling.
     */
    #[Test]
    public function zero_padded_masjid_spellings_share_one_sign_in_allowance(): void
    {
        $masjid = $this->makeMasjid();
        $limit = (int) config('family.login.requests_per_hour_per_address', 5);
        $email = 'parent-'.uniqid().'@test.local';

        // Spend the whole per-address allowance on the canonical spelling.
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson("/api/family/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
                ->assertStatus(202);
        }

        $this->postJson("/api/family/masjids/{$masjid->id}/auth/request-code", ['email' => $email])
            ->assertStatus(429, 'the per-address ceiling is not enforced at all');

        // Padded spellings bind the same tenant. Before the fix each of these
        // hashed to its own bucket and answered 202 — measured at 12 codes
        // against a ceiling of 5.
        foreach (['01', '001', '0001'] as $spelling) {
            $this->postJson("/api/family/masjids/{$spelling}/auth/request-code", ['email' => $email])
                ->assertStatus(429, "'{$spelling}' minted a fresh sign-in allowance for the same masjid");
        }
    }

    /**
     * `(string)` on an array raises "Array to string conversion", which Laravel
     * escalates to an ErrorException — a 500 raised INSIDE the rate limiter, so
     * before the counter increments and before validation exists to reject it.
     * An unauthenticated endpoint that returns a server error on demand, unmetered.
     */
    #[Test]
    public function a_non_scalar_email_is_rejected_rather_than_raising_a_server_error(): void
    {
        $masjid = $this->makeMasjid();

        foreach (['request-code', 'verify-code'] as $action) {
            $response = $this->postJson("/api/family/masjids/{$masjid->id}/auth/{$action}", [
                'email' => ['a@b.c'],
                'code' => '123456',
            ]);

            $this->assertNotSame(500, $response->getStatusCode(), "{$action} 500s on an array email");
            $this->assertContains($response->getStatusCode(), [202, 410, 422], "{$action} answered {$response->getStatusCode()}");
        }
    }

    // ------------------------------------------------- the destructive-sweep widener

    /**
     * `--masjid=0` is the string "0", which is FALSY in PHP, so
     * `when($masjidId, …)` skipped the narrowing entirely and the sweep ran
     * across EVERY tenant — on commands that force-delete minors' records.
     *
     * `--masjid=abc` was always safe (truthy, casts to 0, matches nothing),
     * which is exactly what made `0` the sharp edge: the one spelling that
     * silently widens instead of narrowing.
     */
    #[Test]
    public function a_zero_masjid_option_does_not_turn_a_scoped_purge_into_a_platform_wide_one(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();

        $rows = [];
        foreach ([$a, $b] as $masjid) {
            $rows[$masjid->id] = $this->makeExpiredGroupPost($masjid);
        }

        // Masjid 0 exists nowhere. The sweep must therefore delete NOTHING.
        $this->artisan('groups:purge-feed', ['--masjid' => '0'])->assertExitCode(0);

        foreach ($rows as $masjidId => $postId) {
            $this->assertDatabaseHas('group_posts', ['id' => $postId], connection: 'sqlite');
        }
    }

    // ------------------------------------------------- the unbounded aid grant

    /**
     * The charge was always safe (the service floors the adjusted total at
     * zero), but with no upper bound the LEDGER accepted
     * `amount_minor: 99999999999999999` — so the permanent audit trail for a
     * waiver on a $100 program read "aid: $999,999,999,999,999.99".
     */
    #[Test]
    public function an_absurd_aid_grant_is_refused_by_validation(): void
    {
        $rules = (new \App\Http\Requests\Admin\Registrations\GrantAdjustmentRequest())->rules();

        $this->assertContains('integer', $rules['amount_minor']);
        $this->assertContains('min:0', $rules['amount_minor']);

        $max = collect($rules['amount_minor'])->first(fn ($rule) => is_string($rule) && str_starts_with($rule, 'max:'));

        $this->assertNotNull($max, 'amount_minor has no upper bound — an absurd grant becomes the audit trail');
        $this->assertLessThanOrEqual(
            99999999,
            (int) str_replace('max:', '', $max),
            'the ceiling is above Stripe\'s per-charge maximum, so it bounds nothing real'
        );
    }

    // ------------------------------------------------- "open" that ignored the window

    /**
     * Every status surface resolved to `is_active` alone, while the public
     * register path enforces `isWithinWindow()`. An offering whose `closes_at`
     * had passed showed a green "Open for registration" badge and refused every
     * real registration.
     */
    #[Test]
    public function an_offering_past_its_window_does_not_report_itself_as_open(): void
    {
        $masjid = $this->makeMasjid();

        $offering = new \App\Models\Offering([
            'masjid_id' => $masjid->id,
            'name' => 'Autumn Semester',
            'slug' => 'autumn',
            'is_active' => true,
            'closes_at' => now()->subDay(),
        ]);

        $this->assertTrue((bool) $offering->is_active, 'precondition: the flag is still on');
        $this->assertFalse($offering->is_open, 'a closed window still reported itself open');
        $this->assertSame('closed', $offering->closed_reason);

        $offering->closes_at = null;
        $offering->opens_at = now()->addDay();
        $this->assertFalse($offering->is_open);
        $this->assertSame('not_yet_open', $offering->closed_reason);

        $offering->opens_at = null;
        $this->assertTrue($offering->is_open);
        $this->assertNull($offering->closed_reason);

        $offering->is_active = false;
        $this->assertFalse($offering->is_open);
        $this->assertSame('inactive', $offering->closed_reason);
    }

    // ------------------------------------------------------------------ helpers

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
            'crm_enabled' => true,
        ]);
    }

    /** A group post already past its retention window, for the purge sweep. */
    private function makeExpiredGroupPost(Masjid $masjid): int
    {
        $name = 'Class '.uniqid();

        $groupId = DB::table('groups')->insertGetId([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'kind' => 'class',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('group_posts')->insertGetId([
            'masjid_id' => $masjid->id,
            'group_id' => $groupId,
            'body' => 'retained too long',
            'retained_until' => now()->subDay(),
            'created_at' => now()->subMonths(6),
            'updated_at' => now()->subMonths(6),
        ]);
    }
}
