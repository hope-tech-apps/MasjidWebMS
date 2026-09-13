<template>
    <div>
        <label v-if="field.type !== 'checkbox'" class="form-label small mb-1" :for="inputId">
            {{ field.label || field.name }}
            <span v-if="field.required" class="text-danger">*</span>
        </label>

        <!-- Free text -->
        <textarea
            v-if="field.type === 'textarea'"
            :id="inputId"
            class="form-control"
            :class="{ 'is-invalid': !!error }"
            rows="3"
            :placeholder="field.placeholder || ''"
            :value="asText"
            @input="emitText($event)"
        ></textarea>

        <!-- One of N -->
        <select
            v-else-if="field.type === 'select'"
            :id="inputId"
            class="form-select"
            :class="{ 'is-invalid': !!error }"
            :value="asText"
            @change="emitText($event)"
        >
            <option value="">Choose…</option>
            <option v-for="option in field.options || []" :key="option.value" :value="option.value">
                {{ option.label || option.value }}
            </option>
        </select>

        <!-- One of N, spelled out -->
        <div v-else-if="field.type === 'radio'">
            <div v-for="option in field.options || []" :key="option.value" class="form-check">
                <input
                    class="form-check-input"
                    type="radio"
                    :id="`${inputId}-${option.value}`"
                    :name="inputId"
                    :value="option.value"
                    :checked="asText === option.value"
                    @change="emit('update:modelValue', option.value)"
                >
                <label class="form-check-label small" :for="`${inputId}-${option.value}`">
                    {{ option.label || option.value }}
                </label>
            </div>
        </div>

        <!-- Any of N. The answer is an ARRAY, and stays one even with a single tick. -->
        <div v-else-if="field.type === 'checkboxGroup'">
            <div v-for="option in field.options || []" :key="option.value" class="form-check">
                <input
                    class="form-check-input"
                    type="checkbox"
                    :id="`${inputId}-${option.value}`"
                    :value="option.value"
                    :checked="asList.includes(option.value)"
                    @change="toggleMember(option.value, ($event.target as HTMLInputElement).checked)"
                >
                <label class="form-check-label small" :for="`${inputId}-${option.value}`">
                    {{ option.label || option.value }}
                </label>
            </div>
        </div>

        <!-- A single tick. A REQUIRED one is a waiver: the server's rule is `accepted`. -->
        <div v-else-if="field.type === 'checkbox'" class="form-check">
            <input
                class="form-check-input"
                type="checkbox"
                :id="inputId"
                :class="{ 'is-invalid': !!error }"
                :checked="modelValue === true"
                @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
            >
            <label class="form-check-label small" :for="inputId">
                {{ field.label || field.name }}
                <span v-if="field.required" class="text-danger">*</span>
            </label>
        </div>

        <!--
            A FILE QUESTION IS NOT ASKED HERE, and saying so is the point.

            The public intake posts uploads in their own multipart bag
            (FormSchema::UPLOAD_KEY); this modal posts JSON, so there is nowhere
            for the bytes to go. Rendering a file input that quietly discarded
            the file — or, worse, one that made the admin believe a medical form
            was on record — is the failure this whole screen exists to avoid.
            The parent blocks submission when such a field is REQUIRED.
        -->
        <div v-else-if="field.type === 'file'" class="form-text">
            <i class="bi bi-paperclip me-1"></i>
            An upload cannot be attached from this screen. Collect it separately and
            add it to the family's record.
        </div>

        <!-- text / email / tel / number / date -->
        <input
            v-else
            :id="inputId"
            class="form-control"
            :class="{ 'is-invalid': !!error }"
            :type="htmlType"
            :placeholder="field.placeholder || ''"
            :min="field.min ?? undefined"
            :max="field.max ?? undefined"
            :value="asText"
            @input="emitText($event)"
        >

        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
        <div v-else-if="field.help" class="form-text">{{ field.help }}</div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { FormField } from '@/core/types/data/masjid-related/Form';

/**
 * One question from an offering's intake form, drawn for an administrator
 * entering a registration by hand (T-041i).
 *
 * A DRAWING, NEVER A JUDGEMENT. Nothing here validates: `required`, the option
 * lists, the number bounds and the conditional "required if any attendee is
 * under 18" are all enforced by App\Support\FormSchema against the form's
 * STORED schema, on the same code path the public intake uses. What this
 * component adds is a red asterisk and the server's own error text under the
 * field that earned it — a convenience, exactly as the public renderer's
 * client-side checks are. Re-implementing a rule here would give the office two
 * answers to "is this answer acceptable", and the browser's would be the one
 * that was wrong.
 *
 * The types it draws are `FormSchema::FIELD_TYPES` and nothing else, because a
 * form cannot be saved carrying a type outside that list.
 */
const props = defineProps<{
    field: FormField;
    modelValue: unknown;
    /** The server's message for this field, when the last submit named it. */
    error?: string;
    /** Unique per rendered instance — a repeatable section draws the same field N times. */
    inputId: string;
}>();

const emit = defineEmits<{ (event: 'update:modelValue', value: unknown): void }>();

/**
 * `date` maps to a native date input because the server's rule is a real date;
 * `number` likewise. Everything else is text-shaped — including `email` and
 * `tel`, whose HTML types only change the mobile keyboard.
 */
const htmlType = computed<string>(() => {
    switch (props.field.type) {
        case 'number': return 'number';
        case 'date': return 'date';
        case 'email': return 'email';
        case 'tel': return 'tel';
        default: return 'text';
    }
});

const asText = computed<string>(() =>
    props.modelValue === null || props.modelValue === undefined ? '' : String(props.modelValue)
);

const asList = computed<string[]>(() =>
    Array.isArray(props.modelValue) ? (props.modelValue as string[]) : []
);

const emitText = (event: Event): void => {
    emit('update:modelValue', (event.target as HTMLInputElement | HTMLTextAreaElement).value);
};

/**
 * A checkboxGroup's answer is an ARRAY even when one box is ticked, because
 * that is what the server validates it as — its members are each checked
 * against the declared options, and a bare string would fail the array rule.
 */
const toggleMember = (value: string, checked: boolean): void => {
    const next = asList.value.filter((member) => member !== value);
    if (checked) next.push(value);
    emit('update:modelValue', next);
};
</script>
