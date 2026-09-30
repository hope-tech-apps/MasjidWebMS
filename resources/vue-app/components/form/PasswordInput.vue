<template>
    <div class="password-input-wrapper">
        <Field :name="name" :type="showPassword ? 'text' : 'password'" v-model="model"
            :class="inputClass" :placeholder="placeholder" :id="inputId"
            :autocomplete="autocomplete" :aria-describedby="describedBy"
            :aria-invalid="invalid ? 'true' : undefined"></Field>
        <button type="button" class="password-toggle" @click.prevent="showPassword = !showPassword"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            :title="showPassword ? 'Hide password' : 'Show password'">
            <i class="bi" :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"></i>
        </button>
    </div>
</template>

<script setup lang="ts">
import { Field } from 'vee-validate';
import { computed, ref } from 'vue';

const props = defineProps({
    name: {
        type: String,
        required: true
    },
    modelValue: {
        type: String,
        required: false,
        default: ''
    },
    placeholder: {
        type: String,
        required: false,
        default: '********'
    },
    inputClass: {
        type: String,
        required: false,
        default: 'dashboard-input'
    },
    // Optional, for screens that label the field with <label for> and want the
    // browser's password manager to know which password this is. Every existing
    // caller passes none of these and renders exactly as before.
    inputId: {
        type: String,
        required: false,
        default: undefined
    },
    autocomplete: {
        type: String,
        required: false,
        default: undefined
    },
    describedBy: {
        type: String,
        required: false,
        default: undefined
    },
    invalid: {
        type: Boolean,
        required: false,
        default: false
    }
});

const emit = defineEmits(['update:modelValue']);

const showPassword = ref(false);

const model = computed({
    get: () => props.modelValue,
    set: (val: string) => emit('update:modelValue', val)
});
</script>

<style scoped>
.password-input-wrapper {
    position: relative;
    width: 100%;
}

.password-input-wrapper :deep(input) {
    width: 100%;
    padding-right: 2.5rem;
}

.password-toggle {
    position: absolute;
    right: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    padding: 0.25rem;
    cursor: pointer;
    color: #6c757d;
    display: flex;
    align-items: center;
}

.password-toggle:hover {
    color: #495057;
}
</style>
