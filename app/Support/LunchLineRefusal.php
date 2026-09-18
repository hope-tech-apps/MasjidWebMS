<?php

namespace App\Support;

use App\Models\MealMenuItem;
use RuntimeException;

/**
 * Why a set of requested lunch lines cannot be priced (LunchOrderLines::price).
 *
 * It carries a `kind` as well as a sentence because the four doors that price
 * lines — the public order page, the staff board, and the two edit endpoints —
 * already say these things in their own words to their own audience, and those
 * words are pinned by tests. The KIND is the fact; the sentence is a sensible
 * default a caller may replace.
 */
final class LunchLineRefusal extends RuntimeException
{
    /** An id that is not a live item on this menu (gone, unavailable, or another organisation's). */
    public const UNAVAILABLE = 'unavailable';

    /** More of one item than the kitchen's cap allows. */
    public const OVER_CAP = 'over_cap';

    /** Nothing left to make. */
    public const EMPTY_ORDER = 'empty';

    private function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'One or more items are no longer available — please refresh the menu.');
    }

    public static function overCap(MealMenuItem $item): self
    {
        return new self(self::OVER_CAP, "Only {$item->max_quantity} × {$item->name} per order.");
    }

    public static function emptyOrder(): self
    {
        return new self(self::EMPTY_ORDER, 'Your order is empty.');
    }
}
