<?php

namespace Tests\Feature\Studio;

use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * Studio's feature step holds no copy of the feature list
 * (docs/manara-studio-w1.md S5; docs/manara-studio.md, landmine 3).
 *
 * The list already exists in at least seven copies. The step reads it from GET
 * /api/admin/studio/catalogue and nothing else, so no capability key, no
 * capability or group label, and none of the wizard's legacy mobile keys
 * (`config/verticals.php` feature_keys) may be typed into it: a new config
 * entry must reach the step with no code change, and a failed GET must block
 * behind Retry instead of falling back to a list of the step's own.
 */
class StudioFeatureStepSourceTest extends TestCase
{
    use ReadsStudioSource;

    /** The feature step and the code it alone uses. */
    private const FILES = [
        'components/super/studio/steps/StudioFeatureStep.vue',
        'components/super/studio/steps/FeatureRow.vue',
        'core/studio/featureChoices.ts',
    ];

    #[Test]
    public function no_capability_key_is_typed_into_the_feature_step(): void
    {
        $keys = array_keys(config('capabilities'));
        $this->assertNotEmpty($keys);

        foreach ($this->files() as $relative => $code) {
            foreach ($keys as $key) {
                $this->assertFalse($this->namesKey($code, $key), "{$relative} types in the capability key '{$key}'; the catalogue serves the keys");
            }
        }
    }

    #[Test]
    public function no_capability_or_group_label_is_typed_into_the_feature_step(): void
    {
        $labels = array_merge(
            array_values(array_filter(array_map(fn ($definition) => is_array($definition) ? ($definition['label'] ?? null) : null, config('capabilities')))),
            array_values(config('capability_groups')),
        );
        $this->assertNotEmpty($labels);

        foreach ($this->files() as $relative => $code) {
            foreach ($labels as $label) {
                $this->assertFalse($this->saysWords($code, $label), "{$relative} types in the label '{$label}'; the catalogue serves the words");
            }
        }
    }

    #[Test]
    public function the_step_never_falls_back_to_the_legacy_feature_keys(): void
    {
        $legacy = [];
        foreach (config('verticals') as $vertical) {
            if (is_array($vertical) && is_array($vertical['feature_keys'] ?? null)) {
                $legacy = array_merge($legacy, $vertical['feature_keys']);
            }
        }
        $legacy = array_unique($legacy);
        $this->assertCount(11, $legacy, 'the wizard has 11 legacy mobile keys to guard');

        foreach ($this->files() as $relative => $code) {
            $this->assertStringNotContainsString('feature_keys', $code, "{$relative} reads the wizard's feature_keys");

            foreach ($legacy as $key) {
                $this->assertFalse($this->namesKey($code, $key), "{$relative} types in the legacy key '{$key}'");
            }
        }
    }

    #[Test]
    public function the_rows_come_from_the_served_catalogue_and_a_failure_blocks_behind_retry(): void
    {
        $step = $this->files()['components/super/studio/steps/StudioFeatureStep.vue'];

        $this->assertStringContainsString('store.fetchCatalogue(orgType.value)', $step);
        $this->assertMatchesRegularExpression('/v-for="group in groups"/', $step);
        $this->assertStringContainsString('featureGroups(catalogue.value)', $step);
        // The error branch comes before the rows, and offers Retry.
        $this->assertMatchesRegularExpression(
            '/v-else-if="store\.catalogueError \|\| !catalogue".*?@click="load">Retry<\/button>.*?<fieldset v-else/s',
            $step,
            'a failed catalogue GET must block the step behind Retry'
        );
    }

    #[Test]
    public function the_step_writes_only_what_the_operator_sets_and_the_store_keeps_the_map(): void
    {
        $step = $this->files()['components/super/studio/steps/StudioFeatureStep.vue'];

        // The operator's toggle is the step's only write to the map.
        $this->assertSame(1, substr_count($step, 'store.answers.features.capabilities ='), 'the feature step may write the map only in set()');
        $this->assertMatchesRegularExpression('/function set\(key: string, on: boolean\) \{\s*if \(store\.readOnly\) return;\s*store\.answers\.features\.capabilities = /', $step);

        // The store fills and carries it, on an armed draft only, whenever the
        // organisation type, the platforms or the catalogue change.
        $store = $this->spaCode('stores/super/studioDraftStore.ts');
        $this->assertMatchesRegularExpression(
            '/function syncFeatureChoices\(\) \{\s*if \(!armed\.value\) return;\s*const now = currentChoiceContext\(\);\s*const \{ choices, settled \} = carryChoices\(catalogue\.value, answers\.features\.capabilities, choicesContext, now\);/',
            $store,
            'a provisioned draft (never armed) must not have its map rewritten'
        );
        $this->assertMatchesRegularExpression(
            '/watch\(\[\s*\(\) => answers\.identity\.org_type,\s*\(\) => \(answers\.platforms\.platforms \?\? \[\]\)\.join\(\',\'\),\s*catalogue,\s*armed,\s*\], syncFeatureChoices\);/',
            $store,
            'the map must follow the organisation type and the platforms wherever they change'
        );
        $this->assertStringContainsString('choicesContext = currentChoiceContext();', $store, 'a loaded draft\'s map is taken as set for its own type and platforms');
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
