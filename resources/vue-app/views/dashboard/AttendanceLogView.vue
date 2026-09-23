<template>
    <div class="attendance-log">
        <PageDataContainer
            title="Attendance"
            :hideButton="true"
            :paginationOptions="paginationOptions"
            @pageChange="pageChange"
        >
            <!-- READ ONLY, said once, at the top. The office reads the register;
                 the class teacher takes it. Every write lives in the teacher
                 realm because a mark carries the marker's name — the same
                 boundary the office's gradebook tab sits behind. -->
            <p class="text-muted small mb-3">
                What each class has recorded, day by day.
                Marks are taken by the class teacher — this screen shows them, it does not change them.
            </p>

            <div v-if="loadError" class="alert alert-warning py-2 small">{{ loadError }}</div>
            <!-- OUTSIDE the branches on purpose. This alert used to sit inside
                 `v-if="member"`, and `member` is null at exactly the moment the
                 drill-down fails — so a child's record that could not be opened
                 said nothing at all and the office was left looking at the grid
                 it had just clicked away from. -->
            <div v-if="memberError" class="alert alert-warning py-2 small">{{ memberError }}</div>

            <div v-if="bootstrapping" class="text-muted small">Loading the register…</div>

            <template v-else-if="log">

                <!-- ======================================= ONE CHILD'S RECORD -->
                <template v-if="member">
                    <button class="btn btn-link px-0 text-decoration-none mb-2" @click="closeMember">
                        &larr; All students
                    </button>

                    <div class="d-flex align-items-center gap-3 mb-3">
                        <PersonAvatar
                            :avatar="member.student.contact?.avatar"
                            :first-name="member.student.contact?.first_name"
                            :last-name="member.student.contact?.last_name"
                            :size="48"
                        />
                        <div>
                            <div class="fw-semibold">{{ fullName(member.student.contact) }}</div>
                            <div class="text-muted small">
                                {{ member.student.group_name }}
                                <template v-if="member.student.grade_label"> · {{ member.student.grade_label }}</template>
                                <template v-if="member.student.left_on">
                                    · left {{ humanDate(member.student.left_on) }}
                                </template>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted small">
                        {{ humanDate(member.from) }} to {{ humanDate(member.to) }} ({{ member.timezone }}).
                    </p>

                    <!-- The same six counts the grid row shows, from the same
                         server helper. They are printed, never recomputed here:
                         a record that disagrees with the row it was opened from
                         is the one failure this screen cannot survive. -->
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span
                            v-for="status in STATUS_ORDER"
                            :key="status"
                            class="badge fw-normal"
                            :class="badgeClass(status)"
                        >
                            {{ ATTENDANCE_LABELS[status] }}: {{ member.totals[status] }}
                        </span>
                        <span class="badge bg-light text-muted fw-normal">
                            {{ member.totals.marked }} marked of {{ member.totals.registers }}
                            {{ member.totals.registers === 1 ? 'register' : 'registers' }}
                        </span>
                    </div>

                    <h3 class="h6 fw-semibold mt-4">Every mark</h3>
                    <div class="list-group mb-4">
                        <div
                            v-for="entry in member.entries"
                            :key="entry.date"
                            class="list-group-item d-flex align-items-center gap-3"
                        >
                            <span class="small text-nowrap">{{ humanDate(entry.date) }}</span>
                            <span class="badge fw-normal" :class="badgeClass(entry.status)">
                                {{ ATTENDANCE_LABELS[entry.status] }}
                            </span>
                            <span v-if="entry.note" class="text-muted small flex-grow-1">{{ entry.note }}</span>
                        </div>
                        <div v-if="!member.entries.length" class="text-muted small p-3">
                            Nothing has been marked for this child in this window.
                        </div>
                    </div>

                    <!-- Not "absences". These are the days the class took a
                         register while this child was enrolled and nobody
                         marked them, which is a gap in the paperwork, not a
                         fact about the child. -->
                    <h3 class="h6 fw-semibold">Not marked</h3>
                    <p class="text-muted small">
                        Days this child's class took a register while they were enrolled and no mark was entered for them.
                    </p>
                    <div v-if="member.not_marked.length" class="d-flex flex-wrap gap-2">
                        <span
                            v-for="date in member.not_marked"
                            :key="date"
                            class="badge bg-light text-muted fw-normal"
                        >
                            {{ humanDate(date) }}
                        </span>
                    </div>
                    <p v-else class="text-muted small mb-0">
                        Nothing missing — every register their class took has a mark for them.
                    </p>
                </template>

                <template v-else>

                    <!-- ================================================= TODAY -->
                    <section class="mb-4" aria-labelledby="attendance-today-heading">
                        <h2 id="attendance-today-heading" class="h6 fw-semibold">
                            Today · {{ humanDate(log.today.date) }}
                        </h2>

                        <!-- A CLOSED DAY IS NOT A FORGOTTEN REGISTER. Without this
                             line every class below reads "No register yet" on a day
                             the school was shut, and the office chases teachers who
                             did nothing wrong. -->
                        <p v-if="log.today.school_day.closed" class="text-muted small mb-2">
                            No school today<template v-if="log.today.school_day.reason">
                                — {{ log.today.school_day.reason }}</template>.
                        </p>
                        <p
                            v-else-if="log.today.school_day.has_calendar && !log.today.school_day.meeting_day"
                            class="text-muted small mb-2"
                        >
                            The school does not meet today.
                        </p>

                        <div v-if="!log.today.classes.length" class="text-muted small">
                            There are no {{ classesTerm.toLowerCase() }} here yet, so there is no register to take.
                        </div>

                        <div v-else class="row g-3">
                            <div class="col-lg-7">
                                <ul class="list-group list-group-flush">
                                    <li
                                        v-for="klass in log.today.classes"
                                        :key="klass.group_id"
                                        class="list-group-item d-flex align-items-center gap-3 px-0"
                                    >
                                        <span class="fw-semibold small flex-grow-1">{{ klass.name }}</span>
                                        <span
                                            class="badge fw-normal"
                                            :class="klass.taken ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-muted'"
                                        >
                                            <template v-if="klass.taken">
                                                Register taken · {{ klass.marked }} of {{ todayDenominator(klass) }} marked
                                            </template>
                                            <template v-else>No register yet</template>
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <!-- The away list is the thing an office does every
                                 morning, so it sits beside the register status
                                 rather than under the grid. It is NOT folded
                                 into the class lines above: the payload gives
                                 each child a group_name and no group_id, and
                                 joining two classes on a display string is how
                                 two classes called "Grade 3" merge into one. -->
                            <div class="col-lg-5">
                                <div class="card border-0 bg-light h-100">
                                    <div class="card-body">
                                        <div class="text-muted small text-uppercase mb-2" style="letter-spacing:.04em">
                                            Away today
                                        </div>
                                        <ul v-if="log.today.away.length" class="list-unstyled mb-0">
                                            <li
                                                v-for="child in log.today.away"
                                                :key="child.membership_id"
                                                class="d-flex align-items-center gap-2 mb-1"
                                            >
                                                <span
                                                    class="badge fw-normal"
                                                    :class="badgeClass(child.status)"
                                                >
                                                    {{ ATTENDANCE_LABELS[child.status] }}
                                                </span>
                                                <span class="small">{{ fullName(child) }}</span>
                                                <span v-if="child.group_name" class="text-muted small">· {{ child.group_name }}</span>
                                            </li>
                                        </ul>
                                        <!-- An empty list means two different
                                             things and they are not the same
                                             news. With no register taken yet
                                             nobody CAN be listed away, and
                                             reading that as "everybody is here"
                                             is how a child goes unaccounted
                                             for until home time. -->
                                        <p v-else-if="!anyRegisterToday" class="text-muted small mb-0">
                                            No register has been taken yet today, so nobody is listed away.
                                        </p>
                                        <p v-else class="text-muted small mb-0">
                                            Nobody has been marked away today.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- =============================================== FILTERS -->
                    <section class="row g-3 mb-3 no-print" aria-label="Filter the register">
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="attendance-from">From</label>
                            <input
                                id="attendance-from"
                                v-model="fromDate"
                                type="date"
                                class="form-control"
                                :max="toDate || undefined"
                            >
                        </div>
                        <div class="col-md-6 col-lg-2">
                            <label class="form-label small text-muted mb-1" for="attendance-to">To</label>
                            <input
                                id="attendance-to"
                                v-model="toDate"
                                type="date"
                                class="form-control"
                                :min="fromDate || undefined"
                            >
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="attendance-class">
                                {{ classesTerm }}
                            </label>
                            <select id="attendance-class" v-model="groupFilter" class="form-select">
                                <option value="">All {{ classesTerm.toLowerCase() }}</option>
                                <option v-for="option in classOptions" :key="option.group_id" :value="option.group_id">
                                    {{ option.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label small text-muted mb-1" for="attendance-search">Search</label>
                            <div class="input-group flex-nowrap">
                                <span class="input-group-text bg-white" aria-hidden="true"><i class="bi bi-search"></i></span>
                                <input
                                    id="attendance-search"
                                    v-model="searchQuery"
                                    type="search"
                                    class="form-control"
                                    placeholder="Student name"
                                >
                                <button
                                    v-if="searchQuery"
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Clear search"
                                    aria-label="Clear search"
                                    @click="searchQuery = ''"
                                >
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-2 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input
                                    id="attendance-withdrawn"
                                    v-model="includeWithdrawn"
                                    class="form-check-input"
                                    type="checkbox"
                                >
                                <label class="form-check-label small" for="attendance-withdrawn">
                                    Include withdrawn students
                                </label>
                            </div>
                        </div>
                    </section>

                    <p class="text-muted small">
                        {{ humanDate(log.from) }} to {{ humanDate(log.to) }} ({{ log.timezone }}).
                        <!-- An organisation with no school year gets columns only
                             where somebody took a register. Saying so stops the
                             office reading a short row of columns as lost marks. -->
                        <template v-if="!log.has_calendar">
                            No school year is set up, so the days below are only the days a register was taken.
                        </template>
                    </p>

                    <p v-if="log.closures.length" class="text-muted small">
                        No school on
                        <template v-for="(closure, index) in log.closures" :key="closure.date">
                            <template v-if="index">; </template>{{ humanDate(closure.date) }}<template v-if="closure.reason"> ({{ closure.reason }})</template>
                        </template>.
                    </p>

                    <!-- ============================================== THE LOG -->
                    <!-- Never truncated silently: past the column cap the server
                         withholds the grid and says how many days the window
                         really covers, and the class totals and Today below are
                         still served because they need no columns. -->
                    <div v-if="log.meta.grid_omitted" class="alert alert-secondary py-2 small">
                        {{ log.meta.grid_omitted_reason ?? defaultOmittedReason }}
                    </div>

                    <template v-else-if="!log.students.length">
                        <p v-if="filtersNarrowing" class="text-muted small">
                            No student matches these filters.
                        </p>
                        <p v-else class="text-muted small">
                            No student is on a class roster here yet.
                        </p>
                    </template>

                    <template v-else-if="!log.days.length">
                        <p class="text-muted small">
                            No register was taken between {{ humanDate(log.from) }} and {{ humanDate(log.to) }}.
                            Widen the dates to reach a day that has one.
                        </p>
                    </template>

                    <template v-else>
                        <div class="att-grid-scroll mb-2">
                            <table class="table table-sm table-bordered att-grid mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col" class="att-name-col">Student</th>
                                        <th
                                            v-for="day in log.days"
                                            :key="day.date"
                                            scope="col"
                                            class="att-day-col"
                                            :title="humanDate(day.date)"
                                        >
                                            <span class="d-block text-muted fw-normal">{{ day.weekday }}</span>
                                            <span class="d-block">{{ shortDate(day.date) }}</span>
                                        </th>
                                        <th scope="col" class="att-tail-col">Marks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="student in log.students" :key="student.membership_id">
                                        <th scope="row" class="att-name-col fw-normal">
                                            <button
                                                type="button"
                                                class="btn btn-link p-0 text-start text-decoration-none d-flex align-items-center gap-2"
                                                @click="openMember(student)"
                                            >
                                                <PersonAvatar
                                                    :avatar="student.contact?.avatar"
                                                    :first-name="student.contact?.first_name"
                                                    :last-name="student.contact?.last_name"
                                                    :size="28"
                                                />
                                                <span>
                                                    <span class="d-block small fw-semibold">{{ fullName(student.contact) }}</span>
                                                    <span class="d-block text-muted att-row-sub">
                                                        {{ student.group_name }}
                                                        <template v-if="student.grade_label"> · {{ student.grade_label }}</template>
                                                        <template v-if="student.left_on"> · left {{ shortDate(student.left_on) }}</template>
                                                    </span>
                                                </span>
                                            </button>
                                        </th>

                                        <td
                                            v-for="day in log.days"
                                            :key="day.date"
                                            class="att-cell"
                                            :class="cellClass(student, day)"
                                            :title="cellTitle(student, day)"
                                        >
                                            <template v-if="student.cells[day.date]">
                                                {{ ATTENDANCE_LETTERS[student.cells[day.date].status] }}
                                            </template>
                                            <template v-else-if="cellState(student, day) === 'unmarked'">
                                                <span aria-hidden="true">·</span>
                                                <span class="visually-hidden">Not marked</span>
                                            </template>
                                            <template v-else-if="cellState(student, day) === 'no-register'">
                                                <!-- A GLYPH, not only the hatch. Browsers drop background
                                                     images when they print, and without a mark in ink this
                                                     cell and "not enrolled" come out of the printer as the
                                                     same blank square — the one distinction this grid exists
                                                     to make. Drawn in ink, it survives. -->
                                                <span class="att-hatch-glyph" aria-hidden="true">/</span>
                                                <span class="visually-hidden">No register taken</span>
                                            </template>
                                        </td>

                                        <!-- Four counts and an honest denominator.
                                             No percentage, here or anywhere on
                                             this screen: there is no
                                             days_possible in this codebase, and
                                             a rate computed over the days that
                                             happen to be in the window is a
                                             number a parent would be told and
                                             nobody could defend. -->
                                        <td class="att-tail-col small">
                                            <span class="att-count att-present">{{ student.totals.present }}P</span>
                                            <span class="att-count att-late">{{ student.totals.late }}L</span>
                                            <span class="att-count att-absent">{{ student.totals.absent }}A</span>
                                            <span class="att-count att-excused">{{ student.totals.excused }}E</span>
                                            <span class="text-muted">
                                                of {{ student.totals.registers }}
                                                {{ student.totals.registers === 1 ? 'register' : 'registers' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- The legend is not decoration: four of the cell
                             states look like "nothing" and only this says which
                             nothing each one is. -->
                        <ul class="list-inline small text-muted att-legend">
                            <li class="list-inline-item">
                                <span class="att-swatch att-present">P</span> present
                            </li>
                            <li class="list-inline-item">
                                <span class="att-swatch att-late">L</span> late
                            </li>
                            <li class="list-inline-item">
                                <span class="att-swatch att-absent">A</span> absent
                            </li>
                            <li class="list-inline-item">
                                <span class="att-swatch att-excused">E</span> excused
                            </li>
                            <li class="list-inline-item">
                                <span class="att-swatch att-no-register"></span> no register taken that day
                            </li>
                            <li class="list-inline-item">
                                <span class="att-swatch att-unmarked">·</span> register taken, this child not marked
                            </li>
                            <li class="list-inline-item">
                                <span class="att-swatch"></span> not enrolled that day
                            </li>
                        </ul>
                    </template>

                    <!-- ======================================== CLASS TOTALS -->
                    <section class="mt-4" aria-labelledby="attendance-classes-heading">
                        <h2 id="attendance-classes-heading" class="h6 fw-semibold">
                            {{ classesTerm }} over this window
                        </h2>

                        <div v-if="!log.classes.length" class="text-muted small">
                            There are no {{ classesTerm.toLowerCase() }} here yet, so there is nothing to total.
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Class</th>
                                        <th scope="col" class="text-end">Roster</th>
                                        <th scope="col" class="text-end">Registers taken</th>
                                        <th scope="col" class="text-end">Marks</th>
                                        <th scope="col" class="text-end">Present</th>
                                        <th scope="col" class="text-end">Late</th>
                                        <th scope="col" class="text-end">Absent</th>
                                        <th scope="col" class="text-end">Excused</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="klass in log.classes" :key="klass.group_id">
                                        <td class="small fw-semibold">{{ klass.name }}</td>
                                        <td class="text-end small">{{ klass.roster }}</td>
                                        <td class="text-end small">{{ klass.registers_taken }}</td>
                                        <td class="text-end small">{{ klass.marked }}</td>
                                        <td class="text-end small">{{ klass.present }}</td>
                                        <td class="text-end small">{{ klass.late }}</td>
                                        <td class="text-end small">{{ klass.absent }}</td>
                                        <td class="text-end small">{{ klass.excused }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </template>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, nextTick, ref, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import { apiErrorText } from '@/core/services/ApiErrors';
import {
    ATTENDANCE_LABELS,
    ATTENDANCE_LETTERS,
    AttendanceClassTotals,
    AttendanceContact,
    AttendanceDay,
    AttendanceLogFilters,
    AttendanceStatus,
    AttendanceStudentRow,
    AttendanceTodayClass
} from '@/core/types/data/masjid-related/Attendance';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { useAttendanceLogStore } from '@/stores/masjid/attendanceLogStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * The attendance log — the office's read of the register the class teachers
 * take.
 *
 * READ-ONLY, by construction rather than by omission. There is no save path on
 * this screen and no store method that could grow one: a mark carries the name
 * of the person who entered it, and the office is not that person. The same
 * boundary GroupGradesTab states for the gradebook.
 *
 * Three things this screen must never do, each of them a way to file a wrong
 * fact about a child:
 *
 *  1. **Never render a percentage or an attendance rate.** The payload
 *     deliberately carries none, there is no days_possible in this codebase,
 *     and a rate computed over "the days in the window" would be a number a
 *     parent is told and nobody can defend. Four counts and the register
 *     denominator, nothing else.
 *  2. **Never read a blank cell as an absence.** A missing mark is one of three
 *     different facts — the register was not taken, it was taken and this child
 *     was missed, or the child was not enrolled that day — and the grid draws
 *     all three differently because flattening them is what turns a clerical
 *     gap into an absence record.
 *  3. **Never compute a total here.** Every figure on the page is printed off
 *     the payload. The drill-down's totals and the grid row's come from one
 *     server helper, and the screen's whole claim is that the two agree.
 *
 * Dates are 'Y-m-d' strings in the SCHOOL's timezone, which the payload names.
 * They are sliced and compared as strings and never handed to `new Date()`:
 * parsing 'Y-m-d' yields UTC midnight, which renders as the previous day for
 * every organisation west of Greenwich — a register column labelled a day early.
 */

// Stores
const attendanceStore = useAttendanceLogStore();
const masjidStore = useMasjidStore();

// State
const bootstrapping = ref(true);
const loadError = ref('');
const memberError = ref('');

const fromDate = ref('');
const toDate = ref('');
const groupFilter = ref<number | ''>('');
const searchQuery = ref('');
const includeWithdrawn = ref(false);

/**
 * The class picker's options, kept from a response read with no class filter
 * on. The payload's `classes` block describes the set in scope, so reading the
 * options straight off it would collapse the picker to the one class already
 * chosen and leave no way back to the others.
 */
const classOptions = ref<Pick<AttendanceClassTotals, 'group_id' | 'name'>[]>([]);

const log = computed(() => attendanceStore.log);
const member = computed(() => attendanceStore.member);

/** School vocabulary: a school's `groups` term is "Classrooms". */
const classesTerm = computed<string>(() => masjidStore.term('groups'));

/** Badge order, and the order of the counts in a row's tail. */
const STATUS_ORDER: AttendanceStatus[] = ['present', 'late', 'absent', 'excused'];

const BADGE_CLASSES: Record<AttendanceStatus, string> = {
    present: 'bg-success-subtle text-success-emphasis',
    late: 'bg-warning-subtle text-warning-emphasis',
    absent: 'bg-danger-subtle text-danger-emphasis',
    excused: 'bg-secondary-subtle text-secondary-emphasis',
};

const badgeClass = (status: AttendanceStatus) => BADGE_CLASSES[status];

/**
 * A person's name from whatever the payload carried.
 *
 * Deliberately loose: the grid rows take an AttendanceContact, which the server
 * sends as null when the membership has lost its contact row, and the away list
 * carries bare name fields instead of a contact block. Both can hold nulls, and
 * a row that renders a blank where a child's name goes is a row an office
 * cannot act on — "Student" at least says somebody is there.
 */
const fullName = (person?: Pick<AttendanceContact, 'first_name' | 'last_name'> | null) =>
    [person?.first_name, person?.last_name].filter(Boolean).join(' ') || 'Student';

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
];

/**
 * 'Y-m-d' to "15 September 2026", by splitting the string.
 *
 * Not `new Date(iso).toLocaleDateString()`: that parses a bare 'Y-m-d' as UTC
 * midnight and prints the day before in every timezone behind UTC. The dates on
 * this screen are the school's own days and must survive being read in any
 * browser.
 */
const humanDate = (iso?: string | null): string => {
    if (!iso) return '';

    const [year, month, day] = iso.slice(0, 10).split('-');
    const name = MONTHS[Number(month) - 1];

    return name ? `${Number(day)} ${name} ${year}` : iso;
};

/** The column header's second line: "15 Sep". Same string-only reasoning. */
const shortDate = (iso?: string | null): string => {
    if (!iso) return '';

    const [, month, day] = iso.slice(0, 10).split('-');
    const name = MONTHS[Number(month) - 1];

    return name ? `${Number(day)} ${name.slice(0, 3)}` : iso;
};

/**
 * Whether ANY class has a register today.
 *
 * The away list being empty means one of two unrelated things, and the card
 * says which. See the copy beside it.
 */
const anyRegisterToday = computed<boolean>(() =>
    (log.value?.today.classes ?? []).some((klass) => klass.taken));

const filtersNarrowing = computed<boolean>(() =>
    !!groupFilter.value || !!searchQuery.value.trim());

/**
 * The sentence shown where the grid would be when the window is too wide. The
 * server sends its own, counting the real days; this only covers a payload that
 * sets the flag and not the reason, so the space where a register should be is
 * never left blank.
 */
const defaultOmittedReason = computed<string>(() =>
    `That window covers ${log.value?.meta.columns ?? 0} school days. `
    + `The grid shows at most ${log.value?.meta.column_cap ?? 0} — narrow the dates.`);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    // The pager walks STUDENTS. A child's own record is one payload, and a
    // withheld grid has no rows to page through — PageDataContainer hides the
    // pager entirely when this is undefined.
    if (!log.value || member.value || log.value.meta.grid_omitted) return undefined;

    return {
        currentPage: log.value.meta.page,
        itemsTotal: log.value.meta.total,
        perPage: log.value.meta.per_page
    };
});

const filters = computed<AttendanceLogFilters>(() => ({
    from: fromDate.value,
    to: toDate.value,
    group_id: groupFilter.value,
    search: searchQuery.value,
    include_withdrawn: includeWithdrawn.value
}));

/**
 * Was this child on the roster that day?
 *
 * Both bounds are compared as 'Y-m-d' strings after slicing to ten characters:
 * the `date` cast stores 'Y-m-d 00:00:00', so a bound can arrive with a time on
 * it, and lexicographic comparison against an unsliced bound is subtly wrong
 * at the boundary day.
 *
 * `joined_at` is optional in the payload. When it is absent this answers "yes"
 * for every day up to `left_on`, so the cells before a child joined fall back to
 * the ordinary "not marked" middot rather than claiming an enrolment date the
 * server never sent.
 */
const enrolledOn = (student: AttendanceStudentRow, date: string): boolean => {
    const joined = student.joined_at ? student.joined_at.slice(0, 10) : null;
    const left = student.left_on ? student.left_on.slice(0, 10) : null;

    if (joined && date < joined) return false;
    if (left && date > left) return false;

    return true;
};

/** Which day holds a register for which classes, without scanning an array per cell. */
const takenByDate = computed<Map<string, Set<number>>>(() => {
    const map = new Map<string, Set<number>>();

    for (const day of log.value?.days ?? []) {
        map.set(day.date, new Set(day.taken_by));
    }

    return map;
});

type CellState = 'marked' | 'outside' | 'unmarked' | 'no-register';

/**
 * What one cell is.
 *
 * A MARK WINS OVER EVERYTHING, including the enrolment bounds. A mark outside
 * the recorded window is real data that somebody entered, and hiding it would
 * quietly delete a child's attendance from the screen because a joined_at was
 * typed wrong — the server counts `registers` as the UNION of the days owed and
 * the days marked for the same reason. Enrolment then beats the register states: a class that met
 * after a child left has no claim on that child's row.
 */
/**
 * The denominator this morning's fraction can be honest against.
 *
 * `marked` counts the rows the register holds; `roster` counts who is on the
 * roster NOW. A child withdrawn after the register was taken is in the first and
 * not the second, and the fraction then reads "12 of 11 marked". Same guard as
 * the server's `registers` union: a roster edit must never drop a real mark out
 * of a count, so the count grows to hold it instead.
 */
const todayDenominator = (klass: AttendanceTodayClass): number => Math.max(klass.marked, klass.roster);

const cellState = (student: AttendanceStudentRow, day: AttendanceDay): CellState => {
    if (student.cells[day.date]) return 'marked';
    if (!enrolledOn(student, day.date)) return 'outside';

    return takenByDate.value.get(day.date)?.has(student.group_id) ? 'unmarked' : 'no-register';
};

const cellClass = (student: AttendanceStudentRow, day: AttendanceDay): string => {
    const state = cellState(student, day);

    if (state === 'marked') return `att-mark att-${student.cells[day.date].status}`;

    return `att-${state}`;
};

/** The note travels in the title, which is the only place it fits in a grid. */
const cellTitle = (student: AttendanceStudentRow, day: AttendanceDay): string => {
    const date = humanDate(day.date);
    const cell = student.cells[day.date];

    if (cell) {
        return cell.note
            ? `${date} · ${ATTENDANCE_LABELS[cell.status]} — ${cell.note}`
            : `${date} · ${ATTENDANCE_LABELS[cell.status]}`;
    }

    const state = cellState(student, day);

    if (state === 'outside') return `${date} · not enrolled`;
    if (state === 'unmarked') return `${date} · register taken, not marked`;

    return `${date} · no register taken for this class`;
};

/**
 * Assign without waking the filter watchers, then load once. Without it,
 * syncing the date inputs to the window the server actually served would
 * re-request the same window forever.
 */
let suppressFilterWatchers = false;

const quietly = async (assign: () => void) => {
    suppressFilterWatchers = true;
    assign();
    await nextTick();
    suppressFilterWatchers = false;
};

let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const load = async (page = 1) => {
    loadError.value = '';
    // The drill-down's banner now renders above both branches, so a stale one
    // would follow the office back to the grid.
    memberError.value = '';

    try {
        await attendanceStore.fetchLog(filters.value, page);

        const served = attendanceStore.log;
        if (!served) return;

        // First load asks for no window and the server picks one. Put its
        // answer in the pickers, so the office can see which dates it is
        // reading before it changes them.
        if (!fromDate.value || !toDate.value) {
            await quietly(() => {
                fromDate.value = served.from;
                toDate.value = served.to;
            });
        }

        if (!groupFilter.value) {
            classOptions.value = served.classes.map((klass) => ({
                group_id: klass.group_id,
                name: klass.name
            }));
        }
    } catch (error) {
        loadError.value = apiErrorText(error, 'The attendance log could not be loaded.');
    } finally {
        bootstrapping.value = false;
    }
};

const pageChange = async (data: PageChangeData) => {
    // Pagination.vue emits once from its own onBeforeMount, and the pager only
    // mounts once a payload has given it a total — so that first emit lands
    // immediately after the load that produced it. Asking again for the page
    // already on screen would double the most expensive read this screen makes.
    if (data.toPage === log.value?.meta.page) return;

    await load(data.toPage);
};

/**
 * Open one child's record over the window the SERVER served, not the window in
 * the pickers. A half-typed date in the From field would otherwise read the
 * record over a different range from the row it was opened from, and those two
 * disagreeing is the failure this screen exists to avoid.
 */
const openMember = async (student: AttendanceStudentRow) => {
    const served = attendanceStore.log;
    if (!served) return;

    memberError.value = '';

    try {
        await attendanceStore.fetchMember(student.membership_id, { from: served.from, to: served.to });
    } catch (error) {
        memberError.value = apiErrorText(error, "That child's record could not be opened.");
    }
};

const closeMember = () => {
    memberError.value = '';
    attendanceStore.clearMember();
};

watch([fromDate, toDate, groupFilter, includeWithdrawn], async () => {
    if (suppressFilterWatchers) return;

    // A filter change describes a different set, so the page the admin was on
    // no longer means anything — back to the first page of the new set.
    await load(1);
});

watch(searchQuery, () => {
    if (suppressFilterWatchers) return;

    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        await load(1);
    }, 350);
});

/**
 * Switching organisation must not leave one school's children on screen under
 * another school's name. Close any open record and read the new tenant's
 * register from scratch, dates included: the window is the school's own.
 */
watch(() => attendanceStore.masjidId(), async (id, previousId) => {
    if (!id || id === previousId) return;

    closeMember();
    bootstrapping.value = true;

    await quietly(() => {
        fromDate.value = '';
        toDate.value = '';
        groupFilter.value = '';
        searchQuery.value = '';
        includeWithdrawn.value = false;
        classOptions.value = [];
    });

    await load(1);
});

onBeforeMount(async () => {
    await load(1);
});
</script>

<style scoped>
/*
    The grid scrolls INSIDE this box. A register 40 columns wide would otherwise
    make the whole dashboard scroll sideways on a phone, taking the sidebar and
    the filter row with it.
*/
.att-grid-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    /* The scroll box is the boundary, so it may never be wider than the card it
       sits in — that is the difference between the register scrolling and the
       whole dashboard scrolling. */
    max-width: 100%;
}

.att-grid {
    width: auto;
    min-width: 100%;
}

.att-grid th,
.att-grid td {
    text-align: center;
    vertical-align: middle;
    padding: 0.25rem 0.35rem;
}

/*
    The name stays put while the days scroll past it. Without it, scrolling to
    last Thursday leaves a wall of letters with nothing to say whose they are.
    The opaque background is load-bearing: a transparent sticky cell shows the
    columns sliding under the names.
*/
.att-name-col {
    position: sticky;
    left: 0;
    z-index: 2;
    background-color: #fff;
    text-align: left;
    min-width: 12rem;
}

thead .att-name-col {
    z-index: 3;
}

.att-row-sub {
    font-size: 0.72rem;
    line-height: 1.1;
}

.att-day-col {
    min-width: 3.25rem;
    font-size: 0.72rem;
    line-height: 1.15;
    white-space: nowrap;
}

.att-tail-col {
    text-align: right;
    white-space: nowrap;
    min-width: 11rem;
}

.att-cell {
    width: 3.25rem;
    height: 2.1rem;
    font-weight: 600;
    font-size: 0.8rem;
}

.att-present { color: #146c43; }
.att-late { color: #997404; }
.att-absent { color: #b02a37; }
.att-excused { color: #5c636a; }

.att-mark.att-present { background-color: rgba(25, 135, 84, 0.13); }
.att-mark.att-late { background-color: rgba(255, 193, 7, 0.22); }
.att-mark.att-absent { background-color: rgba(220, 53, 69, 0.13); }
.att-mark.att-excused { background-color: rgba(108, 117, 125, 0.13); }

/*
    Hatching rather than a flat grey. "No register was taken" and "the register
    was taken and this child has no mark" are different facts about a school's
    paperwork, and two shades of grey are not enough of a difference to survive
    a printout or a colour-blind reader — the texture is.
*/
/* Faint on screen, where the hatch already carries the meaning; it is the print
   path that needs the ink. */
.att-hatch-glyph {
    color: rgba(0, 0, 0, 0.28);
}

.att-no-register {
    background-image: repeating-linear-gradient(
        45deg,
        rgba(0, 0, 0, 0.07) 0,
        rgba(0, 0, 0, 0.07) 3px,
        transparent 3px,
        transparent 7px
    );
}

.att-unmarked {
    color: #adb5bd;
}

.att-count {
    margin-right: 0.4rem;
    font-weight: 600;
}

.att-legend {
    margin-bottom: 0;
}

.att-swatch {
    display: inline-block;
    width: 1.5rem;
    height: 1.1rem;
    margin-right: 0.15rem;
    border: 1px solid #dee2e6;
    border-radius: 0.15rem;
    text-align: center;
    line-height: 1;
    font-weight: 600;
    font-size: 0.75rem;
    vertical-align: middle;
}

@media print {
    /* The controls are how the window was chosen; the sentence above the grid
       already says which window it was, in words. */
    .no-print {
        display: none !important;
    }

    /* A scroll box prints as whatever happened to be scrolled into view, which
       on this screen is a register missing half its days. */
    .att-grid-scroll {
        overflow: visible;
    }

    .att-name-col {
        position: static;
    }
}
</style>
