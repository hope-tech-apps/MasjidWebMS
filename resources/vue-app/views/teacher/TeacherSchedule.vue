<template>
    <div class="teacher-schedule" :dir="dir" :lang="lang">
        <div class="schedule-heading">
            <h1 class="h4 mb-0">{{ t('schedule_title') }}</h1>
            <select class="form-select form-select-sm schedule-language" :value="lang" :aria-label="t('switch_lang_title')"
                    @change="setLang(($event.target as HTMLSelectElement).value)">
                <option v-for="option in languages" :key="option.code" :value="option.code" :lang="option.code" :dir="option.dir">{{ option.label }}</option>
            </select>
        </div>
        <p v-if="loading && !page" role="status">{{ t('schedule_loading') }}</p>
        <div v-if="error" role="alert" class="alert alert-danger">
            {{ t(unavailable ? 'schedule_unavailable' : 'schedule_error') }}
            <button v-if="!unavailable" type="button" class="btn btn-outline-secondary" @click="load(requestedWeek)">{{ t('schedule_retry') }}</button>
        </div>
        <template v-if="page">
            <section class="mt-3" aria-labelledby="schedule-today">
                <h2 id="schedule-today" class="h5">{{ t('schedule_today') }} <span class="small text-muted">{{ dateLabel(page.today.date) }}</span></h2>
                <p v-if="page.today.state !== 'meetings'" role="status">{{ t(`schedule_${page.today.state}`, page.today.reason ?? '') }}</p>
                <div v-else class="today-meetings">
                    <ScheduleMeeting v-for="meeting in page.today.meetings" :key="meeting.id" :meeting="meeting"
                                     :week-start="page.week_start" :week-end="page.week_end" :status="status(meeting)" data-test="today-meeting" />
                </div>
            </section>
            <section class="mt-4" aria-labelledby="schedule-week" :aria-busy="loading">
                <h2 id="schedule-week" class="h5">{{ t('schedule_week') }}</h2>
                <div class="week-picker">
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="move(-7)">{{ t('schedule_previous') }}</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="load(null)">{{ t('schedule_this_week') }}</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="move(7)">{{ t('schedule_next_week') }}</button>
                </div>
                <p class="small text-muted mt-2"><bdi>{{ dateLabel(page.week_start) }} – {{ dateLabel(page.week_end) }}</bdi></p>
                <p v-if="loading" role="status">{{ t('schedule_loading') }}</p>
                <p v-if="page.days.length === 0">{{ t('schedule_unplaced') }}</p>
                <div v-else class="schedule-week-scroll" tabindex="0" :aria-label="t('schedule_week')">
                    <div class="schedule-week" :style="{ '--schedule-days': page.days.length }">
                        <section v-for="day in page.days" :key="day.date" class="schedule-day" data-test="week-day">
                            <h3 class="h6">{{ dateLabel(day.date, true) }}</h3>
                            <p v-if="day.reason" class="small">{{ t('schedule_closure', day.reason) }}</p>
                            <p v-else-if="day.date === page.today.date && !day.meetings.length" class="small">{{ t(`schedule_${page.today.state}`, page.today.reason ?? '') }}</p>
                            <div class="day-meetings">
                                <ScheduleMeeting v-for="meeting in day.meetings" :key="meeting.id" :meeting="meeting"
                                                 :week-start="page.week_start" :week-end="page.week_end" />
                            </div>
                        </section>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import TeacherApiService from '@/core/services/TeacherApiService';
import { useAuthStore } from '@/stores/authStore';
import { useFamilyLang } from '@/views/family/familyI18n';
import ScheduleMeeting, { type ScheduleSlot } from './ScheduleMeeting.vue';

interface ScheduleDay { date: string; weekday: number; reason: string | null; meetings: ScheduleSlot[]; }
interface SchedulePage { timezone: string; week_start: string; week_end: string; today: ScheduleDay & { state: string }; days: ScheduleDay[]; }
const { lang, locale, dir, t, setLang, languages } = useFamilyLang();
const auth = useAuthStore();
const page = ref<SchedulePage | null>(null);
const loading = ref(false); const error = ref(false); const unavailable = ref(false);
const requestedWeek = ref<string | null>(null);
const clock = ref(Date.now());
let alive = true; let generation = 0; let timer: ReturnType<typeof setInterval> | undefined;
const schoolClock = computed(() => {
    if (!page.value) return null;
    // The API returns the calendar's resolved timezone, including its existing unset/UTC fallback.
    // Convert the device instant once, then compare wall clocks; never parse a slot as device-local time.
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone:page.value.timezone, year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'})
        .formatToParts(new Date(clock.value)).map(part=>[part.type,part.value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${parts.minute}` };
});
const nextId = computed(() => schoolClock.value?.date === page.value?.today.date
    ? page.value?.today.meetings.find(meeting => meeting.starts_at > schoolClock.value!.time)?.id : undefined);
const status = (meeting: ScheduleSlot): 'now' | 'next' | null => {
    if (!schoolClock.value || schoolClock.value.date !== page.value?.today.date) return null;
    if (meeting.starts_at <= schoolClock.value.time && schoolClock.value.time < meeting.ends_at) return 'now';
    return meeting.id === nextId.value ? 'next' : null;
};
const dateLabel = (date: string, weekday = false) => new Intl.DateTimeFormat(lang.value === 'en' ? 'en-GB' : locale.value,
    {timeZone:'UTC',month:'long',day:'numeric',...(weekday ? {weekday:'long' as const} : {})}).format(new Date(`${date}T12:00:00Z`));
async function load(week: string | null): Promise<void> {
    const school = Number(auth.dashboardMasjidId); if (!Number.isInteger(school) || school < 1) { error.value = true; return; }
    const token = ++generation; loading.value = true; error.value = false; unavailable.value = false; requestedWeek.value = week;
    try {
        const response = await TeacherApiService.get(`/api/teacher/masjids/${school}/schedule${week ? `?week=${encodeURIComponent(week)}` : ''}`);
        if (alive && token === generation) { page.value = response.data.data; clock.value = Date.now(); }
    } catch (failure: any) {
        if (alive && token === generation) { page.value = null; error.value = true; unavailable.value = failure?.response?.status === 404; }
    } finally { if (alive && token === generation) loading.value = false; }
}
function move(days: number): void {
    if (!page.value || loading.value) return;
    const date = new Date(`${page.value.week_start}T12:00:00Z`); date.setUTCDate(date.getUTCDate()+days);
    void load(date.toISOString().slice(0,10));
}
onMounted(() => { void load(null); timer = setInterval(() => { clock.value = Date.now(); }, 30000); });
onBeforeUnmount(() => { alive = false; ++generation; if (timer !== undefined) clearInterval(timer); });
</script>

<style src="../family/urduFont.css"></style>

<style scoped>
.teacher-schedule { min-width: 0; max-width: 100%; text-align: start; }
.schedule-heading { display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; }
.schedule-language { width: auto; max-width: 100%; min-height: 2.75rem; }
.today-meetings, .day-meetings { display: grid; gap: .5rem; }
.week-picker { display: flex; flex-wrap: wrap; gap: .5rem; }
.week-picker button { min-height: 2.75rem; white-space: normal; }
.schedule-week-scroll { width: 100%; max-width: 100%; overflow-x: auto; padding-block-end: .5rem; }
.schedule-week { display: grid; grid-template-columns: repeat(var(--schedule-days), minmax(12rem, 1fr)); gap: .75rem; }
.schedule-day { min-width: 0; overflow-wrap: anywhere; }
[dir="rtl"] .schedule-language { background-position: left .5rem center; padding-left: 2rem; padding-right: .75rem; }
</style>
