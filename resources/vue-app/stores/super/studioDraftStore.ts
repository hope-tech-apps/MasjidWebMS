import { defineStore } from "pinia";
import { computed, reactive, ref, shallowRef, watch } from "vue";
import { AxiosError, AxiosResponse, isAxiosError } from "axios";
import ApiService from "@/core/services/ApiService";
import { getMessageFromObj } from "@/assets/ts/swalMethods";
import { BackendResponseData } from "@/core/types/config/AxiosCustom";
import { extractDominantColors } from "@/core/helpers/extractPalette";
import { LogoPreparationError, prepareLogo } from "@/core/helpers/prepareLogo";
import { createAutosave, SaveRequest } from "@/core/studio/autosave";
import { carryChoices, ChoiceContext, sameChoices } from "@/core/studio/featureChoices";
import { ProvisionOutcome, ProvisionSecrets, provisionBody, readProvisionOutcome } from "@/core/studio/provision";
import {
    autosaveBody,
    changedSections,
    emptyAnswers,
    fingerprints,
    normaliseAnswers,
    presetPreviewBody,
    previewBody,
    sectionFingerprint,
} from "@/core/studio/draftAnswers";
import {
    StudioAnswers,
    StudioCatalogue,
    StudioDomainCheck,
    StudioDomainCheckRequest,
    StudioDraft,
    StudioDraftRow,
    StudioLayoutPreset,
    StudioOptions,
    StudioPreview,
    StudioSaveState,
    StudioSectionKey,
    StudioStepKey,
} from "@/core/types/data/Studio";
import { OrgType } from "@/core/types/data/Vertical";

/** Quiet time before an edit is saved: long enough that typing a name is one save, not twelve. */
export const AUTOSAVE_DEBOUNCE_MS = 1500;

/** Quiet time before the mockups are re-derived: short, because the operator is watching them. */
export const PREVIEW_DEBOUNCE_MS = 400;

/** How many colours the Brand panel offers from a logo. The server keeps up to 16. */
const PALETTE_CANDIDATES = 6;

type Outcome<T> = { ok: true; data: T } | { ok: false; message: string };

/**
 * The sentence to show for a failed call. A 422 lists what is wrong under
 * `errors`, and a 413 is the upload limit, which getMessageFromObj words;
 * every other Studio refusal says it in `message`, and a 409 carries the draft
 * under `data`, which getMessageFromObj would flatten into the text.
 */
function messageOf(error: unknown, fallback: string): string {
    if (isAxiosError(error)) {
        const data = error.response?.data as { message?: unknown; errors?: unknown } | undefined;
        if (data?.errors || error.response?.status === 413) {
            return getMessageFromObj(error as AxiosError<BackendResponseData>) || fallback;
        }
        if (typeof data?.message === 'string' && data.message) return data.message;
        return error.response ? fallback : (error.message || fallback);
    }
    return error instanceof Error && error.message ? error.message : fallback;
}

function statusOf(error: unknown): number | undefined {
    return isAxiosError(error) ? error.response?.status : undefined;
}

/**
 * Manara Studio's working state: the list of drafts, and the one draft being
 * walked (docs/manara-studio-w1.md S5, R6).
 *
 * THE AUTOSAVE
 *  - The form edits `answers`, eight section objects. A deep watch notices any
 *    edit and calls patchSection() for the sections that now differ from what
 *    the server last confirmed; the PATCH goes AUTOSAVE_DEBOUNCE_MS after the
 *    last edit and carries each changed section whole, as a plain object (JSON:
 *    PHP parses multipart only on POST, core/services/ApiService.ts:32-62).
 *  - It is armed ONLY after a successful load. A failed load shows Retry and
 *    nothing is ever saved, so a blank form can never overwrite a real draft.
 *    A provisioned draft is the record of what Step 3 created and is never
 *    armed at all.
 *  - Every PATCH names the lock_version it read. When and how often it is sent
 *    is core/studio/autosave.ts: one PATCH at a time, a step change at once
 *    with the last choice winning, and a 409 (another tab saved, or the draft
 *    was provisioned, first) disarming it until "Reload draft", never retried.
 *
 * THE FEATURE MAP
 *  Step 1's switches are stored as the full map of served keys (R9), set for
 *  one organisation type and one set of platforms. syncFeatureChoices() keeps
 *  it in step when either changes, wherever the operator changes it
 *  (core/studio/featureChoices.ts carryChoices): a new type starts again from
 *  that type's defaults, and a switch nobody moved follows the platforms.
 *
 * THE PREVIEW
 *  refreshPreview() posts the UNSAVED sections to /preview PREVIEW_DEBOUNCE_MS
 *  after the last edit; the server lays them over the saved draft and derives
 *  the palette report, the mockups' data and the starter plan in one place
 *  (R19). A slower answer to an older request is dropped.
 *
 * STEP 3 (S8)
 *  provision() saves anything unsent first, because the server provisions the
 *  draft it holds, then posts the BYO credentials it is handed and nothing
 *  else. The credentials are an argument, never state here: the step keeps
 *  them in its own memory and they are never autosaved (R7). A created
 *  organisation disarms the autosave for good (the draft is now the record of
 *  what was made); a 409 reloads the draft, which arrives read-only, and shows
 *  the organisation that already exists instead of offering a retry.
 *
 * Opening another draft bumps `generation`; any answer still arriving for the
 * previous one is ignored, so it can never land in the wrong draft.
 */
export const useStudioDraftStore = defineStore("studioDraftStore", () => {
    // ---------------------------------------------------------------- list
    const drafts = ref<StudioDraftRow[]>([]);
    const draftsLoading = ref(false);
    const draftsError = ref<string | null>(null);

    /** Open and provisioned drafts both, so a provisioned one can link to its organisation. */
    async function fetchDrafts(): Promise<void> {
        draftsLoading.value = true;
        draftsError.value = null;
        try {
            const res = await ApiService.get(`/api/admin/studio/drafts?status=all`);
            drafts.value = res.data?.data ?? [];
        } catch (error) {
            draftsError.value = messageOf(error, 'The drafts could not be loaded.');
        } finally {
            draftsLoading.value = false;
        }
    }

    /** "New client": an empty draft. It has no colours and no organisation type (R25). */
    async function createDraft(): Promise<Outcome<StudioDraft>> {
        try {
            const res = await ApiService.post('/api/admin/studio/drafts', {});
            return { ok: true, data: res.data.data as StudioDraft };
        } catch (error) {
            return { ok: false, message: messageOf(error, 'The draft could not be created.') };
        }
    }

    async function discardDraft(id: number): Promise<Outcome<number>> {
        try {
            await ApiService.delete(`/api/admin/studio/drafts/${id}`);
            drafts.value = drafts.value.filter((row) => row.id !== id);
            return { ok: true, data: id };
        } catch (error) {
            return { ok: false, message: messageOf(error, 'The draft could not be discarded.') };
        }
    }

    // ------------------------------------------------------------ one draft
    const draft = ref<StudioDraft | null>(null);
    const answers = reactive<StudioAnswers>(emptyAnswers());
    const options = ref<StudioOptions | null>(null);
    const catalogue = ref<StudioCatalogue | null>(null);
    const presets = ref<StudioLayoutPreset[] | null>(null);
    const preview = ref<StudioPreview | null>(null);
    const saveState = ref<StudioSaveState>('idle');
    const savedAt = ref<string | null>(null);
    const loadError = ref<string | null>(null);

    const loading = ref(false);
    const currentStep = ref<StudioStepKey>('foundation');
    const saveError = ref<string | null>(null);
    const conflictMessage = ref<string | null>(null);
    const optionsError = ref<string | null>(null);
    const catalogueLoading = ref(false);
    const catalogueError = ref<string | null>(null);
    const presetsLoading = ref(false);
    const presetsError = ref<string | null>(null);
    const previewLoading = ref(false);
    const previewError = ref<string | null>(null);
    const logoUrl = ref<string | null>(null);
    const logoBusy = ref(false);
    const logoError = ref<string | null>(null);
    const provisioning = ref(false);
    const provisionOutcome = ref<ProvisionOutcome | null>(null);

    /** Fingerprints of each section as the server last confirmed it. */
    const saved = shallowRef<Record<StudioSectionKey, string>>(fingerprints(emptyAnswers()));
    const armed = ref(false);

    let generation = 0;
    let lockVersion = 0;
    let previewTimer: ReturnType<typeof setTimeout> | null = null;
    let previewSeq = 0;
    /** The organisation type and platforms the stored feature map was last set for. */
    let choicesContext: ChoiceContext = { orgType: null, platforms: [] };

    /** The sections the server does not have yet: edited, or on their way. */
    const unsavedSections = computed<StudioSectionKey[]>(() => changedSections(answers, saved.value));

    const readOnly = computed(() => draft.value?.status === 'provisioned');

    /**
     * True while leaving would lose work: a save in flight, or edits and a step
     * change not yet sent. A function, not a computed, because the step and the
     * in-flight save are bookkeeping rather than reactive state; the leave
     * guards ask at the moment of leaving.
     */
    function hasUnsavedWork(): boolean {
        return saveState.value === 'saving' || autosave.busy()
            || (armed.value && (changedSections(answers, saved.value).length > 0 || autosave.stepUnsent()));
    }

    function clearPreviewTimer() {
        if (previewTimer) clearTimeout(previewTimer);
        previewTimer = null;
    }

    function revokeLogoUrl() {
        if (logoUrl.value) URL.revokeObjectURL(logoUrl.value);
        logoUrl.value = null;
    }

    /** Forget the open draft. Anything still arriving for it is ignored. */
    function reset() {
        generation++;
        armed.value = false;
        autosave.reset();
        clearPreviewTimer();
        draft.value = null;
        Object.assign(answers, emptyAnswers());
        saved.value = fingerprints(emptyAnswers());
        lockVersion = 0;
        catalogue.value = null;
        presets.value = null;
        preview.value = null;
        saveState.value = 'idle';
        savedAt.value = null;
        loadError.value = null;
        saveError.value = null;
        conflictMessage.value = null;
        catalogueError.value = null;
        presetsError.value = null;
        previewError.value = null;
        logoError.value = null;
        provisioning.value = false;
        provisionOutcome.value = null;
        currentStep.value = 'foundation';
        revokeLogoUrl();
    }

    /** Take the server's draft as the saved state. Local edits are replaced. */
    function hydrate(payload: StudioDraft) {
        draft.value = payload;
        Object.assign(answers, normaliseAnswers(payload.answers));
        saved.value = fingerprints(answers);
        lockVersion = payload.lock_version;
        savedAt.value = payload.updated_at;
        currentStep.value = payload.current_step;
        choicesContext = currentChoiceContext();
    }

    /** Open a draft. Autosave is armed only once this has succeeded. */
    async function load(id: number): Promise<void> {
        reset();
        const gen = generation;
        loading.value = true;

        try {
            const res = await ApiService.get(`/api/admin/studio/drafts/${id}`);
            if (gen !== generation) return;
            hydrate(res.data.data as StudioDraft);
            armed.value = !readOnly.value;
            void fetchLogo();
            refreshPreview();
        } catch (error) {
            if (gen !== generation) return;
            loadError.value = statusOf(error) === 404
                ? 'This draft does not exist. It may have been discarded.'
                : messageOf(error, 'The draft could not be loaded.');
        } finally {
            if (gen === generation) loading.value = false;
        }
    }

    /** "Reload draft" after a conflict: the server's copy replaces this tab's. */
    async function reload(): Promise<void> {
        const id = draft.value?.id;
        if (id) await load(id);
    }

    /**
     * The PATCH for everything the server does not have yet: each changed
     * section whole, as it stands at this moment, and the step when it moved.
     */
    function saveRequest(step: StudioStepKey | null): SaveRequest<StudioDraft> | null {
        if (!draft.value) return null;

        const sections = changedSections(answers, saved.value);
        if (!sections.length && !step) return null;

        const id = draft.value.id;
        const body = autosaveBody(lockVersion, answers, sections, step ?? undefined);
        const sent: Partial<Record<StudioSectionKey, string>> = {};
        for (const section of sections) {
            sent[section] = sectionFingerprint(answers[section]);
        }

        return {
            send: async () => (await ApiService.patch(`/api/admin/studio/drafts/${id}`, body)).data.data as StudioDraft,
            confirm: (payload) => {
                // The answers stay this tab's: the operator may have typed while
                // this was in flight. Everything else is the server's.
                draft.value = payload;
                lockVersion = payload.lock_version;
                saved.value = { ...saved.value, ...sent };
                savedAt.value = payload.updated_at;
            },
        };
    }

    const autosave = createAutosave<StudioDraft>({
        debounceMs: AUTOSAVE_DEBOUNCE_MS,
        armed,
        saveState,
        saveError,
        request: saveRequest,
        dirty: () => changedSections(answers, saved.value).length > 0,
        serverStep: () => draft.value?.current_step ?? null,
        isConflict: (error) => statusOf(error) === 409,
        onConflict: (error) => {
            clearPreviewTimer();
            // The 409 body carries the draft as it now stands under `data`,
            // which getMessageFromObj would flatten into the message; the
            // sentence is `message` alone.
            const conflict = (error as AxiosError<{ message?: string; data?: StudioDraft }>).response?.data;
            conflictMessage.value = conflict?.message || 'This draft was saved somewhere else since you loaded it.';
            const current = conflict?.data;
            if (current && draft.value) {
                // Shown, never merged: the operator decides by reloading.
                draft.value = { ...draft.value, status: current.status, provisioned_masjid_id: current.provisioned_masjid_id };
            }
        },
        failureMessage: (error) => messageOf(error, 'The draft could not be saved.'),
    });

    /**
     * Schedule the autosave for a section that changed. A section that has come
     * back to what the server holds (typed, then undone) schedules nothing.
     */
    function patchSection(section: StudioSectionKey) {
        if (!armed.value || !changedSections(answers, saved.value).includes(section)) return;
        autosave.schedule();
    }

    /** Save now: every changed section, and the step when it moved. */
    function flush(): Promise<void> {
        return autosave.flush();
    }

    /** Move to a step and record it at once, so a reload resumes there. */
    function setStep(step: StudioStepKey) {
        currentStep.value = step;
        autosave.queueStep(step);
    }

    /** Re-derive the mockups from the saved draft plus this tab's unsaved sections. */
    function refreshPreview() {
        if (!draft.value) return;
        if (previewTimer) clearTimeout(previewTimer);
        previewTimer = setTimeout(() => { previewTimer = null; void runPreview(); }, PREVIEW_DEBOUNCE_MS);
    }

    async function runPreview(): Promise<void> {
        if (!draft.value) return;
        const gen = generation;
        const seq = ++previewSeq;
        const body = previewBody(answers, changedSections(answers, saved.value));

        previewLoading.value = true;
        try {
            const res = await ApiService.post(`/api/admin/studio/drafts/${draft.value.id}/preview`, body);
            if (gen !== generation || seq !== previewSeq) return;
            preview.value = res.data.data as StudioPreview;
            previewError.value = null;
        } catch (error) {
            if (gen !== generation || seq !== previewSeq) return;
            previewError.value = messageOf(error, 'The preview could not be updated.');
        } finally {
            if (gen === generation && seq === previewSeq) previewLoading.value = false;
        }
    }

    /**
     * One layout preset's preview for its card on Step 2: this draft, unsaved
     * edits included, planned with that preset instead of its own. Kept out of
     * `preview`, which always describes the draft's own choice.
     */
    async function previewPreset(preset: string): Promise<Outcome<StudioPreview>> {
        const current = draft.value;
        if (!current) return { ok: false, message: 'No draft is open.' };

        const gen = generation;
        try {
            const body = presetPreviewBody(answers, changedSections(answers, saved.value), preset);
            const res = await ApiService.post(`/api/admin/studio/drafts/${current.id}/preview`, body);
            if (gen !== generation) return { ok: false, message: 'The draft was closed.' };
            return { ok: true, data: res.data.data as StudioPreview };
        } catch (error) {
            return { ok: false, message: messageOf(error, 'This layout could not be previewed.') };
        }
    }

    // Any edit: queue the sections that now differ, and refresh the mockups.
    // hydrate() replaces the answers and the saved fingerprints together, so
    // loading a draft is never itself a change and never saves.
    watch(answers, () => {
        if (!armed.value) return;
        for (const section of changedSections(answers, saved.value)) {
            patchSection(section);
        }
        refreshPreview();
    }, { deep: true });

    function currentChoiceContext(): ChoiceContext {
        return { orgType: answers.identity.org_type ?? null, platforms: [...(answers.platforms.platforms ?? [])] };
    }

    /**
     * Keep Step 1's map in step with the organisation type and platforms, the
     * moment either changes and on whichever step (core/studio/featureChoices.ts
     * carryChoices). Only an armed draft is written: a provisioned one is the
     * record of what Step 3 created, and a failed load never saves. When the
     * platforms moved under a stored map and this type's catalogue is not here,
     * it is fetched, and the map follows when it arrives.
     */
    function syncFeatureChoices() {
        if (!armed.value) return;

        const now = currentChoiceContext();
        const { choices, settled } = carryChoices(catalogue.value, answers.features.capabilities, choicesContext, now);

        if (settled) {
            choicesContext = now;
        } else if (answers.identity.org_type && !catalogueLoading.value) {
            void fetchCatalogue(answers.identity.org_type);
        }

        if (!sameChoices(answers.features.capabilities, choices)) {
            answers.features.capabilities = (choices as Record<string, boolean> | null) ?? null;
        }
    }

    watch([
        () => answers.identity.org_type,
        () => (answers.platforms.platforms ?? []).join(','),
        catalogue,
        armed,
    ], syncFeatureChoices);

    // ----------------------------------------------------------------- logo
    /** The draft's logo as a blob URL: the endpoint is authenticated, so an <img src> cannot fetch it. */
    async function fetchLogo(): Promise<void> {
        const current = draft.value;
        revokeLogoUrl();
        if (!current?.logo) return;

        const gen = generation;
        try {
            const res: AxiosResponse<Blob> = await ApiService.VueApp.axios.get(`/api/admin/studio/drafts/${current.id}/logo`, { responseType: 'blob' });
            if (gen !== generation) return;
            logoUrl.value = URL.createObjectURL(res.data);
        } catch (error) {
            if (gen !== generation) return;
            logoError.value = messageOf(error, 'The logo could not be shown.');
        }
    }

    /** Colours sampled from an image blob, commonest first. */
    function sampleColours(blob: Blob): Promise<string[]> {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(blob);
            const image = new Image();
            image.onload = () => {
                try {
                    resolve(extractDominantColors(image, PALETTE_CANDIDATES));
                } catch {
                    resolve([]);
                } finally {
                    URL.revokeObjectURL(url);
                }
            };
            image.onerror = () => { URL.revokeObjectURL(url); resolve([]); };
            image.src = url;
        });
    }

    /**
     * Upload a logo: converted in the browser when it has to be (prepareLogo),
     * then sampled for palette candidates, which are saved into the brand
     * section so they survive a reload.
     */
    async function uploadLogo(file: File): Promise<boolean> {
        const current = draft.value;
        if (!current || readOnly.value) return false;

        const gen = generation;
        logoBusy.value = true;
        logoError.value = null;

        try {
            const prepared = await prepareLogo(file);
            const form = new FormData();
            form.append('logo', prepared);

            const res = await ApiService.post(`/api/admin/studio/drafts/${current.id}/logo`, form);
            if (gen !== generation) return false;

            // The logo is not part of the answers and does not move the lock
            // version (StudioDraftsController::storeLogo), so only these change.
            const payload = res.data.data as StudioDraft;
            if (draft.value) {
                draft.value = { ...draft.value, logo: payload.logo, palette: payload.palette };
            }

            const colours = await sampleColours(prepared);
            if (gen !== generation) return false;
            answers.brand.extracted = colours.length ? colours : null;

            await fetchLogo();
            refreshPreview();
            return true;
        } catch (error) {
            if (gen !== generation) return false;
            logoError.value = error instanceof LogoPreparationError
                ? error.message
                : messageOf(error, 'The logo could not be uploaded.');
            return false;
        } finally {
            if (gen === generation) logoBusy.value = false;
        }
    }

    async function removeLogo(): Promise<boolean> {
        const current = draft.value;
        if (!current || readOnly.value) return false;

        const gen = generation;
        logoBusy.value = true;
        logoError.value = null;

        try {
            await ApiService.delete(`/api/admin/studio/drafts/${current.id}/logo`);
            if (gen !== generation) return false;
            if (draft.value) draft.value = { ...draft.value, logo: null };
            revokeLogoUrl();
            refreshPreview();
            return true;
        } catch (error) {
            if (gen !== generation) return false;
            logoError.value = messageOf(error, 'The logo could not be removed.');
            return false;
        } finally {
            if (gen === generation) logoBusy.value = false;
        }
    }

    // ------------------------------------------------------------ provision
    /**
     * Step 3's button: the draft becomes an organisation, once (S8). Returns
     * the outcome, which is also kept for the results screen; null when
     * nothing was attempted (no draft, read only, already running) or the
     * draft was closed meanwhile.
     */
    async function provision(secrets: ProvisionSecrets): Promise<ProvisionOutcome | null> {
        const current = draft.value;
        if (!current || readOnly.value || provisioning.value) return null;

        const gen = generation;
        provisioning.value = true;
        provisionOutcome.value = null;

        try {
            // The server provisions what it has saved, so the latest edits go first.
            await flush();
            if (gen !== generation) return null;

            if (saveState.value === 'error' || saveState.value === 'conflict' || changedSections(answers, saved.value).length) {
                const reason = saveState.value === 'conflict' ? conflictMessage.value : saveError.value;
                provisionOutcome.value = {
                    kind: 'failed',
                    message: `The latest answers are not saved, so nothing was created.${reason ? ` ${reason}` : ''}`,
                };
                return provisionOutcome.value;
            }

            let status: number | undefined;
            let body: unknown;
            try {
                const res = await ApiService.post(`/api/admin/studio/drafts/${current.id}/provision`, provisionBody(answers, secrets));
                status = res.status;
                body = res.data;
            } catch (error) {
                status = isAxiosError(error) ? error.response?.status : undefined;
                body = isAxiosError(error) ? error.response?.data : undefined;
            }
            if (gen !== generation) return null;

            const outcome = readProvisionOutcome(status, body);

            if (outcome.kind === 'conflict') {
                // Show what exists, never a retry: the reloaded draft is read only.
                await load(current.id);
                if (draft.value?.id !== current.id) return null;
                provisionOutcome.value = outcome;
                return outcome;
            }

            if (outcome.kind === 'created' || outcome.kind === 'unconfirmed') {
                // The organisation exists: this draft is its record now and is
                // never saved again. Marked here rather than reloaded, so the
                // results survive a failed reload, and so a backend that did
                // not mark the draft cannot be offered a second provision.
                armed.value = false;
                autosave.reset();
                clearPreviewTimer();
                const masjidId = outcome.kind === 'created' ? outcome.result.masjid_id : outcome.masjidId;
                draft.value = { ...current, ...draft.value, status: 'provisioned', provisioned_masjid_id: masjidId };
                // The list, if it was loaded, says Live straight away rather than after its next fetch.
                drafts.value = drafts.value.map((row) => row.id === current.id
                    ? { ...row, status: 'provisioned', provisioned_masjid_id: masjidId }
                    : row);
            }

            provisionOutcome.value = outcome;
            return outcome;
        } finally {
            if (gen === generation || draft.value?.id === current.id) provisioning.value = false;
        }
    }

    // ------------------------------------------------------------ reference
    /** The wizard's options: verticals with their terminology, prayer choices, countries. Fetched once. */
    async function fetchOptions(): Promise<void> {
        if (options.value) return;
        optionsError.value = null;
        try {
            const res = await ApiService.get('/api/admin/onboarding/options');
            options.value = res.data.data as StudioOptions;
        } catch (error) {
            optionsError.value = messageOf(error, 'The organisation types and prayer settings could not be loaded.');
        }
    }

    async function fetchCities(countryId: number): Promise<{ id: number; name: string }[]> {
        try {
            const res = await ApiService.get(`/api/admin/countries/${countryId}/cities`);
            return res.data?.data ?? [];
        } catch {
            return [];
        }
    }

    /** Step 1's catalogue for one organisation type (S1). A failure is reported, never filled in. */
    async function fetchCatalogue(orgType: OrgType): Promise<void> {
        const gen = generation;
        catalogueLoading.value = true;
        catalogueError.value = null;
        try {
            const res = await ApiService.get(`/api/admin/studio/catalogue?org_type=${encodeURIComponent(orgType)}`);
            if (gen !== generation) return;
            catalogue.value = res.data.data as StudioCatalogue;
        } catch (error) {
            if (gen !== generation) return;
            catalogue.value = null;
            catalogueError.value = messageOf(error, 'The server gave no reason.');
        } finally {
            if (gen === generation) catalogueLoading.value = false;
        }
    }

    /** Step 2's layout presets for one organisation type (S4). */
    async function fetchPresets(orgType: OrgType): Promise<void> {
        const gen = generation;
        presetsLoading.value = true;
        presetsError.value = null;
        try {
            const res = await ApiService.get(`/api/admin/studio/layout-presets?org_type=${encodeURIComponent(orgType)}`);
            if (gen !== generation) return;
            presets.value = res.data.data as StudioLayoutPreset[];
        } catch (error) {
            if (gen !== generation) return;
            presets.value = null;
            presetsError.value = messageOf(error, 'The server gave no reason.');
        } finally {
            if (gen === generation) presetsLoading.value = false;
        }
    }

    /** May this host be given to a new organisation? (S3; no Cloudflare call.) */
    async function checkDomain(request: StudioDomainCheckRequest): Promise<Outcome<StudioDomainCheck>> {
        try {
            const res = await ApiService.post('/api/admin/studio/domains/check', request);
            return { ok: true, data: res.data.data as StudioDomainCheck };
        } catch (error) {
            return { ok: false, message: messageOf(error, 'The address could not be checked.') };
        }
    }

    return {
        // list
        drafts, draftsLoading, draftsError, fetchDrafts, createDraft, discardDraft,
        // draft
        draft, answers, options, catalogue, presets, preview, saveState, savedAt, loadError,
        loading, currentStep, saveError, conflictMessage, readOnly, armed, unsavedSections, hasUnsavedWork,
        load, reload, reset, patchSection, flush, setStep, refreshPreview, previewPreset,
        // logo
        logoUrl, logoBusy, logoError, fetchLogo, uploadLogo, removeLogo,
        // step 3
        provisioning, provisionOutcome, provision,
        // reference
        optionsError, fetchOptions, fetchCities,
        catalogueLoading, catalogueError, fetchCatalogue,
        presetsLoading, presetsError, fetchPresets,
        previewLoading, previewError,
        checkDomain,
    };
});
