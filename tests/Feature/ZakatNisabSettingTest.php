<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidZakatSetting;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\ZakatCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The organization's own nisab price, and what the calculator will and will not
 * conclude from it (T-043c).
 *
 * The calculator shipped honest and useless: it refuses to state a threshold
 * without a metal price, and no tenant had any way to give it one. Everything
 * pinned here is about the price that fixes that — and about the ways a price
 * can make the tool WORSE than the silence it replaced.
 *
 * The guarantee at the centre of this file is the stale one. A dated price that
 * nobody refreshes still multiplies out to a confident-looking threshold, and a
 * payer sitting just under it is told they owe nothing. That is a worse outcome
 * than "we cannot tell you", which is why an expired quote is driven back into
 * exactly the same `null` verdict as no quote at all (.claude/rules/zakat.md).
 *
 * Its sharpest edge is the one a row-level date hid completely: the date must
 * belong to ONE price. Gold and silver are quoted months apart, so a single
 * shared `price_quoted_on` describes only whichever was saved last — re-reading
 * gold in September stamped September onto a June silver quote, and every check
 * downstream then passed, because the price WAS dated and the date WAS recent
 * and nothing anywhere compared the two to the same metal. The tests under "a
 * date belongs to ONE price" pin the pairing at each layer it could come apart:
 * the write path, the per-metal verdict, the citation shown to the reader, and
 * the screen that types them in.
 */
class ZakatNisabSettingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The shipped state: no price configured anywhere on the deployment, so
        // anything a test sees comes from the organization row it created.
        config([
            'zakat.nisab.basis' => 'silver',
            'zakat.nisab.gold_grams' => 87.48,
            'zakat.nisab.silver_grams' => 612.36,
            'zakat.nisab.gold_price_per_gram_minor' => null,
            'zakat.nisab.silver_price_per_gram_minor' => null,
            'zakat.nisab.price_freshness_days' => 30,
            'services.stripe.currency' => 'usd',
        ]);

        // Unbound to start: these fixtures write rows for TWO organisations, and
        // a bound context would stamp both into whichever one it held.
        app(TenantContext::class)->forgetTenant();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
        $this->adminA = $this->makeAdminFor($this->masjidA);
    }

    // ===================== the price reaches the calculator =====================

    #[Test]
    public function an_organisations_price_beats_the_config_price_and_is_labelled_as_the_organisations(): void
    {
        // A deployment-wide price exists — one price for every tenant on the box.
        config(['zakat.nisab.silver_price_per_gram_minor' => 500]);

        // ...and this masjid quoted its own.
        $this->setPriceFor($this->masjidA, ['silver_price_per_gram_minor' => 100]);

        $nisab = $this->publicNisab($this->masjidA);

        $this->assertSame(100, $nisab['price_per_gram_minor']);
        // 612.36 g x $1.00 — the organization's figure, not the deployment's.
        $this->assertSame(61236, $nisab['threshold_minor']);
        // "config" here would credit the deployment with a number the office
        // typed, and the payload has to say where a figure came from.
        $this->assertSame(ZakatCalculator::PRICE_SOURCE_ORGANIZATION, $nisab['price_source']);
    }

    #[Test]
    public function a_request_supplied_price_still_beats_the_organisations(): void
    {
        $this->setPriceFor($this->masjidA, ['silver_price_per_gram_minor' => 100]);

        $nisab = $this->publicNisab($this->masjidA, ['nisab_price_per_gram' => 200]);

        $this->assertSame(200, $nisab['price_per_gram_minor']);
        $this->assertSame(ZakatCalculator::PRICE_SOURCE_REQUEST, $nisab['price_source']);
        // A caller's live quote carries no stored date, and none is invented for
        // it out of the organization's row.
        $this->assertNull($nisab['price_quoted_on']);
    }

    #[Test]
    public function an_organisation_with_no_price_still_reports_an_unknown_threshold_rather_than_zero(): void
    {
        // A row exists — the office set a basis — but no price was ever typed.
        MasjidZakatSetting::create([
            'masjid_id' => $this->masjidA->id,
            'nisab_basis' => 'gold',
        ]);

        $data = $this->calculateFor($this->masjidA, ['cash' => 100000000]);

        $this->assertNull($data['nisab']['threshold_minor']);
        $this->assertNull($data['nisab']['price_source']);
        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNull($data['zakat_due_minor']);
        // The arithmetic survives; only the ruling is withheld.
        $this->assertSame(2500000, $data['zakat_at_rate_minor']);
    }

    #[Test]
    public function the_organisations_basis_is_the_default_and_the_payer_may_still_choose_the_other(): void
    {
        $this->setPriceFor($this->masjidA, [
            'nisab_basis' => 'gold',
            'gold_price_per_gram_minor' => 100,
            'silver_price_per_gram_minor' => 100,
        ]);

        // The organization publishes gold, so that is what a caller who said
        // nothing is answered on: 87.48 g.
        $this->assertSame('gold', $this->publicNisab($this->masjidA)['basis']);
        $this->assertSame(8748, $this->publicNisab($this->masjidA)['threshold_minor']);

        // The choice is disputed, so it is never taken away from the payer.
        $silver = $this->publicNisab($this->masjidA, ['basis' => 'silver']);
        $this->assertSame('silver', $silver['basis']);
        $this->assertSame(61236, $silver['threshold_minor']);
    }

    // ============================== provenance ==============================

    #[Test]
    public function the_nisab_payload_says_when_the_price_was_quoted_and_where_it_came_from(): void
    {
        $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->subDays(3)->toDateString(),
            'silver_price_quoted_from' => 'Metals exchange spot, USD per gram',
        ]);

        $nisab = $this->publicNisab($this->masjidA);

        $this->assertSame(Carbon::now()->subDays(3)->toDateString(), $nisab['price_quoted_on']);
        $this->assertSame('Metals exchange spot, USD per gram', $nisab['price_quoted_from']);
        $this->assertSame(ZakatCalculator::FRESHNESS_CURRENT, $nisab['price_freshness']);
        $this->assertSame(30, $nisab['price_freshness_days']);

        // And it is not only in a machine field: the reader is told in words.
        $statement = $this->assumption($this->masjidA, 'metal_price_quoted_on');
        $this->assertStringContainsString('recorded by this organization', $statement);
        $this->assertStringContainsString('Metals exchange spot', $statement);
    }

    #[Test]
    public function a_deployment_configured_price_is_undated_and_therefore_draws_no_conclusion(): void
    {
        // The `.env` escape hatch has no quote date and cannot acquire one, so
        // "we do not know how old this is" is the only honest label for it — and
        // an unverifiable age is not a basis for telling anyone what they owe.
        // The threshold is still published; the verdict is not.
        config(['zakat.nisab.silver_price_per_gram_minor' => 100]);

        $data = $this->calculateFor($this->masjidA, ['cash' => 1000]);

        $this->assertSame(ZakatCalculator::PRICE_SOURCE_CONFIG, $data['nisab']['price_source']);
        $this->assertSame(ZakatCalculator::FRESHNESS_UNDATED, $data['nisab']['price_freshness']);
        $this->assertNull($data['nisab']['price_quoted_on']);
        $this->assertSame(61236, $data['nisab']['threshold_minor']);
        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNull($data['zakat_due_minor']);
    }

    // =========================== the stale guarantee ===========================

    #[Test]
    public function a_price_older_than_the_review_window_withholds_the_verdict_instead_of_saying_nothing_is_due(): void
    {
        // A price quoted two months ago, and wealth comfortably under the
        // threshold it implies. With the price treated as live, this payer would
        // be told "you owe nothing" on the strength of a figure nobody has
        // looked at since — the exact harm .claude/rules/zakat.md names.
        $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->subDays(60)->toDateString(),
        ]);

        $data = $this->calculateFor($this->masjidA, ['cash' => 1000]);

        $this->assertSame(ZakatCalculator::FRESHNESS_STALE, $data['nisab']['price_freshness']);
        // The threshold is STILL reported — it is a record of what the office
        // set, and hiding it would hide the evidence that it is old.
        $this->assertSame(61236, $data['nisab']['threshold_minor']);
        $this->assertSame(Carbon::now()->subDays(60)->toDateString(), $data['nisab']['price_quoted_on']);

        // But no conclusion is drawn from it. Not false, not 0 — null.
        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNull($data['zakat_due_minor']);

        // And the reason is in the payload in words, not only as a status code.
        $statement = collect($data['assumptions'])->firstWhere('key', 'metal_price_quoted_on')['statement'];
        $this->assertStringContainsString('out of date', $statement);
    }

    #[Test]
    public function a_stale_price_withholds_the_verdict_even_for_someone_clearly_above_the_threshold(): void
    {
        // The symmetric case, and the one it would be tempting to special-case:
        // this payer is obviously over any plausible threshold, so "you owe"
        // looks safe. It is still a conclusion drawn from a price nobody has
        // checked, and the tool does not draw it.
        $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->subDays(400)->toDateString(),
        ]);

        $data = $this->calculateFor($this->masjidA, ['cash' => 100000000]);

        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNull($data['zakat_due_minor']);
        // The plain arithmetic is untouched and still theirs to use.
        $this->assertSame(2500000, $data['zakat_at_rate_minor']);
    }

    #[Test]
    public function a_price_becomes_usable_again_the_moment_it_is_requoted(): void
    {
        $setting = $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->subDays(90)->toDateString(),
        ]);

        $this->assertNull($this->calculateFor($this->masjidA, ['cash' => 100000])['nisab']['meets_nisab']);

        $setting->update(['silver_price_quoted_on' => Carbon::now()->toDateString()]);

        $refreshed = $this->calculateFor($this->masjidA, ['cash' => 100000]);
        $this->assertTrue($refreshed['nisab']['meets_nisab']);
        $this->assertSame(2500, $refreshed['zakat_due_minor']);
    }

    #[Test]
    public function an_undated_stored_price_withholds_the_verdict_the_same_way_an_expired_one_does(): void
    {
        // The write path forbids this, so such a row predates the requirement or
        // went round it. "We cannot tell how old this is" is not a safer state
        // than "it is old" — it is the same state with the evidence missing.
        MasjidZakatSetting::create([
            'masjid_id' => $this->masjidA->id,
            'silver_price_per_gram_minor' => 100,
        ]);

        $data = $this->calculateFor($this->masjidA, ['cash' => 1000]);

        $this->assertSame(ZakatCalculator::FRESHNESS_UNDATED, $data['nisab']['price_freshness']);
        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNull($data['zakat_due_minor']);
    }

    // ===================== a date belongs to ONE price =====================

    #[Test]
    public function requoting_one_metal_does_not_re_date_the_other_metals_price(): void
    {
        // The defect this whole slice exists to end. With a single row-level
        // `price_quoted_on`, an office that re-read gold in September stamped
        // September onto a silver price it last looked at in June — and the
        // calculator, which resolves the price per metal, then called that
        // silver figure current and handed donors a hard verdict off it.
        $june = Carbon::now()->subDays(100)->toDateString();

        $this->setPriceFor($this->masjidA, [
            'nisab_basis' => 'silver',
            'silver_price_per_gram_minor' => 95,
            'silver_price_quoted_on' => $june,
            'gold_price_per_gram_minor' => 8000,
            'gold_price_quoted_on' => $june,
        ]);

        Sanctum::actingAs($this->adminA);

        // Only gold is re-read today. Silver is posted back unchanged, with the
        // date it has always had — which is what the screen sends.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'nisab_basis' => 'silver',
            'gold_price_per_gram_minor' => 8500,
            'gold_price_quoted_on' => Carbon::now()->toDateString(),
            'silver_price_per_gram_minor' => 95,
            'silver_price_quoted_on' => $june,
        ])->assertOk();

        $row = MasjidZakatSetting::withoutMasjidScope()->sole();

        $this->assertSame(Carbon::now()->toDateString(), $row->gold_price_quoted_on->toDateString());
        // The silver date did not move, because nothing about silver did.
        $this->assertSame($june, $row->silver_price_quoted_on->toDateString());

        // And the donor on the published (silver) basis is told the truth about
        // it: a hundred-day-old quote, and therefore no verdict.
        $nisab = $this->publicNisab($this->masjidA);
        $this->assertSame($june, $nisab['price_quoted_on']);
        $this->assertSame(ZakatCalculator::FRESHNESS_STALE, $nisab['price_freshness']);
    }

    #[Test]
    public function a_freshly_quoted_gold_price_does_not_make_a_stale_silver_one_usable(): void
    {
        // The same drift seen from the donor's side: whichever metal the payer
        // is answered on, the freshness that governs the verdict is THAT metal's.
        $this->setPriceFor($this->masjidA, [
            'nisab_basis' => 'silver',
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->subDays(90)->toDateString(),
            'gold_price_per_gram_minor' => 100,
            'gold_price_quoted_on' => Carbon::now()->toDateString(),
        ]);

        // Silver — the published basis — is out of date, so no verdict.
        $silver = $this->calculateFor($this->masjidA, ['cash' => 100000000]);
        $this->assertSame(ZakatCalculator::FRESHNESS_STALE, $silver['nisab']['price_freshness']);
        $this->assertNull($silver['nisab']['meets_nisab']);
        $this->assertNull($silver['zakat_due_minor']);

        // Gold was read today, so the payer who chooses gold gets a real answer.
        // One metal's currency neither rescues nor contaminates the other's.
        $gold = $this->calculateFor($this->masjidA, ['cash' => 100000000, 'basis' => 'gold']);
        $this->assertSame(ZakatCalculator::FRESHNESS_CURRENT, $gold['nisab']['price_freshness']);
        $this->assertTrue($gold['nisab']['meets_nisab']);
        $this->assertSame(2500000, $gold['zakat_due_minor']);
    }

    #[Test]
    public function the_citation_shown_beside_a_figure_is_the_one_recorded_for_that_metal(): void
    {
        // A single citation for two independently-read prices misattributes
        // whichever one it does not describe — and the citation is the thing a
        // reader is invited to go and check the threshold against.
        $this->setPriceFor($this->masjidA, [
            'nisab_basis' => 'gold',
            'gold_price_per_gram_minor' => 8500,
            'gold_price_quoted_from' => 'Gold desk quote, USD per gram',
            'silver_price_per_gram_minor' => 95,
            'silver_price_quoted_from' => 'Silver spot, USD per gram',
        ]);

        $gold = $this->publicNisab($this->masjidA);
        $this->assertSame('gold', $gold['basis']);
        $this->assertSame('Gold desk quote, USD per gram', $gold['price_quoted_from']);

        $silver = $this->publicNisab($this->masjidA, ['basis' => 'silver']);
        $this->assertSame('Silver spot, USD per gram', $silver['price_quoted_from']);

        // And in words, not only in a machine field.
        $this->assertStringContainsString(
            'Gold desk quote',
            $this->assumption($this->masjidA, 'metal_price_quoted_on')
        );
    }

    #[Test]
    public function clearing_one_metals_price_clears_the_date_that_described_it(): void
    {
        // A date left standing over a removed price is a claim about a figure
        // that is not there — and it does not stay harmless: the next price
        // typed into that box would be posted wearing it, having passed every
        // rule in the request.
        $this->setPriceFor($this->masjidA, [
            'gold_price_per_gram_minor' => 8500,
            'gold_price_quoted_from' => 'Gold desk quote',
            'silver_price_per_gram_minor' => 95,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'gold_price_per_gram_minor' => null,
            'gold_price_quoted_on' => Carbon::now()->toDateString(),
            'gold_price_quoted_from' => 'Gold desk quote',
            'silver_price_per_gram_minor' => 95,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertOk();

        $row = MasjidZakatSetting::withoutMasjidScope()->sole();

        $this->assertNull($row->gold_price_per_gram_minor);
        $this->assertNull($row->gold_price_quoted_on);
        $this->assertNull($row->gold_price_quoted_from);
        // Silver is untouched by its neighbour's removal.
        $this->assertSame(95, $row->silver_price_per_gram_minor);
        $this->assertNotNull($row->silver_price_quoted_on);
    }

    #[Test]
    public function the_admin_screen_is_given_each_metals_freshness_rather_than_deciding_it(): void
    {
        // Whether a quote has outlived its review window is decided in exactly
        // one place. If the screen had to work the second metal out itself, that
        // copy would be free to tell the office a price is fine while the donor
        // endpoint refuses to use it — so the server answers for both.
        $this->setPriceFor($this->masjidA, [
            'nisab_basis' => 'silver',
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
            'gold_price_per_gram_minor' => 8500,
            'gold_price_quoted_on' => Carbon::now()->subDays(90)->toDateString(),
        ]);

        Sanctum::actingAs($this->adminA);

        $metals = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings")
            ->assertOk()->json('data.metals');

        $this->assertSame(ZakatCalculator::FRESHNESS_CURRENT, $metals['silver']['price_freshness']);
        $this->assertSame(ZakatCalculator::FRESHNESS_STALE, $metals['gold']['price_freshness']);

        // Each block carries its own date, so the office can see WHICH price to
        // go and re-read rather than only that something is wrong.
        $this->assertSame(Carbon::now()->toDateString(), $metals['silver']['price_quoted_on']);
        $this->assertSame(
            Carbon::now()->subDays(90)->toDateString(),
            $metals['gold']['price_quoted_on']
        );

        // And what the office is shown per metal is the same resolution a donor
        // choosing that basis is answered with — not an admin-only mirror.
        $this->assertSame(
            $this->publicNisab($this->masjidA, ['basis' => 'gold'])['threshold_minor'],
            $metals['gold']['threshold_minor']
        );
    }

    #[Test]
    public function a_date_for_one_metal_does_not_satisfy_the_other_metals_price(): void
    {
        Sanctum::actingAs($this->adminA);

        // A gold price with only a SILVER date is the row-level date's failure
        // written out as a request: something that looks dated and is not.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'gold_price_per_gram_minor' => 8500,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertStatus(422);

        $this->assertSame(0, MasjidZakatSetting::withoutMasjidScope()->count());
    }

    // ================== the screen cannot publish an undated figure ==================

    #[Test]
    public function the_price_screen_pairs_each_date_with_its_own_price_and_posts_a_typed_zero(): void
    {
        // A file-content tripwire, because the two guarantees below live only in
        // the browser and no HTTP test can reach them. Both were real defects:
        // the screen posted one shared date for two prices, and it mapped a
        // typed 0 to null — which reads to this API as "delete the published
        // price", so the `min:1` rule three tests above never fired and the
        // office's threshold vanished with no error anywhere.
        $view = file_get_contents(
            base_path('resources/vue-app/views/dashboard/ZakatCalculatorView.vue')
        );

        // Each metal's date and citation travel with that metal's price.
        foreach ([
            'gold_price_per_gram_minor',
            'gold_price_quoted_on',
            'gold_price_quoted_from',
            'silver_price_per_gram_minor',
            'silver_price_quoted_on',
            'silver_price_quoted_from',
        ] as $key) {
            $this->assertStringContainsString($key, $view, "the screen must post `{$key}`");
        }

        // And there is no single shared date field left to re-stamp.
        $this->assertStringNotContainsString('form.price_quoted_on', $view);

        // The Save button is gated on the same refusal the server makes, so a
        // figure whose price the screen cannot date never leaves it.
        $this->assertMatchesRegularExpression(
            '/:disabled="saving \|\| priceProblems\.length > 0"/',
            $view
        );

        // A typed 0 is converted, not discarded: only an EMPTY box is null.
        $this->assertStringContainsString('function priceToMinor', $view);
        $this->assertStringContainsString("gold_price_per_gram_minor: priceToMinor(", $view);
        $this->assertStringContainsString("silver_price_per_gram_minor: priceToMinor(", $view);
    }

    // ============================ tenant isolation ============================

    #[Test]
    public function one_organisations_nisab_price_is_invisible_to_another(): void
    {
        $this->setPriceFor($this->masjidA, ['silver_price_per_gram_minor' => 100]);
        $otherRow = $this->setPriceFor($this->masjidB, ['silver_price_per_gram_minor' => 900]);

        // The PUBLIC calculator answers each tenant from its own row, resolved
        // from the masjid-id header and nothing else.
        $this->assertSame(100, $this->publicNisab($this->masjidA)['price_per_gram_minor']);
        $this->assertSame(900, $this->publicNisab($this->masjidB)['price_per_gram_minor']);

        // A masjid that never set one falls through to "unknown" rather than
        // inheriting a neighbour's figure.
        $orphan = $this->makeMasjid();
        $this->assertNull($this->publicNisab($orphan)['price_per_gram_minor']);

        // And the ADMIN screen sees only the bound tenant's row.
        Sanctum::actingAs($this->adminA);

        $data = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings")
            ->assertOk()->json('data');

        $this->assertSame($this->masjidA->id, (int) $data['setting']['masjid_id']);
        $this->assertSame(100, $data['setting']['silver_price_per_gram_minor']);

        // And at the model layer, with the tenant bound explicitly rather than
        // relying on whatever the last request left behind: masjid B's row does
        // not exist as far as MasjidZakatSetting is concerned. MySQL has no
        // row-level security, so this global scope IS the boundary.
        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertSame(1, MasjidZakatSetting::query()->count());
        $this->assertNull(MasjidZakatSetting::query()->find($otherRow->id));
        $this->assertSame(2, MasjidZakatSetting::withoutMasjidScope()->count());

        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function an_admin_cannot_write_another_organisations_nisab_price(): void
    {
        Sanctum::actingAs($this->adminA);

        // Naming another masjid in the URL is refused by the tenant middleware,
        // not merely scoped away.
        $this->postJson("/api/admin/masjids/{$this->masjidB->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertStatus(403);

        $this->assertSame(0, MasjidZakatSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_stamped_masjid_id_wins_over_one_supplied_in_the_body(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'masjid_id' => $this->masjidB->id,
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertOk();

        $row = MasjidZakatSetting::withoutMasjidScope()->sole();
        $this->assertSame($this->masjidA->id, (int) $row->masjid_id);
    }

    // ============================== the write path ==============================

    #[Test]
    public function only_manage_donations_may_set_the_nisab_price(): void
    {
        // Strip the bridged role and grant ONLY the read permission. syncRoles /
        // givePermissionTo touch pivots only, so the observer does not re-bridge.
        $this->adminA->syncRoles([]);
        $this->adminA->givePermissionTo('view donations');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->adminA);

        // Reading the threshold is a read of the money path.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings")->assertOk();

        // Setting the figure people calculate an obligation against is not.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertStatus(403);
    }

    #[Test]
    public function a_price_cannot_be_saved_without_the_date_it_was_quoted(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 100,
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'gold_price_per_gram_minor' => 100,
        ])->assertStatus(422);

        $this->assertSame(0, MasjidZakatSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_price_quoted_in_the_future_is_rejected(): void
    {
        // A forward-dated quote would read as permanently current and never go
        // stale — the one way round the review window.
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_decimal_price_is_rejected_because_minor_units_are_the_contract(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 100.55,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_price_of_zero_is_rejected_rather_than_producing_a_threshold_everyone_meets(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 0,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function clearing_the_price_returns_the_calculator_to_unknown_rather_than_leaving_the_old_figure(): void
    {
        $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ]);

        Sanctum::actingAs($this->adminA);

        // An office that stops publishing a threshold must be able to remove the
        // figure; leaving a stale one standing is the worse outcome.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'nisab_basis' => 'silver',
            'gold_price_per_gram_minor' => null,
            'gold_price_quoted_on' => null,
            'gold_price_quoted_from' => null,
            'silver_price_per_gram_minor' => null,
            'silver_price_quoted_on' => null,
            'silver_price_quoted_from' => null,
        ])->assertOk();

        $nisab = $this->publicNisab($this->masjidA);
        $this->assertNull($nisab['price_per_gram_minor']);
        $this->assertNull($nisab['threshold_minor']);
        $this->assertNull($nisab['price_source']);
    }

    #[Test]
    public function the_save_records_who_last_touched_the_figure(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings", [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ])->assertOk();

        // A wrong threshold needs a person to ask, not only a timestamp.
        $this->assertSame(
            $this->adminA->id,
            (int) MasjidZakatSetting::withoutMasjidScope()->sole()->updated_by_user_id
        );
    }

    // ======================= the admin screen tells the truth =======================

    #[Test]
    public function the_admin_screen_reads_its_threshold_from_the_same_resolution_the_public_endpoint_uses(): void
    {
        // An admin screen that computed its own threshold from the stored fields
        // would be a second implementation free to disagree with the real one —
        // and the disagreement would surface as a donor paying the wrong amount.
        $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->subDays(60)->toDateString(),
        ]);

        Sanctum::actingAs($this->adminA);

        $admin = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings")
            ->assertOk()->json('data.nisab.nisab');

        $public = $this->publicNisab($this->masjidA);

        $this->assertSame($public['threshold_minor'], $admin['threshold_minor']);
        $this->assertSame($public['price_source'], $admin['price_source']);
        $this->assertSame($public['price_quoted_on'], $admin['price_quoted_on']);
        // Including the bad news: the office sees the staleness a donor sees.
        $this->assertSame(ZakatCalculator::FRESHNESS_STALE, $admin['price_freshness']);
    }

    #[Test]
    public function reading_the_settings_of_an_organisation_that_never_set_one_is_not_an_error(): void
    {
        Sanctum::actingAs($this->adminA);

        $data = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/zakat-settings")
            ->assertOk()->json('data');

        $this->assertNull($data['setting']);
        // "There is no price and therefore no threshold" is itself the answer
        // the screen has to be able to show.
        $this->assertNull($data['nisab']['nisab']['threshold_minor']);
        $this->assertNotEmpty($data['nisab']['assumptions']);
    }

    // ============================== still no writes ==============================

    #[Test]
    public function the_calculator_persists_nothing_now_that_it_reads_a_setting(): void
    {
        $this->setPriceFor($this->masjidA, [
            'silver_price_per_gram_minor' => 100,
            'silver_price_quoted_on' => Carbon::now()->toDateString(),
        ]);

        $before = MasjidZakatSetting::withoutMasjidScope()->count();

        $this->calculateFor($this->masjidA, ['cash' => 100000000, 'investments' => 500000000]);

        // The new read must not have become a write, an upsert or a touch.
        $this->assertSame($before, MasjidZakatSetting::withoutMasjidScope()->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('donations')->count());
    }

    // ================================= helpers =================================

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            // The nisab price lives behind the same `crm` capability as the
            // donations routes it sits beside — "Members, classes & giving" —
            // so a masjid without it 403s before the permission check is ever
            // reached, and every assertion in this file about WHICH permission
            // is required would be measuring the capability gate instead.
            'crm_enabled' => true,
            'name' => 'Nisab Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /**
     * Write a price straight to the table, bypassing the admin API — these tests
     * are about what the CALCULATOR does with a price, and the write path has
     * its own tests above.
     *
     * The date is defaulted PER METAL, and only for a metal this call actually
     * priced. A blanket default would have quietly dated a metal the test never
     * mentioned, which is the same conflation of one metal's evidence with
     * another's that the columns were split to end — a fixture helper is exactly
     * where that would sneak back in unnoticed.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function setPriceFor(Masjid $masjid, array $attributes): MasjidZakatSetting
    {
        foreach (ZakatCalculator::BASES as $metal) {
            if (array_key_exists("{$metal}_price_per_gram_minor", $attributes)
                && ! array_key_exists("{$metal}_price_quoted_on", $attributes)) {
                $attributes["{$metal}_price_quoted_on"] = Carbon::now()->toDateString();
            }
        }

        return MasjidZakatSetting::create(array_merge(['masjid_id' => $masjid->id], $attributes));
    }

    /**
     * The public nisab reference, exactly as a visitor's browser asks for it.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function publicNisab(Masjid $masjid, array $query = []): array
    {
        $url = '/api/v1/zakat/nisab' . ($query === [] ? '' : '?' . http_build_query($query));

        return $this->getJson($url, ['masjid-id' => (string) $masjid->id])
            ->assertOk()->json('data.nisab');
    }

    /**
     * The public calculation, exactly as a visitor's browser posts it.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function calculateFor(Masjid $masjid, array $payload): array
    {
        return $this->postJson('/api/v1/zakat/calculate', $payload, [
            'masjid-id' => (string) $masjid->id,
        ])->assertOk()->json('data');
    }

    /** One named assumption's statement, from the public reference payload. */
    private function assumption(Masjid $masjid, string $key): string
    {
        $assumptions = $this->getJson('/api/v1/zakat/nisab', ['masjid-id' => (string) $masjid->id])
            ->assertOk()->json('data.assumptions');

        return collect($assumptions)->firstWhere('key', $key)['statement'];
    }
}
