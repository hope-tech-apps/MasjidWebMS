<template>
    <div class="min-vh-100 mn-realm">
        <nav class="navbar navbar-expand sticky-top mn-topbar">
            <div class="container-fluid px-3 px-lg-4">
                <router-link to="/teacher" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none teacher-brand">
                    <span class="mn-topbar-tile">
                        <img v-if="school?.logo_url" :src="school.logo_url" alt="" width="32" height="32">
                        <img v-else :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    </span>
                    <span class="mn-topbar-name text-truncate">{{ school?.name || 'My Classes' }}</span>
                </router-link>

                <div class="ms-auto d-flex align-items-center gap-3 flex-shrink-0">
                    <router-link to="/teacher"
                                 class="nav-link mn-topbar-link d-none d-sm-inline"
                                 :class="{ 'is-active': isClassesActive }">
                        My Classes
                    </router-link>
                    <!-- Only once the school has published a calendar
                         (`school_calendar_published` on /api/teacher/user); the
                         route itself stays reachable by URL. Icon-only on a phone
                         (where My Classes is the brand link), so it keeps an
                         accessible name that contains the visible word. -->
                    <router-link v-if="calendarPublished" to="/teacher/calendar"
                                 class="nav-link mn-topbar-link d-inline-flex align-items-center gap-1 teacher-tap"
                                 :class="{ 'is-active': isCalendarActive }"
                                 aria-label="School calendar" title="School calendar">
                        <i class="bi bi-calendar3"></i><span class="d-none d-sm-inline">Calendar</span>
                    </router-link>
                    <!-- Only with 2+ schools (memberships[] on /api/teacher/user). A
                         teacher at one school sees the header they always saw. -->
                    <TeacherSchoolPicker :choices="choices" :current-id="selectedId" :switching="switching"
                                         @choose="switchSchool" />
                    <span v-if="teacherName" class="mn-topbar-person d-none d-md-inline">{{ teacherName }}</span>
                    <button class="btn btn-sm btn-outline-secondary teacher-tap" :disabled="signingOut" @click="signOut">
                        <span v-if="signingOut" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Sign out</span>
                    </button>
                </div>
            </div>
        </nav>

        <main class="container py-3 py-sm-4" style="max-width: 960px;">
            <!-- The server bound a different school than this tab selected: a screen
                 headed one school over another's rows. Blocking, so nothing is read
                 from it until the teacher reconciles (the admin notice, reused). -->
            <TenantMismatchNotice v-if="mismatch" :server-name="nameFor(mismatch.server)"
                                  :selected-name="nameFor(mismatch.selected)" :busy="switching"
                                  @reconcile="reconcile" />

            <!-- One refetch-and-reload has happened and the server still refuses the
                 selected school. Say so; reloading again would only loop. -->
            <div v-else-if="refused" class="alert alert-danger small" role="alert">
                Manara can't open this school for your account right now. Sign out and sign in again,
                or ask your school office.
            </div>

            <router-view v-else />
        </main>
    </div>
</template>

<script setup lang="ts">
import TeacherApiService from '@/core/services/TeacherApiService';
import TeacherSchoolPicker from '@/components/teacher/TeacherSchoolPicker.vue';
import TenantMismatchNotice from '@/components/dashboard/TenantMismatchNotice.vue';
import { useAuthStore } from '@/stores/authStore';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { setOrgTitle } from '@/core/pageTitle';
import { useRoute, useRouter } from 'vue-router';
import { landingSchoolId, schoolChoices, switchTarget } from '@/core/helpers/teacherSchools';
import { clearTeacherSchoolNotices, provideSelectedSchool, teacherSchoolMismatch, teacherSchoolRefused } from '@/core/tenancy/teacherSchoolGuard';
import { bumpTenantEpoch, forgetServerTenant } from '@/core/tenancy/tenantRequests';
import { resetTenantScopedStores } from '@/stores/plugins/tenantStoreReset';
import { useStaffChrome } from '@/core/helpers/staffChrome';

// The staff theme (resources/css/custom/theme.css) for as long as this shell is up.
useStaffChrome();

interface TeacherSchool {
    id: number;
    name: string;
    logo_url: string | null;
    org_type: string | null;
}

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const school = ref<TeacherSchool | null>(null);
const teacherName = ref('');
const signingOut = ref(false);
/** True only when the self payload says so; missing, false or a failed read hide the link. */
const calendarPublished = ref(false);

// The classes list is the shell's home; keep its nav pill lit while browsing it.
const isClassesActive = computed(() => route.path === '/teacher');
const isCalendarActive = computed(() => route.name === 'teacherCalendar');

// The tab title's organisation half (core/pageTitle.ts): whichever org this
// shell is showing, cleared on the way out so the next screen is not titled
// with a school the user has just left.
watch(() => school.value?.name, (name) => setOrgTitle(name), { immediate: true });
onBeforeUnmount(() => setOrgTitle(null));

// ---------------------------------------------------------------- the schools

/**
 * The schools the SERVER granted this teacher — `memberships[]` on
 * /api/teacher/user, the very list the resolver would bind (AuthController::
 * attachMemberships). Never assembled here: an entry the resolver would refuse is
 * a 403 with a spinner in front of it.
 */
const choices = computed(() => schoolChoices(authStore.user?.memberships));

/** What this tab has selected: a claim, not a fact — the server checks it on every request. */
const selectedId = computed<number | null>(() => {
    const id = Number(authStore.dashboardMasjidId);
    return Number.isInteger(id) && id > 0 ? id : null;
});

const nameFor = (id: number): string => choices.value.find((choice) => choice.id === id)?.name ?? `School #${id}`;

const mismatch = teacherSchoolMismatch;
const refused = teacherSchoolRefused;
const switching = ref(false);

/**
 * Switch school: abort what is in flight, empty the stores, remember the choice,
 * and start the shell again from nothing.
 *
 * The first three steps are the admin switcher's (tenantSwitchStore.switchTo):
 * responses already on the wire carry the OTHER school's classes, students and
 * messages, and would otherwise land in screens about to be labelled for this
 * one. The last is a full reload rather than the admin's in-place remount,
 * deliberately (design §5): the teacher's class screen holds thousands of lines
 * of local state and a `groupId` in its route, and none of it may survive into
 * another school. Teachers switch rarely, so the reload costs nothing that
 * matters and removes a class of leak instead of guarding against it.
 */
function switchSchool(id: number): void {
    const target = switchTarget(selectedId.value, id, choices.value);
    if (target === null || switching.value) return;

    switching.value = true;

    try {
        bumpTenantEpoch();
        resetTenantScopedStores();
    } catch (error) {
        // Not fatal: the reload below is what actually guarantees a clean slate.
        console.warn('[tenant] store reset before the school switch failed', error);
    }

    forgetServerTenant();
    authStore.saveDashboardMasjidId(target);
    window.location.assign('/teacher');
}

/** "Continue in {server school}": adopt what the server bound (if we may) and start again. */
function reconcile(): void {
    const server = teacherSchoolMismatch.value?.server ?? null;
    const target = server !== null && choices.value.some((choice) => choice.id === server)
        ? server
        : landingSchoolId(null, choices.value);

    switching.value = true;
    bumpTenantEpoch();
    forgetServerTenant();

    if (target !== null) authStore.saveDashboardMasjidId(target);
    else authStore.forgetDashboardMasjidId();

    window.location.assign('/teacher');
}

/**
 * The header, from the school the SELECTED id names — read through the tenant-bound
 * endpoint, so it is a school the server verified for this very request, never the
 * default membership that /api/teacher/user names. For a teacher at two schools the
 * old source painted school A's name and logo over school B's classes.
 */
async function loadSchoolHeader(): Promise<void> {
    if (selectedId.value === null) {
        // Nothing selected (an older payload with no memberships): the only school
        // the shell can name is the login's own, which is what it always showed.
        const own: any = authStore.user?.masjid ?? null;
        school.value = own;
        calendarPublished.value = (authStore.user as any)?.school_calendar_published === true;
        return;
    }

    try {
        const res = await TeacherApiService.get(`/api/teacher/masjids/${selectedId.value}/school`);
        const data = res.data?.data ?? null;
        if (data) {
            school.value = data;
            calendarPublished.value = data.school_calendar_published === true;
        }
    } catch {
        // Not fatal to the shell — the classes screen shows its own error, a 401 is
        // already handled by TeacherApiService, and a refused school is handled by
        // its 403 interceptor.
        school.value = null;
        calendarPublished.value = false;
    }
}

onMounted(async () => {
    // A notice left over from the last session on this tab (sign-out is an SPA
    // navigation, no reload) must not block this one's screens.
    clearTeacherSchoolNotices();

    // The echo guard compares what the server bound with THIS tab's selection.
    provideSelectedSchool(() => authStore.dashboardMasjidId);

    // The teacher's own name, from their own self endpoint — the shell never
    // reaches into the admin masjid store, which a teacher token cannot read.
    const identity = (async () => {
        try {
            const res = await TeacherApiService.get('/api/teacher/user');
            const data = res.data?.data ?? null;
            if (data) {
                // Unchanged from before multi-school: `users` has no first_name/last_name,
                // so this stays blank and the header prints no name. Showing `data.name`
                // would put a new label on every single-school teacher's header; that is
                // a product change for the owner to ask for, not a side effect here.
                teacherName.value = [data.first_name, data.last_name].filter(Boolean).join(' ');
            }
        } catch {
            teacherName.value = '';
        }
    })();

    await Promise.all([identity, loadSchoolHeader()]);
});

const signOut = async () => {
    signingOut.value = true;
    try {
        await TeacherApiService.post('/api/teacher/logout');
    } catch {
        // Sign out locally regardless of what the server says.
    } finally {
        authStore.removeAuth();
        signingOut.value = false;
        router.push('/auth/sign-in');
    }
};
</script>

<style scoped>
/*
 * A school's full name ("Burlington Islamic Sunday School") is wider than a
 * phone on its own, and `.navbar-brand` never wraps — so the header alone
 * pushed every teacher screen ~120px sideways, and Sign out sat off the edge.
 * The brand may shrink (min-width: 0) and its name truncates; the controls on
 * the right keep their size.
 */
.teacher-brand {
    /* Shrink only; never grow, or the empty header becomes one big link home. */
    min-width: 0;
    flex: 0 1 auto;
}

@media (max-width: 575.98px), (pointer: coarse) {
    /* 44px targets, and the calendar icon is only an icon here. */
    .teacher-brand,
    .teacher-tap {
        min-height: 44px;
        min-width: 44px;
        justify-content: center;
    }
}
</style>
