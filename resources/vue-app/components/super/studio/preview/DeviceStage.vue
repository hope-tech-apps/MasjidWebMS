<template>
    <div ref="stage" class="device-stage" :style="{ height: `${Math.round(height * scale)}px` }">
        <div class="device-canvas" role="group" :aria-label="label"
            :style="{ width: `${width}px`, height: `${height}px`, left: `${offset}px`, transform: `scale(${scale})` }">
            <slot />
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * A device drawn at its real size (1280×800, 393×852, …) and scaled down to
 * the width it is given, so a frame's layout is the device's, not the column's.
 *
 * It measures SYNCHRONOUSLY in onMounted, then again whenever a
 * ResizeObserver reports a change. requestAnimationFrame and ResizeObserver may
 * never fire while the pane is hidden (the preview column folds away below xl,
 * and a background tab or a hidden browser pane stops both), so a stage that
 * waited for either would stay at scale 0 until something else moved it. A
 * width of 0 means "not laid out yet": the last known scale is kept rather
 * than collapsing the frame to nothing.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    /** The device's width in CSS pixels. */
    width: number;
    /** The device's height in CSS pixels. */
    height: number;
    /** What the frame shows, for assistive technology. */
    label: string;
    /** The largest scale drawn, so a phone does not fill a wide column at full height. */
    maxScale?: number;
}>();

const stage = ref<HTMLElement | null>(null);

/** Until the first measurement: small enough that nothing overflows a narrow column. */
const scale = ref(0.25);

/** Left margin that centres a device narrower than the stage. */
const offset = ref(0);

let observer: ResizeObserver | null = null;

function measure() {
    const available = stage.value?.clientWidth ?? 0;
    if (available <= 0) return;
    scale.value = Math.min(props.maxScale ?? 1, available / props.width);
    offset.value = Math.max(0, Math.floor((available - props.width * scale.value) / 2));
}

// The web frame switches between desktop and phone widths in place.
watch(() => [props.width, props.height, props.maxScale], () => measure());

onMounted(() => {
    measure();
    if (typeof ResizeObserver !== 'undefined' && stage.value) {
        observer = new ResizeObserver(() => measure());
        observer.observe(stage.value);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

defineExpose({ measure });
</script>

<style scoped>
.device-stage {
    position: relative;
    width: 100%;
    overflow: hidden;
}

.device-canvas {
    position: absolute;
    top: 0;
    left: 0;
    transform-origin: top left;
    overflow: hidden;
}
</style>
