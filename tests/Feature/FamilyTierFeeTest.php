<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use App\Support\FormPayment;
use App\Support\FormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A form priced by how many children a family registers (settings.fee.countTiers;
 * BISS Sunday School, 2026-09-13), on a STORED form — the JSON round trip every real
 * form takes.
 *
 * What must hold, from the plan's risk 2 ("a silent free or wrong charge"):
 *
 *  - the stored decimal (FormSchema::amountDue()) and the cents snapshot
 *    (FormPayment::quote()) come out of one resolver and agree for every family size;
 *  - a form priced only by count CHARGES A FEE, so it takes payment at all;
 *  - its fee rule carries the count schedule, while a unit-priced rule is the shape it
 *    always was;
 *  - a count schedule nothing can read refuses entries, on a paying form and on a form
 *    that takes no payment alike, and never stores a registration as owing nothing.
 */
class FamilyTierFeeTest extends TestCase
{
    use RefreshDatabase;

    private const TIERS = [
        ['min' => 1, 'amount' => 100, 'label' => '1 child'],
        ['min' => 2, 'amount' => 170, 'label' => '2 children'],
        ['min' => 3, 'amount' => 250, 'label' => '3 children'],
        ['min' => 4, 'amount' => 300, 'label' => '4 children'],
        ['min' => 5, 'amount' => 350, 'label' => '5 or more children'],
    ];

    private Masjid $masjid;

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

        $this->masjid = Masjid::create([
            'name' => 'BISS ' . uniqid(),
            'email' => 'biss-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => 'acct_test_biss',
            'stripe_charges_enabled' => true,
        ]);
    }

    #[Test]
    public function the_stored_decimal_and_the_cents_agree_for_every_family_size(): void
    {
        $form = $this->form()->fresh();
        $schema = FormSchema::for($form);

        foreach (range(0, 7) as $children) {
            $data = $this->answers($children);

            $this->assertSame(
                FormPayment::quote($form, $data)['amount_due_minor'],
                FormPayment::toMinor($schema->amountDue($data)),
                "{$children} children"
            );
        }

        $this->assertSame(250.0, $schema->amountDue($this->answers(3)));
        $this->assertSame(350.0, $schema->amountDue($this->answers(7)));
    }

    #[Test]
    public function a_form_priced_only_by_count_charges_a_fee(): void
    {
        $form = $this->form(['online' => true, 'officePayment' => true])->fresh();

        $this->assertTrue($form->chargesFee());
        $this->assertTrue($form->takesOnlinePayment());
        $this->assertTrue($form->takesOfficePayment());
        $this->assertTrue($form->pricesByCount());

        // The Wix-fallback shape on such a form is unpaid, never free.
        $row = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $this->masjid->id,
            'data' => $this->answers(2),
            'entry_count' => 2,
            'amount_due' => null,
            'status' => 'new',
            'submitted_at' => now(),
        ]);
        $row->save();

        $this->assertFalse($row->fresh()->setRelation('form', $form)->isSettled());
    }

    #[Test]
    public function the_fee_rule_carries_the_count_schedule_and_a_unit_rule_keeps_its_shape(): void
    {
        $fee = $this->form()->fresh()->feeRule();

        $this->assertSame(Form::PRICING_COUNT, $fee['pricing']);
        $this->assertSame(100.0, $fee['amount'], 'the lowest tier, for display only');
        $this->assertSame('children', $fee['perEntryOfSection']);
        $this->assertSame([], $fee['tiers']);
        $this->assertNull($fee['currentTier']);
        $this->assertSame(['min' => 3, 'amount' => 250.0, 'label' => '3 children'], $fee['countTiers'][2]);

        $unit = Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'unit-' . uniqid(),
            'name' => 'Camp',
            'schema' => $this->schema(),
            'settings' => ['fee' => ['amount' => 140, 'currency' => 'USD', 'perEntryOfSection' => 'children']],
        ])->fresh();

        $this->assertSame(
            ['amount', 'currency', 'perEntryOfSection', 'tiers', 'currentTier'],
            array_keys($unit->feeRule()),
            'a unit-priced rule gains no key'
        );
        $this->assertFalse($unit->pricesByCount());
    }

    #[Test]
    public function a_form_that_takes_no_payment_stores_the_family_price(): void
    {
        $form = $this->form(null);

        $this->submit($form, 3)->assertOk()->assertJsonPath('data.amount_due', '250.00');

        $row = FormResponse::sole();
        $this->assertSame('250.00', (string) $row->amount_due);
        $this->assertNull($row->payment_method, 'no payment switched on: no money leg');
    }

    #[Test]
    public function a_schedule_nothing_can_read_refuses_entries_and_never_stores_nothing_owed(): void
    {
        $broken = self::TIERS;
        $broken[3]['min'] = 'four';

        foreach (['takes no payment' => null, 'takes cards' => ['online' => true], 'pays the office' => ['officePayment' => true]] as $label => $payment) {
            $form = $this->form($payment);
            // Written past the doors, as a row the rules never saw could be.
            Form::whereKey($form->id)->update(['settings' => json_encode(array_replace_recursive($form->settings, ['fee' => ['countTiers' => $broken]]))]);

            $this->submit($form->fresh(), 4)
                ->assertStatus(422)
                ->assertJsonPath('message', 'This form cannot take entries right now.');

            $this->assertSame(0, FormResponse::where('form_id', $form->id)->count(), $label);
        }
    }

    #[Test]
    public function a_paying_family_form_refuses_a_list_with_no_children(): void
    {
        // minEntries 0 stored past the doors, so the schema lets an empty list through.
        $form = $this->form(['officePayment' => true], 0);

        $this->submit($form, 0, ['pay_with' => 'office'])
            ->assertStatus(422)
            ->assertJsonPath('data.children.0', 'Add at least one entry.');

        $this->assertSame(0, FormResponse::count());
    }

    // --------------------------------------------------------------- helpers

    /** @param  array<string,mixed>|null  $payment */
    private function form(?array $payment = null, int $minEntries = 1): Form
    {
        $settings = [
            'identity' => ['name' => 'fullName'],
            'fee' => ['currency' => 'USD', 'perEntryOfSection' => 'children', 'countTiers' => self::TIERS],
        ];

        if ($payment !== null) {
            $settings['payment'] = $payment;
        }

        return Form::create([
            'masjid_id' => $this->masjid->id,
            'slug' => 'biss-' . uniqid(),
            'name' => 'BISS Registration',
            'schema' => $this->schema($minEntries),
            'settings' => $settings,
            'is_active' => true,
        ]);
    }

    private function schema(int $minEntries = 1): array
    {
        return ['sections' => [
            ['id' => 'family', 'title' => 'Family', 'fields' => [
                ['name' => 'fullName', 'label' => 'Parent', 'type' => 'text', 'required' => true],
            ]],
            ['id' => 'children', 'title' => 'Children', 'repeatable' => true, 'minEntries' => $minEntries, 'maxEntries' => 12, 'fields' => [
                ['name' => 'childName', 'label' => 'Child', 'type' => 'text', 'required' => true],
            ]],
        ]];
    }

    private function answers(int $children): array
    {
        return [
            'fullName' => 'Amal Yusuf',
            'children' => array_map(fn (int $n) => ['childName' => "Child {$n}"], $children > 0 ? range(1, $children) : []),
        ];
    }

    private function submit(Form $form, int $children, array $extra = [])
    {
        return $this->postJson("/api/v1/forms/{$form->id}/responses", array_merge([
            'data' => $this->answers($children),
            'client_submission_key' => (string) Str::uuid(),
        ], $extra), ['masjid-id' => (string) $this->masjid->id]);
    }
}
