<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/**
 * One organization's nisab price, and the date it was true (T-043c).
 *
 * The zakat calculator refuses to state a threshold without a metal price
 * (.claude/rules/zakat.md), and this row is the only per-tenant place such a
 * price exists. It is therefore not an ordinary settings bag: everything on it
 * feeds a figure a person may pay an obligation against.
 *
 * ## Tenant scoping
 *
 * Uses BelongsToMasjid like every tenant-scoped model, so an admin route never
 * hand-filters and `masjid_id` is stamped server-side from the bound tenant
 * rather than read off the URL or the body (.claude/rules/tenant-scoping.md).
 * The PUBLIC calculator runs UNBOUND — it resolves the masjid itself from the
 * `masjid-id` header — so ZakatCalculator::forMasjid() reads this table through
 * the documented `withoutMasjidScope()` bypass with an explicit `masjid_id`
 * filter, the ImpactMetrics::withTenant shape.
 *
 * `masjid_id` is fillable so system/seed code can set it while UNBOUND (the
 * Fund shape); a bound tenant always overrides it, so no request can write a
 * row into another organisation.
 *
 * ## The date is part of the price, and there is one PER METAL
 *
 * `<metal>_price_quoted_on` is not metadata. A metal price with no date cannot
 * be judged, and an out-of-date threshold is worse than no threshold: it can
 * tell a payer they owe nothing when they do. SaveZakatSettingRequest requires
 * the date whenever that metal's price is present, and ZakatCalculator
 * withholds its verdict — reporting `meets_nisab` as null, exactly as it does
 * when there is no price at all — once the quote is past its review window.
 *
 * Gold and silver each carry their OWN date and their own citation because they
 * are edited independently. A single row-level date belongs to whichever price
 * was saved last, so re-quoting gold would re-date a silver price nobody had
 * looked at for months, and the calculator — which resolves the price per metal
 * — would then publish that silver figure as current. The columns are paired so
 * that a price and the day it was read cannot come apart: nothing on this model,
 * and nothing in the write path, is trusted to keep them together by convention.
 * `fill()` on a price without its date is refused at the boundary, and clearing
 * a price clears its date with it (MasjidZakatSettingController::save).
 *
 * Nothing on this model decides a point of fiqh. `nisab_basis` records which
 * threshold the organization publishes; the calculator names that choice in its
 * assumptions and lets the payer override it, because the choice is disputed
 * and neither this row nor this code may make it silently on anyone's behalf.
 */
class MasjidZakatSetting extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'nisab_basis',
        'gold_price_per_gram_minor',
        'silver_price_per_gram_minor',
        'gold_price_quoted_on',
        'gold_price_quoted_from',
        'silver_price_quoted_on',
        'silver_price_quoted_from',
        'updated_by_user_id',
    ];

    protected $casts = [
        'gold_price_per_gram_minor' => 'integer',
        'silver_price_per_gram_minor' => 'integer',
        'gold_price_quoted_on' => 'date',
        'silver_price_quoted_on' => 'date',
        'updated_by_user_id' => 'integer',
    ];

    /**
     * Deliberately no `isStale()` / `thresholdFor()` helpers here.
     *
     * Whether a quote has outlived its review window is a judgment that decides
     * whether the calculator will answer at all, and it is made in exactly ONE
     * place: ZakatCalculator::freshnessOf(). A convenience copy on this model
     * would be a second implementation of that judgment, free to disagree with
     * the first — and the disagreement would show up as a screen saying the
     * price is fine while the endpoint refuses to use it. This class is the
     * record; the calculator is the reasoning.
     */
}
