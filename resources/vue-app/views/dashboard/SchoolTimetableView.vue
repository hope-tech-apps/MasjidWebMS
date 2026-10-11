<template>
    <section v-if="enabled" class="timetable-page">
        <header class="tt-toolbar">
            <h1>Timetable</h1>
            <label for="tt-year">School year</label>
            <select class="form-select" id="tt-year" v-model="yearId" @change="loadYear"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
            <button class="btn" :class="panel === 'week' ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="panel === 'week'" type="button" @click="showPanel('week')">Class week</button>
            <button class="btn" :class="panel === 'schedules' ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="panel === 'schedules'" type="button" @click="showPanel('schedules')">Schedules</button>
            <button class="btn" :class="panel === 'setup' ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="panel === 'setup'" type="button" @click="panel = 'setup'">Setup</button>
            <button class="btn" :class="panel === 'clashes' ? 'btn-primary active' : 'btn-outline-primary'" :aria-pressed="panel === 'clashes'" type="button" @click="showPanel('clashes')">Clashes{{ loadedDate ? ' (' + clashes.length + ')' : '' }}</button>
        </header>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="loading" role="status">Loading timetable…</p>
        <button class="btn btn-outline-primary" v-if="error && !editor && !outOfYear" type="button" @click="loadYear">Retry</button>
        <p v-if="!loading && !years.length">Add a school year in School Calendar first.</p>
        <div v-if="setup && !loading">
            <OfficeSchedules v-if="panel === 'schedules'" :key="base" :base="base" :setup="setup" @edit-class="editClassWeek" />
            <div v-else-if="panel === 'setup'" class="tt-setup">
                <h2>Period sets</h2>
                <p>This changes the times for the whole year.</p>
                <button class="btn btn-outline-primary" type="button" @click="editSet()">Add period set</button>
                <article v-for="set in setup.sets" :key="set.id">
                    <h3>{{ set.name }}</h3>
                    <ol><li v-for="p in set.periods" :key="p.id">{{ p.name }} <bdi dir="ltr">{{ schoolTimeRange(p.starts_at, p.ends_at) }}</bdi> ({{ p.kind }})</li></ol>
                    <button class="btn btn-outline-primary" type="button" @click="editSet(set)">Edit {{ set.name }}</button>
                    <button class="btn btn-outline-primary" type="button" @click="removeSet(set)">Remove {{ set.name }}</button>
                </article>
                <h2>Weekday period sets</h2>
                <p>This changes the times for the whole year.</p>
                <div v-for="day in setup.weekdays" :key="day" class="tt-field">
                    <label :for="'school-day-' + day">{{ weekdays[day] }}</label>
                    <select class="form-select" :id="'school-day-' + day" :value="schoolSet(day)" @change="saveDay(day, numberValue($event))"><option value="">Choose a set</option><option v-for="s in setup.sets" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </div>
                <h2>Locations</h2>
                <p>Locations are optional. The timetable uses class names; you can add a location later on the class page.</p>
                <table class="table table-sm" aria-label="Class locations">
                    <thead><tr><th scope="col">Class</th><th scope="col">Location</th></tr></thead>
                    <tbody>
                        <tr v-for="g in setup.classes" :key="g.id">
                            <th scope="row" class="fw-normal text-break">{{ g.name }}</th>
                            <td class="text-break">{{ setup.rooms.find((r: any) => r.id === g.room_id)?.name ?? 'No location set' }}</td>
                        </tr>
                    </tbody>
                </table>
                <button class="btn btn-outline-primary" type="button" @click="editRoom()">Add location</button>
                <article v-for="room in setup.rooms" :key="room.id">
                    <p>{{ room.name }}<span v-if="room.capacity"> · Capacity {{ room.capacity }}</span> · {{ room.active ? 'Active' : 'Inactive' }}</p>
                    <button class="btn btn-outline-primary" type="button" @click="editRoom(room)">Edit {{ room.name }}</button>
                    <button class="btn btn-outline-primary" type="button" @click="removeRoom(room)">Remove {{ room.name }}</button>
                </article>
                <h2>Class locations and period sets</h2>
                <article v-for="g in setup.classes" :key="g.id">
                    <h3>{{ g.name }}</h3>
                    <label :for="'usual-room-' + g.id">Location (optional)</label>
                    <select class="form-select" :id="'usual-room-' + g.id" :value="g.room_id ?? ''" @change="saveUsualRoom(g.id, numberValue($event))"><option value="">No location</option><option v-for="r in activeRooms" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                    <div v-for="day in setup.weekdays" :key="day" class="tt-field">
                        <label :for="'class-day-' + g.id + '-' + day">{{ weekdays[day] }} period set</label>
                        <select class="form-select" :id="'class-day-' + g.id + '-' + day" :value="classSet(g.id, day) ?? ''" @change="saveDay(day, numberValue($event), g.id)"><option value="">School's set</option><option v-for="s in setup.sets" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </div>
                </article>
            </div>
            <div v-else-if="panel === 'week'">
                <div class="tt-toolbar">
                    <label for="tt-class">Class</label><select class="form-select" id="tt-class" v-model="classId" @change="loadWeek"><option v-for="g in setup.classes" :key="g.id" :value="g.id">{{ g.name }}</option></select>
                    <label for="as-of">Showing the timetable as of</label><input class="form-control" id="as-of" v-model="asOf" type="date" :min="setup.year.first_day" :max="setup.year.last_day" @change="typedAsOf" @blur="commitAsOf(true)" @keydown.enter.prevent="commitAsOf(true)" />
                </div>
                <h2 v-if="selectedClass">{{ selectedClass.name }}</h2>
                <p v-if="!setup.classes.length">Add a class first.</p>
                <p v-if="weekLoading" role="status">Loading class week…</p>
                <p v-if="dayNotice" role="status">{{ dayNotice }}</p>
                <div v-if="!dayNotice || week.meetings.length" class="tt-grid-scroll" tabindex="0" aria-label="Class week, scroll to see other weekdays">
                    <div class="tt-grid">
                        <section v-for="day in week.days" :key="day.weekday" class="tt-day">
                            <h2>{{ weekdays[day.weekday] }}</h2>
                            <p v-if="!day.periods.length">Choose this weekday's period set in Setup.</p>
                            <article v-for="p in day.periods" :key="p.id" class="tt-cell" :class="{ 'tt-block': p.kind === 'block' }">
                                <h3>{{ p.name }}</h3><p><bdi dir="ltr">{{ schoolTimeRange(p.starts_at, p.ends_at) }}</bdi></p>
                                <div v-if="cell(day.weekday, p.id)">
                                    <button class="btn btn-outline-primary" type="button" @click="editMeeting(day.weekday, p, cell(day.weekday, p.id))">{{ cell(day.weekday, p.id).label }}</button>
                                    <p>{{ cell(day.weekday, p.id).teachers.map((t: any) => t.name).join(', ') || 'No teachers' }}</p>
                                    <p v-if="cell(day.weekday, p.id).room_name">{{ cell(day.weekday, p.id).room_name }}</p>
                                </div>
                                <button class="btn btn-outline-primary" v-else type="button" @click="editMeeting(day.weekday, p)">Place meeting</button>
                            </article>
                            <button class="btn btn-outline-primary" v-if="targetsFor(day.weekday).length" type="button" @click="editCopy(day.weekday)">Copy {{ weekdays[day.weekday] }} to…</button>
                        </section>
                    </div>
                </div>
            </div>
            <div v-else>
                <h2>Clashes</h2><p>Showing clashes as of {{ dateLabel(loadedDate) }}. The office can save a meeting after reviewing its clashes.</p>
                <p v-if="!clashes.length">No clashes.</p>
                <ul class="tt-clash-list"><li v-for="(c, i) in clashes" :key="i"><p>{{ clashSentence(c) }}</p><p class="small text-muted">{{ dateLabel(c.effective_from) }} to {{ dateLabel(c.effective_until) }}</p></li></ul>
            </div>
        </div>
        <div v-if="editor" class="modal-backdrop fade show"></div>
        <div v-if="editor" class="modal fade show d-block tt-dialog-backdrop">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <section class="modal-content tt-dialog" role="dialog" aria-modal="true" aria-labelledby="tt-editor-title" tabindex="-1" ref="dialog">
                <h2 id="tt-editor-title" class="modal-title h5">{{ editorTitle }}</h2>
                <p v-if="selectedClass && ['meeting', 'copy', 'remove-meeting'].includes(editor)">{{ selectedClass.name }}</p>
                <p v-if="error" role="alert">{{ error }}</p>
                <div v-if="warnings.length">
                    <h3>Review these clashes</h3>
                    <ul class="tt-clash-list"><li v-for="(c, i) in warnings" :key="i"><p>{{ clashSentence(c) }}</p><p class="small text-muted">{{ dateLabel(c.effective_from) }} to {{ dateLabel(c.effective_until) }}</p></li></ul>
                    <button class="btn btn-outline-primary" type="button" :disabled="saving" @click="save(true)">Save anyway</button><button class="btn btn-outline-primary" type="button" :disabled="saving" @click="warnings = []">Go back</button>
                </div>
                <form v-else @submit.prevent="save(false)">
                    <div v-if="editor === 'set'">
                        <p>This changes the times for the whole year.</p>
                        <label for="set-name">Set name</label><input class="form-control" id="set-name" v-model="draft.name" maxlength="60" required />
                        <fieldset v-for="(p, i) in draft.periods" :key="p.id ?? 'new-' + i">
                            <legend>Period {{ i + 1 }}</legend>
                            <label :for="'period-name-' + i">Name</label><input class="form-control" :id="'period-name-' + i" v-model="p.name" maxlength="60" required />
                            <label :for="'period-start-' + i">Start</label><input class="form-control" :id="'period-start-' + i" v-model="p.starts_at" type="time" required />
                            <label :for="'period-end-' + i">End</label><input class="form-control" :id="'period-end-' + i" v-model="p.ends_at" type="time" required />
                            <label :for="'period-kind-' + i">Kind</label><select class="form-select" :id="'period-kind-' + i" v-model="p.kind"><option value="teaching">Teaching</option><option value="block">Block (break or activity)</option></select>
                            <button class="btn btn-outline-primary" type="button" :disabled="i === 0" @click="movePeriod(i, -1)">Move up</button><button class="btn btn-outline-primary" type="button" :disabled="i === draft.periods.length - 1" @click="movePeriod(i, 1)">Move down</button><button class="btn btn-outline-primary" type="button" @click="draft.periods.splice(i, 1)">Remove period</button>
                        </fieldset>
                        <button class="btn btn-outline-primary" type="button" @click="addPeriod">Add period</button>
                    </div>
                    <div v-else-if="editor === 'room'">
                        <label for="room-name">Location name</label><input class="form-control" id="room-name" v-model="draft.name" maxlength="60" required />
                        <label for="room-capacity">Capacity (optional)</label><input class="form-control" id="room-capacity" v-model="draft.capacity" type="number" min="1" max="100000" />
                        <label><input class="form-check-input" v-model="draft.active" type="checkbox" /> Active</label>
                    </div>
                    <div v-else-if="editor === 'meeting'">
                        <label for="meeting-kind">Meeting</label><select class="form-select" id="meeting-kind" v-model="draft.kind" @change="defaultTeachers"><option v-if="setup.class_subjects_enabled && draft.periodKind === 'teaching'" value="subject">Subject</option><option value="activity">Activity</option><option v-if="!setup.class_subjects_enabled" value="class">{{ selectedClass?.name }}</option></select>
                        <div v-if="draft.kind === 'subject'"><label for="meeting-subject">Subject</label><select class="form-select" id="meeting-subject" v-model="draft.class_subject_id" @change="defaultTeachers"><option v-for="s in selectedClass?.subjects ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                        <div v-if="draft.kind === 'activity'"><label for="activity-name">Activity name</label><input class="form-control" id="activity-name" v-model="draft.activity_name" maxlength="60" required /></div>
                        <fieldset><legend>Teachers</legend><label v-for="t in setup.teachers" :key="t.id" class="tt-check"><input class="form-check-input" v-model="draft.teacher_ids" type="checkbox" :value="t.id" /> {{ t.name }}</label></fieldset>
                        <p v-if="draft.kind === 'activity'">An activity may have no teachers.</p>
                        <label for="meeting-room">Location (optional)</label><select class="form-select" id="meeting-room" v-model="draft.room_id"><option :value="null">Class's location{{ usualRoomName ? ': ' + usualRoomName : '' }}</option><option v-for="r in activeRooms" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                    </div>
                    <div v-else-if="editor === 'copy'">
                        <p>Copy {{ weekdays[draft.source_weekday] }} to other weekdays with the same period set.</p>
                        <label v-for="day in copyTargets" :key="day" class="tt-check"><input class="form-check-input" v-model="draft.target_weekdays" type="checkbox" :value="day" /> {{ weekdays[day] }}</label>
                        <p v-if="!copyTargets.length">No other weekday uses this period set.</p>
                    </div>
                    <p v-else>{{ draft.confirmText }}</p>
                    <div v-if="['meeting', 'copy', 'remove-meeting'].includes(editor)"><label for="effective-from">From which date</label><input class="form-control" id="effective-from" v-model="draft.effective_from" type="date" :min="setup.year?.first_day" :max="setup.year?.last_day" required /><p>Earlier timetable rows are kept when a later change is made.</p></div>
                    <button class="btn btn-outline-primary" type="submit" :disabled="saving">{{ saving ? 'Saving…' : editor.startsWith('remove-') ? 'Remove' : 'Save' }}</button><button class="btn btn-outline-primary" type="button" :disabled="saving" @click="closeEditor">Cancel</button>
                    <button class="btn btn-outline-primary" v-if="editor === 'meeting' && draft.id" type="button" :disabled="saving" @click="editor = 'remove-meeting'">Remove meeting</button>
                </form>
            </section>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useCommittedDate } from '@/composables/useCommittedDate';
import { formatSchoolDay, weekdayOfIso } from '@/core/types/data/masjid-related/SchoolCalendar';
import { schoolTimeRange } from '@/core/helpers/scheduledSend';
import ApiService from '@/core/services/ApiService';
import OfficeSchedules from './OfficeSchedules.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

const auth = useAuthStore(); const masjid = useMasjidStore();
const enabled = computed(() => masjid.masjid?.capabilities?.school_timetable === true);
const years = ref<any[]>([]); const yearId = ref<number | null>(null); const classId = ref<number | null>(null);
const asOf = ref(''); const loadedDate = ref(''); const outOfYear = ref(false); const panel = ref('schedules'); const setup = ref<any>(null); const week = ref<any>({ days: [], meetings: [] }); const clashes = ref<any[]>([]);
const weekLoading = ref(false); const loading = ref(false); const saving = ref(false); const error = ref(''); const editor = ref(''); const draft = ref<any>({}); const warnings = ref<any[]>([]); const dialog = ref<HTMLElement | null>(null);
const warningFingerprint = ref<string | null>(null);
const warnedRequest = ref<any>(null);
const weekdays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const root = computed(() => `/api/admin/masjids/${auth.dashboardMasjidId}/timetable`);
const base = computed(() => `${root.value}/years/${yearId.value}`);
const selectedClass = computed(() => setup.value?.classes.find((g: any) => g.id === Number(classId.value)));
const activeRooms = computed(() => setup.value?.rooms.filter((r: any) => r.active) ?? []);
const usualRoomName = computed(() => setup.value?.rooms.find((r: any) => r.id === selectedClass.value?.room_id)?.name);
const editorTitle = computed(() => ({ set: 'Period set', room: 'Location', meeting: draft.value.id ? `Change ${draft.value.label} in ${slotLabel.value}` : `Place meeting in ${slotLabel.value}`, copy: 'Copy this day', 'remove-meeting': `Remove ${draft.value.label} from ${slotLabel.value}`, 'remove-set': 'Remove period set', 'remove-room': 'Remove location' }[editor.value] ?? 'Timetable'));
const slotLabel = computed(() => `${selectedClass.value?.name}, ${draft.value.periodName}, ${weekdays[draft.value.weekday]}`);
const targetsFor = (day: number) => week.value.days.filter((d: any) => d.weekday !== day && d.period_set_id && d.period_set_id === week.value.days.find((s: any) => s.weekday === day)?.period_set_id).map((d: any) => d.weekday);
const copyTargets = computed(() => targetsFor(draft.value.source_weekday));
const dateLabel = (date: string) => formatSchoolDay(date, 'en-US', { month: 'long', day: 'numeric', year: 'numeric' });
const boundsMessage = () => `Pick a date between ${dateLabel(setup.value.year.first_day)} and ${dateLabel(setup.value.year.last_day)}.`;
// Said whether or not the class has meetings: the grid below is the recurring week as it
// stands on that date, which would otherwise read as if school were on.
const dayNotice = computed(() => {
    const standing = week.value.meetings.length ? ' The week below is the timetable as it stands on that date.' : '';
    if (week.value.state === 'closure') return `School is closed on ${dateLabel(loadedDate.value)}${week.value.reason ? ': ' + week.value.reason + '.' : '.'}${standing}`;
    return week.value.state === 'non_meeting' ? `The school does not meet on ${dateLabel(loadedDate.value)}.${standing}` : '';
});
function clashSentence(c: any): string {
    const places = c.meetings.map((m: any) => `${m.group_name} (${m.label})`).join(' and ');
    const clocks = c.meetings.map((m: any) => schoolTimeRange(m.starts_at, m.ends_at));
    const time = clocks.every((t: string) => t === clocks[0]) ? clocks[0] : clocks.join(' and ');
    const subject = c.kind === 'room' ? `${c.name} is used by two classes` : `${c.name} is in two places`;
    return `${subject}: ${places}, ${weekdays[c.weekday]} ${time}.`;
}
let lastWeekKey = '';
const inYear = (date: string) => weekdayOfIso(date) !== null && date >= setup.value.year.first_day && date <= setup.value.year.last_day;
function showPanel(view: string) { panel.value = view; if (view === 'week' || view === 'clashes') commitAsOf(true); }
function editClassWeek(id: number, date: string) { classId.value = id; asOf.value = date; showPanel('week'); }
// A complete in-year date loads as soon as the box reports it; a half-typed year waits for
// Enter or leaving the box before it is refused, so typing never flashes the refusal.
// A date box reports a "complete" date at several keys while one is typed over another
// (11/15 passes through 1 Jan and 1 Nov), so a change waits for the typing to stop; Enter
// and leaving the box load at once.
const dateInput = useCommittedDate(asOf, () => commitAsOf(true));
function typedAsOf() { dateInput.typed(); }
function commitAsOf(leaving: boolean) {
    dateInput.clear();
    if (!leaving && !inYear(asOf.value)) return;
    const key = `${base.value}:${classId.value}:${asOf.value}`;
    if (key !== lastWeekKey) void loadWeek();
}
let generation = 0; let weekGeneration = 0; let restoreFocus: HTMLElement | null = null;
function message(e: any): string { const body = e?.response?.data; return Object.values(body?.data ?? {}).flat().filter(v => typeof v === 'string').join(' ') || body?.message || 'The timetable could not be saved or loaded. Try again.'; }
function numberValue(event: Event): number | null { const v = (event.target as HTMLSelectElement).value; return v === '' ? null : Number(v); }
function schoolSet(day: number) { return setup.value.days.find((d: any) => d.weekday === day)?.period_set_id ?? ''; }
function classSet(group: number, day: number) { return setup.value.class_days?.find((d: any) => d.group_id === group && d.weekday === day)?.period_set_id; }
function cell(day: number, period: number) { return week.value.meetings.find((m: any) => m.weekday === day && m.period_id === period); }
async function load() {
    if (!enabled.value) return;
    const run = ++generation; loading.value = true; error.value = '';
    try { const r = await ApiService.get(root.value as any); if (run !== generation || !enabled.value) return; years.value = r.data.data.years; asOf.value ||= r.data.data.today; yearId.value = years.value.find(y => y.first_day <= asOf.value && y.last_day >= asOf.value)?.id ?? years.value[0]?.id ?? null; }
    catch (e) { if (run === generation) error.value = message(e); }
    finally { if (run === generation) loading.value = false; }
    if (run === generation && yearId.value) await loadYear();
}
async function loadYear() {
    if (!enabled.value || !yearId.value) return;
    const run = ++generation; ++weekGeneration; loading.value = true; error.value = ''; setup.value = null; week.value = { days: [], meetings: [] }; clashes.value = []; loadedDate.value = ''; closeEditor();
    try {
        const r = await ApiService.get(`${base.value}/setup` as any); if (run !== generation || !enabled.value) return;
        setup.value = r.data.data; asOf.value ||= setup.value.today;
        if (asOf.value < setup.value.year.first_day || asOf.value > setup.value.year.last_day) asOf.value = setup.value.year.first_day;
        if (!setup.value.classes.some((g: any) => g.id === Number(classId.value))) classId.value = setup.value.classes[0]?.id ?? null;
        if (panel.value !== 'schedules') await loadWeek();
    } catch (e) { if (run === generation) error.value = message(e); }
    finally { if (run === generation) loading.value = false; }
}
async function loadWeek() {
    if (!enabled.value || !yearId.value || !asOf.value) return;
    const run = ++weekGeneration; const scope = base.value; const date = asOf.value;
    lastWeekKey = `${scope}:${classId.value}:${date}`;
    error.value = ''; outOfYear.value = false; loadedDate.value = date;
    if (!inYear(date)) {
        weekLoading.value = false; week.value = { days: [], meetings: [] }; clashes.value = []; error.value = boundsMessage(); outOfYear.value = true; return;
    }
    weekLoading.value = true; week.value = { days: [], meetings: [] }; clashes.value = [];
    try {
        if (classId.value) {
            const r = await ApiService.get(`${scope}/week?group_id=${classId.value}&as_of=${date}&include_clashes=1` as any);
            if (run !== weekGeneration || !enabled.value) return;
            week.value = r.data.data; clashes.value = r.data.data.clashes ?? [];
        } else {
            const r = await ApiService.get(`${scope}/clashes?as_of=${date}` as any);
            if (run === weekGeneration && enabled.value) clashes.value = r.data.data;
        }
    } catch (e) { if (run === weekGeneration) { week.value = { days: [], meetings: [] }; error.value = e?.response?.status === 422 ? boundsMessage() : 'The timetable could not be loaded. Try again.'; } }
    finally { if (run === weekGeneration) weekLoading.value = false; }
}
function openEditor(kind: string, data: any) { restoreFocus = typeof document !== 'undefined' ? document.activeElement as HTMLElement : null; error.value = ''; warnings.value = []; warningFingerprint.value = null; warnedRequest.value = null; editor.value = kind; draft.value = data; nextTick(() => dialog.value?.focus?.()); }
function closeEditor() { editor.value = ''; warnings.value = []; warningFingerprint.value = null; warnedRequest.value = null; restoreFocus?.focus?.(); }
function fromDate() { return [setup.value?.today ?? asOf.value, setup.value?.year?.first_day ?? ''].sort().at(-1); }
function editSet(set?: any) { openEditor('set', set ? JSON.parse(JSON.stringify(set)) : { name: '', periods: [] }); }
function editRoom(room?: any) { openEditor('room', room ? { ...room } : { name: '', capacity: '', active: true }); }
function addPeriod() {
    const previous = draft.value.periods.at(-1);
    const minutes = (time: string) => Number(time.slice(0,2)) * 60 + Number(time.slice(3,5));
    const start = previous ? minutes(previous.ends_at) : 8 * 60;
    const duration = previous ? minutes(previous.ends_at) - minutes(previous.starts_at) : 45;
    const clock = (n: number) => `${String(Math.floor(n / 60) % 24).padStart(2,'0')}:${String(n % 60).padStart(2,'0')}`;
    draft.value.periods.push({ name: '', starts_at: clock(start), ends_at: clock(start + (duration > 0 ? duration : 45)), kind: 'teaching' });
}
function movePeriod(i: number, direction: number) { const rows = draft.value.periods; const [p] = rows.splice(i,1); rows.splice(i + direction,0,p); }
function defaultTeachers() { const g = selectedClass.value; draft.value.teacher_ids = [...(draft.value.kind === 'subject' ? g?.subjects.find((s: any) => s.id === Number(draft.value.class_subject_id))?.teacher_ids ?? g?.teachers ?? [] : g?.teachers ?? [])]; }
function editMeeting(day: number, period: any, meeting?: any) {
    openEditor('meeting', { id: meeting?.id, group_id: Number(classId.value), weekday: day, period_id: period.id, periodKind: period.kind, periodName: period.name, label: meeting?.label, kind: meeting?.kind ?? (setup.value.class_subjects_enabled ? (period.kind === 'teaching' && selectedClass.value?.subjects.length ? 'subject' : 'activity') : 'class'), class_subject_id: meeting?.class_subject_id ?? selectedClass.value?.subjects[0]?.id ?? null, activity_name: meeting?.activity_name ?? '', room_id: meeting?.room_id ?? null, teacher_ids: meeting?.teachers.map((t: any) => t.id) ?? [], effective_from: fromDate() });
    if (!meeting) defaultTeachers();
}
function editCopy(day: number) { openEditor('copy', { group_id: Number(classId.value), source_weekday: day, target_weekdays: [], as_of: asOf.value, effective_from: fromDate() }); }
function removeSet(s: any) { openEditor('remove-set', { id: s.id, confirmText: `Remove ${s.name}? Period sets with meetings are kept.` }); }
function removeRoom(r: any) { openEditor('remove-room', { id: r.id, confirmText: `Remove ${r.name}? Locations with meetings are kept.` }); }
async function simpleWrite(url: string, data: any) { saving.value = true; error.value = ''; try { await ApiService.put(url as any,data); await loadYear(); } catch(e) { error.value = message(e); } finally { saving.value = false; } }
function saveDay(day: number, id: number | null, group?: number) { return simpleWrite(`${base.value}/days`, { group_id: group ?? null, days: [{ weekday: day, period_set_id: id }] }); }
function saveUsualRoom(group: number, room: number | null) { return simpleWrite(`${base.value}/classes/${group}/room`, { room_id: room }); }
async function save(confirm: boolean) {
    if (saving.value || !enabled.value) return;
    saving.value = true; error.value = '';
    const scope = base.value; const run = generation;
    const d = draft.value; let body: any; let url: string; let method = d.id ? 'put' : 'post';
    if (editor.value === 'set') { url = `${base.value}/sets${d.id ? '/' + d.id : ''}`; body = { name: d.name, periods: d.periods.map((p: any,i: number) => ({ ...(p.id ? { id: p.id } : {}), name: p.name, starts_at: p.starts_at, ends_at: p.ends_at, kind: p.kind, position: i + 1 })) }; }
    else if (editor.value === 'room') { url = `${base.value}/rooms${d.id ? '/' + d.id : ''}`; body = { name: d.name, capacity: d.capacity === '' || d.capacity === null ? null : Number(d.capacity), active: d.active }; }
    else if (editor.value === 'copy') { url = `${base.value}/copy-day`; method = 'post'; body = { ...d, confirm_clashes: confirm }; }
    else if (editor.value === 'meeting') { url = `${base.value}/meetings${d.id ? '/' + d.id : ''}`; body = { group_id: d.group_id, weekday: d.weekday, period_id: d.period_id, kind: d.kind, class_subject_id: d.kind === 'subject' ? Number(d.class_subject_id) : null, activity_name: d.kind === 'activity' ? d.activity_name : null, room_id: d.room_id === null || d.room_id === '' ? null : Number(d.room_id), teacher_ids: d.teacher_ids.map(Number), effective_from: d.effective_from, confirm_clashes: confirm }; }
    else { method = 'delete'; url = `${base.value}/${editor.value === 'remove-set' ? 'sets' : editor.value === 'remove-room' ? 'rooms' : 'meetings'}/${d.id}`; body = editor.value === 'remove-meeting' ? { effective_from: d.effective_from } : null; }
    if (confirm && warnedRequest.value) { body = { ...warnedRequest.value.body, confirm_clashes: true, clash_fingerprint: warningFingerprint.value }; url = warnedRequest.value.url; method = warnedRequest.value.method; }
    try {
        if (method === 'delete') { if (body) await ApiService.deleteWithBody(url as any,body); else await ApiService.delete(url as any); }
        else if (method === 'put') await ApiService.put(url as any,body); else await ApiService.post(url as any,body);
        if (scope !== base.value || run !== generation || !enabled.value) return;
        closeEditor(); await loadYear();
    } catch(e: any) { if (scope !== base.value || run !== generation || !enabled.value) return; if (e?.response?.status === 409 && Array.isArray(e.response.data?.clashes)) { warnings.value = e.response.data.clashes; if (body?.confirm_clashes) error.value = warnings.value.length ? 'The clashes have changed. Review the updated list before saving.' : 'There are no clashes now. Review the meeting and save again.'; warningFingerprint.value = e.response.data.clash_fingerprint; warnedRequest.value = { body: JSON.parse(JSON.stringify(body)), url, method }; nextTick(() => dialog.value?.focus?.()); } else error.value = message(e); }
    finally { saving.value = false; }
}
function keydown(e: KeyboardEvent) {
    if (!editor.value) return;
    if (e.key === 'Escape' && !saving.value) { e.preventDefault(); closeEditor(); }
    if (e.key === 'Tab' && dialog.value) { const nodes = Array.from(dialog.value.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), select:not([disabled])')); const first = nodes[0]; const last = nodes.at(-1); if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) { e.preventDefault(); last?.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); } }
}
watch(root, () => { ++generation; ++weekGeneration; closeEditor(); setup.value = null; years.value = []; week.value = { days: [], meetings: [] }; clashes.value = []; yearId.value = null; classId.value = null; asOf.value = ''; load(); });
watch(enabled, on => { if (on) load(); else { ++generation; ++weekGeneration; closeEditor(); setup.value = null; years.value = []; week.value = { days: [], meetings: [] }; clashes.value = []; } });
onMounted(() => { load(); if (typeof document !== 'undefined') document.addEventListener?.('keydown',keydown); });
onBeforeUnmount(() => { ++generation; ++weekGeneration; dateInput.clear(); if (typeof document !== 'undefined') document.removeEventListener?.('keydown',keydown); });
</script>

<style scoped>
.timetable-page, .tt-dialog { box-sizing: border-box; }
.timetable-page { min-width: 0; max-width: 100%; padding: 1rem; overflow-wrap: anywhere; }
.tt-toolbar { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; margin-block-end: 1rem; }
.tt-toolbar h1 { margin-inline-end: auto; }
.tt-toolbar label { margin: 0; white-space: nowrap; }
.tt-toolbar > .form-select, .tt-toolbar > .form-control { width: auto; flex: 0 1 16rem; }
button, input, select { min-height: 44px; max-width: 100%; border-radius: .4rem; }
button { margin: .2rem; }
button:disabled { opacity: .55; }
input, select { min-width: 0; }
.tt-field { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-block: .6rem; }
.tt-setup article, .tt-clash-list li { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .75rem; margin-block: .75rem; }
.tt-grid-scroll { max-width: 100%; overflow-x: auto; overscroll-behavior-inline: contain; }
.tt-grid { display: flex; gap: .75rem; width: max-content; min-width: 100%; }
.tt-day { flex: 0 0 210px; width: 210px; }
.tt-cell { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .6rem; margin-block: .6rem; }
.tt-cell h3 { font-size: 1rem; }
.tt-cell p { margin-block: .3rem; }
.tt-block { background: var(--bs-tertiary-bg); }
.tt-dialog-backdrop .modal-dialog { max-width: 620px; margin-inline: auto; padding-inline: .5rem; }
.tt-dialog { width: min(620px, 100%); max-height: 90dvh; overflow-y: auto; min-width: 0; padding: 1rem; border-radius: .75rem; }
.tt-dialog label { display: block; margin-block-start: .6rem; }
.tt-dialog input:not([type=checkbox]), .tt-dialog select { width: 100%; }
.tt-dialog fieldset { border: 1px solid var(--bs-border-color); padding: .6rem; margin-block: .75rem; min-width: 0; }
.tt-check { display: flex !important; gap: .5rem; align-items: center; }
.tt-check input { flex-shrink: 0; }
.tt-clash-list { padding-inline-start: 1.25rem; }
@media (max-width: 820px) { .tt-toolbar h1, .tt-toolbar > label, .tt-toolbar > .form-select, .tt-toolbar > .form-control { flex: 1 1 100%; width: 100%; } }
@media (max-width: 400px) { .timetable-page { padding: .5rem; } .tt-dialog { padding: .65rem; } }
</style>
