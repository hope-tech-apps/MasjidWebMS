<template>
    <div class="min-vh-100 bg-light">
        <nav class="navbar navbar-expand bg-white border-bottom sticky-top">
            <div class="container-fluid px-3 px-lg-4">
                <router-link to="/teacher" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none teacher-brand">
                    <img v-if="school?.logo_url" :src="school.logo_url" alt="" width="32" height="32"
                         class="rounded" style="object-fit: cover;">
                    <img v-else :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    <span class="fw-semibold text-dark text-truncate">{{ school?.name || 'My Classes' }}</span>
                </router-link>

                <div class="ms-auto d-flex align-items-center gap-3 flex-shrink-0">
                    <router-link to="/teacher"
                                 class="nav-link px-0 d-none d-sm-inline"
                                 :class="isClassesActive ? 'fw-semibold text-success' : 'text-muted'">
                        My Classes
                    </router-link>
                    <!-- Only once the school has published a calendar
                         (`school_calendar_published` on /api/teacher/user); the
                         route itself stays reachable by URL. Icon-only on a phone
                         (where My Classes is the brand link), so it keeps an
                         accessible name that contains the visible word. -->
                    <router-link v-if="calendarPublished" to="/teacher/calendar"
                                 class="nav-link px-0 d-inline-flex align-items-center gap-1 teacher-tap"
                                 :class="isCalendarActive ? 'fw-semibold text-success' : 'text-muted'"
                                 aria-label="School calendar" title="School calendar">
                        <i class="bi bi-calendar3"></i><span class="d-none d-sm-inline">Calendar</span>
                    </router-link>
                    <span v-if="teacherName" class="text-muted small d-none d-md-inline">{{ teacherName }}</span>
                    <button class="btn btn-sm btn-outline-secondary teacher-tap" :disabled="signingOut" @click="signOut">
                        <span v-if="signingOut" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Sign out</span>
                    </button>
                </div>
            </div>
        </nav>

        <main class="container py-3 py-sm-4" style="max-width: 960px;">
            <router-view />
        </main>
    </div>
</template>

<script setup lang="ts">
import TeacherApiService from '@/core/services/TeacherApiService';
import { useAuthStore } from '@/stores/authStore';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { setOrgTitle } from '@/core/pageTitle';
import { useRoute, useRouter } from 'vue-router';

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

onMounted(async () => {
    // The header comes from the teacher's own self endpoint — the shell never
    // reaches into the admin masjid store, which a teacher token cannot read.
    try {
        const res = await TeacherApiService.get('/api/teacher/user');
        const data = res.data?.data ?? null;
        if (data) {
            school.value = data.masjid ?? null;
            teacherName.value = [data.first_name, data.last_name].filter(Boolean).join(' ');
            calendarPublished.value = data.school_calendar_published === true;
        }
    } catch {
        // A failure here is not fatal to the shell — the classes screen shows its
        // own error, and a 401 is already handled by TeacherApiService.
        school.value = null;
        calendarPublished.value = false;
    }
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
