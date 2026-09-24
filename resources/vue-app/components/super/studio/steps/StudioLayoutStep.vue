<template>
    <section class="studio-step-empty" aria-labelledby="studio-layout-title">
        <h5 id="studio-layout-title" class="fw-semibold mb-1">Layout</h5>
        <p class="text-muted mb-0">The layout choice for this step is not built yet.</p>
    </section>
</template>

<script setup lang="ts">
/**
 * Step 2, Layout. The mount point StudioView renders for `current_step`
 * `layout`; the preset cards and their approval are the second half of S5
 * (docs/manara-studio-w1.md S5, `steps/StudioLayoutStep`). The store already
 * has fetchPresets(), `presets`, `presetsError`, and the `layout` answers
 * section, whose `preset` and `approved_at` the approval writes.
 */
</script>

<style scoped>
.studio-step-empty {
    border: 1px dashed var(--input-border, #ddd);
    border-radius: .5rem;
    padding: 1.5rem;
    background: #fff;
}
</style>
