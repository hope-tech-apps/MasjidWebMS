<?php

namespace Tests\Unit;

use App\Support\WcagColor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contrast answers `/menu` and `/orgs` hand the apps.
 *
 * Every brand colour actually in play is pinned by name here, because the whole
 * point of moving this arithmetic to the server is that the two apps stop
 * disagreeing about it. If one of these expectations changes, a band that reads
 * today goes unreadable on somebody's phone — so the numbers are written out
 * rather than recomputed by the test.
 *
 * The greens are Burlington (#01B151), MAS Youth (#47953A and the #5BB34C it
 * was nearly changed to) and the demo tenants' (#2E9E4E); the blue is MEC
 * (#2B66C2), the one colour in the fleet that wants WHITE on its band.
 */
class WcagColorTest extends TestCase
{
    #[Test]
    public function every_green_in_the_fleet_takes_dark_text_on_its_band(): void
    {
        foreach (['#5bb34c', '#47953A', '#01B151', '#2E9E4E'] as $green) {
            $this->assertSame(
                WcagColor::DARK,
                WcagColor::onPrimary($green),
                "{$green} must take dark band text"
            );
        }
    }

    #[Test]
    public function the_mec_blue_takes_white_text_on_its_band(): void
    {
        $this->assertSame(WcagColor::LIGHT, WcagColor::onPrimary('#2B66C2'));
    }

    #[Test]
    public function band_text_is_whichever_candidate_actually_reads_better(): void
    {
        // The rule, not the table: onPrimary is never a preference, it is the
        // higher of two measured ratios.
        foreach (['#5BB34C', '#47953A', '#01B151', '#2E9E4E', '#2B66C2', '#7C7C7C'] as $primary) {
            $chosen = WcagColor::onPrimary($primary);
            $other = $chosen === WcagColor::DARK ? WcagColor::LIGHT : WcagColor::DARK;

            $this->assertGreaterThanOrEqual(
                WcagColor::ratio($primary, $other),
                WcagColor::ratio($primary, $chosen),
                "{$primary} chose the worse of the two"
            );
        }
    }

    #[Test]
    public function brand_text_on_the_white_surface_always_clears_aa(): void
    {
        foreach (['#5BB34C', '#47953A', '#01B151', '#2E9E4E', '#2B66C2', '#7C7C7C', '#FFFF00'] as $primary) {
            $text = WcagColor::primaryOnSurface($primary);

            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $text);
            $this->assertGreaterThanOrEqual(
                WcagColor::AA_NORMAL,
                WcagColor::ratio($text, '#FFFFFF'),
                "{$primary} produced body text under 4.5:1 on white"
            );
        }
    }

    #[Test]
    public function a_colour_that_already_reads_on_white_is_returned_untouched(): void
    {
        // MEC's blue clears 4.5:1 on white on its own; darkening it would hand
        // the app a colour that is not the organisation's.
        $this->assertGreaterThanOrEqual(WcagColor::AA_NORMAL, WcagColor::ratio('#2B66C2', '#FFFFFF'));
        $this->assertSame('#2B66C2', WcagColor::primaryOnSurface('#2B66C2'));
    }

    #[Test]
    public function a_colour_that_does_not_read_on_white_is_darkened_not_replaced(): void
    {
        $text = WcagColor::primaryOnSurface('#5BB34C');

        $this->assertNotSame('#5BB34C', $text);
        $this->assertNotSame('#000000', $text, 'a near-miss must not collapse to black');
        $this->assertGreaterThan(
            WcagColor::ratio('#5BB34C', '#FFFFFF'),
            WcagColor::ratio($text, '#FFFFFF'),
            'the result must be darker than the brand colour, never lighter'
        );
    }

    #[Test]
    public function the_same_colour_always_produces_the_same_answers(): void
    {
        // The payload hash is a sha1 of this output. A non-deterministic step
        // would change the ETag on every request and defeat the 304.
        foreach (['#5BB34C', '#47953A', '#2B66C2'] as $primary) {
            $this->assertSame(WcagColor::primaryOnSurface($primary), WcagColor::primaryOnSurface($primary));
            $this->assertSame(WcagColor::onPrimary($primary), WcagColor::onPrimary($primary));
        }
    }

    #[Test]
    public function band_text_is_large_only_when_neither_candidate_clears_aa(): void
    {
        // A mid grey is the only kind of colour that can fail both ways: white
        // reads at 4.17:1 and dark at 4.25:1, so neither is safe at body size.
        $this->assertLessThan(WcagColor::AA_NORMAL, WcagColor::ratio('#7C7C7C', WcagColor::LIGHT));
        $this->assertLessThan(WcagColor::AA_NORMAL, WcagColor::ratio('#7C7C7C', WcagColor::DARK));
        $this->assertTrue(WcagColor::bandTextLargeOnly('#7C7C7C'));

        // Every brand colour in play clears one of the two, so none of them
        // forces the large-text rule.
        foreach (['#5BB34C', '#47953A', '#01B151', '#2E9E4E', '#2B66C2'] as $primary) {
            $this->assertFalse(
                WcagColor::bandTextLargeOnly($primary),
                "{$primary} should not need the large-text rule"
            );
        }
    }

    #[Test]
    public function normalisation_accepts_the_three_forms_and_refuses_the_rest(): void
    {
        $this->assertSame('#AABBCC', WcagColor::normalize('#abc'));
        $this->assertSame('#01B151', WcagColor::normalize('#01b151'));
        $this->assertSame('#01B151', WcagColor::normalize('  #01B151  '));
        // Alpha is dropped: a client filling a band wants six digits.
        $this->assertSame('#01B151', WcagColor::normalize('#01B151FF'));

        foreach ([null, '', 'green', '01B151', '#12', '#12345', '#GGGGGG'] as $bad) {
            $this->assertNull(WcagColor::normalize($bad), 'accepted ' . var_export($bad, true));
        }
    }

    #[Test]
    public function an_unusable_colour_answers_safely_instead_of_throwing(): void
    {
        // One organisation's mistyped hex must never 500 the menu for the fleet.
        foreach ([null, '', 'rebeccapurple', '#12345'] as $bad) {
            $this->assertSame(WcagColor::LIGHT, WcagColor::onPrimary($bad));
            $this->assertSame(WcagColor::DARK, WcagColor::primaryOnSurface($bad));
            $this->assertFalse(WcagColor::bandTextLargeOnly($bad));
            $this->assertSame(1.0, WcagColor::ratio($bad, '#FFFFFF'));
        }
    }

    #[Test]
    public function the_ratio_is_the_wcag_one(): void
    {
        $this->assertEqualsWithDelta(21.0, WcagColor::ratio('#FFFFFF', '#000000'), 0.001);
        $this->assertEqualsWithDelta(21.0, WcagColor::ratio('#000000', '#FFFFFF'), 0.001, 'order must not matter');
        $this->assertEqualsWithDelta(1.0, WcagColor::ratio('#01B151', '#01B151'), 0.001);
        $this->assertEqualsWithDelta(5.552, WcagColor::ratio('#2B66C2', '#FFFFFF'), 0.01);
        $this->assertEqualsWithDelta(6.754, WcagColor::ratio('#5BB34C', WcagColor::DARK), 0.01);
    }
}
