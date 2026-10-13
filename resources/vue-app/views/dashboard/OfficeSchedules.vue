<template>
    <section class="office-schedules" aria-labelledby="office-schedules-title" lang="en">
        <h2 id="office-schedules-title">Schedules</h2>
        <div class="schedule-controls">
            <button v-for="view in views" :key="view.key" type="button" class="btn" :class="lens === view.key ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="lens === view.key" @click="chooseView(view.key)">{{ view.label }}</button>
            <div class="schedule-field">
                <label for="schedule-as-of">Showing the timetable as of</label>
                <input id="schedule-as-of" v-model="date" class="form-control" type="date" :min="setup.year.first_day" :max="setup.year.last_day" @change="typedDate" @blur="commitDate(true)" @keydown.enter.prevent="commitDate(true)" />
            </div>
            <div v-if="lens === 'teacher'" class="schedule-field">
                <label for="schedule-teacher">Teacher</label>
                <select id="schedule-teacher" v-model="teacherId" class="form-select" @change="load()">
                    <option value="">Choose a teacher</option>
                    <optgroup label="Teachers with meetings"><option v-for="teacher in placedTeachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option></optgroup>
                    <optgroup label="Teachers with nothing placed"><option v-for="teacher in unplacedTeachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option></optgroup>
                </select>
            </div>
            <div v-if="lens === 'class'" class="schedule-field">
                <label for="schedule-class">Class</label><select id="schedule-class" v-model="classId" class="form-select" @change="load()"><option value="">Choose a class</option><option v-for="group in setup.classes" :key="group.id" :value="group.id">{{ group.name }}</option></select>
            </div>
            <div v-if="lens === 'location'" class="schedule-field">
                <label for="schedule-location">Location</label><select id="schedule-location" v-model="locationId" class="form-select" @change="load()"><option value="">Choose a location</option><option v-for="room in setup.rooms" :key="room.id" :value="room.id">{{ room.name }}</option></select>
            </div>
            <template v-if="lens === 'school'">
                <div class="schedule-field">
                    <label for="schedule-weekday">Day</label><select id="schedule-weekday" v-model="weekday" class="form-select" @change="chooseDay"><option v-for="day in setup.weekdays" :key="day" :value="day">{{ weekdays[day] }}</option></select>
                </div>
                <button type="button" class="btn" :class="rowsBy === 'teacher' ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="rowsBy === 'teacher'" @click="rowsBy = 'teacher'">Teachers</button>
                <button type="button" class="btn" :class="rowsBy === 'class' ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="rowsBy === 'class'" @click="rowsBy = 'class'">Classes</button>
            </template>
            <button type="button" class="btn btn-outline-primary" :disabled="!page || loading" @click="printSchedule">Print</button>
            <a v-if="lens === 'class' && page?.may_edit" href="#class-week" @click.prevent="$emit('edit-class', Number(classId), page.as_of)">Edit this class's week</a>
        </div>
        <p v-if="error" role="alert" class="alert alert-danger">{{ error }} <button type="button" class="btn btn-outline-primary" @click="load(true)">Retry</button></p>
        <p v-if="loading" role="status">Loading schedule…</p>
        <div v-if="page && !loading" class="schedule-print">
            <table class="schedule-document">
                <thead><tr><th scope="col"><h3 class="h5">{{ heading }}</h3><p class="fw-normal">As of {{ dateLabel(page.as_of) }} · {{ setup.year.label }}</p><p class="fw-normal">{{ lens === 'school' ? dateLabel(page.day.date) : dateLabel(page.week_start) + ' – ' + dateLabel(page.week_end) }}</p></th></tr></thead>
                <tbody><tr><td>
                    <p v-if="(lens !== 'school' || page.day.date !== page.as_of) && page.as_of_state !== 'meetings'">{{ page.as_of_state === 'closure' ? 'School is closed on ' + dateLabel(page.as_of) + ': ' + sentenceEnd(page.as_of_reason) : 'The school does not meet on ' + dateLabel(page.as_of) + '.' }}</p>
                    <template v-if="lens === 'school'">
                        <p v-if="page.day.state !== 'meetings'" role="status">{{ dayWords(page.day) }}</p>
                        <p v-else-if="!page.columns.length">Choose this day's period sets in Setup.</p>
                        <div class="school-grid-scroll" tabindex="0" aria-label="Whole school day, scroll to see other times">
                            <table class="school-grid table table-bordered align-top">
                                <thead><tr><th scope="col" class="schedule-row-label">{{ rowsBy === 'teacher' ? 'Teacher' : 'Class' }}</th><th v-for="column in page.columns" :key="column.key" scope="col"><div class="school-column-name">{{ column.names.join(' / ') }}</div><div v-if="column.starts_at" class="school-column-time small fw-normal"><bdi dir="ltr">{{ schoolTimeRange(column.starts_at, column.ends_at) }}</bdi></div></th></tr></thead>
                                <tbody><tr v-for="row in rows" :key="row.id" :data-row-id="row.id">
                                    <th scope="row" class="schedule-row-label">{{ row.name }}</th>
                                    <td v-for="column in page.columns" :key="column.key" :data-clock="column.starts_at">
                                        <div v-for="meeting in cells(row.id, column)" :key="meeting.id" :data-meeting-id="meeting.id">
                                            <span v-if="clashTitle(meeting, row.id)" class="badge text-bg-warning" :title="clashTitle(meeting, row.id)">Clash</span>
                                            <ScheduleMeeting :meeting="forRow(meeting, row.id)" :show-time="meeting.starts_at !== column.starts_at || meeting.ends_at !== column.ends_at" :week-start="page.week_start" :week-end="page.week_end" />
                                        </div>
                                    </td>
                                </tr></tbody>
                            </table>
                        </div>
                        <p v-if="!rows.length">{{ rowsBy === 'teacher' ? 'Add teacher accounts first.' : 'Add a class first.' }}</p>
                    </template>
                    <template v-else>
                        <p v-if="!page.days.some((day: any) => day.meetings.length)">Nothing is placed {{ lens === 'teacher' ? 'for this teacher' : lens === 'class' ? 'for this class' : 'at this location' }} yet.</p>
                        <ScheduleWeek :days="page.days" :week-start="page.week_start" :week-end="page.week_end" />
                    </template>
                    <div v-if="(lens === 'school' || lens === 'teacher') && unplacedTeachers.length" class="mt-3"><h4 class="h6">Teachers with nothing placed</h4><ul><li v-for="teacher in unplacedTeachers" :key="teacher.id">{{ teacher.name }}</li></ul></div>
                </td></tr></tbody>
            </table>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApiService from '@/core/services/ApiService';
import { formatSchoolDay, weekdayOfIso } from '@/core/types/data/masjid-related/SchoolCalendar';
import { schoolTimeRange } from '@/core/helpers/scheduledSend';
import ScheduleMeeting from '../teacher/ScheduleMeeting.vue';
import ScheduleWeek from '../teacher/ScheduleWeek.vue';
const props = defineProps<{ base: string; setup: any }>();
defineEmits<{ 'edit-class': [id: number, date: string] }>();
const lens = ref('school'); const rowsBy = ref('teacher'); const page = ref<any>(null);
const date = ref([props.setup.year.first_day, props.setup.today].sort().at(-1)! > props.setup.year.last_day ? props.setup.year.last_day : [props.setup.year.first_day, props.setup.today].sort().at(-1)!);
const teacherId = ref<number | string>(''); const classId = ref<number | string>(props.setup.classes[0]?.id ?? ''); const locationId = ref<number | string>(props.setup.rooms[0]?.id ?? '');
const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const weekday = ref<number | string>('');
let explicitWeekday = false;
const loading = ref(false); const error = ref(''); const placedIds = ref<number[]>([]);
const views = computed(() => [{ key: 'school', label: 'Whole school' }, { key: 'teacher', label: 'By teacher' }, { key: 'class', label: 'By class' }, ...(props.setup.rooms.length ? [{ key: 'location', label: 'By location' }] : [])]);
const placedTeachers = computed(() => props.setup.teachers.filter((t: any) => placedIds.value.includes(t.id)));
const unplacedTeachers = computed(() => props.setup.teachers.filter((t: any) => !placedIds.value.includes(t.id)));
const rows = computed(() => rowsBy.value === 'teacher' ? page.value?.teachers ?? [] : page.value?.classes ?? []);
const heading = computed(() => lens.value === 'school' ? `Whole school · ${rowsBy.value === 'teacher' ? 'Teachers' : 'Classes'}` : (lens.value === 'teacher' ? props.setup.teachers.find((t: any) => t.id === Number(teacherId.value))?.name : lens.value === 'class' ? props.setup.classes.find((g: any) => g.id === Number(classId.value))?.name : props.setup.rooms.find((r: any) => r.id === Number(locationId.value))?.name) ?? 'Schedule');
const dateLabel = (iso: string) => formatSchoolDay(iso, 'en-US', { month: 'long', day: 'numeric', year: 'numeric' });
const boundsMessage = () => `Pick a date between ${dateLabel(props.setup.year.first_day)} and ${dateLabel(props.setup.year.last_day)}.`;
const sentenceEnd = (reason: string) => reason.trim().replace(/\.+$/, '') + '.';
const dayWords = (day: any) => day.state === 'closure' ? `School is closed on ${dateLabel(day.date)}: ${sentenceEnd(day.reason)}` : day.state === 'outside_year' ? 'This day is outside the school year.' : 'The school does not meet on this day.';
function cells(rowId: number, column: any) {
    const list = page.value.meetings.filter((m: any) => (rowsBy.value === 'teacher' ? m.teachers.some((t: any) => t.id === rowId) : m.group_id === rowId) && m.column_key === column.key);
    // A teacher who takes two classes at once has one lesson there: the first class's card names the others.
    return list.filter((m: any, i: number) => !list.slice(0, i).some((first: any) => first.joint?.some((other: any) => other.id === m.id)));
}
// The other classes' lessons a teacher's card stands for: the ones this teacher is placed with as well.
function together(meeting: any, rowId: number) {
    if (rowsBy.value !== 'teacher') return [];
    return (meeting.joint ?? []).map((other: any) => page.value.meetings.find((m: any) => m.id === other.id)).filter((m: any) => m?.teachers.some((t: any) => t.id === rowId));
}
function forRow(meeting: any, rowId: number) {
    const teachers = [meeting, ...together(meeting, rowId)].flatMap((m: any) => m.teachers);
    return { ...meeting, co_teachers: teachers.filter((t: any, i: number) => (rowsBy.value !== 'teacher' || t.id !== rowId) && teachers.findIndex((first: any) => first.id === t.id) === i) };
}
function clashTitle(meeting: any, rowId: number) {
    const ids = [meeting.id, ...together(meeting, rowId).map((m: any) => m.id)];
    const titles = page.value.clashes.filter((c: any) => c.meetings.some((m: any) => ids.includes(m.id)) && (rowsBy.value === 'class' || c.kind !== 'teacher' || c.id === rowId))
        .flatMap((c: any) => c.meetings.filter((m: any) => !ids.includes(m.id)).map((m: any) => `${c.name}: ${m.group_name} (${m.label}), ${schoolTimeRange(m.starts_at, m.ends_at)}`));
    return [...new Set(titles)].join('; ');
}
let generation = 0; let lastKey = ''; let committedDate = date.value;
// As on the class week: a complete in-year date loads at once, a half-typed year waits.
let typing: ReturnType<typeof setTimeout> | undefined;
function typedDate() { clearTimeout(typing); typing = setTimeout(() => { if (date.value >= '2000-01-01') commitDate(true); }, 400); }
function commitDate(leaving: boolean) {
    clearTimeout(typing);
    const inYear = date.value >= props.setup.year.first_day && date.value <= props.setup.year.last_day;
    if (!leaving && !inYear) return;
    if (leaving && date.value === committedDate) return;
    if (date.value !== committedDate) { explicitWeekday = false; committedDate = date.value; }
    void load();
}
function chooseView(view: string) {
    lens.value = view;
    if (view === 'teacher' && !teacherId.value) teacherId.value = placedTeachers.value[0]?.id ?? props.setup.teachers[0]?.id ?? '';
    void load();
}
function chooseDay() { explicitWeekday = true; void load(); }
async function load(retry = false) {
    const selected = lens.value === 'teacher' ? teacherId.value : lens.value === 'class' ? classId.value : locationId.value;
    const path = `${props.base}/schedules/${lens.value}${lens.value === 'school' ? '' : '/' + selected}`;
    // Omit an unchosen weekday so the server can skip closures when choosing the default.
    const key = `${path}?as_of=${date.value}${lens.value === 'school' && explicitWeekday ? '&weekday=' + weekday.value : ''}`;
    if (!retry && key === lastKey) return;
    lastKey = key; const token = ++generation; page.value = null; error.value = ''; loading.value = false;
    if (weekdayOfIso(date.value) === null || date.value < props.setup.year.first_day || date.value > props.setup.year.last_day) { error.value = boundsMessage(); return; }
    if (lens.value !== 'school' && !selected) return;
    loading.value = true;
    try {
        const response = await ApiService.get(key as any);
        if (token === generation) { page.value = response.data.data; placedIds.value = page.value.placed_teacher_ids; if (lens.value === 'school') weekday.value = page.value.weekday; }
    } catch (failure: any) { if (token === generation) error.value = failure?.response?.status === 422 ? boundsMessage() : 'The schedule could not be loaded. Try again.'; }
    finally { if (token === generation) loading.value = false; }
}
function stopPrinting() { document.body?.classList.remove('timetable-printing'); }
function printSchedule() {
    document.body.classList.add('timetable-printing');
    try { window.print(); } finally { stopPrinting(); }
}
onMounted(() => { void load(); if (typeof window !== 'undefined') window.addEventListener('afterprint', stopPrinting); });
onBeforeUnmount(() => { ++generation; clearTimeout(typing); stopPrinting(); if (typeof window !== 'undefined') window.removeEventListener('afterprint', stopPrinting); });
</script>

<style scoped>
.office-schedules { min-width: 0; max-width: 100%; text-align: start; }
.schedule-controls { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-block: 1rem; }
.schedule-field { display: flex; align-items: center; gap: .5rem; min-width: 0; max-width: 100%; }
.schedule-field label { flex-shrink: 0; }
.schedule-controls input, .schedule-controls select { width: auto; max-width: 100%; min-width: 0; min-height: 44px; }
.schedule-controls button { min-height: 44px; }
.schedule-print, .schedule-document { width: 100%; min-width: 0; max-width: 100%; }
.schedule-document { table-layout: fixed; }
.schedule-document > tbody > tr > td { padding: 0; }
.school-grid-scroll { max-width: 100%; overflow-x: auto; overscroll-behavior-inline: contain; }
.school-grid { width: max-content; min-width: 100%; margin-block-end: 0; }
.school-grid th, .school-grid td { min-width: 10rem; max-width: 13rem; overflow-wrap: anywhere; }
.school-column-name, .school-column-time { white-space: normal; }
.schedule-row-label { position: sticky; inset-inline-start: 0; z-index: 1; background: var(--bs-body-bg) !important; }
@media (max-width: 820px) {
    .schedule-field { flex-direction: column; align-items: stretch; width: 100%; }
    .schedule-field input, .schedule-field select { width: 100%; }
    .school-grid { table-layout: fixed; }
    .school-grid th, .school-grid td { width: 150px; min-width: 150px; max-width: 150px; }
    .school-grid .schedule-row-label { width: 96px; min-width: 96px; max-width: 96px; }
}
@media (max-width: 400px) {
    .school-grid-scroll { width: calc(100% + 1rem); max-width: none; margin-inline: -.5rem; }
}
@page timetable-schedule { size: landscape; margin: 10mm; }
@media print {
    :global(body.timetable-printing *:not(:has(.schedule-print)):not(.schedule-print):not(.schedule-print *)) { display: none !important; }
    :global(body.timetable-printing *:has(.schedule-print)) { display: block !important; position: static !important; width: auto !important; max-width: none !important; min-width: 0 !important; margin: 0 !important; padding: 0 !important; overflow: visible !important; }
    .schedule-print { page: timetable-schedule; font-size: 7pt; line-height: 1.15; }
    .school-grid-scroll { overflow: visible; width: 100%; max-width: 100%; margin-inline: 0; }
    .school-grid { width: 100%; table-layout: fixed; font-size: inherit !important; }
    .school-grid th, .school-grid td { width: auto; min-width: 0; max-width: none; padding: .1rem .15rem !important; overflow-wrap: normal; word-break: normal; white-space: normal !important; font-size: inherit !important; letter-spacing: normal !important; }
    .school-grid .schedule-row-label { width: 9%; min-width: 0; max-width: none; }
    .school-column-name, .school-column-time { display: block; white-space: normal; font-size: inherit; letter-spacing: normal; }
    .schedule-print h3 { font-size: 10pt; margin-block-end: .2rem; }
    .schedule-print p { margin-block-end: .3rem; }
    .schedule-print ul { margin-block-end: 0; }
    .schedule-row-label { position: static; }
    thead { display: table-header-group; }
    .school-grid tbody > tr { break-inside: avoid; }
    .schedule-document > tbody > tr { break-inside: auto; }
}
</style>
