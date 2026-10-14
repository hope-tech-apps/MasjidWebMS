<template>
    <div v-if="field.type === 'checkbox'" class="mb-3">
        <label><input type="checkbox" :checked="!!modelValue" @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)" :required="field.required" /> {{ field.label }}</label>
    </div>
    <fieldset v-else-if="field.type === 'radio' || field.type === 'checkboxGroup'" class="mb-3">
        <legend class="form-label fs-6">{{ field.label }}</legend>
        <label v-for="(option, index) in field.options || []" :key="index" class="d-block">
            <input v-if="field.type === 'radio'" type="radio" :name="inputId" :checked="modelValue === value(option)" @change="emit('update:modelValue', value(option))" :required="field.required" />
            <input v-else type="checkbox" :checked="Array.isArray(modelValue) && modelValue.includes(value(option))" @change="toggle(value(option), ($event.target as HTMLInputElement).checked)" /> {{ label(option) }}
        </label>
    </fieldset>
    <div v-else class="mb-3">
        <label :for="inputId" class="form-label">{{ field.label }}<span v-if="field.required"> *</span></label>
        <select v-if="field.type === 'select'" :id="inputId" class="form-select" :value="modelValue" @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)" :required="field.required">
            <option value=""></option><option v-for="(option, index) in field.options || []" :key="index" :value="value(option)">{{ label(option) }}</option>
        </select>
        <textarea v-else-if="field.type === 'textarea'" :id="inputId" class="form-control" :value="modelValue" @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)" :required="field.required" />
        <input v-else-if="field.type === 'file'" :id="inputId" class="form-control" type="file" :required="field.required" @change="emit('file', ($event.target as HTMLInputElement).files?.[0] ?? null)" />
        <input v-else :id="inputId" class="form-control" :type="field.type || 'text'" :value="modelValue" @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" :required="field.required" :min="field.min" :max="field.max" :step="field.step" />
        <small v-if="field.help" class="text-muted">{{ field.help }}</small>
    </div>
</template>
<script setup lang="ts">
const props = defineProps<{ field: any; modelValue: any; inputId: string }>();
const emit = defineEmits(['update:modelValue', 'file']);
const value = (option: any) => typeof option === 'object' ? option.value : option;
const label = (option: any) => typeof option === 'object' ? option.label : option;
function toggle(value: string, checked: boolean) { const values = Array.isArray(props.modelValue) ? props.modelValue.filter(v => v !== value) : []; if (checked) values.push(value); emit('update:modelValue', values); }
</script>
