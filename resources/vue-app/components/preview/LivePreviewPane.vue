<template>
    <div v-if="state !== 'disabled'" class="live-preview card h-100">
        <div class="card-header d-flex align-items-center flex-wrap gap-2 py-2">
            <i class="bi bi-eye"></i>
            <strong class="small">Live preview</strong>
            <span v-if="state === 'ready' && !published" class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                Unsaved changes shown here only
            </span>
            <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Preview width">
                <template v-for="d in DEVICES" :key="d.key">
                    <input
                        :id="`${uid}-${d.key}`"
                        v-model="device"
                        type="radio"
                        class="btn-check"
                        :name="`${uid}-device`"
                        :value="d.key"
                        autocomplete="off"
                    >
                    <label class="btn btn-outline-secondary" :for="`${uid}-${d.key}`" :title="`${d.label} (${d.width}px)`">
                        <i class="bi" :class="d.icon"></i>
                        <span class="visually-hidden">{{ d.label }}</span>
                    </label>
                </template>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" title="Reload preview" @click="open">
                <i class="bi bi-arrow-clockwise"></i>
                <span class="visually-hidden">Reload preview</span>
            </button>
        </div>
        <div ref="viewport" class="live-preview__viewport">
            <div v-if="state === 'loading'" class="live-preview__status text-muted small">
                <span class="spinner-border spinner-border-sm me-2"></span>Loading the site…
            </div>
            <div v-else-if="state === 'error'" class="live-preview__status small">
                <span class="text-danger me-2">The preview could not load.</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="open">Try again</button>
            </div>
            <iframe
                v-if="src"
                ref="frame"
                :key="src"
                :src="src"
                :style="frameStyle"
                title="Live preview of the public site"
                referrerpolicy="no-referrer"
            ></iframe>
        </div>
        <div class="card-footer small text-muted py-1">
            <template v-if="published">This is the page as visitors see it now.</template>
            <template v-else>Visitors see these changes only after you save. Saving publishes them at once.</template>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * The real public site, in preview mode, beside an editor (docs/live-preview.md).
 *
 * Give it the surface being edited, the page to show, and the editor's unsaved values
 * as `overrides`; it opens a preview session, frames the site, and re-sends `overrides`
 * (debounced) whenever they change and whenever the site reports it is ready — after the
 * first load and after each navigation inside the frame.
 *
 * It renders NOTHING when the API says preview is not enabled for this organisation, so
 * an editor that includes it looks exactly as before on an unconfigured deployment.
 *
 * Who it talks to: messages are accepted only from the preview origin the API named AND
 * only from this pane's own frame; messages are posted only to that origin, never '*'.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useMasjidStore } from '@/stores/masjidStore';
import {
    ADMIN_MESSAGE_SOURCE,
    PREVIEW_MESSAGE_SOURCE,
    requestPreviewSession,
    toPlainJson,
    type PreviewSurface,
} from '@/composables/useLivePreview';

const props = withDefaults(defineProps<{
    surface: PreviewSurface;
    path?: string;
    overrides?: Record<string, unknown>;
    /** Change it to open a fresh session and reload the frame (e.g. after a save). */
    reloadKey?: string | number;
    /** Showing the saved site rather than an editor's draft: changes the labels only. */
    published?: boolean;
}>(), {
    path: '/',
    overrides: () => ({}),
    reloadKey: 0,
    published: false,
});

const emit = defineEmits<{ ready: []; unavailable: [] }>();

const DEVICES = [
    { key: 'desktop', label: 'Desktop', width: 1280, icon: 'bi-display' },
    { key: 'tablet', label: 'Tablet', width: 834, icon: 'bi-tablet' },
    { key: 'phone', label: 'Phone', width: 390, icon: 'bi-phone' },
] as const;

const uid = `live-preview-${Math.random().toString(36).slice(2, 8)}`;
const masjidStore = useMasjidStore();

const state = ref<'loading' | 'ready' | 'error' | 'disabled'>('loading');
const src = ref('');
const origin = ref('');
const device = ref<(typeof DEVICES)[number]['key']>('desktop');
const frame = ref<HTMLIFrameElement | null>(null);
const viewport = ref<HTMLElement | null>(null);
const viewportWidth = ref(0);
const viewportHeight = ref(0);

let readyTimer: ReturnType<typeof setTimeout> | undefined;
let sendTimer: ReturnType<typeof setTimeout> | undefined;
let resizeObserver: ResizeObserver | undefined;
let generation = 0;

const deviceWidth = computed(() => DEVICES.find((d) => d.key === device.value)?.width ?? 1280);
const scale = computed(() => (viewportWidth.value > 0 ? Math.min(1, viewportWidth.value / deviceWidth.value) : 1));
const frameStyle = computed(() => ({
    width: `${deviceWidth.value}px`,
    height: `${Math.max(320, viewportHeight.value / scale.value)}px`,
    left: `${Math.max(0, (viewportWidth.value - deviceWidth.value * scale.value) / 2)}px`,
    transform: `scale(${scale.value})`,
    transformOrigin: 'top left',
    border: '0',
    background: '#fff',
    visibility: state.value === 'ready' ? 'visible' as const : 'hidden' as const,
}));

async function open() {
    const masjidId = masjidStore.masjid?.id;
    const mine = ++generation;
    clearTimeout(readyTimer);
    state.value = 'loading';
    src.value = '';

    if (!masjidId) {
        state.value = 'disabled';
        return;
    }

    try {
        const session = await requestPreviewSession(masjidId, props.surface, props.path);
        if (mine !== generation) return; // a newer open() superseded this one
        if (!session.enabled || !session.url || !session.origin) {
            state.value = 'disabled';
            emit('unavailable');
            return;
        }
        origin.value = session.origin;
        src.value = session.url;
        readyTimer = setTimeout(() => {
            if (mine === generation && state.value === 'loading') state.value = 'error';
        }, 20000);
    } catch {
        if (mine === generation) state.value = 'error';
    }
}

function send() {
    const target = frame.value?.contentWindow;
    if (state.value !== 'ready' || !target || !origin.value) return;
    target.postMessage(
        { source: ADMIN_MESSAGE_SOURCE, v: 1, type: 'overrides', overrides: toPlainJson(props.overrides) },
        origin.value,
    );
}

function onMessage(event: MessageEvent) {
    if (!frame.value || event.source !== frame.value.contentWindow || event.origin !== origin.value) return;
    const data = event.data;
    if (!data || data.source !== PREVIEW_MESSAGE_SOURCE || data.v !== 1 || data.type !== 'ready') return;
    clearTimeout(readyTimer);
    const first = state.value !== 'ready';
    state.value = 'ready';
    send();
    if (first) emit('ready');
}

watch(() => props.overrides, () => {
    clearTimeout(sendTimer);
    sendTimer = setTimeout(send, 150);
}, { deep: true });

watch(() => [props.surface, props.path, props.reloadKey, masjidStore.masjid?.id], () => { void open(); });

onMounted(() => {
    window.addEventListener('message', onMessage);
    if (viewport.value && 'ResizeObserver' in window) {
        resizeObserver = new ResizeObserver(([entry]) => {
            viewportWidth.value = entry.contentRect.width;
            viewportHeight.value = entry.contentRect.height;
        });
        resizeObserver.observe(viewport.value);
    }
    void open();
});

onBeforeUnmount(() => {
    generation++;
    window.removeEventListener('message', onMessage);
    resizeObserver?.disconnect();
    clearTimeout(readyTimer);
    clearTimeout(sendTimer);
});
</script>

<style scoped>
.live-preview__viewport {
    position: relative;
    height: 70vh;
    min-height: 420px;
    overflow: hidden;
    background: #f1f3f5;
}

.live-preview__status {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.live-preview iframe {
    position: absolute;
    top: 0;
}
</style>
