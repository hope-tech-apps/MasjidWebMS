<?php

namespace Tests\Unit;

use App\Models\HistoricalOrder;
use App\Support\WixOrderExport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Wix export reader (App\Support\WixOrderExport): the export's own shapes
 * in, one plain order shape out, and every order that does not add up reported
 * rather than repaired. Synthetic values only.
 */
class WixOrderExportTest extends TestCase
{
    private const COLUMNS = [
        'number', 'createdDateUTC', 'buyerEmail', 'billingFirstName', 'billingLastName',
        'billingAddress', 'lineItems(qty x name @unitPrice [options])', 'totalUSD', 'discountUSD',
        'appliedDiscounts(code:amount)', 'buyerNote', 'paymentStatus', 'refundedUSD',
    ];

    private function stores(array ...$rows): array
    {
        return ['columns' => self::COLUMNS, 'rows' => $rows];
    }

    private function row(string $lines, string $total, string $discount = '0', string $codes = '', string $status = 'PAID', string $refunded = '0'): array
    {
        return [10077, '2023-04-20T13:45', ' Someone@Example.TEST ', 'Some', 'One', '9 Invented Rd', $lines, $total, $discount, $codes, 'a note', $status, $refunded];
    }

    #[Test]
    public function money_is_read_as_integer_cents_without_floats(): void
    {
        $this->assertSame(1700, WixOrderExport::toMinor('17'));
        $this->assertSame(1750, WixOrderExport::toMinor('17.5'));
        $this->assertSame(1705, WixOrderExport::toMinor('17.05'));
        $this->assertSame(190000, WixOrderExport::toMinor('1900.00'));
        $this->assertSame(1500, WixOrderExport::toMinor(15));
        $this->assertNull(WixOrderExport::toMinor('17.555'));
        $this->assertNull(WixOrderExport::toMinor('-5'));
        $this->assertNull(WixOrderExport::toMinor('1e3'));
        $this->assertNull(WixOrderExport::toMinor(17.5), 'a float is never money here');
    }

    #[Test]
    public function a_store_order_is_read_into_lines_with_options_and_a_normalised_email(): void
    {
        $read = WixOrderExport::read($this->stores($this->row(
            '1x Full Iftar @1900 [Pick Your Date: |Pick a Second Option Date ]; 2x Individual Iftar @18',
            '1936',
        )), null, null);

        $this->assertSame([], $read['problems']);
        $order = $read['orders'][0];

        $this->assertSame('10077', $order['order_number']);
        $this->assertSame(HistoricalOrder::STATUS_PAID, $order['status'], 'PAID with nothing refunded');
        $this->assertSame('someone@example.test', $order['email']);
        $this->assertSame('2023-04-20 13:45:00', $order['ordered_at']->format('Y-m-d H:i:s'));
        $this->assertSame(193600, $order['total_minor']);
        $this->assertSame(HistoricalOrder::PROVIDER_WIX, $order['provider'], 'the stores export does not name the processor');
        $this->assertSame([
            ['name' => 'Full Iftar', 'quantity' => 1, 'unit_minor' => 190000, 'options' => 'Pick Your Date: |Pick a Second Option Date'],
            ['name' => 'Individual Iftar', 'quantity' => 2, 'unit_minor' => 1800, 'options' => null],
        ], $order['lines']);
        $this->assertArrayNotHasKey('address', $order);
        $this->assertStringNotContainsString('Invented', json_encode($order));
        $this->assertStringNotContainsString('a note', json_encode($order));
    }

    #[Test]
    public function a_coupon_code_with_a_stray_space_is_read_and_the_discount_must_reconcile(): void
    {
        $read = WixOrderExport::read($this->stores($this->row('3x HAJJ SIMULATION & EID ADHA BAZAAR @20', '45', '15', 'Intellicore :15')), null, null);

        $this->assertSame([], $read['problems']);
        $this->assertSame(['Intellicore' => 1500], $read['orders'][0]['discount_codes']);
        $this->assertSame(1500, $read['orders'][0]['discount_minor']);

        $wrong = WixOrderExport::read($this->stores($this->row('3x HAJJ SIMULATION & EID ADHA BAZAAR @20', '60', '15')), null, null);
        $this->assertSame([], $wrong['orders']);
        $this->assertSame(['Store order #10077: the lines less the discount do not equal the total.'], $wrong['problems']);
    }

    #[Test]
    public function a_store_export_without_payment_status_and_refunds_is_refused_rather_than_assumed_paid(): void
    {
        foreach (['paymentStatus', 'refundedUSD'] as $missing) {
            $columns = array_values(array_diff(self::COLUMNS, [$missing]));
            $row = $this->row('1x Individual Iftar @18', '18');
            unset($row[array_search($missing, self::COLUMNS, true)]);

            $read = WixOrderExport::read(['columns' => $columns, 'rows' => [array_values($row)]], null, null);

            $this->assertSame([], $read['orders'], "no {$missing}: nothing is read as paid");
            $this->assertSame(["stores/orders.json has no {$missing} column."], $read['problems']);
        }
    }

    #[Test]
    public function only_a_paid_store_order_with_nothing_refunded_is_read_as_paid(): void
    {
        foreach ([
            ['NOT_PAID', '0', 'its payment status is NOT_PAID'],
            ['PENDING', '0', 'its payment status is PENDING'],
            ['FULLY_REFUNDED', '18', 'its payment status is FULLY_REFUNDED with a refund'],
            ['PAID', '5', 'its payment status is PAID with a refund'],
            ['', '0', 'its payment status is blank'],
        ] as [$status, $refunded, $said]) {
            $read = WixOrderExport::read($this->stores($this->row('1x Individual Iftar @18', '18', '0', '', $status, $refunded)), null, null);

            $this->assertSame([], $read['orders'], "{$status}/{$refunded} is not booked");
            $this->assertStringContainsString("Store order #10077: {$said}", $read['problems'][0] ?? '');
        }

        $unreadable = WixOrderExport::read($this->stores($this->row('1x Individual Iftar @18', '18', '0', '', 'PAID', '')), null, null);
        $this->assertSame(['Store order #10077: the refunded amount is unreadable.'], $unreadable['problems']);

        $paid = WixOrderExport::read($this->stores($this->row('1x Individual Iftar @18', '18', '0', '', 'paid', '0.00')), null, null);
        $this->assertSame(HistoricalOrder::STATUS_PAID, $paid['orders'][0]['status']);
    }

    #[Test]
    public function a_named_processor_column_is_used_and_an_unknown_one_is_refused(): void
    {
        $columns = [...self::COLUMNS, 'paymentProvider'];

        $square = WixOrderExport::read(['columns' => $columns, 'rows' => [[...$this->row('1x Individual Iftar @18', '18'), 'Square']]], null, null);
        $this->assertSame(HistoricalOrder::PROVIDER_SQUARE, $square['orders'][0]['provider']);

        $odd = WixOrderExport::read(['columns' => $columns, 'rows' => [[...$this->row('1x Individual Iftar @18', '18'), 'Venmo']]], null, null);
        $this->assertSame([], $odd['orders']);
        $this->assertStringContainsString('not Square or PayPal', $odd['problems'][0]);
    }

    #[Test]
    public function a_wix_events_order_reads_its_method_fee_and_event(): void
    {
        $catalog = ['events' => [['id' => 'ev1', 'title' => 'NC Zoo & Picnic Trip', 'dateAndTimeSettings' => ['startDate' => '2024-07-27T15:00:00Z']]]];
        $events = ['orders' => [
            ['no' => 'A1', 'ev' => 'ev1', 'cr' => '2024-07-11T22:49:59.947Z', 'fn' => 'A', 'ln' => 'B', 'em' => 'a@example.test',
                'st' => 'PAID', 'meth' => 'creditCard', 'tot' => '35.00', 'form' => ['custom-x' => 'private answer'],
                'inv' => [[['Zoo Admission & Lunch Ticket', 1, '20.00'], ['Zoo Admission & Lunch Ticket', 1, '15.00']], '35.00', '35.00',
                    [['WIX_FEE', 'FEE_INCLUDED', '0.88']], null]],
            ['no' => 'A2', 'ev' => 'ev1', 'cr' => '2024-07-12T10:00:00.000Z', 'fn' => 'A', 'ln' => 'B', 'em' => 'a@example.test',
                'st' => 'DECLINED', 'meth' => '', 'tot' => '20.00',
                'inv' => [[['Zoo Admission & Lunch Ticket', 1, '20.00']], '20.00', '20.00', [['WIX_FEE', 'FEE_INCLUDED', '0.50']], null]],
            ['no' => 'A3', 'ev' => 'missing', 'cr' => '2024-07-12T10:00:00.000Z', 'st' => 'PAID',
                'inv' => [[['X', 1, '1.00']], '1.00', '1.00', [], null]],
        ]];

        $read = WixOrderExport::read(null, $events, $catalog);

        $this->assertSame(['Event order A3: its event is not in events/events.json.'], $read['problems']);
        [$paid, $declined] = $read['orders'];

        $this->assertSame(HistoricalOrder::PROVIDER_WIX, $paid['provider'], 'a card at the Wix checkout does not say whose terminal');
        $this->assertSame('card', $paid['payment_method']);
        $this->assertSame(0, $paid['fee_minor'], 'a fee included in the price is not on top of it');
        $this->assertSame(3500, $paid['total_minor']);
        $this->assertSame('NC Zoo & Picnic Trip', $paid['event']['title']);
        $this->assertStringNotContainsString('private answer', json_encode($paid));

        $this->assertSame(HistoricalOrder::STATUS_DECLINED, $declined['status']);
    }

    #[Test]
    public function an_event_invoice_that_does_not_add_up_is_reported(): void
    {
        $catalog = ['events' => [['id' => 'ev1', 'title' => 'Fair', 'dateAndTimeSettings' => ['startDate' => '2024-10-12T15:00:00Z']]]];
        $events = ['orders' => [
            ['no' => 'B1', 'ev' => 'ev1', 'cr' => '2024-10-01T12:00:00.000Z', 'st' => 'PAID', 'meth' => 'payPal', 'em' => 'b@example.test',
                'inv' => [[['Bracelet', 2, '25.00']], '50.00', '51.00', [['WIX_FEE', 'FEE_ADDED_AT_CHECKOUT', '1.25']], null]],
        ]];

        $read = WixOrderExport::read(null, $events, $catalog);

        $this->assertSame([], $read['orders']);
        $this->assertSame(['Event order B1: the invoice does not add up.'], $read['problems']);
    }

    #[Test]
    public function a_date_that_is_not_an_iso_instant_is_refused_rather_than_guessed(): void
    {
        $row = $this->row('1x Individual Iftar @18', '18');
        $row[1] = 'next friday';

        $read = WixOrderExport::read($this->stores($row), null, null);

        $this->assertSame([], $read['orders']);
        $this->assertSame(['Store order #10077: the order date is unreadable.'], $read['problems']);
    }
}
