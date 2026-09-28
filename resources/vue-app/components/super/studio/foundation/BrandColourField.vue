<template>
    <div class="studio-field colour-field">
        <label :for="textId">{{ label }}</label>
        <div class="d-flex align-items-center gap-2">
            <label class="swatch" :class="{ empty: !modelValue }" :style="modelValue ? { backgroundColor: modelValue } : undefined"
                :title="modelValue ? `Pick ${label.toLowerCase()}` : `Choose ${label.toLowerCase()}`">
                <input type="color" class="swatch-input" :value="pickerValue" :disabled="disabled"
                    :aria-label="`${label} colour picker`" @input="pick" />
            </label>
            <input :id="textId" type="text" class="dashboard-input hex-input" :value="text" :disabled="disabled"
                placeholder="#RRGGBB" :maxlength="anyThemeForm ? 9 : 7" spellcheck="false" autocapitalize="off" @input="type" @blur="settle" />
            <button v-if="clearable && modelValue" type="button" class="btn btn-sm btn-link text-muted px-1" :disabled="disabled"
                @click="emit('update:modelValue', null)">
                Clear
            </button>
        </div>
        <p v-if="invalid" class="studio-error">{{ anyThemeForm ? 'Use a hex colour, like #0A3D62.' : 'Use six hex digits, like #0A3D62.' }}</p>
        <p v-else-if="!modelValue && emptyNote" class="studio-hint">{{ emptyNote }}</p>
        <div v-if="candidates.length" class="d-flex flex-wrap gap-1 mt-1" role="group" :aria-label="`Logo colours for ${label.toLowerCase()}`">
            <button v-for="colour in candidates" :key="colour" type="button" class="candidate"
                :class="{ chosen: colour.toLowerCase() === modelValue?.toLowerCase() }" :style="{ backgroundColor: colour }"
                :title="colour" :aria-label="`Use ${colour}`" :disabled="disabled" @click="emit('update:modelValue', colour)"></button>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * One brand colour: a picker, a hex field, and the logo's candidate colours as
 * one-click choices.
 *
 * A colour starts unchosen (R25) and is shown as an empty swatch, never as the
 * picker's black, so an unchosen colour cannot pass for a chosen one. Only a
 * complete #RRGGBB reaches the draft, the one form the server accepts; half a
 * hex code stays in the field until it is finished or put back.
 *
 * A live organisation may already store a #RGB or #RRGGBBAA (the theme save
 * takes both), so `anyThemeForm` accepts those too and shows the stored value
 * as it is; the picker, which only speaks six digits, gets a display copy.
 */
import { isHex6, isThemeHex } from '@/core/studio/foundationGate';
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    id: string;
    label: string;
    modelValue: string | null | undefined;
    candidates?: string[];
    clearable?: boolean;
    disabled?: boolean;
    emptyNote?: string;
    anyThemeForm?: boolean;
}>(), { candidates: () => [], clearable: false, disabled: false, emptyNote: '', anyThemeForm: false });

const emit = defineEmits<{ (event: 'update:modelValue', value: string | null): void }>();

const textId = `studio-colour-${props.id}`;
const text = ref(props.modelValue ?? '');
const invalid = ref(false);

const accepts = (value: string): boolean => (props.anyThemeForm ? isThemeHex(value) : isHex6(value));

/** The colour picker's own value: always #rrggbb (3 digits expanded, an alpha pair dropped). */
const pickerValue = computed(() => {
    const value = props.modelValue ?? '';
    if (isHex6(value)) return value;
    if (/^#[0-9a-fA-F]{3}$/.test(value)) return `#${value[1]}${value[1]}${value[2]}${value[2]}${value[3]}${value[3]}`.toLowerCase();
    if (/^#[0-9a-fA-F]{8}$/.test(value)) return value.slice(0, 7).toLowerCase();
    return '#000000';
});

watch(() => props.modelValue, (value) => {
    text.value = value ?? '';
    invalid.value = false;
});

function pick(event: Event) {
    emit('update:modelValue', (event.target as HTMLInputElement).value.toLowerCase());
}

function type(event: Event) {
    const value = (event.target as HTMLInputElement).value.trim();
    text.value = value;
    if (value === '') {
        invalid.value = false;
        emit('update:modelValue', null);
    } else if (accepts(value)) {
        invalid.value = false;
        emit('update:modelValue', value.toLowerCase());
    } else {
        invalid.value = value.length >= 7 && !(props.anyThemeForm && value.length < 9);
    }
}

/** Leaving an unfinished code shows the error, then the field goes back to the saved colour. */
function settle() {
    if (text.value !== '' && !accepts(text.value)) {
        invalid.value = true;
        text.value = props.modelValue ?? '';
    }
}
</script>

<style scoped>
.colour-field {
    min-width: 13rem;
}

.swatch {
    position: relative;
    display: inline-block;
    width: 2.5rem;
    height: 2.5rem;
    flex: 0 0 auto;
    border: 1px solid var(--input-border, #ccc);
    border-radius: .35rem;
    cursor: pointer;
    overflow: hidden;
}

.swatch.empty {
    background: repeating-linear-gradient(45deg, #fff, #fff 5px, #eee 5px, #eee 10px);
}

.swatch-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.hex-input {
    width: 8rem;
    font-family: monospace;
}

.candidate {
    width: 1.4rem;
    height: 1.4rem;
    border-radius: 50%;
    border: 1px solid rgba(0, 0, 0, .2);
    padding: 0;
}

.candidate.chosen {
    outline: 2px solid var(--cgreen, #01b151);
    outline-offset: 2px;
}
</style>
