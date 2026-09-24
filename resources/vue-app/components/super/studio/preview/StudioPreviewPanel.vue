<template>
    <section class="studio-preview-empty" aria-labelledby="studio-preview-title">
        <h5 id="studio-preview-title" class="fw-semibold mb-1">Preview</h5>
        <p class="text-muted mb-0">Device mockups are not built yet.</p>
    </section>
</template>

<script setup lang="ts">
/**
 * The preview column's mount point. StudioView gives it the sticky
 * `col-xl-5`; the device frames, one tab per selected platform, are the second
 * half of S5 (docs/manara-studio-w1.md S5, `preview/StudioPreviewPanel`). They
 * read the store's `preview`, which refreshPreview() keeps current with every
 * edit, and `previewLoading` / `previewError`.
 */
</script>

<style scoped>
.studio-preview-empty {
    border: 1px dashed var(--input-border, #ddd);
    border-radius: .5rem;
    padding: 1.5rem;
    background: #fff;
}
</style>
