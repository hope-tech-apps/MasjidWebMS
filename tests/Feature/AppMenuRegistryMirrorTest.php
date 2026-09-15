<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Support\AppMenu;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/app_menu.php and AppMenu::DEFAULT_REGISTRY must be the same registry.
 *
 * The duplicate exists because bin/deploy merges, installs and migrates BEFORE
 * it rebuilds the config cache: for a stretch of every deploy the new PHP reads
 * the PREVIOUS cache. A menu that lost its Worship section for ninety seconds
 * because of that would look exactly like a bug in the switches, so the code
 * copy answers instead — which is only safe while the copy is honest.
 *
 * The rest of this file pins the registry's internal rules, each of which is a
 * silent failure if it breaks: a tab that names no section item is a tab the
 * clients drop (the bar quietly loses a destination), a legacy id claimed twice
 * routes an installed build to the wrong screen, and an `any_of` key that is
 * not a module is a switch nobody can ever turn off.
 */
class AppMenuRegistryMirrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AppMenu::forgetRegistry();
    }

    protected function tearDown(): void
    {
        AppMenu::forgetRegistry();

        parent::tearDown();
    }

    #[Test]
    public function the_config_file_and_the_code_copy_are_identical(): void
    {
        $this->assertSame(
            AppMenu::DEFAULT_REGISTRY,
            AppMenu::registry(),
            'config/app_menu.php and AppMenu::DEFAULT_REGISTRY have drifted'
        );
    }

    #[Test]
    public function the_shipped_config_validates(): void
    {
        // If it did not, registry() would answer from the code copy and log a
        // warning on every single request — silently, since the two are equal.
        Log::spy();

        AppMenu::registry();

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function every_tab_is_also_an_item_in_a_section(): void
    {
        $registry = AppMenu::registry();
        $placed = $this->placedItemKeys($registry);

        $this->assertSame('home', $registry['tabs'][0], 'home must be the first tab');

        foreach ($registry['tabs'] as $key) {
            $this->assertContains($key, $placed, "tab {$key} is in no section");
        }

        $this->assertSame(
            $registry['tabs'],
            array_values(array_unique($registry['tabs'])),
            'a tab is listed twice'
        );
        $this->assertLessThanOrEqual(
            $registry['max_tabs'],
            count($registry['tabs']),
            'more eligible tabs than the bar can carry'
        );
    }

    #[Test]
    public function the_eleven_legacy_feature_ids_each_appear_exactly_once(): void
    {
        $ids = [];

        foreach (AppMenu::registry()['items'] as $key => $item) {
            $this->assertArrayHasKey('legacy_feature_id', $item, "{$key} has no legacy_feature_id");

            if ($item['legacy_feature_id'] !== null) {
                $this->assertIsInt($item['legacy_feature_id'], "{$key}'s legacy id is not an integer");
                $ids[] = $item['legacy_feature_id'];
            }
        }

        sort($ids);

        $this->assertSame(range(1, 11), $ids);
        $this->assertNull(
            AppMenu::registry()['items']['home']['legacy_feature_id'],
            'Home replaces no legacy row — the old drawer had none'
        );
    }

    #[Test]
    public function every_switch_a_menu_entry_names_is_a_real_module(): void
    {
        foreach (AppMenu::registry()['items'] as $key => $item) {
            foreach ($item['any_of'] ?? [] as $moduleKey) {
                $this->assertContains(
                    $moduleKey,
                    Masjid::MODULE_KEYS,
                    "{$key} is gated on {$moduleKey}, which is not a module key"
                );
            }

            foreach ($item['parts'] ?? [] as $part) {
                $this->assertContains(
                    $part,
                    Masjid::MODULE_KEYS,
                    "{$key} declares the part {$part}, which is not a module key"
                );
            }

            $this->assertTrue(
                ($item['always'] ?? false) === true || ! empty($item['any_of']),
                "{$key} names no switch and is not always on"
            );
        }
    }

    #[Test]
    public function every_item_sits_in_exactly_one_section(): void
    {
        $registry = AppMenu::registry();
        $placed = $this->placedItemKeys($registry);

        $this->assertSame(
            count($placed),
            count(array_unique($placed)),
            'an item appears in more than one section'
        );

        $this->assertEqualsCanonicalizing(
            array_keys($registry['items']),
            $placed,
            'every item must be placed, and every placed key must be an item'
        );
    }

    #[Test]
    public function the_five_worship_switches_are_the_worship_section(): void
    {
        // The keys S1.1 added, in the order that fixes their legacy ids 1-5.
        $this->assertSame(
            ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'],
            AppMenu::registry()['sections']['worship']
        );

        $expected = ['quran' => 1, 'hadith' => 2, 'adhkar' => 3, 'qibla' => 4, 'tasbih' => 5];

        foreach ($expected as $key => $id) {
            $this->assertSame($id, AppMenu::registry()['items'][$key]['legacy_feature_id']);
            $this->assertSame([$key], AppMenu::registry()['items'][$key]['any_of']);
        }
    }

    #[Test]
    public function a_registry_the_config_cannot_vouch_for_falls_back_to_the_code_copy(): void
    {
        Log::spy();

        // What a stale config cache looks like: the file predates the Worship
        // keys, so five legacy ids are missing.
        $stale = AppMenu::DEFAULT_REGISTRY;
        unset($stale['sections']['worship']);
        foreach (['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'] as $key) {
            unset($stale['items'][$key]);
        }

        config(['app_menu' => $stale]);
        AppMenu::forgetRegistry();

        $this->assertSame(AppMenu::DEFAULT_REGISTRY, AppMenu::registry());

        Log::shouldHaveReceived('warning')
            ->with('app_menu registry rejected; using the code copy', \Mockery::type('array'));
    }

    #[Test]
    public function a_missing_config_file_falls_back_too(): void
    {
        config(['app_menu' => null]);
        AppMenu::forgetRegistry();

        $this->assertSame(AppMenu::DEFAULT_REGISTRY, AppMenu::registry());
    }

    #[Test]
    public function the_memo_never_outlives_the_config_that_produced_it(): void
    {
        // The registry is memoised per request. Keyed by anything other than
        // the config's own value, that memo would be a way for one deploy's
        // registry to answer another's — the exact failure it exists to stop.
        $this->assertSame(AppMenu::DEFAULT_REGISTRY, AppMenu::registry());

        $edited = AppMenu::DEFAULT_REGISTRY;
        $edited['tabs'] = ['home', 'donate'];

        config(['app_menu' => $edited]);

        $this->assertSame(['home', 'donate'], AppMenu::registry()['tabs']);
    }

    /** @return array<int, string> */
    private function placedItemKeys(array $registry): array
    {
        $placed = [];

        foreach ($registry['sections'] as $keys) {
            foreach ($keys as $key) {
                $placed[] = $key;
            }
        }

        return $placed;
    }
}
