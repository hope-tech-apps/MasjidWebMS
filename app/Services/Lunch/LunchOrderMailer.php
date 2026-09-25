<?php

namespace App\Services\Lunch;

use App\Mail\LunchOrderConfirmation;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Models\MealOrderTopUp;
use App\Support\FormNotifier;
use App\Support\LunchOrderLink;
use App\Support\MasjidTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the Jummah-lunch order email (App\Mail\LunchOrderConfirmation) — the one
 * place that decides WHETHER it goes, and says so once.
 *
 *  - Only to an address the order holds. Email is optional on the order form, and
 *    an order without one is simply never written to.
 *  - At most once per event. Each send is CLAIMED first with a conditional
 *    UPDATE on the row that records it (`meal_orders.confirmation_sent_at` for the
 *    confirmation, `meal_order_top_ups.notified_at` for "your order was
 *    updated"), so Stripe's two success events for one payment, a replayed
 *    webhook, or two workers racing send it once.
 *  - Never at the cost of the order or the webhook. Everything here is caught and
 *    logged at warning — the level production runs at — and a failed queue push
 *    gives its claim back, so a later event for the same payment may try again.
 *    A 500 from a webhook would make Stripe retry an event whose money is already
 *    recorded; an exception on placing an order would tell a customer it failed
 *    when it did not.
 */
final class LunchOrderMailer
{
    /** The confirmation: a pay-at-pickup order on placement, an online order once paid. */
    public function confirmation(MealOrder $order): void
    {
        $this->attempt('confirmation', $order, function () use ($order): void {
            $to = self::address($order);

            if ($to === null || $order->status === MealOrder::STATUS_CANCELLED) {
                return;
            }

            $claimed = MealOrder::withoutMasjidScope()
                ->whereKey($order->id)
                ->whereNull('confirmation_sent_at')
                ->update(['confirmation_sent_at' => Carbon::now()]);

            if ($claimed === 0) {
                return;
            }

            try {
                Mail::to($to)->queue($this->build($order, false));
            } catch (\Throwable $e) {
                MealOrder::withoutMasjidScope()->whereKey($order->id)->update(['confirmation_sent_at' => null]);

                throw $e;
            }
        });
    }

    /** "Your order was updated": a paid order's top-up was applied by the webhook. */
    public function topUpApplied(MealOrder $order, MealOrderTopUp $topUp): void
    {
        $this->attempt('top_up_applied', $order, function () use ($order, $topUp): void {
            $to = self::address($order);

            if ($to === null) {
                return;
            }

            $claimed = MealOrderTopUp::withoutMasjidScope()
                ->whereKey($topUp->id)
                ->where('status', MealOrderTopUp::STATUS_APPLIED)
                ->whereNull('notified_at')
                ->update(['notified_at' => Carbon::now()]);

            if ($claimed === 0) {
                return;
            }

            try {
                Mail::to($to)->queue($this->build($order, true));
            } catch (\Throwable $e) {
                MealOrderTopUp::withoutMasjidScope()->whereKey($topUp->id)->update(['notified_at' => null]);

                throw $e;
            }
        });
    }

    /**
     * "We received your payment, but your order was not changed": a top-up was paid
     * and recorded as a conflict (the order had changed, or ordering had ended).
     * The customer may have closed the Stripe tab believing the plates are coming;
     * the order page is not the only place they learn otherwise. Claimed once on
     * the same `notified_at` stamp, only for a top-up that IS a conflict.
     */
    public function topUpNotApplied(MealOrder $order, MealOrderTopUp $topUp): void
    {
        $this->attempt('top_up_not_applied', $order, function () use ($order, $topUp): void {
            $to = self::address($order);

            if ($to === null) {
                return;
            }

            $claimed = MealOrderTopUp::withoutMasjidScope()
                ->whereKey($topUp->id)
                ->where('status', MealOrderTopUp::STATUS_CONFLICT)
                ->whereNull('notified_at')
                ->update(['notified_at' => Carbon::now()]);

            if ($claimed === 0) {
                return;
            }

            $currency = (string) ($order->currency ?: 'usd');

            try {
                Mail::to($to)->queue($this->build($order, false, FormNotifier::money((int) $topUp->amount_minor, $currency)));
            } catch (\Throwable $e) {
                MealOrderTopUp::withoutMasjidScope()->whereKey($topUp->id)->update(['notified_at' => null]);

                throw $e;
            }
        });
    }

    /** The order's address when it is one a mailer can use, else null. */
    private static function address(MealOrder $order): ?string
    {
        $email = trim((string) $order->customer_email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function build(MealOrder $order, bool $updated, ?string $unappliedPaymentLine = null): LunchOrderConfirmation
    {
        $order->loadMissing('items');

        $menu = MealMenu::withoutMasjidScope()
            ->where('masjid_id', $order->masjid_id)
            ->whereKey($order->meal_menu_id)
            ->first();

        $masjid = Masjid::withTrashed()->find($order->masjid_id);
        $currency = (string) ($order->currency ?: 'usd');

        $paid = $order->settledMinor();
        $balance = (int) $order->total_minor - $paid;

        $items = $order->items->map(fn (MealOrderItem $item) => [
            'name' => (string) $item->item_name,
            'quantity' => (int) $item->quantity,
            'line' => FormNotifier::money((int) $item->line_total_minor, $currency),
        ])->values()->all();

        return new LunchOrderConfirmation(
            orderNumber: (string) $order->order_number,
            masjidName: (string) ($masjid?->name ?: config('mail.from.name')),
            customerName: $order->customer_name ?: null,
            menuTitle: $menu?->title ?: null,
            // A CALENDAR date, already the masjid's own Friday, cast to 00:00 UTC.
            // Shifted into a western timezone it would read as the Thursday
            // (LunchOpeningNotifier::body has the same rule).
            serviceDate: $menu?->service_date ? $menu->service_date->format('l, F j') : null,
            items: $items,
            totalLine: FormNotifier::money((int) $order->total_minor, $currency),
            paidLine: $paid > 0 ? FormNotifier::money($paid, $currency) : null,
            dueLine: $balance > 0 ? FormNotifier::money($balance, $currency) : null,
            dueLabel: $balance > 0
                ? ($paid > 0 ? 'Still due' : ($order->isOnline() ? 'Due' : 'Pay at pickup'))
                : null,
            pickupNote: $menu?->pickup_instructions ?: null,
            orderUrl: LunchOrderLink::url($order),
            // An order with a balance open is not changed online, so the email
            // does not promise it can be (the unapplied payment leaves one).
            changeUntil: $balance === 0 || $paid === 0 ? self::changeUntil($menu) : null,
            updated: $updated,
            masjidEmail: $masjid?->email,
            unappliedPaymentLine: $unappliedPaymentLine,
        );
    }

    /**
     * The cutoff as the customer reads it — in the MASJID'S timezone, because a
     * cutoff shown in UTC is how this module's worst bug read to an admin — or
     * null when there is no cutoff still ahead to promise.
     */
    /**
     * Stands in for a time when the menu is open but has NO cutoff: the order can
     * still be changed, there is simply no moment to name. Returning null there
     * (as this first did) told customers of an open menu they could only look.
     */
    public const WHILE_OPEN = '__while_open__';

    private static function changeUntil(?MealMenu $menu): ?string
    {
        if (! $menu || ! $menu->isOpenForOrders()) {
            return null;
        }

        if ($menu->ordering_closes_at === null) {
            return self::WHILE_OPEN;
        }

        return $menu->ordering_closes_at->copy()
            ->timezone(MasjidTime::zoneFor($menu->masjid_id))
            ->format('l, F j \a\t g:i A');
    }

    private function attempt(string $kind, MealOrder $order, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning("Lunch order {$kind} email could not be sent; the order is unaffected.", [
                'order_id' => (int) $order->id,
                'masjid_id' => (int) $order->masjid_id,
                // The class, not the message: a mail transport's error can quote
                // the customer's address, and the log is not where that belongs.
                'error' => get_class($e),
            ]);
        }
    }
}
