<?php

namespace Tests\Feature\Studio;

use App\Enums\SectionType;
use App\Support\Studio\LayoutPresets;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * Studio's layout step and the website frame it draws hold no copy of the
 * presets (docs/manara-studio-w1.md S4, S5).
 *
 * config/studio_layouts.php is the one place a preset, its pages and its
 * sections are defined, and the starter content it may hold is policed there
 * (the no-invention rule, StudioLayoutPresetsTest). The step reads the presets
 * from GET /studio/layout-presets and each thumbnail from the preview endpoint,
 * so no preset key, preset label, page label or section type may be typed into
 * either file: a preset added or renamed in config must reach the step with no
 * code change, and the frame must never draw a word the config did not send.
 */
class StudioLayoutStepLintTest extends TestCase
{
    use ReadsStudioSource;

    private const FILES = [
        'components/super/studio/steps/StudioLayoutStep.vue',
        'components/super/studio/preview/WebFrame.vue',
    ];

    #[Test]
    public function no_preset_key_or_label_is_typed_in(): void
    {
        $presets = array_merge(...array_values(LayoutPresets::optionsPayload()));
        $this->assertNotEmpty($presets);

        foreach ($this->files() as $relative => $code) {
            foreach ($presets as $preset) {
                $this->assertStringNotContainsString($preset['key'], $code, "{$relative} types in the preset key '{$preset['key']}'");
                $this->assertFalse($this->saysWords($code, $preset['label']), "{$relative} types in the preset label '{$preset['label']}'");
            }
        }
    }

    #[Test]
    public function no_page_or_section_label_is_typed_in(): void
    {
        $labels = array_values((array) config('studio_layouts.labels.en'));
        $this->assertNotEmpty($labels);

        foreach ($this->files() as $relative => $code) {
            foreach ($labels as $label) {
                $this->assertFalse($this->saysWords($code, $label), "{$relative} types in the starter label '{$label}'; the plan carries its words");
            }
        }
    }

    #[Test]
    public function no_section_type_is_typed_in(): void
    {
        foreach ($this->files() as $relative => $code) {
            foreach (SectionType::cases() as $type) {
                $this->assertFalse($this->quotes($code, $type->value), "{$relative} types in the section type '{$type->value}'; the plan says what each section is");
            }
        }
    }

    #[Test]
    public function the_cards_and_thumbnails_come_from_the_server(): void
    {
        $step = $this->files()['components/super/studio/steps/StudioLayoutStep.vue'];

        $this->assertStringContainsString('store.fetchPresets(orgType.value)', $step);
        $this->assertMatchesRegularExpression('/v-for="preset in presets"/', $step);
        $this->assertStringContainsString('store.previewPreset(key)', $step, 'each thumbnail is the server\'s plan of this draft with that preset');
        $this->assertStringContainsString('approvedLayout(key, new Date())', $step, 'approving writes the preset and approved_at together');
    }

    /** @return array<string, string> relative path => code with comments removed */
    private function files(): array
    {
        $files = [];

        foreach (self::FILES as $relative) {
            $files[$relative] = $this->spaCode($relative);
        }

        return $files;
    }
}
