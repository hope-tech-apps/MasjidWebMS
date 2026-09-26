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
use App\Services\Crm\WixOrderHistoryImporter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // Already in the directory, under a differently-cased address than the
        // export's ('Amina@Example.TEST'): case must not matter on EITHER side.
        $this->amina = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Amina', 'last_name' => 'Test',
            'email' => 'AMINA@example.Test',
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
        $this->artisan('crm:import-wix-orders', ['export' => $this->dir, '--masjid' => $this->org->id, '--without-contact-import' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('$250.25')
            ->doesntExpectOutputToContain('amina@example.test')
            ->doesntExpectOutputToContain('AMINA@example.Test')
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

    // ------------------------------------------------------------ contacts first

    #[Test]
    public function buyers_are_not_created_held_before_the_wix_contact_import_has_run(): void
    {
        // The order-first sequence: nothing from the contact import yet, so a
        // held contact made now would never be released by it.
        $this->artisan('crm:import-wix-orders', [
            'export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true, '--batch' => 'b1',
        ])
            ->expectsOutputToContain('Wix contact import has run for this organisation: NO')
            ->expectsOutputToContain('Run wix:import-contacts first, or pass --without-contact-import')
            ->assertExitCode(1);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
        $this->assertSame(1, Contact::withoutMasjidScope()->count(), 'no held contact was created');
        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->count(), 'and no hold was written');
    }

    #[Test]
    public function once_the_wix_contact_import_has_run_the_rest_of_the_buyers_are_created_held(): void
    {
        $this->markWixContactImportRan($this->org, $this->amina->id);

        $this->artisan('crm:import-wix-orders', [
            'export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true, '--batch' => 'b1',
        ])
            ->expectsOutputToContain('Wix contact import has run for this organisation: yes')
            ->assertExitCode(0);

        $yusuf = Contact::withoutMasjidScope()->where('email', 'yusuf.new@example.test')->sole();
        $this->assertSame(EmailSuppression::REASON_ORDER_HISTORY_HOLD,
            EmailSuppression::withoutMasjidScope()->where('email_normalized', 'yusuf.new@example.test')->value('reason'),
            'a buyer the contact import did not bring over is held: Wix gave no consent for them');
        $this->assertSame(2, HistoricalOrder::withoutMasjidScope()->where('contact_id', $yusuf->id)->count());
    }

    // ------------------------------------------------------------ Wix Events items

    #[Test]
    public function a_wix_events_food_purchase_is_kept_on_the_order_and_is_not_a_seat(): void
    {
        $orders = $this->wixEventOrders();
        $orders[] = [
            'no' => 'EVT-3', 'ev' => 'ev-fall-2024', 'cid' => 'c3', 'cr' => '2024-10-03T12:00:00.000Z',
            'fn' => 'Maryam', 'ln' => 'Example', 'em' => 'maryam@example.test',
            'st' => 'PAID', 'conf' => true, 'meth' => 'creditCard', 'ch' => 'ONLINE', 'qty' => 1,
            'tot' => '5.13', 'fci' => false, 'form' => [],
            'inv' => [[['Food Purchase', 1, '5.00']], '5.00', '5.13',
                [['WIX_FEE', 'FEE_ADDED_AT_CHECKOUT', '0.13']], null],
            'tk' => [],
        ];

        // Exit 0 also proves the order reconciles: its $0.13 Wix fee, with no
        // seat to ride on, is counted with the order-only money.
        $this->assertSame(0, $this->importWix($this->org, $this->writeWixExport(null, $orders), ['--execute' => true, '--batch' => 'b1']));

        $food = HistoricalOrder::withoutMasjidScope()->where('order_number', 'EVT-3')->sole();
        $this->assertSame([HistoricalOrder::RECORDED_AS_ORDER_ONLY], array_column($food->lines, 'recorded_as'));
        $this->assertSame(513, $food->total_minor);
        $this->assertSame(0, Registration::withoutMasjidScope()->where('historical_order_id', $food->id)->count(),
            'a food purchase is not a seat');
        $this->assertSame(0, Donation::withoutMasjidScope()->where('historical_order_id', $food->id)->count());
    }

    #[Test]
    public function an_unrecognised_wix_events_item_blocks_the_whole_import(): void
    {
        $orders = $this->wixEventOrders();
        $orders[0]['inv'][0][0][0] = 'Face Painting Pass';

        $this->artisan('crm:import-wix-orders', [
            'export' => $this->writeWixExport(null, $orders), '--masjid' => $this->org->id,
            '--execute' => true, '--without-contact-import' => true,
        ])
            ->expectsOutputToContain('Unrecognised product "Face Painting Pass"')
            ->assertExitCode(1);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
        $this->assertSame(0, Registration::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ linking

    #[Test]
    public function several_contacts_sharing_an_address_link_the_oldest(): void
    {
        $older = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Maryam', 'last_name' => 'First', 'email' => 'Maryam@Example.test',
        ]);
        $newer = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Maryam', 'last_name' => 'Second', 'email' => 'maryam@example.test',
        ]);
        $this->assertLessThan($newer->id, $older->id, 'the premise: the first one made has the lower id');

        $this->artisan('crm:import-wix-orders', [
            'export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true,
            '--batch' => 'b1', '--without-contact-import' => true,
        ])
            ->expectsOutputToContain('1 addresses shared by several contacts')
            ->assertExitCode(0);

        $this->assertSame(
            [$older->id],
            HistoricalOrder::withoutMasjidScope()->whereIn('order_number', ['EVT-1', 'EVT-2'])->pluck('contact_id')->unique()->values()->all(),
        );
        $this->assertSame(2, Contact::withoutMasjidScope()->whereRaw('LOWER(email) = ?', ['maryam@example.test'])->count(),
            'no third Maryam');
    }

    #[Test]
    public function a_buyer_whose_only_contact_was_deleted_is_linked_to_it_and_not_recreated(): void
    {
        $deleted = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Maryam', 'last_name' => 'Removed', 'email' => 'maryam@example.test',
        ]);
        $deleted->delete();

        $this->artisan('crm:import-wix-orders', [
            'export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true,
            '--batch' => 'b1', '--without-contact-import' => true,
        ])
            ->expectsOutputToContain('1 linked to a contact deleted in Manara (left deleted)')
            ->assertExitCode(0);

        $this->assertSame(1, Contact::withoutMasjidScope()->withTrashed()->where('email', 'maryam@example.test')->count(),
            'the person the office deleted is not re-created');
        $this->assertTrue($deleted->fresh()->trashed(), 'nor restored');
        $this->assertSame(
            [$deleted->id],
            HistoricalOrder::withoutMasjidScope()->whereIn('order_number', ['EVT-1', 'EVT-2'])->pluck('contact_id')->unique()->values()->all(),
        );
        $this->assertSame(0, EmailSuppression::withoutMasjidScope()->where('email_normalized', 'maryam@example.test')->count());
    }

    // ------------------------------------------------------------ scaffolding

    #[Test]
    public function an_existing_fund_of_the_same_name_is_reused_not_duplicated(): void
    {
        $fitr = Fund::factory()->create([
            'masjid_id' => $this->org->id, 'name' => 'Zakat-ul-Fitr', 'type' => 'fitra', 'is_active' => true,
        ]);

        app(TenantContext::class)->set($this->org->id);
        $export = \App\Support\WixOrderExport::fromDirectory($this->dir);
        $plan = app(WixOrderHistoryImporter::class)->run($this->org, $export['orders'], $export['problems'], 'b0', false, true);
        app(TenantContext::class)->forgetTenant();
        $this->assertSame(['Iftar'], $plan['funds_to_create']);

        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(1, Fund::withoutMasjidScope()->where('name', 'Zakat-ul-Fitr')->count());
        $zakat = Donation::withoutMasjidScope()->where('source', Donation::SOURCE_HISTORICAL)->where('charged_amount', 3900)->sole();
        $this->assertSame($fitr->id, $zakat->fund_id);
    }

    #[Test]
    public function the_fee_plan_carries_the_lowest_ticket_price_any_buyer_paid(): void
    {
        $rows = $this->wixStoreRows();
        $rows[] = $this->storeRow(10005, '2021-12-01T12:00', 'amina@example.test', 'Amina', 'Test',
            '1x Fall Festival Ticket @8', '8', '0', '');

        $this->importWix($this->org, $this->writeWixExport($rows), ['--execute' => true, '--batch' => 'b1']);

        $festival = Offering::withoutMasjidScope()->where('name', 'Fall Festival 2021')->firstOrFail();
        $this->assertSame(800, FeePlan::withoutMasjidScope()->where('offering_id', $festival->id)->sole()->amount_minor);
    }

    #[Test]
    public function an_offering_slug_this_import_did_not_create_blocks_the_whole_import(): void
    {
        Offering::factory()->forMasjid($this->org)->create(['slug' => 'wix-fall-festival-2021', 'name' => 'Our own fall festival']);

        $this->artisan('crm:import-wix-orders', [
            'export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true, '--without-contact-import' => true,
        ])
            ->expectsOutputToContain('The offering slug wix-fall-festival-2021 is already used by an offering this import did not create.')
            ->assertExitCode(1);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_form_slug_this_import_did_not_create_blocks_the_whole_import(): void
    {
        Form::factory()->create(['masjid_id' => $this->org->id, 'slug' => 'wix-order-history', 'name' => 'Our own form']);

        $this->artisan('crm:import-wix-orders', [
            'export' => $this->dir, '--masjid' => $this->org->id, '--execute' => true, '--without-contact-import' => true,
        ])
            ->expectsOutputToContain('The form slug wix-order-history is already used by a form this import did not create.')
            ->assertExitCode(1);

        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ undo: what stays

    #[Test]
    public function undo_keeps_an_imported_contact_that_records_outside_the_batch_now_point_at(): void
    {
        // Frozen, so the contact's updated_at cannot move: it must be kept for
        // being USED, not for having changed.
        $this->freezeTime();
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);
        $yusuf = Contact::withoutMasjidScope()->where('email', 'yusuf.new@example.test')->sole();

        $fund = Fund::factory()->create(['masjid_id' => $this->org->id, 'name' => 'Building']);
        $gift = Donation::factory()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $fund->id, 'contact_id' => $yusuf->id,
            'source' => Donation::SOURCE_OFFLINE, 'payment_method' => 'cash', 'status' => 'succeeded',
        ]);
        $offering = Offering::factory()->forMasjid($this->org)->create();
        $seat = Registration::factory()->create([
            'masjid_id' => $this->org->id, 'offering_id' => $offering->id, 'contact_id' => $yusuf->id,
        ]);

        $summary = $this->undoAsService('b1');

        $this->assertNotNull($yusuf->fresh(), 'somebody gave and registered as this contact since; undo keeps it');
        $this->assertSame($yusuf->id, (int) $gift->fresh()->contact_id);
        $this->assertSame($yusuf->id, (int) $seat->fresh()->contact_id);
        $this->assertSame(1, $summary['kept'][HistoricalImportRecord::TYPE_CONTACT] ?? 0);
        $this->assertArrayHasKey('in use by records outside this batch', $summary['kept_reasons']);
        $this->assertArrayNotHasKey('changed since the import', $summary['kept_reasons']);
    }

    #[Test]
    public function undo_leaves_a_hold_that_has_since_become_the_persons_own_unsubscribe(): void
    {
        $this->freezeTime();
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        // The person later used the unsubscribe link: EmailSuppressionService
        // rewrites the reason on the row the import recorded.
        DB::table('email_suppressions')->where('email_normalized', 'maryam@example.test')
            ->update(['reason' => EmailSuppression::REASON_UNSUBSCRIBE_LINK]);

        $this->undoAsService('b1');

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->where('email', 'maryam@example.test')->first(),
            'the premise: the contact the batch made went');
        $row = EmailSuppression::withoutMasjidScope()->where('email_normalized', 'maryam@example.test')->sole();
        $this->assertSame(EmailSuppression::REASON_UNSUBSCRIBE_LINK, $row->reason, 'her opt-out stands');
        $this->assertNull($row->released_at);
    }

    #[Test]
    public function undo_leaves_a_hold_that_has_since_been_released(): void
    {
        $this->freezeTime();
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        DB::table('email_suppressions')->where('email_normalized', 'maryam@example.test')
            ->update(['released_at' => '2026-01-02 03:04:05']);

        $this->undoAsService('b1');

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->where('email', 'maryam@example.test')->first(),
            'the premise: the contact the batch made went');
        $row = EmailSuppression::withoutMasjidScope()->where('email_normalized', 'maryam@example.test')->sole();
        $this->assertSame(EmailSuppression::REASON_ORDER_HISTORY_HOLD, $row->reason);
        $this->assertSame('2026-01-02 03:04:05', $row->released_at?->format('Y-m-d H:i:s'), 'the release is somebody\'s decision; undo keeps it');
    }

    #[Test]
    public function undo_keeps_the_hold_while_a_deleted_imported_contact_still_holds_the_address(): void
    {
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);
        $yusuf = Contact::withoutMasjidScope()->where('email', 'yusuf.new@example.test')->sole();

        $this->travel(2)->minutes();
        $yusuf->delete();   // soft: it can come back

        $this->importWix($this->org, '', ['--undo' => 'b1', '--execute' => true]);

        $this->assertNotNull(Contact::withoutMasjidScope()->withTrashed()->find($yusuf->id), 'kept: changed since the import');
        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->where('email_normalized', 'yusuf.new@example.test')->count(),
            'the hold stays under a deleted contact that still has the address');

        $yusuf = Contact::withoutMasjidScope()->withTrashed()->findOrFail($yusuf->id);
        $yusuf->restore();   // what POST /contacts/{id}/restore does

        $this->assertNotNull($yusuf->fresh()->email_opted_out_at, 'restored, and still badged held');
        $this->assertSame(EmailSuppression::REASON_ORDER_HISTORY_HOLD,
            EmailSuppression::withoutMasjidScope()->where('email_normalized', 'yusuf.new@example.test')->value('reason'),
            'and still held in fact');
    }

    #[Test]
    public function undo_keeps_the_scaffolding_a_later_batch_still_uses(): void
    {
        $this->freezeTime();
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        // A later export of the same products under new order numbers.
        $rows = array_map(function (array $row) {
            $row[0] += 10000;

            return $row;
        }, $this->wixStoreRows());
        $events = array_map(fn (array $o) => ['no' => $o['no'] . '-2'] + $o, $this->wixEventOrders());
        $this->assertSame(0, $this->importWix($this->org, $this->writeWixExport($rows, $events), ['--execute' => true, '--batch' => 'b2']));

        $b2Orders = HistoricalOrder::withoutMasjidScope()->where('import_batch', 'b2')->pluck('id');
        $b2Seats = Registration::withoutMasjidScope()->whereIn('historical_order_id', $b2Orders)->get(['id', 'offering_id', 'fee_plan_id'])->toArray();
        $b2Gifts = Donation::withoutMasjidScope()->whereIn('historical_order_id', $b2Orders)->get(['id', 'fund_id'])->toArray();
        $this->assertNotEmpty($b2Seats);
        $this->assertNotEmpty($b2Gifts);
        $this->assertSame(0, HistoricalImportRecord::withoutMasjidScope()->where('import_batch', 'b2')
            ->whereIn('record_type', [HistoricalImportRecord::TYPE_OFFERING, HistoricalImportRecord::TYPE_FEE_PLAN,
                HistoricalImportRecord::TYPE_FORM, HistoricalImportRecord::TYPE_FUND])->count(),
            'the premise: b2 built on b1\'s scaffolding and made none of its own');

        $scaffolding = HistoricalImportRecord::withoutMasjidScope()->where('import_batch', 'b1')
            ->whereIn('record_type', [HistoricalImportRecord::TYPE_OFFERING, HistoricalImportRecord::TYPE_FEE_PLAN,
                HistoricalImportRecord::TYPE_FORM, HistoricalImportRecord::TYPE_FUND])
            ->get(['record_type', 'record_id'])->groupBy('record_type')->map(fn ($g) => $g->pluck('record_id')->all());

        $summary = $this->undoAsService('b1');

        $this->assertSame(count($scaffolding[HistoricalImportRecord::TYPE_OFFERING]), Offering::withoutMasjidScope()->whereIn('id', $scaffolding[HistoricalImportRecord::TYPE_OFFERING])->count());
        $this->assertSame(count($scaffolding[HistoricalImportRecord::TYPE_FEE_PLAN]), FeePlan::withoutMasjidScope()->whereIn('id', $scaffolding[HistoricalImportRecord::TYPE_FEE_PLAN])->count());
        $this->assertSame(1, Form::whereIn('id', $scaffolding[HistoricalImportRecord::TYPE_FORM])->count());
        $this->assertSame(count($scaffolding[HistoricalImportRecord::TYPE_FUND]), Fund::withoutMasjidScope()->whereIn('id', $scaffolding[HistoricalImportRecord::TYPE_FUND])->count());

        foreach ([HistoricalImportRecord::TYPE_OFFERING, HistoricalImportRecord::TYPE_FEE_PLAN, HistoricalImportRecord::TYPE_FORM, HistoricalImportRecord::TYPE_FUND] as $type) {
            $this->assertSame(count($scaffolding[$type]), $summary['kept'][$type] ?? 0, "every {$type} b2 uses is kept");
        }
        $this->assertArrayHasKey('in use by records outside this batch', $summary['kept_reasons']);

        $this->assertSame($b2Seats, Registration::withoutMasjidScope()->whereIn('historical_order_id', $b2Orders)->get(['id', 'offering_id', 'fee_plan_id'])->toArray(),
            'b2\'s registrations are untouched');
        $this->assertSame($b2Gifts, Donation::withoutMasjidScope()->whereIn('historical_order_id', $b2Orders)->get(['id', 'fund_id'])->toArray());
        $this->assertSame(0, HistoricalOrder::withoutMasjidScope()->where('import_batch', 'b1')->count(), 'b1 itself is gone');
    }

    #[Test]
    public function undo_keeps_an_imported_fund_a_gift_was_later_recorded_into(): void
    {
        $this->freezeTime();
        $this->importWix($this->org, $this->dir, ['--execute' => true, '--batch' => 'b1']);

        $iftar = Fund::withoutMasjidScope()->where('name', 'Iftar')->sole();
        Donation::factory()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $iftar->id, 'contact_id' => $this->amina->id,
            'source' => Donation::SOURCE_OFFLINE, 'payment_method' => 'check', 'status' => 'succeeded',
        ]);

        $summary = $this->undoAsService('b1');

        $this->assertNotNull($iftar->fresh(), 'an offline gift now lives in it');
        $this->assertSame(1, $summary['kept'][HistoricalImportRecord::TYPE_FUND] ?? 0);
        $this->assertNull(Fund::withoutMasjidScope()->where('name', 'Zakat-ul-Fitr')->first(), 'the unused one still goes');
    }

    /** Undo through the service, to read the summary the command prints from. */
    private function undoAsService(string $batch): array
    {
        app(TenantContext::class)->set($this->org->id);

        try {
            return app(WixOrderHistoryImporter::class)->undo($this->org, $batch, true);
        } finally {
            app(TenantContext::class)->forgetTenant();
        }
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
