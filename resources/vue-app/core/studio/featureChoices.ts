/**
 * The pure half of Studio's feature step (components/super/studio/steps/
 * StudioFeatureStep.vue, and the store's syncFeatureChoices, which keeps the
 * draft's map in step with its organisation type and platforms): what each
 * served switch is set to, and how the served groups are laid out.
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
 * What a switch starts on when nobody has moved it: what a new organisation of
 * this type is born with (`default_at_creation`), or on when one of its
 * `preselect_with` platforms is selected.
 */
export function startingValue(entry: StudioCatalogueEntry, platforms: readonly string[]): boolean {
    return entry.default_at_creation || preselectMatches(entry, platforms).length > 0;
}

/**
 * The full map of served keys the draft stores (R9: `answers.features.
 * capabilities`), which S8's writer reads.
 *
 *  - A key with a stored boolean keeps it. carryChoices() has already moved
 *    every switch the operator never touched onto the platforms chosen now, so
 *    what is left stored is what this draft holds for them.
 *  - A key with no stored value starts on its startingValue(); the operator can
 *    still turn it off.
 *  - A stored key the catalogue no longer serves (hidden from this type) is
 *    dropped, because the writer would ignore it and the map would stop
 *    describing the draft.
 */
export function fullChoiceMap(
    catalogue: StudioCatalogue,
    stored: Record<string, unknown> | null | undefined,
    platforms: readonly string[],
): Record<string, boolean> {
    const out: Record<string, boolean> = {};

    for (const entry of servedEntries(catalogue)) {
        const chosen = stored?.[entry.key];
        out[entry.key] = typeof chosen === 'boolean' ? chosen : startingValue(entry, platforms);
    }

    return out;
}

/** The organisation type and platforms a feature map was set for. */
export type ChoiceContext = { orgType: string | null; platforms: readonly string[] };

/**
 * The draft's feature map, carried from the context it was last set in
 * (`before`) to the organisation type and platforms chosen now.
 *
 * The map holds every served key (R9), so a switch the operator never touched
 * is stored exactly like one they set. They are told apart by where they sit:
 *
 *  - Another organisation type: nothing carries over. Those switches were set
 *    for a different kind of organisation (a masjid's worship modules are not a
 *    school's), so the map is emptied and, once `now`'s catalogue is here,
 *    starts again from that type's starting values.
 *  - Other platforms: a switch still on the starting value `before` gave it was
 *    never moved, so it follows `now` (Web added turns on what is suggested
 *    with Web; Web removed turns it back off). A switch the operator moved away
 *    from its starting value keeps their choice.
 *
 * `catalogue` is `now.orgType`'s; any other (the previous type's, still
 * loaded) or none counts as not here yet. Without it the platforms cannot be
 * followed, so the map is kept as it is and `settled` is false: the caller keeps
 * `before` as the map's context until the catalogue arrives. `settled` is true
 * whenever the result describes `now`, which the caller then records.
 */
export function carryChoices(
    catalogue: StudioCatalogue | null,
    stored: Record<string, unknown> | null | undefined,
    before: ChoiceContext,
    now: ChoiceContext,
): { choices: Record<string, unknown> | null; settled: boolean } {
    const kept = before.orgType === now.orgType && stored && Object.keys(stored).length ? stored : null;

    if (!catalogue || catalogue.org_type !== now.orgType) {
        return { choices: kept, settled: kept === null || samePlatforms(before.platforms, now.platforms) };
    }

    const followed: Record<string, unknown> = { ...(kept ?? {}) };
    for (const entry of servedEntries(catalogue)) {
        const value = followed[entry.key];
        if (typeof value === 'boolean' && value === startingValue(entry, before.platforms)) {
            followed[entry.key] = startingValue(entry, now.platforms);
        }
    }

    return { choices: fullChoiceMap(catalogue, followed, now.platforms), settled: true };
}

/** Whether two platform lists hold the same platforms, in any order. */
export function samePlatforms(a: readonly string[], b: readonly string[]): boolean {
    return a.length === b.length && a.every((platform) => b.includes(platform));
}

/** Whether two choice maps hold the same keys with the same values. */
export function sameChoices(a: Record<string, unknown> | null | undefined, b: Record<string, unknown> | null | undefined): boolean {
    const left = a ?? {};
    const right = b ?? {};
    const keys = Object.keys(right);
    return Object.keys(left).length === keys.length && keys.every((key) => left[key] === right[key]);
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
