<template>
    <div>
        <!-- READ ONLY, said once at the top, the way the gradebook beside it
             does. A plan carries its author's name and the reflection they
             wrote after the lesson; an office screen that looked editable and
             then dropped the save would put words in a teacher's mouth. -->
        <p class="text-muted small mb-3">
            What this class is being taught, week by week.
            Plans are written by the class teacher — this screen shows them, it does not change them.
        </p>

        <!-- Outside the loading branch, so the arrow somebody just pressed does
             not vanish while its week is on the way. -->
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="loading"
                    aria-label="Previous week" @click="shiftWeek(-1)">
                <i class="bi bi-chevron-left"></i>
            </button>
            <span class="small fw-semibold">{{ weekLabel }}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="loading"
                    aria-label="Next week" @click="shiftWeek(1)">
                <i class="bi bi-chevron-right"></i>
            </button>
            <button v-if="weekStart !== thisWeekStart" type="button" class="btn btn-sm btn-link px-1"
                    :disabled="loading" @click="weekStart = thisWeekStart">
                This week
            </button>
        </div>

        <div v-if="loadError" class="alert alert-warning py-2 small">{{ loadError }}</div>

        <div v-if="loading" class="text-muted small">Loading…</div>

        <!-- NOT `v-else`. A week that failed to load knows nothing about what the
             teacher wrote, and the day cards below would have said "No plan was
             written for this day" about every one of them — a read failure
             rendered as a fact about somebody's work. The alert above is the only
             honest thing to show. -->
        <template v-else-if="!loadError">
            <div v-for="day in daysOnScreen" :key="day.iso" class="card border-0 shadow-sm mb-2">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <span class="fw-semibold">{{ day.label }}</span>
                        <span v-if="!day.meets" class="badge bg-light text-muted fw-normal">
                            Not a scheduled day
                        </span>
                        <span v-if="day.plan?.prefill_source"
                              class="badge bg-success-subtle text-success-emphasis fw-normal">
                            from the pacing guide
                        </span>
                        <span v-if="day.plan?.updated_at" class="text-muted small ms-auto">
                            Last edited {{ when(day.plan.updated_at) }}
                        </span>
                    </div>

                    <!-- A plain sentence, not a dash. "No plan" and "a plan with
                         nothing in it" are different facts about a teacher's
                         week and the office is reading this to tell them apart. -->
                    <p v-if="!day.plan" class="text-muted small mb-0">
                        No plan was written for this day.
                    </p>

                    <template v-else>
                        <div v-if="day.plan.title" class="fw-semibold small mb-1">{{ day.plan.title }}</div>

                        <!-- Activities lead: it is the one field the teacher's
                             form requires, so it is the one field every plan on
                             this screen is guaranteed to have. -->
                        <p v-if="day.plan.body" class="small mb-2" style="white-space:pre-wrap">
                            {{ day.plan.body }}
                        </p>

                        <dl v-if="day.rows.length" class="row small mb-0">
                            <template v-for="row in day.rows" :key="row.key">
                                <dt class="col-sm-4 fw-semibold text-muted">{{ row.label }}</dt>
                                <dd class="col-sm-8">
                                    <ul v-if="row.list" class="mb-0 ps-3">
                                        <li v-for="(item, i) in row.list" :key="i">{{ item }}</li>
                                    </ul>
                                    <span v-else style="white-space:pre-wrap">{{ row.text }}</span>
                                </dd>
                            </template>
                        </dl>

                        <p v-else-if="!day.plan.body" class="text-muted small mb-0">
                            This plan was saved with nothing filled in.
                        </p>
                    </template>
                </div>
            </div>

            <p v-if="!daysOnScreen.length" class="text-muted small">
                This class does not meet on any day of this week.
            </p>
        </template>
    </div>
</template>

<script setup lang="ts">
import ApiService from '@/core/services/ApiService';
import {
    formatSchoolDay,
    isoDayToUtcDate,
    localIsoDay,
    weekdayOfIso,
} from '@/core/types/data/masjid-related/SchoolCalendar';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * The class's lesson plans, for the office.
 *
 * One GET per week and nothing else. Writing, prefilling from the pacing guide,
 * copying a plan across the week and deleting one all live in the teacher realm
 * and are absent here by construction: a plan is signed work, and the office
 * reading it must not be able to become its author. See routes/admin.php.
 *
 * The office and the teacher must READ THE SAME WORDS. The labels below are the
 * ones on the teacher's own form (TeacherClass.vue `planSections`), in
 * `LessonPlan::TEMPLATE_FIELDS` order, which is the order that form asks for
 * them in. A field this screen renamed would make two people describing the
 * same plan on the phone disagree about which box they meant.
 */
const props = defineProps<{ groupId: number; masjidId: number }>();

const base = computed(() => `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}`);

// Pinned rather than the browser's, matching SchoolCalendarView, so a plan's
// date reads identically on the two admin screens that show school days.
const LOCALE = 'en-US';

const loading = ref(true);
const loadError = ref('');
const plans = ref<any[]>([]);
const hiddenFields = ref<Set<string>>(new Set());
const meetingWeekdays = ref<number[] | null>(null);

/**
 * The 22 template fields in `LessonPlan::TEMPLATE_FIELDS` order, with the
 * teacher form's own wording.
 *
 * Three labels carry their section heading down into the label itself —
 * "Code" and "Description" sit under a "Standard" header on the teacher's
 * accordion, and "Methods" under "Teaching Methods & Aids". This screen has no
 * headers to sit under, and a row reading just "Code" in a list that also holds
 * a curriculum week number is a row nobody can place.
 *
 * `teaching_methods_other` has no label on the teacher's form at all: it is an
 * input that appears under the Methods buttons with the placeholder "Other —
 * which?". A placeholder is a question, not a heading, so this one label is
 * authored rather than reused.
 */
const FIELD_LABELS: { key: string; label: string }[] = [
    { key: 'subject', label: 'Subject' },
    { key: 'grade_label', label: 'Grade' },
    { key: 'curriculum_week_no', label: 'Week' },
    { key: 'standard_code', label: 'Standard code' },
    { key: 'standard_description', label: 'Standard description' },
    { key: 'objective', label: 'Objective' },
    { key: 'learning_outcomes', label: 'Learning outcomes' },
    { key: 'differentiation_support', label: 'Support for struggling learners' },
    { key: 'differentiation_extension', label: 'Extension for advanced learners' },
    { key: 'differentiation_learning_styles', label: 'Learning-style adjustments' },
    { key: 'differentiation_ell_aal', label: 'ELL / AAL' },
    { key: 'differentiation_sen', label: 'SEN' },
    { key: 'cross_integration_subject', label: 'Subject integration' },
    { key: 'cross_integration_islamic', label: 'Islamic integration' },
    { key: 'cross_integration_stem', label: 'STEM' },
    { key: 'teaching_methods', label: 'Teaching methods' },
    { key: 'teaching_methods_other', label: 'Other method' },
    { key: 'teaching_aids', label: 'Teaching aids' },
    { key: 'assessment_formative', label: 'Formative check' },
    { key: 'assessment_exit_ticket', label: 'Exit ticket / final task' },
    { key: 'reflection_worked', label: 'What worked well' },
    { key: 'reflection_improve', label: 'What needs improvement' },
];

/** The teacher's checkbox wording, mirroring `LessonPlan::TEACHING_METHODS`. */
const METHOD_LABELS: Record<string, string> = {
    modeling: 'Modeling',
    guided_practice: 'Guided Practice',
    cooperative_learning: 'Cooperative Learning',
    inquiry_discussion: 'Inquiry / Discussion',
    hands_on_activity: 'Hands-On Activity',
    storytelling: 'Storytelling',
    other: 'Other',
};

// ------------------------------------------------------------------ the week

const isoOfUtcDay = (d: Date): string => d.toISOString().slice(0, 10);

/**
 * Day arithmetic through UTC midnight, never through a local Date.
 *
 * `new Date('2026-03-08')` is UTC midnight while `new Date(2026, 2, 8)` is local
 * midnight, and adding seven local days across a DST boundary lands on 23:00 the
 * day before. Every day on this screen is a bare 'Y-m-d' that must survive the
 * round trip unchanged, so all of it stays in UTC — which is also what
 * `formatSchoolDay` renders in.
 */
const addDays = (iso: string, days: number): string => {
    const d = isoDayToUtcDate(iso);
    if (!d) return iso;
    d.setUTCDate(d.getUTCDate() + days);

    return isoOfUtcDay(d);
};

/** Sunday-first, matching the school week the teacher's own picker walks. */
const sundayOf = (iso: string): string => {
    const weekday = weekdayOfIso(iso);

    return weekday === null ? iso : addDays(iso, -weekday);
};

/**
 * The browser's week, not the school's.
 *
 * The office sits in the school building, so in practice these agree; there is
 * no school timezone on this payload to do better with, and the week the arrows
 * walk is a view, not a stored fact. The DAYS themselves are the server's —
 * `meeting_weekdays` and every `session_date` come off the response.
 */
const thisWeekStart = sundayOf(localIsoDay());
const weekStart = ref<string>(thisWeekStart);

const weekDays = computed(() => {
    const out: { iso: string; label: string; weekday: number }[] = [];
    for (let i = 0; i < 7; i++) {
        const iso = addDays(weekStart.value, i);
        out.push({
            iso,
            label: formatSchoolDay(iso, LOCALE, { weekday: 'long', month: 'short', day: 'numeric' }),
            weekday: weekdayOfIso(iso) ?? i,
        });
    }

    return out;
});

const weekLabel = computed(() => {
    const days = weekDays.value;
    if (!days.length) return '';

    const short: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };

    return `${formatSchoolDay(days[0].iso, LOCALE, short)} — ${formatSchoolDay(days[6].iso, LOCALE, short)}`;
});

/**
 * The plan for one day, matched on the DAY and not on the whole string.
 *
 * `session_date` is a `date` cast, and a cast column that reaches a payload
 * without being asked for a date string arrives as a full timestamp
 * ('2026-09-21T00:00:00.000000Z'). Compared whole against a bare 'Y-m-d' that
 * matches nothing, and a week of written plans would render as seven days
 * nobody planned — a wrong answer that looks exactly like a true one.
 */
const planFor = (iso: string) =>
    plans.value.find((p: any) => String(p?.session_date ?? '').slice(0, 10) === iso) ?? null;

// ------------------------------------------------------------------ one plan

const asList = (value: any): string[] | null => {
    if (!Array.isArray(value)) return null;
    const items = value.map((v) => String(v ?? '').trim()).filter(Boolean);

    return items.length ? items : null;
};

/**
 * The rows one plan actually draws.
 *
 * Two kinds of absence, both of which end in the field not being drawn, for
 * different reasons:
 *
 * `hidden_fields` names the boxes this organisation's template does not have.
 * A value can still sit in one of them — written before the template was
 * shortened — and showing it would hand the office a fact the teacher can no
 * longer see or correct.
 *
 * Everything null or empty is skipped too, so a plan with an objective and
 * nothing else draws one row rather than twenty-two, of which twenty-one say
 * nothing.
 */
const rowsFor = (plan: any): { key: string; label: string; text?: string; list?: string[] }[] => {
    const rows: { key: string; label: string; text?: string; list?: string[] }[] = [];

    for (const field of FIELD_LABELS) {
        if (hiddenFields.value.has(field.key)) continue;

        const value = plan?.[field.key];
        if (value === null || value === undefined) continue;

        if (field.key === 'teaching_methods') {
            // The stored codes, in the teacher's words. An unrecognised code is
            // rendered raw rather than dropped: it is a method somebody chose,
            // and a silently shorter list reads as a shorter lesson.
            const methods = asList(value)?.map((m) => METHOD_LABELS[m] ?? m);
            if (methods) rows.push({ key: field.key, label: field.label, list: methods });
            continue;
        }

        const list = asList(value);
        if (list) {
            rows.push({ key: field.key, label: field.label, list });
            continue;
        }

        // An array that reduced to nothing stops here rather than falling
        // through: String(['', '']) is "," and the office would read a row
        // whose entire content is a comma.
        if (Array.isArray(value)) continue;

        const text = String(value).trim();
        if (text) rows.push({ key: field.key, label: field.label, text });
    }

    return rows;
};

/**
 * The days this week that the office is shown, each with its plan already
 * resolved and already reduced to the rows it draws.
 *
 * The meeting days from the school calendar, or Monday to Friday without one —
 * the same fallback the teacher's grid uses. PLUS any other day that actually
 * carries a plan: a teacher who planned a Saturday makeup class wrote something
 * real, and a screen that filtered it out would tell the office that day was
 * empty. Rendering it labelled beats hiding it.
 */
const daysOnScreen = computed(() => {
    const meets = new Set(meetingWeekdays.value ?? [1, 2, 3, 4, 5]);

    return weekDays.value
        .map((d) => ({ ...d, meets: meets.has(d.weekday), plan: planFor(d.iso) }))
        .filter((d) => d.meets || d.plan)
        .map((d) => ({ ...d, rows: d.plan ? rowsFor(d.plan) : [] }));
});

/** `updated_at` is a timestamp, not a school day, so it is read as one. */
const when = (iso: string | null): string => {
    if (!iso) return '';
    const d = new Date(iso);

    return Number.isNaN(d.getTime())
        ? ''
        : d.toLocaleDateString(LOCALE, { month: 'short', day: 'numeric', year: 'numeric' });
};

// ------------------------------------------------------------------ reading

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        // The whole seven-day window, not just the meeting days: the window has
        // to be asked for before the answer says which days this class meets on.
        const days = weekDays.value;
        const res = await ApiService.get(
            `${base.value}/lesson-plans?from=${days[0].iso}&to=${days[6].iso}` as any
        );

        plans.value = res.data?.data?.plans ?? [];
        hiddenFields.value = new Set(res.data?.data?.hidden_fields ?? []);
        meetingWeekdays.value = Array.isArray(res.data?.data?.meeting_weekdays)
            ? res.data.data.meeting_weekdays
            : null;
    } catch (e: any) {
        loadError.value = e?.response?.data?.message ?? 'This week could not be loaded.';
    } finally {
        loading.value = false;
    }
};

const shiftWeek = (delta: number) => {
    weekStart.value = addDays(weekStart.value, delta * 7);
};

watch(weekStart, load);

onMounted(load);
</script>
