<?php

namespace Tests\Feature;

use App\Console\Commands\ReapExpiredCheckouts;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationPaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006f — the expired-checkout reaper (`registrations:reap-expired`).
 *
 * Reserve-at-pending hands out a REAL seat the instant a paid registration is
 * created. `checkout.session.expired` normally gives it back; this command is
 * the backstop for when that event never arrives. So the invariants pinned here
 * are seat-accounting invariants, not command ergonomics:
 *
 *  - EXACTLY ONE seat comes back per expired registration, and a second run
 *    changes nothing (the counter can never go negative or double-return).
 *  - The two release paths CONVERGE. Webhook-then-reaper and reaper-then-webhook
 *    both end at one cancelled registration and one decrement.
 *  - The grace margin is real: a hold whose window closed a minute ago is NOT
 *    swept, because the payment webhook may still be in flight.
 *  - A seat that has been PAID FOR is never reaped, in any state combination.
 *  - Cross-tenant: a narrowed sweep touches one organization only.
 *
 * Every fixture is built through the real T-006b service, so the counters under
 * test are the ones production writes. No Stripe API call happens here; the
 * webhook side is exercised through RegistrationPaymentService's handlers
 * directly (RegistrationWebhookTest pins the HTTP + signature layer).
 */
class RegistrationReaperTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Masjid $otherMasjid;

    private Group $group;

    private RegistrationService $service;

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

        // A console sweep runs UNBOUND, exactly as the real command does.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid(['stripe_account_id' => 'acct_A']);
        $this->otherMasjid = $this->makeMasjid(['stripe_account_id' => 'acct_B']);
        $this->group = Group::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->service = app(RegistrationService::class);
    }

    // ------------------------------------------------------- the core sweep

    #[Test]
    public function the_reaper_releases_exactly_one_seat_per_expired_registration(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);

        $stale = $this->paidPending($offering);
        $alsoStale = $this->paidPending($offering);
        $this->expire($stale);
        $this->expire($alsoStale);

        $this->assertSame(2, $offering->fresh()->registration_count);

        Artisan::call('registrations:reap-expired');

        foreach ([$stale, $alsoStale] as $registration) {
            $registration->refresh();
            // The design's terminal state for an abandoned hold: seat cancelled,
            // money canceled (Stripe's spelling), window cleared.
            $this->assertSame(Registration::STATUS_CANCELLED, $registration->status);
            $this->assertSame(Registration::PAYMENT_CANCELED, $registration->payment_status);
            $this->assertNull($registration->checkout_expires_at);
        }

        // Two seats held, two seats returned — never three, never one.
        $this->assertSame(0, $offering->fresh()->registration_count);

        // Nothing was invented on the way out: no ledger row, no roster row.
        $this->assertDatabaseCount('registration_payments', 0);
        $this->assertSame(0, GroupMembership::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_second_run_is_a_no_op(): void
    {
        $offering = $this->makeOffering(['capacity' => 3, 'registration_count' => 1]);
        $stale = $this->expire($this->paidPending($offering));

        $this->assertSame(2, $offering->fresh()->registration_count);

        Artisan::call('registrations:reap-expired');
        $this->assertSame(1, $offering->fresh()->registration_count);

        // The row no longer matches the sweep at all (not pending, no window),
        // and releaseSeat would refuse it even if it did.
        Artisan::call('registrations:reap-expired');
        $this->assertSame(1, $offering->fresh()->registration_count);
        $this->assertSame(Registration::STATUS_CANCELLED, $stale->fresh()->status);
    }

    // ------------------------------------------------------- the grace margin

    #[Test]
    public function a_registration_inside_the_grace_margin_is_not_reaped(): void
    {
        config(['services.stripe.registration_reaper_grace_minutes' => 15]);

        $offering = $this->makeOffering(['capacity' => 5]);

        // Window closed one minute ago: Stripe may still be delivering the
        // payment webhook for a donor who paid in the last second. Reaping now
        // would cancel a seat somebody has already been charged for.
        $justExpired = $this->expire($this->paidPending($offering), 1);
        // Window closed well beyond the margin: safely abandoned.
        $longExpired = $this->expire($this->paidPending($offering), 60);

        Artisan::call('registrations:reap-expired');

        $this->assertSame(Registration::STATUS_PENDING, $justExpired->fresh()->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $justExpired->fresh()->payment_status);
        $this->assertSame(Registration::STATUS_CANCELLED, $longExpired->fresh()->status);

        // One of the two seats came back; the protected hold still holds.
        $this->assertSame(1, $offering->fresh()->registration_count);
    }

    #[Test]
    public function the_grace_margin_comes_from_config_and_the_option_overrides_it(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->expire($this->paidPending($offering), 20);

        // Config margin wider than the age of the hold: nothing is swept.
        config(['services.stripe.registration_reaper_grace_minutes' => 45]);
        Artisan::call('registrations:reap-expired');
        $this->assertSame(Registration::STATUS_PENDING, $registration->fresh()->status);

        // An operator can narrow it for a one-off run without a config change.
        Artisan::call('registrations:reap-expired', ['--grace' => 5]);
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->assertSame(0, $offering->fresh()->registration_count);
    }

    #[Test]
    public function the_documented_default_grace_is_the_one_the_command_uses(): void
    {
        // The config default and the command's fallback constant must not
        // drift apart — the docblock quotes one number for both.
        $this->assertSame(
            ReapExpiredCheckouts::DEFAULT_GRACE_MINUTES,
            (int) config('services.stripe.registration_reaper_grace_minutes')
        );
        $this->assertSame(15, ReapExpiredCheckouts::DEFAULT_GRACE_MINUTES);
    }

    // ------------------------------------------------- what is NEVER reaped

    #[Test]
    public function a_paid_and_confirmed_registration_is_never_reaped(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        // Settle it exactly as the webhook does, then age the clock past any
        // margin. Settlement nulls the window, so the sweep cannot see it.
        app(RegistrationPaymentService::class)
            ->handleCheckoutCompleted($this->sessionPayload($registration), 'acct_A');

        $this->travel(1)->days();
        Artisan::call('registrations:reap-expired');

        $registration->refresh();
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(1, $offering->fresh()->registration_count);
    }

    #[Test]
    public function a_paid_seat_whose_window_was_left_behind_is_still_never_reaped(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->paidPending($offering);

        // Pathological row: money landed but a stale deadline is still on the
        // record (a hand-edited row, or a future writer that forgets to clear
        // it). payment_status alone must keep the seat safe.
        $registration->forceFill([
            'status' => Registration::STATUS_CONFIRMED,
            'payment_status' => Registration::PAYMENT_PAID,
            'checkout_expires_at' => now()->subDay(),
        ])->save();

        Artisan::call('registrations:reap-expired');

        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->fresh()->status);
        $this->assertSame(1, $offering->fresh()->registration_count);
    }

    #[Test]
    public function waitlisted_free_and_past_due_registrations_are_never_reaped(): void
    {
        $offering = $this->makeOffering(['capacity' => 1]);

        // Holds the only seat.
        $holder = $this->paidPending($offering);
        // Raced out — holds nothing, so there is nothing to give back.
        $waitlisted = $this->paidPending($offering);
        $this->assertSame(Registration::STATUS_WAITLISTED, $waitlisted->status);

        // A free confirmed registration on its own offering: no Stripe leg, no
        // window, nothing to expire.
        $freeOffering = $this->makeOffering(['capacity' => 5]);
        $free = $this->freeConfirmed($freeOffering);

        // A dunning installment (T-006e's state): a failed payment NEVER ejects
        // an enrolled child — that is an explicit admin action.
        $pastDue = $this->paidPending($freeOffering);
        $pastDue->forceFill([
            'payment_status' => Registration::PAYMENT_PAST_DUE,
            'checkout_expires_at' => now()->subDay(),
        ])->save();

        $this->travel(2)->days();
        Artisan::call('registrations:reap-expired');

        $this->assertSame(Registration::STATUS_WAITLISTED, $waitlisted->fresh()->status);
        $this->assertSame(Registration::STATUS_CONFIRMED, $free->fresh()->status);
        $this->assertSame(Registration::PAYMENT_PAST_DUE, $pastDue->fresh()->payment_status);
        $this->assertSame(Registration::STATUS_PENDING, $pastDue->fresh()->status);

        // Only the genuinely abandoned hold moved.
        $this->assertSame(Registration::STATUS_CANCELLED, $holder->fresh()->status);
        $this->assertSame(0, $offering->fresh()->registration_count);
        // free + past_due both still hold their seats on the second offering.
        $this->assertSame(2, $freeOffering->fresh()->registration_count);
    }

    // ------------------------------------------------- convergence, both orders

    #[Test]
    public function webhook_first_then_the_reaper_decrements_exactly_once(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->expire($this->paidPending($offering), 60);

        $this->assertSame(1, $offering->fresh()->registration_count);

        // Stripe's expiry event arrives normally and releases the seat.
        app(RegistrationPaymentService::class)
            ->handleCheckoutExpired($this->sessionPayload($registration), 'acct_A');

        $this->assertSame(0, $offering->fresh()->registration_count);

        // The sweep runs afterwards and must find nothing to do.
        Artisan::call('registrations:reap-expired');

        $this->assertSame(0, $offering->fresh()->registration_count);
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->assertStringContainsString('0 seat(s) released', Artisan::output());
    }

    #[Test]
    public function the_reaper_first_then_the_webhook_decrements_exactly_once(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->expire($this->paidPending($offering), 60);

        Artisan::call('registrations:reap-expired');
        $this->assertSame(0, $offering->fresh()->registration_count);

        // Stripe's expiry event finally arrives (or is redelivered). The seam is
        // pending-only, so it changes nothing — the counter cannot go negative.
        app(RegistrationPaymentService::class)
            ->handleCheckoutExpired($this->sessionPayload($registration), 'acct_A');

        $this->assertSame(0, $offering->fresh()->registration_count);
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->assertSame(Registration::PAYMENT_CANCELED, $registration->fresh()->payment_status);
    }

    #[Test]
    public function a_payment_landing_after_the_reaper_records_money_without_resurrecting_the_seat(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $registration = $this->expire($this->paidPending($offering), 60);

        Artisan::call('registrations:reap-expired');
        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);

        // Very late money on a reaped hold. It is recorded in the ledger (the
        // org refunds from its own dashboard — it is merchant of record) and
        // the seat stays gone: a cancelled registration is never resurrected,
        // and capacity is not silently exceeded behind the offering's back.
        app(RegistrationPaymentService::class)
            ->handleCheckoutCompleted($this->sessionPayload($registration), 'acct_A');

        $this->assertSame(Registration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->assertSame(0, $offering->fresh()->registration_count);
        $this->assertSame(1, RegistrationPayment::withoutMasjidScope()
            ->where('registration_id', $registration->id)->count());
    }

    // ------------------------------------------------------------- dry run

    #[Test]
    public function a_dry_run_reports_the_counts_and_mutates_nothing(): void
    {
        $offering = $this->makeOffering(['capacity' => 5]);
        $stale = $this->expire($this->paidPending($offering), 60);
        $this->expire($this->paidPending($offering), 60);

        Artisan::call('registrations:reap-expired', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Would reap 2 expired checkout(s)', $output);
        $this->assertStringContainsString('2 seat(s) released', $output);

        // Nothing moved.
        $this->assertSame(2, $offering->fresh()->registration_count);
        $this->assertSame(Registration::STATUS_PENDING, $stale->fresh()->status);
        $this->assertSame(Registration::PAYMENT_AWAITING, $stale->fresh()->payment_status);

        // And the real run reports the same numbers the dry run promised.
        Artisan::call('registrations:reap-expired');
        $this->assertStringContainsString('Reaped 2 expired checkout(s)', Artisan::output());
        $this->assertSame(0, $offering->fresh()->registration_count);
    }

    // -------------------------------------------------------- cross-tenant

    #[Test]
    public function a_masjid_narrowed_sweep_never_touches_another_tenants_registration(): void
    {
        $mine = $this->makeOffering(['capacity' => 5]);
        $theirs = $this->makeOffering(['capacity' => 5], $this->otherMasjid);

        $myStale = $this->expire($this->paidPending($mine), 60);
        $theirStale = $this->expire($this->paidPending($theirs, $this->otherMasjid), 60);

        Artisan::call('registrations:reap-expired', ['--masjid' => $this->masjid->id]);

        $this->assertSame(Registration::STATUS_CANCELLED, $myStale->fresh()->status);
        $this->assertSame(0, $mine->fresh()->registration_count);

        // Another organization's abandoned hold is emphatically NOT this
        // organization's to reclaim.
        $this->assertSame(Registration::STATUS_PENDING, $theirStale->fresh()->status);
        $this->assertSame(1, $theirs->fresh()->registration_count);
    }

    #[Test]
    public function an_unnarrowed_sweep_crosses_organizations_and_credits_each_its_own_seat(): void
    {
        $mine = $this->makeOffering(['capacity' => 5]);
        $theirs = $this->makeOffering(['capacity' => 5], $this->otherMasjid);

        $this->expire($this->paidPending($mine), 60);
        $this->expire($this->paidPending($theirs, $this->otherMasjid), 60);

        // A system job with no tenant bound sweeps every organization — that is
        // the point of withoutMasjidScope() being explicit rather than implied.
        Artisan::call('registrations:reap-expired');

        // Each decrement landed on its OWN offering.
        $this->assertSame(0, $mine->fresh()->registration_count);
        $this->assertSame(0, $theirs->fresh()->registration_count);
        $this->assertSame(2, Registration::withoutMasjidScope()
            ->where('status', Registration::STATUS_CANCELLED)->count());
    }

    // ----------------------------------------------------------- fixtures

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
        ], $overrides));
    }

    private function makeOffering(array $state = [], ?Masjid $masjid = null): Offering
    {
        $masjid ??= $this->masjid;

        $factory = Offering::factory()->forMasjid($masjid);

        if ($masjid->is($this->masjid)) {
            $factory = $factory->withRoster($this->group);
        }

        return $factory->create($state);
    }

    /** A paid one-time registration in its post-intake state, via the real service. */
    private function paidPending(Offering $offering, ?Masjid $masjid = null): Registration
    {
        $masjid ??= $this->masjid;

        $plan = FeePlan::factory()->create([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ]);

        $registration = $this->service->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $masjid->id]),
            ['full_name' => 'Amal Yusuf']
        );

        // What RegistrationCheckoutService records the instant it hands back a
        // URL — the handle the expiry event is matched against.
        $registration->forceFill([
            'stripe_checkout_session_id' => 'cs_' . $registration->uuid,
        ])->save();

        return $registration;
    }

    private function freeConfirmed(Offering $offering): Registration
    {
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return $this->service->register(
            $offering,
            $plan,
            Contact::factory()->create(['masjid_id' => $this->masjid->id]),
            ['full_name' => 'Free Path']
        );
    }

    /** Backdate the checkout window so the hold is $minutesAgo minutes stale. */
    private function expire(Registration $registration, int $minutesAgo = 60): Registration
    {
        $registration->forceFill([
            'checkout_expires_at' => now()->subMinutes($minutesAgo),
        ])->save();

        return $registration;
    }

    /** The Checkout Session object as the webhook handlers receive it. */
    private function sessionPayload(Registration $registration): array
    {
        return [
            'id' => 'cs_' . $registration->uuid,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 15000,
            'payment_intent' => 'pi_' . $registration->uuid,
            'metadata' => ['registration_uuid' => $registration->uuid],
        ];
    }
}
