<template>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <label class="btn btn-sm btn-outline-secondary mb-0"
               :class="{ disabled: disabled || preparing || modelValue.length >= max }">
            <i class="bi bi-image me-1"></i>{{ modelValue.length ? 'Add more' : 'Add photos' }}
            <input type="file" class="d-none" multiple :accept="accept"
                   :disabled="disabled || preparing || modelValue.length >= max"
                   @change="onChosen">
        </label>

        <span v-if="preparing" class="small text-muted">
            <span class="spinner-border spinner-border-sm me-1"></span>Preparing…
        </span>

        <div v-for="(file, i) in modelValue" :key="previews[i] || i" class="photo-chip">
            <img v-if="previews[i]" :src="previews[i]" :alt="file.name">
            <button type="button" class="btn-close btn-close-white" :disabled="disabled"
                    :aria-label="`Remove ${file.name}`" @click="remove(i)"></button>
        </div>

        <span v-if="note" class="small text-muted w-100">{{ note }}</span>
    </div>
</template>

<script setup lang="ts">
import { preparePhoto } from '@/core/helpers/preparePhoto';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Choose photos to send with a class-story post or a message.
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
}>(), {
    max: 8,
    disabled: false,
    accept: 'image/jpeg,image/png,image/webp',
});

const emit = defineEmits<{ (e: 'update:modelValue', files: File[]): void }>();

const preparing = ref(false);
const note = ref('');
const previews = ref<string[]>([]);

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
    const room = props.max - props.modelValue.length;
    if (chosen.length > room) {
        note.value = `Up to ${props.max} photos at a time — the first ${Math.max(room, 0)} were added.`;
    }

    preparing.value = true;
    try {
        const prepared = await Promise.all(chosen.slice(0, Math.max(room, 0)).map(preparePhoto));
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
.photo-chip img {
    width: 100%;
    height: 100%;
    object-fit: cover;
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
