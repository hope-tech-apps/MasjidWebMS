<template>
    <!--
        "Seen by 4 of 7 parents" under a class story, for STAFF screens only (the
        teacher screen and the office's Story tab). The family portal never draws
        this and its payload never carries the numbers: a parent is not told
        another parent opened anything.

        Rendered only while the school has switched read receipts on
        (`meta.story_reads.enabled`); while off the server omits the fields, so a
        receipt nobody is keeping is never shown as "0 of 7". The same goes for a
        story older than the day recording began: the server sends `seen_tracked:
        false` and this draws "Not tracked before <date>".
    -->
    <div v-if="enabled && untracked" class="small text-muted mt-2" data-test="story-seen-untracked">
        <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>{{ notTrackedLabel(since) }}
    </div>
    <div v-else-if="enabled && audienceCount !== null" class="small text-muted mt-2" data-test="story-seen">
        <i class="bi me-1" :class="seenCount ? 'bi-eye text-success' : 'bi-eye-slash'" aria-hidden="true"></i>
        <details v-if="seenBy.length" class="d-inline">
            <summary class="d-inline" style="cursor: pointer;">Seen by {{ seenCount }} of {{ audienceCount }} {{ audienceCount === 1 ? 'parent' : 'parents' }}</summary>
            <ul class="list-unstyled mb-0 mt-1 ps-3">
                <li v-for="(p, i) in seenBy" :key="i">{{ p.name }}</li>
            </ul>
        </details>
        <span v-else>Seen by {{ seenCount }} of {{ audienceCount }} {{ audienceCount === 1 ? 'parent' : 'parents' }}</span>
        <div v-if="unreachableCount > 0" class="fst-italic">
            {{ unreachableCount }} more {{ unreachableCount === 1 ? 'parent has' : 'parents have' }} no portal login and
            {{ unreachableCount === 1 ? 'is' : 'are' }} not counted.
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { notTrackedLabel } from '@/core/helpers/storySeen';

export type StorySeenPerson = { name: string; seen_at?: string | null };

const props = defineProps<{
    /** `meta.story_reads.enabled` — receipts are being collected. */
    enabled?: boolean;
    seenBy?: StorySeenPerson[];
    seenCount?: number | null;
    audienceCount?: number | null;
    /** `meta.story_reads.unreachable_count`. */
    unreachable?: number | null;
    /** The post's `seen_tracked`: false means it predates recording. Absent means tracked. */
    tracked?: boolean | null;
    /** The post's `seen_since`: the school-local `Y-m-d` recording began. */
    since?: string | null;
}>();

const untracked = computed(() => props.tracked === false);
const seenBy = computed(() => props.seenBy ?? []);
const seenCount = computed(() => props.seenCount ?? 0);
const audienceCount = computed<number | null>(() => (typeof props.audienceCount === 'number' ? props.audienceCount : null));
const unreachableCount = computed(() => props.unreachable ?? 0);
</script>
