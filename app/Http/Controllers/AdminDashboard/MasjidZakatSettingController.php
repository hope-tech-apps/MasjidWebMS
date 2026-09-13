<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Zakat\SaveZakatSettingRequest;
use App\Models\Masjid;
use App\Models\MasjidZakatSetting;
use App\Support\Errors;
use App\Support\ZakatCalculator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the organization's nisab price (T-043c).
 *
 * The zakat calculator shipped correct and unusable — it refuses to state a
 * threshold without a metal price, and no tenant had any way to give it one, so
 * every masjid's endpoint answered "unknown" for ever. This controller is the
 * one place a price arrives from.
 *
 * ## Why a price the office types, and not a market feed
 *
 * A live feed would put an outbound HTTP call, a vendor key and a new failure
 * mode inside a public donor-facing endpoint, and it would make the platform —
 * not the masjid — the author of a figure people pay a religious obligation
 * against. A typed, dated, attributed price puts that authorship where it
 * belongs: with the organization whose name is on the page and whose scholar
 * the payer can actually ask. It also degrades honestly, which a feed does not:
 * a feed that dies leaves yesterday's cached number looking live, while a quote
 * that ages announces its own age and the calculator stops drawing conclusions
 * from it. (.claude/rules/zakat.md: "Nothing in this file is a live market
 * feed.")
 *
 * ## What `index` returns, and why it returns more than the row
 *
 * Alongside the stored setting it returns `nisab`, the resolved reference from
 * ZakatCalculator::forMasjid() — the SAME object and the SAME code path the
 * public endpoint answers donors from. An admin screen that rendered its own
 * idea of the threshold from the stored fields would be a second implementation
 * free to disagree with the real one, and the disagreement would show up as a
 * donor paying the wrong amount. Here the office sees literally what a donor
 * sees, including the staleness verdict.
 *
 * It also returns `metals`, the same resolution run once PER METAL. The office
 * edits two prices with two dates, and either one can be stale while the other
 * is current — the published basis alone cannot show that, so a screen that
 * only had `nisab` would either stay silent about the other metal or work out
 * its own answer. Working out its own answer is the thing forbidden: whether a
 * quote has outlived its review window is decided in exactly one place
 * (ZakatCalculator::freshnessOf), and a copy of that judgment on the client
 * would be free to say a price is fine while the endpoint refuses to use it.
 * So the server answers the question twice and the screen only renders it.
 *
 * ## Tenancy
 *
 * MasjidZakatSetting is BelongsToMasjid, so this controller never filters by
 * `$masjid_id` and never writes it: the bound tenant scopes the read and the
 * creating hook stamps the write (.claude/rules/tenant-scoping.md). The route
 * parameter stays only as the URL convention every other admin route follows.
 */
class MasjidZakatSettingController extends Controller
{
    /**
     * GET /api/admin/masjids/{masjid_id}/zakat-settings
     *
     * `data.setting` is null when this organization has never saved one — the
     * MasjidDonationLinkController::index shape. `data.nisab` is present either
     * way, because "there is no price and therefore no threshold" is itself the
     * answer the screen has to show.
     */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $this->settingPayload($masjid, MasjidZakatSetting::query()->first()),
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/admin/masjids/{masjid_id}/zakat-settings
     *
     * POST rather than PUT to match every other settings screen in this admin
     * API (donation-link, iqama, jumaa, about) — and because ApiService::put
     * flips axios's global Content-Type to urlencoded, which is how a form-encoded
     * body once reached Laravel's `boolean` rule as the strings "true"/"false"
     * and 422'd every live order.
     *
     * `updateOrCreate` on an empty condition rather than on `masjid_id`: the
     * tenant scope already narrows the query to this organization and the
     * creating hook stamps the column, so naming `masjid_id` here would be the
     * client-supplied tenant id the rules forbid.
     */
    public function save(SaveZakatSettingRequest $request, $masjid_id)
    {
        // Outside the try, so a bad masjid id surfaces as a clean 404 rather
        // than being swallowed into a 500 by the catch below.
        $masjid = Masjid::findOrFail($masjid_id);

        try {
            $setting = MasjidZakatSetting::query()->first() ?? new MasjidZakatSetting();

            // `only()` on what was SENT, so an absent key leaves the stored
            // value alone. The screen therefore posts all seven fields every
            // time, with explicit nulls for the ones it cleared — clearing a
            // price is a real edit (an office that stops publishing the gold
            // threshold must be able to remove the figure rather than leave a
            // stale one standing), and it must not depend on a key's absence.
            $setting->fill($request->safe()->only([
                'nisab_basis',
                'gold_price_per_gram_minor',
                'gold_price_quoted_on',
                'gold_price_quoted_from',
                'silver_price_per_gram_minor',
                'silver_price_quoted_on',
                'silver_price_quoted_from',
            ]));

            // A cleared price takes its date and its citation with it.
            //
            // The request refuses a price with no date; this is the other half
            // of the same guarantee — a date with no price. Left behind, that
            // orphan date is a claim about a figure that is no longer there, and
            // it does not stay orphaned for long: the next time the office types
            // a price into that metal's box, the old date is sitting in the form
            // waiting to be posted with it, and a March date lands on a
            // September quote having passed every rule in the request. Stripping
            // the pair on the way out is what stops a stale date from surviving
            // the price it described.
            $this->clearOrphanedQuote($setting, ZakatCalculator::BASIS_GOLD);
            $this->clearOrphanedQuote($setting, ZakatCalculator::BASIS_SILVER);

            // Who to ask when a threshold looks wrong. Recorded on every save,
            // including the save that CLEARS a price.
            $setting->updated_by_user_id = $request->user()?->id;

            $setting->save();

            return response()->json([
                'status' => 'success',
                // Recomputed from what was just stored, so the screen's "this is
                // what donors will see" panel can never lag the save.
                'data' => $this->settingPayload($masjid, $setting->fresh()),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The one response shape both verbs return, resolved through the calculator.
     *
     * Built ONCE per request and reused for every basis: `forMasjid()` reads the
     * row, so calling it three times would be three queries answering from three
     * reads of the same data — and, on the save path, three chances to describe
     * a row that changed underneath. One resolution, asked three questions.
     *
     * @return array<string,mixed>
     */
    private function settingPayload(Masjid $masjid, ?MasjidZakatSetting $setting): array
    {
        $calculator = ZakatCalculator::forMasjid($masjid);

        return [
            'setting' => $setting,
            // The published basis: what a donor who says nothing is answered on.
            'nisab' => $calculator->reference(),
            // The same resolution per metal, so the screen can tell the office
            // which of its two prices has gone stale without deciding staleness
            // itself. A payer may choose either basis, so both are real answers,
            // not previews.
            'metals' => collect(ZakatCalculator::BASES)
                ->mapWithKeys(fn (string $basis) => [
                    $basis => $calculator->reference(['basis' => $basis])['nisab'],
                ])
                ->all(),
        ];
    }

    /**
     * Drop one metal's quote date and citation when that metal has no price.
     *
     * Small, and deliberately not clever: it exists so the invariant "a stored
     * date always describes a stored price" holds for every row this controller
     * writes, rather than holding only for the paths somebody remembered. The
     * calculator reads the pair per metal (ZakatCalculator::nisab), so a date
     * that outlives its price is a fact about nothing that is one save away from
     * becoming a fact about the wrong number.
     */
    private function clearOrphanedQuote(MasjidZakatSetting $setting, string $metal): void
    {
        if ($setting->{$metal . '_price_per_gram_minor'} === null) {
            $setting->{$metal . '_price_quoted_on'} = null;
            $setting->{$metal . '_price_quoted_from'} = null;
        }
    }
}
