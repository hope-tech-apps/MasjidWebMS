<template>
    <div class="schedule-week-scroll" tabindex="0" aria-label="Week">
        <div class="schedule-week" :style="{ '--schedule-days': days.length }">
            <section v-for="day in days" :key="day.date" class="schedule-day" data-test="week-day">
                <h3 class="h6">{{ dateLabel(day.date) }}</h3>
                <p v-if="day.reason" class="small">No school: {{ day.reason }}</p>
                <p v-else-if="day.state === 'outside_year'" class="small">Outside the school year.</p>
                <p v-else-if="!day.meetings.length && !day.periods" class="small">{{ emptyWords?.(day.date) || 'Nothing is placed on this day.' }}</p>
                <div v-if="!day.reason && day.state !== 'outside_year'" class="day-meetings">
                    <template v-if="day.periods">
                        <p v-if="!day.periods.length">Choose this weekday's period set in Setup.</p>
                        <article v-for="period in day.periods" :key="period.id" class="schedule-period border rounded p-2" :class="{ 'bg-body-tertiary': period.kind === 'block' }">
                            <h4 class="h6">{{ period.name }}</h4>
                            <ScheduleMeeting v-if="day.meetings.find((m: any) => m.period_id === period.id)" :meeting="day.meetings.find((m: any) => m.period_id === period.id)" :week-start="weekStart" :week-end="weekEnd" />
                            <bdi v-else dir="ltr">{{ schoolTimeRange(period.starts_at, period.ends_at) }}</bdi>
                        </article>
                    </template>
                    <ScheduleMeeting v-else v-for="meeting in day.meetings" :key="meeting.id" :meeting="meeting" :week-start="weekStart" :week-end="weekEnd" />
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
import { schoolTimeRange } from '@/core/helpers/scheduledSend';
import ScheduleMeeting from './ScheduleMeeting.vue';
defineProps<{ days: any[]; weekStart: string; weekEnd: string; emptyWords?: (date: string) => string }>();
const dateLabel = (date: string) => formatSchoolDay(date, 'en-US', { month: 'long', day: 'numeric', year: 'numeric', weekday: 'long' });
</script>

<style scoped>
.schedule-week-scroll { width: 100%; max-width: 100%; overflow-x: auto; padding-block-end: .5rem; }
.schedule-week { min-width: max-content; display: grid; grid-template-columns: repeat(var(--schedule-days), minmax(12rem, 1fr)); gap: .75rem; }
.schedule-day { min-width: 0; overflow-wrap: anywhere; }
.schedule-day:first-child { position: sticky; inset-inline-start: 0; z-index: 1; background: var(--bs-body-bg); }
.day-meetings { display: grid; gap: .5rem; }
@media print {
    .schedule-week-scroll { overflow: visible; }
    .schedule-week { min-width: 0; grid-template-columns: repeat(var(--schedule-days), minmax(0, 1fr)); }
    .schedule-day { overflow-wrap: normal; word-break: normal; }
    .schedule-day:first-child { position: static; }
    .schedule-day h3 { break-after: avoid; }
    .schedule-period { break-inside: avoid; }
}
</style>
