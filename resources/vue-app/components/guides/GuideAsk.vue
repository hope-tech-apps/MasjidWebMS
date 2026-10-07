<template>
    <section class="guide-ask" aria-labelledby="guide-ask-title">
        <h2 id="guide-ask-title">Ask the guide</h2>
        <form autocomplete="off" @submit.prevent="send">
            <label for="guide-question">Your question</label>
            <textarea id="guide-question" v-model="question" class="form-control" rows="2" required
                      :minlength="minChars" :maxlength="maxChars" :disabled="waiting" />
            <button class="btn btn-outline-secondary" type="submit" :disabled="waiting || question.trim().length < minChars">Ask</button>
        </form>
        <div role="status" aria-live="polite" aria-atomic="true">
            <p v-if="waiting">Asking the guide…</p>
            <p v-else-if="error" class="guide-answer">{{ error }}</p>
            <p v-else-if="result" class="guide-answer">{{ result.answer }}</p>
        </div>
        <template v-if="result?.tasks.length">
            <p>From the guide:</p>
            <ul><li v-for="task in result.tasks" :key="`${task.book}:${task.id}`">
                <a :href="path(task.book, task.id)" @click.prevent="navigate(path(task.book, task.id))">{{ task.title }}</a>
            </li></ul>
        </template>
    </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
interface Answer { answer: string; unknown: boolean; tasks: { book: string; id: string; title: string }[] }
const props = defineProps<{
    request: (question: string, signal: AbortSignal) => Promise<Answer>;
    path: (book: string, task?: string) => string;
    navigate: (target: string) => void;
    minChars: number;
    maxChars: number;
}>();
const question = ref('');
const waiting = ref(false);
const error = ref('');
const result = ref<Answer | null>(null);
let controller: AbortController | null = null;
let active = true;
const send = async () => {
    if (waiting.value || question.value.trim().length < props.minChars || question.value.length > props.maxChars) return;
    waiting.value = true; error.value = ''; result.value = null;
    controller = new AbortController();
    try {
        const answer = await props.request(question.value, controller.signal);
        if (active) result.value = answer;
    } catch (failure) {
        if (active) error.value = failure instanceof Error ? failure.message : 'That did not work. Try again.';
    } finally { if (active) waiting.value = false; }
};
onBeforeUnmount(() => { active = false; controller?.abort(); question.value = ''; result.value = null; });
</script>

<style scoped>
.guide-ask { margin-bottom: 1rem; padding: .75rem; border: 1px solid var(--guide-line, var(--mn-line)); border-radius: .75rem; background: var(--guide-card, var(--mn-surface)); color: var(--guide-ink, var(--mn-ink)); }
.guide-ask h2 { font-size: 1.1rem; color: inherit; }
.guide-ask form { display: grid; gap: .5rem; }
.guide-ask textarea { width: 100%; min-width: 0; resize: vertical; }
.guide-ask .form-control, .guide-ask .btn { color: inherit; background: var(--guide-card, var(--mn-surface)); border-color: var(--guide-line, var(--mn-field)); }
.guide-ask .btn { justify-self: start; }
.guide-answer { white-space: pre-wrap; overflow-wrap: anywhere; margin: .75rem 0; }
.guide-ask a { color: var(--guide-accent, var(--mn-brand-strong)); overflow-wrap: anywhere; }
.guide-ask :focus-visible { outline: 3px solid var(--guide-ring, var(--mn-brand-strong)); outline-offset: 3px; }
</style>
