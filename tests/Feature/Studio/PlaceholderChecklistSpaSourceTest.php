<?php

namespace Tests\Feature\Studio;

use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * The page builder's starter-site badges (Studio W2 S10) draw nothing for an
 * empty checklist, which is what every live organisation gets, never hold the
 * page builder up while the checklist loads, and are read by no save path.
 * Source-level, like StudioSpaSourceTest: the suite has no browser.
 */
class PlaceholderChecklistSpaSourceTest extends TestCase
{
    use ReadsStudioSource;

    #[Test]
    public function the_store_reads_one_endpoint_and_a_failure_is_an_empty_checklist(): void
    {
        $store = $this->spaCode('stores/masjid/pagesStore.ts');

        $this->assertMatchesRegularExpression('~/api/admin/masjids/\$\{masjidStore\.masjid\.id\}/pages/placeholders`~', $store);
        $this->assertMatchesRegularExpression('~catch \(e\) \{\s*placeholderChecklist\.value = null;~', $store, 'a failed read draws nothing rather than stale or invented counts');
        $this->assertStringContainsString("'/api/admin/masjids/\${string}/pages/placeholders'", str_replace('`', "'", $this->spaCode('core/types/config/BackendApiRoutes.ts')));
    }

    #[Test]
    public function the_pages_list_draws_nothing_for_an_empty_payload(): void
    {
        $view = $this->spaCode('views/dashboard/pages/PagesView.vue');

        $this->assertMatchesRegularExpression('~v-if="checklistOpen > 0"[^>]*starter-to-fill-total~s', $view);
        $this->assertMatchesRegularExpression('~v-if="pagesStore\.openCountForPage\(page\.id\) > 0"~', $view);
        $this->assertMatchesRegularExpression('~checklistOpen = computed\(\(\) => pagesStore\.placeholderChecklist\?\.open \?\? 0\)~', $view);
        $this->assertMatchesRegularExpression('~void pagesStore\.fetchPlaceholderChecklist\(\);~', $view, 'the pages never wait for the checklist');
    }

    #[Test]
    public function the_sections_list_draws_nothing_for_an_empty_payload(): void
    {
        $view = $this->spaCode('views/dashboard/pages/PageSectionsView.vue');

        $this->assertMatchesRegularExpression('~v-if="openCount\(section\.id\) > 0"~', $view);
        $this->assertMatchesRegularExpression('~v-if="!section\.is_active && heldUntilFilled\(section\.id\)"~', $view);
        $this->assertStringContainsString('inactive until filled', $view);
        $this->assertMatchesRegularExpression('~void pagesStore\.fetchPlaceholderChecklist\(\);~', $view, 're-read on every load, so a save lowers the count');
    }

    #[Test]
    public function the_section_modal_shows_the_hints_and_no_save_path_reads_them(): void
    {
        $modal = $this->spaCode('components/modals/SectionFormModal.vue');

        $this->assertMatchesRegularExpression('~v-if="starterPlaceholders\.length > 0"~', $modal);
        $this->assertStringContainsString('Starter placeholder', $modal);
        $this->assertMatchesRegularExpression('~starterPlaceholders = computed\(\(\) => props\.section \? pagesStore\.openPlaceholders\(props\.section\.id\) : \[\]\)~', $modal);

        // The save serialiser is untouched: it neither reads the checklist nor drops `settings`.
        preg_match('~const handleSubmit = async \(\) => \{(.*?)\n\};~s', $modal, $submit);
        $this->assertNotEmpty($submit, 'handleSubmit was found');
        $this->assertStringNotContainsString('starterPlaceholders', $submit[1]);
        $this->assertStringNotContainsString('placeholderChecklist', $submit[1]);
        $this->assertStringContainsString("formDataToSend.append('settings', JSON.stringify(formData.value.settings))", $submit[1]);
    }
}
