<template>
    <div class="small bg-light px-3 py-2" data-report-subject-summary>
        <p class="fw-semibold mb-1">{{ summary.heading }}</p>
        <p v-if="total > 0" class="mb-1">{{ counts }}</p>
        <p v-else class="mb-1">Nothing marked yet in {{ summary.name }}.</p>
        <a v-if="summary.can_open" :href="href">Open {{ summary.name }}</a>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    summary: { name: string; heading: string; counts: Record<number, number>; can_open: boolean };
    levels: { level: number; short_label: string }[];
    href: string;
}>();
const total = computed(() => Object.values(props.summary.counts).reduce((sum, n) => sum + n, 0));
const counts = computed(() => props.levels.map(level => `${level.level} ${level.short_label}: ${props.summary.counts[level.level] ?? 0}`).join(' · '));
</script>
