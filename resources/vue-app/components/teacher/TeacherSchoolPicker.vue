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

        It is also the switch between AREAS, for the one kind of login that has
        two: Friday-lunch staff at one organisation who teaches at another. Such a
        login's user payload carries `areas`; the places in the area this shell is
        NOT are listed under their role ("Friday lunch · Burlington Masjid") and
        choosing one is handed to the shell. The lunch shell mounts this with no
        schools at all. Without `areas` (every login with one kind of role) the
        control is exactly the one described above, to the word.
    -->
    <div v-if="canPickSchool(choices) || places.length > 0" class="btn-group teacher-school-picker"
        :class="{ 'teacher-school-picker-places': places.length > 0 }">
        <button type="button" class="btn btn-sm btn-light-success dropdown-toggle d-flex align-items-center gap-2 teacher-tap"
            data-bs-toggle="dropdown" aria-expanded="false" :disabled="switching"
            :aria-label="buttonLabel" :title="buttonTitle">
            <span v-if="switching" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <i v-else class="bi bi-arrow-left-right" aria-hidden="true"></i>
            <!-- The word is hidden on a phone for a school picker, as it always was.
                 With another area to go to it stays: the email that tells someone
                 they were added says "use the switch at the top of the page". -->
            <span :class="{ 'd-none d-md-inline': places.length === 0 }">Switch</span>
        </button>

        <ul class="dropdown-menu dropdown-menu-end teacher-school-menu">
            <template v-if="canPickSchool(choices)">
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
            </template>

            <template v-if="places.length > 0">
                <li v-if="canPickSchool(choices)">
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <h6 class="dropdown-header">Other places you work</h6>
                </li>

                <!-- Buttons, like the schools above: Tab, the arrow keys, Enter and
                     Space all reach them through the dropdown's own handling. -->
                <li v-for="place in places" :key="`${place.area}-${place.masjidId}`">
                    <button type="button" class="dropdown-item d-flex align-items-center gap-2 teacher-tap"
                        :disabled="switching" @click.prevent="emit('chooseArea', place)">
                        <i class="bi" :class="place.area === 'lunch' ? 'bi-cup-hot' : 'bi-mortarboard'" aria-hidden="true"></i>
                        <span class="flex-grow-1 text-start">{{ place.label }}</span>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { canPickSchool, SchoolChoice } from '@/core/helpers/teacherSchools';
import { areaName, currentPlace, otherPlaces, StaffArea, StaffPlace } from '@/core/helpers/staffAreas';

const props = defineProps<{
    /** The schools the SERVER granted (`memberships[]` on /api/teacher/user), never assembled locally. */
    choices: SchoolChoice[];
    /** The school this tab has selected. */
    currentId: number | null;
    /** A switch is under way: the control is disabled so a second click cannot start another. */
    switching?: boolean;
    /** `areas[]` from the user payload, exactly as the server sent it. Absent for a login with one kind of role. */
    areas?: unknown;
    /** The area the shell showing this control is. */
    currentArea?: StaffArea;
}>();

const emit = defineEmits<{
    (event: 'choose', id: number): void;
    (event: 'chooseArea', place: StaffPlace): void;
}>();

const currentName = computed<string>(
    () => props.choices.find((choice) => choice.id === props.currentId)?.name ?? 'no school'
);

/** The places in the OTHER area. Empty without `areas`, which is every login with one kind of role. */
const places = computed<StaffPlace[]>(() => otherPlaces(props.areas, props.currentArea));

/**
 * Where this shell is, in the menu's own words. Built from the area, never from
 * the school list alone: the lunch shell has no schools, and "You are in no
 * school" is not something to read out to someone looking at a lunch board.
 */
const hereLabel = computed<string>(() => {
    const here = currentPlace(props.areas, props.currentArea, props.currentId);
    if (here) return here.label;

    return props.currentArea ? areaName(props.currentArea) : currentName.value;
});

// The visible word, "Switch", opens both sentences, so what a screen reader says
// contains what a sighted person reads on the button.
const buttonTitle = computed<string>(
    () => places.value.length > 0 ? 'Switch between the places you work' : 'Switch school'
);

const buttonLabel = computed<string>(
    () => places.value.length > 0
        ? `You are in ${hereLabel.value}. Switch between the places you work.`
        : `You are in ${currentName.value}. Switch school.`
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

@media (max-width: 575.98px) {
    /*
     * With other places in it (a login that works in two areas) the menu hangs
     * from a button that sits in the middle of a phone's header, and it is 15rem
     * wide: measured at 375px, 25px of it was off the left edge of the screen.
     * Nothing corrects that: inside a navbar Bootstrap places a dropdown with
     * plain CSS (data-bs-popper="static"), not Popper. So on a phone the menu
     * hangs from the header instead, a margin in from each edge.
     */
    .teacher-school-picker-places {
        position: static;
    }

    .teacher-school-picker-places .teacher-school-menu {
        left: 1rem;
        right: 1rem;
    }
}
</style>
