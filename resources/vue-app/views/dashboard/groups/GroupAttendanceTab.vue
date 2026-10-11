<template>
    <section class="office-register" aria-label="Class attendance">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div class="register-date">
                <label for="office-attendance-date" class="form-label">Day</label>
                <input id="office-attendance-date" class="form-control" type="date" v-model="draft"
                    :max="today || undefined" :disabled="saving" @change="dateInput.typed" @blur="dateInput.commitNow" @keydown.enter.prevent="dateInput.commitNow" />
            </div>
            <button type="button" class="btn btn-outline-secondary" :disabled="loading || saving" @click="load(draft)">Reload</button>
        </div>
        <p v-if="loading" class="text-muted" role="status">Loading the register…</p>
        <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
        <p v-if="refusal" class="alert alert-secondary" role="status">{{ refusal }} Pick another day to see its register.</p>
        <div v-if="!loading && !refusal && loaded">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <span class="small text-muted">{{ formatSchoolDay(loaded, 'en-US') }} · {{ taken ? 'Register taken for this day.' : 'Not taken yet for this day.' }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!editable || !students.length" @click="markAll">Mark all present</button>
            </div>
            <p v-if="!students.length" class="text-muted">No students on this roster yet.</p>
            <div class="list-group mb-3">
                <div v-for="student in students" :key="student.membership_id" :data-student="student.membership_id" class="list-group-item register-student">
                    <div class="d-flex flex-wrap align-items-start gap-2 mb-2 register-name">
                        <span class="fw-semibold text-break">{{ name(student) }}</span>
                        <AllergyBadge v-if="student.student_allergy !== undefined" :note="student.student_allergy" />
                        <span v-if="student.grade_label" class="badge bg-primary-subtle text-primary-emphasis">{{ student.grade_label }}</span>
                    </div>
                    <p v-if="student.marked_by" class="small text-muted text-break mb-2">Marked by {{ student.marked_by }} at {{ when(student.marked_at) }}</p>
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
                <span v-if="saved" class="text-success" role="status">Saved.</span>
            </div>
        </div>
        <div v-if="loaded" class="mt-4">
            <h6>School registers for {{ formatSchoolDay(loaded, 'en-US') }}</h6>
            <p v-if="overviewError" class="alert alert-danger" role="alert">{{ overviewError }} <button type="button" class="btn btn-sm btn-outline-danger" @click="loadOverview(loaded)">Retry overview</button></p>
            <p v-if="overviewLoading" class="text-muted" role="status">Loading school registers…</p>
            <div v-else class="list-group">
                <div v-for="classroom in classes" :key="classroom.id" class="list-group-item text-break">
                    <button type="button" class="btn btn-link text-start p-0 text-break" :disabled="saving" @click="openClass(classroom.id)">{{ classroom.name }}</button>
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

const props = defineProps<{ groupId: number; masjidId: number; date?: string }>();
const router = useRouter();
type Student = { membership_id: number; contact: { first_name?: string; last_name?: string } | null; grade_label?: string; status: string | null; note: string; marked_by?: string | null; marked_at?: string | null; student_allergy?: any };
type Classroom = { id: number; name: string; taken: boolean; marked_by: string | null; marked_at: string | null };
const options = [{ value: 'present', label: 'Present' }, { value: 'absent', label: 'Absent' }, { value: 'late', label: 'Late' }, { value: 'excused', label: 'Excused' }];
const draft = ref(props.date ?? ''); const loaded = ref(''); const today = ref(''); const timezone = ref('America/New_York');
const students = ref<Student[]>([]); const classes = ref<Classroom[]>([]); const taken = ref(false); const refusal = ref('');
const loading = ref(false); const saving = ref(false); const saved = ref(false); const error = ref(''); const overviewError = ref(''); const overviewLoading = ref(false);
const base = computed(() => `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}/attendance`);
const editable = computed(() => !loading.value && !saving.value && !refusal.value && !!loaded.value && draft.value === loaded.value);
const name = (student: Student) => [student.contact?.first_name, student.contact?.last_name].filter(Boolean).join(' ') || 'Student';
const when = (iso?: string | null) => formatLastOpened(iso, 'en-US', timezone.value);
let generation = 0; let overviewGeneration = 0; let alive = true;
function apply(data: any) {
    loaded.value = data.session_date; draft.value = data.session_date; today.value = data.today; timezone.value = data.timezone;
    students.value = (data.students ?? []).map((s: Student) => ({ ...s, note: s.note ?? '' })); taken.value = data.taken; refusal.value = data.refusal ?? '';
}
function completeDate() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.value)) { error.value = 'Choose a complete date.'; return; }
    if (draft.value !== loaded.value) void load(draft.value);
}
const dateInput = useCommittedDate(draft, completeDate);
async function load(date = '') {
    if (saving.value) return;
    dateInput.clear(); const run = ++generation; loading.value = true; error.value = ''; saved.value = false;
    // Invalidate the old overview too: a later date must never receive an older answer.
    ++overviewGeneration; classes.value = []; overviewError.value = ''; loaded.value = ''; students.value = []; refusal.value = '';
    try {
        const response = await ApiService.get(`${base.value}${date ? `?date=${encodeURIComponent(date)}` : ''}`);
        if (!alive || run !== generation) return;
        const pendingDraft = draft.value;
        apply(response.data.data);
        if (pendingDraft !== date) draft.value = pendingDraft;
        void loadOverview(loaded.value);
    } catch (e) { if (alive && run === generation) error.value = apiErrorText(e, 'Could not load the register.'); }
    finally { if (alive && run === generation) loading.value = false; }
}
async function loadOverview(date: string) {
    const run = ++overviewGeneration; overviewLoading.value = true; overviewError.value = '';
    try {
        const response = await ApiService.get(`/api/admin/masjids/${props.masjidId}/attendance/registers?date=${encodeURIComponent(date)}`);
        if (alive && run === overviewGeneration) classes.value = response.data.data.classes;
    } catch (e) { if (alive && run === overviewGeneration) overviewError.value = apiErrorText(e, 'Could not load school registers.'); }
    finally { if (alive && run === overviewGeneration) overviewLoading.value = false; }
}
function markAll() { if (!editable.value) return; for (const student of students.value) student.status = 'present'; saved.value = false; }
async function save() {
    if (!editable.value || !students.value.some(s => s.status)) return;
    saving.value = true; error.value = ''; saved.value = false;
    const date = loaded.value; const run = generation;
    try {
        const response = await ApiService.put(base.value, { session_date: date, marks: students.value.filter(s => s.status).map(s => ({ membership_id: s.membership_id, status: s.status, note: s.note })) });
        if (!alive || run !== generation) return;
        apply(response.data.data); saved.value = true; void loadOverview(date);
    } catch (e: any) {
        if (!alive || run !== generation) return;
        error.value = apiErrorText(e, 'Could not save the register.');
        // Keep the server's refusal visible while reloading a calendar or roster that changed.
        if (e?.response?.status === 422) {
            const message = error.value; saving.value = false; await load(date); error.value = message;
        }
    } finally { if (alive && run === generation) saving.value = false; }
}
function openClass(id: number) { if (!saving.value) void router.push({ name: 'masjid.groupDetail', params: { groupId: id }, query: { tab: 'attendance', date: loaded.value } }); }
onMounted(() => load(draft.value));
watch(() => [props.masjidId, props.groupId, props.date], () => { draft.value = props.date ?? ''; void load(draft.value); });
onBeforeUnmount(() => { alive = false; ++generation; ++overviewGeneration; });
</script>

<style scoped>
.office-register, .register-student, .register-name { min-width: 0; max-width: 100%; }
.register-date { width: 12rem; max-width: 100%; }
.register-marks { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .25rem; }
.register-student textarea { width: 100%; min-width: 0; }
@media (max-width: 575px) {
    .register-marks { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
