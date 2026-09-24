<?php

namespace Tests\Feature\Studio;

use App\Support\Studio\LayoutPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * GET /api/admin/studio/layout-presets?org_type=… (docs/manara-studio-w1.md S4):
 * Step 2's cards for one vertical, served from config so the SPA retypes none.
 */
class StudioLayoutPresetsEndpointTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function it_serves_one_verticals_presets_and_a_blank_org_type_reads_as_masjid(): void
    {
        $school = $this->getJson('/api/admin/studio/layout-presets?org_type=school')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->assertSame(LayoutPresets::optionsPayload()['school'], $school);
        $this->assertSame(['school.essentials', 'school.prospectus', 'school.community'], array_column($school, 'key'));

        $admissions = collect($school[0]['pages'])->firstWhere('slug', 'admissions');
        $this->assertSame('Admissions', $admissions['title']);
        $this->assertSame(['admissions/header', 'admissions/tuition', 'admissions/admissions_form'], array_column($admissions['sections'], 'slot'));

        $blank = $this->getJson('/api/admin/studio/layout-presets')->assertOk()->json('data');
        $this->assertSame('masjid.essentials', $blank[0]['key']);

        $this->getJson('/api/admin/studio/layout-presets?org_type=church')->assertStatus(422);
    }
}
