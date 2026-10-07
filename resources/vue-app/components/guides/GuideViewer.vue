<template>
    <div class="guide-viewer" role="dialog" aria-modal="true" aria-label="Enlarged guide picture" @click.self="emit('close')">
        <div class="guide-viewer-panel">
            <div class="guide-viewer-controls">
                <button ref="closeButton" class="btn btn-light" type="button" @click="emit('close')">Close picture</button>
                <button ref="zoomButton" class="btn btn-light" type="button" :aria-pressed="String(zoomed)" @click="zoomed = !zoomed">{{ zoomed ? 'Fit picture' : 'Zoom picture' }}</button>
            </div>
            <div ref="imageRegion" class="guide-viewer-image" :class="{ 'is-zoomed': zoomed }" tabindex="0" role="region" aria-label="Picture; zoom to scroll for detail">
                <img :src="picture.src" :alt="picture.alt">
            </div>
            <p>{{ picture.alt }}</p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
const props = defineProps<{ picture: { src: string; alt: string; opener: HTMLElement } }>();
const emit = defineEmits<{ close: [] }>();
const closeButton = ref<HTMLButtonElement | null>(null);
const zoomButton = ref<HTMLButtonElement | null>(null);
const imageRegion = ref<HTMLElement | null>(null);
const zoomed = ref(false);
const key = (event: KeyboardEvent) => {
    if (event.key === 'Escape') { event.preventDefault(); emit('close'); }
    if (event.key === 'Tab') {
        const controls = [closeButton.value, zoomButton.value, imageRegion.value].filter(Boolean) as HTMLElement[];
        const current = controls.indexOf(document.activeElement as HTMLElement);
        event.preventDefault(); controls[(current + (event.shiftKey ? controls.length - 1 : 1)) % controls.length]?.focus();
    }
};
onMounted(() => { document.addEventListener('keydown', key); closeButton.value?.focus(); });
onBeforeUnmount(() => { document.removeEventListener('keydown', key); props.picture.opener.focus(); });
</script>

<style scoped>
.guide-viewer { position: fixed; inset: 0; z-index: 1100; background: #000d; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.guide-viewer-panel { color: var(--guide-ink, var(--mn-ink, #172b2a)); background: var(--guide-paper, var(--mn-surface, #fff)); padding: .75rem; border-radius: .75rem; width: min(100%, 1440px); min-width: 0; max-height: 100%; overflow: auto; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; gap: .75rem; justify-items: center; }
.guide-viewer-controls { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; }
.guide-viewer .guide-viewer-controls .btn { background: var(--guide-card, var(--mn-surface)); color: var(--guide-ink, var(--mn-ink)); border-color: var(--guide-line, var(--mn-line)); }
.guide-viewer .btn:focus-visible { outline: 3px solid var(--guide-ring, #168372); outline-offset: 3px; }
.guide-viewer-image { width: 100%; min-width: 0; max-height: 75vh; overflow: auto; touch-action: pan-x pan-y pinch-zoom; }
.guide-viewer-image img { display: block; margin: auto; max-width: 100%; max-height: 75vh; object-fit: contain; }
.guide-viewer-image.is-zoomed img { width: auto; max-width: none; max-height: none; }
.guide-viewer-image:focus-visible { outline: 3px solid var(--guide-ring, #168372); outline-offset: 3px; }
</style>
