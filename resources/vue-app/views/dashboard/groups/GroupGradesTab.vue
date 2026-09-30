<template>
    <div>
        <!-- READ ONLY for work and marks, and it says so once, at the top. An
             office screen that looks like the teacher's marking screen but
             silently drops the save is worse than one that never offered the
             control. The one control here is the class's weights, below: how
             much each type counts is a policy of the class, not a mark. -->
        <p class="text-muted small mb-3">
            What this class has been set, and how each child did.
            Marks are entered by the class teacher — this screen shows them, it does not change them.
        </p>

        <div v-if="loadError" class="alert alert-warning py-2 small">{{ loadError }}</div>

        <div v-if="loading" class="text-muted small">Loading…</div>

        <!-- ============================================== THE WORK SET -->
        <template v-else-if="!openAssignment">
            <!-- ============================================ THE CLASS'S WEIGHTS -->
            <!-- The office sets these, not only reads them: a class whose teachers are
                 all limited to some subjects has nobody else who may. Never read-only
                 here, unlike the teacher's panel (which is for a limited teacher). -->
            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" :aria-expanded="showWeights" @click="toggleWeights">
                    <i class="bi bi-sliders me-1"></i>{{ weightingEnabled ? 'Weights' : 'Set weights' }}
                </button>
            </div>

            <div v-if="showWeights" class="card border-0 shadow-sm mb-3" data-test="weights-panel">
                <div class="card-body">
                    <div class="fw-semibold mb-1">How much each type of work counts</div>
                    <p class="text-muted small mb-2">
                        Each type of work counts by its weight, however many pieces of it there are: with Test at
                        40 and Homework at 10, Tests make up four fifths of the average whether a child has done
                        one Homework or ten. Weights are relative, so they do not have to add up to 100, and a
                        type nobody has been marked on yet changes nothing. A teacher can give one piece of work its
                        own weight when they set it, and it then counts on its own, beside the types. Leave the
                        weights unset for a plain average.
                    </p>
                    <div class="row g-2 align-items-end">
                        <div v-for="t in workTypes" :key="t.key" class="col-6 col-sm-auto">
                            <label class="form-label small text-muted mb-1" :for="`weight-${t.key}`">{{ t.label }}</label>
                            <input :id="`weight-${t.key}`" v-model="weightsForm[t.key]" type="number" inputmode="numeric"
                                   min="0" :max="weightMax" step="1" class="form-control form-control-sm" style="width:5.5rem">
                        </div>
                        <div class="col-auto d-flex gap-2">
                            <button class="btn btn-sm btn-success" :disabled="savingWeights" @click="saveWeights">
                                {{ savingWeights ? 'Saving…' : 'Save weights' }}
                            </button>
                            <button v-if="weightingEnabled && !confirmClearWeights" class="btn btn-sm btn-outline-danger"
                                    :disabled="savingWeights" @click="confirmClearWeights = true">Clear</button>
                        </div>
                    </div>
                    <div v-if="confirmClearWeights" class="alert alert-warning small mt-3 mb-0">
                        Clear the weights? Every average goes back to the plain one, and any weight a teacher gave to
                        one piece of work is removed too.
                        <div class="mt-2 d-flex gap-2">
                            <button class="btn btn-sm btn-danger" :disabled="savingWeights" @click="clearWeights">Clear them</button>
                            <button class="btn btn-sm btn-light" @click="confirmClearWeights = false">Keep them</button>
                        </div>
                    </div>
                    <p v-if="weightsSaved" class="text-success small mt-2 mb-0"><i class="bi bi-check-circle me-1"></i>Saved</p>
                    <p v-if="weightsError" class="text-danger small mt-2 mb-0" role="alert">{{ weightsError }}</p>
                </div>
            </div>

            <p v-if="!assignments.length" class="text-muted small">No work has been set for this class yet.</p>
            <div v-else class="list-group">
                <button v-for="a in assignments" :key="a.id" type="button"
                        class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                        @click="openScores(a)">
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">{{ a.title }}</div>
                        <div class="text-muted small">
                            {{ a.assigned_on }} · {{ scaleLabel(a) }}
                        </div>
                        <!-- The wrapper asks about the weight note too: a simple-scale piece with no subject, type or
                             standard has nothing else to show, and its "not averaged" is the one thing worth saying (review G5). -->
                        <div v-if="a.subject || a.type_label || a.standard_code || weightNote(a, weights, weightingEnabled) || (weightingEnabled && isUntyped(a))"
                             class="d-flex flex-wrap gap-1 mt-1">
                            <span v-if="a.subject" class="badge bg-primary-subtle text-primary-emphasis fw-normal">{{ a.subject }}</span>
                            <span v-if="a.type_label" class="badge bg-secondary-subtle text-secondary-emphasis fw-normal">{{ a.type_label }}</span>
                            <span v-if="weightNote(a, weights, weightingEnabled)" class="badge bg-light text-muted fw-normal">{{ weightNote(a, weights, weightingEnabled) }}</span>
                            <span v-if="a.standard_code" class="badge bg-success-subtle text-success-emphasis fw-normal"
                                  :title="a.curriculum_focus ?? ''">{{ a.standard_code }}</span>
                            <span v-if="weightingEnabled && isUntyped(a)" class="badge bg-warning-subtle text-warning-emphasis fw-normal"
                                  title="Work with no type is left out of the weighted average">no type</span>
                        </div>
                    </div>
                    <span class="badge"
                          :class="a.scored >= a.roster ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-muted'">
                        {{ a.scored }}/{{ a.roster }} marked
                    </span>
                    <i class="bi bi-chevron-right text-muted"></i>
                </button>
            </div>
            <p v-if="assignments.length && weightingEnabled && untypedInList > 0" class="text-warning-emphasis small mt-2 mb-0" data-test="untyped-note">
                {{ untypedListNote(untypedInList) }}
            </p>
        </template>

        <!-- ============================================ ONE CHILD'S RECORD -->
        <template v-else-if="student">
            <button class="btn btn-link px-0 text-decoration-none mb-2" @click="student = null">
                &larr; {{ openAssignment.title }}
            </button>

            <div class="d-flex align-items-center gap-3 mb-3">
                <PersonAvatar :avatar="student.student?.contact?.avatar"
                              :first-name="student.student?.contact?.first_name"
                              :last-name="student.student?.contact?.last_name" :size="48" />
                <div>
                    <div class="fw-semibold">{{ name(student.student?.contact) }}</div>
                    <div class="text-muted small">{{ student.summary?.recorded ?? 0 }} marks recorded</div>
                </div>
            </div>

            <!-- TWO SCALES, TWO SUMMARIES, NEVER ONE NUMBER. Points work is a
                 total out of a total; levels work is a mean level. Adding them
                 together would turn "Meets Expectations" into 75%, which is
                 exactly what a standards scale exists to prevent — the server
                 keeps them apart (GradebookController::forMember) and so does
                 this card. -->
            <div class="row g-3 mb-3">
                <div v-if="(student.summary?.points_counted ?? 0) > 0" class="col-12 col-md-6">
                    <div class="card border-0 bg-light h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase" style="letter-spacing:.04em">Points work</div>
                            <div class="fs-4 fw-semibold">
                                {{ student.summary.points_earned }} / {{ student.summary.points_possible }}
                                <span v-if="pointsPct !== null" class="fs-6 text-muted">({{ pointsPct }})</span>
                            </div>
                            <div class="text-muted small">
                                over {{ student.summary.points_counted }}
                                {{ student.summary.points_counted === 1 ? 'piece' : 'pieces' }} of work
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="(student.summary?.simple?.recorded ?? 0) > 0" class="col-12 col-md-6">
                    <div class="card border-0 bg-light h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase" style="letter-spacing:.04em">Marked in words</div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <span v-for="d in student.summary.simple.distribution" :key="d.value"
                                      class="badge bg-white border text-muted fw-normal">
                                    {{ d.label }}: {{ d.count }}
                                </span>
                            </div>
                            <div v-if="student.summary.simple.missing" class="text-muted small mt-2">
                                {{ student.summary.simple.missing }} not handed in
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="(student.summary?.levels?.recorded ?? 0) > 0" class="col-12 col-md-6">
                    <div class="card border-0 bg-light h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase" style="letter-spacing:.04em">Levels work</div>
                            <div class="fs-4 fw-semibold">
                                {{ student.summary.levels.mean ?? '—' }}
                                <span v-if="student.summary.levels.mean_label" class="fs-6 text-muted">
                                    · {{ student.summary.levels.mean_label }}
                                </span>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <span v-for="d in student.summary.levels.distribution" :key="d.level"
                                      class="badge bg-white border text-muted fw-normal">
                                    {{ d.level }} · {{ d.short_label }}: {{ d.count }}
                                </span>
                            </div>
                            <div v-if="student.summary.levels.missing" class="text-muted small mt-2">
                                {{ student.summary.levels.missing }} not handed in
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- The class's weights applied to this child (T-001.2), and the same
                 marks grouped by subject (T-001.3): what the teacher's Students
                 view and the parent's screen show, from the same arithmetic. -->
            <div v-if="studentLines.length || student.summary?.by_subject?.length" class="card border-0 bg-light mb-3">
                <div class="card-body">
                    <dl v-if="studentLines.length" class="row small mb-2">
                        <template v-for="line in studentLines" :key="line.label">
                            <dt class="col-sm-4 fw-semibold">{{ line.label }}</dt>
                            <dd class="col-sm-8 mb-1">{{ line.value }} <span class="text-muted">{{ line.note }}</span></dd>
                        </template>
                    </dl>
                    <ul v-if="student.summary?.by_subject?.length" class="list-unstyled small mb-0">
                        <li v-for="b in student.summary.by_subject" :key="b.subject ?? '_none'" class="d-flex justify-content-between gap-3">
                            <span>{{ b.subject ?? 'No subject' }}</span>
                            <span class="text-muted text-end">{{ subjectLine(b) || '—' }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="list-group">
                <div v-for="(s, i) in student.scores" :key="i"
                     class="list-group-item d-flex align-items-center gap-3">
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">{{ s.assignment?.title ?? 'Withdrawn work' }}</div>
                        <div class="text-muted small">
                            {{ s.assignment?.assigned_on }}<span v-if="s.assignment?.subject"> · {{ s.assignment.subject }}</span><span v-if="s.assignment?.type_label"> · {{ s.assignment.type_label }}</span>
                        </div>
                    </div>
                    <span class="badge" :class="markClass(s.status)">{{ markText(s, s.assignment) }}</span>
                </div>
                <div v-if="!student.scores?.length" class="text-muted small p-3">Nothing marked yet.</div>
            </div>
            <p v-if="student.scores_truncated" class="text-muted small mt-2 mb-0">
                Showing the {{ student.scores_shown }} most recent marks.
            </p>
        </template>

        <!-- ============================================= ONE PIECE OF WORK -->
        <template v-else>
            <button class="btn btn-link px-0 text-decoration-none mb-2" @click="openAssignment = null">
                &larr; All work
            </button>
            <div class="fw-semibold mb-1">{{ openAssignment.title }}</div>
            <div class="text-muted small mb-1">
                {{ openAssignment.assigned_on }} · {{ scaleLabel(openAssignment) }}
                <span v-if="openAssignment.subject"> · {{ openAssignment.subject }}</span>
                <span v-if="openAssignment.type_label"> · {{ openAssignment.type_label }}</span>
                <span v-if="weightNote(openAssignment, weights, weightingEnabled)"> · {{ weightNote(openAssignment, weights, weightingEnabled) }}</span>
            </div>
            <div v-if="openAssignment.standard_code || openAssignment.curriculum_focus" class="small mb-3">
                <span class="fw-semibold">{{ openAssignment.standard_code || 'Standard' }}</span>
                {{ openAssignment.curriculum_focus }}
                <span class="text-muted">· from the school's pacing guide<span v-if="openAssignment.curriculum_week_no">, week {{ openAssignment.curriculum_week_no }}</span></span>
            </div>
            <div v-else class="mb-3"></div>

            <!-- The school's own words for the levels, off the payload rather
                 than hardcoded, so the office reads the same key the teacher
                 marked against. -->
            <details v-if="openAssignment.scale === 'levels' && levelKey.length" class="mb-3">
                <summary class="small text-primary" style="cursor:pointer">What do 4, 3, 2 and 1 mean?</summary>
                <dl class="row small mt-2 mb-0">
                    <template v-for="l in levelKey" :key="l.level">
                        <dt class="col-sm-3 fw-semibold">{{ l.level }} — {{ l.short_label }}</dt>
                        <dd class="col-sm-9 text-muted">{{ l.description }}</dd>
                    </template>
                </dl>
            </details>

            <div class="list-group">
                <button v-for="s in openAssignment.students" :key="s.membership_id" type="button"
                        class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                        @click="openStudent(s)">
                    <PersonAvatar :avatar="s.contact?.avatar"
                                  :first-name="s.contact?.first_name" :last-name="s.contact?.last_name" :size="34" />
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold small">{{ name(s.contact) }}</span>
                            <span v-if="s.grade_label" class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                {{ s.grade_label }}
                            </span>
                        </div>
                        <div v-if="s.note" class="text-muted small">{{ s.note }}</div>
                    </div>
                    <span class="badge" :class="markClass(s.status)">{{ markText(s, openAssignment) }}</span>
                    <i class="bi bi-chevron-right text-muted"></i>
                </button>
                <div v-if="!openAssignment.students?.length" class="text-muted small p-3">
                    No students on this roster yet.
                </div>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import ApiService from '@/core/services/ApiService';
import {
    averageLines, firstFieldError, isUntyped, pointsPercentText, subjectLine, untypedInWork, untypedListNote, weightNote,
    weightsClearCall, weightsFormFrom, weightsRequest, weightsSaveCall, type WeightsCall,
} from '@/core/helpers/gradebook';
import { computed, onMounted, ref } from 'vue';

/**
 * The class gradebook, for the office.
 *
 * Three GETs and one PUT: the work the class was set, the marks against one
 * piece of it, one child's whole record, and the class's weights. Every write
 * the teacher realm exposes here — setting work, entering marks, withdrawing
 * work — is absent by construction, because those writes mail the family and
 * carry the marker's name. The office asks; the teacher marks. The weights are
 * the one exception: a policy of the class that signs no judgement, and one a
 * teacher limited to some subjects may not change, so the office must be able
 * to. See routes/admin.php.
 *
 * A BLANK IS NOT A ZERO, on every row on this screen. An unmarked child reads
 * "Not marked", missing work reads "Missing" and excused work reads "Excused" —
 * three different facts that a single dash would flatten into one.
 */
const props = defineProps<{ groupId: number; masjidId: number }>();

const base = computed(() => `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}`);

const loading = ref(true);
const loadError = ref('');
const assignments = ref<any[]>([]);
const levelKey = ref<any[]>([]);
// The class's weights: the office reads them, and sets or clears them below. The types
// and the ceiling come off the payload, as they do on the teacher's screen.
const weights = ref<Record<string, number>>({});
const weightingEnabled = ref(false);
const workTypes = ref<{ key: string; label: string }[]>([]);
const weightMax = ref(100);
const showWeights = ref(false);
const weightsForm = ref<Record<string, string>>({});
const savingWeights = ref(false);
const weightsSaved = ref(false);
const weightsError = ref('');
const confirmClearWeights = ref(false);
const openAssignment = ref<any>(null);
const student = ref<any>(null);

const name = (c: any) => [c?.first_name, c?.last_name].filter(Boolean).join(' ') || 'Student';

// THREE scales, not two: an organisation with `simple_marking` on marks work
// Excellent / Good / Needs work (stored 3/2/1), and printing "out of 4" over it
// would turn a word into a fraction. Same words the teacher's screen uses.
const SCALE_LABELS: Record<string, string> = {
    levels: 'levels 4–1',
    simple: 'Excellent / Good / Needs work',
};
const scaleLabel = (a: any) => SCALE_LABELS[a?.scale] ?? `out of ${a?.points_possible}`;

/**
 * What one cell says.
 *
 * `status` leads and the number follows, never the other way round: a missing
 * piece of work can carry a stored zero, and printing "0" for it would tell the
 * office the child scored nothing rather than handed nothing in.
 */
const markText = (s: any, a: any): string => {
    if (s?.status === 'missing') return 'Missing';
    if (s?.status === 'excused') return 'Excused';
    if (s?.status !== 'scored' || s?.points_earned === null || s?.points_earned === undefined) return 'Not marked';

    // The server labels the marks it stores as codes (Excellent / Good / Needs
    // work is a 3, a 2 or a 1), and its word wins over anything reconstructed
    // here — the office must read what the teacher chose, not a number that
    // scale never meant.
    if (s?.mark_label) return s.mark_label;

    if (a?.scale === 'levels') {
        const level = levelKey.value.find((l: any) => l.level === Number(s.points_earned));

        return level ? `${level.level} · ${level.short_label}` : String(s.points_earned);
    }

    return `${s.points_earned} / ${a?.points_possible ?? '—'}`;
};

const markClass = (status: string | null) => {
    if (status === 'missing') return 'bg-danger-subtle text-danger-emphasis';
    if (status === 'excused') return 'bg-secondary-subtle text-secondary-emphasis';
    if (status === 'scored') return 'bg-success-subtle text-success-emphasis';

    return 'bg-light text-muted';
};

// Points work only, and only when there is a denominator: a percentage over an
// empty total is a division by zero rendered as "NaN%" on a child's record. The
// same helper, and so the same rounding, as the "Points" line in the figures card below
// (`averageLines`): this block used to round to a whole number and that one to a decimal,
// so one child read 85% in one and 84.7% in the other.
const pointsPct = computed<string | null>(() =>
    pointsPercentText(student.value?.summary?.points_earned, student.value?.summary?.points_possible));

const studentLines = computed(() => averageLines(student.value?.summary));

// Work a weighted class leaves out for want of a type: the same count, and the same words, as the teacher's list.
const untypedInList = computed(() => untypedInWork(assignments.value));

// `quiet` re-reads the list without the "Loading…" swap, so the weights panel that just
// saved is still on screen when the fresh list lands.
const load = async (quiet = false) => {
    if (!quiet) loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(`${base.value}/assignments` as any);
        assignments.value = res.data?.data ?? [];
        levelKey.value = res.data?.performance_levels ?? [];
        weights.value = { ...(res.data?.weights ?? {}) };
        weightingEnabled.value = !!res.data?.weighting_enabled;
        workTypes.value = res.data?.types ?? workTypes.value;
        weightMax.value = res.data?.weight_max ?? weightMax.value;
    } catch (e: any) {
        loadError.value = e?.response?.data?.message ?? 'The gradebook could not be loaded.';
    } finally {
        loading.value = false;
    }
};

// ---------- the class's weights ----------
const toggleWeights = () => {
    showWeights.value = !showWeights.value;
    confirmClearWeights.value = false;
    weightsSaved.value = false;
    weightsError.value = '';
    if (showWeights.value) weightsForm.value = weightsFormFrom(weights.value, workTypes.value);
};

const sendWeights = async (call: WeightsCall, failed: string) => {
    weightsError.value = '';
    weightsSaved.value = false;
    savingWeights.value = true;
    try {
        const res = await ApiService.put(call.url as any, call.payload);
        weights.value = { ...(res.data?.data?.weights ?? {}) };
        weightingEnabled.value = !!res.data?.data?.weighting_enabled;
        weightsForm.value = weightsFormFrom(weights.value, workTypes.value);
        confirmClearWeights.value = false;
        weightsSaved.value = true;
        // Clearing removes every piece's own weight, and the badges on the list read them.
        await load(true);
    } catch (e: any) {
        weightsError.value = firstFieldError(e, failed);
    } finally {
        savingWeights.value = false;
    }
};

const saveWeights = async () => {
    weightsError.value = '';
    weightsSaved.value = false;
    const request = weightsRequest(weightsForm.value, workTypes.value, weightMax.value);
    if (!request.ok) { weightsError.value = request.message; return; }

    await sendWeights(weightsSaveCall(base.value, request.weights), 'The weights could not be saved.');
};

const clearWeights = () => sendWeights(weightsClearCall(base.value), 'The weights could not be cleared.');

const openScores = async (a: any) => {
    loading.value = true;
    loadError.value = '';
    student.value = null;
    try {
        const res = await ApiService.get(`${base.value}/assignments/${a.id}` as any);
        openAssignment.value = res.data?.data ?? null;
        // The key travels with the marking screen too; take the fresher copy.
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
    } catch (e: any) {
        loadError.value = e?.response?.data?.message ?? 'That work could not be opened.';
    } finally {
        loading.value = false;
    }
};

const openStudent = async (s: any) => {
    loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(`${base.value}/members/${s.membership_id}/grades` as any);
        student.value = res.data?.data ?? null;
        levelKey.value = student.value?.performance_levels ?? levelKey.value;
    } catch (e: any) {
        loadError.value = e?.response?.data?.message ?? "That child's record could not be opened.";
    } finally {
        loading.value = false;
    }
};

onMounted(() => load());
</script>
