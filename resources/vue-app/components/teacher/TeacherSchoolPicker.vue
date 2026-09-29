<template>
    <!--
        The teacher shell's school picker (docs/multi-tenant-admin-design.md §5).

        Renders NOTHING for a teacher at one school — no button, no caret, no gap in
        the header. That is the requirement, not an optimisation: the teachers who
        belong to one school must see exactly the header they see today, and a
        control that offers a choice of one invites the question "which school am I
        in?" where there was never any doubt.

        The admin OrgSwitcher's presentation (Bootstrap dropdown, same menu), but
        driven by props rather than the admin `tenantSwitchStore`: that store's
        `hasNoOrganisation` and masjid-store fetches are MasjidAdmin-specific.
    -->
    <div v-if="canPickSchool(choices)" class="btn-group teacher-school-picker">
        <button type="button" class="btn btn-sm btn-light-success dropdown-toggle d-flex align-items-center gap-2 teacher-tap"
            data-bs-toggle="dropdown" aria-expanded="false" :disabled="switching"
            :aria-label="`You are in ${currentName}. Switch school.`" title="Switch school">
            <span v-if="switching" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <i v-else class="bi bi-arrow-left-right" aria-hidden="true"></i>
            <span class="d-none d-md-inline">Switch</span>
        </button>

        <ul class="dropdown-menu dropdown-menu-end teacher-school-menu">
            <li>
                <h6 class="dropdown-header">Your schools</h6>
            </li>

            <li v-for="choice in choices" :key="choice.id">
                <button type="button" class="dropdown-item d-flex align-items-center gap-2 teacher-tap"
                    :class="{ 'teacher-school-current': choice.id === currentId }"
                    :aria-current="choice.id === currentId ? 'true' : undefined" :disabled="switching"
                    @click.prevent="emit('choose', choice.id)">
                    <i class="bi" :class="choice.id === currentId ? 'bi-check2' : 'bi-building'" aria-hidden="true"></i>
                    <span class="flex-grow-1 text-start">{{ choice.name }}</span>
                    <span v-if="choice.id === currentId" class="visually-hidden">(current school)</span>
                </button>
            </li>
        </ul>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { canPickSchool, SchoolChoice } from '@/core/helpers/teacherSchools';

const props = defineProps<{
    /** The schools the SERVER granted (`memberships[]` on /api/teacher/user), never assembled locally. */
    choices: SchoolChoice[];
    /** The school this tab has selected. */
    currentId: number | null;
    /** A switch is under way: the control is disabled so a second click cannot start another. */
    switching?: boolean;
}>();

const emit = defineEmits<{ (event: 'choose', id: number): void }>();

const currentName = computed<string>(
    () => props.choices.find((choice) => choice.id === props.currentId)?.name ?? 'no school'
);
</script>

<style scoped>
.teacher-school-menu {
    min-width: 15rem;
    --bs-dropdown-border-color: var(--lighted-gray);
}

.teacher-school-menu .dropdown-item {
    cursor: pointer;
}

.teacher-school-current {
    /* Marked, not disabled: re-selecting it is a no-op, and greying it out reads as
       "this school is unavailable". */
    background-color: var(--cgreen-light);
    font-weight: 600;
}

@media (max-width: 575.98px), (pointer: coarse) {
    /* 44px targets, like the rest of the teacher header. */
    .teacher-tap {
        min-height: 44px;
        min-width: 44px;
        justify-content: center;
    }

    /* ...but a menu row reads left to right. */
    .dropdown-item.teacher-tap {
        justify-content: flex-start;
    }
}
</style>
