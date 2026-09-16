<template>
    <a v-if="url" :href="url" target="_blank" rel="noopener" class="d-inline-block teacher-photo"
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
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * One class photo, fetched with the teacher's token.
 *
 * The photo is served as bytes behind the bearer token, never at a public URL,
 * so an <img src> cannot load it directly: the bytes are fetched, shown through
 * an object URL, and that URL is released when the tile goes away. Tapping it
 * opens the full photo in a new tab. The teacher-side twin of FamilyAttachment.
 */
const props = defineProps<{ src: string; name?: string | null }>();

const url = ref('');
const failed = ref(false);

onMounted(async () => {
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
</style>
