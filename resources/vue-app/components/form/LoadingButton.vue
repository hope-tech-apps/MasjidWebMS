<template>
    <button :type="type" :class="`btn ${classes}`" :disabled="isLoading">
        <span v-if="!isLoading">
            <slot />
        </span>
        <span v-else class="d-flex align-items-center justify-content-center gap-2">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>Loading...</span>
        </span>
    </button>
</template>

<script setup lang="ts">
import { ButtonType } from '@/core/types/elements/Buttons';

const props = defineProps({
    type: {
        type: String as () => ButtonType,
        required: false,
        default: 'btn-success'
    },
    classes: {
        type: String,
        required: false,
        default: 'btn-success'
    },
    isLoading: {
        type: Boolean,
        required: false,
        default: false
    }
});

</script>