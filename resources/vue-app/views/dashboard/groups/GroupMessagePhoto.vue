<template>
    <a v-if="url" :href="url" target="_blank" rel="noopener" class="d-inline-block message-photo"
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
import { useGroupFeedStore } from '@/stores/masjid/groupFeedStore';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A photo a teacher sent in a conversation, as the office sees it.
 *
 * Same arrangement as the class-story thumbnails: the bytes sit behind the
 * authenticated `download_path`, so they are fetched with the admin token and
 * shown through an object URL that is released with the tile. The fetch is the
 * feed store's, because it is the same request against the same kind of file.
 */
const props = defineProps<{ src: string; name?: string | null }>();

const feedStore = useGroupFeedStore();
const url = ref('');
const failed = ref(false);

onMounted(async () => {
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
</style>
