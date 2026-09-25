<?php

namespace Tests\Feature;

use App\Mail\FormResponseSubmitted;
use App\Mail\FormSubmissionReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Support\FormNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripePages;
use Tests\Support\MakesRamadanGivingForms;
use Tests\TestCase;

/**
 * A paid form that charges unit price x a quantity question (Zakat-ul-Fitr per
 * person; Ramadan giving, 2026-09-25), through the public submit the renderer calls:
 *
 *  - the server multiplies, and nothing in the request body can change the total;
 *  - a quantity below 1, above the question's max, or not a whole number is refused
 *    before anything is written or Stripe is asked;
 *  - the Stripe line is the unit x the quantity, and the row snapshots the breakdown;
 *  - the admin detail and the receipt restate the breakdown the payer was quoted, the
 *    row counts the quantity as its entries, and an existing per-entry form's emails are
 *    unchanged;
 *  - the public page publishes no `amount` or unitMinor an older renderer would draw as
 *    the whole total, shows the price for each in the question, and offers no staff entry;
 *  - the save accepts a paying form priced per quantity and refuses one that could
 *    charge for nothing or without bound.
 */
class FormQuantityPaymentTest extends TestCase
{
    use MakesRamadanGivingForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteAndStubStripe();
        Mail::fake();
    }

    #[Test]
    public function a_card_payment_charges_the_unit_price_times_the_quantity_and_snapshots_the_breakdown(): void
    {
        $form = $this->makeZakatForm($this->makeOrg());

        $this->submitTo($form, ['people' => '4'])->assertOk()
            ->assertJsonPath('data.amount_due_minor', 6800)
            ->assertJsonPath('data.total_minor', 6800);

        $row = FormResponse::sole();
        $this->assertSame(6800, $row->amount_due_minor);
        $this->assertSame(6800, $row->total_minor);
        $this->assertSame('68.00', (string) $row->amount_due);
        $this->assertSame(1700, $row->unit_price_minor);
        $this->assertSame(4, $row->price_quantity);

        $this->assertCount(1, FakeStripePages::$created);
        $this->assertSame([[
            'quantity' => 4,
            'price_data' => ['currency' => 'usd', 'unit_amount' => 1700, 'product_data' => ['name' => 'Zakat-ul-Fitr']],
        ]], FakeStripePages::$created[0]['params']['line_items']);
    }

    #[Test]
    public function the_total_is_recomputed_on_the_server_whatever_the_body_claims(): void
    {
        $form = $this->makeZakatForm($this->makeOrg());

        $this->submitTo($form, ['people' => 2], [
            'amount_due_minor' => 1,
            'total_minor' => 1,
            'amount' => 1,
            'unit_price_minor' => 1,
            'quantity' => 1,
        ])->assertOk()->assertJsonPath('data.total_minor', 3400);

        $line = FakeStripePages::$created[0]['params']['line_items'][0];
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(1700, $line['price_data']['unit_amount']);
        $this->assertSame(3400, FormResponse::sole()->total_minor);
    }

    #[Test]
    public function a_quantity_outside_its_bounds_is_refused_before_anything_is_written(): void
    {
        $form = $this->makeZakatForm($this->makeOrg(), ['min' => 1, 'max' => 12]);

        foreach (['0', '13', '2.5', 'many', ''] as $people) {
            $this->submitTo($form, ['people' => $people])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['people'], 'data');
        }

        $this->assertSame(0, FormResponse::count());
        $this->assertSame([], FakeStripePages::$created);
    }

    #[Test]
    public function a_part_person_is_refused_as_not_a_whole_number(): void
    {
        $form = $this->makeZakatForm($this->makeOrg(), ['min' => 1, 'max' => 12]);

        $this->submitTo($form, ['people' => '2.5'])
            ->assertStatus(422)
            ->assertJsonPath('data.people.0', 'The Number of people field must be an integer.');

        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function a_quantity_question_without_a_max_is_still_capped_at_the_ceiling(): void
    {
        $form = $this->makeZakatForm($this->makeOrg(), ['min' => 1]);

        $this->submitTo($form, ['people' => (string) (Form::MAX_QUANTITY + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['people'], 'data');

        $this->assertSame(0, FormResponse::count());
    }

    #[Test]
    public function the_admin_detail_and_list_show_the_breakdown(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeZakatForm($org);
        $this->submitTo($form, ['people' => 3])->assertOk();
        $row = FormResponse::sole();

        Sanctum::actingAs($this->makeAdminFor($org));

        $this->getJson("/api/admin/masjids/{$org->id}/forms/{$form->id}/responses/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.price_breakdown', ['unit_minor' => 1700, 'quantity' => 3, 'label' => null, 'currency' => 'usd']);

        $this->getJson("/api/admin/masjids/{$org->id}/forms/{$form->id}/responses")
            ->assertOk()
            ->assertJsonPath('data.data.0.price_breakdown.quantity', 3)
            ->assertJsonPath('meta.price_breakdown', true);
    }

    #[Test]
    public function a_gift_for_four_people_counts_four_entries_not_one(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeZakatForm($org);
        $this->submitTo($form, ['people' => '4'])->assertOk();

        $this->assertSame(4, FormResponse::sole()->entry_count);

        Sanctum::actingAs($this->makeAdminFor($org));
        $this->getJson("/api/admin/masjids/{$org->id}/forms/{$form->id}/responses")
            ->assertOk()
            ->assertJsonPath('data.data.0.entry_count', 4);

        // A level charged once counts one, whatever was typed in a box it does not use.
        $iftar = $this->makeIftarForm($org, []);
        $this->submitTo($iftar, ['sponsorship' => 'individual', 'people' => '3'])->assertOk();
        $this->assertSame(3, FormResponse::where('form_id', $iftar->id)->sole()->entry_count);
    }

    #[Test]
    public function the_receipt_and_the_coordinator_email_say_unit_times_quantity_and_not_one_person(): void
    {
        $form = $this->makeZakatForm($this->makeOrg());
        $this->submitTo($form, ['people' => 4])->assertOk();

        $row = FormResponse::sole();
        $this->assertTrue($row->markPaid('pi_zakat_1'));
        FormNotifier::submitted($form, $row->fresh());

        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->hasTo('jane@example.test')
            && $mail->breakdownLine === '$17.00 × 4'
            && $mail->amountLine === '$68.00'
            && $mail->entryCount === 0);

        Mail::assertQueued(FormResponseSubmitted::class, fn (FormResponseSubmitted $mail) => $mail->breakdownLine === '$17.00 × 4');

        $html = (new FormSubmissionReceipt(
            responseId: $row->id,
            formName: 'Zakat-ul-Fitr',
            masjidName: 'Test Masjid',
            registrantName: 'Jane Giver',
            entryCount: 0,
            amountLine: '$68.00',
            tierLabel: null,
            people: [],
            title: null,
            body: null,
            nextSteps: [],
            paymentNote: null,
            masjidEmail: null,
            paymentLine: 'Paid $68.00 by card',
            breakdownLine: '$17.00 × 4',
        ))->render();

        $this->assertStringContainsString('$17.00 × 4', $html);
        $this->assertStringNotContainsString('People registered', $html);
    }

    #[Test]
    public function an_existing_per_entry_form_sends_the_emails_it_always_did(): void
    {
        $org = $this->makeOrg();
        $form = Form::create([
            'masjid_id' => $org->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => ['sections' => [
                ['id' => 'you', 'title' => 'You', 'fields' => [
                    ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ]],
                ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 10, 'fields' => [
                    ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ]],
            ]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true],
            ],
            'is_active' => true,
        ]);

        $this->submitTo($form, ['attendees' => [['attendeeName' => 'Guest 1'], ['attendeeName' => 'Guest 2'], ['attendeeName' => 'Guest 3']]])
            ->assertOk()->assertJsonPath('data.total_minor', 4500);
        $row = FormResponse::sole();
        $this->assertTrue($row->markPaid('pi_festival_1'));
        FormNotifier::submitted($form, $row->fresh());

        // The people are listed as always, and no "$15.00 × 3" line is added.
        Mail::assertQueued(FormSubmissionReceipt::class, fn (FormSubmissionReceipt $mail) => $mail->breakdownLine === null
            && $mail->entryCount === 3
            && $mail->amountLine === '$45.00'
            && $mail->reservedDate === null
            && $mail->lostDate === null);
        Mail::assertQueued(FormResponseSubmitted::class, fn (FormResponseSubmitted $mail) => $mail->breakdownLine === null
            && $mail->entryCount === 3);

        $html = Mail::queued(FormSubmissionReceipt::class)->first()->render();
        $this->assertStringContainsString('People registered', $html);
        $this->assertStringNotContainsString('×', $html);

        // The admin list keeps its columns as they were.
        Sanctum::actingAs($this->makeAdminFor($org));
        $this->getJson("/api/admin/masjids/{$org->id}/forms/{$form->id}/responses")->assertOk()->assertJsonPath('meta.price_breakdown', false);
    }

    #[Test]
    public function the_public_page_shows_the_price_for_each_person(): void
    {
        $payload = $this->publicFormPayload($this->makeZakatForm($this->makeOrg()));

        $people = collect($payload['schema']['sections'][0]['fields'])->firstWhere('name', 'people');
        $this->assertSame('$17.00 each.', $people['help']);
    }

    #[Test]
    public function staff_codes_are_not_offered_on_a_form_priced_per_quantity(): void
    {
        $org = $this->makeOrg();
        $form = $this->makeZakatForm($org, settings: ['payment' => ['online' => true, 'staffCodes' => true]]);

        // Stored another way than the save (which refuses it): still no staff button, whose
        // amount the page cannot work out.
        $this->assertFalse($form->takesStaffCodes());
        $this->assertFalse($this->publicFormPayload($form)['settings']['payment']['staffEntry']);
    }

    #[Test]
    public function the_public_page_publishes_no_total_an_older_renderer_would_draw_wrong(): void
    {
        $payload = $this->publicFormPayload($this->makeZakatForm($this->makeOrg()));

        $this->assertArrayNotHasKey('amount', $payload['settings']['fee'], 'an older page draws `amount` as the whole total');
        $this->assertSame('quantity', $payload['settings']['fee']['pricing']);
        $this->assertSame('people', $payload['settings']['fee']['perQuantityOf']);
        $this->assertEquals(17, $payload['settings']['fee']['unitAmount']);

        $this->assertNull($payload['settings']['payment']['unitMinor'], 'no unit x rows total for an older page');
        $this->assertSame(1700, $payload['settings']['payment']['unitMinorEach']);
        $this->assertSame('people', $payload['settings']['payment']['quantityField']);
    }

    #[Test]
    public function the_breakdown_columns_are_integers_and_a_string(): void
    {
        $this->assertSame('integer', Schema::getColumnType('form_responses', 'unit_price_minor'));
        $this->assertSame('integer', Schema::getColumnType('form_responses', 'price_quantity'));
        $this->assertSame('varchar', Schema::getColumnType('form_responses', 'price_label'));
    }

    // ----------------------------------------------------------------- the save

    #[Test]
    public function a_paying_form_priced_per_quantity_saves_without_a_repeatable_section(): void
    {
        $org = $this->makeOrg();
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson("/api/admin/masjids/{$org->id}/forms", $this->zakatDocument())
            ->assertCreated();

        $saved = Form::where('masjid_id', $org->id)->sole();
        $this->assertSame('people', $saved->settings['fee']['perQuantityOf']);
        $this->assertTrue($saved->takesOnlinePayment());
    }

    #[Test]
    public function a_quantity_that_could_charge_for_nothing_or_without_bound_is_refused_at_save(): void
    {
        $org = $this->makeOrg();
        Sanctum::actingAs($this->makeSuperAdmin());
        $url = "/api/admin/masjids/{$org->id}/forms";

        // Each edit returns the edited document (data_set() edits its copy and returns it).
        $cases = [
            'not a number question' => [fn (array $d) => data_set($d, 'schema.sections.0.fields.1.type', 'text'), 'settings.fee.perQuantityOf'],
            'not required' => [fn (array $d) => data_set($d, 'schema.sections.0.fields.1.required', false), 'settings.fee.perQuantityOf'],
            'a minimum below 1' => [fn (array $d) => data_set($d, 'schema.sections.0.fields.1.min', 0), 'settings.fee.perQuantityOf'],
            'a maximum above the ceiling' => [fn (array $d) => data_set($d, 'schema.sections.0.fields.1.max', Form::MAX_QUANTITY + 1), 'settings.fee.perQuantityOf'],
            'no such question' => [fn (array $d) => data_set($d, 'settings.fee.perQuantityOf', 'nobody'), 'settings.fee.perQuantityOf'],
            'beside a per-entry count' => [fn (array $d) => data_set($d, 'settings.fee.perEntryOfSection', 'giver'), 'settings.fee.perEntryOfSection'],
            'with staff codes' => [fn (array $d) => data_set($d, 'settings.payment.staffCodes', true), 'settings.payment.staffCodes'],
        ];

        foreach ($cases as $why => [$edit, $key]) {
            $this->postJson($url, $edit($this->zakatDocument()))
                ->assertStatus(422)
                ->assertJsonValidationErrors([$key], 'data');
        }

        $this->assertSame(0, Form::where('masjid_id', $org->id)->count());
    }

    // ---------------------------------------------------------------- helpers

    /** The document the builder or form:import would send for a Zakat-ul-Fitr form. */
    private function zakatDocument(): array
    {
        return [
            'slug' => 'zakat-' . uniqid(),
            'name' => 'Zakat-ul-Fitr',
            'is_active' => false,
            'schema' => ['sections' => [['id' => 'giver', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 20],
            ]]]],
            'settings' => [
                'fee' => ['amount' => 17, 'currency' => 'USD', 'perQuantityOf' => 'people'],
                'payment' => ['online' => true],
            ],
        ];
    }
}
