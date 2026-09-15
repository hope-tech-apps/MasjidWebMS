<template>
    <!--
        The dashboard has no organisation to show, for one of two reasons (S5 of
        docs/multi-tenant-admin-design.md). Either way the screens below render
        nothing, and a dashboard that renders nothing reads as a broken product
        rather than as a state with a cause and a way out.

        `granted` — the server described this account and granted it no
        organisation. Since S4 the API no longer signs such an account out; it
        used to, and that would lock out an admin whose access lives in the
        membership pivot rather than in `masjids.user_id`.

        `chosen` — several organisations, none of them marked default, and
        nothing picked yet. Nothing in the SPA may choose here: a guess is a
        request scoped to an organisation nobody asked for.

        Neither is reachable while `tenancy.multi_membership` is false: every
        admin who can sign in today holds exactly one grant.
    -->
    <div class="alert alert-warning py-3 px-3 mb-0" role="status">
        <div class="fw-semibold mb-1">
            <i class="bi me-1" :class="reason === 'granted' ? 'bi-building-slash' : 'bi-buildings'"
                aria-hidden="true"></i>
            {{ reason === 'granted' ? 'No organisation is assigned to this account' : 'Choose an organisation' }}
        </div>
        <div class="small mb-0">
            <template v-if="reason === 'granted'">
                Your sign-in works, but it is not attached to any organisation yet, so there is
                nothing for these screens to show. Ask whoever administers Manara for your
                organisation to add you to it — your account does not need to be created again.
            </template>
            <template v-else>
                This account administers more than one organisation and none of them is set as
                your default. Pick one from <strong>Switch</strong> at the top of the page to
                start.
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
    /** Why there is nothing to show: nothing granted, or nothing chosen. */
    reason?: 'granted' | 'chosen';
}>(), {
    reason: 'granted',
});
</script>
