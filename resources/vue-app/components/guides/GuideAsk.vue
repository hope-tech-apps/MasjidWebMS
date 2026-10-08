<template>
    <section class="guide-ask" aria-labelledby="guide-ask-title">
        <h2 id="guide-ask-title">Ask the guide</h2>
        <form autocomplete="off" @submit.prevent="send">
            <label for="guide-question">Your question</label>
            <textarea id="guide-question" v-model="state.question" class="form-control" rows="2" required
                      :minlength="minChars" :maxlength="maxChars" :disabled="state.waiting" />
            <button class="btn btn-outline-secondary" type="submit" :disabled="state.waiting || state.question.trim().length < minChars">Ask</button>
        </form>
        <div role="status" aria-live="polite" aria-atomic="true">
            <p v-if="state.waiting">Asking the guide…</p>
            <p v-else-if="state.error" class="guide-answer">{{ state.error }}</p>
            <p v-else-if="state.result" class="guide-answer">{{ state.result.answer }}</p>
        </div>
        <template v-if="sources.length">
            <p>From the guide:</p>
            <ul><li v-for="task in sources" :key="`${task.kind}:${task.book}:${task.id}`">
                <a :href="path(task.book, task.kind === 'task' ? task.id : undefined, task.kind === 'question' ? task.id : undefined)" @click.prevent="navigate(path(task.book, task.kind === 'task' ? task.id : undefined, task.kind === 'question' ? task.id : undefined))">{{ task.title }}</a>
            </li></ul>
        </template>
    </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
interface Source { book: string; id: string; title: string }
interface Answer { answer: string; unknown: boolean; tasks: Source[]; questions: Source[] }
const props = defineProps<{
    state: { question: string; waiting: boolean; error: string; result: Answer | null };
    send: () => void;
    path: (book: string, task?: string, faq?: string) => string;
    navigate: (target: string) => void;
    minChars: number;
    maxChars: number;
}>();
const sources = computed(() => [
    ...(props.state.result?.tasks ?? []).map(task => ({ ...task, kind: 'task' })),
    ...(props.state.result?.questions ?? []).map(question => ({ ...question, kind: 'question' })),
]);
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
