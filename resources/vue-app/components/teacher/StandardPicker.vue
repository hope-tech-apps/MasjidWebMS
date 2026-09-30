<template>
    <!--
        One standard from the school's OWN pacing guide, for a piece of work
        (T-001.1). Typing searches the guide by code or topic, through the same
        endpoint the lesson plan's Standard box uses; a pick is kept as a
        snapshot and shown as a chip with a way to clear it.

        It never offers anything the guide did not return. A subject the guide
        has no rows for (Arabic) answers "nothing in the pacing guide" and there
        is no way to type a standard of your own here: the server refuses one
        the guide does not name, so the box would only ever invite a rejection.
    -->
    <div class="position-relative">
        <div v-if="modelValue" class="d-flex align-items-start gap-2 border rounded px-2 py-1 bg-body-tertiary">
            <div class="flex-grow-1 small">
                <div class="d-flex gap-2 align-items-baseline flex-wrap">
                    <span class="fw-semibold">{{ modelValue.standard_code || 'No code' }}</span>
                    <span dir="auto">{{ modelValue.curriculum_focus }}</span>
                </div>
                <div class="text-muted sp-meta">
                    From the school's pacing guide<span v-if="modelValue.curriculum_week_no"> · Week {{ modelValue.curriculum_week_no }}</span>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-link text-danger p-0" aria-label="Remove the standard"
                    :disabled="disabled" @click="clear">Remove</button>
        </div>

        <template v-else>
            <input :id="inputId" type="text" maxlength="64" autocomplete="off"
                   class="form-control form-control-sm" :disabled="disabled"
                   placeholder="Type a code or topic, e.g. NF.1 or fractions"
                   role="combobox" aria-autocomplete="list"
                   :aria-expanded="state.open && state.matches.length > 0"
                   :aria-controls="`${inputId}-list`"
                   :aria-activedescendant="state.open && state.active >= 0 ? `${inputId}-opt-${state.active}` : undefined"
                   @input="onInput" @focus="onInput"
                   @compositionstart="state.composing = true" @compositionend="state.composing = false"
                   @keydown="search.onKey($event)" @blur="search.close()">

            <ul v-if="state.open && state.matches.length" :id="`${inputId}-list`" role="listbox"
                class="list-group position-absolute w-100 shadow sp-list">
                <template v-for="(m, i) in state.matches"
                          :key="`${m.grade_label}|${m.subject}|${m.standard_code}|${m.focus}|${m.objective ?? ''}`">
                    <li v-if="!m.in_scope && (i === 0 || state.matches[i - 1].in_scope)" role="presentation"
                        class="list-group-item py-1 px-2 text-uppercase text-muted fw-semibold sp-divider"
                        @mousedown.prevent>
                        Other grades and subjects
                    </li>
                    <!-- mousedown, not click: a click lands after the field's blur has closed the list. -->
                    <li :id="`${inputId}-opt-${i}`" role="option" :aria-selected="i === state.active"
                        class="list-group-item list-group-item-action py-1 px-2 small"
                        :class="{ 'bg-success-subtle': i === state.active }"
                        @mousedown.prevent="search.pick(m)" @mouseenter="state.active = i">
                        <div class="d-flex gap-2 align-items-baseline">
                            <span class="fw-semibold text-nowrap">{{ m.standard_code || 'No code' }}</span>
                            <span dir="auto">{{ m.focus }}</span>
                        </div>
                        <div v-if="m.objective" class="text-muted small" dir="auto">{{ m.objective }}</div>
                        <div class="text-muted sp-meta">{{ m.grade_label }} · {{ m.subject }} · {{ weeksLabel(m.weeks) }}</div>
                    </li>
                </template>
            </ul>
            <div v-else-if="state.open && state.emptyFor !== null && state.emptyFor === state.typed" class="form-text">
                Nothing in the pacing guide matches. A standard can only be one the school's guide names.
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { reactive } from 'vue';
import TeacherApiService from '@/core/services/TeacherApiService';
import {
    createStandardSearch,
    newStandardSearchState,
    weeksLabel,
    type StandardMatch,
} from '@/core/helpers/standardSearch';

/** The three snapshot fields a piece of work stores. */
interface PickedStandard {
    standard_code: string | null;
    curriculum_focus: string;
    curriculum_week_no: number | null;
}

const props = defineProps<{
    masjidId: number | string;
    modelValue: PickedStandard | null;
    /** The grade the class teaches, when it is one grade; ranks that grade's rows first. */
    grade?: string | null;
    /** The subject the work is filed under; ranks that subject's rows first. */
    subject?: string | null;
    inputId?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: PickedStandard | null): void }>();

const inputId = props.inputId ?? 'work-standard';
const state = reactive(newStandardSearchState());

const search = createStandardSearch(state, {
    fetch: async (q) => {
        const params = new URLSearchParams({ q });
        if (props.grade) params.set('grade', props.grade);
        if (props.subject) params.set('subject', props.subject);
        const res = await TeacherApiService.get(`/api/teacher/masjids/${props.masjidId}/curriculum/standards?${params}`);
        return (res.data?.data?.matches ?? []) as StandardMatch[];
    },
    onPick: (m) => emit('update:modelValue', {
        standard_code: m.standard_code ?? null,
        curriculum_focus: m.focus,
        curriculum_week_no: m.week_no ?? null,
    }),
});

// Read from the input itself, not a v-model: an on-screen keyboard composing a
// word does not update the model until the word ends.
const onInput = (e: Event) => search.onInput(String((e.target as HTMLInputElement | null)?.value ?? ''));

const clear = () => emit('update:modelValue', null);
</script>

<style scoped>
.sp-list { top: 100%; left: 0; z-index: 30; max-height: 18rem; overflow-y: auto; margin-top: 2px; }
.sp-list .list-group-item { cursor: pointer; }
.sp-meta { font-size: .75rem; }
.sp-divider { font-size: .65rem; letter-spacing: .04em; background: var(--bs-tertiary-bg); cursor: default; }
</style>
