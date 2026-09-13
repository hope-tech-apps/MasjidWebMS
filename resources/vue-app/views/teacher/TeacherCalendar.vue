<template>
    <div>
        <router-link to="/teacher" class="text-decoration-none small d-inline-block mb-3">
            &larr; My Classes
        </router-link>

        <h1 class="h4 mb-1">School calendar</h1>
        <p class="text-muted small mb-4">
            The days school meets, and the days there's no school. The school office keeps this up to date.
        </p>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-success" role="status">
                <span class="visually-hidden">Loading the school calendar…</span>
            </span>
        </div>

        <div v-else-if="error" class="alert alert-danger" role="alert">
            {{ error }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
        </div>

        <div v-else-if="!calendar || !calendar.years.length" class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar3 fs-2 d-block mb-2 text-muted"></i>
                <p class="mb-1 fw-semibold">The school calendar hasn't been published yet</p>
                <p class="text-muted small mb-0">When the office adds the school year, its days will show up here.</p>
            </div>
        </div>

        <template v-else>
            <!-- Coming up -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">Coming up</h2>
                    <p v-if="!calendar.upcoming.length" class="text-muted small mb-0">There are no more school days this year.</p>
                    <ul v-else class="list-unstyled mb-0 d-flex flex-column gap-2">
                        <li v-for="day in calendar.upcoming" :key="day.date" class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold small">{{ formatSchoolDay(day.date, LOCALE, { weekday: 'long', month: 'long', day: 'numeric' }) }}</span>
                            <span v-if="day.date === calendar.today" class="badge bg-success-subtle text-success-emphasis">Today</span>
                            <template v-if="day.closed">
                                <span class="badge bg-danger-subtle text-danger-emphasis">No school</span>
                                <span v-if="day.reason" class="small">{{ day.reason }}</span>
                            </template>
                            <span v-else class="small text-muted">School day</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Which year -->
            <div v-if="calendar.years.length > 1" class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="School year">
                <button v-for="year in calendar.years" :key="year.id" type="button" class="btn btn-sm"
                        :class="selectedYearId === year.id ? 'btn-success' : 'btn-outline-secondary'"
                        :aria-pressed="selectedYearId === year.id"
                        @click="selectedYearId = year.id">
                    {{ year.label }}
                </button>
            </div>

            <template v-if="selectedYear">
                <div class="mb-3">
                    <h2 class="h5 mb-1">{{ selectedYear.label }}</h2>
                    <div class="text-muted small">
                        {{ formatSchoolDay(selectedYear.first_day, LOCALE, { month: 'long', day: 'numeric', year: 'numeric' }) }}
                        –
                        {{ formatSchoolDay(selectedYear.last_day, LOCALE, { month: 'long', day: 'numeric', year: 'numeric' }) }}
                        · Meets every {{ weekdayName(selectedYear.meeting_weekday, LOCALE) }}
                    </div>
                </div>

                <SchoolCalendarList
                    :days="selectedDays"
                    :today="calendar.today"
                    :locale="LOCALE"
                    :labels="{ today: 'Today', noSchool: 'No school', schoolDay: 'School day', empty: 'No school days are listed for this year.' }"
                />
            </template>
        </template>
    </div>
</template>

<script setup lang="ts">
import TeacherApiService from '@/core/services/TeacherApiService';
import SchoolCalendarList from '@/components/common/SchoolCalendarList.vue';
import {
    SchoolCalendarReadPayload,
    daysOfYear,
    defaultYear,
    formatSchoolDay,
    readSchoolCalendarRead,
    weekdayName,
} from '@/core/types/data/masjid-related/SchoolCalendar';
import { useAuthStore } from '@/stores/authStore';
import { computed, onMounted, ref } from 'vue';

/**
 * The school calendar, read-only, for a teacher.
 *
 * Addressed per school like every other teacher route
 * (/api/teacher/masjids/{masjid_id}/…); the server binds the tenant from the
 * token and uses the id only to shape the route. Not capability-gated: an org
 * with no calendar answers `years: []`, which is the quiet empty state — a
 * failed read is an error with a retry, never that empty state.
 */

const LOCALE = 'en-US';

const authStore = useAuthStore();
const masjidId = computed(() => authStore.dashboardMasjidId ?? 0);

const calendar = ref<SchoolCalendarReadPayload | null>(null);
const loading = ref(true);
const error = ref('');
const selectedYearId = ref<number | null>(null);

const selectedYear = computed(() => calendar.value?.years.find((y) => y.id === selectedYearId.value) ?? null);
const selectedDays = computed(() => (selectedYear.value ? daysOfYear(selectedYear.value) : []));

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const res = await TeacherApiService.get(`/api/teacher/masjids/${masjidId.value}/school-calendar`);
        const next = readSchoolCalendarRead(res.data?.data);
        if (!next) throw new Error('The calendar response was not a calendar.');

        calendar.value = next;
        if (!next.years.some((y) => y.id === selectedYearId.value)) {
            selectedYearId.value = defaultYear(next.years, next.today)?.id ?? null;
        }
    } catch {
        // A 401 is already handled centrally (redirect to sign-in).
        calendar.value = null;
        error.value = 'We could not load the school calendar just now. Please try again.';
    } finally {
        loading.value = false;
    }
};

onMounted(load);
</script>
