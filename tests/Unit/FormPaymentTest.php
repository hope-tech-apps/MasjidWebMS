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
