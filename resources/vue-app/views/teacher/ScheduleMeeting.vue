<template>
    <article class="schedule-meeting" :class="{ 'is-now': status === 'now' }">
        <div class="schedule-clock"><bdi dir="ltr">{{ meeting.starts_at }}–{{ meeting.ends_at }}</bdi></div>
        <span v-if="status" class="schedule-status">{{ t(status === 'now' ? 'schedule_now' : 'schedule_next') }}</span>
        <RouterLink :to="meeting.href" class="schedule-class"><bdi dir="auto">{{ meeting.group_name }}</bdi></RouterLink>
        <div v-if="meeting.kind !== 'class'" dir="auto">{{ meeting.label }}</div>
        <div v-if="meeting.co_teachers.length" class="small text-muted"><bdi dir="auto">{{ meeting.co_teachers.map(person => person.name).join(', ') }}</bdi></div>
        <div v-if="meeting.room_name" class="small" data-test="location"><bdi dir="auto">{{ meeting.room_name }}</bdi></div>
        <div v-if="inWeek(meeting.effective_from)" class="small text-muted">{{ t('schedule_from', dateLabel(meeting.effective_from)) }}</div>
        <div v-if="meeting.effective_until && inWeek(meeting.effective_until)" class="small text-muted">{{ t('schedule_until', dateLabel(meeting.effective_until)) }}</div>
    </article>
</template>

<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { useFamilyLang } from '@/views/family/familyI18n';

export interface ScheduleSlot {
    id: number; group_id: number; group_name: string; kind: string; label: string;
    starts_at: string; ends_at: string; room_name: string | null; href: string;
    co_teachers: { id: number; name: string }[]; effective_from: string; effective_until: string | null;
}
const props = defineProps<{ meeting: ScheduleSlot; status?: 'now' | 'next' | null; weekStart: string; weekEnd: string }>();
const { lang, locale, t } = useFamilyLang();
const inWeek = (date: string) => date >= props.weekStart && date <= props.weekEnd;
const dateLabel = (date: string) => new Intl.DateTimeFormat(lang.value === 'en' ? 'en-GB' : locale.value,
    { timeZone: 'UTC', day: 'numeric', month: 'long' }).format(new Date(`${date}T12:00:00Z`));
</script>

<style scoped>
.schedule-meeting { position: relative; padding: .75rem; border: 1px solid #dfe3e8; border-radius: .5rem; overflow-wrap: anywhere; text-align: start; }
.schedule-meeting.is-now { border-color: #198754; border-inline-start: .25rem solid #198754; }
.schedule-clock { font-variant-numeric: tabular-nums; font-size: .875rem; }
.schedule-class { display: block; font-weight: 600; padding-block: .25rem; min-height: 2.75rem; }
.schedule-status { display: inline-block; font-size: .8rem; color: #146c43; background: #d1e7dd; border-radius: .25rem; padding: .1rem .4rem; margin-block: .25rem; }
</style>
