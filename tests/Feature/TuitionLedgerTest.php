<?php

namespace Tests\Feature;

use App\Models\{Contact, FeePlan, GroupMembership, Masjid, Offering, SchoolTerm, TuitionAccount, TuitionChild, TuitionGuardian, TuitionLine, TuitionSchedule, TuitionCheckout, TuitionMail, User};
use App\Services\FamilyLedger\{FamilyLedger, TuitionCharges, TuitionPayments, TuitionReminders, TuitionStatement};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

class TuitionLedgerTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;

    private function setupLedger(int $count = 1): array
    {
        $school = $this->makeMasjid();
        $school->forceFill(['stripe_account_id' => 'acct_practice', 'stripe_charges_enabled' => true, 'capability_overrides' => ['tuition_ledger' => true, 'school_calendar' => true, 'school_calendar_terms' => true]])->save();
        $year = $this->year($school); $class = $this->classroom($school); $children = []; $parent = null;
        for ($i = 0; $i < $count; $i++) {
            $child = $this->enrol($class); $parent = $this->guardian($child, $parent); $children[] = $child->contact_id;
        }
        $actor = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15555550107', 'name' => 'Practice Clerk', 'email' => 'clerk@example.invalid']);
        $ledger = app(FamilyLedger::class);
        $account = $ledger->open($school, $children, [$parent->id], $actor->id);
        TuitionChild::where('account_id', $account->id)->update(['starts_on' => '2026-08-24']);
        $offering = Offering::factory()->create(['masjid_id' => $school->id, 'group_id' => $class->id]);
        $plan = FeePlan::create(['masjid_id' => $school->id, 'offering_id' => $offering->id, 'kind' => 'one_time', 'amount_minor' => 12345, 'currency' => 'usd', 'label' => 'Practice tuition', 'is_active' => true]);
        $schedule = TuitionSchedule::create(['masjid_id' => $school->id, 'fee_plan_id' => $plan->id, 'school_year_id' => $year->id, 'cadence' => 'monthly', 'due_day' => 9, 'sibling_percent' => 10, 'group_ids' => [$class->id], 'grades' => [], 'created_by_user_id' => $actor->id]);
        return compact('school', 'year', 'class', 'children', 'parent', 'actor', 'account', 'plan', 'schedule', 'ledger');
    }

    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }

    #[Test]
    public function due_day_uses_school_timezone_and_overlapping_sweeps_never_double_a_charge(): void
    {
        $x = $this->setupLedger(); $this->travelTo(now()->parse('2026-10-09 03:59:00 UTC'));
        $this->artisan('tuition:charges')->assertSuccessful(); $this->assertSame(0, TuitionLine::count());
        $this->travelTo(now()->parse('2026-10-09 04:00:00 UTC'));
        $this->artisan('tuition:charges')->assertSuccessful(); $this->artisan('tuition:charges')->assertSuccessful();
        app(TuitionCharges::class)->post($x['school']);
        $this->assertSame(1, TuitionLine::count()); $this->assertSame(12345, $x['ledger']->balance($x['account']));
        $this->assertSame('2026-10-09', TuitionLine::first()->effective_on->toDateString());
    }

    #[Test]
    public function siblings_have_separate_discount_lines_eldest_full_with_integer_rounding(): void
    {
        $x = $this->setupLedger(3); $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC'));
        app(TuitionCharges::class)->post($x['school']);
        $this->assertSame([12345, 12345, 12345], TuitionLine::where('kind', 'charge')->pluck('amount_minor')->all());
        $this->assertSame([-1234, -1234], TuitionLine::where('kind', 'sibling_discount')->pluck('amount_minor')->all());
        $this->assertSame(34567, $x['ledger']->balance($x['account']));
    }

    #[Test]
    public function credits_office_payments_voids_and_balance_are_append_only(): void
    {
        $x = $this->setupLedger();
        $charge = $x['ledger']->office($x['account'], 'charge', 9000, '2026-10-01', 'Books', $x['actor']->id);
        $credit = $x['ledger']->office($x['account'], 'credit', 400, '2026-10-02', 'Aid', $x['actor']->id);
        $payment = $x['ledger']->office($x['account'], 'office_payment', 1000, '2026-10-03', 'Received', $x['actor']->id, 'cash', 'Practice reference');
        $x['ledger']->void($credit, 'Correction', $x['actor']->id);
        $this->assertSame(8000, $x['ledger']->balance($x['account']));
        $this->assertSame(8000, (int) TuitionLine::sum('amount_minor'));
        $this->assertNull($payment->stripe_payment_intent_id); $this->assertSame('cash', $payment->method);
        $this->expectException(\LogicException::class); $charge->update(['amount_minor' => 1]);
    }

    #[Test]
    public function yearly_and_term_dates_come_from_the_school_calendar_even_on_closed_days(): void
    {
        $x = $this->setupLedger();
        SchoolTerm::create(['masjid_id' => $x['school']->id, 'school_year_id' => $x['year']->id, 'name' => 'Practice term', 'position' => 1, 'starts_on' => '2026-10-04', 'ends_on' => '2026-12-20']);
        $x['schedule']->update(['cadence' => 'term']);
        $this->assertSame(['2026-10-04'], array_column(app(TuitionCharges::class)->periods($x['schedule']), 'due_on'));
        $x['schedule']->update(['cadence' => 'yearly']);
        $this->assertSame(['2026-08-24'], array_column(app(TuitionCharges::class)->periods($x['schedule']), 'due_on'));
    }

    private function webhook(string $type, array $object, string $id = 'evt_practice', string $account = 'acct_practice', ?int $created = null)
    {
        config(['services.stripe.connect_webhook_secret' => 'whsec_practice']);
        $body = json_encode(['id' => $id, 'type' => $type, 'account' => $account, 'created' => $created ?? time(), 'data' => ['object' => $object]]);
        $stamp = time(); $signature = hash_hmac('sha256', $stamp.'.'.$body, 'whsec_practice');
        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$stamp},v1={$signature}"], $body);
    }

    private function checkout(array $x): TuitionCheckout
    {
        return TuitionCheckout::create(['masjid_id' => $x['school']->id, 'account_id' => $x['account']->id, 'payer_contact_id' => $x['parent']->id, 'uuid' => 'practice-checkout', 'amount_minor' => 1000, 'balance_minor' => 2000, 'currency' => 'usd', 'stripe_account_id' => 'acct_practice', 'stripe_session_id' => 'cs_practice']);
    }

    #[Test]
    public function card_lines_only_arrive_from_verified_webhooks_and_event_pairs_converge(): void
    {
        $x = $this->setupLedger(); $this->checkout($x); $this->family($x['parent']);
        $this->getJson("/api/family/masjids/{$x['school']->id}/tuition?checkout=success")->assertOk();
        $this->assertSame(0, TuitionLine::count());
        $metadata = ['tuition_checkout_uuid' => 'practice-checkout'];
        $session = ['id' => 'cs_practice', 'metadata' => $metadata, 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'usd', 'payment_intent' => 'pi_practice'];
        $this->postJson('/api/stripe/webhook', ['data' => ['object' => $session]])->assertUnauthorized();
        $this->webhook('checkout.session.completed', $session)->assertOk();
        $this->webhook('checkout.session.completed', $session)->assertOk();
        $this->webhook('payment_intent.succeeded', ['id' => 'pi_practice', 'metadata' => $metadata, 'amount_received' => 1000, 'currency' => 'usd', 'latest_charge' => 'ch_practice'], 'evt_practice_pi')->assertOk();
        $this->assertSame(1, TuitionLine::where('kind', 'card_payment')->count());
        $this->assertSame(-1000, $x['ledger']->balance($x['account']));
        $this->assertTrue(TuitionLine::first()->needs_review);
    }

    /** Real Stripe: a Charge does NOT carry its PaymentIntent's metadata, so the refund is found by the intent id. */
    #[Test]
    public function a_refund_charge_without_metadata_finds_its_tuition_payment_by_payment_intent(): void
    {
        $x = $this->setupLedger(); $this->checkout($x);
        $session = ['id' => 'cs_practice', 'metadata' => ['tuition_checkout_uuid' => 'practice-checkout'], 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'usd', 'payment_intent' => 'pi_practice'];
        $this->webhook('checkout.session.completed', $session, 'evt_paid')->assertOk();
        $charge = ['id' => 'ch_practice', 'object' => 'charge', 'payment_intent' => 'pi_practice', 'metadata' => [], 'amount_refunded' => 400, 'currency' => 'usd'];
        $this->webhook('charge.refunded', $charge, 'evt_refund_real')->assertOk();
        $this->assertSame([400], TuitionLine::where('kind', 'refund')->pluck('amount_minor')->all());
        // Another intent on the same account is not a tuition payment.
        $this->webhook('charge.refunded', ['id' => 'ch_other', 'payment_intent' => 'pi_other', 'metadata' => [], 'amount_refunded' => 100, 'currency' => 'usd'], 'evt_refund_other')->assertOk();
        $this->assertSame(1, TuitionLine::where('kind', 'refund')->count());
    }

    #[Test]
    public function refund_events_append_cumulative_deltas_once_even_before_payment_settlement(): void
    {
        $x = $this->setupLedger(); $this->checkout($x);
        $charge = ['id' => 'ch_practice', 'payment_intent' => 'pi_practice', 'metadata' => ['tuition_checkout_uuid' => 'practice-checkout'], 'amount_refunded' => 350, 'currency' => 'usd'];
        $this->webhook('charge.refunded', $charge, 'evt_refund')->assertOk();
        $this->webhook('charge.refunded', $charge, 'evt_refund_again')->assertOk();
        $charge['amount_refunded'] = 500; $this->webhook('charge.refunded', $charge, 'evt_refund_more')->assertOk();
        $this->assertSame([350,150], TuitionLine::where('kind', 'refund')->pluck('amount_minor')->all());
    }

    #[Test]
    public function checkout_refuses_above_balance_and_below_school_minimum_without_calling_stripe(): void
    {
        $x = $this->setupLedger(); $x['ledger']->office($x['account'], 'charge', 900, '2026-10-01', 'Tuition', $x['actor']->id);
        $this->family($x['parent']); $url = "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}/checkout";
        $this->postJson($url, ['amount_minor' => 901])->assertUnprocessable();
        $x['account']->update(['minimum_payment_minor' => 200]);
        $this->postJson($url, ['amount_minor' => 100])->assertUnprocessable();
        $this->assertSame(0, TuitionCheckout::count());
    }

    #[Test]
    public function guardian_must_be_office_confirmed_over_every_child_and_never_reads_another_account(): void
    {
        $x = $this->setupLedger(2); $this->family($x['parent']); $url = "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}";
        $this->getJson($url)->assertOk();
        GroupMembership::where('contact_id', $x['parent']->id)->where('guardian_of_contact_id', $x['children'][1])->delete();
        $this->getJson($url)->assertForbidden();
        $this->getJson("/api/family/masjids/{$x['school']->id}/tuition")->assertOk()->assertJsonPath('data.accounts', []);
        $this->getJson($url.'/statement?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }

    #[Test]
    public function reminder_before_and_after_due_batches_guardians_logs_once_and_never_adds_a_fee(): void
    {
        Mail::fake(); config(['queue.default' => 'database']); $x = $this->setupLedger(2);
        $x['account']->update(['remind_before_days' => 3, 'remind_after_days' => 2]);
        $this->travelTo(now()->parse('2026-10-06 16:00:00 UTC'));
        $this->artisan('tuition:remind')->assertSuccessful(); $this->artisan('tuition:remind')->assertSuccessful();
        $this->assertSame(1, TuitionMail::count()); $this->assertSame(0, TuitionLine::count());
        $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC')); app(TuitionCharges::class)->post($x['school']);
        $this->travelTo(now()->parse('2026-10-11 16:00:00 UTC')); $this->artisan('tuition:remind')->assertSuccessful();
        $this->assertSame(2, TuitionMail::count()); $this->assertSame(3, TuitionLine::count());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    #[Test]
    public function date_range_statement_has_only_range_lines_and_a_computed_opening_balance(): void
    {
        $x = $this->setupLedger();
        foreach (['2026-09-30' => 900, '2026-10-01' => 100, '2026-10-31' => 200, '2026-11-01' => 300] as $date => $amount) $x['ledger']->office($x['account'], 'charge', $amount, $date, $date, $x['actor']->id);
        $statement = app(TuitionStatement::class); $data = $statement->data($x['account'], '2026-10-01', '2026-10-31');
        $this->assertSame(900, $data['opening_minor']); $this->assertSame(1200, $data['closing_minor']);
        $this->assertSame(['2026-10-01', '2026-10-31'], array_column($data['lines'], 'date'));
        $html = $statement->html($x['account'], '2026-10-01', '2026-10-31');
        $this->assertStringContainsString('Oct 1, 2026', $html); $this->assertStringContainsString('Oct 31, 2026', $html);
        $this->assertStringNotContainsString('#2', $html); $this->assertStringNotContainsString('office_payment', $html);
        $this->assertStringContainsString('$9.00', $html); $this->assertStringContainsString('$12.00', $html);
        $this->assertStringNotContainsString('2026-09-30', $html); $this->assertStringNotContainsString('2026-11-01', $html);
        $this->assertStringStartsWith('%PDF-', $statement->pdf($x['account'], '2026-10-01', '2026-10-31'));
    }

    #[Test]
    public function switch_off_hides_everything_and_creates_nothing(): void
    {
        $x = $this->setupLedger(); $x['school']->forceFill(['capability_overrides' => ['tuition_ledger' => false]])->save();
        $this->family($x['parent']); $this->getJson("/api/family/masjids/{$x['school']->id}/tuition")->assertForbidden();
        $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC')); $this->artisan('tuition:charges')->assertSuccessful(); $this->artisan('tuition:remind')->assertSuccessful();
        $this->assertSame(0, TuitionLine::count()); $this->assertSame(0, TuitionMail::count());
        $this->assertArrayNotHasKey('tuition_ledger', $x['school']->fresh()->capabilities);
    }

    #[Test]
    public function all_new_models_are_tenant_isolated(): void
    {
        $x = $this->setupLedger(); $this->checkout($x); $x['ledger']->office($x['account'], 'credit', 1, '2026-10-01', 'Practice', $x['actor']->id);
        TuitionMail::create(['masjid_id' => $x['school']->id, 'account_id' => $x['account']->id, 'guardian_contact_id' => $x['parent']->id, 'mail_key' => 'practice', 'kind' => 'statement', 'status' => 'queued', 'from_on' => '2026-10-01', 'to_on' => '2026-10-31']);
        $other = $this->makeMasjid('b'); app(TenantContext::class)->set($other->id);
        foreach ([\App\Models\OrganisationBillingSetting::class, TuitionAccount::class, TuitionChild::class, TuitionGuardian::class, TuitionSchedule::class, TuitionLine::class, TuitionCheckout::class, TuitionMail::class] as $model) $this->assertSame(0, $model::count(), $model);
        app(TenantContext::class)->set($x['school']->id);
        foreach ([\App\Models\OrganisationBillingSetting::class, TuitionAccount::class, TuitionChild::class, TuitionGuardian::class, TuitionSchedule::class, TuitionLine::class, TuitionCheckout::class, TuitionMail::class] as $model) $this->assertGreaterThan(0, $model::count(), $model);
    }
    #[Test]
    public function checkout_is_hosted_card_only_direct_to_school_with_exact_minor_units_and_safe_returns(): void
    {
        $x = $this->setupLedger(); $x['ledger']->office($x['account'], 'charge', 2000, '2026-10-01', 'Tuition', $x['actor']->id);
        $captured = [];
        $this->mock(\App\Services\Stripe\RegistrationCheckoutService::class)->shouldReceive('createDirectCheckout')->once()->withArgs(function ($params, $account, $key) use (&$captured) {
            $captured = $params;
            $this->assertSame('acct_practice', $account); $this->assertStringStartsWith('tuition:', $key);
            $this->assertSame(['card'], $params['payment_method_types']); $this->assertSame(1500, $params['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame($params['metadata'], $params['payment_intent_data']['metadata']);
            $this->assertArrayNotHasKey('transfer_data', $params['payment_intent_data']);
            $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/family/', $params['success_url']);
            return true;
        })->andReturn(['id' => 'cs_practice', 'url' => 'https://checkout.stripe.com/practice', 'payment_intent' => null]);
        $this->family($x['parent']); $url = "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}/checkout";
        $this->postJson($url, ['amount_minor' => 1500, 'return_url' => 'https://example.invalid'])->assertOk();
        $this->postJson($url, ['amount_minor' => 1500])->assertOk();
        $this->assertSame(1, TuitionCheckout::count()); $this->assertSame(1, TuitionLine::count());
    }

    #[Test]
    public function wrong_account_unpaid_or_wrong_currency_events_cannot_write_a_payment(): void
    {
        $x = $this->setupLedger(); $this->checkout($x);
        $pi = ['id' => 'pi_practice', 'metadata' => ['tuition_checkout_uuid' => 'practice-checkout'], 'amount_received' => 1000, 'currency' => 'usd'];
        $this->webhook('payment_intent.succeeded', $pi, 'evt_wrong_account', 'acct_other')->assertOk();
        $this->webhook('checkout.session.completed', ['id' => 'cs_practice', 'metadata' => $pi['metadata'], 'currency' => 'usd', 'payment_intent' => 'pi_practice', 'payment_status' => 'unpaid', 'amount_total' => 1000], 'evt_unpaid')->assertOk();
        $pi['currency'] = 'cad'; $this->webhook('payment_intent.succeeded', $pi, 'evt_currency')->assertStatus(500);
        $this->assertSame(0, TuitionLine::count());
    }

    #[Test]
    public function changed_amount_is_still_paid_and_flagged_and_settles_after_the_switch_is_turned_off(): void
    {
        $x = $this->setupLedger(); $this->checkout($x);
        $x['school']->forceFill(['capability_overrides' => ['tuition_ledger' => false]])->save();
        $this->webhook('payment_intent.succeeded', ['id' => 'pi_practice', 'metadata' => ['tuition_checkout_uuid' => 'practice-checkout'], 'currency' => 'usd', 'amount_received' => 1100], 'evt_changed')->assertOk();
        $line = TuitionLine::firstOrFail(); $this->assertSame(-1100, $line->amount_minor); $this->assertTrue($line->needs_review);
    }

    #[Test]
    public function an_office_only_school_can_keep_debt_and_refuses_checkout(): void
    {
        $x = $this->setupLedger(); $x['school']->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => false])->save();
        $x['ledger']->office($x['account'], 'charge', 1000, '2026-10-01', 'Practice tuition', $x['actor']->id);
        $x['ledger']->office($x['account'], 'office_payment', 200, '2026-10-02', 'Received', $x['actor']->id, 'zelle');
        $this->family($x['parent']); $url = "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.card_available', false)->assertJsonPath('data.balance_minor', 800);
        $this->postJson($url.'/checkout', ['amount_minor' => 800])->assertUnprocessable();
    }

    #[Test]
    public function a_scheduled_charge_void_reverses_its_discount_once_without_deleting_history(): void
    {
        $x = $this->setupLedger(2); $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC')); app(TuitionCharges::class)->post($x['school']);
        $charge = TuitionLine::where('kind', 'charge')->latest('id')->firstOrFail();
        $x['ledger']->void($charge, 'Practice correction', $x['actor']->id); $x['ledger']->void($charge, 'Practice correction', $x['actor']->id);
        $this->assertSame(5, TuitionLine::count()); $this->assertSame(12345, $x['ledger']->balance($x['account']));
        $this->assertSame(12345, collect($x['ledger']->due($x['account']))->sum('amount_minor'));
        $this->expectException(\LogicException::class); $charge->delete();
    }

    #[Test]
    public function the_eldest_eligible_child_pays_full_even_if_entered_last_and_grade_filters_apply(): void
    {
        $x = $this->setupLedger(3);
        foreach ($x['children'] as $index => $id) { $c = Contact::findOrFail($id); $c->date_of_birth = $index === 2 ? '2010-01-01' : '2015-01-01'; $c->save(); }
        $rows = app(TuitionCharges::class)->preview($x['school'], '2026-10-09');
        $this->assertSame($x['children'][2], $rows[0]['child_contact_id']); $this->assertSame(0, $rows[0]['discount_minor']);
        $x['schedule']->update(['grades' => ['Practice grade']]);
        $this->assertSame([], app(TuitionCharges::class)->preview($x['school'], '2026-10-09'));
        GroupMembership::where('contact_id', $x['children'][1])->where('role', 'member')->update(['grade_label' => 'Practice grade']);
        $rows = app(TuitionCharges::class)->preview($x['school'], '2026-10-09');
        $this->assertCount(1, $rows); $this->assertSame(0, $rows[0]['discount_minor']);
    }

    #[Test]
    public function real_database_queue_serializes_ids_and_delivers_one_statement_with_pdf_and_rechecks_custody(): void
    {
        $x = $this->setupLedger(); config(['queue.default' => 'database']); Mail::fake();
        $x['ledger']->office($x['account'], 'charge', 1000, '2026-10-01', 'Practice charge', $x['actor']->id);
        $this->assertSame(1, app(TuitionReminders::class)->recipients($x['account'], 'statement', '2026-10-01', '2026-10-31'));
        $payload = json_decode(DB::table('jobs')->first()->payload, true); $job = unserialize($payload['data']['command']);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();
        $job->handle();
        Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, fn ($mail) => isset($mail->rawAttachments[0]['data']) && str_starts_with($mail->rawAttachments[0]['data'], '%PDF-'));
        Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, 1);
        $this->assertSame('sent', TuitionMail::first()->status);
        $this->assertSame(1, app(TuitionReminders::class)->recipients($x['account'], 'statement', '2026-10-01', '2026-10-31'));
        GroupMembership::where('role', 'guardian')->delete();
        $row = TuitionMail::latest('id')->first(); (new \App\Jobs\SendTuitionMail($row->id, $x['school']->id))->handle();
        $this->assertSame('skipped', $row->fresh()->status); Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, 1);
    }

    #[Test]
    public function paid_debt_suppresses_queued_overdue_mail_and_unsafe_transport_fails_without_logging_an_address(): void
    {
        $x = $this->setupLedger(); config(['queue.default' => 'database']); Mail::fake();
        $x['account']->update(['remind_after_days' => 2]); $x['ledger']->office($x['account'], 'charge', 1000, '2026-10-01', 'Practice charge', $x['actor']->id);
        $this->travelTo(now()->parse('2026-10-03 16:00:00 UTC')); app(TuitionReminders::class)->queue($x['school']);
        $x['ledger']->office($x['account'], 'office_payment', 1000, '2026-10-03', 'Received', $x['actor']->id, 'cash');
        $row = TuitionMail::firstOrFail(); (new \App\Jobs\SendTuitionMail($row->id, $x['school']->id))->handle();
        $this->assertSame('skipped', $row->fresh()->status); Mail::assertNothingSent();
        app(TuitionReminders::class)->recipients($x['account'], 'statement', '2026-10-01', '2026-10-31');
        $row = TuitionMail::latest('id')->first(); config(['app.env' => 'production']);
        (new \App\Jobs\SendTuitionMail($row->id, $x['school']->id))->handle();
        $this->assertSame('failed', $row->fresh()->status); Mail::assertNothingSent();
    }

    #[Test]
    public function tuition_contacts_are_kept_on_account_deletion_and_all_new_tables_have_portable_keys(): void
    {
        $x = $this->setupLedger();
        $this->assertContains('tuition_guardians', app(\App\Services\Member\MemberAccountDeletion::class)->reasonsToKeep($x['parent']));
        $this->assertTrue($x['ledger']->holdsContact(Contact::findOrFail($x['children'][0])));
        foreach (['organisation_billing_settings', 'tuition_accounts', 'tuition_children', 'tuition_guardians', 'tuition_schedules', 'tuition_checkouts', 'tuition_lines', 'tuition_mails'] as $table) {
            $keys = \Illuminate\Support\Facades\Schema::getIndexes($table);
            $this->assertNotNull(collect($keys)->firstWhere('primary', true), $table);
            foreach ($keys as $key) $this->assertLessThanOrEqual(64, strlen($key['name']));
            foreach (\Illuminate\Support\Facades\Schema::getForeignKeys($table) as $fk) if ($fk['foreign_table'] === 'contacts') $this->assertSame('restrict', strtolower($fk['on_delete']));
        }
    }

    #[Test]
    public function office_routes_are_scoped_and_export_exact_range_with_formula_safe_references(): void
    {
        $x = $this->setupLedger(); $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $x['school']->forceFill(['user_id' => $x['actor']->id])->save();
        \Laravel\Sanctum\Sanctum::actingAs($x['actor'], ['staff']);
        $url = "/api/admin/masjids/{$x['school']->id}/tuition";
        $this->postJson($url.'/'.$x['account']->id.'/entries', ['idempotency_key' => '22222222-2222-4222-8222-222222222222', 'kind' => 'office_payment', 'amount_minor' => 100, 'effective_on' => '2026-10-02', 'reason' => '=SUM(1,2)', 'method' => 'cash', 'reference' => '=HYPERLINK("https://example.invalid")'])->assertOk();
        $body = $this->get($url.'/export?from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('Original entry', $body); $this->assertStringContainsString('Recorded at', $body);
        $this->assertStringContainsString("'=SUM", $body); $this->assertStringContainsString("'=HYPERLINK", $body);
        $other = $this->makeMasjid('b'); $account = TuitionAccount::create(['masjid_id' => $other->id, 'currency' => 'usd']);
        $this->getJson($url.'/'.$account->id.'?from=2026-10-01&to=2026-10-31')->assertNotFound();
        $this->postJson($url.'/'.$account->id.'/entries', ['idempotency_key' => '33333333-3333-4333-8333-333333333333', 'kind' => 'charge', 'amount_minor' => 100, 'effective_on' => '2026-10-02', 'reason' => 'Practice'])->assertNotFound();
    }

    #[Test]
    public function new_migration_rolls_down_and_up_without_leaving_tables_or_losing_primary_keys(): void
    {
        $migration = require database_path('migrations/2026_10_15_000000_create_tuition_ledger.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('tuition_lines'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('organisation_billing_settings'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('tuition_lines'));
    }

    #[Test]
    public function school_settings_supply_currency_minimum_and_reminders_without_new_masjid_columns(): void
    {
        $x = $this->setupLedger();
        $settings = \App\Models\OrganisationBillingSetting::firstOrFail();
        $settings->update(['minimum_payment_minor' => 300, 'remind_before_days' => 3]);
        $this->travelTo(now()->parse('2026-10-06 16:00:00 UTC'));
        $this->assertSame(1, app(TuitionReminders::class)->queue($x['school']));
        $x['ledger']->office($x['account'], 'charge', 1000, '2026-10-01', 'Practice', $x['actor']->id);
        $this->family($x['parent']); $url = "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.minimum_payment_minor', 300);
        $this->postJson($url.'/checkout', ['amount_minor' => 200])->assertUnprocessable();
    }

    #[Test]
    public function prepaid_credit_advances_next_due_and_the_normal_next_due_read_has_bounded_queries(): void
    {
        $x = $this->setupLedger(); $this->travelTo(now()->parse('2026-10-06 16:00:00 UTC'));
        $x['ledger']->office($x['account'], 'credit', 12345, '2026-10-01', 'Practice prepaid credit', $x['actor']->id);
        $this->family($x['parent']); DB::enableQueryLog(); DB::flushQueryLog();
        $response = $this->getJson("/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}");
        $queries = count(DB::getQueryLog()); DB::disableQueryLog();
        file_put_contents('/tmp/tuition-next-due-query-count.txt', (string) $queries);
        $response->assertOk()->assertJsonPath('data.next_due_on', '2026-11-09')->assertJsonPath('data.next_due_minor', 12345);
        $this->assertLessThan(60, $queries);
        $x['account']->update(['remind_before_days'=>3]); app(TenantContext::class)->forgetTenant();
        $this->assertSame(0, app(TuitionReminders::class)->queue($x['school']));
    }

    #[Test]
    public function delayed_verified_stripe_events_use_the_event_day_in_school_timezone(): void
    {
        $x = $this->setupLedger(); $this->checkout($x); $this->travelTo(now()->parse('2026-11-03 16:00:00 UTC'));
        $this->webhook('payment_intent.succeeded', ['id' => 'pi_practice', 'metadata' => ['tuition_checkout_uuid' => 'practice-checkout'], 'currency' => 'usd', 'amount_received' => 1000], 'evt_late', 'acct_practice', strtotime('2026-11-01 03:30:00 UTC'))->assertOk();
        $this->assertSame('2026-10-31', TuitionLine::firstOrFail()->effective_on->toDateString());
    }

    #[Test]
    public function replacing_a_term_row_does_not_make_its_charged_period_new_again(): void
    {
        $x = $this->setupLedger(); $x['schedule']->update(['cadence' => 'term']);
        $data = ['masjid_id' => $x['school']->id, 'school_year_id' => $x['year']->id, 'name' => 'Practice term', 'position' => 1, 'starts_on' => '2026-10-09', 'ends_on' => '2026-12-20'];
        $term = SchoolTerm::create($data); $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC'));
        app(TuitionCharges::class)->post($x['school']); $term->delete(); SchoolTerm::create($data); app(TuitionCharges::class)->post($x['school']);
        $this->assertSame(1, TuitionLine::where('kind', 'charge')->count());
    }

    #[Test]
    public function hidden_off_catalogues_and_an_off_noop_leave_no_override_or_audit(): void
    {
        $school = $this->makeMasjid(); $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->create(['type'=>'SuperAdmin', 'phone'=>'+15555550108', 'name'=>'Practice Operator', 'email'=>'operator@example.invalid']), ['staff']);
        foreach (["/api/admin/masjids/{$school->id}/capabilities", '/api/admin/studio/catalogue?org_type=school'] as $url) $this->assertStringNotContainsString('tuition_ledger', $this->getJson($url)->assertOk()->getContent());
        $this->patchJson("/api/admin/masjids/{$school->id}/capabilities/tuition_ledger", ['enabled'=>false])->assertOk();
        $this->assertArrayNotHasKey('tuition_ledger', $school->fresh()->capability_overrides ?? []);
        $this->assertDatabaseMissing('masjid_capability_changes', ['masjid_id'=>$school->id,'capability'=>'tuition_ledger']);
    }

    #[Test]
    public function office_can_create_class_only_and_grade_only_t006_plans_and_currency_is_persisted(): void
    {
        $x = $this->setupLedger(); $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $x['school']->forceFill(['user_id' => $x['actor']->id])->save(); \Laravel\Sanctum\Sanctum::actingAs($x['actor'], ['staff']);
        $url = "/api/admin/masjids/{$x['school']->id}/tuition";
        $payload = ['offering_id' => $x['plan']->offering_id, 'school_year_id' => $x['year']->id, 'label' => 'Practice plan', 'amount_minor' => 12345, 'cadence' => 'monthly', 'due_day' => 9, 'sibling_percent' => 10, 'group_ids' => [$x['class']->id], 'grades' => []];
        $response = $this->postJson($url.'/schedules', $payload)->assertOk();
        $this->assertFalse(FeePlan::findOrFail($response->json('data.fee_plan_id'))->is_active);
        $payload['group_ids'] = []; $payload['grades'] = ['Practice grade']; $this->postJson($url.'/schedules', $payload)->assertOk();
        $this->putJson($url.'/settings', ['currency'=>'cad','minimum_payment_minor'=>0])->assertUnprocessable();
        config(['services.stripe.currency'=>'cad']); $this->assertSame('usd', \App\Services\FamilyLedger\TuitionSettings::for($x['school']->id)->currency);
    }

    #[Test]
    public function a_guardian_reads_only_their_own_account_and_student_tokens_open_no_money_door(): void
    {
        $x = $this->setupLedger(); $other = $this->enrol($x['class']); $otherParent = $this->guardian($other);
        $account = $x['ledger']->open($x['school'], [$other->contact_id], [$otherParent->id], $x['actor']->id);
        $this->family($x['parent']); $base = "/api/family/masjids/{$x['school']->id}/tuition";
        $this->getJson($base.'/'.$account->id)->assertForbidden();
        $this->postJson($base.'/'.$account->id.'/checkout', ['amount_minor'=>1])->assertForbidden();
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.accounts')->assertJsonPath('data.accounts.0.id', $x['account']->id);
        $membership = GroupMembership::where('contact_id', $x['children'][0])->where('role', 'member')->firstOrFail();
        $token = $x['parent']->createStudentHandoffToken($membership->id)->plainTextToken;
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson($base)->assertForbidden();
    }

    #[Test]
    public function retrying_an_office_payment_with_its_action_key_records_once(): void
    {
        $x = $this->setupLedger(); $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $x['school']->forceFill(['user_id' => $x['actor']->id])->save(); \Laravel\Sanctum\Sanctum::actingAs($x['actor'], ['staff']);
        $url = "/api/admin/masjids/{$x['school']->id}/tuition/{$x['account']->id}/entries";
        $body = ['kind'=>'office_payment','amount_minor'=>100,'effective_on'=>'2026-10-02','reason'=>'Practice received','method'=>'cash','idempotency_key'=>'11111111-1111-4111-8111-111111111111'];
        $first = $this->postJson($url, $body)->assertOk(); $second = $this->postJson($url, $body)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id')); $this->assertSame(1, TuitionLine::count());
        $body['amount_minor']=101; $this->postJson($url, $body)->assertUnprocessable(); $this->assertSame(-100, $x['ledger']->balance($x['account']));
    }

    #[Test]
    public function the_writer_derives_tenant_and_account_and_rejects_non_integer_money(): void
    {
        $x=$this->setupLedger(); $other=$this->makeMasjid('b');
        $line=$x['ledger']->append($x['account'], ['masjid_id'=>$other->id,'account_id'=>999999,'kind'=>'charge','amount_minor'=>100,'effective_on'=>'2026-10-01','reason'=>'Practice','recorded_by_label'=>'Practice Clerk','source'=>'office','idempotency_key'=>'practice-writer-scope']);
        $this->assertSame($x['school']->id,$line->masjid_id); $this->assertSame($x['account']->id,$line->account_id);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $x['ledger']->append($x['account'], ['kind'=>'charge','amount_minor'=>'100']);
    }

    #[Test]
    public function tuition_metadata_cannot_hijack_an_existing_form_payment_event(): void
    {
        $x=$this->setupLedger(); $this->checkout($x);
        $this->mock(\App\Services\Stripe\FormResponsePaymentService::class)->shouldReceive('handleCheckoutCompleted')->once();
        $this->webhook('checkout.session.completed', ['id'=>'cs_practice','metadata'=>['tuition_checkout_uuid'=>'practice-checkout','form_response_uuid'=>'practice-form-response'],'payment_status'=>'paid','payment_intent'=>'pi_practice','amount_total'=>1000,'currency'=>'usd'], 'evt_legacy_priority')->assertOk();
        $this->assertSame(0,TuitionLine::count());
    }


    #[Test]
    public function descriptions_are_public_but_office_notes_and_legacy_void_reasons_never_reach_families(): void
    {
        $this->travelTo(now()->parse('2026-10-14 21:17:00 UTC'));
        $x = $this->setupLedger(); Mail::fake(); config(['queue.default' => 'database']);
        $charge = $x['ledger']->office($x['account'], 'charge', 2000, '2026-10-11', 'PRIVATE-CHARGE-NOTE', $x['actor']->id, description: 'October tuition');
        $payment = $x['ledger']->office($x['account'], 'office_payment', 500, '2026-10-14', 'PRIVATE-PAYMENT-NOTE', $x['actor']->id, 'cash', description: 'Payment received');
        $x['ledger']->void($payment, 'PRIVATE-VOID-NOTE', $x['actor']->id, 'Payment correction');
        // Simulate rows from before the description column existed. Do not reinterpret their notes as public copy.
        DB::table('tuition_lines')->where('id', $payment->id)->update(['description' => null]);
        $statement = app(TuitionStatement::class);
        $office = $statement->data($x['account'], '2026-10-01', '2026-10-31', true);
        $this->assertSame('PRIVATE-CHARGE-NOTE', $office['lines'][0]['note']);
        $this->assertSame('Oct 14, 2026, 5:17 PM', $office['lines'][0]['recorded_at']);
        $this->assertArrayNotHasKey('reason', $charge->toArray());
        $this->family($x['parent']); $base = "/api/family/masjids/{$x['school']->id}/tuition";
        foreach ([$base, $base.'/'.$x['account']->id.'?from=2026-10-01&to=2026-10-31'] as $url) {
            $json = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('PRIVATE-', $json);
            $this->assertStringNotContainsString('"note"', $json); $this->assertStringNotContainsString('"reason"', $json);
        }
        $public = $this->getJson($base.'/'.$x['account']->id.'?from=2026-10-01&to=2026-10-31')->json('data.statement');
        $this->assertSame(['October tuition', 'Payment', 'Payment correction'], array_column($public['lines'], 'description'));
        $html = $statement->html($x['account'], '2026-10-01', '2026-10-31');
        foreach (['October tuition', 'Payment, cash', 'Payment correction', 'Practice Guardian', $x['school']->name, $x['school']->email, $x['school']->phone, 'Oct 14, 2026'] as $text) $this->assertStringContainsString($text, $html);
        foreach (['PRIVATE-', 'office payment', '(entry ', '#'.$charge->id, 'This is a tuition statement.', '2026-10-14'] as $text) $this->assertStringNotContainsString($text, $html);
        $pdf = $this->get($base.'/'.$x['account']->id.'/statement?from=2026-10-01&to=2026-10-31')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertFamilyPdfHasNoStaffNote($pdf);
        file_put_contents('/tmp/tuition-walk-family.pdf', $pdf);
        app(TuitionReminders::class)->recipients($x['account'], 'statement', '2026-10-01', '2026-10-31');
        $row = TuitionMail::firstOrFail(); (new \App\Jobs\SendTuitionMail($row->id, $x['school']->id))->handle();
        Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, function ($mail) {
            $this->assertStringNotContainsString('PRIVATE-', $mail->render());
            $this->assertStringStartsWith('%PDF-', $mail->rawAttachments[0]['data']);
            $this->assertFamilyPdfHasNoStaffNote($mail->rawAttachments[0]['data']);
            file_put_contents('/tmp/tuition-walk-mailed.pdf', $mail->rawAttachments[0]['data']);
            return true;
        });
    }

    #[Test]
    public function reminder_speaks_to_families_with_overdue_and_coming_dates_currency_balance_and_link(): void
    {
        // UTC next day; school still Oct 14. Both reminder selection and wording must use that day.
        $this->travelTo(now()->parse('2026-10-15 01:17:00 UTC'));
        $x = $this->setupLedger(); Mail::fake(); config(['queue.default' => 'database', 'app.url' => 'https://portal.example.invalid/']);
        $x['account']->update(['remind_before_days' => 3, 'remind_after_days' => 3]);
        $x['ledger']->office($x['account'], 'charge', 3000, '2026-10-11', 'PRIVATE-OVERDUE-NOTE', $x['actor']->id);
        $x['ledger']->office($x['account'], 'charge', 2000, '2026-10-17', 'PRIVATE-FUTURE-NOTE', $x['actor']->id);
        $x['ledger']->office($x['account'], 'office_payment', 1000, '2026-10-14', 'PRIVATE-RECEIVED-NOTE', $x['actor']->id, 'zelle');
        $this->assertSame(1, app(TuitionReminders::class)->queue($x['school']));
        $row = TuitionMail::firstOrFail(); (new \App\Jobs\SendTuitionMail($row->id, $x['school']->id))->handle();
        Mail::assertSent(\App\Mail\AttendanceAbsenceMail::class, function ($mail) use ($x) {
            foreach (['$20.00 was due Oct 11, 2026.', '$20.00 is due Oct 17, 2026.', 'Current balance as of Oct 14, 2026: $20.00.', 'https://portal.example.invalid/family/'.$x['school']->id.'/tuition'] as $text) $this->assertStringContainsString($text, $mail->bodyText);
            $this->assertStringNotContainsString('PRIVATE-', $mail->render()); $this->assertStringNotContainsString('USD ', $mail->bodyText);
            return true;
        });
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertSame('¥123', TuitionStatement::money(123, 'jpy'));
        $this->assertStringContainsString('1.234', TuitionStatement::money(1234, 'kwd'));
    }

    #[Test]
    public function family_balance_ends_today_upcoming_is_separate_and_default_statement_starts_with_the_school_year(): void
    {
        $this->travelTo(now()->parse('2026-10-15 01:17:00 UTC')); $x = $this->setupLedger();
        $x['ledger']->office($x['account'], 'charge', 63500, '2026-10-11', 'PRIVATE', $x['actor']->id);
        $x['ledger']->office($x['account'], 'charge', 2000, '2026-10-17', 'PRIVATE', $x['actor']->id);
        $this->family($x['parent']); $url = "/api/family/masjids/{$x['school']->id}/tuition/{$x['account']->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.balance_minor', 63500)->assertJsonPath('data.statement.closing_minor', 63500)->assertJsonPath('data.default_from', '2026-08-24')->assertJsonPath('data.statement.from', '2026-08-24')->assertJsonPath('data.school_today', '2026-10-14')->assertJsonPath('data.next_due_on', '2026-10-17')->assertJsonPath('data.next_due_minor', 2000);
        $this->postJson($url.'/checkout', ['amount_minor' => 65500])->assertUnprocessable();
        $this->assertSame(65500, $x['ledger']->balance($x['account']));
        $this->mock(\App\Services\Stripe\RegistrationCheckoutService::class)->shouldReceive('createDirectCheckout')->once()->andReturn(['id' => 'cs_today', 'url' => 'https://checkout.stripe.com/practice']);
        $this->postJson($url.'/checkout', ['amount_minor' => 63500])->assertOk();
        $checkout = TuitionCheckout::firstOrFail();
        $this->assertSame(65500, $checkout->balance_minor);
        $this->webhook('checkout.session.completed', ['id' => 'cs_today', 'metadata' => ['tuition_checkout_uuid' => $checkout->uuid], 'payment_status' => 'paid', 'currency' => 'usd', 'amount_total' => 63500, 'payment_intent' => 'pi_today'], 'evt_today', created: now()->timestamp)->assertOk();
        $this->assertFalse(TuitionLine::where('kind', 'card_payment')->firstOrFail()->needs_review);
        $this->getJson($url)->assertOk()->assertJsonPath('data.balance_minor', 0)->assertJsonPath('data.next_due_minor', 2000)->assertJsonPath('data.statement.lines.1.method_label', 'Card');
        $x['year']->update(['last_day' => '2026-09-30']);
        $this->assertSame('2026-10-11', TuitionStatement::defaultFrom($x['school']->id, $x['account']));
    }

    #[Test]
    public function office_form_fields_csv_units_family_child_method_labels_and_mail_times_round_trip(): void
    {
        $this->travelTo(now()->parse('2026-10-14 21:17:00 UTC')); $x = $this->setupLedger();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class); $x['school']->forceFill(['user_id' => $x['actor']->id])->save();
        \Laravel\Sanctum\Sanctum::actingAs($x['actor'], ['staff']); $url = "/api/admin/masjids/{$x['school']->id}/tuition";
        $body = ['idempotency_key' => '44444444-4444-4444-8444-444444444444', 'kind' => 'office_payment', 'amount_minor' => '2000', 'effective_on' => '2026-10-14', 'note' => 'PRIVATE-OFFICE-NOTE', 'description' => 'October payment', 'method' => 'cash_app', 'child_contact_id' => (string) $x['children'][0]];
        $id = $this->post($url.'/'.$x['account']->id.'/entries', $body)->assertOk()->json('data.id');
        $this->post($url.'/'.$x['account']->id.'/entries/'.$id.'/void', ['note' => 'PRIVATE-CORRECTION', 'description' => 'Payment corrected'])->assertOk();
        TuitionMail::create(['masjid_id' => $x['school']->id, 'account_id' => $x['account']->id, 'guardian_contact_id' => $x['parent']->id, 'mail_key' => 'walk-times', 'kind' => 'statement', 'status' => 'sent', 'from_on' => '2026-10-01', 'to_on' => '2026-10-31', 'sent_at' => now()]);
        $this->getJson($url.'/'.$x['account']->id.'?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('data.statement.lines.0.note', 'PRIVATE-OFFICE-NOTE')->assertJsonPath('data.statement.lines.0.recorded_at', 'Oct 14, 2026, 5:17 PM')->assertJsonPath('data.mails.0.created_at', 'Oct 14, 2026, 5:17 PM')->assertJsonPath('data.mails.0.sent_at', 'Oct 14, 2026, 5:17 PM');
        $options = $this->getJson($url.'/options')->assertOk();
        foreach (['cash_app' => 'Cash App', 'zelle' => 'Zelle', 'check' => 'Check', 'bank_transfer' => 'Bank transfer'] as $key => $label) $options->assertJsonPath('data.method_labels.'.$key, $label);
        $options->assertJsonPath('data.default_from', '2026-08-24');
        $csv = $this->get($url.'/export?from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('Practice Guardian', $row['Family']); $this->assertSame('Sample Student', $row['Child']);
        $this->assertSame('USD', $row['Currency']); $this->assertSame('-20.00', $row['Amount']); $this->assertSame('0.00', $row['Closing balance']);
        $this->assertSame('Cash App', $row['Method']); $this->assertSame('October payment', $row['Description']);
    }

    #[Test]
    public function existing_schedule_rows_get_plan_descriptions_and_the_statement_shows_no_staff_reason(): void
    {
        $this->travelTo(now()->parse('2026-10-09 16:00:00 UTC')); $x = $this->setupLedger(); app(TuitionCharges::class)->post($x['school']);
        $this->assertSame('Practice tuition', TuitionLine::firstOrFail()->description);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('tuition_lines', 'description'));
        $data = app(TuitionStatement::class)->data($x['account'], '2026-10-01', '2026-10-31');
        $this->assertSame('Practice tuition', $data['lines'][0]['description']); $this->assertArrayNotHasKey('reason', $data['lines'][0]);
    }
    /** Inspect actual mPDF streams, including UTF-16 text; an uncompressed byte search misses private text. */
    private function assertFamilyPdfHasNoStaffNote(string $pdf): void
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $decoded = $pdf;
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content !== false) $decoded .= $content;
        }
        $this->assertStringContainsString(mb_convert_encoding('October tuition', 'UTF-16BE', 'UTF-8'), $decoded);
        $this->assertStringNotContainsString('PRIVATE-', $decoded);
        $this->assertStringNotContainsString(mb_convert_encoding('PRIVATE-', 'UTF-16BE', 'UTF-8'), $decoded);
    }
}
