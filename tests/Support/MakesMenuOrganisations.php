<?php

namespace Tests\Support;

use App\Models\Masjid;
use App\Models\ThemeSetting;
use App\Support\AppMenu;

/**
 * Fixtures for the four /menu suites.
 *
 * Two of the three things a menu test needs are NOT mass assignable, and
 * passing them to create() silently does nothing — which is how a test can
 * "prove" a switched-off module is hidden while having switched nothing off:
 *
 *   listed_at             publishing an organisation is its own act
 *   capability_overrides  its writer is MasjidsController::setCapability
 *
 * Both go through forceFill here, once, so no suite has to remember.
 */
trait MakesMenuOrganisations
{
    /** The snippet every suite in this repo uses; see tests/CLAUDE.md. */
    protected function useSqliteInMemory(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * An organisation. `listed_at`, `org_type` and `capability_overrides` are
     * accepted here and applied the way each one actually has to be.
     *
     * @param array<string, mixed> $overrides
     */
    protected function menuOrg(string $name, array $overrides = []): Masjid
    {
        $forced = [];

        foreach (['listed_at', 'capability_overrides'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $forced[$key] = $overrides[$key];
                unset($overrides[$key]);
            }
        }

        $org = Masjid::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));

        if ($forced !== []) {
            $org->forceFill($forced)->save();
        }

        return $org->fresh();
    }

    /** A published organisation — the only kind a parent offers as a profile. */
    protected function listedOrg(string $name, array $overrides = []): Masjid
    {
        return $this->menuOrg($name, $overrides + ['listed_at' => now()]);
    }

    /**
     * Switch modules OFF for one organisation, the way a SuperAdmin does.
     *
     * @param array<int, string> $keys
     */
    protected function switchOff(Masjid $org, array $keys): Masjid
    {
        return $this->setSwitches($org, array_fill_keys($keys, false));
    }

    /**
     * Switch modules ON for one organisation — the only way a school or a
     * community org reaches a masjid-default module.
     *
     * @param array<int, string> $keys
     */
    protected function switchOn(Masjid $org, array $keys): Masjid
    {
        return $this->setSwitches($org, array_fill_keys($keys, true));
    }

    /** @param array<string, bool> $decisions */
    protected function setSwitches(Masjid $org, array $decisions): Masjid
    {
        $overrides = $org->capability_overrides ?? [];

        $org->forceFill(['capability_overrides' => array_merge($overrides, $decisions)])->save();

        return $org->fresh();
    }

    /** Give an organisation a brand colour, so its profile carries a theme. */
    protected function brand(Masjid $org, ?string $primary): Masjid
    {
        ThemeSetting::create([
            'masjid_id' => $org->id,
            'primary_color' => $primary,
        ]);

        return $org->fresh();
    }

    /** @return array<int, string> the item keys one profile lists, in order */
    protected function itemKeys(array $profile): array
    {
        $keys = [];

        foreach ($profile['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /** @return array<int, string> the section keys one profile lists, in order */
    protected function sectionKeys(array $profile): array
    {
        return array_column($profile['sections'], 'key');
    }

    /** The profile for one organisation id out of a decoded /menu body. */
    protected function profileFor(array $data, int $id): ?array
    {
        foreach ($data['profiles'] as $profile) {
            if ($profile['id'] === $id) {
                return $profile;
            }
        }

        return null;
    }

    protected function menu(int $masjidId, array $headers = [], string $query = '')
    {
        return $this->getJson("/api/mobile/masjids/{$masjidId}/menu{$query}", $headers);
    }

    /** The registry memo must not carry one test's config into the next. */
    protected function forgetMenuRegistry(): void
    {
        AppMenu::forgetRegistry();
    }
}
