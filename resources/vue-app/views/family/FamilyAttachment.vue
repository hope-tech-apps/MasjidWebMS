<template>
    <!-- A parent uploads nothing, but must be able to WATCH. The element issues
         its own ranged requests, so it gets a short-lived signed ticket rather
         than the bearer-token blob a photo gets. -->
    <video v-if="isVideo && videoSrc" class="rounded border family-video"
           controls preload="metadata" playsinline
           :src="videoSrc" :title="name || t('attachment')" :lang="lang" :dir="dir"
           @error="onVideoError"></video>
    <span v-else-if="isVideo && videoFailed" class="badge bg-light text-muted border" :lang="lang" :dir="dir">
        {{ name || t('attachment') }}
    </span>
    <span v-else-if="isVideo" class="placeholder-glow d-inline-block family-video" :lang="lang" :dir="dir">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>

    <a v-else-if="url" :href="url" target="_blank" rel="noopener" class="d-inline-block" :lang="lang" :dir="dir">
        <img :src="url" :alt="name || t('attachment')" class="rounded border"
             style="max-height:140px;max-width:100%;object-fit:cover;">
    </a>
    <span v-else-if="failed" class="badge bg-light text-muted border" :lang="lang" :dir="dir">
        {{ name || t('attachment') }}
    </span>
    <span v-else class="placeholder-glow d-inline-block" style="width:140px;height:100px;" :lang="lang" :dir="dir">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>
</template>

<script setup lang="ts">
import FamilyApiService from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
import { useVideoPlayback } from '@/core/helpers/videoPlayback';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    src: string;
    name?: string | null;
    mime?: string | null;
    isVideo?: boolean;
    playbackPath?: string | null;
}>();

/**
 * No language toggle here, unlike the five screens: this is a tile rendered many
 * times inside a post in the class story, and a toggle on each one would be four
 * buttons in a row of photos. It reads the shared language so its one word — the
 * fallback name for an attachment nobody titled — follows whatever the parent
 * chose on the class screen around it. The attachment's own `name` is what a
 * teacher called the file and is never translated.
 */
const { lang, dir, t } = useFamilyLang();

const isVideo = props.isVideo === true || String(props.mime ?? '').startsWith('video/');

/**
 * The API serves attachment BYTES behind the bearer token rather than a signed
 * URL — deliberately, so access dies with consent instead of outliving it. An
 * <img src> cannot carry an Authorization header, so a PHOTO's bytes are fetched
 * and turned into an object URL, which is revoked when this tile goes away.
 *
 * A VIDEO is the one exception, and it is bounded rather than granted: the
 * server mints a signed URL that lasts minutes, names this parent, and is
 * re-checked against consent on every ranged request it buys. See
 * core/helpers/videoPlayback and GroupMediaPlaybackController.
 */
const { src: videoSrc, failed: videoFailed, onError: onVideoError } = isVideo
    ? useVideoPlayback((path) => FamilyApiService.post(path), props.playbackPath)
    : { src: ref(''), failed: ref(false), onError: () => undefined };

const url = ref('');
const failed = ref(false);

onMounted(async () => {
    if (isVideo) return;

    try {
        url.value = await FamilyApiService.blobUrl(props.src);
    } catch {
        failed.value = true;
    }
});

onBeforeUnmount(() => {
    if (url.value) URL.revokeObjectURL(url.value);
});
</script>

<style scoped>
.family-video {
    width: 260px;
    max-width: 100%;
    background: #000;
}
</style>
