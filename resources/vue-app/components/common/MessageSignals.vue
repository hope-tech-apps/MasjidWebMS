<template>
    <!--
        Reactions (🤲 👍 💯 ❓) and the read receipt under ONE message, shared by
        the teacher screen, the parent portal and the office's Conversations tab
        so the three cannot drift.

        Everything shown comes from the server's serializer
        (App\Support\GroupMessageSignals), which has already decided whose names
        this viewer may see — a parent is never sent another family's name — so
        this component renders and never filters.
    -->
    <div class="message-signals mt-1" :class="alignEnd ? 'text-end' : ''">
        <div v-if="reactions.length" class="d-inline-flex flex-wrap gap-1" :class="alignEnd ? 'justify-content-end' : ''"
             role="group" :aria-label="labels.reactionsGroup">
            <button v-for="r in reactions" :key="r.key" type="button"
                    class="btn btn-sm rounded-pill px-2 py-0 signal-pill"
                    :class="[r.mine ? 'btn-success-subtle border-success' : 'btn-light border', { 'signal-empty': !r.count }]"
                    :aria-pressed="r.mine ? 'true' : 'false'"
                    :aria-label="ariaFor(r)" :title="whoFor(r) || labelFor(r)"
                    :disabled="!canReact || busy === r.key"
                    @click="toggle(r)">
                <span aria-hidden="true">{{ r.emoji }}</span>
                <span v-if="r.count" class="ms-1 small">{{ r.count }}</span>
            </button>
        </div>

        <div v-if="whoLine" class="text-muted small mt-1" dir="auto">{{ whoLine }}</div>

        <div v-if="showReceipt" class="text-muted small mt-1" dir="auto">
            <i class="bi me-1" :class="readBy.length ? 'bi-check2-all text-success' : 'bi-check2'" aria-hidden="true"></i>
            {{ readBy.length ? fill(labels.seenBy, names(readBy)) : labels.notSeen }}
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';

export type MessageSignalPerson = { name: string; is_parent: boolean };
export type MessageReaction = {
    key: string;
    emoji: string;
    count: number;
    mine: boolean;
    by: MessageSignalPerson[];
};
export type MessageSignalLabels = {
    you: string;
    /** "{x}" is replaced with the list of names. */
    seenBy: string;
    notSeen: string;
    /** "{n}" is replaced with a count of people the viewer is not shown by name. */
    others: string;
    and: string;
    reactionsGroup: string;
    reactionNames: Record<string, string>;
};

const EN: MessageSignalLabels = {
    you: 'You',
    seenBy: 'Seen by {x}',
    notSeen: 'Not seen yet',
    others: '{n} more',
    and: ', ',
    reactionsGroup: 'Reactions',
    reactionNames: { ameen: 'Ameen', thumbs_up: 'Thumbs up', hundred: '100', question: 'Question' },
};

const props = withDefaults(defineProps<{
    reactions?: MessageReaction[];
    readBy?: MessageSignalPerson[];
    /** Show "Seen by …" — the screens do this under the viewer's own side of the conversation. */
    showReceipt?: boolean;
    /** False on a closed conversation: the server refuses, so the buttons say so first. */
    canReact?: boolean;
    alignEnd?: boolean;
    labels?: Partial<MessageSignalLabels>;
    /** Performs the PUT/DELETE and resolves with the server's fresh reactions (or null on failure). */
    send?: (key: string, on: boolean) => Promise<MessageReaction[] | null>;
}>(), {
    reactions: () => [],
    readBy: () => [],
    showReceipt: false,
    canReact: true,
    alignEnd: false,
});

const emit = defineEmits<{ (e: 'update:reactions', value: MessageReaction[]): void }>();

const labels = computed<MessageSignalLabels>(() => ({ ...EN, ...(props.labels ?? {}) }));
const busy = ref<string | null>(null);

const fill = (template: string, x: string) => template.replace('{x}', x).replace('{n}', x);
const names = (people: MessageSignalPerson[]) => people.map(p => p.name).join(labels.value.and);
const labelFor = (r: MessageReaction) => labels.value.reactionNames[r.key] ?? r.key;

/** Everyone this viewer may be shown by name, "You" first, then how many are unnamed. */
const whoFor = (r: MessageReaction) => {
    if (!r.count) return '';
    const shown = [...(r.mine ? [labels.value.you] : []), ...r.by.map(p => p.name)];
    const unnamed = r.count - shown.length;
    if (unnamed > 0) shown.push(labels.value.others.replace('{n}', String(unnamed)));
    return shown.join(labels.value.and);
};

const ariaFor = (r: MessageReaction) => {
    const who = whoFor(r);
    return who ? `${labelFor(r)}: ${who}` : labelFor(r);
};

/** One line under the pills naming who reacted, because a tooltip never shows on a phone. */
const whoLine = computed(() => props.reactions
    .filter(r => r.count)
    .map(r => `${r.emoji} ${whoFor(r)}`)
    .join('  ·  '));

const toggle = async (r: MessageReaction) => {
    if (!props.send || !props.canReact || busy.value) return;
    busy.value = r.key;
    try {
        const fresh = await props.send(r.key, !r.mine);
        if (fresh) emit('update:reactions', fresh);
    } finally {
        busy.value = null;
    }
};
</script>

<style scoped>
.signal-pill { line-height: 1.6; font-size: 0.85rem; }
.signal-empty { opacity: 0.55; }
.signal-empty:hover, .signal-empty:focus-visible { opacity: 1; }
.btn-success-subtle { background-color: var(--bs-success-bg-subtle, #d1e7dd); }
</style>
