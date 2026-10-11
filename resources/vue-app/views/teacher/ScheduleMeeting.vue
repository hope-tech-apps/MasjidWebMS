<template>
    <article class="schedule-meeting border rounded" :class="{ 'is-now border-success': status === 'now' }">
        <div v-if="showTime !== false" class="schedule-clock"><bdi dir="ltr">{{ schoolTimeRange(meeting.starts_at, meeting.ends_at) }}</bdi></div>
        <span v-if="status" class="schedule-status badge text-bg-success">{{ status === 'now' ? 'Happening now' : 'Next' }}</span>
        <RouterLink v-if="meeting.href" :to="meeting.href" class="schedule-class"><bdi dir="auto">{{ meeting.group_name }}</bdi></RouterLink>
        <div v-else class="schedule-class"><bdi dir="auto">{{ meeting.group_name }}</bdi></div>
        <div v-if="meeting.kind !== 'class'" dir="auto">{{ meeting.label }}</div>
        <div v-if="meeting.co_teachers.length" class="small text-muted"><bdi dir="auto">{{ meeting.co_teachers.map(person => person.name).join(', ') }}</bdi></div>
        <div v-if="meeting.room_name" class="small" data-test="location"><bdi dir="auto">{{ meeting.room_name }}</bdi></div>
        <div v-if="inWeek(meeting.effective_from)" class="small text-muted">From {{ dateLabel(meeting.effective_from) }}</div>
        <div v-if="meeting.effective_until && inWeek(meeting.effective_until)" class="small text-muted">Until {{ dateLabel(meeting.effective_until) }}</div>
    </article>
</template>

<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
import { schoolTimeRange } from '@/core/helpers/scheduledSend';

export interface ScheduleSlot {
    id: number; group_id: number; group_name: string; kind: string; label: string;
    starts_at: string; ends_at: string; room_name: string | null; href: string | null;
    co_teachers: { id: number; name: string }[]; effective_from: string; effective_until: string | null;
}
const props = withDefaults(defineProps<{ meeting: ScheduleSlot; status?: 'now' | 'next' | null; showTime?: boolean; weekStart: string; weekEnd: string }>(), { showTime: true });
const inWeek = (date: string) => date >= props.weekStart && date <= props.weekEnd;
const dateLabel = (date: string) => formatSchoolDay(date, 'en-US', { month: 'long', day: 'numeric', year: 'numeric' });
</script>

<style scoped>
.schedule-meeting { position: relative; padding: .75rem; border-radius: .5rem; overflow-wrap: anywhere; text-align: start; }
.schedule-meeting.is-now { border-inline-start-width: .25rem !important; }
.schedule-clock { font-variant-numeric: tabular-nums; font-size: .875rem; }
.schedule-class { display: block; font-weight: 600; padding-block: .25rem; min-height: 2.75rem; }
@media print { .schedule-meeting { break-inside: avoid; padding: .1rem .2rem; overflow-wrap: normal; word-break: normal; font-size: inherit; } .schedule-class { min-height: 0; padding-block: .1rem; } .schedule-clock { font-size: inherit; } }
.schedule-status { display: inline-block; font-size: .8rem; border-radius: .25rem; padding: .1rem .4rem; margin-block: .25rem; }
</style>
