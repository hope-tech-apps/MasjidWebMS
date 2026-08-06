import { defineStore } from "pinia";
import { computed, ref } from "vue";
import { AxiosResponse } from "axios";
import ApiService from "@/core/services/ApiService";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { useAuthStore } from "@/stores/authStore";
import { useMasjidStore } from "@/stores/masjidStore";
import { ThemeSetting } from "@/core/types/data/masjid-related/ThemeSetting";
import {
    Flyer,
    FlyerContent,
    FlyerCutoutState,
    FlyerCutoutStatus,
    FlyerDesign,
    FlyerImageSlotState,
    FlyerListItem,
    FlyerPalette,
    FlyerPaletteAudit,
    FlyerPalettesFile,
    FlyerPayload,
    FlyerSlot,
    FlyerTemplate,
    FlyerTemplateIndex,
    FlyerTemplateManifest,
    FLYER_CUTOUT_PENDING
} from "@/core/types/data/masjid-related/Flyer";

// palette.js ships beside the templates it colours and has no declaration file; the
// project's wildcard '*.js' module declaration only promises a default export. The
// import is deliberate rather than reimplemented: resolvePalette()'s WCAG gate is the
// thing standing between a tenant's brand colour and an invisible disclaimer, and a
// second copy of that gate in TypeScript is a second copy to get wrong.
// @ts-ignore
import { resolvePalette, paletteToCssVars, auditPalette } from "../../../flyer-templates/palette.js";

/**
 * The designs are BUNDLED, not fetched.
 *
 * Rendering happens in the browser (the droplet has no Node, and DomPDF cannot do
 * flexbox or Arabic shaping), so the manifests and their markup are compiled in at
 * build time. That also means the Studio can build and export a flyer on a host where
 * the flyer tables have not been seeded yet — only *saving* needs the server.
 *
 * index.json is the registry: adding a template is one entry there, exactly as
 * resources/flyer-templates/README.md says.
 */
const TEMPLATE_JSON = import.meta.glob<Record<string, unknown>>(
    '../../../flyer-templates/*.json',
    { eager: true, import: 'default' }
);
const TEMPLATE_HTML = import.meta.glob<string>(
    '../../../flyer-templates/*.html',
    { eager: true, query: '?raw', import: 'default' }
);

/** Glob keys carry the whole relative path; the index refers to files by name alone. */
function byBasename<T>(record: Record<string, T>): Record<string, T> {
    const out: Record<string, T> = {};
    Object.entries(record).forEach(([path, value]) => {
        const name = path.split('/').pop();
        if (name) out[name] = value;
    });
    return out;
}

const JSON_ASSETS = byBasename(TEMPLATE_JSON);
const HTML_ASSETS = byBasename(TEMPLATE_HTML);

const TEMPLATE_INDEX = JSON_ASSETS['index.json'] as unknown as FlyerTemplateIndex;
const PALETTES = JSON_ASSETS['palettes.json'] as unknown as FlyerPalettesFile;

/** Poll cadence for the cutout worker: brisk while it is likely running, then patient.
 *  ProcessFlyerCutout allows one retry after a 60s backoff, so giving up before ~3
 *  minutes would show a failure the worker is about to retract. */
const POLL_FAST_MS = 2000;
const POLL_SLOW_MS = 5000;
const POLL_FAST_ATTEMPTS = 15;
const POLL_MAX_ATTEMPTS = 45;

/** Guard-rail on the upload, before the wire. The worker downscales to 1400px anyway. */
const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

/**
 * A manifest may name the image slot background removal is FOR, outright.
 *
 * Read as an extension of FlyerSlot rather than by widening the shared type: none of
 * the shipped manifests carry the flag yet, and until one does this is a forward
 * declaration of the contract, not a field the editor or the renderer depends on.
 */
type FlyerImageSlot = FlyerSlot & { cutout?: boolean };

/**
 * Flyer Studio state: which design is open, what its slots hold, the palette that will
 * be snapshotted onto the row, and the cutout job's progress.
 *
 * The rendering itself is not here — FlyerPreview.vue owns the DOM, because the export
 * has to serialise the very nodes the admin was looking at. This store owns the data
 * and every call to /api/admin/masjids/{masjid_id}/flyers*.
 */
export const useFlyersStore = defineStore('flyersStore', () => {

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    // State
    const templates = ref<FlyerTemplate[]>([]);
    /** False once a /flyer-templates call has failed — the Studio still builds, but cannot save. */
    const templatesAvailable = ref(true);
    const theme = ref<ThemeSetting | null>(null);

    const selectedKey = ref<string | null>(null);
    const title = ref('');
    const content = ref<FlyerContent>({});
    const images = ref<Record<string, FlyerImageSlotState>>({});
    /** '' = derive the palette from this masjid's own theme_settings. */
    const paletteKey = ref('');

    const flyerId = ref<number | null>(null);
    const cutoutStatus = ref<FlyerCutoutStatus>('none');
    const cutoutError = ref<string | null>(null);
    /**
     * Whether this host can remove a background at all — the server says so, rather
     * than the Studio inferring it from a status of 'none'. Background removal is off
     * by default per host (config/flyer.php), so "not available" is a normal answer and
     * not a failure to report.
     */
    const cutoutAvailable = ref(false);

    const loading = ref(false);
    const uploading = ref(false);
    const saving = ref(false);
    const savedAt = ref<string | null>(null);
    /**
     * Whether the ADMIN has saved this flyer, as opposed to the draft row the store
     * creates behind their back to hold a photo. The difference decides whether leaving
     * the design deletes the row or leaves it alone.
     */
    const savedByAdmin = ref(false);

    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let pollAttempts = 0;
    /** Which polling run is current. stopPolling() bumps it; see pollCutout(). */
    let pollRun = 0;

    /**
     * The photo last handed to uploadPhoto(), kept so a failed send can be retried
     * without asking the admin to find the file on their disk a second time. Not
     * reactive: nothing renders it, and a ref would only add a proxy around a File.
     */
    let lastPhoto: File | null = null;

    // ------------------------------------------------------------------ designs

    /**
     * The shipped designs, each paired with its server row when there is one. Built
     * from index.json so a template that is registered but missing a file is skipped
     * rather than rendered as a blank canvas.
     */
    const designs = computed<FlyerDesign[]>(() => {
        const entries = TEMPLATE_INDEX?.templates ?? [];

        return entries
            .map((entry) => ({
                key: entry.key,
                manifest: JSON_ASSETS[entry.manifest] as unknown as FlyerTemplateManifest,
                html: HTML_ASSETS[entry.html],
                template: templates.value.find((t) => t.key === entry.key) ?? null
            }))
            .filter((design) => !!design.manifest && !!design.html);
    });

    const design = computed<FlyerDesign | null>(
        () => designs.value.find((d) => d.key === selectedKey.value) ?? null
    );

    const manifest = computed<FlyerTemplateManifest | null>(() => design.value?.manifest ?? null);

    const slots = computed<FlyerSlot[]>(() => manifest.value?.slots ?? []);

    /** Every image slot the design declares, in manifest order. */
    const imageSlots = computed<FlyerImageSlot[]>(
        () => slots.value.filter((slot) => slot.type === 'image') as FlyerImageSlot[]
    );

    /**
     * The one image slot that goes to the server — the one background removal is FOR.
     * A flyer row holds a single source_image_path, so a cutout can only ever be about
     * one picture, and which picture is the DESIGN's answer, never the order the slots
     * happen to be declared in.
     *
     * "The first image slot" is wrong on three of the six shipped designs: it picks the
     * masjid's LOGO on janazah and event-invitation, and the full-bleed BACKGROUND on
     * event-photo — and then strips the background out of each of them.
     *
     * A manifest may say so outright (`"cutout": true` on the slot). Until the manifests
     * carry that flag the answer is derived from what they already declare: the slot the
     * layout gives the `image` treatment is the flyer's subject — the dish that sits on
     * the gradient — and a design whose own ground IS the photo can never want that
     * photo cut out, whatever else it says.
     *
     * Every other image slot stays in this browser. It is still drawn and still
     * exported — the preview and the export both run off data URLs — it simply has
     * nowhere to live on a row with room for one picture.
     */
    const cutoutSlot = computed<string | null>(() => {
        const declared = imageSlots.value.find((slot) => slot.cutout === true);
        if (declared) return declared.name;

        if (manifest.value?.palette?.ground === 'photo') return null;

        return imageSlots.value.find((slot) => slot.treatment === 'image')?.name ?? null;
    });

    // ------------------------------------------------------------------ palette

    function numericMasjidId(): number | null {
        const id = masjidId();
        const parsed = typeof id === 'string' ? parseInt(id, 10) : id;
        return typeof parsed === 'number' && !Number.isNaN(parsed) ? parsed : null;
    }

    /** Named palettes this tenant may pick. Burlington's purple is Burlington's. */
    const paletteOptions = computed<FlyerPalette[]>(() => {
        const all = Object.values(PALETTES?.palettes ?? {});
        const id = numericMasjidId();
        return all.filter((p) => !p.restricted_to_masjid || p.restricted_to_masjid === id);
    });

    /**
     * Resolved, gated, and ready to stamp on the flyer root. Recomputed rather than
     * stored, so switching palette or template re-runs the contrast check instead of
     * trusting a value that was correct for a different ground.
     */
    const palette = computed<FlyerPalette>(() => resolvePalette({
        named: paletteKey.value ? PALETTES?.palettes?.[paletteKey.value] : undefined,
        theme: theme.value,
        masjidId: numericMasjidId(),
        palettes: PALETTES
    }));

    const cssVars = computed<Record<string, string>>(() => paletteToCssVars(palette.value));

    /**
     * resolvePalette() already repairs every failing pair; this is the canary that says
     * it stayed repaired. The Studio shows it, so a hand-added palette in palettes.json
     * cannot quietly ship an unreadable line.
     */
    const paletteAudit = computed<FlyerPaletteAudit[]>(() => auditPalette(palette.value));

    // ------------------------------------------------------------------ content

    /**
     * What the preview and the export actually render: the typed content, with each
     * image slot swapped for the data URL in hand. Cutout or original — never a branch
     * on cutout_status, because a failed or still-queued cutout has to fall back to the
     * upload rather than leave a hole where the photo goes.
     */
    const renderContent = computed<FlyerContent>(() => {
        const out: FlyerContent = { ...content.value };

        Object.entries(images.value).forEach(([slot, state]) => {
            out[slot] = (state.useCutout && state.cutout) ? state.cutout : state.original;
        });

        return out;
    });

    /** Required slots still empty — the export and save guard. */
    const missingRequired = computed<FlyerSlot[]>(() => slots.value.filter((slot) => {
        if (!slot.required) return false;

        if (slot.type === 'image') {
            const state = images.value[slot.name];
            return !state?.original;
        }

        const value = content.value[slot.name];
        if (Array.isArray(value)) return value.every((item) => !hasAnyValue(item));

        return !String(value ?? '').trim();
    }));

    const isComplete = computed(() => !!manifest.value && missingRequired.value.length === 0);

    const cutoutPending = computed(() => FLYER_CUTOUT_PENDING.includes(cutoutStatus.value));

    /** A flyer can only be saved against a template row the server knows about. */
    const canSave = computed(() => !!design.value?.template && isComplete.value);

    function hasAnyValue(item: FlyerListItem): boolean {
        return Object.values(item).some((value) => !!String(value ?? '').trim());
    }

    // ------------------------------------------------------------------ requests

    /**
     * The masjid whose flyers this screen is making. dashboardMasjidId is set from
     * localStorage at auth time, so it survives a refresh that has not yet hydrated
     * masjidStore.masjid.
     */
    function masjidId(): number | string | null {
        return authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null;
    }

    /**
     * The flyer endpoints are not in the BackendApiRoute union yet — that file is
     * shared. One cast, here, instead of one at every call site.
     */
    function route(path: string): BackendApiRoute {
        return path as BackendApiRoute;
    }

    /**
     * Seeded system templates plus any this masjid authored. A failure is not fatal:
     * `templatesAvailable` goes false, the Studio keeps working, and Save explains
     * itself rather than throwing.
     */
    async function fetchTemplates(): Promise<FlyerTemplate[]> {
        const id = masjidId();
        if (!id) return [];

        await ApiService.get(route(`/api/admin/masjids/${id}/flyer-templates`))
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                    templates.value = res.data.data;
                    templatesAvailable.value = true;
                }
            })
            .catch((e: Error) => {
                templatesAvailable.value = false;
                console.error('Fetch flyer templates error: ', e);
            });

        return templates.value;
    }

    /** The brand the cool/neutral gradient is derived from. Absent is fine — palette.js defaults. */
    async function fetchTheme(): Promise<ThemeSetting | null> {
        const id = masjidId();
        if (!id) return null;

        await ApiService.get(`/api/admin/masjids/${id}/theme`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    theme.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch theme settings error: ', e);
            });

        return theme.value;
    }

    async function initialise(): Promise<void> {
        loading.value = true;
        try {
            await Promise.all([fetchTemplates(), fetchTheme()]);
        } finally {
            loading.value = false;
        }
    }

    // ------------------------------------------------------------------ editing

    /**
     * Open a design. Slots start at their manifest defaults — except the three the
     * manifests mark `ask_always`, which start empty however tempting the default is:
     * the order phone number, the food disclaimer and the name of the deceased are
     * asked every time, never carried forward.
     */
    function selectTemplate(key: string): void {
        const next = designs.value.find((d) => d.key === key);
        if (!next) return;

        stopPolling();
        // Same reasoning as reset(): whatever draft the last design left behind is
        // about to become unreachable.
        discardImplicitDraft();

        selectedKey.value = key;
        flyerId.value = null;
        savedByAdmin.value = false;
        lastPhoto = null;
        cutoutStatus.value = 'none';
        cutoutError.value = null;
        cutoutAvailable.value = false;
        savedAt.value = null;
        images.value = {};
        content.value = defaultContent(next.manifest);
        title.value = next.manifest.name;
    }

    function defaultContent(source: FlyerTemplateManifest): FlyerContent {
        const seeded: FlyerContent = {};

        source.slots.forEach((slot) => {
            if (slot.type === 'list') {
                seeded[slot.name] = [emptyListItem(slot)];
                return;
            }

            if (slot.type === 'image') {
                seeded[slot.name] = null;
                return;
            }

            seeded[slot.name] = slot.ask_always ? '' : (slot.default ?? '');
        });

        return seeded;
    }

    function emptyListItem(slot: FlyerSlot): FlyerListItem {
        const item: FlyerListItem = {};
        (slot.item_slots ?? []).forEach((field) => {
            item[field.name] = field.default ?? '';
        });
        return item;
    }

    function setSlot(name: string, value: string): void {
        content.value = { ...content.value, [name]: value };
    }

    function setListItems(name: string, items: FlyerListItem[]): void {
        content.value = { ...content.value, [name]: items };
    }

    function addListItem(name: string): void {
        const slot = slots.value.find((s) => s.name === name);
        if (!slot) return;

        const current = (content.value[name] as FlyerListItem[]) ?? [];
        if (slot.maxItems && current.length >= slot.maxItems) return;

        setListItems(name, [...current, emptyListItem(slot)]);
    }

    function removeListItem(name: string, index: number): void {
        const current = (content.value[name] as FlyerListItem[]) ?? [];
        setListItems(name, current.filter((_, i) => i !== index));
    }

    // ------------------------------------------------------------------ images

    /**
     * Take an upload into the editor. The file is read to a DATA URL rather than an
     * object URL because the export inlines the whole flyer into an SVG that the
     * browser rasterises in an isolated context — a blob: or http: src there simply
     * never loads, and the flyer exports with a hole in it.
     */
    async function setImage(slot: string, file: File): Promise<void> {
        if (!file.type.startsWith('image/')) {
            throw new Error('That file is not an image.');
        }
        if (file.size > MAX_IMAGE_BYTES) {
            throw new Error('That image is larger than 12MB. Please use a smaller one.');
        }

        const dataUrl = await readAsDataUrl(file);

        images.value = {
            ...images.value,
            [slot]: {
                original: dataUrl,
                cutout: null,
                // Only the cutout slot can ever have one; seeding true anywhere else
                // promises a cut-out for a picture nothing is going to cut out.
                useCutout: slot === cutoutSlot.value,
                fileName: file.name
            }
        };

        // The cutout slot is the only image the flyer row can store, and the only one
        // background removal is meant for.
        if (slot === cutoutSlot.value) {
            await uploadPhoto(file);
        }
    }

    function clearImage(slot: string): void {
        const next = { ...images.value };
        delete next[slot];
        images.value = next;

        if (slot !== cutoutSlot.value) return;

        stopPolling();
        lastPhoto = null;
        cutoutStatus.value = 'none';
        cutoutError.value = null;

        // Drop the stored photo too, or reopening this draft composites a picture the
        // admin has already taken off the flyer.
        const id = masjidId();
        if (!id || !flyerId.value) return;

        ApiService.delete(route(`/api/admin/masjids/${id}/flyers/${flyerId.value}/photo`))
            .catch((e: Error) => {
                console.error('Flyer photo delete error: ', e);
            });
    }

    /** Fall back to the upload — the honest control for a cutout that came back wrong. */
    function useCutout(slot: string, use: boolean): void {
        const state = images.value[slot];
        if (!state) return;

        images.value = { ...images.value, [slot]: { ...state, useCutout: use } };
    }

    function readAsDataUrl(file: File): Promise<string> {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result));
            reader.onerror = () => reject(new Error('That image could not be read.'));
            reader.readAsDataURL(file);
        });
    }

    /**
     * Pull one of the flyer's images down as a data URL.
     *
     * Through axios rather than fetch: uploads live on the private disk and are served
     * back by an authenticated endpoint, so the request needs the Authorization header
     * that ApiService already carries. ApiService.get() cannot ask for a blob, which is
     * why the instance is used directly here.
     */
    async function urlToDataUrl(url: string): Promise<string> {
        const res: AxiosResponse = await ApiService.VueApp.axios.get(url, { responseType: 'blob' });

        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result));
            reader.onerror = () => reject(new Error('The cut-out could not be read.'));
            reader.readAsDataURL(res.data as Blob);
        });
    }

    // ------------------------------------------------------------------ cutout

    /**
     * Send the photo up so the worker can strip its background. A flyer row has to
     * exist first — the image is stored against it — so an unsaved Studio session
     * becomes a draft at this point rather than on Save.
     *
     * Background removal is off by default per host (config/flyer.php), in which case
     * the server answers `available: false` with a status of 'none' and we simply keep
     * using the upload. That is a normal answer, not a failure.
     *
     * Anything that IS a failure — including a draft that could not be created — ends
     * up on cutoutStatus/cutoutError, never in the console alone. The photo exists as a
     * data URL in this one tab until it goes up; an admin who believes it was stored is
     * an admin who closes the tab.
     */
    async function uploadPhoto(file: File): Promise<void> {
        const id = masjidId();
        if (!id || !cutoutSlot.value) return;

        lastPhoto = file;
        uploading.value = true;
        cutoutError.value = null;

        try {
            const draft = await ensureDraft();

            const body = new FormData();
            body.append('image', file);
            body.append('remove_background', '1');

            const res: AxiosResponse = await ApiService.post(
                route(`/api/admin/masjids/${id}/flyers/${draft}/photo`),
                body
            );

            if (res.data?.status !== 'success' || !res.data?.data) {
                throw new Error('The server did not accept the photo.');
            }

            await absorbCutout(res.data.data as FlyerCutoutState);
            if (cutoutPending.value) schedulePoll();
        } catch (e) {
            // The upload is an enhancement — the flyer still renders and exports from
            // the local data URL — so this must not take the editor down with it. It
            // must not pass for success either.
            cutoutStatus.value = 'failed';
            cutoutError.value = uploadFailureMessage(e);
            console.error('Flyer photo upload error: ', e);
        } finally {
            uploading.value = false;
        }
    }

    /** Why the photo did not go up, in the admin's terms rather than the wire's. */
    function uploadFailureMessage(e: unknown): string {
        // A design with no server row cannot have a draft, so the upload never had
        // anywhere to go — that is worth saying plainly instead of blaming the network.
        if (!design.value?.template) {
            return 'This design is not set up on this server yet, so the photo could not be sent for background removal. '
                + 'It is still on the flyer and will be included in the export.';
        }

        const server = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;

        return (server || 'The photo could not be sent for background removal.')
            + ' It is still on the flyer and will be included in the export — try again to have the background removed.';
    }

    /** Send the same photo again after a failure. The file is still in hand. */
    async function retryUpload(): Promise<void> {
        if (!lastPhoto || uploading.value) return;

        await uploadPhoto(lastPhoto);
    }

    /** Take the worker's progress, and the finished cut-out with it. */
    async function absorbCutout(state: FlyerCutoutState): Promise<void> {
        if (state.flyer_id) flyerId.value = state.flyer_id;
        cutoutStatus.value = state.cutout_status ?? 'none';
        cutoutError.value = state.cutout_error ?? null;
        cutoutAvailable.value = !!state.available;

        const slot = cutoutSlot.value;
        if (!slot) return;

        const image = images.value[slot];
        if (!image || !state.images?.cutout || image.cutout) return;

        try {
            const cutout = await urlToDataUrl(state.images.cutout);

            // The photo can be swapped or cleared while its cut-out is downloading;
            // landing a cut-out of the previous picture on the current one is worse
            // than having no cut-out at all.
            const current = images.value[slot];
            if (!current || current.original !== image.original) return;

            images.value = { ...images.value, [slot]: { ...current, cutout } };
        } catch (e) {
            // A cutout we cannot fetch is a cutout we do not have. Keep the original.
            cutoutStatus.value = 'failed';
            cutoutError.value = 'The cut-out was made but could not be downloaded. The original will be used.';
            console.error('Flyer cutout fetch error: ', e);
        }
    }

    function schedulePoll(): void {
        stopPolling();
        pollAttempts = 0;
        const run = pollRun;
        pollTimer = setTimeout(() => pollCutout(run), POLL_FAST_MS);
    }

    /**
     * Poll until the job settles. It runs long by design — one cutout is ~3.2s of the
     * droplet's only vCPU and the job allows a retry after a minute's backoff — so the
     * ceiling here is about three minutes, after which the admin is told to carry on
     * with the original rather than watch a spinner forever.
     *
     * Every iteration carries the run it belongs to. Clearing the timer only cancels a
     * poll that has not started: one already awaiting its request would come back after
     * the screen was unmounted, write to state nobody is watching, and arm the next
     * timer — a loop that survives its own cancellation.
     */
    async function pollCutout(run: number): Promise<void> {
        if (run !== pollRun) return;

        const id = masjidId();
        if (!id || !flyerId.value) return;

        pollAttempts += 1;

        try {
            const res: AxiosResponse = await ApiService.get(
                route(`/api/admin/masjids/${id}/flyers/${flyerId.value}/cutout`)
            );

            if (run !== pollRun) return;

            if (res.data?.status === 'success' && res.data?.data) {
                await absorbCutout(res.data.data as FlyerCutoutState);
            }
        } catch (e) {
            if (run !== pollRun) return;
            console.error('Flyer cutout poll error: ', e);
        }

        if (run !== pollRun) return;

        if (!cutoutPending.value) return;

        if (pollAttempts >= POLL_MAX_ATTEMPTS) {
            cutoutError.value = 'Background removal is taking longer than usual. The original photo is being used — reopen this draft later to pick up the cut-out.';
            return;
        }

        pollTimer = setTimeout(
            () => pollCutout(run),
            pollAttempts < POLL_FAST_ATTEMPTS ? POLL_FAST_MS : POLL_SLOW_MS
        );
    }

    /**
     * Call from onUnmounted — a timer outliving the screen keeps hitting the API.
     *
     * Bumping the run is what makes this a cancellation rather than a pause: an
     * iteration already in flight resumes into a run number that is no longer current
     * and stops there.
     */
    function stopPolling(): void {
        if (pollTimer) clearTimeout(pollTimer);
        pollTimer = null;
        pollRun += 1;
    }

    // ------------------------------------------------------------------ saving

    /**
     * Content as it goes to the server: image slots nulled. The picture lives in
     * source_image_path / cutout_path on the row; a data URL in the JSON column would
     * be megabytes of base64 per draft, and the server validates content against the
     * design's slots anyway.
     */
    function contentForSave(): FlyerContent {
        const out: FlyerContent = { ...content.value };

        slots.value
            .filter((slot) => slot.type === 'image')
            .forEach((slot) => { out[slot.name] = null; });

        return out;
    }

    function draftTitle(): string {
        const typed = String(title.value ?? '').trim();
        if (typed) return typed;

        // Fall back to whatever names the flyer on the flyer itself.
        const named = String(content.value.title ?? content.value.name ?? '').trim();

        return named || manifest.value?.name || 'Untitled flyer';
    }

    function payload(): FlyerPayload | null {
        const template = design.value?.template;
        if (!template) return null;

        return {
            flyer_template_id: template.id,
            title: draftTitle(),
            content: contentForSave(),
            // Snapshotted as resolved, so a rebrand next month does not restyle a flyer
            // the board has already approved.
            palette: palette.value
        };
    }

    /**
     * The row the photo needs to hang off, created silently on first upload.
     *
     * It throws rather than swallowing: uploadPhoto is the only caller and it has to be
     * able to tell the admin the photo did not go up. A draft that could not be created
     * is a photo that only exists in this tab.
     */
    async function ensureDraft(): Promise<number> {
        if (flyerId.value) return flyerId.value;

        return await persistDraft();
    }

    /**
     * Save, as the admin's own action. Throws on 422 so the view can surface the field
     * errors rather than claim a save that did not happen.
     *
     * The flag is the whole point of the split: from here on this flyer is the admin's,
     * and reset() must never delete it.
     */
    async function saveDraft(): Promise<number> {
        const id = await persistDraft();

        savedByAdmin.value = true;
        // Set here and not in persistDraft(): "saved at" means the admin saved, not
        // that the store created a row behind their back to park a photo on.
        savedAt.value = new Date().toISOString();

        return id;
    }

    /** Create or update the row. */
    async function persistDraft(): Promise<number> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const body = payload();
        if (!body) throw new Error('This design has not been set up on the server yet, so it cannot be saved.');

        saving.value = true;

        try {
            const res: AxiosResponse = flyerId.value
                ? await ApiService.put(route(`/api/admin/masjids/${id}/flyers/${flyerId.value}`), body)
                : await ApiService.post(route(`/api/admin/masjids/${id}/flyers`), body);

            if (res.data?.status === 'success' && res.data?.data) {
                const flyer = res.data.data as Flyer;
                flyerId.value = flyer.id;
                return flyer.id;
            }

            throw new Error('Failed to save the flyer.');
        } finally {
            saving.value = false;
        }
    }

    /**
     * Throw away the row ensureDraft() created behind the admin's back.
     *
     * That row exists for one reason — the cutout worker needs somewhere to hang the
     * photo — and the Studio has no flyer list to reach it from afterwards. Walking away
     * without deleting it leaves a row nobody can ever open, plus its source image and
     * its cut-out sitting on the private disk. DELETE /flyers/{id} takes the files with
     * it (FlyersController::destroy).
     *
     * A flyer the admin saved is theirs. It is never touched here.
     */
    function discardImplicitDraft(): void {
        const id = masjidId();
        const flyer = flyerId.value;

        if (!id || !flyer || savedByAdmin.value) return;

        ApiService.delete(route(`/api/admin/masjids/${id}/flyers/${flyer}`))
            .catch((e: Error) => {
                console.error('Flyer draft discard error: ', e);
            });
    }

    /** Leave the Studio the way it was found. */
    function reset(): void {
        stopPolling();
        discardImplicitDraft();

        selectedKey.value = null;
        flyerId.value = null;
        savedByAdmin.value = false;
        lastPhoto = null;
        content.value = {};
        images.value = {};
        title.value = '';
        cutoutStatus.value = 'none';
        cutoutError.value = null;
        cutoutAvailable.value = false;
        savedAt.value = null;
    }

    return {
        // state
        templates,
        templatesAvailable,
        theme,
        selectedKey,
        title,
        content,
        images,
        paletteKey,
        flyerId,
        cutoutStatus,
        cutoutError,
        cutoutAvailable,
        loading,
        uploading,
        saving,
        savedAt,
        // getters
        designs,
        design,
        manifest,
        slots,
        cutoutSlot,
        paletteOptions,
        palette,
        cssVars,
        paletteAudit,
        renderContent,
        missingRequired,
        isComplete,
        cutoutPending,
        canSave,
        // actions
        masjidId,
        fetchTemplates,
        fetchTheme,
        initialise,
        selectTemplate,
        setSlot,
        setListItems,
        addListItem,
        removeListItem,
        setImage,
        clearImage,
        useCutout,
        retryUpload,
        saveDraft,
        stopPolling,
        reset
    };
});
