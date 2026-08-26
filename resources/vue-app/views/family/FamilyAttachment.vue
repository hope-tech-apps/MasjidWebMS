<template>
    <a v-if="url" :href="url" target="_blank" rel="noopener" class="d-inline-block">
        <img :src="url" :alt="name || 'Attachment'" class="rounded border"
             style="max-height:140px;max-width:100%;object-fit:cover;">
    </a>
    <span v-else-if="failed" class="badge bg-light text-muted border">{{ name || 'Attachment' }}</span>
    <span v-else class="placeholder-glow d-inline-block" style="width:140px;height:100px;">
        <span class="placeholder w-100 h-100 rounded"></span>
    </span>
</template>

<script setup lang="ts">
import FamilyApiService from '@/core/services/FamilyApiService';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ src: string; name?: string | null }>();

const url = ref('');
const failed = ref(false);

/**
 * The API serves attachment BYTES behind the bearer token rather than a signed
 * URL — deliberately, so access dies with consent instead of outliving it. An
 * <img src> cannot carry an Authorization header, so the bytes are fetched and
 * turned into an object URL, which is revoked when this tile goes away.
 */
onMounted(async () => {
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
