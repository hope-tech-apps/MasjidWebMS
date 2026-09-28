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
import type { StudioCapabilitiesApplied, StudioCatalogue, StudioCatalogueEntry } from "@/core/types/data/Studio";

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

/** The platforms, in the order given, that preselect a switch left where they put it. */
function addSuggesters(into: string[], entry: StudioCatalogueEntry, platforms: readonly string[]): void {
    for (const platform of preselectMatches(entry, platforms)) {
        if (!into.includes(platform)) into.push(platform);
    }
}

/** Every served entry by key. */
function entriesByKey(catalogue: StudioCatalogue): Map<string, StudioCatalogueEntry> {
    return new Map(servedEntries(catalogue).map((entry) => [entry.key, entry]));
}

/**
 * Step 1's map counted for Step 3's review.
 *
 *  - `changed`: switches the operator moved away from where Studio put them,
 *    which is startingValue() for this type AND these platforms. Only these
 *    are edits.
 *  - `suggested`: switches still where a chosen platform's `preselect_with`
 *    put them, which a new organisation of this type would not have: Web
 *    ticks website pages. Nobody moved them, so they are not called changed.
 *    `suggestedWith` names those platforms, in the draft's order.
 *
 * Both are null while the catalogue is not loaded. The server's own report
 * (`capabilities_applied.changed`) is a different count, against
 * `default_at_creation` alone; splitApplied() reads that one.
 */
export type FeatureCounts = {
    on: number;
    total: number;
    suggested: number | null;
    suggestedWith: string[];
    changed: number | null;
};

export function featureCounts(
    map: Record<string, boolean> | null | undefined,
    catalogue: StudioCatalogue | null,
    platforms: readonly string[],
): FeatureCounts {
    const choices = Object.entries(map ?? {});
    const counts: FeatureCounts = {
        on: choices.filter(([, on]) => on).length,
        total: choices.length,
        suggested: null,
        suggestedWith: [],
        changed: null,
    };
    if (!catalogue) return counts;

    const entries = entriesByKey(catalogue);
    let suggested = 0;
    let changed = 0;
    for (const [key, on] of choices) {
        const entry = entries.get(key);
        if (!entry) continue;
        const start = startingValue(entry, platforms);
        if (on !== start) {
            changed++;
        } else if (start !== entry.default_at_creation) {
            suggested++;
            addSuggesters(counts.suggestedWith, entry, platforms);
        }
    }

    return { ...counts, suggested, changed };
}

/**
 * The review's Features line: "11 of 33 on, 1 suggested with Web, 0 changed
 * by you". The suggested part is left out when there are none; without the
 * catalogue only the first part can be said. `nameOf` names a platform
 * (platforms.ts platformLabel); it is passed in so this module stays runnable
 * under node.
 */
export function featureCountsText(counts: FeatureCounts, nameOf: (platform: string) => string = (platform) => platform): string {
    if (!counts.total) return '';
    const parts = [`${counts.on} of ${counts.total} on`];
    if (counts.suggested !== null && counts.changed !== null) {
        if (counts.suggested > 0) parts.push(`${counts.suggested} suggested with ${counts.suggestedWith.map(nameOf).join(', ')}`);
        parts.push(`${counts.changed} changed by you`);
    }
    return parts.join(', ');
}

/** The server's `capabilities_applied.changed`, told apart: preselections left alone, and the operator's own changes. */
export type AppliedSplit = {
    /** Each with the platforms that preselect it (`with`), in the order given. */
    suggested: (StudioCapabilitiesApplied['changed'][number] & { with: string[] })[];
    suggestedWith: string[];
    byYou: StudioCapabilitiesApplied['changed'];
};

/**
 * Splits what the server reports as differing from a new organisation's
 * defaults (CapabilityWriter::applyAtCreation, against `default_at_creation`
 * alone) into the switches still where a platform's preselection put them and
 * the ones the operator changed, which includes a preselection turned back
 * off (listed by the server as unchanged). `platforms` must be the ones the
 * organisation was CREATED with, from the server's answer (`app_publishing.
 * enabled_platforms`), never the draft's answers as they stand now.
 *
 * Null when it cannot be told: no catalogue, no platforms reported, or a key
 * the catalogue does not describe. The caller then says only that they differ
 * from the defaults, which is what the server said.
 */
export function splitApplied(
    applied: StudioCapabilitiesApplied,
    catalogue: StudioCatalogue | null,
    platforms: readonly string[] | null | undefined,
): AppliedSplit | null {
    if (!catalogue || !Array.isArray(platforms)) return null;

    const entries = entriesByKey(catalogue);
    const split: AppliedSplit = { suggested: [], suggestedWith: [], byYou: [] };
    for (const change of applied.changed) {
        const entry = entries.get(change.key);
        if (!entry) return null;
        if (change.enabled === startingValue(entry, platforms) && change.enabled !== entry.default_at_creation) {
            split.suggested.push({ ...change, with: preselectMatches(entry, platforms) });
            addSuggesters(split.suggestedWith, entry, platforms);
        } else {
            split.byYou.push(change);
        }
    }

    // A preselected switch the operator turned off is back at its default, so
    // the server files it under `unchanged`; it is still the operator's change.
    for (const key of applied.unchanged) {
        const entry = entries.get(key);
        if (entry && startingValue(entry, platforms) && !entry.default_at_creation) {
            split.byYou.push({ key, enabled: false });
        }
    }

    return split;
}

/**
 * The results' Features line, from the server's report. With a split: "1
 * suggested with Web, 0 changed by you, 32 at a new organisation's
 * defaults". Without one, only what the server's list means: "1 differs from
 * a new organisation's defaults, 32 match them".
 */
export function appliedText(
    applied: StudioCapabilitiesApplied,
    split: AppliedSplit | null,
    nameOf: (platform: string) => string = (platform) => platform,
): string {
    if (!split) {
        const unchanged = applied.unchanged.length;
        const differ = applied.changed.length;
        return `Features: ${differ} ${differ === 1 ? 'differs' : 'differ'} from a new organisation's defaults, ${unchanged} ${unchanged === 1 ? 'matches' : 'match'} them`;
    }

    // Switches the operator turned back off are in byYou but also in the server's unchanged list.
    const changedKeys = new Set(split.byYou.map((change) => change.key));
    const unchanged = applied.unchanged.filter((key) => !changedKeys.has(key)).length;
    const parts: string[] = [];
    if (split.suggested.length) parts.push(`${split.suggested.length} suggested with ${split.suggestedWith.map(nameOf).join(', ')}`);
    parts.push(`${split.byYou.length} changed by you`);
    parts.push(`${unchanged} at a new organisation's defaults`);
    return `Features: ${parts.join(', ')}`;
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
