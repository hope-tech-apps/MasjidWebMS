<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\OrganisationPaymentMethod;
use Illuminate\Support\Collection;

/**
 * An organisation's accepted payment methods, as the PUBLIC may be told them.
 *
 * The one reader for every public surface (the payment-methods endpoint and the
 * kitchen catalogue), so a page never decides for itself whether card is on.
 *
 * `card` is withheld unless the organisation's Stripe Connect account can take
 * charges right now (Masjid::canAcceptDonations — the gate every Manara charge
 * already uses). An organisation that ticked "card" before finishing Stripe
 * onboarding would otherwise advertise a method its checkout refuses.
 *
 * Reads run unbound on /api/v1, so masjid_id is filtered by hand here and the
 * tenant scope is bypassed explicitly: a foreign organisation's rows are never
 * in the answer whatever is bound.
 */
final class AcceptedPaymentMethods
{
    /** @return Collection<int, OrganisationPaymentMethod> in display order */
    public static function rows(int $masjidId): Collection
    {
        $order = array_flip(PaymentMethods::KEYS);

        return OrganisationPaymentMethod::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereIn('method', PaymentMethods::KEYS)
            ->get()
            ->sortBy(fn (OrganisationPaymentMethod $m) => [(int) $m->sort_order, $order[$m->method] ?? 99])
            ->values();
    }

    /**
     * What a public page shows: each accepted method the organisation can honour
     * today, with its label and its "how to pay" text.
     *
     * @return list<array{method: string, label: string, instructions: ?string, online: bool}>
     */
    public static function publicList(Masjid $masjid): array
    {
        $cardReady = $masjid->canAcceptDonations();

        return self::rows((int) $masjid->id)
            ->filter(fn (OrganisationPaymentMethod $m) => $m->method !== PaymentMethods::CARD || $cardReady)
            ->map(fn (OrganisationPaymentMethod $m) => [
                'method' => (string) $m->method,
                'label' => $m->displayLabel(),
                'instructions' => filled($m->instructions) ? (string) $m->instructions : null,
                'online' => $m->method === PaymentMethods::CARD,
            ])
            ->values()
            ->all();
    }

    /**
     * The words for one method as THIS organisation names it: its own label (an
     * "Other" must be named — UpdatePaymentMethodsRequest — because "Other" tells a
     * customer nothing), else the vocabulary's, else the key. For every message
     * that names a customer's chosen method: the office email, the board, the
     * staff confirmation. A method the organisation has since stopped accepting
     * still gets the vocabulary's word; the order was placed under it.
     */
    public static function labelFor(int $masjidId, string $method): string
    {
        $row = self::rows($masjidId)->firstWhere('method', $method);

        return $row instanceof OrganisationPaymentMethod
            ? $row->displayLabel()
            : (PaymentMethods::LABELS[$method] ?? $method);
    }

    /**
     * Every method the organisation accepts, for STAFF taking an order on the board
     * (MealOrdersController::store). Not narrowed by a menu's public switches, and
     * card is listed even before Stripe can take it, marked `ready: false`, so the
     * board can say why it cannot be chosen rather than hide it.
     *
     * @return list<array{method: string, label: string, online: bool, ready: bool}>
     */
    public static function staffList(Masjid $masjid): array
    {
        $cardReady = $masjid->canAcceptDonations();

        return self::rows((int) $masjid->id)
            ->map(fn (OrganisationPaymentMethod $m) => [
                'method' => (string) $m->method,
                'label' => $m->displayLabel(),
                'online' => $m->method === PaymentMethods::CARD,
                'ready' => $m->method !== PaymentMethods::CARD || $cardReady,
            ])
            ->values()
            ->all();
    }

    /** Does the public list offer this method right now? */
    public static function offers(Masjid $masjid, string $method): bool
    {
        foreach (self::publicList($masjid) as $row) {
            if ($row['method'] === $method) {
                return true;
            }
        }

        return false;
    }
}
