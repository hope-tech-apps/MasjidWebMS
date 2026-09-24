<template>
    <!-- A VIDEO plays in place. It cannot be an <img>, and it cannot be a blob
         fetched with the token: the element issues its own ranged requests, so
         it is given a short-lived signed URL instead. preload="metadata" fetches
         the first few hundred KB — enough for a duration and a first frame —
         rather than the whole 100MB. -->
    <video v-if="isVideo && videoSrc" class="rounded border teacher-video"
           controls preload="metadata" playsinline
           :src="videoSrc" :title="name || 'Video'"
           @error="onVideoError"></video>
    <span v-else-if="isVideo && videoFailed" class="badge bg-light text-muted border">
        <i class="bi bi-camera-video me-1"></i>{{ name || 'Video' }} (could not load)
    </span>
    <span v-else-if="isVideo" class="placeholder-glow d-inline-block teacher-video">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>

    <a v-else-if="url" :href="url" target="_blank" rel="noopener" class="d-inline-block teacher-photo"
       :title="name || 'Photo'">
        <img :src="url" :alt="name || 'Photo'" class="rounded border">
    </a>
    <span v-else-if="failed" class="badge bg-light text-muted border">
        <i class="bi bi-image me-1"></i>{{ name || 'Photo' }} (could not load)
    </span>
    <span v-else class="placeholder-glow d-inline-block teacher-photo">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>
</template>

<script setup lang="ts">
import TeacherApiService from '@/core/services/TeacherApiService';
import { useVideoPlayback } from '@/core/helpers/videoPlayback';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * One class attachment, as the teacher sees it.
 *
 * A PHOTO is served as bytes behind the bearer token, never at a public URL, so
 * an <img src> cannot load it directly: the bytes are fetched, shown through an
 * object URL, and that URL is released when the tile goes away.
 *
 * A VIDEO takes the other path, and has to — see core/helpers/videoPlayback.
 * Nothing here transcodes or thumbnails anything: the server has no ffmpeg, so
 * what the teacher's phone produced is what the browser is asked to play.
 *
 * The branch is on `mime_type` (via `is_video`), the ONLY discriminator the
 * attachment tables carry. Before this component learned to make it, a video
 * rendered as a silent broken image.
 */
const props = defineProps<{
    src: string;
    name?: string | null;
    mime?: string | null;
    isVideo?: boolean;
    playbackPath?: string | null;
}>();

const isVideo = props.isVideo === true || String(props.mime ?? '').startsWith('video/');

// Destructured at the top level of <script setup> so the template unwraps them
// like any other ref. A photo never asks for a ticket: the inert triple keeps
// the branch out of the template's expressions.
const { src: videoSrc, failed: videoFailed, onError: onVideoError } = isVideo
    ? useVideoPlayback((path) => TeacherApiService.post(path), props.playbackPath)
    : { src: ref(''), failed: ref(false), onError: () => undefined };

const url = ref('');
const failed = ref(false);

onMounted(async () => {
    if (isVideo) return;

    try {
        url.value = await TeacherApiService.blobUrl(props.src);
    } catch {
        failed.value = true;
    }
});

onBeforeUnmount(() => {
    if (url.value) URL.revokeObjectURL(url.value);
});
</script>

<style scoped>
.teacher-photo,
.teacher-photo img {
    width: 120px;
    height: 120px;
}
.teacher-photo img {
    object-fit: cover;
}
/* Wider than a photo tile: a 120px square video has no usable scrubber. */
.teacher-video {
    width: 260px;
    max-width: 100%;
    background: #000;
}
</style>
