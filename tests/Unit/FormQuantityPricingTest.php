<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Support\FormPayment;
use App\Support\FormSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The arithmetic of the two prices added for Ramadan giving (2026-09-25), through the
 * one resolver every reader uses (Form::priceFor()) and the cents it becomes
 * (FormPayment::quote()):
 *
 *  - unit price x a quantity question (Zakat-ul-Fitr per person): a whole number, a
 *    string answer counted, anything else 0 so the never-free refusal fires, and never
 *    above Form::MAX_QUANTITY;
 *  - a price chosen by answer (iftar sponsorship levels): the level's price, x the
 *    quantity only for a level charged per unit, named after the option's label;
 *  - an unreadable list, or a quantity beside a per-entry count, prices nothing;
 *  - the stored decimal agrees with the cents (unit x quantity, never the unit alone);
 *  - the answers a level does not use are dropped before they are stored.
 *
 * No database: the forms are built in memory.
 */
class FormQuantityPricingTest extends TestCase
{
    #[Test]
    public function a_quantity_question_multiplies_the_unit_price_into_one_stripe_line(): void
    {
        $quote = FormPayment::quote($this->zakatForm(), ['fullName' => 'Jane Giver', 'people' => 4]);

        $this->assertSame(1700, $quote['unit_minor']);
        $this->assertSame(4, $quote['quantity']);
        $this->assertSame(6800, $quote['amount_due_minor']);
        $this->assertSame(6800, $quote['total_minor']);
        $this->assertSame([[
            'quantity' => 4,
            'price_data' => ['currency' => 'usd', 'unit_amount' => 1700, 'product_data' => ['name' => 'Zakat-ul-Fitr']],
        ]], $quote['line_items']);
    }

    #[Test]
    public function a_quantity_sent_as_a_string_counts_and_anything_but_a_whole_number_counts_as_nothing(): void
    {
        $form = $this->zakatForm();

        $this->assertSame(3, $form->priceFor(['people' => '3'])['quantity']);
        $this->assertSame(3, $form->priceFor(['people' => ' 3 '])['quantity']);

        foreach (['2.5', 'four', '', null, true, -2] as $junk) {
            $this->assertSame(0, $form->priceFor(['people' => $junk])['quantity'], 'never a guessed quantity: '.json_encode($junk));
        }

        // A quantity of 0 owes nothing, which a paying form refuses (never the free path).
        $this->assertSame(0, FormPayment::quote($form, ['people' => '0'])['total_minor']);
    }

    #[Test]
    public function a_quantity_above_the_ceiling_is_never_priced(): void
    {
        $form = $this->zakatForm();

        $this->assertSame(Form::MAX_QUANTITY, $form->priceFor(['people' => Form::MAX_QUANTITY])['quantity']);
        $this->assertNull($form->priceFor(['people' => Form::MAX_QUANTITY + 1]));
        $this->assertNull(FormPayment::quote($form, ['people' => Form::MAX_QUANTITY + 1]));
    }

    #[Test]
    public function a_level_chosen_by_answer_sets_the_price_and_names_the_line(): void
    {
        $form = $this->iftarForm();

        $quarter = FormPayment::quote($form, ['sponsorship' => 'quarter', 'iftar_date' => '2027-02-10']);
        $this->assertSame(45000, $quarter['unit_minor']);
        $this->assertSame(1, $quarter['quantity']);
        $this->assertSame(45000, $quarter['total_minor']);
        $this->assertSame('Quarter Iftar', $quarter['tier_label']);
        $this->assertSame('Iftar Sponsorship (Quarter Iftar)', $quarter['line_items'][0]['price_data']['product_data']['name']);

        $full = FormPayment::quote($form, ['sponsorship' => 'full']);
        $this->assertSame(190000, $full['total_minor']);

        // A level charged per unit multiplies by the quantity question.
        $individual = FormPayment::quote($form, ['sponsorship' => 'individual', 'people' => '3']);
        $this->assertSame(1800, $individual['unit_minor']);
        $this->assertSame(3, $individual['quantity']);
        $this->assertSame(5400, $individual['total_minor']);
    }

    #[Test]
    public function a_level_not_charged_per_unit_ignores_a_quantity_typed_beside_it(): void
    {
        $quote = FormPayment::quote($this->iftarForm(), ['sponsorship' => 'half', 'people' => 7]);

        $this->assertSame(1, $quote['quantity']);
        $this->assertSame(95000, $quote['total_minor'], 'a Half Iftar is one sponsorship, not seven');
    }

    #[Test]
    public function an_answer_naming_no_level_prices_nothing(): void
    {
        // A level the question offers is priced; anything else is not, and a paying form
        // refuses a submission it cannot price rather than charging something.
        $this->assertNotNull($this->iftarForm()->priceFor(['sponsorship' => 'full']));
        $this->assertNull($this->iftarForm()->priceFor(['sponsorship' => 'banquet']));
        $this->assertNull($this->iftarForm()->priceFor([]));
    }

    #[Test]
    public function an_unreadable_price_list_prices_the_whole_form_as_nothing(): void
    {
        $bad = $this->iftarForm(fn (array $fee) => array_replace_recursive($fee, ['byChoice' => ['prices' => [1 => ['amount' => 'lots']]]]));
        $this->assertNull($bad->feeRule(), 'one unreadable level must not drop out and leave its payers unpriced');

        $twice = $this->iftarForm(fn (array $fee) => array_replace_recursive($fee, ['byChoice' => ['prices' => [1 => ['value' => 'individual']]]]));
        $this->assertNull($twice->feeRule(), 'a value priced twice is ambiguous');

        $negative = $this->iftarForm(fn (array $fee) => array_replace_recursive($fee, ['byChoice' => ['prices' => [1 => ['amount' => -450]]]]));
        $this->assertNull($negative->feeRule(), 'a negative level would pay the sponsor');

        $noQuantity = $this->iftarForm(function (array $fee) {
            unset($fee['perQuantityOf']);

            return $fee;
        });
        $this->assertNull($noQuantity->feeRule(), 'a level charged per unit with nothing to count by');

        // Still a form that charges, so a paying form refuses entries rather than going free.
        $this->assertTrue($bad->chargesFee());
    }

    #[Test]
    public function a_quantity_beside_a_per_entry_count_prices_nothing(): void
    {
        $form = $this->zakatForm(['perEntryOfSection' => 'children']);

        $this->assertNull($form->feeRule());
    }

    #[Test]
    public function a_form_priced_only_by_its_levels_charges_a_fee(): void
    {
        $this->assertTrue($this->iftarForm()->chargesFee());
    }

    #[Test]
    public function the_stored_decimal_is_unit_times_quantity_never_the_unit_alone(): void
    {
        $this->assertSame(68.0, FormSchema::for($this->zakatForm())->amountDue(['people' => 4]));
        $this->assertSame(450.0, FormSchema::for($this->iftarForm())->amountDue(['sponsorship' => 'quarter']));
        $this->assertSame(54.0, FormSchema::for($this->iftarForm())->amountDue(['sponsorship' => 'individual', 'people' => 3]));
    }

    #[Test]
    public function a_unit_priced_rule_without_a_quantity_keeps_exactly_its_keys(): void
    {
        $plain = new Form(['name' => 'Camp', 'schema' => ['sections' => []], 'settings' => ['fee' => ['amount' => 15, 'currency' => 'USD']]]);

        $this->assertSame(['amount', 'currency', 'perEntryOfSection', 'tiers', 'currentTier'], array_keys($plain->feeRule()));
        $this->assertSame(['amount', 'currency', 'perEntryOfSection', 'tiers', 'currentTier', 'perQuantityOf'], array_keys($this->zakatForm()->feeRule()));
    }

    #[Test]
    public function the_answers_a_level_does_not_use_are_dropped_before_they_are_stored(): void
    {
        $form = $this->iftarForm();

        $this->assertSame(
            ['sponsorship' => 'quarter', 'iftar_date' => '2027-02-10'],
            $form->withoutUnusedPriceAnswers(['sponsorship' => 'quarter', 'people' => '4', 'iftar_date' => '2027-02-10'])
        );
        $this->assertSame(
            ['sponsorship' => 'individual', 'people' => '4'],
            $form->withoutUnusedPriceAnswers(['sponsorship' => 'individual', 'people' => '4', 'iftar_date' => '2027-02-10'])
        );

        // Every other form's answers are untouched.
        $answers = ['people' => '4', 'fullName' => 'Jane Giver'];
        $this->assertSame($answers, $this->zakatForm()->withoutUnusedPriceAnswers($answers));
    }

    #[Test]
    public function only_a_level_that_reserves_a_date_names_one(): void
    {
        $form = $this->iftarForm();

        $this->assertSame('2027-02-10', $form->reservedDateIn(['sponsorship' => 'full', 'iftar_date' => '2027-02-10']));
        $this->assertNull($form->reservedDateIn(['sponsorship' => 'individual', 'iftar_date' => '2027-02-10']));
        $this->assertNull($form->reservedDateIn(['sponsorship' => 'full']));
        $this->assertNull($this->zakatForm()->reservedDateIn(['people' => 2]));
    }

    // ---------------------------------------------------------------- helpers

    private function zakatForm(array $fee = []): Form
    {
        return new Form([
            'name' => 'Zakat-ul-Fitr',
            'schema' => ['sections' => [['id' => 'giver', 'title' => 'You', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'required' => true, 'min' => 1],
            ]]]],
            'settings' => ['fee' => array_replace(['amount' => 17, 'currency' => 'USD', 'perQuantityOf' => 'people'], $fee)],
        ]);
    }

    private function iftarForm(?callable $edit = null): Form
    {
        $fee = [
            'currency' => 'USD',
            'perQuantityOf' => 'people',
            'byChoice' => ['field' => 'sponsorship', 'prices' => [
                ['value' => 'individual', 'amount' => 18, 'perQuantity' => true],
                ['value' => 'quarter', 'amount' => 450, 'reservesDate' => true],
                ['value' => 'half', 'amount' => 950, 'reservesDate' => true],
                ['value' => 'full', 'amount' => 1900, 'reservesDate' => true],
            ]],
        ];

        return new Form([
            'name' => 'Iftar Sponsorship',
            'schema' => ['sections' => [['id' => 'sponsor', 'title' => 'Sponsor', 'fields' => [
                ['name' => 'sponsorship', 'label' => 'Sponsorship', 'type' => 'radio', 'required' => true, 'options' => [
                    ['value' => 'individual', 'label' => 'Individual Iftar'],
                    ['value' => 'quarter', 'label' => 'Quarter Iftar'],
                    ['value' => 'half', 'label' => 'Half Iftar'],
                    ['value' => 'full', 'label' => 'Full Iftar'],
                ]],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'min' => 1],
                ['name' => 'iftar_date', 'label' => 'Date', 'type' => 'select', 'optionsSource' => 'reservable_dates'],
            ]]]],
            'settings' => [
                'fee' => $edit ? $edit($fee) : $fee,
                'reservation' => ['field' => 'iftar_date', 'dates' => ['2027-02-10', '2027-02-11']],
            ],
        ]);
    }
}
