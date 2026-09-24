<template>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <label class="btn btn-sm btn-outline-secondary mb-0"
               :class="{ disabled: disabled || preparing || full }">
            <i class="bi bi-image me-1"></i>{{ modelValue.length ? 'Add more' : addLabel }}
            <input type="file" class="d-none" multiple :accept="acceptAll"
                   :disabled="disabled || preparing || full"
                   @change="onChosen">
        </label>

        <span v-if="preparing" class="small text-muted">
            <span class="spinner-border spinner-border-sm me-1"></span>Preparing…
        </span>

        <div v-for="(file, i) in modelValue" :key="previews[i] || i" class="photo-chip">
            <!-- A video previews as a VIDEO. An <img> pointed at an .mp4 shows a
                 broken-image glyph and nothing says why, which is exactly the
                 failure this picker used to hand a teacher who chose a clip. -->
            <video v-if="previews[i] && isVideoFile(file)" :src="previews[i]" muted playsinline
                   preload="metadata" :aria-label="file.name"></video>
            <img v-else-if="previews[i]" :src="previews[i]" :alt="file.name">
            <span v-if="isVideoFile(file)" class="chip-badge"><i class="bi bi-camera-video"></i></span>
            <button type="button" class="btn-close btn-close-white" :disabled="disabled"
                    :aria-label="`Remove ${file.name}`" @click="remove(i)"></button>
        </div>

        <span v-if="note" class="small text-muted w-100">{{ note }}</span>
    </div>
</template>

<script setup lang="ts">
import { preparePhoto } from '@/core/helpers/preparePhoto';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Choose photos — and, since 2026-09-24, one video — to send with a
 * class-story post or a message.
 *
 * SHARED, and it lives in components/partials for that reason: the teacher
 * screens and the office conversations tab need the same control, and the second
 * copy of a picker is the one that stops getting the fix. It was
 * `views/teacher/TeacherPhotoPicker.vue` until the office side needed it too.
 *
 * Each chosen photo is shrunk and stripped of its metadata (location included)
 * before it is kept — see preparePhoto — so what the teacher previews here is
 * exactly what will be sent. The accept list mirrors the server's allowlist;
 * on an iPhone that also makes Safari hand over a JPEG instead of a HEIC.
 */
const props = withDefaults(defineProps<{
    modelValue: File[];
    max?: number;
    disabled?: boolean;
    accept?: string;
    /** Video allowlist and count, from the server's own `meta` (accepted_video_types / max_videos_per_*). */
    videoAccept?: string;
    maxVideos?: number;
}>(), {
    max: 8,
    disabled: false,
    accept: 'image/jpeg,image/png,image/webp',
    videoAccept: 'video/mp4,video/quicktime,video/webm',
    maxVideos: 1,
});

const emit = defineEmits<{ (e: 'update:modelValue', files: File[]): void }>();

const preparing = ref(false);
const note = ref('');
const previews = ref<string[]>([]);

/**
 * ONE control, TWO bags. The picker holds a single File[] and the CALLER splits
 * it into the server's `images` and `videos` keys on the way out — because the
 * two are validated against different allowlists, ceilings and counts, and a
 * teacher choosing "three photos and the recital" should not have to find two
 * buttons to do it.
 */
const isVideoFile = (file: File): boolean => (file.type || '').startsWith('video/');

const acceptAll = computed(() => [props.accept, props.videoAccept].filter(Boolean).join(','));

const counts = computed(() => {
    const videos = props.modelValue.filter(isVideoFile).length;
    return { videos, images: props.modelValue.length - videos };
});

// Full when NEITHER kind has room left; the per-kind ceilings are applied when
// files are chosen, so a teacher with their one video can still add photos.
const full = computed(() =>
    counts.value.images >= props.max && counts.value.videos >= props.maxVideos);

const addLabel = computed(() => (props.maxVideos > 0 ? 'Add photos or video' : 'Add photos'));

const release = () => {
    previews.value.forEach((url) => url && URL.revokeObjectURL(url));
    previews.value = [];
};

// Previews follow the list, including when the parent clears it after sending.
watch(() => props.modelValue, (files) => {
    release();
    previews.value = files.map((file) => URL.createObjectURL(file));
}, { immediate: true });

onBeforeUnmount(release);

const onChosen = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const chosen = Array.from(input.files ?? []);
    input.value = '';
    if (!chosen.length) return;

    note.value = '';

    // Per-KIND room, not one shared count: the ceilings differ by two orders of
    // magnitude (8 × 8MB against 1 × 100MB) and a shared count would let eight
    // videos through the client and be refused by the server after the upload.
    let imageRoom = Math.max(0, props.max - counts.value.images);
    let videoRoom = Math.max(0, props.maxVideos - counts.value.videos);

    const accepted: File[] = [];
    let droppedImages = 0;
    let droppedVideos = 0;

    for (const file of chosen) {
        if (isVideoFile(file)) {
            if (videoRoom > 0) { accepted.push(file); videoRoom--; } else { droppedVideos++; }
        } else if (imageRoom > 0) {
            accepted.push(file); imageRoom--;
        } else {
            droppedImages++;
        }
    }

    if (droppedImages) {
        note.value = `Up to ${props.max} photos at a time — the extra ${droppedImages} were not added.`;
    }
    if (droppedVideos) {
        note.value = [note.value, `Up to ${props.maxVideos} video at a time.`].filter(Boolean).join(' ');
    }

    preparing.value = true;
    try {
        // preparePhoto shrinks and strips metadata from IMAGES and returns
        // anything else untouched — which is the right behaviour for video and
        // the only one available: there is no in-browser transcoder here and no
        // ffmpeg on the server, so a clip is sent exactly as the phone made it.
        const prepared = await Promise.all(accepted.map(preparePhoto));
        emit('update:modelValue', [...props.modelValue, ...prepared]);
    } finally {
        preparing.value = false;
    }
};

const remove = (index: number) => {
    const next = props.modelValue.slice();
    next.splice(index, 1);
    note.value = '';
    emit('update:modelValue', next);
};
</script>

<style scoped>
.photo-chip {
    position: relative;
    width: 56px;
    height: 56px;
    border-radius: 0.375rem;
    overflow: hidden;
    background: var(--bs-light, #f1f3f5);
}
.photo-chip img,
.photo-chip video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.photo-chip .chip-badge {
    position: absolute;
    bottom: 2px;
    left: 3px;
    color: #fff;
    font-size: 0.7rem;
    text-shadow: 0 0 3px rgba(0, 0, 0, 0.9);
}
.photo-chip .btn-close {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 0.6rem;
    height: 0.6rem;
    padding: 0.2rem;
    background-color: rgba(0, 0, 0, 0.55);
    border-radius: 50%;
    opacity: 1;
}

/* A finger, not a cursor: the "Add photos" label is a 31px .btn-sm, and a <label>
   does not centre its content the way a <button> does, hence the flex. */
@media (max-width: 575.98px), (pointer: coarse) {
    label.btn {
        min-height: 44px;
        display: inline-flex;
        align-items: center;
    }
}
</style>
