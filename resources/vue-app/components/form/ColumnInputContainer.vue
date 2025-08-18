<template>
    <div class="d-flex flex-column input-group">
        <label for="email_input" class="">{{ label }}</label>
        <div class="d-flex flex-column">
            <slot />
            <ErrorMessage v-if="show_error" :name="name" class="error-message" />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ErrorMessage } from 'vee-validate';

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