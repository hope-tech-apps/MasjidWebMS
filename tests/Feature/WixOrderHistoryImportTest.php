<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\EmailSuppression;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Fund;
use App\Models\HistoricalImportRecord;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\RegistrationAdjustment;
use App\Models\RegistrationPayment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WritesWixOrderExport;
use Tests\TestCase;

/**
 * `crm:import-wix-orders` — MEC's Wix store and Wix Events orders as donation
 * and registration HISTORY (DECISIONS.md 2026-09-25, "Wix order history").
 *
 * What is pinned, in the order the owner's instruction lists it:
 *   - dry run by default, printing counts and money and never a buyer;
 *   - giving → donations, tickets → registrations on unpublished offerings,
 *     everything else → the order record only;
 *   - every row marked `historical` with the provider and the Wix order number,
 *     never a Stripe id;
 *   - nothing sent: no receipt, mail, notification or queued job;
 *   - contacts linked by email, or created with the address held from broadcasts;
 *   - idempotent; and undo removes exactly what the batch created.
 *
 * The export is SYNTHETIC (tests/Support/WritesWixOrderExport.php) in the real
 * export's shape. Sqlite in memory, per tests/CLAUDE.md.
 */
class WixOrderHistoryImportTest extends TestCase
{
    use RefreshDatabase;
    use WritesWixOrderExport;

    private Masjid $org;
    private string $dir;
    private Contact $amina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->org = $this->makeOrgForWixImport();

        // Already in the directory, under a differently-cased address.
        $this->amina = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Amina', 'last_name' => 'Test',
            'email' => 'amina@example.test',
        ]);

        // Unsubscribed once and then re-subscribed from her own link: the
        // released row is her request to be mailed, and the import must keep it.
        EmailSuppression::create([
            'masjid_id' => $this->org->id,
            'email_normalized' => 'released@example.test',
            'reason' => EmailSuppression::REASON_UNSUBSCRIBE_LINK,
            'suppressed_at' => Carbon::parse('2025-01-01'),
            'released_at' => Carbon::parse('2025-02-01'),
        ]);

        $this->dir = $this->writeWixExport();
    }

    protected function tearDown(): void
    {
        $this->removeWixExports();
        parent::tearDown();
    }

    // ------------------------------------------------------------ dry run

    #[Test]
    public function a_dry_run_writes_nothing_and_prints_no_buyer(): void
    {
        $this->artisan('crm:import-wix-orders', ['export' => $this->dir, '--masjid' => $this->org->id])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('$250.25')
            ->doesntExpectOutputToContain('amina@example.test')
            ->doesntExpectOutputToContain('Yusuf')
            ->doesntExpectOutputToContain('Invented Way')
            ->assertExitCode(0);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
        $this->assertSame(0, Donation::withoutMasjidScope()->count());
        $this->assertSame(0, Registration::withoutMasjidScope()->count());
        $this->assertSame(1, Contact::withoutMasjidScope()->count(), 'a dry run must not create a contact');
        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->count());
        $this->assertSame(0, Fund::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ mapping

    #[Test]
    public function giving_lines_become_historical_donations_marked_with_the_order_and_provider(): void
    {
        $this->assertSame(0, $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']));

        $gifts = Donation::withoutMasjidScope()->orderBy('charged_amount')->get();
        $this->assertCount(2, $gifts);

        [$iftar, $zakat] = [$gifts[0], $gifts[1]];
        $this->assertSame(1000, $iftar->charged_amount);
        $this->assertSame(3900, $zakat->charged_amount);

        $order = HistoricalOrder::withoutMasjidScope()->where('order_number', '10001')->firstOrFail();

        foreach ($gifts as $gift) {
            $this->assertSame(Donation::SOURCE_HISTORICAL, $gift->source);
            $this->assertSame(HistoricalOrder::PROVIDER_WIX, $gift->payment_method);
            $this->assertSame($order->id, (int) $gift->historical_order_id);
            $this->assertSame($this->amina->id, (int) $gift->contact_id, 'linked by email whatever its case');
            $this->assertSame('succeeded', $gift->status);
            $this->assertNull($gift->stripe_payment_intent_id);
            $this->assertNull($gift->stripe_checkout_session_id);
            $this->assertNull($gift->stripe_charge_id);
            $this->assertStringContainsString('Wix order #10001', $gift->note);
            $this->assertStringContainsString('not through Manara', $gift->note);
            // 02:05 UTC on the 29th was the 28th in the organisation's timezone.
            $this->assertSame('2021-04-28', $gift->donated_at->toDateString());
            $this->assertSame('2021-04-29 02:05:00', $gift->created_at->utc()->format('Y-m-d H:i:s'));
        }

        $fitr = Fund::withoutMasjidScope()->where('name', 'Zakat-ul-Fitr')->firstOrFail();
        $this->assertSame($fitr->id, $zakat->fund_id);
        $this->assertSame('fitra', $fitr->type);
        $this->assertFalse($fitr->is_active, 'a history fund is never offered to a donor');
        $this->assertFalse($fitr->receiptable);
        $this->assertFalse($zakat->is_zakat, 'Zakat-ul-Fitr is not zakat al-mal; the fund type decides nothing more');
    }

    #[Test]
    public function tickets_become_settled_registrations_on_unpublished_offerings(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $festival = Offering::withoutMasjidScope()->where('name', 'Fall Festival 2021')->firstOrFail();
        $this->assertFalse($festival->is_active, 'a historical offering is never published');
        $this->assertSame(0, (int) $festival->registration_count, 'history never touches the seat counter');
        $this->assertSame(Form::where('slug', 'wix-order-history')->value('id'), $festival->intake_form_id);
        $this->assertFalse((bool) Form::where('slug', 'wix-order-history')->value('is_active'));

        $registration = Registration::withoutMasjidScope()->where('offering_id', $festival->id)->firstOrFail();
        $this->assertSame(Registration::SOURCE_HISTORICAL, $registration->source);
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Registration::PAYMENT_PAID, $registration->payment_status);
        $this->assertSame(2000, $registration->list_total_minor, 'the food tickets are not part of the seat');
        $this->assertNull($registration->stripe_checkout_session_id);
        $this->assertNull($registration->idempotency_key);
        $this->assertNull($registration->checkout_expires_at);
        $this->assertStringContainsString('Wix order #10002', (string) $registration->staff_note);

        $payment = RegistrationPayment::withoutMasjidScope()->where('registration_id', $registration->id)->sole();
        $this->assertSame(2000, $payment->amount_minor);
        $this->assertSame(RegistrationPayment::STATUS_SUCCEEDED, $payment->status);
        $this->assertNull($payment->stripe_payment_intent_id);
        $this->assertSame('2021-11-07 16:30:00', $payment->paid_at->utc()->format('Y-m-d H:i:s'));

        $plan = FeePlan::withoutMasjidScope()->findOrFail($registration->fee_plan_id);
        $this->assertFalse($plan->is_active);
        $this->assertSame(1000, $plan->amount_minor);
    }

    #[Test]
    public function a_wix_coupon_is_recorded_as_a_code_adjustment_and_the_payment_is_what_was_paid(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $bazaar = Offering::withoutMasjidScope()->where('name', 'Hajj Simulation & Eid Adha Bazaar 2026')->firstOrFail();
        $registration = Registration::withoutMasjidScope()->where('offering_id', $bazaar->id)->sole();

        $this->assertSame(6000, $registration->list_total_minor);
        $this->assertSame(4500, $registration->adjusted_total_minor);

        $adjustment = RegistrationAdjustment::withoutMasjidScope()->where('registration_id', $registration->id)->sole();
        $this->assertSame(RegistrationAdjustment::KIND_CODE, $adjustment->kind);
        $this->assertSame(1500, $adjustment->amount_minor);
        $this->assertSame('Wix coupon: Intellicore', $adjustment->reason);

        $this->assertSame(4500, RegistrationPayment::withoutMasjidScope()->where('registration_id', $registration->id)->sum('amount_minor'));
    }

    #[Test]
    public function a_wix_events_order_carries_the_fee_the_buyer_paid_and_an_abandoned_checkout_is_cancelled(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $festival = Offering::withoutMasjidScope()->where('name', 'Fall Festival (7th Annual) 2024')->firstOrFail();
        $this->assertStringContainsString('October 12, 2024', (string) $festival->description);

        $paid = Registration::withoutMasjidScope()->where('offering_id', $festival->id)
            ->where('status', Registration::STATUS_CONFIRMED)->sole();
        $this->assertSame(5000, $paid->list_total_minor);
        $this->assertSame(5125, RegistrationPayment::withoutMasjidScope()->where('registration_id', $paid->id)->sum('amount_minor'));
        $this->assertStringContainsString('PayPal', (string) $paid->staff_note);

        $order = HistoricalOrder::withoutMasjidScope()->where('order_number', 'EVT-1')->sole();
        $this->assertSame(HistoricalOrder::PROVIDER_PAYPAL, $order->provider);
        $this->assertSame(125, $order->fee_minor);

        $abandoned = Registration::withoutMasjidScope()->where('offering_id', $festival->id)
            ->where('status', Registration::STATUS_CANCELLED)->sole();
        $this->assertSame(Registration::PAYMENT_CANCELED, $abandoned->payment_status);
        $this->assertSame(0, RegistrationPayment::withoutMasjidScope()->where('registration_id', $abandoned->id)->count());
    }

    #[Test]
    public function food_tickets_and_merchandise_stay_on_the_order_and_nowhere_else(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $rug = HistoricalOrder::withoutMasjidScope()->where('order_number', '10004')->sole();
        $this->assertSame(6000, $rug->total_minor);
        $this->assertSame(HistoricalOrder::RECORDED_AS_ORDER_ONLY, $rug->lines[0]['recorded_as']);
        $this->assertSame(0, Donation::withoutMasjidScope()->where('historical_order_id', $rug->id)->count());
        $this->assertSame(0, Registration::withoutMasjidScope()->where('historical_order_id', $rug->id)->count());

        $mixed = HistoricalOrder::withoutMasjidScope()->where('order_number', '10002')->sole();
        $this->assertSame(
            [HistoricalOrder::RECORDED_AS_REGISTRATION, HistoricalOrder::RECORDED_AS_ORDER_ONLY],
            array_column($mixed->lines, 'recorded_as'),
        );
        $this->assertSame(4500, $mixed->total_minor);
    }

    #[Test]
    public function no_buyer_detail_beyond_the_contact_link_is_stored(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $stored = json_encode([
            HistoricalOrder::withoutMasjidScope()->get()->toArray(),
            Donation::withoutMasjidScope()->pluck('note'),
            Registration::withoutMasjidScope()->pluck('staff_note'),
        ]);

        $this->assertStringNotContainsString(self::WIX_SECRET_ANSWER, $stored);
        $this->assertStringNotContainsString('Invented Way', $stored);
        $this->assertStringNotContainsString('555-0100', $stored);
        $this->assertStringNotContainsString('please call first', $stored);
        $this->assertStringNotContainsString('@example.test', $stored);
    }

    // ------------------------------------------------------------ no side effects

    #[Test]
    public function importing_sends_nothing_and_issues_no_receipt(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(2, Donation::withoutMasjidScope()->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, DonationReceipt::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ contacts

    #[Test]
    public function an_unknown_buyer_becomes_a_contact_whose_address_is_held_from_broadcasts(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $yusuf = Contact::withoutMasjidScope()->where('email', 'yusuf.new@example.test')->sole();
        $this->assertSame('b1', $yusuf->import_batch);
        $this->assertSame(2, HistoricalOrder::withoutMasjidScope()->where('contact_id', $yusuf->id)->count(),
            'two orders, one contact');

        $hold = EmailSuppression::withoutMasjidScope()->where('email_normalized', 'yusuf.new@example.test')->sole();
        $this->assertSame(EmailSuppression::REASON_ORDER_HISTORY_HOLD, $hold->reason);
        $this->assertNull($hold->released_at);
        $this->assertNotNull($yusuf->fresh()->email_opted_out_at, 'the directory mirror shows the hold');

        // Her own "resume" stands: no new hold, her row untouched.
        $released = EmailSuppression::withoutMasjidScope()->where('email_normalized', 'released@example.test')->sole();
        $this->assertNotNull($released->released_at);
        $this->assertSame(EmailSuppression::REASON_UNSUBSCRIBE_LINK, $released->reason);

        // A contact the directory already had gets no hold.
        $this->assertSame(0, EmailSuppression::withoutMasjidScope()->where('email_normalized', 'amina@example.test')->count());
    }

    // ------------------------------------------------------------ idempotent

    #[Test]
    public function running_the_same_export_again_imports_nothing_twice(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);
        $before = $this->counts();

        $this->artisan('crm:import-wix-orders', ['export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true, '--batch' => 'b2'])
            ->expectsOutputToContain('Already imported (skipped): 6')
            ->assertExitCode(0);

        $this->assertSame($before, $this->counts());
    }

    // ------------------------------------------------------------ refusals

    #[Test]
    public function an_unrecognised_product_blocks_the_whole_import(): void
    {
        $rows = $this->wixStoreRows();
        $rows[] = $this->storeRow(10005, '2026-03-01T12:00', 'x@example.test', 'X', 'Y', '1x Mystery Box @5', '5', '0', '');
        $dir = $this->writeWixExport($rows);

        $this->artisan('crm:import-wix-orders', ['export' => $dir, '--masjid' => $this->org->id, '--execute' => true])
            ->expectsOutputToContain('Mystery Box')
            ->assertExitCode(1);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
        $this->assertSame(0, Donation::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_order_whose_lines_do_not_add_up_blocks_the_whole_import(): void
    {
        $rows = $this->wixStoreRows();
        $rows[0][13] = '48';   // total no longer equals 3 × 13 + 10
        $dir = $this->writeWixExport($rows);

        $this->artisan('crm:import-wix-orders', ['export' => $dir, '--masjid' => $this->org->id, '--execute' => true])
            ->expectsOutputToContain('Store order #10001')
            ->assertExitCode(1);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_importer_refuses_to_run_for_an_organisation_the_tenant_is_not_bound_to(): void
    {
        $other = $this->makeOrgForWixImport();
        app(TenantContext::class)->set($other->id);

        $this->expectException(\LogicException::class);
        app(\App\Services\Crm\WixOrderHistoryImporter::class)->run($this->org, [], [], 'b1', true);
    }

    // ------------------------------------------------------------ undo

    #[Test]
    public function undo_removes_exactly_what_the_batch_created(): void
    {
        $generalFund = Fund::factory()->create(['masjid_id' => $this->org->id, 'name' => 'General Donation']);
        $baseline = $this->counts();

        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);
        $this->assertNotSame($baseline, $this->counts());

        $this->assertSame(0, $this->importWix($this->org, '', ['--undo' => 'b1', '--execute' => true]));

        $this->assertSame($baseline, $this->counts());
        $this->assertNotNull($this->amina->fresh(), 'a contact that was there before is never touched');
        $this->assertNotNull($generalFund->fresh(), 'a fund that was there before is never touched');
        $this->assertNotNull(
            EmailSuppression::withoutMasjidScope()->where('email_normalized', 'released@example.test')->first()?->released_at,
        );
    }

    #[Test]
    public function undo_keeps_a_created_contact_somebody_has_changed_since(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $yusuf = Contact::withoutMasjidScope()->where('email', 'yusuf.new@example.test')->sole();
        $this->travel(2)->minutes();
        $yusuf->forceFill(['phone' => '555-0142'])->save();   // the contact import filled it in

        $this->importWix($this->org, '', ['--undo' => 'b1', '--execute' => true]);

        $this->assertNotNull($yusuf->fresh(), 'somebody built on this contact; undo keeps it');
        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->where('email_normalized', 'yusuf.new@example.test')->count(),
            'and its hold, while a live contact still holds the address');
        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
        $this->assertNull(Contact::withoutMasjidScope()->where('email', 'maryam@example.test')->first(),
            'an untouched contact of the batch still goes');
        $this->assertSame(0, EmailSuppression::withoutMasjidScope()->where('email_normalized', 'maryam@example.test')->count());
    }

    #[Test]
    public function an_undo_dry_run_removes_nothing(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);
        $before = $this->counts();

        $this->importWix($this->org, '', ['--undo' => 'b1']);

        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function the_new_columns_have_the_types_the_importer_relies_on(): void
    {
        $this->assertSame('integer', Schema::getColumnType('donations', 'historical_order_id'));
        $this->assertSame('integer', Schema::getColumnType('registrations', 'historical_order_id'));
        $this->assertSame('varchar', Schema::getColumnType('historical_orders', 'order_number'));
        $this->assertSame('varchar', Schema::getColumnType('historical_orders', 'provider'));
        $this->assertSame('integer', Schema::getColumnType('historical_orders', 'total_minor'));
        $this->assertSame('datetime', Schema::getColumnType('historical_orders', 'ordered_at'));
        $this->assertSame('varchar', Schema::getColumnType('historical_import_records', 'record_type'));
    }

    /** Row counts of everything the import may write, for before/after comparison. */
    private function counts(): array
    {
        return [
            'orders' => HistoricalOrder::withoutMasjidScope()->count(),
            'records' => HistoricalImportRecord::withoutMasjidScope()->count(),
            'donations' => Donation::withoutMasjidScope()->count(),
            'registrations' => Registration::withoutMasjidScope()->count(),
            'payments' => RegistrationPayment::withoutMasjidScope()->count(),
            'adjustments' => RegistrationAdjustment::withoutMasjidScope()->count(),
            'offerings' => Offering::withoutMasjidScope()->withTrashed()->count(),
            'plans' => FeePlan::withoutMasjidScope()->count(),
            'forms' => Form::withTrashed()->count(),
            'funds' => Fund::withoutMasjidScope()->count(),
            'contacts' => Contact::withoutMasjidScope()->withTrashed()->count(),
            'suppressions' => EmailSuppression::withoutMasjidScope()->count(),
        ];
    }
}
