<?php

namespace App\Support;

use App\Models\HistoricalOrder;
use Carbon\CarbonImmutable;

/**
 * Reads MEC's Wix order exports into one plain shape the order-history importer
 * can map (App\Services\Crm\WixOrderHistoryImporter).
 *
 * Two files, exactly as the read-only Wix pull wrote them (workspace
 * `raw/stores/orders.json` and `raw/events/{orders,events}.json`, see the
 * migration's reports/stores.md and reports/events.md):
 *
 *   stores/orders.json  `{columns: [...], rows: [[...], ...]}` — one row per
 *                       Wix Stores order, columns found BY NAME. Line items are
 *                       one string, `2x Fall Festival Ticket @10; 1x … [options]`.
 *   events/orders.json  `{orders: [{no, ev, cr, fn, ln, em, st, meth, tot, inv}]}`
 *                       where `inv` is `[items[[name, qty, unitPrice]], subTotal,
 *                       grandTotal, fees[[name, type, amount]], discount]`.
 *   events/events.json  `{events: [{id, title, dateAndTimeSettings.startDate}]}`,
 *                       for the event each ticket order belongs to.
 *
 * WHICH PROCESSOR TOOK THE MONEY. The Wix Events export names the method on each
 * order: `payPal` is PayPal, and `creditCard` does not say whose card terminal it
 * went through, so it is recorded as `wix` (paid at the Wix checkout) with method
 * `card`. The Stores projection carries no payment column at all — Wix reports
 * 410 Square and 275 PayPal payments across the 685 orders only in aggregate — so
 * every store order is `wix` too, UNLESS the pull that feeds the real run adds a
 * column named `paymentProvider` (`Square` / `PayPal`, from Wix's
 * list-payments-by-order-ids), in which case each order carries its own. Nothing
 * here guesses a processor.
 *
 * NOTHING PERSONAL IS KEPT beyond what linking needs: the buyer's email (to find
 * or create the contact) and name (to name a contact that must be created).
 * Addresses, phone numbers, buyer notes and the Wix Events checkout answers
 * (emergency contacts on the 2024 zoo trip) are never read into the result.
 *
 * STRICT. Money must be a plain decimal of at most two places, and every order
 * must add up: store lines less the discount equal the total; event items equal
 * the subtotal, and the subtotal less the discount plus the fee Wix added at
 * checkout equals the grand total. An order that does not is reported in
 * `problems` by its order number and reason, never repaired, and the importer
 * refuses to write while any problem stands.
 */
class WixOrderExport
{
    private const LINE_PATTERN = '/^(\d+)x (.+?) @(\d+(?:\.\d{1,2})?)(?: \[(.*)\])?$/s';

    /**
     * @param  array<string, mixed>|null  $stores  decoded stores/orders.json
     * @param  array<string, mixed>|null  $events  decoded events/orders.json
     * @param  array<string, mixed>|null  $catalog decoded events/events.json
     * @return array{orders: list<array<string, mixed>>, problems: list<string>}
     */
    public static function read(?array $stores, ?array $events, ?array $catalog): array
    {
        $orders = [];
        $problems = [];

        if ($stores !== null) {
            [$storeOrders, $storeProblems] = self::readStores($stores);
            array_push($orders, ...$storeOrders);
            array_push($problems, ...$storeProblems);
        }

        if ($events !== null) {
            [$eventOrders, $eventProblems] = self::readEvents($events, $catalog ?? []);
            array_push($orders, ...$eventOrders);
            array_push($problems, ...$eventProblems);
        }

        return ['orders' => $orders, 'problems' => $problems];
    }

    /**
     * Read the export directory the Wix pull writes: `stores/orders.json`,
     * `events/orders.json`, `events/events.json`. A missing half is skipped, so
     * one channel can be imported on its own; a present file that is not JSON is
     * a problem, not a skip.
     *
     * @return array{orders: list<array<string, mixed>>, problems: list<string>}
     */
    public static function fromDirectory(string $dir): array
    {
        $problems = [];
        $load = function (string $relative) use ($dir, &$problems): ?array {
            $path = rtrim($dir, '/') . '/' . $relative;

            if (! is_file($path)) {
                return null;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                $problems[] = "{$relative} is not a JSON object.";

                return null;
            }

            return $decoded;
        };

        $stores = $load('stores/orders.json');
        $events = $load('events/orders.json');
        $catalog = $load('events/events.json');

        if ($events !== null && $catalog === null) {
            $problems[] = 'events/orders.json needs events/events.json beside it, to name each event.';
            $events = null;
        }

        $read = self::read($stores, $events, $catalog);

        return ['orders' => $read['orders'], 'problems' => array_merge($problems, $read['problems'])];
    }

    /**
     * A plain decimal of dollars as integer cents, by string arithmetic — never a
     * float. Null for anything that is not `123`, `123.4` or `123.45`.
     */
    public static function toMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value * 100 : null;
        }

        if (! is_string($value) || ! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($value), $m)) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Lower-cased and trimmed, or null when there is no address to key on. */
    public static function normaliseEmail(mixed $email): ?string
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';

        return $email === '' ? null : $email;
    }

    // ------------------------------------------------------------------ stores

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private static function readStores(array $export): array
    {
        $columns = $export['columns'] ?? null;
        $rows = $export['rows'] ?? null;

        if (! is_array($columns) || ! is_array($rows)) {
            return [[], ['stores/orders.json has no columns/rows.']];
        }

        // Columns by the start of their name, so the legend Wix's projection
        // puts in brackets ("fulfillment(F=…)") does not have to be restated.
        $index = [];
        foreach ($columns as $i => $name) {
            $index[strtok((string) $name, '(')] = $i;
        }

        foreach (['number', 'createdDateUTC', 'buyerEmail', 'billingFirstName', 'billingLastName', 'lineItems', 'totalUSD', 'discountUSD', 'appliedDiscounts'] as $required) {
            if (! array_key_exists($required, $index)) {
                return [[], ["stores/orders.json has no {$required} column."]];
            }
        }

        $cell = fn (array $row, string $name) => array_key_exists($name, $index) ? ($row[$index[$name]] ?? null) : null;

        $orders = [];
        $problems = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $problems[] = 'stores/orders.json has a row that is not a list.';

                continue;
            }

            $number = trim((string) $cell($row, 'number'));
            $label = "Store order #{$number}";

            if ($number === '') {
                $problems[] = 'A store order has no number.';

                continue;
            }

            $orderedAt = self::instant($cell($row, 'createdDateUTC'));
            if ($orderedAt === null) {
                $problems[] = "{$label}: the order date is unreadable.";

                continue;
            }

            $lines = [];
            $unreadableLine = false;
            foreach (explode('; ', (string) $cell($row, 'lineItems')) as $raw) {
                if (! preg_match(self::LINE_PATTERN, trim($raw), $m)) {
                    $unreadableLine = true;

                    break;
                }

                $lines[] = [
                    'name' => trim($m[2]),
                    'quantity' => (int) $m[1],
                    'unit_minor' => self::toMinor($m[3]),
                    'options' => isset($m[4]) && trim($m[4]) !== '' ? trim($m[4]) : null,
                ];
            }

            if ($unreadableLine || $lines === []) {
                $problems[] = "{$label}: a line item is unreadable.";

                continue;
            }

            $total = self::toMinor($cell($row, 'totalUSD'));
            $discountCell = $cell($row, 'discountUSD');
            $discount = ($discountCell === null || $discountCell === '') ? 0 : self::toMinor($discountCell);

            if ($total === null || $discount === null) {
                $problems[] = "{$label}: an amount is unreadable.";

                continue;
            }

            $gross = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_minor'], $lines));
            if ($gross - $discount !== $total) {
                $problems[] = "{$label}: the lines less the discount do not equal the total.";

                continue;
            }

            [$provider, $method] = self::storeProvider($cell($row, 'paymentProvider'));
            if ($provider === null) {
                $problems[] = "{$label}: the payment provider is not Square or PayPal.";

                continue;
            }

            $orders[] = [
                'source' => HistoricalOrder::SOURCE_WIX_STORES,
                'order_number' => $number,
                'status' => HistoricalOrder::STATUS_PAID,
                'provider' => $provider,
                'payment_method' => $method,
                'ordered_at' => $orderedAt,
                'email' => self::normaliseEmail($cell($row, 'buyerEmail')),
                'first_name' => trim((string) $cell($row, 'billingFirstName')),
                'last_name' => trim((string) $cell($row, 'billingLastName')),
                'lines' => $lines,
                'discount_minor' => $discount,
                'discount_codes' => self::discountCodes((string) $cell($row, 'appliedDiscounts')),
                'fee_minor' => 0,
                'total_minor' => $total,
                'event' => null,
            ];
        }

        return [$orders, $problems];
    }

    /**
     * No column → `wix` (the export does not say). A column that names neither
     * processor is unreadable rather than guessed.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function storeProvider(mixed $value): array
    {
        if ($value === null) {
            return [HistoricalOrder::PROVIDER_WIX, null];
        }

        return match (strtolower(trim((string) $value))) {
            'square' => [HistoricalOrder::PROVIDER_SQUARE, 'card'],
            'paypal' => [HistoricalOrder::PROVIDER_PAYPAL, 'paypal'],
            default => [null, null],
        };
    }

    /** `Intellicor:15` (or several, comma-separated) → ['Intellicor' => 1500]. */
    private static function discountCodes(string $cell): array
    {
        $codes = [];

        foreach (preg_split('/[,;]\s*/', trim($cell)) ?: [] as $part) {
            $at = strrpos($part, ':');
            if ($at === false) {
                continue;
            }

            $code = trim(substr($part, 0, $at));
            $amount = self::toMinor(substr($part, $at + 1));

            if ($code !== '' && $amount !== null) {
                $codes[$code] = ($codes[$code] ?? 0) + $amount;
            }
        }

        return $codes;
    }

    // ------------------------------------------------------------------ events

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private static function readEvents(array $export, array $catalog): array
    {
        $events = [];
        foreach (($catalog['events'] ?? []) as $event) {
            if (! is_array($event) || ! isset($event['id'], $event['title'])) {
                continue;
            }

            $events[(string) $event['id']] = [
                'id' => (string) $event['id'],
                'title' => trim((string) $event['title']),
                'starts_at' => self::instant($event['dateAndTimeSettings']['startDate'] ?? null),
            ];
        }

        $orders = [];
        $problems = [];

        foreach (($export['orders'] ?? []) as $order) {
            if (! is_array($order)) {
                $problems[] = 'events/orders.json has an order that is not an object.';

                continue;
            }

            $number = trim((string) ($order['no'] ?? ''));
            $label = "Event order {$number}";

            if ($number === '') {
                $problems[] = 'An event order has no number.';

                continue;
            }

            $event = $events[(string) ($order['ev'] ?? '')] ?? null;
            if ($event === null || $event['starts_at'] === null) {
                $problems[] = "{$label}: its event is not in events/events.json.";

                continue;
            }

            $status = match ((string) ($order['st'] ?? '')) {
                'PAID' => HistoricalOrder::STATUS_PAID,
                'CANCELED' => HistoricalOrder::STATUS_CANCELED,
                'DECLINED' => HistoricalOrder::STATUS_DECLINED,
                default => null,
            };
            if ($status === null) {
                $problems[] = "{$label}: status is neither paid, canceled nor declined.";

                continue;
            }

            $orderedAt = self::instant($order['cr'] ?? null);
            if ($orderedAt === null) {
                $problems[] = "{$label}: the order date is unreadable.";

                continue;
            }

            $invoice = $order['inv'] ?? null;
            if (! is_array($invoice) || count($invoice) < 4 || ! is_array($invoice[0])) {
                $problems[] = "{$label}: the invoice is missing.";

                continue;
            }

            [$items, $subTotal, $grandTotal, $fees] = $invoice;
            $discount = self::eventDiscount($invoice[4] ?? null);

            $lines = [];
            foreach ($items as $item) {
                if (! is_array($item) || count($item) < 3) {
                    $lines = null;

                    break;
                }

                $lines[] = [
                    'name' => trim((string) $item[0]),
                    'quantity' => (int) $item[1],
                    'unit_minor' => self::toMinor((string) $item[2]),
                    'options' => null,
                ];
            }

            $sub = self::toMinor((string) $subTotal);
            $grand = self::toMinor((string) $grandTotal);

            $added = 0;
            $feesReadable = is_array($fees);
            foreach ($feesReadable ? $fees : [] as $fee) {
                $amount = is_array($fee) ? self::toMinor((string) ($fee[2] ?? '')) : null;
                if ($amount === null) {
                    $feesReadable = false;

                    break;
                }

                // A fee INCLUDED in the ticket price was absorbed by MEC; one ADDED
                // at checkout was paid by the buyer on top, and is in the total.
                if (($fee[1] ?? '') !== 'FEE_INCLUDED') {
                    $added += $amount;
                }
            }

            if ($lines === null || $lines === [] || in_array(null, array_column($lines, 'unit_minor'), true)
                || $sub === null || $grand === null || $discount === null || ! $feesReadable) {
                $problems[] = "{$label}: an amount is unreadable.";

                continue;
            }

            $gross = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_minor'], $lines));
            if ($gross !== $sub || $sub - $discount + $added !== $grand) {
                $problems[] = "{$label}: the invoice does not add up.";

                continue;
            }

            [$provider, $method] = match ((string) ($order['meth'] ?? '')) {
                'payPal' => [HistoricalOrder::PROVIDER_PAYPAL, 'paypal'],
                'creditCard' => [HistoricalOrder::PROVIDER_WIX, 'card'],
                default => [HistoricalOrder::PROVIDER_WIX, null],
            };

            $orders[] = [
                'source' => HistoricalOrder::SOURCE_WIX_EVENTS,
                'order_number' => $number,
                'status' => $status,
                'provider' => $provider,
                'payment_method' => $method,
                'ordered_at' => $orderedAt,
                'email' => self::normaliseEmail($order['em'] ?? null),
                'first_name' => trim((string) ($order['fn'] ?? '')),
                'last_name' => trim((string) ($order['ln'] ?? '')),
                'lines' => $lines,
                'discount_minor' => $discount,
                'discount_codes' => [],
                'fee_minor' => $added,
                'total_minor' => $grand,
                'event' => $event,
            ];
        }

        return [$orders, $problems];
    }

    /** Wix Events' invoice discount: absent/null is none; a decimal or `{amount}` is read. */
    private static function eventDiscount(mixed $discount): ?int
    {
        if ($discount === null || $discount === '') {
            return 0;
        }

        if (is_array($discount)) {
            $discount = $discount['amount']['value'] ?? $discount['amount'] ?? null;
        }

        return self::toMinor(is_scalar($discount) ? (string) $discount : null);
    }

    /**
     * An ISO-8601 instant (`2017-08-28T00:38`, `2024-07-11T22:49:59.947Z`) read in
     * UTC, or null. The shape is checked first: Carbon alone would also accept
     * phrases like "next friday".
     */
    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', trim($value))) {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
