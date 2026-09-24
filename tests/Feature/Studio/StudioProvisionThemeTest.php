<?php

namespace Tests\Feature\Studio;

use App\Models\ThemeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * The new organisation's theme tokens hold two things written by two steps:
 * the preset's header and footer (the provisioner, `tokens.layout`) and the
 * palette's inks (ApplyDraftBrand, `tokens.color.on*`). The second must merge
 * with the first, or the approved layout is lost (docs/manara-studio-w1.md S8).
 */
class StudioProvisionThemeTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function tokens_carry_both_the_auto_ink_and_the_preset_layout(): void
    {
        $draft = $this->draftWith($this->studioAnswers(sections: [
            'brand' => self::BURLINGTON_BRAND,
            'layout' => ['preset' => 'masjid.gathering', 'approved_at' => self::APPROVED_AT],
        ]));

        $preview = $this->postJson(self::DRAFTS . "/{$draft->id}/preview", [])->assertOk()->json('data');
        $data = $this->provision($draft->id)->assertCreated()->json('data');

        $tokens = ThemeSetting::where('masjid_id', $data['masjid_id'])->firstOrFail()->tokens;
        $this->assertSame(['header' => 'overlay', 'footer' => 'columns'], $tokens['layout']);
        $this->assertSame('#111827', $tokens['color']['onPrimary']);

        $served = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $data['masjid_id']])->assertOk()->json('data.theme.tokens');
        $this->assertSame($preview['web']['theme_layout'], $served['layout'], 'the served layout is the one the operator approved');
        $this->assertSame($preview['web_tokens']['onPrimary'], $served['color']['onPrimary'], 'the served ink is the one the preview painted');
    }
}
