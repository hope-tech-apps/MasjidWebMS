<?php

namespace Tests\Unit;

use App\Models\ThemeSetting;
use App\Support\DesignTokens;
use App\Support\Studio\PaletteContrast;
use App\Support\WcagColor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Studio's PaletteReport (docs/manara-studio-w1.md S2, R16). The figures the
 * plan quotes were the recon's own arithmetic; these assertions are the
 * measurement.
 */
class PaletteContrastTest extends TestCase
{
    /** The Burlington palette the old wizard defaulted every client to. */
    private const BURLINGTON = [
        'primary_color' => '#01b151',
        'secondary_color' => '#1b1b2e',
        'accent_color' => '#ffba63',
        'background_color' => '#f3f8fb',
    ];

    private const WHITE_PAGE = [
        'primary_color' => '#FFFFFF',
        'secondary_color' => '#1B1B2E',
        'accent_color' => '#FFBA63',
        'background_color' => '#FFFFFF',
    ];

    /** @return array<string, mixed> */
    private function pair(array $report, string $key): array
    {
        foreach ($report['pairs'] as $pair) {
            if ($pair['key'] === $key) {
                return $pair;
            }
        }

        $this->fail("no {$key} pair in the report");
    }

    #[Test]
    public function black_on_white_is_21(): void
    {
        $pair = $this->pair(PaletteContrast::report(self::WHITE_PAGE, ['onPrimary' => '#000000']), 'on_primary');

        $this->assertSame(21.0, $pair['ratio']);
        $this->assertTrue($pair['passes']);
        $this->assertSame('manual', $pair['ink_source']);
    }

    #[Test]
    public function grey_777_on_white_is_about_4_48_and_fails(): void
    {
        $report = PaletteContrast::report(self::WHITE_PAGE, ['onPrimary' => '#777777']);
        $pair = $this->pair($report, 'on_primary');

        $this->assertSame(4.48, $pair['ratio']);
        $this->assertSame(4.5, $pair['required']);
        $this->assertFalse($pair['passes']);
        $this->assertTrue($pair['blocking']);
        // A hand-picked ink is honoured even when it fails, and the report says so.
        $this->assertSame('#777777', $report['tokens']['color']['onPrimary']);
        $this->assertFalse($report['valid']);
        $this->assertSame(['on_primary'], $report['blocking_failures']);
    }

    #[Test]
    public function a_ratio_that_rounds_up_to_4_5_still_fails(): void
    {
        // #088766 on white is 4.4989:1. Shown to 2 dp it reads 4.50; judged on
        // that, body text below WCAG AA would pass the blocking gate.
        $report = PaletteContrast::report(self::WHITE_PAGE, ['onPrimary' => '#088766']);
        $pair = $this->pair($report, 'on_primary');

        $this->assertSame(4.5, $pair['ratio']);
        $this->assertFalse($pair['passes']);
        $this->assertContains('on_primary', $report['blocking_failures']);
    }

    #[Test]
    public function on_burlington_green_design_tokens_white_is_about_2_83_and_the_auto_ink_about_6_26(): void
    {
        $designInk = DesignTokens::resolve(new ThemeSetting(['primary_color' => '#01B151']))['color']['onPrimary'];
        $this->assertSame('#FFFFFF', $designInk);
        $this->assertSame(2.83, round(WcagColor::ratio($designInk, '#01B151'), 2));

        $report = PaletteContrast::report(self::BURLINGTON);
        $pair = $this->pair($report, 'on_primary');

        $this->assertSame('#111827', $pair['foreground']);
        $this->assertSame('#01B151', $pair['background']);
        $this->assertSame(6.26, $pair['ratio']);
        $this->assertTrue($pair['passes']);
        $this->assertSame('auto', $pair['ink_source']);
        $this->assertSame(['onPrimary' => '#111827'], $report['tokens']['color']);
    }

    #[Test]
    public function the_burlington_palette_passes_with_its_marks_flagged_as_advisory_only(): void
    {
        $report = PaletteContrast::report(self::BURLINGTON);

        $this->assertTrue($report['valid']);
        $this->assertSame([], $report['blocking_failures']);
        $this->assertSame(
            ['text_on_background', 'on_primary', 'on_secondary', 'on_accent', 'primary_on_background', 'accent_on_background'],
            array_column($report['pairs'], 'key'),
        );

        // DesignTokens' own ink already reads on the navy and the peach, so it
        // stands and nothing is overridden for them.
        $this->assertSame('design_tokens', $this->pair($report, 'on_secondary')['ink_source']);
        $this->assertSame('design_tokens', $this->pair($report, 'on_accent')['ink_source']);

        foreach (['primary_on_background', 'accent_on_background'] as $key) {
            $pair = $this->pair($report, $key);
            $this->assertSame(3.0, $pair['required']);
            $this->assertFalse($pair['blocking']);
            $this->assertFalse($pair['passes'], "{$key} is below 3:1 on Burlington's background and is reported, not blocking");
            $this->assertNull($pair['ink_source']);
        }
    }

    #[Test]
    public function every_ratio_is_wcag_colors_to_two_places(): void
    {
        $report = PaletteContrast::report(self::BURLINGTON, ['onAccent' => '#1B1B2E']);

        foreach ($report['pairs'] as $pair) {
            $this->assertSame(round(WcagColor::ratio($pair['foreground'], $pair['background']), 2), $pair['ratio'], $pair['key']);
        }
    }

    #[Test]
    public function a_colour_not_yet_chosen_is_never_graded_with_a_default(): void
    {
        $brand = self::BURLINGTON;
        unset($brand['primary_color']);

        $report = PaletteContrast::report($brand);
        $pair = $this->pair($report, 'on_primary');

        // DesignTokens would have filled Burlington's green in; the report does not.
        $this->assertNull($pair['background']);
        $this->assertNull($pair['ratio']);
        $this->assertFalse($pair['passes']);
        $this->assertNull($this->pair($report, 'primary_on_background')['foreground']);
        $this->assertContains('on_primary', $report['blocking_failures']);
        $this->assertFalse($report['valid']);
    }

    #[Test]
    public function aspect_warning_is_true_for_a_1000_by_250_logo(): void
    {
        $this->assertTrue(PaletteContrast::report(self::BURLINGTON, [], ['width' => 1000, 'height' => 250])['aspect_warning']);
        $this->assertFalse(PaletteContrast::report(self::BURLINGTON, [], ['width' => 400, 'height' => 200])['aspect_warning'], 'exactly 2:1 is fine');
        $this->assertFalse(PaletteContrast::report(self::BURLINGTON, [], ['width' => 250, 'height' => 1000])['aspect_warning']);
        $this->assertFalse(PaletteContrast::report(self::BURLINGTON)['aspect_warning'], 'no logo, no warning');
    }
}
