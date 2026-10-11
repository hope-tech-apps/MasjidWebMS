<template>
    <section v-if="enabled" class="timetable-page">
        <header class="tt-toolbar">
            <h1>Timetable</h1>
            <label for="tt-year">School year</label>
            <select id="tt-year" v-model="yearId" @change="loadYear"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option></select>
            <button type="button" @click="panel = 'week'">Class week</button>
            <button type="button" @click="panel = 'setup'">Setup</button>
            <button type="button" @click="panel = 'clashes'">Clashes ({{ clashes.length }})</button>
        </header>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="loading" role="status">Loading timetable…</p>
        <button v-if="error && !editor" type="button" @click="loadYear">Retry</button>
        <p v-if="!loading && !years.length">Add a school year in School Calendar first.</p>
        <div v-if="setup && !loading">
            <div v-if="panel === 'setup'" class="tt-setup">
                <h2>Period sets</h2>
                <p>This changes the times for the whole year.</p>
                <button type="button" @click="editSet()">Add period set</button>
                <article v-for="set in setup.sets" :key="set.id">
                    <h3>{{ set.name }}</h3>
                    <ol><li v-for="p in set.periods" :key="p.id">{{ p.name }} <bdi dir="ltr">{{ p.starts_at }}–{{ p.ends_at }}</bdi> ({{ p.kind }})</li></ol>
                    <button type="button" @click="editSet(set)">Edit {{ set.name }}</button>
                    <button type="button" @click="removeSet(set)">Remove {{ set.name }}</button>
                </article>
                <h2>Weekday period sets</h2>
                <p>This changes the times for the whole year.</p>
                <div v-for="day in setup.weekdays" :key="day" class="tt-field">
                    <label :for="'school-day-' + day">{{ weekdays[day] }}</label>
                    <select :id="'school-day-' + day" :value="schoolSet(day)" @change="saveDay(day, numberValue($event))"><option value="">Choose a set</option><option v-for="s in setup.sets" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </div>
                <h2>Rooms</h2>
                <button type="button" @click="editRoom()">Add room</button>
                <article v-for="room in setup.rooms" :key="room.id">
                    <p>{{ room.name }}<span v-if="room.capacity"> · Capacity {{ room.capacity }}</span> · {{ room.active ? 'Active' : 'Inactive' }}</p>
                    <button type="button" @click="editRoom(room)">Edit {{ room.name }}</button>
                    <button type="button" @click="removeRoom(room)">Remove {{ room.name }}</button>
                </article>
                <h2>Class rooms and period sets</h2>
                <article v-for="g in setup.classes" :key="g.id">
                    <h3>{{ g.name }}</h3>
                    <label :for="'usual-room-' + g.id">Usual room</label>
                    <select :id="'usual-room-' + g.id" :value="g.room_id ?? ''" @change="saveUsualRoom(g.id, numberValue($event))"><option value="">No usual room</option><option v-for="r in activeRooms" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                    <div v-for="day in setup.weekdays" :key="day" class="tt-field">
                        <label :for="'class-day-' + g.id + '-' + day">{{ weekdays[day] }} period set</label>
                        <select :id="'class-day-' + g.id + '-' + day" :value="classSet(g.id, day) ?? ''" @change="saveDay(day, numberValue($event), g.id)"><option value="">School's set</option><option v-for="s in setup.sets" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                    </div>
                </article>
            </div>
            <div v-else-if="panel === 'week'">
                <div class="tt-toolbar">
                    <label for="tt-class">Class</label><select id="tt-class" v-model="classId" @change="loadWeek"><option v-for="g in setup.classes" :key="g.id" :value="g.id">{{ g.name }}</option></select>
                    <label for="as-of">Showing the timetable as of</label><input id="as-of" v-model="asOf" type="date" />
                </div>
                <p v-if="!setup.classes.length">Add a class first.</p>
                <p v-if="weekLoading" role="status">Loading class week…</p>
                <div class="tt-grid-scroll" tabindex="0" aria-label="Class week, scroll to see other weekdays">
                    <div class="tt-grid">
                        <section v-for="day in week.days" :key="day.weekday" class="tt-day">
                            <h2>{{ weekdays[day.weekday] }}</h2>
                            <p v-if="!day.periods.length">Choose this weekday's period set in Setup.</p>
                            <article v-for="p in day.periods" :key="p.id" class="tt-cell" :class="{ 'tt-block': p.kind === 'block' }">
                                <h3>{{ p.name }}</h3><p><bdi dir="ltr">{{ p.starts_at }}–{{ p.ends_at }}</bdi></p>
                                <div v-if="cell(day.weekday, p.id)">
                                    <button type="button" @click="editMeeting(day.weekday, p, cell(day.weekday, p.id))">{{ cell(day.weekday, p.id).label }}</button>
                                    <p>{{ cell(day.weekday, p.id).teachers.map((t: any) => t.name).join(', ') || 'No teachers' }}</p>
                                    <p>{{ cell(day.weekday, p.id).room_name || 'No room' }}</p>
                                </div>
                                <button v-else type="button" @click="editMeeting(day.weekday, p)">Place meeting</button>
                            </article>
                            <button v-if="day.periods.length" type="button" @click="editCopy(day.weekday)">Copy {{ weekdays[day.weekday] }} to…</button>
                        </section>
                    </div>
                </div>
            </div>
            <div v-else>
                <h2>Clashes</h2><p>Showing clashes as of {{ asOf }}. The office can save a meeting after reviewing its clashes.</p>
                <p v-if="!clashes.length">No clashes.</p>
                <ul class="tt-clash-list"><li v-for="(c, i) in clashes" :key="i"><strong>{{ c.kind }}: {{ c.name }}</strong><p>{{ weekdays[c.weekday] }} · {{ c.effective_from }} to {{ c.effective_until }}</p><p v-for="(m, j) in c.meetings" :key="j">{{ m.group_name }} · {{ m.label }} · <bdi dir="ltr">{{ m.starts_at }}–{{ m.ends_at }}</bdi></p></li></ul>
            </div>
        </div>
        <div v-if="editor" class="tt-dialog-backdrop">
            <section class="tt-dialog" role="dialog" aria-modal="true" aria-labelledby="tt-editor-title" tabindex="-1" ref="dialog">
                <h2 id="tt-editor-title">{{ editorTitle }}</h2>
                <p v-if="error" role="alert">{{ error }}</p>
                <div v-if="warnings.length">
                    <h3>Review these clashes</h3>
                    <ul class="tt-clash-list"><li v-for="(c, i) in warnings" :key="i"><strong>{{ c.kind }}: {{ c.name }}</strong><p>{{ weekdays[c.weekday] }} · {{ c.effective_from }} to {{ c.effective_until }}</p><p v-for="(m, j) in c.meetings" :key="j">{{ m.group_name }} · {{ m.label }} · <bdi dir="ltr">{{ m.starts_at }}–{{ m.ends_at }}</bdi></p></li></ul>
                    <button type="button" :disabled="saving" @click="save(true)">Save anyway</button><button type="button" :disabled="saving" @click="warnings = []">Go back</button>
                </div>
                <form v-else @submit.prevent="save(false)">
                    <div v-if="editor === 'set'">
                        <p>This changes the times for the whole year.</p>
                        <label for="set-name">Set name</label><input id="set-name" v-model="draft.name" maxlength="60" required />
                        <fieldset v-for="(p, i) in draft.periods" :key="p.id ?? 'new-' + i">
                            <legend>Period {{ i + 1 }}</legend>
                            <label :for="'period-name-' + i">Name</label><input :id="'period-name-' + i" v-model="p.name" maxlength="60" required />
                            <label :for="'period-start-' + i">Start</label><input :id="'period-start-' + i" v-model="p.starts_at" type="time" required />
                            <label :for="'period-end-' + i">End</label><input :id="'period-end-' + i" v-model="p.ends_at" type="time" required />
                            <label :for="'period-kind-' + i">Kind</label><select :id="'period-kind-' + i" v-model="p.kind"><option value="teaching">Teaching</option><option value="block">Block (break or activity)</option></select>
                            <button type="button" :disabled="i === 0" @click="movePeriod(i, -1)">Move up</button><button type="button" :disabled="i === draft.periods.length - 1" @click="movePeriod(i, 1)">Move down</button><button type="button" @click="draft.periods.splice(i, 1)">Remove period</button>
                        </fieldset>
                        <button type="button" @click="draft.periods.push({ name: '', starts_at: '08:00', ends_at: '09:00', kind: 'teaching' })">Add period</button>
                    </div>
                    <div v-else-if="editor === 'room'">
                        <label for="room-name">Room name</label><input id="room-name" v-model="draft.name" maxlength="60" required />
                        <label for="room-capacity">Capacity (optional)</label><input id="room-capacity" v-model="draft.capacity" type="number" min="1" max="100000" />
                        <label><input v-model="draft.active" type="checkbox" /> Active</label>
                    </div>
                    <div v-else-if="editor === 'meeting'">
                        <label for="meeting-kind">Meeting</label><select id="meeting-kind" v-model="draft.kind" @change="defaultTeachers"><option v-if="setup.class_subjects_enabled && draft.periodKind === 'teaching'" value="subject">Subject</option><option value="activity">Activity</option><option v-if="!setup.class_subjects_enabled" value="class">Whole class</option></select>
                        <div v-if="draft.kind === 'subject'"><label for="meeting-subject">Subject</label><select id="meeting-subject" v-model="draft.class_subject_id" @change="defaultTeachers"><option v-for="s in selectedClass?.subjects ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                        <div v-if="draft.kind === 'activity'"><label for="activity-name">Activity name</label><input id="activity-name" v-model="draft.activity_name" maxlength="60" required /></div>
                        <fieldset><legend>Teachers</legend><label v-for="t in setup.teachers" :key="t.id" class="tt-check"><input v-model="draft.teacher_ids" type="checkbox" :value="t.id" /> {{ t.name }}</label></fieldset>
                        <p v-if="draft.kind === 'activity'">An activity may have no teachers.</p>
                        <label for="meeting-room">Room</label><select id="meeting-room" v-model="draft.room_id"><option :value="null">Class's usual room{{ usualRoomName ? ': ' + usualRoomName : '' }}</option><option v-for="r in activeRooms" :key="r.id" :value="r.id">{{ r.name }}</option></select>
                    </div>
                    <div v-else-if="editor === 'copy'">
                        <p>Copy {{ weekdays[draft.source_weekday] }} to other weekdays with the same period set.</p>
                        <label v-for="day in copyTargets" :key="day" class="tt-check"><input v-model="draft.target_weekdays" type="checkbox" :value="day" /> {{ weekdays[day] }}</label>
                        <p v-if="!copyTargets.length">No other weekday uses this period set.</p>
                    </div>
                    <p v-else>{{ draft.confirmText }}</p>
                    <div v-if="['meeting', 'copy', 'remove-meeting'].includes(editor)"><label for="effective-from">From which date</label><input id="effective-from" v-model="draft.effective_from" type="date" :min="setup.year?.first_day" :max="setup.year?.last_day" required /><p>Earlier timetable rows are kept when a later change is made.</p></div>
                    <button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button><button type="button" :disabled="saving" @click="closeEditor">Cancel</button>
                    <button v-if="editor === 'meeting' && draft.id" type="button" :disabled="saving" @click="editor = 'remove-meeting'">Remove meeting</button>
                </form>
            </section>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

const auth = useAuthStore(); const masjid = useMasjidStore();
const enabled = computed(() => masjid.masjid?.capabilities?.school_timetable === true);
const years = ref<any[]>([]); const yearId = ref<number | null>(null); const classId = ref<number | null>(null);
const asOf = ref(''); const panel = ref('week'); const setup = ref<any>(null); const week = ref<any>({ days: [], meetings: [] }); const clashes = ref<any[]>([]);
const weekLoading = ref(false); const loading = ref(false); const saving = ref(false); const error = ref(''); const editor = ref(''); const draft = ref<any>({}); const warnings = ref<any[]>([]); const dialog = ref<HTMLElement | null>(null);
const weekdays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const root = computed(() => `/api/admin/masjids/${auth.dashboardMasjidId}/timetable`);
const base = computed(() => `${root.value}/years/${yearId.value}`);
const selectedClass = computed(() => setup.value?.classes.find((g: any) => g.id === Number(classId.value)));
const activeRooms = computed(() => setup.value?.rooms.filter((r: any) => r.active) ?? []);
const usualRoomName = computed(() => setup.value?.rooms.find((r: any) => r.id === selectedClass.value?.room_id)?.name);
const editorTitle = computed(() => ({ set: 'Period set', room: 'Room', meeting: draft.value.id ? 'Change meeting' : 'Place meeting', copy: 'Copy this day', 'remove-meeting': 'Remove meeting', 'remove-set': 'Remove period set', 'remove-room': 'Remove room' }[editor.value] ?? 'Timetable'));
const copyTargets = computed(() => week.value.days.filter((d: any) => d.weekday !== draft.value.source_weekday && d.period_set_id === week.value.days.find((s: any) => s.weekday === draft.value.source_weekday)?.period_set_id).map((d: any) => d.weekday));
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
    const run = ++generation; ++weekGeneration; loading.value = true; error.value = ''; setup.value = null; week.value = { days: [], meetings: [] }; clashes.value = []; closeEditor();
    try {
        const r = await ApiService.get(`${base.value}/setup` as any); if (run !== generation || !enabled.value) return;
        setup.value = r.data.data; asOf.value ||= setup.value.today;
        if (!setup.value.classes.some((g: any) => g.id === Number(classId.value))) classId.value = setup.value.classes[0]?.id ?? null;
        await loadWeek();
    } catch (e) { if (run === generation) error.value = message(e); }
    finally { if (run === generation) loading.value = false; }
}
async function loadWeek() {
    if (!enabled.value || !yearId.value || !asOf.value) return;
    const run = ++weekGeneration; const scope = base.value; const date = asOf.value;
    weekLoading.value = true; week.value = { days: [], meetings: [] }; clashes.value = [];
    try {
        const c = await ApiService.get(`${scope}/clashes?as_of=${date}` as any); if (run !== weekGeneration || !enabled.value) return; clashes.value = c.data.data;
        if (classId.value) { const r = await ApiService.get(`${scope}/week?group_id=${classId.value}&as_of=${date}` as any); if (run === weekGeneration && enabled.value) week.value = r.data.data; }
    } catch (e) { if (run === weekGeneration) { week.value = { days: [], meetings: [] }; error.value = message(e); } }
    finally { if (run === weekGeneration) weekLoading.value = false; }
}
function openEditor(kind: string, data: any) { restoreFocus = typeof document !== 'undefined' ? document.activeElement as HTMLElement : null; error.value = ''; warnings.value = []; editor.value = kind; draft.value = data; nextTick(() => dialog.value?.focus?.()); }
function closeEditor() { editor.value = ''; warnings.value = []; restoreFocus?.focus?.(); }
function fromDate() { return [setup.value?.today ?? asOf.value, setup.value?.year?.first_day ?? ''].sort().at(-1); }
function editSet(set?: any) { openEditor('set', set ? JSON.parse(JSON.stringify(set)) : { name: '', periods: [] }); }
function editRoom(room?: any) { openEditor('room', room ? { ...room } : { name: '', capacity: '', active: true }); }
function movePeriod(i: number, direction: number) { const rows = draft.value.periods; const [p] = rows.splice(i,1); rows.splice(i + direction,0,p); }
function defaultTeachers() { const g = selectedClass.value; draft.value.teacher_ids = [...(draft.value.kind === 'subject' ? g?.subjects.find((s: any) => s.id === Number(draft.value.class_subject_id))?.teacher_ids ?? g?.teachers ?? [] : g?.teachers ?? [])]; }
function editMeeting(day: number, period: any, meeting?: any) {
    openEditor('meeting', { id: meeting?.id, group_id: Number(classId.value), weekday: day, period_id: period.id, periodKind: period.kind, kind: meeting?.kind ?? (setup.value.class_subjects_enabled ? (period.kind === 'teaching' && selectedClass.value?.subjects.length ? 'subject' : 'activity') : 'class'), class_subject_id: meeting?.class_subject_id ?? selectedClass.value?.subjects[0]?.id ?? null, activity_name: meeting?.activity_name ?? '', room_id: meeting?.room_id ?? null, teacher_ids: meeting?.teachers.map((t: any) => t.id) ?? [], effective_from: fromDate() });
    if (!meeting) defaultTeachers();
}
function editCopy(day: number) { openEditor('copy', { group_id: Number(classId.value), source_weekday: day, target_weekdays: [], as_of: asOf.value, effective_from: fromDate() }); }
function removeSet(s: any) { openEditor('remove-set', { id: s.id, confirmText: `Remove ${s.name}? Period sets with meetings are kept.` }); }
function removeRoom(r: any) { openEditor('remove-room', { id: r.id, confirmText: `Remove ${r.name}? Rooms with meetings are kept.` }); }
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
    try {
        if (method === 'delete') { if (body) await ApiService.deleteWithBody(url as any,body); else await ApiService.delete(url as any); }
        else if (method === 'put') await ApiService.put(url as any,body); else await ApiService.post(url as any,body);
        if (scope !== base.value || run !== generation || !enabled.value) return;
        closeEditor(); await loadYear();
    } catch(e: any) { if (scope !== base.value || run !== generation || !enabled.value) return; if (e?.response?.status === 409 && Array.isArray(e.response.data?.clashes)) { warnings.value = e.response.data.clashes; nextTick(() => dialog.value?.focus?.()); } else error.value = message(e); }
    finally { saving.value = false; }
}
function keydown(e: KeyboardEvent) {
    if (!editor.value) return;
    if (e.key === 'Escape' && !saving.value) { e.preventDefault(); closeEditor(); }
    if (e.key === 'Tab' && dialog.value) { const nodes = Array.from(dialog.value.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), select:not([disabled])')); const first = nodes[0]; const last = nodes.at(-1); if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) { e.preventDefault(); last?.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); } }
}
watch(asOf, () => { if (setup.value) loadWeek(); });
watch(root, () => { ++generation; ++weekGeneration; closeEditor(); setup.value = null; years.value = []; week.value = { days: [], meetings: [] }; clashes.value = []; yearId.value = null; classId.value = null; asOf.value = ''; load(); });
watch(enabled, on => { if (on) load(); else { ++generation; ++weekGeneration; closeEditor(); setup.value = null; years.value = []; week.value = { days: [], meetings: [] }; clashes.value = []; } });
onMounted(() => { load(); if (typeof document !== 'undefined') document.addEventListener?.('keydown',keydown); });
onBeforeUnmount(() => { ++generation; ++weekGeneration; if (typeof document !== 'undefined') document.removeEventListener?.('keydown',keydown); });
</script>

<style scoped>
.timetable-page, .tt-dialog { box-sizing: border-box; }
.timetable-page { min-width: 0; max-width: 100%; padding: 1rem; overflow-wrap: anywhere; }
.tt-toolbar { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; margin-block-end: 1rem; }
.tt-toolbar h1 { margin-inline-end: auto; }
button, input, select { min-height: 44px; max-width: 100%; border-radius: .4rem; }
button { padding: .5rem .8rem; border: 1px solid #64748b; background: #233044; color: #fff; margin: .2rem; }
button:disabled { opacity: .55; }
button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid #67e8f9; outline-offset: 2px; }
input, select { color: inherit; background: #172233; border: 1px solid #64748b; padding: .4rem; min-width: 0; }
.tt-field { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-block: .6rem; }
.tt-setup article, .tt-clash-list li { border: 1px solid #475569; border-radius: .5rem; padding: .75rem; margin-block: .75rem; }
.tt-grid-scroll { max-width: 100%; overflow-x: auto; overscroll-behavior-inline: contain; }
.tt-grid { display: flex; gap: .75rem; width: max-content; min-width: 100%; }
.tt-day { flex: 0 0 210px; width: 210px; }
.tt-cell { border: 1px solid #475569; border-radius: .5rem; padding: .6rem; margin-block: .6rem; }
.tt-cell h3 { font-size: 1rem; }
.tt-cell p { margin-block: .3rem; }
.tt-block { background: #273246; }
.tt-dialog-backdrop { position: fixed; inset: 0; background: #0009; display: flex; align-items: center; justify-content: center; z-index: 1100; padding: .5rem; }
.tt-dialog { width: min(620px, 100%); max-height: 90dvh; overflow-y: auto; min-width: 0; padding: 1rem; border-radius: .75rem; background: #152033; color: #fff; }
.tt-dialog label { display: block; margin-block-start: .6rem; }
.tt-dialog input:not([type=checkbox]), .tt-dialog select { width: 100%; }
.tt-dialog fieldset { border: 1px solid #64748b; padding: .6rem; margin-block: .75rem; min-width: 0; }
.tt-check { display: flex !important; gap: .5rem; align-items: center; }
.tt-check input { flex-shrink: 0; }
.tt-clash-list { padding-inline-start: 1.25rem; }
@media (max-width: 400px) { .timetable-page { padding: .5rem; } .tt-toolbar > select, .tt-toolbar > input { flex: 1 1 100%; width: 100%; } .tt-dialog { padding: .65rem; } }
</style>
