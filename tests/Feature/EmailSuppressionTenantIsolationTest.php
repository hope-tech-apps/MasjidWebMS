<?php

namespace Tests\Feature;

use App\Models\EmailSuppression;
use App\Models\Masjid;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-tenant guarantee for App\Models\EmailSuppression (T-042c).
 *
 * .claude/rules/tenant-scoping.md makes this mandatory rather than optional, and
 * tests/Feature/TenantScopingCoverageTest.php enforces that mechanically: MySQL
 * has no row-level security, so the BelongsToMasjid global scope plus a suite
 * like this one IS the isolation guarantee.
 *
 * There is a second, email-specific claim in here, and it is the same one
 * SmsTenantIsolationTest makes for the other channel. An unsubscribe is per
 * ORGANISATION: each masjid is its own sender identity, the link is minted for
 * one of them, and telling masjid B it may not email somebody because they left
 * masjid A's list would be both wrong and unaskable of the person. So a
 * suppression must be invisible AND inert across the tenant boundary.
 *
 * The WRITE verbs matter more here than the reads. A global scope that covers
 * SELECT but not UPDATE would let organisation A release organisation B's
 * opt-out — resuming mail to somebody who asked to be left alone, which is the
 * statutory exposure this whole feature exists to prevent.
 */
class EmailSuppressionTenantIsolationTest extends TestCase
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

        app(TenantContext::class)->forgetTenant();

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
        EmailSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidA->id,
            'email_normalized' => 'ours@test.local',
            'suppressed_at' => Carbon::now(),
        ]);
        EmailSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidB->id,
            'email_normalized' => 'theirs@test.local',
            'suppressed_at' => Carbon::now(),
        ]);

        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertSame(['ours@test.local'], EmailSuppression::pluck('email_normalized')->all());
    }

    /**
     * The write half, which is the dangerous one for this table: releasing or
     * deleting another organisation's row resumes email to somebody who
     * unsubscribed, and under CAN-SPAM each subsequent message is its own
     * exposure for that organisation rather than a support ticket.
     */
    #[Test]
    public function a_bound_tenant_can_neither_find_nor_clear_another_organisations_suppression(): void
    {
        // One human, unsubscribed from BOTH organisations. The suppression is
        // keyed (masjid_id, email_normalized), so these are two separate rows.
        $address = 'someone@test.local';

        $inA = EmailSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidA->id,
            'email_normalized' => $address,
            'reason' => EmailSuppression::REASON_UNSUBSCRIBE_LINK,
            'suppressed_at' => Carbon::now(),
        ]);
        $inB = EmailSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidB->id,
            'email_normalized' => $address,
            'reason' => EmailSuppression::REASON_UNSUBSCRIBE_LINK,
            'suppressed_at' => Carbon::now(),
        ]);

        app(TenantContext::class)->set($this->masjidA->id);

        // READ: B's row is a MISS, not a filtered result.
        $this->assertNull(EmailSuppression::find($inB->id));
        $this->assertSame(1, EmailSuppression::count());

        // WRITE: a scope covering SELECT but not UPDATE/DELETE would still let A
        // resume mail that B's subscriber asked to stop.
        $this->assertSame(
            0,
            EmailSuppression::where('id', $inB->id)->update(['released_at' => Carbon::now()]),
            'Masjid A must not be able to release masjid B\'s opt-out.'
        );
        $this->assertSame(
            0,
            EmailSuppression::where('id', $inB->id)->delete(),
            'Masjid A must not be able to delete masjid B\'s opt-out.'
        );

        $survivor = EmailSuppression::withoutMasjidScope()->find($inB->id);

        $this->assertNotNull($survivor, 'B\'s suppression must still exist.');
        $this->assertTrue($survivor->isActive(), 'B\'s suppression must still be in force.');

        // Non-vacuity: the identical statement DOES reach A's own row, so the
        // zeros above are the tenant boundary and not a malformed query.
        $this->assertSame(
            1,
            EmailSuppression::where('id', $inA->id)->update(['released_at' => Carbon::now()]),
            'The same update must work within the bound tenant, or the zeros above prove nothing.'
        );
    }

    #[Test]
    public function an_unsubscribe_from_one_organisation_does_not_silence_another(): void
    {
        $service = app(EmailSuppressionService::class);

        $service->suppress($this->masjidA->id, 'Member@Test.Local');

        $this->assertTrue($service->isSuppressed($this->masjidA->id, 'member@test.local'));
        // Consent is per organisation, and so is the unsubscribe that withdraws it.
        $this->assertFalse($service->isSuppressed($this->masjidB->id, 'member@test.local'));
        $this->assertEmpty($service->suppressedAmong($this->masjidB->id, ['member@test.local']));
    }

    #[Test]
    public function the_creating_hook_never_writes_an_opt_out_into_the_wrong_organisation(): void
    {
        // A tenant bound to A while the service is asked to suppress for B. The
        // explicit id must win: a suppression stamped into A would leave B's
        // subscriber still receiving mail AND silence a stranger in A.
        app(TenantContext::class)->set($this->masjidA->id);

        $suppression = app(EmailSuppressionService::class)
            ->suppress($this->masjidB->id, 'crossed@test.local');

        $this->assertNotNull($suppression);
        $this->assertSame($this->masjidB->id, $suppression->masjid_id);
        $this->assertSame(0, EmailSuppression::where('email_normalized', 'crossed@test.local')->count());
    }

    #[Test]
    public function the_documented_bypass_still_crosses_tenants_for_the_public_landing(): void
    {
        // The unsubscribe landing is a public web route with no tenant binding,
        // exactly like the Stripe webhook: it resolves the organisation from the
        // token in the link and must be able to reach that organisation's row
        // whatever else is bound.
        EmailSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidB->id,
            'email_normalized' => 'reader@test.local',
            'suppressed_at' => Carbon::now(),
        ]);

        app(TenantContext::class)->set($this->masjidA->id);

        $row = EmailSuppression::withoutMasjidScope()
            ->where('email_normalized', 'reader@test.local')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($this->masjidB->id, $row->masjid_id);
    }
}
