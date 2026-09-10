<template>
    <!--
        role="status" (which implies aria-live="polite") rather than a bare div:
        a screen-reader user gets no colour, and "you are not on the live site"
        is exactly the kind of thing they must not have to infer.
    -->
    <div v-if="label" class="env-ribbon" role="status">
        <span class="env-ribbon__label">{{ label }}</span>
    </div>
</template>

<script setup lang="ts">
/**
 * "This is not the live site" — said once, on every screen.
 *
 * Staging is a scrubbed copy of production: same branding, same admin, same
 * donation and registration screens. Two tabs side by side are otherwise
 * identical, which is how a real card number gets typed into a throwaway box,
 * or how an hour is spent "fixing" a record that will be wiped on the next
 * restore. This is the cheapest possible defence against that.
 *
 * Mounted once in AdminDashboardApp (the root, above <RouterView>) so it covers
 * admin, teacher, family, lunch and portal screens without any of them opting
 * in — a per-layout banner would be missed by whichever layout is added next.
 *
 * The value comes from `window.__APP_ENV__`, emitted by
 * resources/views/vue-app-index.blade.php. It is NOT read from import.meta.env:
 * VITE_APP_URL and friends are baked at BUILD time and the same bundle is
 * served by every deployment on purpose (see OrgPortal.vue), so a build-time
 * value would say whatever the machine that ran `npm run build` happened to be.
 * The server that answered the request is the only honest source.
 */

// Read once at setup: the global is written by the document before the bundle
// executes and nothing mutates it afterwards, so there is nothing to react to.
const raw = typeof window.__APP_ENV__ === 'string' ? window.__APP_ENV__.trim() : '';

// Empty label => nothing renders. Production is the silent case, and so is a
// page served by anything that did not emit the global at all (an old cached
// Blade, a bare HTML harness) — guessing "unknown" there would put a permanent
// ribbon on the live site the first time a deploy went out in the wrong order.
const label = raw === '' || raw.toLowerCase() === 'production' ? '' : raw.toUpperCase();
</script>

<style scoped>
.env-ribbon {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;

    /*
        Above everything this app can stack: Bootstrap modals/tooltips top out
        around 1080 and SweetAlert2 sits at 1060. A ribbon that a dialog can
        cover is a ribbon you stop trusting.
    */
    z-index: 2147483000;

    /*
        NEVER intercept a click. The bar spans the full width across the top of
        every screen, so without this it would swallow clicks on whatever sits
        underneath it — a nav item, a close button — and the safety marker would
        become a bug.
    */
    pointer-events: none;

    /* Amber over black: reads as a warning at a glance, in either theme. */
    border-top: 4px solid #f0a202;
}

.env-ribbon__label {
    background: #f0a202;
    color: #1a1a1a;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.14em;
    line-height: 1;
    padding: 4px 14px 5px;
    border-radius: 0 0 6px 6px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);

    /* Long env names must not push the pill off-screen on a phone. */
    max-width: 90vw;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media print {
    /* A printed report card or receipt should not carry a UI chrome bar. */
    .env-ribbon {
        display: none;
    }
}
</style>
