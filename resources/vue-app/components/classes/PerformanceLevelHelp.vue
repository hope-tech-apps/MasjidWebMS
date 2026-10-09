<template>
    <details v-if="levels.length" class="mb-3">
        <summary class="small text-primary" style="cursor:pointer">What do 4, 3, 2 and 1 mean?</summary>
        <dl class="row small mt-2 mb-0">
            <template v-for="l in levels" :key="l.level">
                <dt class="col-sm-3 fw-semibold">{{ l.level }} — {{ l.short_label }}</dt>
                <dd class="col-sm-9 text-muted">{{ l.description }}</dd>
            </template>
        </dl>
    </details>
</template>
<script setup lang="ts">
// The report-card authority supplies every label and description.
defineProps<{ levels: { level: number; short_label: string; description: string }[] }>();
</script>
