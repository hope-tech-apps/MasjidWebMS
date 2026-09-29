import { defineStore } from "pinia";
import { computed, ref } from "vue";
import { isAxiosError } from "axios";
import ApiService from "@/core/services/ApiService";
import { serverMessage } from "@/core/helpers/serverMessage";
import { isThemeHex } from "@/core/studio/foundationGate";
import { PREVIEW_DEBOUNCE_MS } from "@/stores/super/studioDraftStore";
import { StudioColourKey, StudioPreview } from "@/core/types/data/Studio";
import {
    StudioBrandAssets,
    StudioCapabilitiesOutcome,
    StudioOrganisationFeatureEntry,
    StudioOrganisationSnapshot,
} from "@/core/types/data/StudioOrganisation";

type Outcome<T> = { ok: true; data: T } | { ok: false; message: string };

/** The theme's four colours, in the Brand panel's order. */
export const STUDIO_COLOUR_KEYS: StudioColourKey[] = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

function statusOf(error: unknown): number | undefined {
    return isAxiosError(error) ? error.response?.status : undefined;
}

/** Two colours are the same whatever their case (the theme screen may have stored upper case). */
function sameColour(a: string | null | undefined, b: string | null | undefined): boolean {
    return (a ?? '').toLowerCase() === (b ?? '').toLowerCase();
}

/**
 * Studio with an organisation that already exists open in it
 * (docs/manara-studio-w2.md S9, R14). There is no draft and no autosave: the
 * snapshot is read-only, and a change is PENDING in this tab until a Save
 * sends it through a writer that already exists.
 *
 * THE SNAPSHOT
 *  fetchSnapshot() reads GET /studio/organisations/{id}. Opening another
 *  organisation bumps `generation` and drops everything pending, so an answer
 *  still arriving for the previous one can never land here; re-reading the same
 *  one (after a save) keeps what is still pending.
 *
 * PENDING CHANGES
 *  `pendingCapabilities` and `pendingColours` hold only what differs from the
 *  live values: setting a switch or a colour back to what the organisation has
 *  removes it. Nothing is written until the matching Save.
 *
 * THE PREVIEW
 *  refreshPreview() posts the pending changes to /studio/organisations/{id}/
 *  preview PREVIEW_DEBOUNCE_MS after the last edit (the draft store's delay).
 *  The server applies them to an in-memory copy and answers the W1 preview
 *  shape; it writes nothing. A slower answer to an older request is dropped.
 *  The four colours go only when all four are #RRGGBB, because the server
 *  takes the brand whole or not at all.
 *
 * THE WRITERS (each returns an Outcome and re-reads the snapshot on success)
 *  - saveFeatures(): S7's bulk PATCH, form-encoded, `capabilities[<key>]=1|0`,
 *    booleans as '1'/'0' (.claude/rules/shipping.md). Only keys whose writer is
 *    `capability` and whose value moves: every key sent writes a ledger row,
 *    and a column-backed key makes the whole request a 422 (R7). A refusal
 *    keeps the pending changes.
 *  - saveColours(): the theme screen's own save with the four colour fields
 *    and nothing else, so the stored design tokens survive.
 *  - regenerateBrandAssets(): S8's favicon, touch icon and share image.
 */
export const useStudioOrganisationStore = defineStore("studioOrganisationStore", () => {
    const snapshot = ref<StudioOrganisationSnapshot | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const preview = ref<StudioPreview | null>(null);
    /** An edit is waiting out the debounce: `preview` does not describe it yet. */
    const previewQueued = ref(false);
    const previewLoading = ref(false);
    const previewError = ref<string | null>(null);

    /** Catalogue key => the value this tab wants, only where it differs from the live value. */
    const pendingCapabilities = ref<Record<string, boolean>>({});
    /** Colour => the value this tab wants, only where it differs from the saved theme. */
    const pendingColours = ref<Partial<Record<StudioColourKey, string | null>>>({});

    const savingFeatures = ref(false);
    const savingColours = ref(false);
    const regenerating = ref(false);

    let generation = 0;
    let previewTimer: ReturnType<typeof setTimeout> | null = null;
    let previewSeq = 0;

    /** Every served feature entry, in the order served. */
    const featureEntries = computed<StudioOrganisationFeatureEntry[]>(() =>
        (snapshot.value?.sections.features.data ?? []).flatMap((group) => group.entries));

    /** The theme's colours as saved; null where the organisation has none. */
    const savedColours = computed<Record<StudioColourKey, string | null>>(() => {
        const stored = snapshot.value?.sections.brand.data.colours ?? null;
        return {
            primary_color: stored?.primary_color ?? null,
            secondary_color: stored?.secondary_color ?? null,
            accent_color: stored?.accent_color ?? null,
            background_color: stored?.background_color ?? null,
        };
    });

    /** The colours as this tab shows them: pending where edited, saved otherwise. */
    const colours = computed<Record<StudioColourKey, string | null>>(() => {
        const out = { ...savedColours.value };
        for (const key of STUDIO_COLOUR_KEYS) {
            if (key in pendingColours.value) out[key] = pendingColours.value[key] ?? null;
        }
        return out;
    });

    /** The colours that differ from the saved theme. */
    const changedColours = computed<StudioColourKey[]>(() =>
        STUDIO_COLOUR_KEYS.filter((key) => !sameColour(colours.value[key], savedColours.value[key])));

    /** All four are a colour the theme save takes (#RGB, #RRGGBB or #RRGGBBAA), as stored or as typed. */
    const coloursComplete = computed(() => STUDIO_COLOUR_KEYS.every((key) => isThemeHex(colours.value[key] ?? '')));

    /**
     * The switches that would move: capability-writer entries whose pending
     * value differs from the live one. What the preview is sent, and the most
     * a save may send.
     */
    const capabilityChanges = computed<Record<string, boolean>>(() => {
        const out: Record<string, boolean> = {};
        for (const entry of featureEntries.value) {
            const pending = pendingCapabilities.value[entry.key];
            if (entry.writer === 'capability' && typeof pending === 'boolean' && pending !== entry.enabled) {
                out[entry.key] = pending;
            }
        }
        return out;
    });

    /** Whether leaving would drop changes nobody has saved. */
    function hasUnsavedChanges(): boolean {
        return Object.keys(capabilityChanges.value).length > 0 || changedColours.value.length > 0;
    }

    function clearPreviewTimer() {
        if (previewTimer) clearTimeout(previewTimer);
        previewTimer = null;
        previewQueued.value = false;
    }

    /** Forget the open organisation. Anything still arriving for it is ignored. */
    function reset() {
        generation++;
        clearPreviewTimer();
        snapshot.value = null;
        loading.value = false;
        error.value = null;
        preview.value = null;
        previewLoading.value = false;
        previewError.value = null;
        pendingCapabilities.value = {};
        pendingColours.value = {};
        savingFeatures.value = false;
        savingColours.value = false;
        regenerating.value = false;
    }

    /**
     * Read the organisation. Another organisation than the one held starts
     * clean; the same one is re-read with its pending changes kept, minus any
     * the organisation now already has.
     */
    async function fetchSnapshot(id: number): Promise<boolean> {
        if (snapshot.value?.org.id !== id) reset();

        const gen = generation;
        loading.value = true;
        error.value = null;

        try {
            const res = await ApiService.get(`/api/admin/studio/organisations/${id}`);
            if (gen !== generation) return false;
            snapshot.value = res.data.data as StudioOrganisationSnapshot;
            prunePending();
            refreshPreview();
            return true;
        } catch (failure) {
            if (gen !== generation) return false;
            error.value = statusOf(failure) === 404
                ? 'This organisation does not exist.'
                : serverMessage(failure, 'The organisation could not be loaded.');
            return false;
        } finally {
            if (gen === generation) loading.value = false;
        }
    }

    /** Drop pending values the organisation now has, and switches it no longer serves. */
    function prunePending() {
        const capabilities: Record<string, boolean> = {};
        for (const entry of featureEntries.value) {
            const pending = pendingCapabilities.value[entry.key];
            if (entry.writer === 'capability' && typeof pending === 'boolean' && pending !== entry.enabled) {
                capabilities[entry.key] = pending;
            }
        }
        pendingCapabilities.value = capabilities;

        const edited: Partial<Record<StudioColourKey, string | null>> = {};
        for (const key of STUDIO_COLOUR_KEYS) {
            if (key in pendingColours.value && !sameColour(pendingColours.value[key], savedColours.value[key])) {
                edited[key] = pendingColours.value[key] ?? null;
            }
        }
        pendingColours.value = edited;
    }

    /** Set one switch for the preview. Only a capability-writer entry can be set here. */
    function setCapability(key: string, on: boolean) {
        const entry = featureEntries.value.find((candidate) => candidate.key === key);
        if (!entry || entry.writer !== 'capability') return;

        const next = { ...pendingCapabilities.value };
        if (on === entry.enabled) delete next[key];
        else next[key] = on;
        pendingCapabilities.value = next;
        refreshPreview();
    }

    function discardCapabilities() {
        pendingCapabilities.value = {};
        refreshPreview();
    }

    /** Set one colour for the preview; null while its field is empty. */
    function setColour(key: StudioColourKey, value: string | null) {
        const next = { ...pendingColours.value };
        if (sameColour(value, savedColours.value[key])) delete next[key];
        else next[key] = value;
        pendingColours.value = next;
        refreshPreview();
    }

    function discardColours() {
        pendingColours.value = {};
        refreshPreview();
    }

    /** Everything pending is dropped (leaving the screen on purpose). */
    function discardAll() {
        pendingCapabilities.value = {};
        pendingColours.value = {};
        refreshPreview();
    }

    /** The preview's body: the pending switches, and the four colours once they are complete and changed. */
    function previewBody(): URLSearchParams {
        const body = new URLSearchParams();

        if (changedColours.value.length && coloursComplete.value) {
            for (const key of STUDIO_COLOUR_KEYS) {
                body.append(`brand[${key}]`, colours.value[key] as string);
            }
        }

        for (const [key, on] of Object.entries(capabilityChanges.value)) {
            body.append(`capabilities[${key}]`, on ? '1' : '0');
        }

        return body;
    }

    /** Re-derive the mockups from the live organisation plus this tab's pending changes. */
    function refreshPreview() {
        if (!snapshot.value) return;
        clearPreviewTimer();
        previewQueued.value = true;
        previewTimer = setTimeout(() => {
            previewTimer = null;
            previewQueued.value = false;
            void runPreview();
        }, PREVIEW_DEBOUNCE_MS);
    }

    async function runPreview(): Promise<void> {
        const id = snapshot.value?.org.id;
        if (!id) return;

        const gen = generation;
        const seq = ++previewSeq;
        previewLoading.value = true;

        try {
            const res = await ApiService.post(`/api/admin/studio/organisations/${id}/preview`, previewBody());
            if (gen !== generation || seq !== previewSeq) return;
            preview.value = res.data.data as StudioPreview;
            previewError.value = null;
        } catch (failure) {
            if (gen !== generation || seq !== previewSeq) return;
            previewError.value = serverMessage(failure, 'The preview could not be updated.');
        } finally {
            if (gen === generation && seq === previewSeq) previewLoading.value = false;
        }
    }

    /**
     * Apply feature changes to the live organisation (S7's bulk PATCH).
     * `changes` is re-checked against the snapshot: a key is sent only when its
     * writer is `capability` and its value moves.
     */
    async function saveFeatures(changes: Record<string, boolean>): Promise<Outcome<StudioCapabilitiesOutcome>> {
        const current = snapshot.value;
        if (!current) return { ok: false, message: 'No organisation is open.' };
        if (savingFeatures.value) return { ok: false, message: 'A save is already in progress.' };

        const body = new URLSearchParams();
        for (const entry of featureEntries.value) {
            const on = changes[entry.key];
            if (entry.writer !== 'capability' || typeof on !== 'boolean' || on === entry.enabled) continue;
            body.append(`capabilities[${entry.key}]`, on ? '1' : '0');
        }
        if (body.toString() === '') return { ok: false, message: 'Nothing has changed.' };

        const gen = generation;
        savingFeatures.value = true;

        try {
            const res = await ApiService.patch(`/api/admin/masjids/${current.org.id}/capabilities`, body);
            const meta = res.data?.meta as StudioCapabilitiesOutcome | undefined;
            if (gen === generation) {
                pendingCapabilities.value = {};
                await fetchSnapshot(current.org.id);
            }
            return { ok: true, data: { changed: meta?.changed ?? [], unchanged: meta?.unchanged ?? [] } };
        } catch (failure) {
            // A refusal (Giving off while a monthly gift can bill, say) wrote
            // nothing for any key; the pending changes stay for another try.
            return { ok: false, message: serverMessage(failure, 'The features could not be saved.') };
        } finally {
            if (gen === generation) savingFeatures.value = false;
        }
    }

    /**
     * Save the four colours through the theme screen's own endpoint, which
     * purges the renderer. Only the four fields: `tokens` is never sent, so the
     * stored design tokens survive.
     */
    async function saveColours(values: Record<StudioColourKey, string | null>): Promise<Outcome<null>> {
        const current = snapshot.value;
        if (!current) return { ok: false, message: 'No organisation is open.' };
        if (!STUDIO_COLOUR_KEYS.every((key) => isThemeHex(values[key] ?? ''))) {
            return { ok: false, message: 'All four colours need a value like #0A3D62.' };
        }

        const body = new URLSearchParams();
        for (const key of STUDIO_COLOUR_KEYS) {
            body.append(key, values[key] as string);
        }

        const gen = generation;
        savingColours.value = true;

        try {
            await ApiService.post(`/api/admin/masjids/${current.org.id}/theme`, body);
            if (gen === generation) {
                pendingColours.value = {};
                await fetchSnapshot(current.org.id);
            }
            return { ok: true, data: null };
        } catch (failure) {
            return { ok: false, message: serverMessage(failure, 'The colours could not be saved.') };
        } finally {
            if (gen === generation) savingColours.value = false;
        }
    }

    /**
     * Rebuild the favicon, touch icon and share image from the current logo
     * (S8). Without `background`, the server takes the saved theme's.
     */
    async function regenerateBrandAssets(background?: string): Promise<Outcome<StudioBrandAssets>> {
        const current = snapshot.value;
        if (!current) return { ok: false, message: 'No organisation is open.' };

        const body = new URLSearchParams();
        if (background) body.append('background_color', background);

        const gen = generation;
        regenerating.value = true;

        try {
            const res = await ApiService.post(`/api/admin/masjids/${current.org.id}/brand-assets/regenerate`, body);
            if (gen === generation) await fetchSnapshot(current.org.id);
            return { ok: true, data: res.data.data as StudioBrandAssets };
        } catch (failure) {
            return { ok: false, message: serverMessage(failure, 'The images could not be regenerated.') };
        } finally {
            if (gen === generation) regenerating.value = false;
        }
    }

    return {
        snapshot, loading, error, fetchSnapshot, reset,
        featureEntries, pendingCapabilities, capabilityChanges, setCapability, discardCapabilities,
        savedColours, pendingColours, colours, changedColours, coloursComplete, setColour, discardColours,
        hasUnsavedChanges, discardAll,
        preview, previewQueued, previewLoading, previewError, refreshPreview,
        savingFeatures, saveFeatures,
        savingColours, saveColours,
        regenerating, regenerateBrandAssets,
    };
});
