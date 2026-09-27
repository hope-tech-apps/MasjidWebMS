<?php

namespace Tests\Feature\Studio;

use App\Jobs\PurgeRendererCache;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\Page;
use App\Models\StudioDraft;
use App\Support\Studio\LayoutPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * Studio W2 S12: an organisation's website language. Stored on `masjids`,
 * provisioned from a draft, carried by the by-host lookup only when set,
 * editable on a live organisation from Studio, and Arabic offered only once
 * the reviewed Arabic starter labels exist (the slice ships dark).
 */
class WebsiteLocaleTest extends TestCase
{
    use MakesStudioDomains;
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    /** A stand-in Arabic table with exactly the English keys, each value marked. */
    private function withArabicLabels(): void
    {
        config(['studio_layouts.labels.ar' => array_map(
            fn (string $label) => "AR:{$label}",
            (array) config('studio_layouts.labels.en'),
        )]);
    }

    private function draftFor(string $locale): StudioDraft
    {
        $answers = $this->studioAnswers('masjid', self::MINIMAL);
        $answers['identity']['website_locale'] = $locale;

        return $this->draftWith($answers);
    }

    #[Test]
    public function the_column_is_a_short_nullable_string_and_every_existing_org_has_none(): void
    {
        $this->assertTrue(Schema::hasColumn('masjids', 'website_locale'));
        $this->assertContains(Schema::getColumnType('masjids', 'website_locale'), ['varchar', 'string']);
        $this->assertNull($this->makeOrg()->fresh()->website_locale);
        $this->assertContains('website_locale', Masjid::PUBLIC_DIRECTORY_DENYLIST);
    }

    #[Test]
    public function the_lookup_carries_locale_only_when_set(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'www.locale.example', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $lookup = fn () => $this->getJson('/api/v1/organizations/by-host?host=www.locale.example')->assertOk()->json('data');

        $this->assertArrayNotHasKey('locale', $lookup(), 'unset: the bytes every live organisation has today');

        foreach (['ar', 'en'] as $locale) {
            $org->update(['website_locale' => $locale]);
            $this->assertSame($locale, $lookup()['locale']);
        }

        $org->update(['website_locale' => null]);
        $this->assertArrayNotHasKey('locale', $lookup());
    }

    #[Test]
    public function an_arabic_draft_provisions_arabic_labels(): void
    {
        $this->withArabicLabels();

        $id = $this->provision($this->draftFor('ar')->id)->assertCreated()->json('data.masjid_id');
        $org = Masjid::findOrFail($id);

        $this->assertSame('ar', $org->website_locale);

        $home = Page::where('masjid_id', $id)->where('slug', 'home')->firstOrFail();
        $this->assertSame(config('studio_layouts.labels.ar')['page.home'], $home->title, 'the starter pages are worded from labels.ar');
        $this->assertStringStartsWith('AR:', $home->title);
    }

    #[Test]
    public function arabic_is_refused_while_the_label_table_is_absent(): void
    {
        $this->assertNull(config('studio_layouts.labels.ar'), 'the premise: the slice ships dark');
        $this->assertSame(['en'], LayoutPresets::websiteLocales());
        $this->getJson('/api/admin/onboarding/options')->assertOk()->assertJsonPath('data.website_locales', ['en']);

        $before = Masjid::count();
        $draft = $this->draftFor('ar');

        $this->provision($draft->id)
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data.website_locale.0', 'An Arabic website cannot be provisioned yet: its starter labels have not been reviewed.');

        $this->assertSame($before, Masjid::count(), 'nothing was created');
        $this->assertSame(StudioDraft::STATUS_DRAFT, $draft->fresh()->status);

        // Once the reviewed table ships, Arabic is offered.
        $this->withArabicLabels();
        $this->assertSame(['en', 'ar'], LayoutPresets::websiteLocales());
        $this->getJson('/api/admin/onboarding/options')->assertOk()->assertJsonPath('data.website_locales', ['en', 'ar']);
    }

    #[Test]
    public function an_english_or_absent_locale_provisions_as_before(): void
    {
        $id = $this->provision($this->draftFor('en')->id)->assertCreated()->json('data.masjid_id');
        $this->assertSame('en', Masjid::findOrFail($id)->website_locale);

        $plain = $this->provision($this->draftWith($this->studioAnswers('masjid', self::MINIMAL))->id)->assertCreated()->json('data.masjid_id');
        $this->assertNull(Masjid::findOrFail($plain)->website_locale);
    }

    #[Test]
    public function an_unknown_locale_is_a_422(): void
    {
        $draft = $this->draftWith($this->studioAnswers('masjid', self::MINIMAL));

        $this->patchJson("/api/admin/studio/drafts/{$draft->id}", [
            'lock_version' => $draft->lock_version,
            'answers' => ['identity' => ['website_locale' => 'fr'] + $draft->section('identity')],
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $org = $this->makeOrg();
        $this->patch("/api/admin/studio/organisations/{$org->id}/website-locale", ['locale' => 'fr'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertNull($org->fresh()->website_locale);
    }

    #[Test]
    public function a_live_organisations_locale_is_set_and_cleared_from_studio_and_purges_the_renderer(): void
    {
        config(['services.renderer' => [
            'secret' => 'test-renderer-secret-0123456789abcdef', // RendererConfig needs 32+ characters
            'preview_origin' => 'https://renderer.example.test',
            'purge_origins' => 'https://renderer.example.test',
            'admin_origins' => 'https://masjid.hopetechapps.com',
            'timeout' => 5,
        ]]);
        $org = $this->makeOrg();
        Queue::fake();
        Log::spy();

        // Form-encoded, as the SPA sends it. Arabic here is not gated on the
        // starter-label table: this is the renderer's chrome, not starter pages.
        $this->patch("/api/admin/studio/organisations/{$org->id}/website-locale", ['locale' => 'ar'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => ['website_locale' => 'ar']]);
        $this->assertSame('ar', $org->fresh()->website_locale);
        Queue::assertPushed(PurgeRendererCache::class, fn (PurgeRendererCache $job) => $job->organisationId === (int) $org->id);
        Log::shouldHaveReceived('warning')->with('Organisation website language changed', Mockery::on(fn ($c) => $c['masjid_id'] === (int) $org->id && $c['before'] === null && $c['after'] === 'ar'))->once();

        // Empty clears it: null renders `en`, and the lookup stops sending a locale.
        $this->patch("/api/admin/studio/organisations/{$org->id}/website-locale", ['locale' => ''], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertExactJson(['status' => 'success', 'data' => ['website_locale' => null]]);
        $this->assertNull($org->fresh()->website_locale);

        // The snapshot shows it in the identity section.
        $org->update(['website_locale' => 'ar']);
        $this->getJson("/api/admin/studio/organisations/{$org->id}")->assertOk()
            ->assertJsonPath('data.sections.identity.data.website_locale', 'ar');
    }
}
