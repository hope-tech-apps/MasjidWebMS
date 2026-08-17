<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M-F4 — the masjid-timezone half of `Form::resolveTier()`, pinned.
 *
 * Commit 3fad893 ("resolve fee tiers in the masjid's timezone, not the
 * server's") was ENTIRELY UNASSERTED. Reverting the whole commit and running the
 * full suite changed nothing — 1 failed, 1 skipped, 1876 passed before and
 * after, and not one of 16,168 assertions noticed. The reason is structural
 * rather than careless: `TieredFeeTest::campForm()` builds `new Form()` with no
 * `masjid_id`, so `$this->masjid?->timezone` is always null and every boundary
 * case in that file exercises the `config('app.timezone')` FALLBACK. The branch
 * the commit added was unreachable from the only test that looked at
 * boundaries.
 *
 * The fix is correct and live on the real route — measured against a masjid on
 * `America/New_York`:
 *
 *     2026-08-15 03:59 UTC (Aug 14 23:59 EDT) -> 'Early bird' $100
 *     2026-08-15 04:30 UTC (Aug 15 00:30 EDT) -> 'Standard'   $120
 *
 * so this file pins it rather than changing it. A cut-off is a CALENDAR DATE and
 * a calendar date belongs to the masjid's clock: with the server on UTC an
 * "early bird ends tonight" price would step up at 8:00 PM Eastern and charge
 * the standard rate to everyone who registered that evening.
 *
 * Three things are asserted, because the commit has three ways to regress:
 *   1. the MASJID branch — the rollover happens at local midnight, not UTC's;
 *   2. the FALLBACK branch — a form with no masjid, or a masjid with no
 *      timezone, still resolves against `config('app.timezone')`, and does so
 *      DIFFERENTLY from (1) at the same instant, so a regression that deletes
 *      the masjid lookup cannot pass both;
 *   3. DST IN BOTH DIRECTIONS — the offset that decides "which day is it" is
 *      -4 in August and -5 in November/March, so a fix that hard-codes an offset
 *      instead of asking the timezone fails here.
 */
class TieredFeeTimezoneTest extends TestCase
{
    use RefreshDatabase;

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

        // The server's clock, which must NOT be what decides the calendar day.
        config(['app.timezone' => 'UTC']);
    }

    // ------------------------------------------------------- 1. masjid branch

    /**
     * THE MEASURED BOUNDARY. 23:59 on the last early-bird evening in Burlington
     * is already tomorrow in UTC; the price must not know that.
     */
    #[Test]
    public function the_cutoff_rolls_over_at_the_masjids_midnight_not_the_servers(): void
    {
        $form = $this->campForm('America/New_York');

        $this->assertSame('Early bird', $this->tierAt($form, '2026-08-15 03:59:00')['label']);
        $this->assertSame(100.0, $this->amountAt($form, '2026-08-15 03:59:00'));

        $this->assertSame('Standard', $this->tierAt($form, '2026-08-15 04:30:00')['label']);
        $this->assertSame(120.0, $this->amountAt($form, '2026-08-15 04:30:00'));
    }

    /** The same instant, a masjid the other side of the date line's midnight. */
    #[Test]
    public function a_masjid_ahead_of_utc_steps_up_before_the_server_does(): void
    {
        $form = $this->campForm('Asia/Karachi'); // UTC+5, no DST

        // 2026-08-14 19:30 UTC is already 2026-08-15 00:30 in Karachi.
        $this->assertSame('Standard', $this->tierAt($form, '2026-08-14 19:30:00')['label']);
        // …and the server, still on August 14, would have said early bird.
        $this->assertSame('Early bird', $this->tierAt($this->campForm(''), '2026-08-14 19:30:00')['label']);
    }

    // ----------------------------------------------------- 2. fallback branch

    /**
     * A form whose masjid has no timezone on file must still resolve, against
     * the app's own timezone. The column is NOT NULL with a 'UTC' default, so
     * the reachable "no timezone" state is the EMPTY STRING — which is why
     * `resolveTier()` uses `?:` rather than `??`, and why that choice is worth
     * an assertion of its own.
     *
     * Asserted at an instant where the answer DIFFERS from the masjid branch
     * above, so no single implementation that ignores the masjid can satisfy
     * both.
     */
    #[Test]
    public function a_masjid_with_a_blank_timezone_falls_back_to_the_app_timezone(): void
    {
        $form = $this->campForm('');

        // 03:59 UTC on the 15th is the 15th as far as UTC is concerned.
        $this->assertSame('Standard', $this->tierAt($form, '2026-08-15 03:59:00')['label']);
        $this->assertSame(120.0, $this->amountAt($form, '2026-08-15 03:59:00'));
    }

    /** A form with no masjid at all — the shape every unit test builds. */
    #[Test]
    public function a_form_with_no_masjid_falls_back_to_the_app_timezone(): void
    {
        $form = new Form();
        $form->settings = $this->settings();

        $this->assertSame('Standard', $this->tierAt($form, '2026-08-15 03:59:00')['label']);
    }

    // ------------------------------------------------------------- 3. DST

    /**
     * SPRING FORWARD. On 2026-03-08 New York moves from UTC-5 to UTC-4, so the
     * calendar day rolls over at 05:00 UTC before the change and 04:00 UTC
     * after. A cut-off on the 7th therefore expires at 2026-03-08 05:00 UTC.
     */
    #[Test]
    public function the_rollover_follows_the_standard_time_offset_before_the_spring_change(): void
    {
        $form = $this->campForm('America/New_York', '2026-03-07');

        // 04:59 UTC = 23:59 EST on March 7 — still the 7th, still early bird.
        $this->assertSame('Early bird', $this->tierAt($form, '2026-03-08 04:59:00')['label']);
        // 05:00 UTC = 00:00 EST on March 8 — the cut-off has passed.
        $this->assertSame('Standard', $this->tierAt($form, '2026-03-08 05:00:00')['label']);
    }

    /**
     * SUMMER, the other side of the same transition: -4, so the day rolls over
     * an hour earlier in UTC. Same code, different offset, and a hard-coded one
     * cannot satisfy both this and the test above.
     */
    #[Test]
    public function the_rollover_follows_the_daylight_offset_in_summer(): void
    {
        $form = $this->campForm('America/New_York', '2026-08-14');

        $this->assertSame('Early bird', $this->tierAt($form, '2026-08-15 03:59:00')['label']);
        $this->assertSame('Standard', $this->tierAt($form, '2026-08-15 04:00:00')['label']);
    }

    /**
     * FALL BACK. On 2026-11-01 New York returns to UTC-5, so a cut-off on
     * November 1 survives until 2026-11-02 05:00 UTC — an hour LATER in UTC than
     * the identical cut-off would have survived in October.
     */
    #[Test]
    public function the_rollover_follows_the_offset_back_after_the_autumn_change(): void
    {
        $form = $this->campForm('America/New_York', '2026-11-01');

        $this->assertSame('Early bird', $this->tierAt($form, '2026-11-02 04:59:00')['label']);
        $this->assertSame('Standard', $this->tierAt($form, '2026-11-02 05:00:00')['label']);

        // October, still on daylight time: the same cut-off shape expires at 04:00 UTC.
        $october = $this->campForm('America/New_York', '2026-10-15');
        $this->assertSame('Early bird', $this->tierAt($october, '2026-10-16 03:59:00')['label']);
        $this->assertSame('Standard', $this->tierAt($october, '2026-10-16 04:00:00')['label']);
    }

    // ------------------------------------------------------------------ helpers

    private function settings(string $earlyBirdUntil = '2026-08-14'): array
    {
        return ['fee' => [
            'currency' => 'USD',
            'perEntryOfSection' => 'attendees',
            'amount' => 140,
            'tiers' => [
                ['label' => 'Early bird', 'amount' => 100, 'until' => $earlyBirdUntil],
                ['label' => 'Standard', 'amount' => 120, 'until' => '2026-12-31'],
                ['label' => 'Day of camp', 'amount' => 140],
            ],
        ]];
    }

    /**
     * A PERSISTED form under a real masjid — the shape the public route resolves,
     * and the one `new Form()` can never produce.
     */
    private function campForm(string $timezone, string $earlyBirdUntil = '2026-08-14'): Form
    {
        $masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => $timezone,
        ]);

        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'camp-' . uniqid(),
            'name' => 'Camp',
            'schema' => ['sections' => [[
                'id' => 'attendees',
                'title' => 'Attendees',
                'repeatable' => true,
                'minEntries' => 1,
                'fields' => [['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true]],
            ]]],
            'settings' => $this->settings($earlyBirdUntil),
        ]);
    }

    private function tierAt(Form $form, string $utc): array
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        $fee = $form->feeRule();

        Carbon::setTestNow();

        return $fee['currentTier'];
    }

    private function amountAt(Form $form, string $utc): float
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        $amount = $form->feeRule()['amount'];

        Carbon::setTestNow();

        return $amount;
    }
}
