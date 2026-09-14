<template>
    <!--
        A SuperAdmin is looking at something this organisation's administrators do not see.
        One component, so the whole-screen notice (DashboardLayout) and the Details screen's
        tab notice (MosqueDetailsTabsView) cannot drift apart in wording.
    -->
    <div class="alert alert-warning py-2 px-3 small mb-0" role="status">
        <i class="bi bi-dash-circle me-1" aria-hidden="true"></i>
        Switched off for {{ orgName }}: {{ detail }}
    </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
    /** The organisation's name; blank while its payload is still loading, as it always was. */
    orgName?: string | null;
    /** The rest of the sentence after the colon. */
    detail?: string;
}>(), {
    orgName: '',
    detail: 'its administrators do not see this screen.',
});
</script>
