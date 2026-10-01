<template>
    <!--
        The words of one sent message, with an Edit control for its author
        (W7, 2026-10-01). Shared by the teacher screen and the office's
        Conversations tab so the two cannot drift.

        The default slot is the screen's own rendering of the body, so each
        keeps its look. Whether the control shows is the server's `can_edit`
        (author, open conversation, can still read it); a refusal that still
        arrives is shown here, in place, with the draft kept. `save` does the
        request and throws on failure.
    -->
    <div class="editable-message" :class="alignEnd ? 'text-end' : ''">
        <template v-if="!editing">
            <slot />
            <div v-if="canEdit" class="mt-1">
                <button type="button" class="btn btn-link btn-sm p-0 small" aria-label="Edit this message"
                        @click="start">Edit</button>
            </div>
        </template>

        <form v-else class="text-start" @submit.prevent="submit">
            <textarea ref="field" v-model="draft" class="form-control form-control-sm" rows="3" dir="auto"
                      aria-label="Edit message text" :maxlength="maxLength > 0 ? maxLength : undefined"
                      :disabled="saving" @keydown.esc.prevent="cancel"
                      @keydown.ctrl.enter.prevent="submit"></textarea>
            <div v-if="problem && draft !== original" class="text-danger small mt-1">{{ problem }}</div>
            <div v-if="error" class="alert alert-danger small py-1 px-2 mt-1 mb-0" role="alert">{{ error }}</div>
            <div class="d-flex gap-2 mt-2" :class="alignEnd ? 'justify-content-end' : ''">
                <button type="submit" class="btn btn-success btn-sm" aria-label="Save the edited message"
                        :disabled="saving || !saveable">
                    <span v-if="saving" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span v-else>Save</span>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" aria-label="Cancel editing"
                        :disabled="saving" @click="cancel">Cancel</button>
            </div>
        </form>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { canSaveEdit, editProblem } from '@/core/helpers/messageEdit';
import { serverMessage } from '@/core/helpers/serverMessage';

const props = withDefaults(defineProps<{
    /** The message as it reads now. */
    body: string | null | undefined;
    canEdit: boolean;
    /** The message carries a photo or video, so its words may be emptied. */
    hasMedia?: boolean;
    /** Does the request; resolves when saved, throws when refused. */
    save: (body: string) => Promise<unknown>;
    maxLength?: number;
    alignEnd?: boolean;
}>(), { hasMedia: false, maxLength: 0, alignEnd: false });

const editing = ref(false);
const draft = ref('');
const original = ref('');
const saving = ref(false);
const error = ref('');
const field = ref<HTMLTextAreaElement | null>(null);

const media = computed(() => ({ body: props.body, attachments: props.hasMedia ? [1] : [] }));
const problem = computed(() => editProblem(draft.value, media.value, props.maxLength));
const saveable = computed(() => canSaveEdit(draft.value, media.value, props.maxLength));

const start = async () => {
    original.value = props.body ?? '';
    draft.value = original.value;
    error.value = '';
    editing.value = true;
    await nextTick();
    field.value?.focus();
};

const cancel = () => {
    if (saving.value) return;
    editing.value = false;
    error.value = '';
};

const submit = async () => {
    if (saving.value || !saveable.value) return;
    saving.value = true;
    error.value = '';
    try {
        await props.save(draft.value.trim());
        editing.value = false;
    } catch (e) {
        // In place, with the draft kept: a refused edit must not cost the author their text.
        error.value = serverMessage(e, 'That edit could not be saved.');
    } finally {
        saving.value = false;
    }
};
</script>
