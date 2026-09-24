<template>
    <section class="studio-step-empty" aria-labelledby="studio-features-title">
        <h5 id="studio-features-title" class="fw-semibold mb-1">Features</h5>
        <p class="text-muted mb-0">The feature list for this step is not built yet.</p>
    </section>
</template>

<script setup lang="ts">
/**
 * Step 1, Features. The mount point StudioView renders for `current_step`
 * `features`; the step itself (the served catalogue, grouped, with Retry and no
 * fallback to the legacy keys) is the second half of S5
 * (docs/manara-studio-w1.md S5, `steps/StudioFeatureStep`). The store already
 * has what it needs: fetchCatalogue(), `catalogue`, `catalogueError`, and the
 * `features` answers section.
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
