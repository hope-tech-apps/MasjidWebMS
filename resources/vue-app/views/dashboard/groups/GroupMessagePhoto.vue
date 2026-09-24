<template>
    <video v-if="isVideo && videoSrc" class="rounded border message-video"
           controls preload="metadata" playsinline
           :src="videoSrc" :title="name || 'Video'"
           @error="onVideoError"></video>
    <span v-else-if="isVideo && videoFailed" class="badge bg-light text-muted border">
        <i class="bi bi-camera-video me-1"></i>{{ name || 'Video' }} (could not load)
    </span>
    <span v-else-if="isVideo" class="placeholder-glow d-inline-block message-video">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>

    <a v-else-if="url" :href="url" target="_blank" rel="noopener" class="d-inline-block message-photo"
       :title="name || 'Photo'">
        <img :src="url" :alt="name || 'Photo'" class="rounded border">
    </a>
    <span v-else-if="failed" class="badge bg-light text-muted border">
        <i class="bi bi-image me-1"></i>{{ name || 'Photo' }} (could not load)
    </span>
    <span v-else class="placeholder-glow d-inline-block message-photo">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>
</template>

<script setup lang="ts">
import ApiService from '@/core/services/ApiService';
import { useGroupFeedStore } from '@/stores/masjid/groupFeedStore';
import { useVideoPlayback } from '@/core/helpers/videoPlayback';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * An attachment a teacher sent in a conversation, as the office sees it.
 *
 * PHOTOS keep the arrangement the class-story thumbnails use: the bytes sit
 * behind the authenticated `download_path`, are fetched with the admin token,
 * and are shown through an object URL released with the tile.
 *
 * VIDEO cannot: a <video> element makes its own ranged requests and cannot send
 * a bearer token, so it is given a short-lived signed ticket instead. See
 * core/helpers/videoPlayback. The branch is on `mime_type` — an <img> pointed at
 * a video is a silent broken image, which is what this used to be.
 */
const props = defineProps<{
    src: string;
    name?: string | null;
    mime?: string | null;
    isVideo?: boolean;
    playbackPath?: string | null;
}>();

const isVideo = props.isVideo === true || String(props.mime ?? '').startsWith('video/');

const { src: videoSrc, failed: videoFailed, onError: onVideoError } = isVideo
    ? useVideoPlayback((path) => ApiService.VueApp.axios.post(path), props.playbackPath)
    : { src: ref(''), failed: ref(false), onError: () => undefined };

const feedStore = useGroupFeedStore();
const url = ref('');
const failed = ref(false);

onMounted(async () => {
    if (isVideo) return;

    try {
        url.value = await feedStore.attachmentObjectUrl(props.src);
    } catch {
        failed.value = true;
    }
});

onBeforeUnmount(() => {
    if (url.value) URL.revokeObjectURL(url.value);
});
</script>

<style scoped>
.message-photo,
.message-photo img {
    width: 96px;
    height: 96px;
}
.message-photo img {
    object-fit: cover;
}
/* A 96px square has no usable scrubber; a clip in a conversation gets width. */
.message-video {
    width: 240px;
    max-width: 100%;
    background: #000;
}
</style>
