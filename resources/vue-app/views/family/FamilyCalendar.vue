<template>
    <div :dir="dir" :lang="lang">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <!-- The arrow is a direction: back is the left in English and the right in Arabic. -->
            <router-link :to="`/family/${masjidId}`"
                         class="text-decoration-none small d-inline-flex align-items-center gap-1">
                <i :class="backIcon"></i>{{ t('cal_back') }}
            </router-link>

            <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                    :title="t('switch_lang_title')" @click="toggle">
                {{ switchLabel }}
            </button>
        </div>

        <h1 class="h4 mb-1">{{ t('cal_title') }}</h1>
        <p class="text-muted small mb-4">{{ t('cal_sub') }}</p>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-success" role="status">
                <span class="visually-hidden">{{ t('cal_loading') }}</span>
            </span>
        </div>

        <!-- A failed read is an error, never the "not published yet" card: that
             card would tell a parent there is no calendar when there may be one. -->
        <div v-else-if="error" class="alert alert-danger d-flex flex-wrap align-items-center gap-2" role="alert">
            <span>{{ tMessage(error) }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="load">{{ t('cal_retry') }}</button>
        </div>

        <div v-else-if="!calendar || !calendar.years.length" class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-calendar3 fs-2 d-block mb-2 text-muted"></i>
                <p class="mb-1 fw-semibold">{{ t('cal_empty_title') }}</p>
                <p class="text-muted small mb-0">{{ t('cal_empty_body') }}</p>
            </div>
        </div>

        <template v-else>
            <!-- Coming up: what a parent opens this for. -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h6 mb-3">{{ t('cal_upcoming') }}</h2>
                    <p v-if="!calendar.upcoming.length" class="text-muted small mb-0">{{ t('cal_no_upcoming') }}</p>
                    <ul v-else class="list-unstyled mb-0 d-flex flex-column gap-2">
                        <li v-for="day in calendar.upcoming" :key="day.date" class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold small">{{ formatSchoolDay(day.date, locale, { weekday: 'long', month: 'long', day: 'numeric' }) }}</span>
                            <span v-if="day.date === calendar.today" class="badge bg-success-subtle text-success-emphasis">{{ t('cal_today') }}</span>
                            <template v-if="day.closed">
                                <span class="badge bg-danger-subtle text-danger-emphasis">{{ t('cal_no_school') }}</span>
                                <!-- The school's own words, untranslated and fenced for direction. -->
                                <span v-if="day.reason" class="small" dir="auto">{{ day.reason }}</span>
                            </template>
                            <span v-else class="small text-muted">{{ t('cal_school_day') }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-if="calendar.years.length > 1" class="d-flex flex-wrap gap-2 mb-3" role="group" :aria-label="t('cal_year')">
                <button v-for="year in calendar.years" :key="year.id" type="button" class="btn btn-sm"
                        :class="selectedYearId === year.id ? 'btn-success' : 'btn-outline-secondary'"
                        :aria-pressed="selectedYearId === year.id"
                        dir="auto"
                        @click="selectedYearId = year.id">
                    {{ year.label }}
                </button>
            </div>

            <template v-if="selectedYear">
                <div class="mb-3">
                    <h2 class="h5 mb-1" dir="auto">{{ selectedYear.label }}</h2>
                    <div class="text-muted small">
                        {{ formatSchoolDay(selectedYear.first_day, locale, { month: 'long', day: 'numeric', year: 'numeric' }) }}
                        –
                        {{ formatSchoolDay(selectedYear.last_day, locale, { month: 'long', day: 'numeric', year: 'numeric' }) }}
                    </div>
                    <div class="small mt-1">{{ t('cal_meets_every', weekdayName(selectedYear.meeting_weekday, locale)) }}</div>
                </div>

                <SchoolCalendarList
                    :days="selectedDays"
                    :today="calendar.today"
                    :locale="locale"
                    :labels="{ today: t('cal_today'), noSchool: t('cal_no_school'), schoolDay: t('cal_school_day'), empty: t('cal_no_days') }"
                />
            </template>
        </template>
    </div>
</template>

<script setup lang="ts">
import FamilyApiService from '@/core/services/FamilyApiService';
import SchoolCalendarList from '@/components/common/SchoolCalendarList.vue';
import {
    SchoolCalendarReadPayload,
    daysOfYear,
    defaultYear,
    formatSchoolDay,
    readSchoolCalendarRead,
    weekdayName,
} from '@/core/types/data/masjid-related/SchoolCalendar';
import { useFamilyStore } from '@/stores/familyStore';
import { useFamilyLang } from '@/views/family/familyI18n';
import type { FamilyMessage } from '@/views/family/familyI18n';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * The school calendar, read-only, for a parent.
 *
 * FamilyApiService only — never the staff client (see its header). Dates go
 * through Intl with the portal's own locale (`ar-u-nu-latn` in Arabic: Arabic
 * month names, Western digits, matching every other number in the portal). The
 * year's label and a closure's reason are the school's words and are shown as
 * written, fenced with dir="auto".
 */

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();
const { lang, isAr, dir, locale, toggle, t, tMessage, switchLabel } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId));
const backIcon = computed(() => (isAr.value ? 'bi bi-arrow-right' : 'bi bi-arrow-left'));

const calendar = ref<SchoolCalendarReadPayload | null>(null);
const loading = ref(true);
const error = ref<FamilyMessage | null>(null);
const selectedYearId = ref<number | null>(null);

const selectedYear = computed(() => calendar.value?.years.find((y) => y.id === selectedYearId.value) ?? null);
const selectedDays = computed(() => (selectedYear.value ? daysOfYear(selectedYear.value) : []));

const load = async () => {
    loading.value = true;
    error.value = null;
    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${masjidId.value}/school-calendar`);
        const next = readSchoolCalendarRead(res.data?.data);
        if (!next) throw new Error('The calendar response was not a calendar.');

        calendar.value = next;
        if (!next.years.some((y) => y.id === selectedYearId.value)) {
            selectedYearId.value = defaultYear(next.years, next.today)?.id ?? null;
        }
    } catch (e: any) {
        if (familyStore.handleAuthFailure(e?.response?.status)) {
            router.replace(`/family/${masjidId.value}/sign-in`);
            return;
        }
        calendar.value = null;
        error.value = { key: 'cal_load_error' };
    } finally {
        loading.value = false;
    }
};

onMounted(load);
</script>
