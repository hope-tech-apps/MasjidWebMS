<?php

namespace Tests\Feature\Studio;

use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * Studio W2 S12 in the SPA, source-level like StudioSpaSourceTest: Step 0 offers
 * the languages the server says it can provision (Arabic only once its reviewed
 * labels ship), and a live organisation's language is saved through its one
 * endpoint, behind a confirm that says it needs the owner's go.
 */
class WebsiteLocaleSpaSourceTest extends TestCase
{
    use ReadsStudioSource;

    #[Test]
    public function step_zero_offers_only_the_languages_the_server_offers(): void
    {
        $panel = $this->spaCode('components/super/studio/foundation/IdentityPanel.vue');

        $this->assertMatchesRegularExpression('~store\.options\?\.website_locales \?\? \[\'en\'\]~', $panel, 'an older payload reads as English only');
        $this->assertStringContainsString('identity.value.website_locale = value', $panel);
        $this->assertStringNotContainsString("'ar'] ??", $panel, 'Arabic is never offered by default');
    }

    #[Test]
    public function a_live_organisations_language_is_saved_through_its_endpoint_behind_the_owners_go(): void
    {
        $store = $this->spaCode('stores/super/studioOrganisationStore.ts');
        $card = $this->spaCode('components/super/studio/live/LiveWebsiteLocaleCard.vue');

        $this->assertMatchesRegularExpression('~ApiService\.patch\(`/api/admin/studio/organisations/\$\{current\.org\.id\}/website-locale`, body\)~', $store);
        $this->assertMatchesRegularExpression("~body\\.append\\('locale', locale\\)~", $store);
        $this->assertStringContainsString("go ahead only with the owner's go", $card);
        $this->assertStringContainsString('right to left', $card);
        $this->assertStringContainsString('<LiveWebsiteLocaleCard', $this->spaCode('views/dashboard/super/studio/StudioOrganisationView.vue'));
    }

    #[Test]
    public function the_language_dialog_never_renders_the_organisation_name_as_html(): void
    {
        $card = $this->spaCode('components/super/studio/live/LiveWebsiteLocaleCard.vue');

        // SweetAlert renders `title`, `html` and `footer` as HTML, and the name is tenant-editable.
        $this->assertStringContainsString('titleText: title', $card);
        $this->assertDoesNotMatchRegularExpression('~^\s*(title|footer)\s*:~m', $card, 'a data-bearing title or footer must be titleText or escaped');
        $this->assertMatchesRegularExpression('~html: dialogHtml\(sentences\)~', $card, 'the body goes through the escaping helper');
        $this->assertDoesNotMatchRegularExpression('~html:\s*`~', $card, 'no hand-built html template with interpolation');
    }

    #[Test]
    public function the_store_reset_clears_the_saving_flag_so_the_language_card_never_locks(): void
    {
        $store = $this->spaCode('stores/super/studioOrganisationStore.ts');

        // Capture only reset()'s own body; an unbounded lazy match runs on into saveWebsiteLocale's finally block.
        $this->assertSame(1, preg_match('~function reset\(\) \{((?:(?!\n    \}).)*)\n    \}~s', $store, $m), 'reset() not found');
        $this->assertStringContainsString('savingLocale.value = false;', $m[1]);
    }

    #[Test]
    public function clearing_the_language_has_its_own_wording_and_claims_no_direction_for_it(): void
    {
        $card = $this->spaCode('components/super/studio/live/LiveWebsiteLocaleCard.vue');

        $this->assertStringContainsString("Clear \${org}'s website language?", $card);
        $this->assertStringNotContainsString('not chosen (English)', $card, 'the cleared label is never spliced into a sentence');
    }
}
