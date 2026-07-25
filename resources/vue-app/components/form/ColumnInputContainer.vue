<template>
    <div class="d-flex flex-column input-group">
        <label :for="inputId" class="">{{ label }}</label>
        <div ref="controlWrapper" class="d-flex flex-column">
            <slot :input-id="inputId" />
            <ErrorMessage v-if="show_error" :name="name" class="error-message" />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ErrorMessage } from 'vee-validate';
import { onMounted, ref } from 'vue';

// Unique id per instance so the label is programmatically associated with its
// input. Exposed as a scoped-slot prop (`input-id`) for explicit binding, and
// also applied to the first native form control found in the slot on mount so
// existing consumers get labeled without any template changes.
const inputId = `column-input-${Math.random().toString(36).slice(2, 9)}`;
const controlWrapper = ref<HTMLElement>();

onMounted(() => {
    const control = controlWrapper.value?.querySelector('input, select, textarea');
    if (control && !control.id) {
        control.id = inputId;
    }
});

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    name: {
        type: String,
        required: true
    },
    show_error: {
        type: Boolean,
        required: false,
        default: false
    },
})

const { label, name, show_error } = props
</script>

<style scoped>
input:focus {
    background-color: blueviolet !important;
}
</style>