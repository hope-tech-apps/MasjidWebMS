<?php

namespace App\Services\Member;

use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\HistoricalOrder;
use App\Models\MealOrder;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * WHAT A MEMBER IS SHOWN of a purchase or a gift, field by field.
 *
 * No model is ever serialised here or anywhere near it. `Order` hides four fields and
 * `Donation` hides none, so a raw model would carry the Stripe session and payment-intent
 * ids, `Donation` would carry its Stripe ids and fees, and `order_items.payload` holds
 * attendee names and answers. Every value below is read by name from a row that
 * MemberPurchases has already decided is the caller's, and anything added to a table later
 * reaches no member until it is written here.
 *
 * What is deliberately absent, so it is not "restored" as an omission:
 *   - `fee_minor`. It means MANARA'S application fee on `orders` and the fee WIX added to
 *     the buyer's total on `historical_orders`, and the migration that says the two
 *     "mirror" each other is wrong about it. Neither is shown as a fee.
 *   - Stripe ids, connected accounts, fingerprints, payloads, answers, line options, the
 *     import batch and what a Wix line was recorded as, buyer names and phones (typed at a
 *     basket page, never verified), and the bearer uuids of a form response and a meal
 *     order.
 *
 * Statuses, one vocabulary: `paid`, `refunded`, `partially_refunded`, `disputed`,
 * `canceled`, `declined`. A row is never reported as paid where its own record says
 * otherwise, and a Wix order that was canceled or declined keeps that word and a note that
 * no money was taken (its total is what the order would have cost, and never moved).
 *
 * Amounts are integer minor units all the way to the client. Dates are the calendar day in
 * the ORGANISATION's timezone, `YYYY-MM-DD`: a UTC instant rendered as a date shows a
 * member the next day for an evening purchase, and a date-only column has no instant to
 * convert at all.
 */
class MemberPurchaseProjector
{
    /** The one sentence for a cart, form or meal purchase (slice 6 brief). */
    public const RECEIPT_NOTE_PURCHASE = 'A confirmation was emailed; there is no tax receipt for a purchase.';

    public const RECEIPT_NOTE_WIX_PAID = 'This order was paid at the organisation\'s old Wix checkout. It cannot be refunded or reissued here, and no tax receipt is issued for it.';

    public const RECEIPT_NOTE_WIX_UNPAID = 'This order was canceled or declined at the organisation\'s old Wix checkout, so no payment was taken for it.';

    public const RECEIPT_NOTE_WIX_UNKNOWN = 'The organisation\'s old Wix checkout did not record whether this order was paid, so it is not shown as paid.';

    public const RECEIPT_NOTE_GIFT_WIX = 'This gift was made at the organisation\'s old Wix checkout, before it moved to this system, so no tax receipt is issued for it here.';

    public const RECEIPT_NOTE_GIFT_NONE = 'No tax receipt has been issued for this gift.';

    /** What a line is called when the buyer chose to cover the card fee or gave an extra with a meal. */
    public const LINE_FEE_COVERED = 'Card fee covered';

    public const LINE_MEAL_DONATION = 'Optional donation';

    /** How many line labels the list shows before it says how many there are. */
    private const SUMMARY_LABELS = 3;

    /**
     * One row of the list.
     *
     * @return array{source: string, id: string, number: ?string, date: ?string, status: string, total_minor: int, currency: string, summary: array{labels: list<string>, count: int}}
     */
    public function orderRow(string $source, Model $model, string $timezone): array
    {
        $view = $this->view($source, $model, $timezone);

        return [
            'source' => $source,
            'id' => $view['id'],
            'number' => $view['number'],
            'date' => $view['date'],
            'status' => $view['status'],
            'total_minor' => $view['total_minor'],
            'currency' => $view['currency'],
            'summary' => [
                'labels' => array_slice(array_column($view['lines'], 'label'), 0, self::SUMMARY_LABELS),
                'count' => count($view['lines']),
            ],
        ];
    }

    /**
     * One order in full: the lines, then the totals.
     *
     * `subtotal_minor` is the lines added up and `total_minor` is what the record says was
     * paid. They are two figures on purpose: a Wix total also holds the fee Wix added at
     * checkout, which is not shown (see the class comment), so the two need not agree.
     *
     * @return array<string, mixed>
     */
    public function orderDetail(string $source, Model $model, string $timezone): array
    {
        $view = $this->view($source, $model, $timezone);

        return [
            'source' => $source,
            'id' => $view['id'],
            'number' => $view['number'],
            'date' => $view['date'],
            'status' => $view['status'],
            'currency' => $view['currency'],
            'lines' => $view['lines'],
            'totals' => [
                'subtotal_minor' => array_sum(array_column($view['lines'], 'line_minor')),
                'discount_minor' => $view['discount_minor'],
                'total_minor' => $view['total_minor'],
            ],
            'receipt_note' => $view['receipt_note'],
        ];
    }

    /**
     * One succeeded gift.
     *
     * `receipt.id` is the gift's own uuid: a receipt has no handle of its own, is one to one
     * with its gift, and the row number of either is not something to put in a URL. It is
     * the key of GET me/receipts/{id}/pdf.
     *
     * An imported Wix gift never gets a receipt (ReceiptService::issueFor declines it, and
     * the admin download refuses it), so it is told so instead of being shown an empty
     * space where a document should be.
     *
     * @return array<string, mixed>
     */
    public function gift(Donation $gift, string $timezone): array
    {
        $receipt = $gift->isHistorical() ? null : $gift->receipt;

        return [
            'id' => (string) $gift->uuid,
            'date' => $gift->donated_at?->toDateString() ?? $this->localDay($gift->created_at, $timezone),
            'fund_name' => $gift->fund?->name,
            // What left the giver's card, which is what the receipt's gross says. A giver who
            // covered the processing fee gave the fund less than this; the fee is not shown.
            'amount_minor' => (int) $gift->charged_amount,
            'currency' => (string) $gift->currency,
            'is_zakat' => (bool) $gift->is_zakat,
            'receipt' => $receipt === null ? null : [
                'id' => (string) $gift->uuid,
                'serial' => (int) $receipt->serial_number,
            ],
            'receipt_note' => match (true) {
                $receipt !== null => null,
                $gift->isHistorical() => self::RECEIPT_NOTE_GIFT_WIX,
                default => self::RECEIPT_NOTE_GIFT_NONE,
            },
        ];
    }

    // ------------------------------------------------------------ per source

    /**
     * What every source reduces to before it becomes a row or a detail.
     *
     * @return array{id: string, number: ?string, date: ?string, status: string, currency: string, lines: list<array{label: string, quantity: int, unit_minor: int, line_minor: int}>, discount_minor: int, total_minor: int, receipt_note: string}
     */
    private function view(string $source, Model $model, string $timezone): array
    {
        return match (true) {
            $model instanceof Order => $this->cartView($model, $timezone),
            $model instanceof HistoricalOrder => $this->wixView($model, $timezone),
            $model instanceof FormResponse => $this->formView($model, $timezone),
            $model instanceof MealOrder => $this->mealView($model, $timezone),
            default => throw new \InvalidArgumentException("No projection for a {$source} order."),
        };
    }

    private function cartView(Order $order, string $timezone): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            $lines[] = $this->line(
                (string) $item->label,
                (int) $item->quantity,
                (int) $item->unit_amount_minor,
                (int) $item->total_minor
            );
        }

        return [
            'id' => (string) $order->uuid,
            'number' => (string) $order->order_number,
            'date' => $this->localDay($order->paid_at ?? $order->created_at, $timezone),
            // A refund or dispute on the basket's one charge is flagged on the ORDER (the event
            // names an amount, never a line), so it is the order's status. The amount refunded
            // is not shown: staff reconcile the lines by hand.
            'status' => match ($order->charge_flag) {
                Order::CHARGE_FLAG_REFUNDED => 'refunded',
                Order::CHARGE_FLAG_PARTIALLY_REFUNDED => 'partially_refunded',
                Order::CHARGE_FLAG_DISPUTED => 'disputed',
                default => 'paid',
            },
            'currency' => (string) $order->currency,
            'lines' => $lines,
            'discount_minor' => 0,
            'total_minor' => (int) $order->total_minor,
            'receipt_note' => self::RECEIPT_NOTE_PURCHASE,
        ];
    }

    private function wixView(HistoricalOrder $order, string $timezone): array
    {
        $lines = [];

        foreach ((array) $order->lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = max(0, (int) ($line['quantity'] ?? 0));
            $unit = max(0, (int) ($line['unit_minor'] ?? 0));

            // The line's own `options`, `discount_minor`, `recorded_as` and `recorded_in` are
            // the importer's bookkeeping and are not shown. The order's discount is, once.
            $lines[] = $this->line((string) ($line['name'] ?? ''), $quantity, $unit, $quantity * $unit);
        }

        // Anything the importer did not write is not paid: a word it does not know must
        // never be read as a payment.
        $status = in_array($order->status, [
            HistoricalOrder::STATUS_PAID,
            HistoricalOrder::STATUS_CANCELED,
            HistoricalOrder::STATUS_DECLINED,
        ], true) ? (string) $order->status : 'unknown';

        return [
            'id' => (string) $order->id,
            'number' => (string) $order->order_number,
            'date' => $this->localDay($order->ordered_at, $timezone),
            'status' => $status,
            'currency' => (string) $order->currency,
            'lines' => $lines,
            'discount_minor' => (int) $order->discount_minor,
            'total_minor' => (int) $order->total_minor,
            'receipt_note' => match ($status) {
                HistoricalOrder::STATUS_PAID => self::RECEIPT_NOTE_WIX_PAID,
                HistoricalOrder::STATUS_CANCELED, HistoricalOrder::STATUS_DECLINED => self::RECEIPT_NOTE_WIX_UNPAID,
                default => self::RECEIPT_NOTE_WIX_UNKNOWN,
            },
        ];
    }

    private function formView(FormResponse $response, string $timezone): array
    {
        $formName = trim((string) $response->form?->name);
        $formName = $formName !== '' ? $formName : 'Registration';

        // The cents the row froze at submit, else the legacy dollars column: a row written
        // before the money leg has only that.
        $due = $response->amount_due_minor !== null
            ? (int) $response->amount_due_minor
            : (int) round(((float) $response->amount_due) * 100);
        $feeCovered = (int) $response->fee_covered_minor;

        $lines = [];
        $breakdown = $response->priceBreakdown();

        if ($breakdown !== null) {
            $tier = trim((string) $breakdown['label']);

            $lines[] = $this->line(
                $tier !== '' ? "{$formName} ({$tier})" : $formName,
                $breakdown['quantity'],
                $breakdown['unit_minor'],
                $breakdown['unit_minor'] * $breakdown['quantity']
            );
        } else {
            $lines[] = $this->line($formName, 1, $due, $due);
        }

        if ($feeCovered > 0) {
            $lines[] = $this->line(self::LINE_FEE_COVERED, 1, $feeCovered, $feeCovered);
        }

        $currency = strtolower(trim((string) $response->currency));

        return [
            'id' => (string) $response->id,
            // The registration number the confirmation e-mail prints.
            'number' => (string) $response->id,
            'date' => $this->localDay($response->paid_at ?? $response->submitted_at, $timezone),
            // A refunded card payer is cancelled AND still reads as paid (FormResponse::isCancelled),
            // so the refund is asked about first.
            'status' => match (true) {
                $response->charge_flag === FormResponse::CHARGE_FLAG_REFUNDED => 'refunded',
                $response->charge_flag === FormResponse::CHARGE_FLAG_DISPUTED => 'disputed',
                $response->isCancelled() => 'canceled',
                default => 'paid',
            },
            'currency' => $currency !== '' ? $currency : strtolower((string) config('services.stripe.currency', 'usd')),
            'lines' => $lines,
            'discount_minor' => 0,
            'total_minor' => $response->total_minor !== null ? (int) $response->total_minor : $due + $feeCovered,
            'receipt_note' => self::RECEIPT_NOTE_PURCHASE,
        ];
    }

    private function mealView(MealOrder $order, string $timezone): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            $lines[] = $this->line(
                (string) $item->item_name,
                (int) $item->quantity,
                (int) $item->unit_price_minor,
                (int) $item->line_total_minor
            );
        }

        // The two extras a customer can add on top of the dishes are part of what their card
        // paid, so they are lines: the lines add up to the total and nothing is unexplained.
        if ((int) $order->donation_minor > 0) {
            $lines[] = $this->line(self::LINE_MEAL_DONATION, 1, (int) $order->donation_minor, (int) $order->donation_minor);
        }

        if ((int) $order->fee_covered_minor > 0) {
            $lines[] = $this->line(self::LINE_FEE_COVERED, 1, (int) $order->fee_covered_minor, (int) $order->fee_covered_minor);
        }

        return [
            'id' => (string) $order->id,
            // The pickup number the kitchen calls out, unique within one menu, not one order.
            'number' => $order->order_number === null ? null : (string) $order->order_number,
            'date' => $this->localDay($order->paid_at ?? $order->placed_at ?? $order->created_at, $timezone),
            'status' => $order->status === MealOrder::STATUS_CANCELLED ? 'canceled' : 'paid',
            'currency' => (string) $order->currency,
            'lines' => $lines,
            'discount_minor' => 0,
            // What was PAID: an order edited after payment records the total it settled at.
            'total_minor' => $order->settledMinor(),
            'receipt_note' => self::RECEIPT_NOTE_PURCHASE,
        ];
    }

    // ------------------------------------------------------------------ small

    /** @return array{label: string, quantity: int, unit_minor: int, line_minor: int} */
    private function line(string $label, int $quantity, int $unit, int $line): array
    {
        return [
            'label' => $label,
            'quantity' => $quantity,
            'unit_minor' => $unit,
            'line_minor' => $line,
        ];
    }

    /** The calendar day at the organisation, or null for an instant that was never recorded. */
    private function localDay(?CarbonInterface $at, string $timezone): ?string
    {
        return $at?->copy()->setTimezone($timezone)->toDateString();
    }
}
