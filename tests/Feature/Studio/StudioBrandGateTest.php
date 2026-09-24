<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\Page;
use App\Models\StudioDraft;
use App\Models\ThemeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * StudioBrandGate: what Step 3 refuses beyond the wizard's rules
 * (docs/manara-studio-w1.md S8, R16, R23, R27). Each refusal is a 422 that
 * creates nothing.
 */
class StudioBrandGateTest extends TestCase
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
    public function a_grey_777777_background_is_refused(): void
    {
        // DesignTokens reads #777777 as dark and puts #F5F5F5 text on it: about 4.1:1.
        $draft = $this->draftWith($this->studioAnswers(sections: ['brand' => ['background_color' => '#777777'] + self::PLAIN_BRAND]));

        $this->provision($draft->id)
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['brand.text_on_background']]);

        $this->assertNothingCreated($draft);
    }

    #[Test]
    public function an_alpha_hex_is_refused_by_studio_but_still_accepted_by_the_legacy_endpoint(): void
    {
        $brand = ['primary_color' => '#1B4D3EFF'] + self::PLAIN_BRAND;
        $draft = $this->draftWith($this->studioAnswers(sections: ['brand' => $brand]));

        $this->provision($draft->id)
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['brand.primary_color']]);
        $this->assertNothingCreated($draft);

        // The wizard's endpoint keeps its own rule: #RGB, #RRGGBB and alpha.
        $payload = $this->draftWith($this->studioAnswers(sections: ['brand' => $brand]), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['web_domain']);

        $this->postJson('/api/admin/onboarding/provision', $payload)->assertCreated();
        $this->assertSame('#1B4D3EFF', ThemeSetting::latest('id')->first()->primary_color);
    }

    /** @return array<string, array{0: string}> */
    public static function webRequirements(): array
    {
        return [
            'web without a logo' => ['logo'],
            'web without a slug' => ['slug'],
            'web without an approved layout' => ['layout_preset'],
        ];
    }

    #[Test]
    #[DataProvider('webRequirements')]
    public function web_needs_a_logo_a_slug_and_an_approved_layout_and_creates_nothing_without_one(string $missing): void
    {
        $answers = $this->studioAnswers();

        if ($missing === 'slug') {
            // Blank, as a cleared field is stored: no slug, not an invalid one.
            $answers['identity']['slug'] = '  ';
        }

        if ($missing === 'layout_preset') {
            unset($answers['layout']['approved_at']);
        }

        $draft = $this->draftWith($answers, logo: $missing !== 'logo');

        $response = $this->provision($draft->id)->assertStatus(422);

        $this->assertSame([$missing], array_keys($response->json('data')), "only {$missing} is refused");

        if ($missing === 'slug') {
            $this->assertSame(['A website needs a subdomain. Choose one in Foundation.'], $response->json('data.slug'), 'refused as missing, not as an invalid label');
        }
        $this->assertNothingCreated($draft);
    }

    #[Test]
    public function without_web_none_of_the_three_is_needed(): void
    {
        $answers = $this->studioAnswers(sections: ['platforms' => ['platforms' => ['ios', 'android']]]);
        unset($answers['identity']['slug'], $answers['layout']);

        $this->provision($this->draftWith($answers, logo: false)->id)->assertCreated();
    }

    #[Test]
    public function burlingtons_green_auto_inks_to_111827(): void
    {
        $draft = $this->draftWith($this->studioAnswers(sections: ['brand' => self::BURLINGTON_BRAND]));

        $data = $this->provision($draft->id)->assertCreated()->json('data');

        $theme = ThemeSetting::where('masjid_id', $data['masjid_id'])->firstOrFail();
        $this->assertSame('#111827', $theme->tokens['color']['onPrimary']);
        $this->assertSame('#01B151', $theme->primary_color, 'the brand colour itself is untouched');
    }

    #[Test]
    public function a_name_another_organisation_has_is_refused_by_field_rather_than_failing(): void
    {
        $taken = $this->org();
        $answers = $this->studioAnswers();
        $answers['identity']['name'] = $taken->name;

        $this->provision($this->draftWith($answers)->id)
            ->assertStatus(422)
            ->assertJsonPath('data.name.0', 'Another organisation already has this name.');

        $this->assertSame(1, Masjid::count(), 'only the organisation that already had the name');
    }

    private function assertNothingCreated(StudioDraft $draft): void
    {
        $this->assertSame(0, Masjid::count(), 'no organisation');
        $this->assertSame(0, Page::count());
        $this->assertSame(0, MasjidDomain::count());
        $this->assertSame(StudioDraft::STATUS_DRAFT, $draft->fresh()->status);
        \Illuminate\Support\Facades\Mail::assertNothingOutgoing();
    }
}
