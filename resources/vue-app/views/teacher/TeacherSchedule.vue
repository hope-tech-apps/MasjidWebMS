<template>
    <div class="teacher-schedule" lang="en">
        <div class="schedule-heading">
            <h1 class="h4 mb-0">My schedule</h1>
        </div>
        <p v-if="loading && !page" role="status">Loading your schedule…</p>
        <div v-if="error" role="alert" class="alert alert-danger">
            {{ unavailable ? 'My schedule is not available at this school.' : 'Your schedule could not be loaded.' }}
            <button v-if="!unavailable" type="button" class="btn btn-outline-secondary" @click="load(requestedWeek)">Try again</button>
        </div>
        <template v-if="page">
            <section class="mt-3" aria-labelledby="schedule-today">
                <h2 id="schedule-today" class="h5">Today <span class="small text-muted">{{ dateLabel(page.today.date) }}</span></h2>
                <p v-if="page.today.state !== 'meetings'" role="status">{{ todayWords(page.today.state, page.today.reason) }}</p>
                <div v-else class="today-meetings">
                    <ScheduleMeeting v-for="meeting in page.today.meetings" :key="meeting.id" :meeting="meeting"
                                     :week-start="page.week_start" :week-end="page.week_end" :status="status(meeting)" data-test="today-meeting" />
                </div>
            </section>
            <section class="mt-4" aria-labelledby="schedule-week" :aria-busy="loading">
                <h2 id="schedule-week" class="h5">Week</h2>
                <div class="week-picker">
                    <label for="schedule-week-date">Week containing</label>
                    <input id="schedule-week-date" v-model="weekDate" type="date" class="form-control" @change="commitWeek(false)" @blur="commitWeek(true)" @keydown.enter.prevent="commitWeek(true)" />
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="move(-7)">Previous week</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="load(null)">This week</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="move(7)">Next week</button>
                </div>
                <p class="small text-muted mt-2"><bdi>{{ dateLabel(page.week_start) }} – {{ dateLabel(page.week_end) }}</bdi></p>
                <p v-if="loading" role="status">Loading your schedule…</p>
                <p v-if="page.days.length === 0">Nothing is placed for you yet.</p>
                <div v-else class="schedule-week-scroll" tabindex="0" aria-label="Week">
                    <div class="schedule-week" :style="{ '--schedule-days': page.days.length }">
                        <section v-for="day in page.days" :key="day.date" class="schedule-day" data-test="week-day">
                            <h3 class="h6">{{ dateLabel(day.date, true) }}</h3>
                            <p v-if="day.reason" class="small">No school: {{ day.reason }}</p>
                            <p v-else-if="day.date === page.today.date && !day.meetings.length" class="small">{{ todayWords(page.today.state, page.today.reason) }}</p>
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
import { formatSchoolDay, weekdayOfIso } from '@/core/types/data/masjid-related/SchoolCalendar';
import ScheduleMeeting, { type ScheduleSlot } from './ScheduleMeeting.vue';

interface ScheduleDay { date: string; weekday: number; reason: string | null; meetings: ScheduleSlot[]; }
interface SchedulePage { timezone: string; week_start: string; week_end: string; today: ScheduleDay & { state: string }; days: ScheduleDay[]; }
const weekDate = ref('');
const todayWords = (state: string, reason?: string | null) => ({ non_meeting: 'The school does not meet today.', closure: `No school today: ${reason ?? ''}`, not_started: 'The school year has not started.', ended: 'The school year has ended.', unplaced: 'Nothing is placed for you yet.' }[state] ?? '');
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
const dateLabel = (date: string, weekday = false) => formatSchoolDay(date, 'en-US',
    { month: 'long', day: 'numeric', year: 'numeric', ...(weekday ? { weekday: 'long' as const } : {}) });
// A complete date loads as soon as the box reports it. While a year is still being typed the
// box reports years like 0002 and 0020; those wait for Enter or leaving the box.
function commitWeek(leaving: boolean): void {
    if (weekdayOfIso(weekDate.value) === null) return;
    if (!leaving && weekDate.value < '2000-01-01') return;
    const date = new Date(`${weekDate.value}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() - ((date.getUTCDay() + 6) % 7));
    const week = date.toISOString().slice(0,10);
    if (week !== (requestedWeek.value ?? page.value?.week_start)) void load(week);
}
async function load(week: string | null): Promise<void> {
    const school = Number(auth.dashboardMasjidId); if (!Number.isInteger(school) || school < 1) { error.value = true; return; }
    const enteredDate = weekDate.value;
    const token = ++generation; loading.value = true; error.value = false; unavailable.value = false; requestedWeek.value = week;
    try {
        const response = await TeacherApiService.get(`/api/teacher/masjids/${school}/schedule${week ? `?week=${encodeURIComponent(week)}` : ''}`);
        if (alive && token === generation) { page.value = response.data.data; if (weekDate.value === enteredDate) weekDate.value = page.value!.week_start; clock.value = Date.now(); }
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

<style scoped>
.teacher-schedule { min-width: 0; max-width: 100%; text-align: start; }
.schedule-heading { display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; }
.today-meetings, .day-meetings { display: grid; gap: .5rem; }
.week-picker { display: flex; flex-wrap: wrap; gap: .5rem; }
.week-picker input { width: auto; min-height: 2.75rem; }
.week-picker button { min-height: 2.75rem; white-space: normal; }
.schedule-week-scroll { width: 100%; max-width: 100%; overflow-x: auto; padding-block-end: .5rem; }
.schedule-week { display: grid; grid-template-columns: repeat(var(--schedule-days), minmax(12rem, 1fr)); gap: .75rem; }
.schedule-day { min-width: 0; overflow-wrap: anywhere; }
</style>
