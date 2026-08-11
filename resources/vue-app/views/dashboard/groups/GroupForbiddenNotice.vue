<template>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-shield-lock fs-1 d-block mb-3"></i>
        <p class="mb-1 fw-semibold">You are not on this roster</p>
        <p class="mb-0 small">
            {{ what }} about members of a group is only shown to that group's leaders, the member, and
            that member's own guardians &mdash; not to every administrator. Add yourself to the roster
            as a leader to see it.
        </p>
    </div>
</template>

<script setup lang="ts">
/**
 * The 403 a group surface answers when the caller has no standing IN the group.
 *
 * This is ORDINARY, not a failure: `view contacts` is held by every masjid
 * admin, so gating a child's photograph, points or hifz record on it alone would
 * publish the class story to the whole tenant. Reading additionally requires
 * being in the group, decided server-side by `App\Support\GroupAudience`.
 *
 * So the tab degrades to this panel rather than an error toast — the admin has
 * not done anything wrong, and the API has not broken. See
 * .claude/rules/groups.md ("Disclosure is not administration"). The deliberate
 * asymmetry, which this copy has to be honest about, is that such an admin can
 * still WRITE here: publishing is administration, reading is disclosure.
 */
defineProps<{
    /** What is being withheld, as a sentence subject: "Photos and notes", "Behaviour records". */
    what: string;
}>();
</script>
