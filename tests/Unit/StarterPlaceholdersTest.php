<?php

namespace Tests\Unit;

use App\Support\Studio\StarterPlaceholders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The public-settings strip on the page-section serializer every live renderer
 * reads (docs/manara-studio-w1.md S8): it removes Studio's `studio` marker and
 * leaves every other value exactly as it came.
 */
class StarterPlaceholdersTest extends TestCase
{
    #[Test]
    public function a_marker_alone_serves_as_null_and_beside_other_settings_is_removed(): void
    {
        $marker = ['version' => 1, 'preset' => 'masjid.classic', 'slot' => 'home/hero', 'placeholders' => []];

        $this->assertNull(StarterPlaceholders::publicSettings(['studio' => $marker]));
        $this->assertSame(['bind' => 'about_text'], StarterPlaceholders::publicSettings(['bind' => 'about_text', 'studio' => $marker]));
    }

    #[Test]
    public function every_live_settings_shape_is_returned_untouched(): void
    {
        foreach ([null, [], ['bind' => 'about_text'], ['bind' => 'mission_vision_cards'], ['background' => '#ffffff'], 'raw', 0, ['studio_note' => 'x']] as $settings) {
            $this->assertSame($settings, StarterPlaceholders::publicSettings($settings));
        }
    }
}
