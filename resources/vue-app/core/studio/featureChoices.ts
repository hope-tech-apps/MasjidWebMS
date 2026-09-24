/**
 * The pure half of Studio's feature step (components/super/studio/steps/
 * StudioFeatureStep.vue): what each served switch is set to, and how the
 * served groups are laid out.
 *
 * Everything is read from the catalogue the server sends (GET
 * /api/admin/studio/catalogue, App\Support\CapabilityCatalogue). Nothing here
 * names a key, a label or a group, so a new config entry reaches the step with
 * no code change and Studio never becomes one more copy of the feature list
 * (docs/manara-studio.md, landmine 3). There is no fallback list: without a
 * catalogue there are no choices.
 *
 * Only `import type`, so tests/studio-feature-choices.test.ts runs it under node.
 */
import type { StudioCatalogue, StudioCatalogueEntry } from "@/core/types/data/Studio";

/** `CapabilityCatalogue::NOT_OFFERED`: served, but shown collapsed. */
export const NOT_OFFERED = 'not_offered';

/** Every served entry, in the order served. */
export function servedEntries(catalogue: StudioCatalogue): StudioCatalogueEntry[] {
    return catalogue.groups.flatMap((group) => group.entries);
}

/**
 * The selected platforms an entry is preselected with (its `preselect_with`
 * that the draft has chosen), in the draft's order. Empty when none match.
 */
export function preselectMatches(entry: StudioCatalogueEntry, platforms: readonly string[]): string[] {
    return platforms.filter((platform) => entry.preselect_with.includes(platform));
}

/**
 * The full map of served keys the draft stores (R9: `answers.features.
 * capabilities`), which S8's writer reads.
 *
 *  - A key the operator already set keeps its value: the stored boolean is
 *    their choice, and switching a platform later never overrides it.
 *  - A key with no stored value starts on what a new organisation of this
 *    type is born with (`default_at_creation`), or on when one of its
 *    `preselect_with` platforms is selected; the operator can still turn it off.
 *  - A stored key the catalogue no longer serves (the organisation type
 *    changed, and it is hidden from the new one) is dropped, because the
 *    writer would ignore it and the map would stop describing the draft.
 */
export function fullChoiceMap(
    catalogue: StudioCatalogue,
    stored: Record<string, unknown> | null | undefined,
    platforms: readonly string[],
): Record<string, boolean> {
    const out: Record<string, boolean> = {};

    for (const entry of servedEntries(catalogue)) {
        const chosen = stored?.[entry.key];
        out[entry.key] = typeof chosen === 'boolean'
            ? chosen
            : entry.default_at_creation || preselectMatches(entry, platforms).length > 0;
    }

    return out;
}

/** Whether two choice maps hold the same keys with the same values. */
export function sameChoices(a: Record<string, unknown> | null | undefined, b: Record<string, boolean>): boolean {
    const left = a ?? {};
    const keys = Object.keys(b);
    return Object.keys(left).length === keys.length && keys.every((key) => left[key] === b[key]);
}

export type FeatureGroupView = {
    key: string;
    label: string;
    /** Rows shown open, in the order served. */
    offered: StudioCatalogueEntry[];
    /** `not_offered` rows, collapsed under "Not usually for a …". */
    notOffered: StudioCatalogueEntry[];
};

/** The served groups in the order served, each split into open and collapsed rows. */
export function featureGroups(catalogue: StudioCatalogue): FeatureGroupView[] {
    return catalogue.groups.map((group) => ({
        key: group.key,
        label: group.label,
        offered: group.entries.filter((entry) => entry.visibility !== NOT_OFFERED),
        notOffered: group.entries.filter((entry) => entry.visibility === NOT_OFFERED),
    }));
}
