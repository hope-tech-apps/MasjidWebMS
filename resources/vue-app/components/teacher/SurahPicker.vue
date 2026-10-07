<template>
    <!--
        The surah of a recitation, found by typing its number or part of its name
        (owner, 2026-10-07: "instead of having to scroll for the surah they want
        they should be able to type the surah number or part of the name").

        It only ever offers the rows the server's surah list returned, and it
        holds a surah NUMBER or nothing: text that is not a pick is never sent.
        Focusing the box still shows the whole list in mushaf order, so nothing a
        teacher could do with the old drop-down is lost.
    -->
    <div class="position-relative">
        <input :id="inputId" ref="box" type="text" autocomplete="off" autocapitalize="off" spellcheck="false"
               class="form-control form-control-sm" :class="{ 'is-invalid': !open && unresolved }" :disabled="disabled"
               :placeholder="surahs.length ? 'Type a number or part of the name' : 'Loading the surahs…'"
               role="combobox" aria-autocomplete="list"
               :aria-expanded="open && matches.length > 0"
               :aria-controls="`${inputId}-list`"
               :aria-activedescendant="open && active >= 0 && matches[active] ? `${inputId}-opt-${matches[active].number}` : undefined"
               :value="text"
               @input="onInput" @focus="onFocus" @keydown="onKey" @blur="onBlur"
               @compositionstart="composing = true" @compositionend="composing = false">

        <ul v-if="open && matches.length" :id="`${inputId}-list`" role="listbox"
            class="list-group position-absolute w-100 shadow surah-list">
            <!-- mousedown, not click: a click lands after the field's blur has closed the list. -->
            <li v-for="(s, i) in matches" :id="`${inputId}-opt-${s.number}`" :key="s.number" role="option"
                :aria-selected="s.number === modelValue"
                class="list-group-item list-group-item-action py-1 px-2 small"
                :class="{ 'bg-success-subtle': i === active, 'fw-semibold': s.number === modelValue }"
                @mousedown.prevent="pick(s)" @mouseenter="active = i">
                {{ surahLabel(s) }}
            </li>
        </ul>
        <div v-else-if="open && typed.trim()" class="form-text" role="status">
            No surah matches “{{ typed.trim() }}”. Type its number (1 to {{ surahs.length || 114 }}) or part of its name.
        </div>
        <!-- Left with text that names no one surah: the box holds NONE, and says so,
             rather than going back to the surah of the last recitation. -->
        <div v-else-if="!open && unresolved" class="form-text text-danger" role="alert">
            “{{ unresolved }}” is not one surah. Choose a surah from the list.
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { matchSurahs, surahLabel, surahOnLeave, type Surah } from '@/core/helpers/surahSearch';

const props = defineProps<{
    /** The server's list (`GET .../quran-surahs`), in mushaf order. */
    surahs: Surah[];
    /** The chosen surah's number, or null. */
    modelValue: number | null;
    inputId?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: number | null): void }>();

const inputId = props.inputId ?? 'surah';

const chosen = computed(() => props.surahs.find((s) => s.number === props.modelValue) ?? null);

/** What the teacher has typed since the box was focused; '' while it only shows the chosen surah. */
const typed = ref('');
/** What the box shows. */
const text = ref('');
const open = ref(false);
/** The highlighted row; -1 is none. */
const active = ref(-1);
/** An on-screen keyboard is composing a word: Enter belongs to it, not to the list. */
const composing = ref(false);
/** Text the box was left with that names no one surah; the box then holds none. */
const unresolved = ref('');
const box = ref<HTMLInputElement | null>(null);

const matches = computed(() => matchSurahs(props.surahs, typed.value));

const showChosen = () => { text.value = chosen.value ? surahLabel(chosen.value) : ''; };

// The chosen surah, or the list arriving after the box was drawn. Never while
// the teacher is typing: that would replace the letters under her fingers.
watch([chosen, () => props.surahs.length], () => {
    if (chosen.value) unresolved.value = '';
    // Text left unresolved stays in the box beside its message.
    if (!open.value && !unresolved.value) showChosen();
}, { immediate: true });

const reveal = () => nextTick(() => {
    const row = matches.value[active.value];
    if (row) document.getElementById(`${inputId}-opt-${row.number}`)?.scrollIntoView?.({ block: 'nearest' });
});

const onFocus = (e: Event) => {
    typed.value = '';
    unresolved.value = '';
    open.value = true;
    active.value = chosen.value ? matches.value.findIndex((s) => s.number === chosen.value?.number) : -1;
    // The chosen surah's words are selected, so the first key replaces them.
    (e.target as HTMLInputElement | null)?.select?.();
    reveal();
};

// Read from the input itself, not a v-model: an on-screen keyboard composing a
// word does not update the model until the word ends.
const onInput = (e: Event) => {
    const value = String((e.target as HTMLInputElement | null)?.value ?? '');
    typed.value = value;
    text.value = value;
    open.value = true;
    // The best match is highlighted, so "36" then Enter is the whole gesture.
    active.value = matches.value.length ? 0 : -1;
};

const pick = (s: Surah) => {
    emit('update:modelValue', s.number);
    typed.value = '';
    unresolved.value = '';
    open.value = false;
    active.value = -1;
    text.value = surahLabel(s);
    // A pick by Enter or by a tap leaves the cursor in the box. Its words are
    // selected again, so typing another surah replaces them instead of being
    // added to the end of this one's name.
    nextTick(() => { if (box.value && document.activeElement === box.value) box.value.select?.(); });
};

const close = () => {
    typed.value = '';
    unresolved.value = '';
    open.value = false;
    active.value = -1;
    showChosen();
};

const onKey = (e: KeyboardEvent) => {
    if (composing.value || e.isComposing) return;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open.value) { open.value = true; }
        const last = matches.value.length - 1;
        if (last < 0) return;
        active.value = e.key === 'ArrowDown'
            ? (active.value >= last ? 0 : active.value + 1)
            : (active.value <= 0 ? last : active.value - 1);
        reveal();
    } else if (e.key === 'Enter') {
        if (open.value && matches.value[active.value]) {
            // Enter chooses the surah; it must not also submit anything around the box.
            e.preventDefault();
            pick(matches.value[active.value]);
        }
    } else if (e.key === 'Escape') {
        if (open.value) {
            e.preventDefault();
            close();
        }
    }
};

/**
 * Leaving the box with text typed and no pick. An exact number, or the only
 * match, is taken (surahOnLeave). Anything less certain leaves the box holding
 * NO surah, with the text still in it and a sentence under it: going back to the
 * surah of the last recitation would let "Record" file this one under it. With
 * nothing typed, the box simply shows the surah it holds.
 */
const onBlur = () => {
    const left = open.value ? typed.value.trim() : '';
    if (!left) {
        close();
        return;
    }
    const certain = surahOnLeave(props.surahs, left);
    if (certain) {
        pick(certain);
        return;
    }
    unresolved.value = left;
    typed.value = '';
    open.value = false;
    active.value = -1;
    emit('update:modelValue', null);
};
</script>

<style scoped>
.surah-list { top: 100%; left: 0; z-index: 30; max-height: 16rem; overflow-y: auto; margin-top: 2px; }
.surah-list .list-group-item { cursor: pointer; }
</style>
