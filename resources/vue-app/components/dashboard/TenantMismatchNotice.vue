<template>
    <!--
        The server bound one organisation and this tab believes it is in another
        (S5 of docs/multi-tenant-admin-design.md).

        Every response now says which organisation the server actually scoped it
        to, and the header renders THAT. This notice exists for the case where
        the two answers differ: the rows on screen belong to `serverName`, the
        tab thinks it is in `selectedName`, and the difference is exactly the
        thing nobody would notice on their own — a 200 with real data under a
        heading that is quietly wrong.

        Telling the user is the whole feature. Silently re-pointing the tab would
        hide the disagreement, and a disagreement here means either a stale
        selection or a resolver doing something this build does not expect; both
        are worth a human looking at.
    -->
    <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 py-2 px-3 small mb-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span class="flex-grow-1">
            You are seeing <strong>{{ serverName }}</strong>, not <strong>{{ selectedName }}</strong>.
            Manara is showing the organisation the server allowed this account to open.
        </span>
        <button type="button" class="btn btn-sm btn-danger" :disabled="busy" @click.prevent="emit('reconcile')">
            Continue in {{ serverName }}
        </button>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    /** The organisation the server said it bound — the one whose rows are on screen. */
    serverName: string;
    /** The organisation this tab had selected. */
    selectedName: string;
    /** A switch is already running; do not offer a second one. */
    busy?: boolean;
}>();

const emit = defineEmits<{ (event: 'reconcile'): void }>();
</script>
