<?php

namespace Tests\Unit;

use App\Models\Form;
use App\Models\Masjid;
use App\Support\FormPayment;
use App\Support\FormSchema;
use App\Support\StripeFees;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * App\Support\FormPayment: the one place a form's decimal-dollar fee becomes
 * integer cents, and the only builder of a form's Stripe line items.
 *
 * What it must hold, from the festival brief:
 *
 *  - one float-to-cents conversion, rounding rather than truncating;
 *  - the unit price for a flat, a per-entry and a tiered fee, the tier chosen
 *    on the MASJID's calendar day;
 *  - the quantity is the row count of the section the fee is per entry of —
 *    what the fee multiplies by — never entry_count;
 *  - a covered card fee is StripeFees::coverage() of what is owed, only when
 *    offered, asked for, and paid by card;
 *  - the line items sum to the total, or it throws before anything is written.
 *
 * No database: a Form is built in memory with its masjid relation set, which is
 * everything FormPayment reads.
 */
class FormPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin Stripe's published rate so the coverage figures are deterministic.
        config([
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ conversion

    #[Test]
    public function the_one_conversion_rounds_rather_than_truncates(): void
    {
        // 19.99 * 100 is 1998.9999999999998 in binary floating point; a bare
        // (int) would charge 1998.
        $this->assertSame(1999, FormPayment::toMinor(19.99));
        $this->assertSame(1999, FormPayment::toMinor('19.99'));
        $this->assertSame(10000, FormPayment::toMinor(100));
        $this->assertSame(50, FormPayment::toMinor(0.5));
        $this->assertSame(29, FormPayment::toMinor(0.1 + 0.19));
    }

    #[Test]
    public function whole_cents_are_told_apart_from_fractions_of_a_cent(): void
    {
        $this->assertSame(1999, FormPayment::wholeMinor(19.99));
        $this->assertSame(1250, FormPayment::wholeMinor('12.50'));
        $this->assertSame(50, FormPayment::wholeMinor(0.5));
        $this->assertSame(0, FormPayment::wholeMinor(0));
        $this->assertSame(100000000, FormPayment::wholeMinor(1000000));

        $this->assertNull(FormPayment::wholeMinor(12.345));
        $this->assertNull(FormPayment::wholeMinor('12.345'));
        $this->assertNull(FormPayment::wholeMinor('ten dollars'));
        $this->assertNull(FormPayment::wholeMinor(null));
        $this->assertNull(FormPayment::wholeMinor(true));
        $this->assertNull(FormPayment::wholeMinor([10]));
    }

    // ------------------------------------------------------------ unit price

    #[Test]
    public function a_flat_fee_is_one_unit_whatever_the_rows(): void
    {
        $form = $this->form(['amount' => 25, 'currency' => 'USD']);

        $quote = FormPayment::quote($form, $this->attendees(4));

        $this->assertSame(2500, FormPayment::unitMinor($form));
        $this->assertSame(1, $quote['quantity']);
        $this->assertSame(2500, $quote['amount_due_minor']);
        $this->assertSame(0, $quote['fee_covered_minor']);
        $this->assertSame(2500, $quote['total_minor']);
        $this->assertSame('usd', $quote['currency']);
        $this->assertNull($quote['tier_label']);
        $this->assertSame([[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => 2500,
                'product_data' => ['name' => 'Fall Festival 2026'],
            ],
        ]], $quote['line_items']);
    }

    #[Test]
    public function a_per_entry_fee_charges_each_row_of_its_section(): void
    {
        $form = $this->form(['amount' => 19.99, 'perEntryOfSection' => 'attendees']);
        $data = $this->attendees(3);

        $quote = FormPayment::quote($form, $data);

        $this->assertSame(1999, $quote['unit_minor']);
        $this->assertSame(3, $quote['quantity']);
        $this->assertSame(5997, $quote['amount_due_minor']);
        $this->assertSame(5997, $quote['total_minor']);
        $this->assertSame(5997, FormPayment::amountDueMinor($form, $data));
        $this->assertSame(3, FormPayment::quantity($form, $data));

        $this->assertSame(3, $quote['line_items'][0]['quantity']);
        $this->assertSame(1999, $quote['line_items'][0]['price_data']['unit_amount']);

        // The legacy decimal the row also stores agrees to the cent.
        $this->assertSame(59.97, FormSchema::for($form)->amountDue($data));
    }

    /**
     * The festival brief's line-item finding: entry_count reads the FIRST
     * repeatable section and never goes below 1, so a quantity taken from it
     * would not sum to what is owed.
     */
    #[Test]
    public function the_quantity_is_the_fee_sections_row_count_not_entry_count(): void
    {
        $form = $this->form(['amount' => 10, 'perEntryOfSection' => 'attendees'], [], 'UTC', [
            [
                'id' => 'volunteers',
                'title' => 'Volunteers',
                'repeatable' => true,
                'fields' => [['name' => 'name', 'label' => 'Name', 'type' => 'text']],
            ],
            [
                'id' => 'attendees',
                'title' => 'Attendees',
                'repeatable' => true,
                'minEntries' => 1,
                'fields' => [['name' => 'name', 'label' => 'Name', 'type' => 'text']],
            ],
        ]);

        $data = [
            'volunteers' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C'], ['name' => 'D'], ['name' => 'E']],
            'attendees' => [['name' => 'X'], ['name' => 'Y']],
        ];

        $this->assertSame(5, FormSchema::for($form)->entryCount($data), 'entry_count reads the first repeatable section');

        $quote = FormPayment::quote($form, $data);

        $this->assertSame(2, $quote['quantity']);
        $this->assertSame(2000, $quote['total_minor']);
        $this->assertSame(20.0, FormSchema::for($form)->amountDue($data));
    }

    #[Test]
    public function an_empty_attendee_list_owes_nothing_and_carries_no_lines(): void
    {
        $form = $this->form(
            ['amount' => 15, 'perEntryOfSection' => 'attendees'],
            ['online' => true, 'allowFeeCoverage' => true]
        );
        $data = $this->attendees(0);

        $quote = FormPayment::quote($form, $data, true, true);

        $this->assertSame(0, $quote['quantity']);
        $this->assertSame(0, $quote['amount_due_minor']);
        $this->assertSame(0, $quote['fee_covered_minor']);
        $this->assertSame(0, $quote['total_minor'], 'the caller refuses this — a paying form never takes the free path');
        $this->assertSame([], $quote['line_items'], 'never a zero-quantity line, never a $0 session');

        // …while entry_count still says 1, which is why it is never the quantity.
        $this->assertSame(1, FormSchema::for($form)->entryCount($data));
    }

    #[Test]
    public function the_tier_in_force_is_the_masjids_calendar_day(): void
    {
        $fee = [
            'perEntryOfSection' => 'attendees',
            'tiers' => [
                ['label' => 'Early bird', 'amount' => 10, 'until' => '2026-10-01'],
                ['label' => 'Standard', 'amount' => 15],
            ],
        ];

        // 01:30 UTC on Oct 2 is still 21:30 on Oct 1 in New York.
        Carbon::setTestNow(Carbon::parse('2026-10-02 01:30:00', 'UTC'));

        $eastern = FormPayment::quote($this->form($fee, [], 'America/New_York'), $this->attendees(2));

        $this->assertSame(1000, $eastern['unit_minor']);
        $this->assertSame('Early bird', $eastern['tier_label']);
        $this->assertSame(2000, $eastern['total_minor']);
        $this->assertSame(
            'Fall Festival 2026 (Early bird)',
            $eastern['line_items'][0]['price_data']['product_data']['name']
        );

        $utc = FormPayment::quote($this->form($fee, [], 'UTC'), $this->attendees(2));

        $this->assertSame(1500, $utc['unit_minor'], 'the same instant is already Oct 2 in UTC');
        $this->assertSame('Standard', $utc['tier_label']);

        // An explicit instant is honoured over the clock.
        $this->assertSame(
            1500,
            FormPayment::unitMinor(
                $this->form($fee, [], 'America/New_York'),
                Carbon::parse('2026-10-02 12:00:00', 'America/New_York')
            )
        );
    }

    // ------------------------------------------------------------- fee cover

    #[Test]
    public function a_covered_card_fee_is_stripes_coverage_of_what_is_owed(): void
    {
        $form = $this->form(
            ['amount' => 20, 'perEntryOfSection' => 'attendees'],
            ['online' => true, 'allowFeeCoverage' => true]
        );

        $quote = FormPayment::quote($form, $this->attendees(3), true, true);

        // 6000 owed: round((6000 + 30) / 0.971) = 6210, so the payer adds 210.
        $this->assertSame(6000, $quote['amount_due_minor']);
        $this->assertSame(StripeFees::coverage(6000), $quote['fee_covered_minor']);
        $this->assertSame(210, $quote['fee_covered_minor']);
        $this->assertSame(6210, $quote['total_minor']);
        $this->assertSame(210, FormPayment::feeCoveredMinor($form, 6000, true, true));

        $this->assertCount(2, $quote['line_items']);
        $this->assertSame([
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => 210,
                'product_data' => ['name' => FormPayment::FEE_LINE_NAME],
            ],
        ], $quote['line_items'][1]);

        $sum = array_sum(array_map(
            fn (array $line) => $line['quantity'] * $line['price_data']['unit_amount'],
            $quote['line_items']
        ));
        $this->assertSame($quote['total_minor'], $sum);
    }

    #[Test]
    public function the_fee_is_covered_only_when_offered_asked_for_and_paid_by_card(): void
    {
        $fee = ['amount' => 20, 'perEntryOfSection' => 'attendees'];
        $offered = $this->form($fee, ['online' => true, 'allowFeeCoverage' => true]);
        $notOffered = $this->form($fee, ['online' => true]);
        $cardOff = $this->form($fee, ['staffCodes' => true, 'allowFeeCoverage' => true]);
        $data = $this->attendees(1);

        $this->assertSame(0, FormPayment::quote($offered, $data, false, true)['fee_covered_minor'], 'the payer said no');
        $this->assertSame(0, FormPayment::quote($offered, $data, true, false)['fee_covered_minor'], 'cash has no card fee');
        $this->assertSame(0, FormPayment::quote($notOffered, $data, true, true)['fee_covered_minor'], 'the form does not offer it');
        $this->assertSame(0, FormPayment::quote($cardOff, $data, true, true)['fee_covered_minor'], 'card payment is off here');

        // Not covered: one line, and the total is the price.
        $quote = FormPayment::quote($offered, $data, false, true);
        $this->assertCount(1, $quote['line_items']);
        $this->assertSame(2000, $quote['total_minor']);
    }

    // ----------------------------------------------------------------- lines

    #[Test]
    public function lines_that_do_not_add_up_to_the_total_are_refused(): void
    {
        $line = fn (mixed $unit, mixed $quantity) => [
            'quantity' => $quantity,
            'price_data' => ['currency' => 'usd', 'unit_amount' => $unit, 'product_data' => ['name' => 'Entry']],
        ];

        FormPayment::assertLinesMatchTotal([$line(1000, 3), $line(120, 1)], 3120);
        FormPayment::assertLinesMatchTotal([], 0);

        foreach ([
            'a cent short' => [[$line(1000, 3)], 3001],
            'a zero quantity' => [[$line(1000, 0)], 0],
            'a float unit amount' => [[$line(10.0, 1)], 10],
            'a string quantity' => [[$line(1000, '3')], 3000],
            'a negative unit' => [[$line(-500, 1), $line(1000, 1)], 500],
            'no lines for a total' => [[], 500],
        ] as $label => [$lines, $total]) {
            try {
                FormPayment::assertLinesMatchTotal($lines, $total);
                $this->fail("{$label}: accepted lines that do not add up");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---------------------------------------------------------------- edges

    #[Test]
    public function a_form_that_charges_nothing_has_no_quote(): void
    {
        $form = new Form();
        $form->name = 'Community iftar RSVP';
        $form->schema = ['sections' => []];
        $form->settings = ['submitButtonLabel' => 'Send'];
        $form->setRelation('masjid', null);

        $this->assertNull(FormPayment::quote($form, []));
        $this->assertNull(FormPayment::unitMinor($form));
        $this->assertNull(FormPayment::amountDueMinor($form, []));
        $this->assertSame(1, FormPayment::quantity($form, []));
        $this->assertSame('usd', FormPayment::currencyFor($form));
    }

    #[Test]
    public function a_negative_price_throws_rather_than_refunding(): void
    {
        // The settings rules refuse this; a row written some other way must still
        // never become a negative line.
        $form = $this->form(['amount' => -5]);

        $this->expectException(LogicException::class);

        FormPayment::quote($form, []);
    }

    // ------------------------------------------- priced by number of children (BISS)

    /** BISS Sunday School 2026: the whole family, by how many children it registers. */
    private const FAMILY_TIERS = [
        ['min' => 1, 'amount' => 100, 'label' => '1 child'],
        ['min' => 2, 'amount' => 170, 'label' => '2 children'],
        ['min' => 3, 'amount' => 250, 'label' => '3 children'],
        ['min' => 4, 'amount' => 300, 'label' => '4 children'],
        ['min' => 5, 'amount' => 350, 'label' => '5 or more children'],
    ];

    #[Test]
    public function a_family_is_charged_the_tier_for_its_number_of_children_and_five_is_the_cap(): void
    {
        $form = $this->form(['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'countTiers' => self::FAMILY_TIERS]);

        foreach ([1 => 10000, 2 => 17000, 3 => 25000, 4 => 30000, 5 => 35000, 6 => 35000, 12 => 35000] as $children => $owed) {
            $quote = FormPayment::quote($form, $this->attendees($children));

            $this->assertSame($owed, $quote['amount_due_minor'], "{$children} children");
            $this->assertSame($owed, $quote['unit_minor'], "{$children} children");
            $this->assertSame(1, $quote['quantity'], "{$children} children: one family, never × rows");
            $this->assertSame($owed, $quote['total_minor'], "{$children} children");
            $this->assertSame(1, FormPayment::quantity($form, $this->attendees($children)));
        }
    }

    #[Test]
    public function a_family_with_no_children_owes_nothing_and_carries_no_lines(): void
    {
        $form = $this->form(
            ['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'countTiers' => self::FAMILY_TIERS],
            ['online' => true, 'requireFeeCoverage' => true]
        );

        $quote = FormPayment::quote($form, $this->attendees(0), false, true);

        $this->assertSame(0, $quote['quantity']);
        $this->assertSame(0, $quote['amount_due_minor']);
        $this->assertSame(0, $quote['fee_covered_minor'], 'no fee on nothing owed');
        $this->assertSame(0, $quote['total_minor'], 'the caller refuses this; a paying form never takes the free path');
        $this->assertSame([], $quote['line_items']);
        $this->assertSame(0.0, FormSchema::for($form)->amountDue($this->attendees(0)));
    }

    #[Test]
    public function the_tier_is_chosen_by_its_minimum_not_by_the_order_it_was_stored_in(): void
    {
        // Read in written order, a family of six would stop at the "2 children" tier.
        $form = $this->form(['perEntryOfSection' => 'attendees', 'countTiers' => [
            ['min' => 1, 'amount' => 100, 'label' => '1 child'],
            ['min' => 5, 'amount' => 350, 'label' => '5 or more children'],
            ['min' => 2, 'amount' => 170, 'label' => '2 children'],
        ]]);

        $this->assertSame(35000, FormPayment::amountDueMinor($form, $this->attendees(6)));
        $this->assertSame(17000, FormPayment::amountDueMinor($form, $this->attendees(4)));
        $this->assertSame(17000, FormPayment::amountDueMinor($form, $this->attendees(2)));
        $this->assertSame(10000, FormPayment::amountDueMinor($form, $this->attendees(1)));
        $this->assertSame('5 or more children', FormPayment::quote($form, $this->attendees(6))['tier_label']);
    }

    #[Test]
    public function a_schedule_nothing_can_read_prices_nothing_rather_than_a_cheaper_tier(): void
    {
        $unreadable = [
            'a min that is not a whole number' => fn (array $t) => array_replace_recursive($t, [3 => ['min' => 'four']]),
            'a min of zero' => fn (array $t) => array_replace_recursive($t, [0 => ['min' => 0]]),
            'a tier with no amount' => function (array $t) { unset($t[4]['amount']); return $t; },
            'a negative amount' => fn (array $t) => array_replace_recursive($t, [2 => ['amount' => -250]]),
            'two tiers for one number' => fn (array $t) => array_replace_recursive($t, [3 => ['min' => 3]]),
            'a tier that is not a tier' => fn (array $t) => [...$t, 'five'],
            'a schedule that is not a list' => fn (array $t) => 'one hundred dollars',
        ];

        foreach ($unreadable as $label => $break) {
            $form = $this->form(['perEntryOfSection' => 'attendees', 'countTiers' => $break(self::FAMILY_TIERS)], ['online' => true]);
            $data = $this->attendees(5);

            $this->assertNull($form->priceFor($data), $label);
            $this->assertNull(FormPayment::quote($form, $data, false, true), $label);
            $this->assertNull(FormSchema::for($form)->amountDue($data), $label);
            $this->assertTrue($form->pricesByCount(), "{$label}: still a count schedule, never quietly unit-priced");
        }

        // Counting no section, and a family size no tier starts low enough for.
        $this->assertNull($this->form(['countTiers' => self::FAMILY_TIERS])->priceFor($this->attendees(3)));
        $this->assertNull(
            $this->form(['perEntryOfSection' => 'attendees', 'countTiers' => array_slice(self::FAMILY_TIERS, 1)])->priceFor($this->attendees(1))
        );
    }

    #[Test]
    public function a_family_is_one_line_named_after_its_tier(): void
    {
        $form = $this->form(['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'countTiers' => self::FAMILY_TIERS]);

        $this->assertSame([[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => 25000,
                'product_data' => ['name' => 'Fall Festival 2026 (3 children)'],
            ],
        ]], FormPayment::quote($form, $this->attendees(3))['line_items']);

        $this->assertSame(250.0, FormSchema::for($form)->amountDue($this->attendees(3)), 'the decimal is the tier, never 100 × 3');
    }

    #[Test]
    public function a_required_card_fee_is_added_for_every_card_payer_whatever_the_browser_says(): void
    {
        $form = $this->form(
            ['currency' => 'USD', 'perEntryOfSection' => 'attendees', 'countTiers' => self::FAMILY_TIERS],
            ['online' => true, 'requireFeeCoverage' => true]
        );

        $table = [1 => [330, 10330], 2 => [539, 17539], 3 => [778, 25778], 4 => [927, 30927], 5 => [1076, 36076]];

        foreach ($table as $children => [$fee, $total]) {
            foreach ([false, true] as $coverFees) {
                $quote = FormPayment::quote($form, $this->attendees($children), $coverFees, true);

                $this->assertSame($fee, $quote['fee_covered_minor'], "{$children} children, cover_fees " . var_export($coverFees, true));
                $this->assertSame($total, $quote['total_minor']);
                $this->assertCount(2, $quote['line_items']);
                $this->assertSame(
                    $quote['amount_due_minor'],
                    $quote['total_minor'] - StripeFees::on($quote['total_minor']),
                    "{$children} children: the school nets the tier price at 2.9% + 30¢"
                );
            }
        }
    }

    #[Test]
    public function the_required_fee_is_never_added_off_the_card_path_and_never_widens_the_optional_box(): void
    {
        $fee = ['perEntryOfSection' => 'attendees', 'countTiers' => self::FAMILY_TIERS];
        $data = $this->attendees(3);

        $required = $this->form($fee, ['online' => true, 'requireFeeCoverage' => true]);
        $this->assertSame(0, FormPayment::quote($required, $data, true, false)['fee_covered_minor'], 'cash and the office have no card fee');

        // The switch on a form that takes no card: nothing to add it to.
        $noCard = $this->form($fee, ['staffCodes' => true, 'officePayment' => true, 'requireFeeCoverage' => true]);
        $this->assertFalse($noCard->requiresFeeCoverage());
        $this->assertSame(0, FormPayment::quote($noCard, $data, true, true)['fee_covered_minor']);

        // Required is its own key: an old renderer must never be told to draw an optional box.
        $this->assertTrue($required->requiresFeeCoverage());
        $this->assertFalse($required->allowsFeeCoverage());

        // Without the switch the optional box behaves as it always has.
        $optional = $this->form($fee, ['online' => true, 'allowFeeCoverage' => true]);
        $this->assertSame(0, FormPayment::quote($optional, $data, false, true)['fee_covered_minor']);
        $this->assertSame(778, FormPayment::quote($optional, $data, true, true)['fee_covered_minor']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * A festival form in memory: a person, then a repeatable attendee list.
     *
     * @param  array<string,mixed>  $fee
     * @param  array<string,mixed>  $payment
     */
    private function form(array $fee, array $payment = [], ?string $timezone = 'America/New_York', ?array $sections = null): Form
    {
        $form = new Form();
        $form->name = 'Fall Festival 2026';
        $form->schema = ['sections' => $sections ?? [
            [
                'id' => 'main',
                'title' => 'You',
                'fields' => [['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
            ],
            [
                'id' => 'attendees',
                'title' => 'Attendees',
                'repeatable' => true,
                'minEntries' => 1,
                'fields' => [['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]],
            ],
        ]];
        $form->settings = array_filter(['fee' => $fee, 'payment' => $payment ?: null]);
        $form->setRelation('masjid', (new Masjid())->forceFill(['timezone' => $timezone]));

        return $form;
    }

    /** @return array<string,mixed> a cleaned submission with $count attendees */
    private function attendees(int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['name' => "Guest {$i}"];
        }

        return ['fullName' => 'Amal Yusuf', 'attendees' => $rows];
    }
}
