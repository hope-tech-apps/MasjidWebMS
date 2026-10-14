<template>
    <section class="office-register" aria-label="Class attendance">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div class="register-date">
                <label for="office-attendance-date" class="form-label">Day</label>
                <input id="office-attendance-date" class="form-control" type="date" v-model="draft"
                    :max="today || undefined" :disabled="saving || emailSaving" @change="dateInput.typed" @blur="dateInput.commitNow" @keydown.enter.prevent="dateInput.commitNow" />
            </div>
            <button type="button" class="btn btn-outline-secondary" :disabled="loading || saving || emailSaving" @click="load(draft)">Reload</button>
        </div>
        <div v-if="periodRegisters.length" class="mb-3" aria-label="Period registers">
            <p class="small text-muted">{{ readOnly ? 'Choose a saved period to view its marks.' : periodMode ? 'Choose a period to take or correct its register. The day mark comes from the periods taken.' : 'Period attendance is switched off. Take the day register. Saved period registers are read-only.' }}</p>
            <button v-if="!periodMode && selectedMeeting" type="button" class="btn btn-sm btn-outline-secondary me-2" :disabled="saving" @click="choosePeriod(0)">Day register</button>
            <button v-for="period in periodRegisters" :key="period.id" type="button" class="btn btn-sm me-2 mb-2"
                :class="selectedMeeting === period.id ? 'btn-primary' : 'btn-outline-secondary'" :disabled="saving" @click="choosePeriod(period.id)">{{ period.label }}{{ period.taken ? ' · Taken' : ' · Not taken' }}</button>
        </div>
        <h6 v-if="meeting">{{ meeting.period_name }} · {{ meeting.label }}</h6>
        <p v-if="readOnly" class="alert alert-secondary" role="status">Read-only. Saved period marks are kept; take the day register when period attendance is switched off.</p>
        <p v-if="loading" class="text-muted" role="status">Loading the register…</p>
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
        <p v-if="refusal" class="alert alert-secondary" role="status">{{ refusal }} Pick another day to see its register.</p>
        <div v-if="!loading && !refusal && loaded">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <span class="small text-muted">{{ formatSchoolDay(loaded, 'en-US') }} · {{ taken ? 'Register taken for this day.' : 'Not taken yet for this day.' }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!editable || !students.length" @click="markAll">Mark all present</button>
            </div>
            <div v-if="confirmAll" class="alert alert-warning" role="alert">
                <p>{{ protectedCount }} {{ plural(protectedCount, 'student') }} {{ protectedCount === 1 ? 'is' : 'are' }} marked absent, late or excused. Mark them present too?</p>
                <p class="small">{{ protectedSummary }}</p>
                <button type="button" class="btn btn-sm btn-primary me-2" :disabled="!editable" @click="setAllPresent()">Mark everyone present</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!editable" @click="setAllPresent(true)">Keep those marks</button>
            </div>
            <p v-if="!students.length" class="text-muted">{{ periodMode && !selectedMeeting ? "Choose a period above." : "No students on this roster yet." }}</p>
            <div class="list-group mb-3">
                <div v-for="student in students" :key="student.membership_id" :data-student="student.membership_id" class="list-group-item register-student">
                    <div class="d-flex flex-wrap align-items-start gap-2 mb-2 register-name">
                        <span class="fw-semibold text-break">{{ name(student) }}</span>
                        <AllergyBadge v-if="student.student_allergy !== undefined" :note="student.student_allergy" />
                        <span v-if="student.grade_label" class="badge bg-primary-subtle text-primary-emphasis">{{ student.grade_label }}</span>
                    </div>
                    <p v-if="student.day_status" class="small mb-2">Day: {{ student.day_status }}</p>
                    <p v-if="student.marked_by" class="small text-muted text-break mb-2">Marked by {{ student.marked_by }} at {{ when(student.marked_at) }}</p>
                    <p v-if="conflicts[student.membership_id]" class="small text-warning-emphasis mb-2" role="status">{{ conflicts[student.membership_id] }}</p>
                    <div class="register-marks mb-2" role="group" :aria-label="`Mark ${name(student)}`">
                        <button v-for="option in options" :key="option.value" type="button" class="btn btn-sm"
                            :class="student.status === option.value ? 'btn-primary' : 'btn-outline-secondary'"
                            :disabled="!editable" :aria-pressed="student.status === option.value" @click="student.status = option.value; saved = false">{{ option.label }}</button>
                    </div>
                    <label :for="`attendance-note-${student.membership_id}`" class="form-label small text-break">Note for {{ name(student) }} (optional)</label>
                    <textarea :id="`attendance-note-${student.membership_id}`" class="form-control" rows="1" maxlength="500" :disabled="!editable" v-model="student.note" @input="saved = false"></textarea>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
                <button type="button" class="btn btn-success" :disabled="!editable || !students.some(s => s.status)" @click="save">{{ saving ? 'Saving…' : 'Save register' }}</button>
                <span v-if="saved" class="text-success" role="status">{{ savedMessage }}</span>
            </div>
        </div>
        <section v-if="absenceEmails && !overviewLoading && !overviewError" class="mt-4" aria-labelledby="absence-emails-title">
            <h6 id="absence-emails-title">Absence emails</h6>
            <p class="small text-muted">Once a day, confirmed guardians receive the absences still marked at this time ({{ timezone }}).</p>
            <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
                <div class="register-date">
                    <label for="absence-email-time" class="form-label">Send time</label>
                    <input id="absence-email-time" class="form-control" type="time" v-model="emailTime" :disabled="emailSaving" />
                </div>
                <button type="button" class="btn btn-outline-secondary" :disabled="emailSaving || !emailTime" @click="saveEmailTime">Save email time</button>
            </div>
            <p v-if="emailError" class="alert alert-danger" role="alert">{{ emailError }}</p>
            <p v-if="emailSaved" class="text-success small" role="status">Email time saved.</p>
            <p class="small text-muted">For {{ formatSchoolDay(loaded, 'en-US') }}. Sent means the mail service accepted the email.</p>
            <p v-if="!absenceEmails.rows.length" class="small text-muted">No absence emails for this day.</p>
            <ul v-else class="list-group">
                <li v-for="row in absenceEmails.rows" :key="row.id" class="list-group-item text-break">
                    <div>{{ row.guardian_email }} · {{ row.child }}</div>
                    <div class="small">{{ row.classes.map((c: any) => c.name).join(', ') }}</div>
                    <div class="small text-muted">{{ row.status }}<template v-if="row.sent_at"> · {{ when(row.sent_at) }}</template></div>
                </li>
            </ul>
        </section>
        <div v-if="loaded && realm !== 'teacher'" class="mt-4">
            <h6>School registers for {{ formatSchoolDay(loaded, 'en-US') }}</h6>
            <p v-if="overviewError" class="alert alert-danger" role="alert">{{ overviewError }} <button type="button" class="btn btn-sm btn-outline-danger" @click="loadOverview(loaded)">Retry overview</button></p>
            <p v-if="overviewLoading" class="text-muted" role="status">Loading school registers…</p>
            <div v-else class="list-group">
                <div v-for="classroom in classes" :key="classroom.id" class="list-group-item text-break">
                    <button type="button" class="btn btn-link text-start p-0 text-break" :disabled="saving || emailSaving" @click="openClass(classroom.id)">{{ classroom.name }}</button>
                    <div class="small text-muted">{{ classroom.taken ? `Taken by ${classroom.marked_by} at ${when(classroom.marked_at)}` : 'Not taken' }}</div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
import { formatLastOpened } from '@/core/helpers/lastOpened';
import { useCommittedDate } from '@/composables/useCommittedDate';
import AllergyBadge from '@/components/classes/AllergyBadge.vue';

const props = defineProps<{ groupId: number; masjidId: number; date?: string; meetingId?: number; classPeriodsOnly?: boolean; realm?: 'teacher' | 'office'; transport?: any }>();
const realm = computed(() => props.realm ?? 'office');
const api = computed(() => props.transport ?? ApiService);
const selectedMeeting = ref(props.meetingId ?? 0);
const periodMode = ref(false); const readOnly = ref(false); const meeting = ref<any>(null);
const periodRegisters = ref<{ id: number; label: string; taken: boolean; read_only: boolean }[]>([]);
const router = useRouter();
type Student = { membership_id: number; contact: { first_name?: string; last_name?: string } | null; grade_label?: string; status: string | null; note: string; marked_by?: string | null; marked_at?: string | null; student_allergy?: any; day_status?: string | null };
type Classroom = { id: number; name: string; taken: boolean; marked_by: string | null; marked_at: string | null };
const options = [{ value: 'present', label: 'Present' }, { value: 'absent', label: 'Absent' }, { value: 'late', label: 'Late' }, { value: 'excused', label: 'Excused' }];
const draft = ref(props.date ?? ''); const loaded = ref(''); const today = ref(''); const timezone = ref('America/New_York');
const students = ref<Student[]>([]); const classes = ref<Classroom[]>([]); const taken = ref(false); const refusal = ref('');
const loading = ref(false); const saving = ref(false); const saved = ref(false); const error = ref(''); const overviewError = ref(''); const overviewLoading = ref(false);
const absenceEmails = ref<any>(null); const emailTime = ref(''); const emailSaving = ref(false); const emailError = ref(''); const emailSaved = ref(false);
const revision = ref(''); const baseline = ref<Record<number, { status: string | null; note: string }>>({});
const conflicts = ref<Record<number, string>>({}); const confirmAll = ref(false);
const protectedKinds = ['absent', 'late', 'excused'];
const protectedMarks = computed(() => Object.values(baseline.value).filter(s => protectedKinds.includes(s.status ?? '')));
const protectedCount = computed(() => protectedMarks.value.length);
const protectedSummary = computed(() => protectedKinds.map(kind => {
    const count = protectedMarks.value.filter(s => s.status === kind).length;
    return count ? `${count} ${kind}` : '';
}).filter(Boolean).join(', '));
const plural = (count: number, singular: string, multiple = singular + 's') => count === 1 ? singular : multiple;
const savedMessage = computed(() => {
    const unmarked = students.value.filter(s => !s.status).length;
    return 'Saved.' + (unmarked ? ` ${unmarked} ${plural(unmarked, 'student')} ${unmarked === 1 ? 'is' : 'are'} not marked yet.` : '');
});
const base = computed(() => `/api/${realm.value === 'teacher' ? 'teacher' : 'admin'}/masjids/${props.masjidId}/${selectedMeeting.value ? `period-attendance/${selectedMeeting.value}` : props.classPeriodsOnly ? `period-attendance/classes/${props.groupId}` : `groups/${props.groupId}/attendance`}`);
const editable = computed(() => !readOnly.value && !(periodMode.value && !selectedMeeting.value) && !loading.value && !saving.value && !refusal.value && !!loaded.value && draft.value === loaded.value);
const name = (student: Student) => [student.contact?.first_name, student.contact?.last_name].filter(Boolean).join(' ') || 'Student';
const when = (iso?: string | null) => formatLastOpened(iso, 'en-US', timezone.value);
let generation = 0; let overviewGeneration = 0; let alive = true;
function apply(data: any, headers?: any) {
    revision.value = headers?.['x-attendance-revision'] ?? '';
    conflicts.value = {}; confirmAll.value = false;
    readOnly.value = !!data.read_only; meeting.value = data.meeting ?? null;
    if (!selectedMeeting.value) { periodMode.value = !!data.period_mode; periodRegisters.value = data.registers ?? data.retained_periods ?? []; }
    loaded.value = data.session_date; draft.value = data.session_date; today.value = data.today; timezone.value = data.timezone;
    students.value = (data.students ?? []).map((s: Student) => ({ ...s, note: s.note ?? '' })); taken.value = data.taken; refusal.value = data.refusal ?? '';
    baseline.value = Object.fromEntries(students.value.map(s => [s.membership_id, { status: s.status, note: s.note }]));
}
function completeDate() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.value)) { error.value = 'Choose a complete date.'; return; }
    if (draft.value !== loaded.value) { if (!props.meetingId) selectedMeeting.value = 0; void load(draft.value); }
}
const dateInput = useCommittedDate(draft, completeDate);
async function load(date = '', keepChoices = false) {
    if (saving.value) return;
    dateInput.clear(); const run = ++generation; ++overviewGeneration; absenceEmails.value = null; emailSaving.value = false; emailTime.value = ''; emailSaved.value = false; emailError.value = ''; loading.value = true; error.value = ''; saved.value = false;
    // Invalidate the old overview too: a later date must never receive an older answer.
    ++overviewGeneration; classes.value = []; overviewError.value = ''; confirmAll.value = false;
    if (!keepChoices) { loaded.value = ''; students.value = []; refusal.value = ''; revision.value = ''; conflicts.value = {}; }
    try {
        const response = await api.value.get(`${base.value}${date ? `?date=${encodeURIComponent(date)}` : ''}`);
        if (!alive || run !== generation) return;
        const pendingDraft = draft.value;
        apply(response.data.data, response.headers);
        if (pendingDraft !== date) draft.value = pendingDraft;
        if (realm.value !== 'teacher') void loadOverview(loaded.value);
    } catch (e) { if (alive && run === generation) error.value = apiErrorText(e, 'Could not load the register.'); }
    finally { if (alive && run === generation) loading.value = false; }
}
async function loadOverview(date: string) {
    const run = ++overviewGeneration; overviewLoading.value = true; overviewError.value = '';
    try {
        const response = await ApiService.get(`/api/admin/masjids/${props.masjidId}/attendance/registers?date=${encodeURIComponent(date)}`);
        if (alive && run === overviewGeneration) {
            classes.value = response.data.data.classes;
            absenceEmails.value = response.data.data.absence_emails ?? null;
            if (!emailSaving.value) emailTime.value = absenceEmails.value?.send_time ?? '';
        }
    } catch (e) { if (alive && run === overviewGeneration) overviewError.value = apiErrorText(e, 'Could not load school registers.'); }
    finally { if (alive && run === overviewGeneration) overviewLoading.value = false; }
}
async function saveEmailTime() {
    if (!absenceEmails.value || emailSaving.value) return;
    const run = generation;
    emailSaving.value = true; emailError.value = ''; emailSaved.value = false;
    try {
        const response = await ApiService.put(`/api/admin/masjids/${props.masjidId}/attendance/absence-emails/settings`, { send_time: emailTime.value });
        if (!alive || run !== generation) return;
        emailTime.value = response.data.data.send_time;
        absenceEmails.value.send_time = emailTime.value; emailSaved.value = true;
    } catch (e) {
        if (alive && run === generation) emailError.value = apiErrorText(e, 'Could not save the absence email time.');
    } finally { if (alive && run === generation) emailSaving.value = false; }
}
function setAllPresent(keepMarks = false) {
    if (!editable.value) return;
    for (const student of students.value) {
        if (keepMarks && protectedKinds.includes(baseline.value[student.membership_id]?.status ?? '')) continue;
        student.status = 'present';
    }
    saved.value = false; confirmAll.value = false;
}
function markAll() { if (!editable.value) return; if (protectedCount.value) confirmAll.value = true; else setAllPresent(); }
async function save() {
    if (!editable.value || !students.value.some(s => s.status)) return;
    saving.value = true; error.value = ''; saved.value = false;
    const date = loaded.value; const run = generation;
    const choices = students.value.map(s => ({ ...s, changedStatus: s.status !== baseline.value[s.membership_id]?.status, changedNote: s.note !== baseline.value[s.membership_id]?.note }));
    try {
        const response = await api.value.put(base.value, { session_date: date, marks: students.value.filter(s => s.status).map(s => ({ membership_id: s.membership_id, status: s.status, note: s.note })) }, 'url', { 'X-Attendance-Revision': revision.value });
        if (!alive || run !== generation) return;
        apply(response.data.data, response.headers); saved.value = true; if (realm.value !== 'teacher') void loadOverview(date);
    } catch (e: any) {
        if (!alive || run !== generation) return;
        error.value = apiErrorText(e, 'Could not save the register.');
        // Keep the server's refusal visible while reloading a calendar or roster that changed.
        if (e?.response?.status === 409) {
            const message = error.value; saving.value = false; await load(date, true);
            if (!alive || loaded.value !== date || refusal.value) return;
            for (const student of students.value) {
                const choice = choices.find(s => s.membership_id === student.membership_id);
                if (!choice) continue;
                const statusDiffers = choice.changedStatus && choice.status !== student.status;
                const noteDiffers = choice.changedNote && choice.note !== student.note;
                if (statusDiffers || noteDiffers) {
                    conflicts.value[student.membership_id] = `Your unsaved ${statusDiffers ? `choice (${choice.status ?? 'unmarked'})` : 'note'} is shown below. Saved mark: ${student.status ?? 'unmarked'}${noteDiffers ? `. Saved note: ${student.note || '(empty)'}` : ''}. Review it before saving.`;
                    if (statusDiffers) student.status = choice.status;
                    if (noteDiffers) student.note = choice.note;
                }
            }
            error.value = message;
        } else if (e?.response?.status === 422) {
            const message = error.value; saving.value = false; await load(date); error.value = message;
        }
    } finally { if (alive && run === generation) saving.value = false; }
}
function choosePeriod(id: number) { if (saving.value) return; selectedMeeting.value = id; void load(loaded.value || draft.value); }
function openClass(id: number) { if (!saving.value) void router.push({ name: 'masjid.groupDetail', params: { groupId: id }, query: { tab: 'attendance', date: loaded.value } }); }
onMounted(() => load(draft.value));
watch(() => [props.masjidId, props.groupId, props.date, props.meetingId], () => { selectedMeeting.value = props.meetingId ?? 0; periodRegisters.value = []; periodMode.value = false; readOnly.value = false; draft.value = props.date ?? ''; void load(draft.value); });
onBeforeUnmount(() => { alive = false; ++generation; ++overviewGeneration; });
</script>

<style scoped>
.office-register, .register-student, .register-name { min-width: 0; max-width: 100%; }
.register-date { width: 12rem; max-width: 100%; }
.register-marks { display: flex; flex-wrap: wrap; gap: .25rem; }
.register-marks .btn { min-height: 44px; white-space: nowrap; overflow-wrap: normal; word-break: normal; }
.register-student textarea { width: 100%; min-width: 0; }
</style>
