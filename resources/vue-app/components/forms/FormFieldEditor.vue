<template>
    <div class="card mb-2 field-card">
        <div class="card-body">
            <!-- Header: position, type summary, reorder / remove -->
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                <div class="text-truncate">
                    <span class="badge bg-secondary me-2">Q{{ index + 1 }}</span>
                    <span class="text-muted small">{{ typeLabel }}</span>
                    <span v-if="field.required" class="badge bg-danger-subtle text-danger border border-danger-subtle ms-2">Required</span>
                    <span v-if="field.requiredIf" class="badge bg-warning-subtle text-dark border ms-2">Conditional</span>
                </div>
                <div class="btn-group flex-shrink-0">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        @click="emit('move-up')"
                        :disabled="index === 0"
                        title="Move Up"
                    >
                        <i class="bi bi-arrow-up"></i>
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        @click="emit('move-down')"
                        :disabled="index === total - 1"
                        title="Move Down"
                    >
                        <i class="bi bi-arrow-down"></i>
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm btn-danger"
                        @click="emit('remove')"
                        title="Remove Question"
                    >
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>

            <div class="row">
                <!-- Label -->
                <div class="col-md-7 mb-3">
                    <label class="form-label">Question <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        class="form-control"
                        :value="field.label"
                        @input="emit('label-input', ($event.target as HTMLInputElement).value)"
                        placeholder="e.g. Full name"
                    />
                </div>

                <!-- Type -->
                <div class="col-md-5 mb-3">
                    <label class="form-label">Answer type</label>
                    <select
                        class="form-select"
                        :value="field.type"
                        @change="emit('type-change', ($event.target as HTMLSelectElement).value as FormFieldType)"
                    >
                        <option v-for="type in fieldTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>

                <!-- Name (auto-derived from the label, but editable) -->
                <div class="col-md-7 mb-3">
                    <label class="form-label">Answer key</label>
                    <input
                        type="text"
                        class="form-control form-control-sm font-monospace"
                        :class="{ 'is-invalid': !!nameProblem }"
                        v-model.trim="field.name"
                    />
                    <div v-if="nameProblem" class="invalid-feedback d-block">{{ nameProblem }}</div>
                    <small v-else class="form-text text-muted">
                        Follows the question until you edit it. Letters, numbers and underscores,
                        starting with a letter. Renaming it after responses arrive orphans the
                        answers already stored under the old key.
                    </small>
                </div>

                <!-- Required -->
                <div class="col-md-5 mb-3">
                    <label class="form-label d-block">Answer required?</label>
                    <div class="form-check form-switch mt-2">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            :id="`${idPrefix}_required`"
                            v-model="field.required"
                        />
                        <label class="form-check-label" :for="`${idPrefix}_required`">
                            {{ requiredLabel }}
                        </label>
                    </div>
                </div>
            </div>

            <!-- Options (choice questions only) -->
            <div v-if="hasOptions" class="mb-3 border-top pt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Choices <span class="text-danger">*</span></label>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addOption">
                        <i class="bi bi-plus-circle"></i> Add Choice
                    </button>
                </div>

                <div
                    v-for="(option, optionIndex) in options"
                    :key="optionIndex"
                    class="row g-2 align-items-end mb-2"
                >
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Shown to the visitor</label>
                        <input
                            type="text"
                            class="form-control form-control-sm"
                            :value="option.label"
                            @input="onOptionLabelInput(optionIndex, ($event.target as HTMLInputElement).value)"
                            placeholder="e.g. Brothers"
                        />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Stored value</label>
                        <input
                            type="text"
                            class="form-control form-control-sm font-monospace"
                            v-model.trim="option.value"
                        />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Detail (optional)</label>
                        <input
                            type="text"
                            class="form-control form-control-sm"
                            v-model="option.detail"
                            placeholder="e.g. Send to (336) 350-1642"
                        />
                    </div>
                    <div class="col-md-1 d-flex justify-content-end">
                        <div class="btn-group">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="moveOption(optionIndex, -1)"
                                :disabled="optionIndex === 0"
                                title="Move Up"
                            >
                                <i class="bi bi-arrow-up"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                @click="removeOption(optionIndex)"
                                title="Remove Choice"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="options.length === 0" class="alert alert-warning py-2 mb-0 small">
                    A choice question needs at least one choice — the form cannot be saved without one.
                </div>
            </div>

            <!-- Number bounds -->
            <div v-if="field.type === 'number'" class="row border-top pt-3">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Minimum</label>
                    <input
                        type="number"
                        class="form-control"
                        :value="field.min ?? ''"
                        @input="field.min = toNumberOrNull(($event.target as HTMLInputElement).value)"
                        placeholder="No minimum"
                    />
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Maximum</label>
                    <input
                        type="number"
                        class="form-control"
                        :value="field.max ?? ''"
                        @input="field.max = toNumberOrNull(($event.target as HTMLInputElement).value)"
                        placeholder="No maximum"
                    />
                </div>
            </div>

            <!-- Advanced -->
            <button
                type="button"
                class="btn btn-link btn-sm px-0 text-decoration-none"
                @click="showAdvanced = !showAdvanced"
            >
                <i class="bi" :class="showAdvanced ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                Help text, legal text{{ conditionalSources.length ? ', conditional rule' : '' }}
            </button>

            <div v-if="showAdvanced" class="row border-top pt-3 mt-1">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Help text</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        v-model="field.help"
                        placeholder="Shown under the question"
                    />
                </div>

                <div class="col-md-6 mb-3" v-if="supportsPlaceholder">
                    <label class="form-label">Placeholder</label>
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        v-model="field.placeholder"
                        placeholder="Shown inside the empty box"
                    />
                </div>

                <div class="col-md-6 mb-3" v-if="supportsPlaceholder">
                    <label class="form-label">Browser autofill</label>
                    <select class="form-select form-select-sm" v-model="field.autocomplete">
                        <option :value="null">None</option>
                        <option v-for="token in autocompleteTokens" :key="token" :value="token">{{ token }}</option>
                        <option v-if="hasCustomAutocomplete" :value="field.autocomplete">{{ field.autocomplete }}</option>
                    </select>
                    <small class="form-text text-muted">Lets a phone fill this in from the visitor's saved contact details.</small>
                </div>

                <div class="col-12 mb-3">
                    <label class="form-label">Full legal text (optional)</label>
                    <textarea
                        class="form-control form-control-sm"
                        v-model="field.bodyText"
                        rows="4"
                        placeholder="Waiver, medical authorisation, code of conduct — shown behind a 'read full text' disclosure next to the question."
                    ></textarea>
                </div>

                <!-- Conditional requirement -->
                <div class="col-12 mb-2" v-if="conditionalSources.length">
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            :id="`${idPrefix}_conditional`"
                            :checked="!!field.requiredIf"
                            @change="onConditionalToggle(($event.target as HTMLInputElement).checked)"
                        />
                        <label class="form-check-label" :for="`${idPrefix}_conditional`">
                            Require this only when an entry is below a number
                        </label>
                    </div>
                    <small class="form-text text-muted d-block mb-2">
                        For example: a guardian's name is required only when any attendee is under 18.
                    </small>

                    <div v-if="field.requiredIf" class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">In this repeating section</label>
                            <select
                                class="form-select form-select-sm"
                                :value="field.requiredIf?.section"
                                @change="onConditionalSectionChange(($event.target as HTMLSelectElement).value)"
                            >
                                <option v-for="source in conditionalSources" :key="source.id" :value="source.id">
                                    {{ source.title || source.id }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1">When this number question</label>
                            <select
                                class="form-select form-select-sm"
                                :value="field.requiredIf?.field"
                                @change="setConditionalField(($event.target as HTMLSelectElement).value)"
                            >
                                <option
                                    v-for="numberField in conditionalFields"
                                    :key="numberField.name"
                                    :value="numberField.name"
                                >
                                    {{ numberField.label || numberField.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Is under</label>
                            <input
                                type="number"
                                class="form-control form-control-sm"
                                :value="field.requiredIf?.value"
                                @input="setConditionalValue(($event.target as HTMLInputElement).value)"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import {
    CHOICE_FIELD_TYPES,
    FormField,
    FormFieldOption,
    FormFieldType,
    FormFieldTypeInfo,
    deriveFormIdentifier,
    uniqueFormIdentifier
} from '@/core/types/data/masjid-related/Form';
import { computed, ref } from 'vue';

/**
 * One question inside the form builder.
 *
 * The field object is mutated in place (the same pattern GridCardsSectionEditor uses for
 * its cards). Anything that needs knowledge of the SIBLING questions — deriving a unique
 * answer key from the label, resetting options when the type changes — is emitted up to
 * FormBuilder, which owns the whole schema and is the only place naming rules live.
 */
const props = defineProps<{
    field: FormField;
    index: number;
    total: number;
    fieldTypes: FormFieldTypeInfo[];
    /** Unique per field — ids for the checkbox/label pairs. */
    idPrefix: string;
    /** Set by the parent when this question's answer key is illegal or duplicated. */
    nameProblem?: string | null;
    /**
     * Repeatable sections this question may point a conditional rule at. Empty when the
     * form has none, or when this question is itself inside the repeatable section —
     * the backend only evaluates conditionals on flat questions.
     */
    conditionalSources: { id: string; title: string; fields: { name: string; label: string }[] }[];
}>();

const emit = defineEmits<{
    'label-input': [value: string];
    'type-change': [value: FormFieldType];
    'move-up': [];
    'move-down': [];
    remove: [];
}>();

// Common autocomplete tokens. A form imported with something else keeps its value via
// hasCustomAutocomplete rather than silently losing it.
const autocompleteTokens = [
    'name',
    'given-name',
    'family-name',
    'email',
    'tel',
    'street-address',
    'address-level2',
    'postal-code',
    'bday',
    'off'
];

const showAdvanced = ref(
    !!(props.field.help || props.field.placeholder || props.field.autocomplete || props.field.bodyText || props.field.requiredIf)
);

const typeLabel = computed(() =>
    props.fieldTypes.find(type => type.value === props.field.type)?.label || props.field.type
);

const hasOptions = computed(() => CHOICE_FIELD_TYPES.includes(props.field.type));

const options = computed<FormFieldOption[]>(() => props.field.options ?? []);

// A required checkbox means "must be ticked" server-side (`accepted`), not merely present.
const requiredLabel = computed(() => {
    if (!props.field.required) return 'Optional';
    return props.field.type === 'checkbox' ? 'Must be ticked' : 'Must be answered';
});

// A placeholder / autofill hint only makes sense on a typed-in answer.
const supportsPlaceholder = computed(() =>
    ['text', 'email', 'tel', 'number', 'date', 'textarea'].includes(props.field.type)
);

const hasCustomAutocomplete = computed(() =>
    !!props.field.autocomplete && !autocompleteTokens.includes(props.field.autocomplete)
);

const conditionalFields = computed(() => {
    const source = props.conditionalSources.find(candidate => candidate.id === props.field.requiredIf?.section);
    return source ? source.fields : [];
});

/** '' -> null so an emptied box clears the bound rather than storing 0 or NaN. */
const toNumberOrNull = (value: string): number | null => {
    if (value === null || value.trim() === '') return null;
    const parsed = Number(value);
    return Number.isNaN(parsed) ? null : parsed;
};

const addOption = () => {
    const list = props.field.options ?? [];
    list.push({ value: '', label: '', detail: null });
    props.field.options = list;
};

const removeOption = (index: number) => {
    props.field.options?.splice(index, 1);
};

const moveOption = (index: number, direction: number) => {
    const list = props.field.options;
    if (!list) return;

    const target = index + direction;
    if (target < 0 || target >= list.length) return;

    [list[target], list[index]] = [list[index], list[target]];
};

/**
 * The stored value follows the choice's wording until somebody edits it by hand, exactly
 * like the answer key follows the question. Values must be unique within the question —
 * the server rejects duplicates.
 */
const onOptionLabelInput = (index: number, label: string) => {
    const list = props.field.options;
    if (!list || !list[index]) return;

    const option = list[index];
    const wasAuto = !option.value || option.value === deriveFormIdentifier(option.label, 'option');

    option.label = label;

    if (wasAuto) {
        const taken = list.filter((_, i) => i !== index).map(other => other.value);
        option.value = uniqueFormIdentifier(deriveFormIdentifier(label, 'option'), taken);
    }
};

const onConditionalToggle = (checked: boolean) => {
    if (!checked) {
        props.field.requiredIf = null;
        return;
    }

    const source = props.conditionalSources[0];
    if (!source) return;

    props.field.requiredIf = {
        rule: 'anyEntryUnder',
        section: source.id,
        field: source.fields[0]?.name ?? '',
        value: 18
    };
};

const onConditionalSectionChange = (sectionId: string) => {
    const rule = props.field.requiredIf;
    if (!rule) return;

    const source = props.conditionalSources.find(candidate => candidate.id === sectionId);

    rule.section = sectionId;
    // The old question belongs to the old section, so re-point at the new one.
    rule.field = source?.fields[0]?.name ?? '';
};

const setConditionalField = (name: string) => {
    const rule = props.field.requiredIf;
    if (!rule) return;

    rule.field = name;
};

/** The threshold must stay numeric — the server rejects a rule without one. */
const setConditionalValue = (raw: string) => {
    const rule = props.field.requiredIf;
    if (!rule) return;

    rule.value = toNumberOrNull(raw) ?? 0;
};
</script>

<style scoped>
.field-card {
    border: 1px solid #dee2e6;
}

.field-card .card-body {
    background-color: #ffffff;
}
</style>
