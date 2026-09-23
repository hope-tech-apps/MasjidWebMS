<template>
    <div>
        <!-- READ ONLY, and it says so once, at the top. An office screen that
             looks like the teacher's marking screen but silently drops the save
             is worse than one that never offered the control. -->
        <p class="text-muted small mb-3">
            What this class has been set, and how each child did.
            Marks are entered by the class teacher — this screen shows them, it does not change them.
        </p>

        <div v-if="loadError" class="alert alert-warning py-2 small">{{ loadError }}</div>

        <div v-if="loading" class="text-muted small">Loading…</div>

        <!-- ============================================== THE WORK SET -->
        <template v-else-if="!openAssignment">
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
                    </div>
                    <span class="badge"
                          :class="a.scored >= a.roster ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-muted'">
                        {{ a.scored }}/{{ a.roster }} marked
                    </span>
                    <i class="bi bi-chevron-right text-muted"></i>
                </button>
            </div>
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
                                <span v-if="pointsPct !== null" class="fs-6 text-muted">({{ pointsPct }}%)</span>
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

            <div class="list-group">
                <div v-for="(s, i) in student.scores" :key="i"
                     class="list-group-item d-flex align-items-center gap-3">
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">{{ s.assignment?.title ?? 'Withdrawn work' }}</div>
                        <div class="text-muted small">{{ s.assignment?.assigned_on }}</div>
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
            <div class="text-muted small mb-3">
                {{ openAssignment.assigned_on }} · {{ scaleLabel(openAssignment) }}
            </div>

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
import { computed, onMounted, ref } from 'vue';

/**
 * The class gradebook, for the office.
 *
 * Three GETs and nothing else: the work the class was set, the marks against
 * one piece of it, and one child's whole record. Every write the teacher realm
 * exposes here — setting work, entering marks, withdrawing work — is absent by
 * construction, because those writes mail the family and carry the marker's
 * name. The office asks; the teacher marks. See routes/admin.php.
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
// empty total is a division by zero rendered as "NaN%" on a child's record.
const pointsPct = computed<number | null>(() => {
    const possible = Number(student.value?.summary?.points_possible ?? 0);
    if (!possible) return null;

    return Math.round((Number(student.value?.summary?.points_earned ?? 0) / possible) * 100);
});

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(`${base.value}/assignments` as any);
        assignments.value = res.data?.data ?? [];
        levelKey.value = res.data?.performance_levels ?? [];
    } catch (e: any) {
        loadError.value = e?.response?.data?.message ?? 'The gradebook could not be loaded.';
    } finally {
        loading.value = false;
    }
};

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

onMounted(load);
</script>
