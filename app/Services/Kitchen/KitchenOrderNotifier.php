<?php

namespace App\Services\Kitchen;

use App\Mail\KitchenOrderForCustomer;
use App\Mail\KitchenOrderForOffice;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Support\AcceptedPaymentMethods;
use App\Support\FormNotifier;
use App\Support\KitchenOrderLink;
use App\Support\MasjidTime;
use App\Support\PaymentMethods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The kitchen's messages (owner: "Pickup at MEC, 48h, office confirms").
 *
 *  - placed(): the office hears about a new order that needs confirming, and the
 *    customer is told it was received and that the office will confirm it. Sent
 *    when the order becomes REAL: on placement for an offline payment, on the
 *    webhook's payment for a card order (LunchOrderMailer::confirmation routes a
 *    kitchen order here), so an abandoned card page notifies nobody.
 *  - confirmed(): the customer is told the office confirmed it.
 *
 * Each send is CLAIMED on its own column with a conditional UPDATE before it is
 * queued (`office_notified_at`, `confirmation_sent_at`,
 * `customer_confirmed_sent_at`), exactly as LunchOrderMailer does, so Stripe's two
 * success events for one payment, a replayed webhook or a double press send once.
 * A failed queue push gives its claim back.
 *
 * Never at the cost of the order or the webhook: every failure is caught and
 * logged at warning, the level production runs at, with the exception's CLASS
 * only — a transport error can quote an address, and the log is not where that
 * belongs.
 */
final class KitchenOrderNotifier
{
    /** Bound on how many addresses one menu can notify: a typed list, not a mailing list. */
    public const MAX_RECIPIENTS = 5;

    public function placed(MealOrder $order): void
    {
        if ($order->status === MealOrder::STATUS_CANCELLED) {
            return;
        }

        $this->attempt('office', $order, function () use ($order): void {
            $menu = self::menu($order);
            $masjid = Masjid::withTrashed()->find($order->masjid_id);
            $recipients = self::officeRecipients($menu, $masjid);

            if ($recipients === [] || ! $this->claim($order, 'office_notified_at')) {
                return;
            }

            try {
                Mail::to($recipients)->queue($this->forOffice($order, $menu, $masjid));
            } catch (\Throwable $e) {
                $this->release($order, 'office_notified_at');

                throw $e;
            }
        });

        $this->attempt('received', $order, function () use ($order): void {
            $this->toCustomer($order, 'confirmation_sent_at', KitchenOrderForCustomer::RECEIVED);
        });
    }

    public function confirmed(MealOrder $order): void
    {
        if ($order->status === MealOrder::STATUS_CANCELLED) {
            return;
        }

        $this->attempt('confirmed', $order, function () use ($order): void {
            $this->toCustomer($order, 'customer_confirmed_sent_at', KitchenOrderForCustomer::CONFIRMED);
        });
    }

    /**
     * Who in the office hears about a new order: the menu's `notify_emails`, as
     * typed ("a@x, b@y"), else the organisation's own address — so a catalogue
     * can never be configured into notifying nobody (FormNotifier's rule).
     *
     * @return list<string>
     */
    public static function officeRecipients(?MealMenu $menu, ?Masjid $masjid): array
    {
        $typed = preg_split('/[,;\s]+/', (string) ($menu?->notify_emails ?? '')) ?: [];

        $emails = collect($typed)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->take(self::MAX_RECIPIENTS)
            ->values()
            ->all();

        if ($emails !== []) {
            return $emails;
        }

        $fallback = trim((string) $masjid?->email);

        return $fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL) ? [strtolower($fallback)] : [];
    }

    /**
     * The pickup time as the customer and the office read it: the ORGANISATION'S
     * wall clock, never UTC and never the reader's own zone.
     */
    public static function pickupLabel(MealOrder $order): ?string
    {
        if ($order->pickup_at === null) {
            return null;
        }

        return $order->pickup_at->copy()
            ->timezone(MasjidTime::zoneFor($order->masjid_id))
            ->format('l, F j \a\t g:i A');
    }

    /**
     * One sentence about the money, in the order's own terms: what was paid and
     * how, or what is owed and how the customer said they would pay.
     */
    public static function paymentLine(MealOrder $order): string
    {
        $currency = (string) ($order->currency ?: 'usd');
        $total = FormNotifier::money((int) $order->total_minor, $currency);

        if ($order->payment_status === MealOrder::PAYMENT_PAID) {
            if ($order->paid_via !== null) {
                return 'Paid ' . $total . ' (' . (MealOrder::PAID_VIA_LABELS[$order->paid_via] ?? $order->paid_via) . ').';
            }

            return 'Paid ' . $total . ($order->isOnline() ? ' by card.' : '.');
        }

        if ($order->isOnline()) {
            return 'Awaiting card payment of ' . $total . '.';
        }

        $how = $order->preferred_payment !== null
            ? (PaymentMethods::LABELS[$order->preferred_payment] ?? $order->preferred_payment)
            : null;

        return $how !== null ? $total . ' to pay by ' . $how . '.' : $total . ' to pay.';
    }

    private function toCustomer(MealOrder $order, string $column, string $kind): void
    {
        $to = trim((string) $order->customer_email);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || ! $this->claim($order, $column)) {
            return;
        }

        try {
            Mail::to($to)->queue($this->forCustomer($order, $kind));
        } catch (\Throwable $e) {
            $this->release($order, $column);

            throw $e;
        }
    }

    private function forCustomer(MealOrder $order, string $kind): KitchenOrderForCustomer
    {
        $menu = self::menu($order);
        $masjid = Masjid::withTrashed()->find($order->masjid_id);

        return new KitchenOrderForCustomer(
            kind: $kind,
            orderNumber: (string) $order->order_number,
            masjidName: (string) ($masjid?->name ?: config('mail.from.name')),
            customerName: $order->customer_name ?: null,
            menuTitle: $menu?->title ?: null,
            pickupLabel: self::pickupLabel($order),
            items: self::items($order),
            totalLine: FormNotifier::money((int) $order->total_minor, (string) ($order->currency ?: 'usd')),
            paymentLine: self::paymentLine($order),
            howToPay: self::howToPay($order, $masjid),
            pickupNote: $menu?->pickup_instructions ?: null,
            orderUrl: KitchenOrderLink::url($order),
            masjidEmail: $masjid?->email,
        );
    }

    private function forOffice(MealOrder $order, ?MealMenu $menu, ?Masjid $masjid): KitchenOrderForOffice
    {
        return new KitchenOrderForOffice(
            orderNumber: (string) $order->order_number,
            masjidName: (string) ($masjid?->name ?: config('mail.from.name')),
            menuTitle: $menu?->title ?: null,
            customerName: (string) $order->customer_name,
            customerPhone: $order->customer_phone ?: null,
            customerEmail: $order->customer_email ?: null,
            customerNotes: $order->customer_notes ?: null,
            pickupLabel: self::pickupLabel($order),
            items: self::items($order),
            totalLine: FormNotifier::money((int) $order->total_minor, (string) ($order->currency ?: 'usd')),
            paymentLine: self::paymentLine($order),
            adminUrl: rtrim((string) config('app.url'), '/') . '/masjid/jummah-lunch',
        );
    }

    /**
     * The organisation's CURRENT "how to pay" text for the method the customer
     * chose, when the order is still unpaid and offline. Null otherwise: a paid
     * order needs no instructions, and a card order pays on its own page.
     */
    private static function howToPay(MealOrder $order, ?Masjid $masjid): ?string
    {
        if ($masjid === null
            || $order->payment_status !== MealOrder::PAYMENT_UNPAID
            || $order->isOnline()
            || $order->preferred_payment === null) {
            return null;
        }

        foreach (AcceptedPaymentMethods::publicList($masjid) as $method) {
            if ($method['method'] === $order->preferred_payment) {
                return $method['instructions'];
            }
        }

        return null;
    }

    /** @return list<array{name: string, quantity: int, line: string}> */
    private static function items(MealOrder $order): array
    {
        $currency = (string) ($order->currency ?: 'usd');

        return $order->loadMissing('items')->items->map(fn (MealOrderItem $item) => [
            'name' => (string) $item->item_name,
            'quantity' => (int) $item->quantity,
            'line' => FormNotifier::money((int) $item->line_total_minor, $currency),
        ])->values()->all();
    }

    private static function menu(MealOrder $order): ?MealMenu
    {
        return MealMenu::withoutMasjidScope()
            ->withTrashed()
            ->where('masjid_id', $order->masjid_id)
            ->whereKey($order->meal_menu_id)
            ->first();
    }

    private function claim(MealOrder $order, string $column): bool
    {
        return MealOrder::withoutMasjidScope()
            ->whereKey($order->id)
            ->whereNull($column)
            ->update([$column => Carbon::now()]) === 1;
    }

    private function release(MealOrder $order, string $column): void
    {
        MealOrder::withoutMasjidScope()->whereKey($order->id)->update([$column => null]);
    }

    private function attempt(string $kind, MealOrder $order, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning("Kitchen order {$kind} email could not be sent; the order is unaffected.", [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $order->masjid_id,
                'error' => get_class($e),
            ]);
        }
    }
}
