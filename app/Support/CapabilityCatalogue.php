<?php

namespace App\Support;

use App\Models\Masjid;

/**
 * What Manara Studio's feature step offers a NEW organisation of one type, read
 * from config/capabilities.php on every call.
 *
 * The switch panel (MasjidsController::capabilities) answers "what does this
 * organisation have"; this answers "what would one be born with, and what may
 * it be given". It exists so the Studio SPA never holds its own copy of the
 * keys, labels or defaults: a new config entry reaches Studio with no code
 * change, and a key the SPA has never heard of is still rendered.
 *
 * Every rule here reads the same data the gates read (the entry's `defaults`,
 * Masjid::MODULE_DEFAULTS, the column-backed grants' `provision_default`), so
 * what it says a new organisation is born with is what provisioning gives.
 */
final class CapabilityCatalogue
{
    /** A school feature a non-school must never be offered (D14). Not served. */
    public const HIDDEN = 'hidden';

    /** A module this org type is not offered by default; Studio shows it collapsed. */
    public const NOT_OFFERED = 'not_offered';

    public const DEFAULT = 'default';

    public const OPTIONAL = 'optional';

    /**
     * The served groups for one org type, in config/capability_groups.php order.
     *
     * A group the entries name but that file does not (a stale config cache) is
     * appended and labelled by its key, as the switch panel does, so an entry is
     * never dropped for want of a card to sit on.
     *
     * @return list<array{key: string, label: string, entries: list<array<string, mixed>>}>
     */
    public static function forOrgType(string $orgType): array
    {
        $groupLabels = config('capability_groups', []);
        $entries = [];

        foreach (config('capabilities', []) as $key => $definition) {
            if (! is_array($definition) || self::visibility($key, $definition, $orgType) === self::HIDDEN) {
                continue;
            }

            // The switch panel's fallback group, so the two place an entry alike.
            $entries[$definition['group'] ?? 'tools'][] = self::entry($key, $definition, $orgType);
        }

        $groups = [];

        foreach (array_unique(array_merge(array_keys($groupLabels), array_keys($entries))) as $groupKey) {
            if (empty($entries[$groupKey])) {
                continue;
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $groupLabels[$groupKey] ?? $groupKey,
                'entries' => $entries[$groupKey],
            ];
        }

        return $groups;
    }

    /**
     * One served entry.
     *
     * `default_for_org_type`, `offered_by_default`, `writer`, `where` and
     * `surface` are computed exactly as the switch panel computes them, so the
     * two screens cannot describe one key differently.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    public static function entry(string $key, array $def, string $orgType): array
    {
        $column = ! empty($def['column']) ? $def['column'] : null;
        $isModule = ($def['kind'] ?? null) === 'module';
        $description = is_string($def['description'] ?? null) ? $def['description'] : '';

        return [
            'key' => $key,
            'kind' => $isModule ? 'module' : 'grant',
            'label' => $def['label'] ?? $key,
            'description' => $description,
            'turns_on' => is_string($def['turns_on'] ?? null) && trim($def['turns_on']) !== ''
                ? $def['turns_on']
                : $description,
            'writer' => $column ? $key : 'capability',
            'default_for_org_type' => $column ? null : (bool) ($def['defaults'][$orgType] ?? false),
            'offered_by_default' => $isModule
                ? (bool) (Masjid::MODULE_DEFAULTS[$key][$orgType] ?? true)
                : ! $column && (bool) ($def['defaults'][$orgType] ?? false),
            'default_at_creation' => self::defaultAtCreation($key, $orgType),
            'visibility' => self::visibility($key, $def, $orgType),
            'preselect_with' => array_values(array_filter(
                is_array($def['studio_preselect_with'] ?? null) ? $def['studio_preselect_with'] : [],
                fn ($platform) => is_string($platform) && $platform !== ''
            )),
            'where' => self::nonEmptyString($def['where'] ?? null),
            'surface' => self::nonEmptyString($def['surface'] ?? null),
            'app' => self::appPlacement($key),
        ];
    }

    /**
     * How Studio shows an entry to one org type. The first rule that matches wins.
     *
     *  1. A `school` group entry for a non-school is hidden and never served
     *     (D14). The four school grants default off for every type, school
     *     included, so the group is the only data that marks them.
     *  2. A module this type is not offered (MODULE_DEFAULTS false) is
     *     `not_offered`: still servable, since a SuperAdmin can switch one on.
     *  3. Anything a new organisation is born with is `default`.
     *  4. The rest is `optional`.
     *
     * @param  array<string, mixed>  $def
     */
    public static function visibility(string $key, array $def, string $orgType): string
    {
        if (($def['group'] ?? null) === 'school' && $orgType !== Masjid::ORG_TYPE_SCHOOL) {
            return self::HIDDEN;
        }

        if (($def['kind'] ?? null) === 'module' && (Masjid::MODULE_DEFAULTS[$key][$orgType] ?? null) === false) {
            return self::NOT_OFFERED;
        }

        return self::defaultAtCreation($key, $orgType) ? self::DEFAULT : self::OPTIONAL;
    }

    /**
     * What a newly provisioned organisation of this type has, before anyone
     * decides otherwise.
     *
     * A column-backed grant is born with its `provision_default`; without one,
     * CRM is on (OnboardingController::provision writes it) and anything else is
     * at its column default, off. Everything else is the org type's catalogue
     * default, then the code copy a stale config cache would be read through.
     * An unknown key is never had.
     */
    public static function defaultAtCreation(string $key, string $orgType): bool
    {
        $def = config("capabilities.{$key}");

        if (! is_array($def)) {
            return false;
        }

        if (! empty($def['column'])) {
            return (bool) ($def['provision_default'] ?? ($key === 'crm'));
        }

        return (bool) ($def['defaults'][$orgType] ?? Masjid::MODULE_DEFAULTS[$key][$orgType] ?? false);
    }

    /**
     * The full desired map for a new organisation: every key served to this org
     * type, its chosen value where a boolean was chosen, otherwise its default
     * at creation. A hidden or unknown key in $choices is ignored; refusing one
     * is the request's job.
     *
     * @param  array<string, mixed>  $choices
     * @return array<string, bool>
     */
    public static function resolve(string $orgType, array $choices): array
    {
        $out = [];

        foreach (config('capabilities', []) as $key => $definition) {
            if (! is_array($definition) || self::visibility($key, $definition, $orgType) === self::HIDDEN) {
                continue;
            }

            $out[$key] = is_bool($choices[$key] ?? null)
                ? $choices[$key]
                : self::defaultAtCreation($key, $orgType);
        }

        return $out;
    }

    /**
     * Which mobile app menu entries this switch can show, in menu order, and
     * whether any of them can sit on the tab bar. Read from the validated
     * AppMenu registry, so Studio's app chip is the menu's own derivation.
     *
     * @return array{items: list<string>, tab: bool}
     */
    public static function appPlacement(string $key): array
    {
        $registry = AppMenu::registry();
        $items = [];

        foreach ($registry['sections'] as $itemKeys) {
            foreach ($itemKeys as $itemKey) {
                if (in_array($key, $registry['items'][$itemKey]['any_of'] ?? [], true)) {
                    $items[] = $itemKey;
                }
            }
        }

        return [
            'items' => $items,
            'tab' => array_intersect($items, $registry['tabs']) !== [],
        ];
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
