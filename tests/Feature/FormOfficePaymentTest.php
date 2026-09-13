<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Stripe\FormResponseCheckoutService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * A family paying the school office instead of a card (settings.payment.officePayment;
 * BISS Sunday School, 2026-09-13), from the public submit to the office recording the
 * money, through the HTTP layer the page and the admin screen use.
 *
 * What is pinned here:
 *
 *  - `pay_with: office` writes an UNPAID `office` row owing the tier price, with no card
 *    fee even where card payers must cover it, and never asks Stripe anything: no
 *    account, no return origin, no call;
 *  - a page that says nothing pays by card when the form can take one right now, and
 *    otherwise pays the office — never a row with no money leg; a choice the form does
 *    not offer is a 422 by name, never swapped for the other;
 *  - money moves, so the replay key is required, a double-tap writes one row, and the
 *    other choice under the same key is a 409;
 *  - the family is emailed what it owes and how to pay at once; the paid receipt follows
 *    when staff record it, and the coordinators hear about the family once;
 *  - "Mark paid" on such a row must say how the money came, and settlement keeps writing
 *    cash or external, so the totals, the list filters, the CSV and the roster CSV count a
 *    paid office registration exactly once;
 *  - `paid_via` is a nullable string(16), pinned where SQLite would wave a length through.
 *
 * Stripe is the checkout service with its seams stubbed; nothing here reaches it.
 */
class FormOfficePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://sundayschool.example.org';

    private const ACCOUNT = 'acct_test_biss';

    private const INVITE = 'SundaySchool2026Abcdef';

    private const INSTRUCTIONS = 'Zelle office@biss.example or Cash App $BISSOffice, or bring cash or a check on Sunday.';

    private const TIERS = [
        ['min' => 1, 'amount' => 100, 'label' => '1 child'],
        ['min' => 2, 'amount' => 170, 'label' => '2 children'],
        ['min' => 3, 'amount' => 250, 'label' => '3 children'],
        ['min' => 4, 'amount' => 300, 'label' => '4 children'],
        ['min' => 5, 'amount' => 350, 'label' => '5 or more children'],
    ];

    /** @var array<int,array<string,mixed>> every page Stripe was asked for */
    public static array $created = [];

    private Masjid $masjid;

    private Form $form;

    private User $admin;

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

        config([
            'forms.payment_return_origins' => [self::ORIGIN],
            'forms.submit_per_hour' => 100,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
        ]);

        Mail::fake();
        self::$created = [];
        $this->stubStripe();

        $this->masjid = Masjid::create([
            'name' => 'Burlington Islamic Sunday School ' . uniqid(),
            'email' => 'biss-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => self::ACCOUNT,
            'stripe_charges_enabled' => true,
        ]);

        // Card with the fee required, and the office: the BISS registration form.
        $this->form = $this->familyForm();

        $this->admin = User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    // ------------------------------------------------------------------ the submit

    #[Test]
    public function a_family_that_chooses_the_office_owes_the_tier_price_with_no_card_fee_and_stripe_is_never_asked(): void
    {
        $response = $this->submit(['pay_with' => 'office', 'cover_fees' => true], 3)
            ->assertOk()
            ->assertJsonPath('message', 'Thank you — your response has been received.')
            ->assertJsonPath('data.payment_method', 'office')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.amount_due', '250.00')
            ->assertJsonPath('data.amount_due_minor', 25000)
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 25000)
            ->assertJsonPath('data.currency', 'usd')
            ->assertJsonPath('data.cancelled', false);

        // The written row's usual answer, and nothing about a card page or the group.
        $this->assertEqualsCanonicalizing([
            'id', 'amount_due', 'entry_count', 'success_title', 'success_body', 'success_next_steps',
            'uuid', 'cancelled', 'payment_method', 'payment_status', 'amount_due_minor',
            'fee_covered_minor', 'total_minor', 'currency',
        ], array_keys($response->json('data')));

        $row = FormResponse::sole();
        $this->assertSame($response->json('data.uuid'), $row->uuid);
        $this->assertSame(FormResponse::METHOD_OFFICE, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $row->payment_status);
        $this->assertSame(25000, $row->amount_due_minor);
        $this->assertSame(0, $row->fee_covered_minor, 'a required card fee is a CARD fee');
        $this->assertSame(25000, $row->total_minor);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->paid_via);
        $this->assertNull($row->idempotency_key);
        $this->assertNull($row->stripe_checkout_session_id);

        $this->assertSame([], self::$created);
    }

    #[Test]
    public function the_office_needs_no_stripe_account_and_no_return_origin(): void
    {
        config(['forms.payment_return_origins' => []]);
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        // No Origin header at all.
        $this->submit(['pay_with' => 'office'], 2, origin: null)
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'office')
            ->assertJsonPath('data.total_minor', 17000);

        // …and a page that says nothing, on a form that cannot take a card right now.
        $this->submit([], 1, origin: null)
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'office')
            ->assertJsonPath('data.total_minor', 10000);

        $this->assertSame(2, FormResponse::where('payment_method', FormResponse::METHOD_OFFICE)->count());
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function a_page_that_says_nothing_pays_by_card_when_it_can_and_the_office_when_it_cannot(): void
    {
        // Card live: exactly as today, with the required fee.
        $this->submit([], 3)
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.fee_covered_minor', 778)
            ->assertJsonPath('data.total_minor', 25778)
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_1');

        // A form that pays the office only.
        $officeOnly = $this->familyForm(['officePayment' => true, 'officeInstructions' => self::INSTRUCTIONS]);

        $this->submit([], 3, $officeOnly)
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'office')
            ->assertJsonMissingPath('data.checkout_url');

        $this->assertCount(1, self::$created);

        // A family that chose the card while the card is down is told so, never quietly
        // sent to the office — and no row is written.
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        $this->submit(['pay_with' => 'card'], 3)->assertStatus(422)->assertJsonPath('data', null);

        // With the office switched off, the page is refused rather than a row with no money leg.
        $cardOnly = $this->familyForm(['online' => true, 'requireFeeCoverage' => true]);

        $this->submit([], 3, $cardOnly)->assertStatus(422);

        $this->assertSame(2, FormResponse::count());
        $this->assertSame(0, FormResponse::whereNull('payment_method')->count());
    }

    /**
     * A staff entry sends no pay_with and keeps today's cash path exactly, even on a form
     * that offers the office and cannot take a card right now — the case where a page
     * that says nothing would otherwise be sent to the office.
     */
    #[Test]
    public function a_staff_code_entry_is_cash_at_the_gate_never_an_office_registration(): void
    {
        $form = $this->familyForm(['online' => true, 'staffCodes' => true, 'officePayment' => true, 'officeInstructions' => self::INSTRUCTIONS]);
        $this->masjid->forceFill(['stripe_charges_enabled' => false])->save();

        FormStaffCode::factory()->withCode('BISS-7QWD')->create([
            'form_id' => $form->id,
            'masjid_id' => $this->masjid->id,
            'holder_name' => 'Najd Haddad',
            'expires_at' => now()->addDay(),
        ]);

        $token = $this->postJson("/api/v1/forms/{$form->id}/staff-session", [
            'staff_code' => 'BISS-7QWD',
            'device_id' => 'phone-najd-biss-1',
        ], ['masjid-id' => (string) $this->masjid->id])->assertOk()->json('data.staff_token');

        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => $this->answers(3),
            'staff_token' => $token,
            'device_id' => 'phone-najd-biss-1',
            'client_submission_key' => 'biss-cash-entry-0001',
        ], ['masjid-id' => (string) $this->masjid->id])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.fee_covered_minor', 0)
            ->assertJsonPath('data.total_minor', 25000);

        $row = FormResponse::sole();
        $this->assertSame(FormResponse::METHOD_CASH, $row->payment_method);
        $this->assertNotNull($row->staff_code_id);
        $this->assertSame(0, FormResponse::where('payment_method', FormResponse::METHOD_OFFICE)->count());
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function a_choice_the_form_does_not_offer_is_refused_by_name(): void
    {
        $cardOnly = $this->familyForm(['online' => true]);
        $officeOnly = $this->familyForm(['officePayment' => true]);

        $this->submit(['pay_with' => 'office'], 2, $cardOnly)
            ->assertStatus(422)
            ->assertJsonPath('data.pay_with.0', 'This form does not take payment at the office.');

        $this->submit(['pay_with' => 'card'], 2, $officeOnly)
            ->assertStatus(422)
            ->assertJsonPath('data.pay_with.0', 'This form does not take card payment.');

        $this->submit(['pay_with' => 'bank'], 2)
            ->assertStatus(422)
            ->assertJsonPath('data.pay_with.0', 'Choose to pay by card or at the office.');

        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function money_moves_at_the_office_too_so_the_replay_key_is_required_and_a_double_tap_writes_one_row(): void
    {
        $officeOnly = $this->familyForm(['officePayment' => true]);

        $this->postJson("/api/v1/forms/{$officeOnly->id}/responses", [
            'data' => $this->answers(2),
        ], ['masjid-id' => (string) $this->masjid->id])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['client_submission_key']]);

        $key = ['client_submission_key' => 'office-render-key-0001', 'pay_with' => 'office'];

        $first = $this->submit($key, 2)->assertOk();
        $second = $this->submit($key, 2)->assertOk();

        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame(1, FormResponse::count());
        Mail::assertQueued(FormSubmissionReceipt::class, 1);

        // The same key choosing the card instead, or leaving it to the server while the
        // card is live: a different registration, never the office row.
        $this->submit(['pay_with' => 'card'] + $key, 2)->assertStatus(409);
        $this->submit(['client_submission_key' => $key['client_submission_key']], 2)->assertStatus(409);

        $this->assertSame(1, FormResponse::count());
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function an_office_registration_is_never_payable_by_card_afterwards(): void
    {
        $uuid = $this->submit(['pay_with' => 'office'], 3)->assertOk()->json('data.uuid');

        $this->getJson("/api/v1/form-responses/{$uuid}", ['masjid-id' => (string) $this->masjid->id])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'office')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.can_pay', false);

        $this->postJson("/api/v1/form-responses/{$uuid}/checkout", ['return_path' => '/'], [
            'masjid-id' => (string) $this->masjid->id,
            'Origin' => self::ORIGIN,
        ])->assertStatus(422);

        $this->assertSame([], self::$created);
    }

    // --------------------------------------------------------------- the emails

    #[Test]
    public function the_family_is_told_what_it_owes_at_once_and_the_coordinators_hear_about_it_once(): void
    {
        $row = $this->officeRow(3);

        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->responseId === $row->id
            && $mail->hasTo('amal@example.com')
            && $mail->amountLine === '$250.00'
            && $mail->tierLabel === '3 children'
            && $mail->paymentLine === null
            && $mail->paymentNote === self::INSTRUCTIONS
            && $mail->whatsappUrl === null);
        Mail::assertQueued(FormResponseSubmitted::class, fn (FormResponseSubmitted $mail) => $mail->responseId === $row->id
            && $mail->amountLine === '$250.00'
            && $mail->paymentLine === null);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->url("/{$row->id}/mark-paid-external"), ['via' => 'zelle'])->assertOk();

        // The paid receipt, with the group link now that it is settled; no second coordinator email.
        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->responseId === $row->id
            && $mail->paymentLine === 'Paid by Zelle (recorded by staff)'
            && $mail->whatsappUrl === 'https://chat.whatsapp.com/' . self::INVITE
            && $mail->paymentNote === null);
        Mail::assertQueued(FormSubmissionReceipt::class, 2);
        Mail::assertQueued(FormResponseSubmitted::class, 1);
    }

    // ------------------------------------------------------------ the office's books

    #[Test]
    public function marking_an_office_registration_paid_must_say_how_the_money_came(): void
    {
        $row = $this->officeRow(3);
        Sanctum::actingAs($this->admin);

        foreach ([[], ['via' => 'cash'], ['via' => 'bitcoin'], ['via' => '']] as $body) {
            $this->postJson($this->url("/{$row->id}/mark-paid-external"), $body)
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Choose how they paid: Zelle, Cash App, Venmo or Check.'));

            $fresh = $row->fresh();
            $this->assertSame(FormResponse::METHOD_OFFICE, $fresh->payment_method, json_encode($body));
            $this->assertSame(FormResponse::PAYMENT_UNPAID, $fresh->payment_status);
            $this->assertNull($fresh->paid_via);
        }

        $this->postJson($this->url("/{$row->id}/mark-paid-external"), ['via' => 'zelle'])
            ->assertOk()
            ->assertJsonPath('message', 'Marked paid.')
            ->assertJsonPath('data.payment_method', 'external')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.paid_via', 'zelle')
            ->assertJsonPath('data.marked_paid_by.id', $this->admin->id);

        $paid = $row->fresh();
        $this->assertSame(FormResponse::PAID_VIA_ZELLE, $paid->paid_via);
        $this->assertSame(25000, $paid->total_minor);
        $this->assertSame(0, $paid->fee_covered_minor);
        $this->assertNotNull($paid->paid_at);

        // A colleague's second press, naming another method, changes nothing.
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]));

        $this->postJson($this->url("/{$row->id}/mark-paid-external"), ['via' => 'venmo'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This registration is already marked paid.');

        $this->assertSame(FormResponse::PAID_VIA_ZELLE, $row->fresh()->paid_via);
        $this->assertSame($this->admin->id, $row->fresh()->marked_paid_by_user_id);
    }

    #[Test]
    public function taking_cash_for_an_office_registration_records_cash_and_says_so(): void
    {
        $row = $this->officeRow(2);
        Sanctum::actingAs($this->admin);

        $this->postJson($this->url("/{$row->id}/take-cash"))
            ->assertOk()
            ->assertJsonPath('message', 'Cash recorded.')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.paid_via', 'cash');

        $this->assertSame(17000, $row->fresh()->total_minor);
        $this->assertSame([], self::$created);
    }

    #[Test]
    public function a_paid_office_registration_counts_exactly_once_in_the_totals_the_filters_and_both_csvs(): void
    {
        $byZelle = $this->officeRow(3, 'Zelle Family');
        $byCash = $this->officeRow(2, 'Cash Family');
        $owing = $this->officeRow(1, 'Owing Family');

        Sanctum::actingAs($this->admin);

        $this->postJson($this->url("/{$byZelle->id}/mark-paid-external"), ['via' => 'zelle'])->assertOk();
        $this->postJson($this->url("/{$byCash->id}/take-cash"))->assertOk();

        // ---- the totals
        $totals = $this->getJson($this->url('/cash-totals'))->assertOk()->json('data');

        $this->assertSame(17000, $totals['totals']['cash_minor']);
        $this->assertSame(1, $totals['totals']['submissions']);
        $this->assertSame(25000, $totals['other_paid']['external']['total_minor']);
        $this->assertSame(1, $totals['other_paid']['external']['submissions']);
        $this->assertSame(0, $totals['other_paid']['online']['total_minor']);
        $this->assertSame(
            42000,
            $totals['totals']['cash_minor'] + $totals['other_paid']['external']['total_minor'] + $totals['other_paid']['online']['total_minor'],
            'the two paid registrations, each once'
        );
        $this->assertSame(25000, $totals['external_by_via']['zelle']['total_minor'], 'a detail of external, not a second total');
        $this->assertSame(0, $totals['external_by_via']['unrecorded']['total_minor']);
        $this->assertSame(['submissions' => 1, 'people' => 1, 'owed_minor' => 10000], $totals['owed_office']);

        // ---- the filters
        $ids = fn (string $payment) => collect($this->getJson($this->url("?payment={$payment}"))->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$byZelle->id, $byCash->id])->sort()->values()->all(), $ids('paid'));
        $this->assertSame([$owing->id], $ids('unpaid'));
        $this->assertSame([$owing->id], $ids('office'), 'office is the families not yet recorded');
        $this->assertSame([$byZelle->id], $ids('external'));
        $this->assertSame([$byCash->id], $ids('cash'));

        // ---- the responses CSV: one line per registration
        $csv = $this->get($this->url('/export'))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csv)))));

        $this->assertSame('Paid via', $lines[0][14]);
        $this->assertCount(4, $lines, 'a header and three registrations');

        $byId = collect(array_slice($lines, 1))->keyBy(fn (array $line) => (int) $line[4]);

        $this->assertSame(['external', 'paid', '250.00', 'zelle'], [$byId[$byZelle->id][5], $byId[$byZelle->id][6], $byId[$byZelle->id][11], $byId[$byZelle->id][14]]);
        $this->assertSame(['cash', 'paid', '170.00', 'cash'], [$byId[$byCash->id][5], $byId[$byCash->id][6], $byId[$byCash->id][11], $byId[$byCash->id][14]]);
        $this->assertSame(['office', 'unpaid', '', ''], [$byId[$owing->id][5], $byId[$owing->id][6], $byId[$owing->id][11], $byId[$owing->id][14]]);

        // ---- the roster: one row per child, labelled by its family's payment
        $roster = collect($this->getJson($this->url('/roster'))->assertOk()->json('data.data'));

        $this->assertCount(6, $roster);
        $this->assertSame(['Paid by Zelle'], $roster->where('response_id', $byZelle->id)->pluck('payment')->unique()->values()->all());
        $this->assertSame(["Cash ({$this->admin->name})"], $roster->where('response_id', $byCash->id)->pluck('payment')->unique()->values()->all());
        $this->assertSame(['Owed — paying the office'], $roster->where('response_id', $owing->id)->pluck('payment')->all());

        $rosterCsv = $this->get($this->url('/roster/export'))->assertOk()->streamedContent();

        $this->assertSame(3, substr_count($rosterCsv, 'Paid by Zelle'));
        $this->assertSame(2, substr_count($rosterCsv, "Cash ({$this->admin->name})"));
        $this->assertSame(1, substr_count($rosterCsv, 'Owed — paying the office'));
        $this->assertCount(7, array_values(array_filter(explode("\n", trim($rosterCsv)))), 'a header and six children');
    }

    #[Test]
    public function the_screen_is_told_the_form_takes_office_payment_and_how_a_payment_can_have_come(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.payment.office', true)
            ->assertJsonPath('meta.payment.paid_via', [
                ['value' => 'zelle', 'label' => 'Zelle'],
                ['value' => 'cashapp', 'label' => 'Cash App'],
                ['value' => 'venmo', 'label' => 'Venmo'],
                ['value' => 'check', 'label' => 'Check'],
            ])
            ->assertJsonPath('meta.payment_filters', ['paid', 'unpaid', 'settled', 'cash', 'online', 'external', 'office']);
    }

    // ------------------------------------------------------------------ the column

    #[Test]
    public function paid_via_is_a_nullable_string_of_sixteen(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('form_responses', 'paid_via'));
        $this->assertTrue(collect(Schema::getColumns('form_responses'))->firstWhere('name', 'paid_via')['nullable']);

        foreach (FormResponse::PAID_VIA as $via) {
            $this->assertLessThanOrEqual(16, strlen($via), $via);
        }
    }

    /**
     * SQLite enforces no VARCHAR length, so the length MySQL will be given is read off the
     * migration's own Blueprint rather than off the SQLite table.
     */
    #[Test]
    public function the_migration_declares_paid_via_as_a_string_of_sixteen_for_mysql(): void
    {
        $migration = require database_path('migrations/2026_09_14_000002_add_paid_via_to_form_responses_table.php');

        // A Blueprint reads the schema grammar off its connection, which is set only once
        // a schema builder has been asked for.
        $connection = DB::connection();
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'form_responses');

        Schema::shouldReceive('table')
            ->once()
            ->with('form_responses', Mockery::type(\Closure::class))
            ->andReturnUsing(fn (string $table, \Closure $callback) => $callback($blueprint));

        $migration->up();

        $columns = $blueprint->getAddedColumns();

        $this->assertCount(1, $columns);
        $this->assertSame('paid_via', $columns[0]->name);
        $this->assertSame('string', $columns[0]->type);
        $this->assertSame(16, $columns[0]->length);
        $this->assertTrue($columns[0]->nullable);
        $this->assertSame([], array_values(array_filter(
            $blueprint->getCommands(),
            fn ($command) => in_array($command->name, ['index', 'unique', 'foreign'], true)
        )), 'a plain column: no index, no foreign key');
    }

    // ---------------------------------------------------------------- helpers

    /** An office registration through the real submit. */
    private function officeRow(int $children, string $name = 'Amal Yusuf'): FormResponse
    {
        $uuid = $this->submit(['pay_with' => 'office'], $children, name: $name)->assertOk()->json('data.uuid');

        return FormResponse::where('uuid', $uuid)->firstOrFail();
    }

    private function submit(array $extra = [], int $children = 3, ?Form $form = null, ?string $origin = self::ORIGIN, string $name = 'Amal Yusuf'): TestResponse
    {
        $form ??= $this->form;
        $headers = ['masjid-id' => (string) $form->masjid_id];

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        return $this->postJson("/api/v1/forms/{$form->id}/responses", array_merge([
            'data' => $this->answers($children, $name),
            'return_path' => '/',
            'client_submission_key' => (string) Str::uuid(),
        ], $extra), $headers);
    }

    private function answers(int $children, string $name = 'Amal Yusuf'): array
    {
        return [
            'fullName' => $name,
            'email' => 'amal@example.com',
            'children' => array_map(fn (int $n) => ['childName' => "{$name} child {$n}"], $children > 0 ? range(1, $children) : []),
        ];
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/forms/{$this->form->id}/responses{$suffix}";
    }

    /** @param  array<string,mixed>|null  $payment */
    private function familyForm(?array $payment = null): Form
    {
        return Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'biss-' . uniqid(),
            'name' => 'BISS Registration 2026',
            'schema' => ['sections' => [
                ['id' => 'family', 'title' => 'Family', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Parent name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ]],
                ['id' => 'children', 'title' => 'Children', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 10, 'fields' => [
                    ['name' => 'childName', 'label' => 'Child name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'notifyEmails' => ['office@biss.test'],
                'fee' => ['currency' => 'USD', 'perEntryOfSection' => 'children', 'countTiers' => self::TIERS],
                'payment' => $payment ?? [
                    'online' => true,
                    'requireFeeCoverage' => true,
                    'officePayment' => true,
                    'officeInstructions' => self::INSTRUCTIONS,
                ],
                'whatsappUrl' => 'https://chat.whatsapp.com/' . self::INVITE,
                'paymentNote' => 'Card payments carry the card processing fee.',
            ],
            'is_active' => true,
        ]);
    }

    /** Stripe, as far as the service can tell: pages cs_test_1, cs_test_2…, each open. */
    private function stubStripe(): void
    {
        $this->app->bind(FormResponseCheckoutService::class, function ($app) {
            return new class($app->make(StripeClient::class)) extends FormResponseCheckoutService
            {
                protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
                {
                    FormOfficePaymentTest::$created[] = $params;
                    $n = count(FormOfficePaymentTest::$created);

                    return ['id' => "cs_test_{$n}", 'url' => "https://checkout.stripe.test/pay/cs_test_{$n}", 'payment_intent' => null];
                }

                protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
                {
                    return ['status' => 'open', 'url' => "https://checkout.stripe.test/pay/{$sessionId}"];
                }

                protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
                {
                }
            };
        });
    }
}
