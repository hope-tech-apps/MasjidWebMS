<?php

namespace Tests\Feature\Studio;

use App\Support\CapabilityCatalogue;
use App\Support\Studio\StudioPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * The device mockups Step 2 draws are what the provisioned org's apps get
 * (docs/manara-studio-w1.md S4, S8): the iOS tab bar and menu are /menu's, and
 * Android's tab bar is /features' rows 10, 11 and 6, which S8 seeds from the
 * same switches the preview read.
 */
class StudioPreviewParityTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    /** @return array<string, array{0: string, 1: array<string, bool>}> */
    public static function orgs(): array
    {
        return [
            'masjid at its defaults' => ['masjid', []],
            'masjid without announcements or giving' => ['masjid', ['announcements' => false, 'donation_link' => false, 'giving' => false, 'quran' => false]],
            'school at its defaults' => ['school', []],
            'community with contact and donations on' => ['community', ['contact_requests' => true, 'donation_link' => true, 'gallery' => false]],
        ];
    }

    #[Test]
    public function menu_tabs_and_sections_equal_the_ios_preview_and_features_equal_the_android_preview(): void
    {
        foreach (self::orgs() as $case => [$orgType, $choices]) {
            $map = $choices + CapabilityCatalogue::resolve($orgType, []);
            $draft = $this->draftWith($this->studioAnswers($orgType, sections: ['features' => ['capabilities' => $map]]));

            $preview = $this->postJson(self::DRAFTS . "/{$draft->id}/preview", [])->assertOk()->json('data.app');
            $id = $this->provision($draft->id)->assertCreated()->json('data.masjid_id');

            $profile = collect($this->getJson("/api/mobile/masjids/{$id}/menu")->assertOk()->json('data.profiles'))->firstWhere('id', $id);
            $this->assertSame($preview['ios']['tabs'], $profile['tabs'], "{$case}: iOS tabs");
            $this->assertSame($preview['ios']['sections'], $profile['sections'], "{$case}: iOS menu");

            $available = collect($this->getJson("/api/mobile/masjids/{$id}/features")->assertOk()->json('data'))
                ->filter(fn (array $row) => (bool) $row['pivot']['is_available'])
                ->pluck('id')
                ->all();
            $tabs = ['home'];
            foreach (StudioPreview::ANDROID_TABS as $featureId => $tab) {
                if (in_array($featureId, $available, true)) {
                    $tabs[] = $tab;
                }
            }
            $this->assertSame($preview['android']['tabs'], $tabs, "{$case}: Android tabs");
        }
    }
}
