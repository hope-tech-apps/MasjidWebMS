<template>
    <div>
        <PageDataContainer
            title="School Calendar"
            :buttonProps="{ title: 'Add school year', type: 'button', class: 'btn btn-success', disabled: loading || !!loadError }"
            @headerButtonClick="openYearModal(null)"
        >
            <div class="container w-100">
                <p class="text-muted small mb-4">
                    Set each school year once, with its first and last day. Classes meet on the weekday of the
                    first day, every week until the last day. Then mark the days there is no school. Teachers,
                    families and sign-up forms all read this one calendar.
                </p>

                <!-- Loading -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading the school calendar…</span>
                    </div>
                </div>

                <!-- Error: never shown as an empty calendar, which would read as "no school year yet". -->
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
                </div>

                <!-- Empty -->
                <div v-else-if="!years.length" class="text-center py-5">
                    <i class="bi bi-calendar3 fs-1 d-block mb-3 text-muted"></i>
                    <p class="fw-semibold mb-1">No school year yet</p>
                    <p class="text-muted small mb-3">
                        Until you add one, teachers and families see "The school calendar hasn't been published yet",
                        and a sign-up question can't list school days.
                    </p>
                    <button type="button" class="btn btn-success btn-sm" @click="openYearModal(null)">
                        <i class="bi bi-plus-circle me-1"></i>Add the school year
                    </button>
                </div>

                <template v-else>
                    <p class="small text-muted mb-3">
                        <i class="bi bi-clock me-1"></i>
                        Dates follow the school's time zone<template v-if="timezone"> ({{ timezone }})</template>.
                        Today there is {{ formatDay(today) }}.
                    </p>

                    <!-- Which year -->
                    <div v-if="years.length > 1" class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="School year">
                        <button
                            v-for="year in years"
                            :key="year.id"
                            type="button"
                            class="btn btn-sm"
                            :class="selectedYearId === year.id ? 'btn-success' : 'btn-outline-secondary'"
                            :aria-pressed="selectedYearId === year.id"
                            @click="selectedYearId = year.id"
                        >
                            {{ year.label }}
                        </button>
                    </div>

                    <template v-if="selectedYear">
                        <div class="card border mb-4">
                            <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
                                <div>
                                    <h2 class="h5 mb-1">{{ selectedYear.label }}</h2>
                                    <div class="text-muted small">
                                        {{ formatDay(selectedYear.first_day) }} – {{ formatDay(selectedYear.last_day) }}
                                    </div>
                                    <div class="small mt-2">
                                        <i class="bi bi-arrow-repeat me-1"></i>Meets every {{ weekday(selectedYear.meeting_weekday) }}
                                        <span class="text-muted">
                                            · {{ openCount }} school day{{ openCount === 1 ? '' : 's' }}
                                            · {{ closedCount }} with no school
                                        </span>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="openYearModal(selectedYear)">
                                        <i class="bi bi-pencil me-1"></i>Edit year
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" @click="askDeleteYear(selectedYear)">
                                        <i class="bi bi-trash me-1"></i>Delete year
                                    </button>
                                </div>
                            </div>
                        </div>

                        <p class="small text-muted mb-3">
                            Click a school day to mark it as no school. Click a no-school day to change its reason
                            or make it a school day again.
                        </p>

                        <div v-if="!months.length" class="alert alert-warning">
                            This year has no school days. Check its first and last day.
                        </div>

                        <div v-for="month in months" :key="month.key" class="mb-4">
                            <h3 class="h6 fw-semibold text-muted mb-2">
                                {{ formatSchoolDay(month.firstDate, LOCALE, { month: 'long', year: 'numeric' }) }}
                            </h3>
                            <div class="list-group">
                                <button
                                    v-for="day in month.days"
                                    :key="day.date"
                                    type="button"
                                    class="list-group-item list-group-item-action d-flex flex-wrap align-items-center gap-2"
                                    :class="{ 'is-past': day.date < today, 'is-today': day.date === today }"
                                    :aria-current="day.date === today ? 'date' : undefined"
                                    @click="openDay(day)"
                                >
                                    <span class="fw-semibold small day-date">{{ formatDay(day.date, 'short') }}</span>
                                    <span v-if="day.date === today" class="badge bg-success-subtle text-success-emphasis">Today</span>
                                    <template v-if="day.closed">
                                        <span class="badge bg-danger-subtle text-danger-emphasis">No school</span>
                                        <span class="small text-body text-break">{{ day.reason }}</span>
                                    </template>
                                    <span v-else class="small text-muted">School day</span>
                                    <span class="ms-auto small text-primary text-nowrap">
                                        <i :class="`bi ${day.closed ? 'bi-pencil' : 'bi-calendar-x'} me-1`"></i>
                                        {{ day.closed ? 'Change' : 'Mark as no school' }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </template>
                </template>
            </div>
        </PageDataContainer>

        <!-- ============================================ add / edit a school year -->
        <Teleport to="body">
            <div v-if="yearModalOpen" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);"
                 role="dialog" aria-modal="true" aria-labelledby="schoolYearModalTitle" @click.self="closeYearModal">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form @submit.prevent="saveYear">
                            <div class="modal-header">
                                <h5 id="schoolYearModalTitle" class="modal-title">
                                    {{ editingYear ? 'Edit school year' : 'Add school year' }}
                                </h5>
                                <button type="button" class="btn-close" aria-label="Close" :disabled="savingYear" @click="closeYearModal"></button>
                            </div>
                            <div class="modal-body">
                                <div v-if="yearBanner" class="alert alert-danger py-2 small" role="alert">{{ yearBanner }}</div>

                                <div class="mb-3">
                                    <label class="form-label" for="schoolYearLabel">Name <span class="text-danger">*</span></label>
                                    <input id="schoolYearLabel" type="text" class="form-control" maxlength="32"
                                           :class="{ 'is-invalid': yearFieldErrors.label }"
                                           v-model="yearForm.label" placeholder="e.g. 2026–27">
                                    <div v-if="yearFieldErrors.label" class="invalid-feedback">{{ yearFieldErrors.label }}</div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label" for="schoolYearFirst">First day <span class="text-danger">*</span></label>
                                        <input id="schoolYearFirst" type="date" class="form-control"
                                               :class="{ 'is-invalid': yearFieldErrors.first_day }"
                                               v-model="yearForm.first_day">
                                        <div v-if="yearFieldErrors.first_day" class="invalid-feedback">{{ yearFieldErrors.first_day }}</div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="schoolYearLast">Last day <span class="text-danger">*</span></label>
                                        <input id="schoolYearLast" type="date" class="form-control"
                                               :class="{ 'is-invalid': yearFieldErrors.last_day || yearOrderProblem }"
                                               :min="yearForm.first_day || undefined"
                                               v-model="yearForm.last_day">
                                        <div v-if="yearFieldErrors.last_day" class="invalid-feedback">{{ yearFieldErrors.last_day }}</div>
                                        <div v-else-if="yearOrderProblem" class="invalid-feedback">{{ yearOrderProblem }}</div>
                                    </div>
                                </div>

                                <!-- The weekday is TAKEN from the first day, so say so while they pick it. -->
                                <div class="alert alert-light border small py-2 mt-3 mb-0">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    <template v-if="firstWeekday !== null">
                                        <strong>Meets every {{ weekday(firstWeekday) }}.</strong>
                                        The weekday comes from the first day.
                                    </template>
                                    <template v-else>
                                        Classes meet on the weekday of the first day, every week.
                                    </template>
                                </div>
                                <div v-if="lastDayWeekdayNote" class="alert alert-warning small py-2 mt-2 mb-0">
                                    {{ lastDayWeekdayNote }}
                                </div>
                                <p v-if="editingYear" class="form-text mb-0 mt-2">
                                    If new dates would leave a no-school day outside the year, you'll be asked to remove that day first.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" :disabled="savingYear" @click="closeYearModal">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="!canSaveYear">
                                    <span v-if="savingYear" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ editingYear ? 'Save changes' : 'Add school year' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ============================================ one day: close it, or change / reopen it -->
        <Teleport to="body">
            <div v-if="dayModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);"
                 role="dialog" aria-modal="true" aria-labelledby="schoolDayModalTitle" @click.self="closeDay">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form @submit.prevent="saveClosure">
                            <div class="modal-header">
                                <div>
                                    <h5 id="schoolDayModalTitle" class="modal-title">{{ formatDay(dayModal.date) }}</h5>
                                    <div class="small text-muted">
                                        {{ dayModal.closed ? 'No school on this day' : 'A school day' }}
                                    </div>
                                </div>
                                <button type="button" class="btn-close" aria-label="Close" :disabled="savingDay !== null" @click="closeDay"></button>
                            </div>
                            <div class="modal-body">
                                <div v-if="dayBanner" class="alert alert-danger py-2 small" role="alert">{{ dayBanner }}</div>

                                <label class="form-label" for="schoolDayReason">
                                    Why is there no school? <span class="text-danger">*</span>
                                </label>
                                <input id="schoolDayReason" type="text" class="form-control" maxlength="160"
                                       :class="{ 'is-invalid': dayFieldErrors.reason }"
                                       v-model="reasonInput" placeholder="e.g. Winter break, Eid al-Fitr">
                                <div v-if="dayFieldErrors.reason" class="invalid-feedback">{{ dayFieldErrors.reason }}</div>
                                <div v-else class="form-text">
                                    Teachers and families see this reason. No one can take attendance on a no-school day,
                                    and it drops off any sign-up question that lists school days.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button v-if="dayModal.closed" type="button" class="btn btn-outline-danger me-auto"
                                        :disabled="savingDay !== null" @click="removeClosure">
                                    <span v-if="savingDay === 'remove'" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    Make it a school day again
                                </button>
                                <button type="button" class="btn btn-secondary" :disabled="savingDay !== null" @click="closeDay">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="savingDay !== null || !reasonInput.trim()">
                                    <span v-if="savingDay === 'save'" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ dayModal.closed ? 'Save reason' : 'Mark as no school' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ============================================ delete a year -->
        <Teleport to="body">
            <div v-if="deleteTarget" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);"
                 role="dialog" aria-modal="true" aria-labelledby="schoolYearDeleteTitle" @click.self="cancelDeleteYear">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 id="schoolYearDeleteTitle" class="modal-title text-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>Delete {{ deleteTarget.label }}?
                            </h5>
                            <button type="button" class="btn-close" aria-label="Close" :disabled="deletingYear" @click="cancelDeleteYear"></button>
                        </div>
                        <div class="modal-body">
                            <div v-if="deleteError" class="alert alert-danger py-2 small" role="alert">{{ deleteError }}</div>
                            <p class="mb-2">
                                This removes the school year {{ formatDay(deleteTarget.first_day) }} – {{ formatDay(deleteTarget.last_day) }}<template
                                    v-if="deleteTarget.closures.length"> and its {{ deleteTarget.closures.length }}
                                    no-school day{{ deleteTarget.closures.length === 1 ? '' : 's' }}</template>.
                            </p>
                            <p class="text-muted small mb-0">
                                Teachers and families will stop seeing these dates, and a sign-up question that lists
                                school days will have none to offer until another year is added.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" :disabled="deletingYear" @click="cancelDeleteYear">Cancel</button>
                            <button type="button" class="btn btn-danger" :disabled="deletingYear" @click="confirmDeleteYear">
                                <span v-if="deletingYear" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                Delete year
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { AxiosResponse } from 'axios';
import Swal from 'sweetalert2';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import {
    SchoolCalendarDay,
    SchoolCalendarPayload,
    SchoolClosureCreatePayload,
    SchoolClosureUpdatePayload,
    SchoolYear,
    SchoolYearPayload,
    daysOfYear,
    defaultYear,
    formatSchoolDay,
    monthsOf,
    readSchoolCalendar,
    weekdayName,
    weekdayOfIso,
} from '@/core/types/data/masjid-related/SchoolCalendar';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * The ADMIN school calendar — school years and the days there is no school.
 *
 * Every write answers with the WHOLE calendar (the same body as the GET), so
 * the screen replaces its state from the response instead of patching a row it
 * thinks it changed. If a write ever succeeds without that body, the screen
 * reads the calendar back rather than trusting itself.
 *
 * Refusals are the server's to word: it names the blocking dates and counts
 * ("3 attendance marks exist on that day"), so they are shown verbatim.
 *
 * Gated by the `school_calendar` capability on the route and in the menu; the
 * server's `capability:school_calendar` middleware is the boundary.
 */

const LOCALE = 'en-US';

const authStore = useAuthStore();
const masjidStore = useMasjidStore();

/** dashboardMasjidId survives a hard refresh that has not yet hydrated masjidStore (see formsStore). */
const masjidId = computed(() => authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null);

const endpoint = (suffix = ''): BackendApiRoute =>
    `/api/admin/masjids/${masjidId.value}/school-calendar${suffix}` as BackendApiRoute;

// ------------------------------------------------------------------ state

const calendar = ref<SchoolCalendarPayload | null>(null);
const loading = ref(true);
const loadError = ref('');
const selectedYearId = ref<number | null>(null);

const years = computed<SchoolYear[]>(() => calendar.value?.years ?? []);
const today = computed(() => calendar.value?.today ?? '');
const timezone = computed(() => calendar.value?.timezone ?? '');
const selectedYear = computed(() => years.value.find((y) => y.id === selectedYearId.value) ?? null);
const selectedDays = computed<SchoolCalendarDay[]>(() => (selectedYear.value ? daysOfYear(selectedYear.value) : []));
const months = computed(() => monthsOf(selectedDays.value));
const closedCount = computed(() => selectedDays.value.filter((d) => d.closed).length);
const openCount = computed(() => selectedDays.value.length - closedCount.value);

const formatDay = (iso: string, style: 'long' | 'short' = 'long') =>
    formatSchoolDay(iso, LOCALE, style === 'long'
        ? { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }
        : { weekday: 'short', month: 'short', day: 'numeric' });

const weekday = (n: number) => weekdayName(n, LOCALE);

// ------------------------------------------------------------------ reading

/**
 * Take the calendar out of a response. `prefer` picks the year to show (a year
 * just added or edited); otherwise the current choice is kept while it exists.
 */
const applyPayload = (res: AxiosResponse | undefined, prefer?: (y: SchoolYear) => boolean): boolean => {
    const next = readSchoolCalendar(res?.data?.data);
    if (!next) return false;

    calendar.value = next;

    const preferred = prefer ? next.years.find(prefer) : undefined;
    if (preferred) {
        selectedYearId.value = preferred.id;
    } else if (!next.years.some((y) => y.id === selectedYearId.value)) {
        selectedYearId.value = defaultYear(next.years, next.today)?.id ?? null;
    }

    return true;
};

const load = async () => {
    if (!masjidId.value) return;

    loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(endpoint());
        if (!applyPayload(res)) {
            calendar.value = null;
            loadError.value = 'The school calendar could not be read. Please reload the page.';
        }
    } catch (error) {
        calendar.value = null;
        loadError.value = apiErrorText(error, 'Could not load the school calendar.');
    } finally {
        loading.value = false;
    }
};

/** After a write: the response IS the calendar. If it is not, read it back. */
const afterWrite = async (res: AxiosResponse, prefer?: (y: SchoolYear) => boolean) => {
    if (!applyPayload(res, prefer)) await load();
};

watch(masjidId, (id) => { if (id) load(); }, { immediate: true });

// ------------------------------------------------------------------ errors

const isBag = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);

/**
 * A 422's messages, split into the ones a control on screen can show inline and
 * the rest (a closure date, a school_year_id, a count of attendance marks),
 * which go in the banner — every message shown, none dropped.
 */
const splitErrors = (error: any, inline: string[], fallback: string): { fields: Record<string, string>; banner: string } => {
    const body = error?.response?.data ?? {};
    const bag = error?.response?.status === 422
        ? (isBag(body.data) ? body.data : isBag(body.errors) ? body.errors : null)
        : null;

    if (!bag) return { fields: {}, banner: apiErrorText(error, fallback) };

    const fields: Record<string, string> = {};
    const rest: string[] = [];

    for (const [key, messages] of Object.entries(bag)) {
        const list = (Array.isArray(messages) ? messages : [messages]).map((m) => String(m));
        const root = key.split('.')[0];
        if (inline.includes(root)) {
            fields[root] = fields[root] ? `${fields[root]} ${list.join(' ')}` : list.join(' ');
        } else {
            rest.push(...list);
        }
    }

    if (!Object.keys(fields).length && !rest.length) {
        return { fields, banner: apiErrorText(error, fallback) };
    }

    return { fields, banner: rest.join(' ') };
};

// ------------------------------------------------------------------ years

const yearModalOpen = ref(false);
const editingYear = ref<SchoolYear | null>(null);
const yearForm = ref<SchoolYearPayload>({ label: '', first_day: '', last_day: '' });
const yearFieldErrors = ref<Record<string, string>>({});
const yearBanner = ref('');
const savingYear = ref(false);

const firstWeekday = computed(() => weekdayOfIso(yearForm.value.first_day));
const lastWeekday = computed(() => weekdayOfIso(yearForm.value.last_day));

const yearOrderProblem = computed(() =>
    yearForm.value.first_day && yearForm.value.last_day && yearForm.value.last_day < yearForm.value.first_day
        ? 'The last day is before the first day.'
        : '');

/** Advice, not a block: the server decides, and its refusal is shown word for word. */
const lastDayWeekdayNote = computed(() => {
    const first = firstWeekday.value;
    const last = lastWeekday.value;
    if (first === null || last === null || first === last || yearOrderProblem.value) return '';
    return `The last day is a ${weekday(last)}, but classes meet on ${weekday(first)}s. `
        + `Pick the last ${weekday(first)} there is school.`;
});

const canSaveYear = computed(() =>
    !savingYear.value
    && !!yearForm.value.label.trim()
    && !!yearForm.value.first_day
    && !!yearForm.value.last_day
    && !yearOrderProblem.value);

const openYearModal = (year: SchoolYear | null) => {
    editingYear.value = year;
    yearForm.value = year
        ? { label: year.label, first_day: year.first_day, last_day: year.last_day }
        : { label: '', first_day: '', last_day: '' };
    yearFieldErrors.value = {};
    yearBanner.value = '';
    yearModalOpen.value = true;
};

const closeYearModal = () => {
    if (savingYear.value) return;
    yearModalOpen.value = false;
    editingYear.value = null;
};

const saveYear = async () => {
    if (!canSaveYear.value) return;

    const editing = editingYear.value;
    const payload: SchoolYearPayload = {
        label: yearForm.value.label.trim(),
        first_day: yearForm.value.first_day,
        last_day: yearForm.value.last_day,
    };

    savingYear.value = true;
    yearFieldErrors.value = {};
    yearBanner.value = '';
    try {
        const res = editing
            ? await ApiService.put(endpoint(`/years/${editing.id}`), payload)
            : await ApiService.post(endpoint('/years'), payload);

        yearModalOpen.value = false;
        editingYear.value = null;
        await afterWrite(res, (y) => (editing ? y.id === editing.id : y.first_day === payload.first_day));

        Swal.fire({
            icon: 'success',
            title: editing ? 'School year saved' : 'School year added',
            timer: 2500,
            showConfirmButton: false,
        });
    } catch (error) {
        const split = splitErrors(error, ['label', 'first_day', 'last_day'],
            editing ? 'Could not save the school year.' : 'Could not add the school year.');
        yearFieldErrors.value = split.fields;
        yearBanner.value = split.banner;
    } finally {
        savingYear.value = false;
    }
};

const deleteTarget = ref<SchoolYear | null>(null);
const deletingYear = ref(false);
const deleteError = ref('');

const askDeleteYear = (year: SchoolYear) => {
    deleteError.value = '';
    deleteTarget.value = year;
};

const cancelDeleteYear = () => {
    if (deletingYear.value) return;
    deleteTarget.value = null;
};

const confirmDeleteYear = async () => {
    const target = deleteTarget.value;
    if (!target) return;

    deletingYear.value = true;
    deleteError.value = '';
    try {
        const res = await ApiService.delete(endpoint(`/years/${target.id}`));
        deleteTarget.value = null;
        await afterWrite(res);
        Swal.fire({
            icon: 'success',
            title: 'School year deleted',
            text: `${target.label} has been removed.`,
            timer: 2500,
            showConfirmButton: false,
        });
    } catch (error) {
        // Kept open, so the server's reason stays next to the button it refused.
        deleteError.value = apiErrorText(error, 'Could not delete the school year.');
    } finally {
        deletingYear.value = false;
    }
};

// ------------------------------------------------------------------ closures

const dayModal = ref<SchoolCalendarDay | null>(null);
const reasonInput = ref('');
const dayFieldErrors = ref<Record<string, string>>({});
const dayBanner = ref('');
const savingDay = ref<'save' | 'remove' | null>(null);

const openDay = (day: SchoolCalendarDay) => {
    dayModal.value = day;
    reasonInput.value = day.reason ?? '';
    dayFieldErrors.value = {};
    dayBanner.value = '';
};

const closeDay = () => {
    if (savingDay.value !== null) return;
    dayModal.value = null;
};

const saveClosure = async () => {
    const day = dayModal.value;
    const year = selectedYear.value;
    const reason = reasonInput.value.trim();
    if (!day || !year || !reason || savingDay.value !== null) return;

    savingDay.value = 'save';
    dayFieldErrors.value = {};
    dayBanner.value = '';
    try {
        let res: AxiosResponse;
        if (day.closureId !== null) {
            const payload: SchoolClosureUpdatePayload = { reason };
            res = await ApiService.put(endpoint(`/closures/${day.closureId}`), payload);
        } else {
            const payload: SchoolClosureCreatePayload = { school_year_id: year.id, closed_on: day.date, reason };
            res = await ApiService.post(endpoint('/closures'), payload);
        }
        dayModal.value = null;
        await afterWrite(res);
    } catch (error) {
        const split = splitErrors(error, ['reason'], 'Could not save this day.');
        dayFieldErrors.value = split.fields;
        dayBanner.value = split.banner;
    } finally {
        savingDay.value = null;
    }
};

const removeClosure = async () => {
    const day = dayModal.value;
    if (!day || day.closureId === null || savingDay.value !== null) return;

    savingDay.value = 'remove';
    dayFieldErrors.value = {};
    dayBanner.value = '';
    try {
        const res = await ApiService.delete(endpoint(`/closures/${day.closureId}`));
        dayModal.value = null;
        await afterWrite(res);
    } catch (error) {
        dayBanner.value = apiErrorText(error, 'Could not make this a school day again.');
    } finally {
        savingDay.value = null;
    }
};

// Lock the page behind any open dialog, and let Escape close it.
const anyModalOpen = computed(() => yearModalOpen.value || dayModal.value !== null || deleteTarget.value !== null);

const onEscape = (e: KeyboardEvent) => {
    if (e.key !== 'Escape') return;
    if (dayModal.value) closeDay();
    else if (deleteTarget.value) cancelDeleteYear();
    else if (yearModalOpen.value) closeYearModal();
};

watch(anyModalOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) document.addEventListener('keydown', onEscape);
    else document.removeEventListener('keydown', onEscape);
});

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onEscape);
});
</script>

<style scoped>
.day-date {
    min-width: 7.5rem;
}

/* Past days stay clickable (a closure can be recorded after the fact) but read
   as past — by colour, so the text keeps its contrast. */
.is-past .day-date {
    color: var(--bs-secondary-color);
}

.is-today {
    border-left: 3px solid var(--bs-success);
}

.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
