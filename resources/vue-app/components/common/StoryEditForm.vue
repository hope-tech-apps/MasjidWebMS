<template>
    <!--
        Edit a story that has already gone out (W7-2a): its title and its text, in place.
        Not ScheduledItems' editor, which always shows a send time. It makes no request: it
        raises `save` with the draft and `cancel`, and the screen's own client does the call.
        The error is the server's, in words, and the form stays open so nothing typed is lost.
        Photos and videos are not changed here.
    -->
    <form class="story-edit-form" data-test="story-edit-form" @submit.prevent="onSave">
        <input v-model="draft.heading" type="text" maxlength="255" class="form-control form-control-sm mb-2"
               placeholder="Title (optional)" aria-label="Title">
        <textarea v-model="draft.body" rows="4" class="form-control form-control-sm mb-2" aria-label="Story text"></textarea>
        <p class="small text-muted mb-2">Families will see that this story was edited. Nobody is notified.</p>
        <p v-if="error" class="small text-danger mb-2" role="alert" data-test="story-edit-error">{{ error }}</p>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-success" :disabled="busy || !ready" aria-label="Save changes to this story">
                {{ busy ? 'Saving…' : 'Save' }}
            </button>
            <button type="button" class="btn btn-sm btn-link text-muted" :disabled="busy" aria-label="Stop editing this story" @click="$emit('cancel')">
                Cancel editing
            </button>
        </div>
    </form>
</template>

<script setup lang="ts">
import { computed, reactive } from 'vue';
import { draftOf, storyEditReady, type EditableStory } from '@/core/helpers/storyEdit';

const props = defineProps<{
    /** The story as it stands: the draft starts from it and "changed" is judged against it. */
    post: EditableStory;
    busy?: boolean;
    /** Why the last save failed, in words. */
    error?: string;
}>();

const emit = defineEmits<{
    (e: 'save', draft: { heading: string; body: string }): void;
    (e: 'cancel'): void;
}>();

const draft = reactive(draftOf(props.post));

const ready = computed(() => storyEditReady(draft, props.post));

const onSave = () => {
    if (ready.value && !props.busy) emit('save', { heading: draft.heading, body: draft.body });
};
</script>
