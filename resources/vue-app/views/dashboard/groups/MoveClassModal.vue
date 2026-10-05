<template>
    <div ref="root" class="modal fade show d-block move-class" tabindex="-1" style="background:rgba(0,0,0,.5)"
         role="dialog" aria-modal="true" aria-labelledby="move-class-title"
         @click.self="cancel">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="move-class-title" class="modal-title">
                        <i class="bi bi-arrow-right-circle me-2"></i> Move this class
                    </h5>
                    <button ref="closeButton" type="button" class="btn-close" aria-label="Close" :disabled="saving" @click="cancel"></button>
                </div>

                <!-- AFTER THE RUN: every student by name, in the server's words. It
                     stays until the office taps OK, because it carries things to do. -->
                <template v-if="result">
                    <div class="modal-body" aria-live="polite">
                        <h6 id="move-class-result" ref="resultHeading" tabindex="-1" class="fw-semibold mb-2">What happened</h6>
                        <ul class="mb-3 ps-3" data-part="done">
                            <li v-for="(line, i) in result.lines.done" :key="i" class="mb-1">{{ line }}</li>
                        </ul>

                        <template v-if="result.lines.consent.length">
                            <div class="fw-semibold mb-1">Parents and consent</div>
                            <ul class="mb-3 ps-3" data-part="consent">
                                <li v-for="(line, i) in result.lines.consent" :key="i" class="mb-1">{{ line }}</li>
                            </ul>
                        </template>

                        <template v-if="result.lines.bucks.length">
                            <div class="fw-semibold mb-1">Manara Bucks</div>
                            <ul class="mb-3 ps-3" data-part="bucks">
                                <li v-for="(line, i) in result.lines.bucks" :key="i" class="mb-1">{{ line }}</li>
                            </ul>
                        </template>

                        <!-- Open here: this is where the office can act on them. -->
                        <template v-if="result.lines.afterwards.length">
                            <div class="fw-semibold mb-1">Afterwards, check</div>
                            <ul class="mb-3 ps-3" data-part="afterwards">
                                <li v-for="(line, i) in result.lines.afterwards" :key="i" class="mb-1">{{ line }}</li>
                            </ul>
                        </template>

                        <div class="fw-semibold mb-1">Each student</div>
                        <ul class="list-unstyled mb-0" data-part="outcomes">
                            <li v-for="student in result.students" :key="student.membership_id" class="border-top py-2">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-semibold">{{ student.name ?? 'A student' }}</span>
                                    <span class="badge" :class="outcomeBadge(student.outcome)">{{ outcomeWord(student.outcome) }}</span>
                                </div>
                                <template v-if="student.outcome === 'not_moved'">
                                    <div v-for="(line, i) in (student.reason ?? '').split('\n')" :key="i" class="small">{{ line }}</div>
                                    <button v-if="student.open_group" type="button" class="btn btn-sm btn-outline-secondary mt-1"
                                            @click="openClass(student.open_group)">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Open {{ student.open_group.name }}
                                    </button>
                                </template>
                                <template v-else-if="student.outcome === 'moved' && student.lines?.length">
                                    <button type="button" class="btn btn-sm btn-link px-0"
                                            :aria-expanded="details.includes(student.membership_id)" @click="toggleDetails(student.membership_id)">
                                        <i class="bi me-1" :class="details.includes(student.membership_id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i> Details
                                    </button>
                                    <ul v-if="details.includes(student.membership_id)" class="small mb-0 ps-3">
                                        <li v-for="(line, i) in student.lines" :key="i" class="mb-1">{{ line }}</li>
                                    </ul>
                                </template>
                            </li>
                        </ul>
                    </div>
                    <div class="modal-footer flex-column flex-sm-row align-items-stretch align-items-sm-center">
                        <button v-if="canMoveTheRest" type="button" class="btn btn-outline-primary" @click="moveTheRest">
                            <i class="bi bi-arrow-repeat me-1"></i> Move the rest
                        </button>
                        <button ref="okButton" type="button" class="btn btn-primary" @click="finish">OK</button>
                    </div>
                </template>

                <!-- THE ANSWER NEVER ARRIVED. Nothing is assumed about what happened,
                     and the same request is not sent again. -->
                <template v-else-if="lost">
                    <div class="modal-body" aria-live="polite">
                        <div class="alert alert-warning mb-0" role="alert">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            The answer did not arrive. Some students may have been moved, and the move may still be running.
                            Wait a minute, then close this and reload the roster: anyone not marked 'Moved to {{ chosenName }}'
                            was not moved and can be moved again.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" @click="reloadRoster">
                            <i class="bi bi-arrow-clockwise me-1"></i> Close and reload the roster
                        </button>
                    </div>
                </template>

                <form v-else @submit.prevent="save">
                    <div class="modal-body">
                        <p class="mb-3">
                            <span class="fw-semibold">{{ groupName }}</span> has {{ currentStudents }}
                            current {{ currentStudents === 1 ? 'student' : 'students' }}.
                        </p>

                        <div class="mb-3">
                            <label class="form-label" for="move-class-to">Move to</label>
                            <div v-if="classesState === 'loading'" class="form-text">Loading classes…</div>
                            <div v-else-if="classesState === 'failed'" class="text-danger small" role="alert">
                                <i class="bi bi-exclamation-triangle me-1"></i> Could not load the classes.
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" @click="loadClasses">Try again</button>
                            </div>
                            <div v-else-if="options.length === 0" class="form-text">
                                This school has no other class that is running. Add the class first, on the Classes page.
                            </div>
                            <select v-else id="move-class-to" class="form-select" v-model="form.toGroupId" :disabled="saving">
                                <option :value="null" disabled>Choose a class</option>
                                <option v-for="option in options" :key="option.id" :value="option.id">{{ option.name }}</option>
                            </select>
                            <div v-for="(note, i) in notOffered" :key="i" class="form-text" data-part="not-offered">{{ note }}</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="move-class-first-day">First day in the new class</label>
                            <input id="move-class-first-day" type="date" class="form-control" :max="schoolToday"
                                   v-model="form.movedOn" :disabled="saving">
                        </div>

                        <fieldset class="mb-3">
                            <legend class="form-label fs-6">Grades</legend>
                            <div class="form-check d-flex align-items-center gap-2">
                                <input id="move-class-grade-keep" class="form-check-input mt-0" type="radio" name="move-class-grades" value="keep"
                                       :checked="form.gradeMode === 'keep'" :disabled="saving" @change="chooseGrades('keep')">
                                <label class="form-check-label" for="move-class-grade-keep">
                                    Keep each student's grade. A student going back to a place they held before gets the
                                    grade recorded on that place.
                                </label>
                            </div>
                            <div class="form-check d-flex align-items-center gap-2">
                                <input id="move-class-grade-up" class="form-check-input mt-0" type="radio" name="move-class-grades" value="up"
                                       :checked="form.gradeMode === 'up'" :disabled="saving" @change="chooseGrades('up')">
                                <label class="form-check-label" for="move-class-grade-up">Move each grade up one</label>
                            </div>
                            <div class="form-check d-flex flex-wrap align-items-center gap-2">
                                <input id="move-class-grade-set" class="form-check-input mt-0" type="radio" name="move-class-grades" value="set"
                                       :checked="form.gradeMode === 'set'" :disabled="saving" @change="chooseGrades('set')">
                                <label class="form-check-label" for="move-class-grade-set">Give everyone this grade:</label>
                                <input id="move-class-grade-label" type="text" class="form-control form-control-sm w-auto" maxlength="32"
                                       aria-label="The grade to give everyone" v-model="form.gradeLabel"
                                       :disabled="saving || form.gradeMode !== 'set'" @blur="gradeTextDone">
                            </div>
                        </fieldset>

                        <div v-if="form.toGroupId" aria-live="polite">
                            <div v-if="checkState === 'idle'" class="text-muted">Choose the first day in the new class.</div>

                            <div v-else-if="checkState === 'checking'" class="text-muted">Checking…</div>

                            <div v-else-if="checkState === 'failed'" class="text-danger" role="alert">
                                <i class="bi bi-exclamation-triangle me-1"></i> {{ checkError }}
                                <button v-if="checkAction === 'retry'" type="button"
                                        class="btn btn-sm btn-outline-secondary ms-2" @click="check">Try again</button>
                                <button v-else-if="checkAction === 'reload'" type="button"
                                        class="btn btn-sm btn-outline-secondary ms-2" @click="reloadRoster">Reload the roster</button>
                            </div>

                            <!-- A refusal about the class as a whole: one sentence, no list. -->
                            <div v-else-if="preview && !preview.can_move" class="text-danger" role="alert">
                                <div v-for="(line, i) in (preview.refusal ?? '').split('\n')" :key="i" class="mb-1">
                                    <i v-if="i === 0" class="bi bi-x-octagon me-1"></i>{{ line }}
                                </div>
                            </div>

                            <template v-else-if="preview">
                                <!-- WHO: the class as a list of children, each with a tick,
                                     before anything else the office has to read. -->
                                <section data-part="who" class="mb-3">
                                    <div class="fw-semibold mb-1">Who</div>
                                    <ul class="mb-2 ps-3">
                                        <li v-for="(line, i) in preview.lines.who" :key="i" class="mb-1">{{ line }}</li>
                                    </ul>

                                    <div v-if="preview.students.length" class="d-flex flex-wrap gap-2 mb-2">
                                        <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="tickAll">
                                            <i class="bi bi-check2-square me-1"></i> All who can move ({{ ableIds.length }})
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="tickNone">
                                            <i class="bi bi-square me-1"></i> None
                                        </button>
                                        <button v-if="cameIds.length" type="button" class="btn btn-outline-secondary" :disabled="saving" @click="tickCame">
                                            <i class="bi bi-arrow-return-left me-1"></i>
                                            Only the {{ cameIds.length }} {{ cameIds.length === 1 ? 'student' : 'students' }} who came from {{ chosenName }}
                                        </button>
                                    </div>

                                    <div v-if="tickNote" class="small text-muted mb-2" data-part="tick-note">{{ tickNote }}</div>
                                    <div v-if="tickedIds.length > preview.limits.max_students" class="small text-muted mb-2" data-part="too-many">
                                        Move up to {{ preview.limits.max_students }} at a time. The rest stay on this list for the next round.
                                    </div>

                                    <ul class="list-unstyled mb-0" data-part="students">
                                        <li v-for="student in preview.students" :key="student.membership_id" class="border-top py-2">
                                            <div class="form-check d-flex align-items-center gap-2">
                                                <input :id="`move-class-student-${student.membership_id}`" class="form-check-input mt-0" type="checkbox"
                                                       :checked="tickedIds.includes(student.membership_id)"
                                                       :disabled="!student.can_move || saving"
                                                       :aria-describedby="student.can_move ? undefined : `move-class-why-${student.membership_id}`"
                                                       @change="toggle(student.membership_id, $event)">
                                                <label class="form-check-label fw-semibold" :for="`move-class-student-${student.membership_id}`">
                                                    {{ student.name ?? 'A student' }}
                                                </label>
                                                <span v-if="changedIds.includes(student.membership_id)" class="badge bg-warning text-dark">Changed</span>
                                            </div>

                                            <div v-if="gradeChange(student)" class="small" data-part="grade">Grade: {{ gradeChange(student) }}</div>
                                            <div v-if="student.grade_note" class="small text-muted">{{ student.grade_note }}</div>

                                            <template v-if="student.can_move">
                                                <div class="small">{{ student.summary }}</div>
                                                <button type="button" class="btn btn-sm btn-link px-0"
                                                        :aria-expanded="details.includes(student.membership_id)" @click="toggleDetails(student.membership_id)">
                                                    <i class="bi me-1" :class="details.includes(student.membership_id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i> Details
                                                </button>
                                                <ul v-if="details.includes(student.membership_id)" class="small mb-0 ps-3">
                                                    <li v-for="(line, i) in student.lines" :key="i" class="mb-1">{{ line }}</li>
                                                </ul>
                                            </template>
                                            <!-- Cannot move: the reason as ordinary text, never a tooltip. -->
                                            <div v-else :id="`move-class-why-${student.membership_id}`" class="small text-danger">
                                                <div v-for="(line, i) in (student.refusal ?? '').split('\n')" :key="i">{{ line }}</div>
                                                <button v-if="student.open_group" type="button" class="btn btn-sm btn-outline-secondary mt-1"
                                                        @click="openClass(student.open_group)">
                                                    <i class="bi bi-box-arrow-up-right me-1"></i> Open {{ student.open_group.name }}
                                                </button>
                                            </div>
                                        </li>
                                    </ul>
                                </section>

                                <!-- WHAT FOLLOWS THEM, in three short groups. -->
                                <section data-part="follows" class="mb-3">
                                    <div v-if="ableIds.length > tickedIds.length && tickedIds.length > 0" class="small text-muted mb-2" data-part="counted">
                                        The lines below count all {{ ableIds.length }} students who can move.
                                        {{ ableIds.length - tickedIds.length }} of them {{ ableIds.length - tickedIds.length === 1 ? 'is' : 'are' }} not ticked.
                                    </div>
                                    <template v-for="group in groups" :key="group.key">
                                        <div v-if="preview.lines[group.key].length" class="border-top py-1">
                                            <button type="button" class="btn btn-link px-0 fw-semibold text-start"
                                                    :aria-expanded="opened[group.key]" @click="opened[group.key] = !opened[group.key]">
                                                <i class="bi me-1" :class="opened[group.key] ? 'bi-chevron-up' : 'bi-chevron-down'"></i> {{ group.title }}
                                            </button>
                                            <ul v-if="opened[group.key]" class="mb-2 ps-3">
                                                <li v-for="(line, i) in preview.lines[group.key]" :key="i" class="mb-1">{{ line }}</li>
                                            </ul>
                                        </div>
                                    </template>
                                </section>

                                <!-- AFTERWARDS, CHECK: one disclosure, closed. None of it is a
                                     consequence to weigh before the tap, except a report card
                                     that cannot be started afterwards, which is counted beside it. -->
                                <section v-if="preview.lines.afterwards.length" data-part="afterwards" class="border-top py-1">
                                    <button type="button" class="btn btn-link px-0 fw-semibold text-start"
                                            :aria-expanded="opened.afterwards" @click="opened.afterwards = !opened.afterwards">
                                        <i class="bi me-1" :class="opened.afterwards ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                        Afterwards, check ({{ preview.lines.afterwards.length }})
                                    </button>
                                    <span v-if="preview.counts.report_cards_not_started > 0" class="badge bg-warning text-dark ms-2">
                                        {{ preview.counts.report_cards_not_started }}
                                        {{ preview.counts.report_cards_not_started === 1 ? 'report card' : 'report cards' }} not started
                                    </span>
                                    <ul v-if="opened.afterwards" class="mb-2 ps-3">
                                        <li v-for="(line, i) in preview.lines.afterwards" :key="i" class="mb-1">{{ line }}</li>
                                    </ul>
                                </section>
                            </template>
                        </div>

                        <!-- A refusal from the run, before anything was written: shown
                             here, and the check above runs again with the ticks kept. -->
                        <div v-if="saveError" class="alert alert-danger mt-3 mb-0" role="alert">
                            <i class="bi bi-x-octagon me-1"></i>
                            <span v-for="(line, i) in saveError.split('\n')" :key="i" class="d-block">{{ line }}</span>
                            <button v-if="saveOpenGroup" type="button" class="btn btn-sm btn-outline-danger mt-2"
                                    @click="openClass(saveOpenGroup)">
                                Open {{ saveOpenGroup.name }}
                            </button>
                        </div>

                        <p class="small text-muted mt-3 mb-0">Nothing is saved until you press Move.</p>
                    </div>
                    <div class="modal-footer flex-column flex-sm-row align-items-stretch align-items-sm-center">
                        <span class="small me-sm-auto" :class="saving ? 'fw-semibold' : 'text-muted'" aria-live="polite" data-part="why">
                            {{ saving ? `Moving ${sending.length} ${sending.length === 1 ? 'student' : 'students'}. Keep this page open.` : notYet }}
                        </span>
                        <button type="button" class="btn btn-secondary" :disabled="saving" @click="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary" :disabled="!canMove">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ sending.length ? moveButtonLabel(sending.length) : 'Move' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * MOVE A WHOLE CLASS: the office's dialog.
 *
 * One check and one request. Choosing a class, a day or what happens to grades asks the server
 * what the move would do, and the dialog draws the class as a list of students, each with a tick,
 * then the server's own sentences about what follows them. Move sends the ticked rows, each with
 * what the list showed for it; the answer names every student, and stays until OK.
 *
 * THE BODY IS IN THE ORDER IT IS ACTED ON: the choices, then WHO (the list), then what follows
 * them in three short groups, then one closed "Afterwards, check". On a phone the button is in
 * reach before anything below the fold is read, so the list comes before the long text.
 *
 * THE TICKS ARE THE OFFICE'S, kept by roster row id across every re-check and every refusal
 * (core/helpers/rosterClassMove.ts). Only the first check for a class ticks everyone who can move.
 *
 * THE BUTTON IS OFF, AND THE HANDLER REFUSES TOO: while the check runs, after a refusal, with no
 * choice about grades, with nobody ticked, and from the tap until the answer. A second tap that
 * lands before the button is redrawn sends nothing. Beside it, in words, why.
 *
 * WHEN THE ANSWER DOES NOT ARRIVE nothing is assumed about what happened, and the same body is
 * never sent again: the dialog says to wait and reload the roster, which shows who was moved.
 *
 * No Teleport in here. The roster tab wraps this in one; mounted on its own it can be driven by
 * the suite (tests/roster-class-move-mounted.test.ts).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import type { GroupMembership } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { trapTab } from '@/core/helpers/focusTrap';
import { classOptions } from '@/core/helpers/rosterMove';
import {
    classMoveFields, everyoneWhoCanMove, gradeChange, moveButtonLabel, movedFromClassesNotOffered, refusalOf, someWereLeft,
    theRest, ticksAfterCheck, toSend, whoCameFromTarget, whyNotYet,
} from '@/core/helpers/rosterClassMove';
import type {
    ClassMoveAnswer, ClassMoveOutcome, ClassMovePreview, GradeMode, OpenGroup,
} from '@/core/helpers/rosterClassMove';

const props = defineProps<{
    groupId: number;
    groupName: string;
    /** This class's roster rows as the page drew them: for the count, and for "moved from a class not in this list". */
    roster: GroupMembership[];
    /** Today on the school's clock: the date field's default and its latest day. */
    schoolToday: string;
}>();

const emit = defineEmits<{
    (event: 'close'): void;
    /** Students were moved and the office has read what happened: the roster is out of date. */
    (event: 'moved'): void;
    /** The roster this dialog was opened from is out of date. */
    (event: 'reload'): void;
    /** `membershipId` is the roster row in that class the refusal is about, when it names one. */
    (event: 'open-class', groupId: number, membershipId?: number): void;
}>();

const groupsStore = useGroupsStore();

const currentStudents = computed(() => props.roster.filter((r) => r.role === 'member' && !r.left_on).length);

const form = ref<{ toGroupId: number | null; movedOn: string; gradeMode: GradeMode | null; gradeLabel: string }>({
    toGroupId: null,
    movedOn: props.schoolToday,
    // None is chosen for the office: Move waits for the choice.
    gradeMode: null,
    gradeLabel: '',
});

// ------------------------------------------------------------- the class list

const classes = ref<any[]>([]);
const classesState = ref<'loading' | 'failed' | 'ready'>('loading');

const options = computed(() => classOptions(classes.value, props.groupId, form.value.movedOn));
const chosenName = computed(() => options.value.find((o) => o.id === form.value.toGroupId)?.name ?? 'the new class');
const notOffered = computed(() => classesState.value === 'ready' ? movedFromClassesNotOffered(props.roster, options.value) : []);

const loadClasses = async () => {
    classesState.value = 'loading';
    try {
        classes.value = await groupsStore.fetchClassesForMove();
        classesState.value = 'ready';
    } catch {
        classesState.value = 'failed';
    }
};

// ------------------------------------------------------------------- the check

const preview = ref<ClassMovePreview | null>(null);
const checkState = ref<'idle' | 'checking' | 'failed' | 'ready'>('idle');
const checkError = ref('');
const checkAction = ref<'retry' | 'reload' | null>(null);
let asked = 0;

/** The ticked roster row ids. Null until the first check for the chosen class has answered. */
const ticked = ref<number[] | null>(null);
const tickedIds = computed(() => ticked.value ?? []);
const tickNote = ref('');
/** The last answer, while "Move the rest" waits for its fresh check to tick from it. */
let restOf: ClassMoveAnswer | null = null;
/** A class other than the first one was chosen: the next check says the list was ticked again. */
let reticked = false;

const ableIds = computed(() => everyoneWhoCanMove(preview.value?.students ?? []));
const cameIds = computed(() => whoCameFromTarget(preview.value?.students ?? []));
const sending = computed(() => preview.value
    ? toSend(tickedIds.value, preview.value.students, preview.value.limits.max_students)
    : []);

const check = async () => {
    cancelGradeText();

    const toGroupId = form.value.toGroupId;
    if (!toGroupId || !form.value.movedOn) {
        asked += 1;
        preview.value = null;
        checkState.value = 'idle';
        return;
    }

    const mine = ++asked;
    checkState.value = 'checking';
    preview.value = null;
    tickNote.value = '';

    try {
        const answer = await groupsStore.previewClassMove(
            props.groupId, toGroupId, form.value.movedOn, form.value.gradeMode, form.value.gradeLabel.trim(),
        );
        if (mine !== asked) return;   // a newer choice has been made since

        if (restOf) {
            // "Move the rest": only those students, and only those of them that can still move.
            ticked.value = theRest(restOf, answer.students);
            const n = ticked.value.length;
            tickNote.value = n === 0
                ? 'None of the students who were not reached or were busy can be moved now. Each row says why.'
                : `Only the ${n} ${n === 1 ? 'student' : 'students'} who ${n === 1 ? 'was' : 'were'} not reached or ${n === 1 ? 'was' : 'were'} busy ${n === 1 ? 'is' : 'are'} ticked.`;
            restOf = null;
        } else {
            const first = ticked.value === null;
            ticked.value = ticksAfterCheck(ticked.value, answer.students);
            if (first && reticked) tickNote.value = `The list was ticked again for ${answer.to_group?.name ?? chosenName.value}.`;
        }
        reticked = false;

        preview.value = answer;
        checkState.value = 'ready';
    } catch (error: any) {
        if (mine !== asked) return;

        const status = error?.response?.status;
        checkState.value = 'failed';
        if (status === 404) {
            checkError.value = 'This roster has changed. Reload it.';
            checkAction.value = 'reload';
        } else if (status === 403) {
            checkError.value = 'You do not have permission to move students.';
            checkAction.value = null;
        } else {
            checkError.value = 'Could not check this move. Try again.';
            checkAction.value = 'retry';
        }
    }
};

/** A choice was made: a refusal from the last request was about the choices made THEN. */
const choiceChanged = () => {
    saveError.value = '';
    saveOpenGroup.value = null;
    changedIds.value = [];
    check();
};

// Another class starts again from "everyone who can move", and the dialog says so.
watch(() => form.value.toGroupId, (_now, before) => {
    reticked = before !== null && ticked.value !== null;
    ticked.value = null;
    restOf = null;
    choiceChanged();
});

watch(() => form.value.movedOn, choiceChanged);

// A class that has ended by the chosen day drops off the list; so does the choice.
watch(options, (list) => {
    if (form.value.toGroupId !== null && !list.some((o) => o.id === form.value.toGroupId)) {
        form.value.toGroupId = null;
    }
});

const chooseGrades = (mode: GradeMode) => {
    if (form.value.gradeMode === mode) return;

    form.value.gradeMode = mode;
    choiceChanged();
};

// TYPING IN THE GRADE FIELD DOES NOT ASK ON EACH KEYSTROKE (one check reads every student): it
// asks when the field loses focus, or after a pause of half a second.
let gradeText: ReturnType<typeof setTimeout> | null = null;

const cancelGradeText = () => {
    if (gradeText !== null) clearTimeout(gradeText);
    gradeText = null;
};

watch(() => form.value.gradeLabel, () => {
    if (form.value.gradeMode !== 'set') return;

    cancelGradeText();
    // Off at once: the list on screen is for the grade typed before.
    checkState.value = form.value.toGroupId && form.value.movedOn ? 'checking' : 'idle';
    asked += 1;
    gradeText = setTimeout(choiceChanged, 500);
});

const gradeTextDone = () => {
    if (gradeText === null) return;

    choiceChanged();
};

// ------------------------------------------------------------------- the ticks

const toggle = (id: number, event: Event) => {
    if (saving.value || !ableIds.value.includes(id)) return;

    const on = (event.target as HTMLInputElement).checked;
    const rest = tickedIds.value.filter((t) => t !== id);
    ticked.value = on ? [...rest, id] : rest;
    tickNote.value = '';
};

const tickAll = () => { ticked.value = ableIds.value; tickNote.value = ''; };
const tickNone = () => { ticked.value = []; tickNote.value = ''; };
const tickCame = () => { ticked.value = cameIds.value; tickNote.value = ''; };

// --------------------------------------------- the three groups, and the details

const groups: Array<{ key: 'consent' | 'records' | 'bucks'; title: string }> = [
    { key: 'consent', title: 'Parents and consent' },
    { key: 'records', title: 'Records and the register' },
    { key: 'bucks', title: 'Manara Bucks' },
];

// The first group is open; on a wide screen the others are too.
const wide = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    && window.matchMedia('(min-width: 768px)').matches;
const opened = reactive<{ consent: boolean; records: boolean; bucks: boolean; afterwards: boolean }>({
    consent: true, records: wide, bucks: wide, afterwards: false,
});

const details = ref<number[]>([]);
const toggleDetails = (id: number) => {
    details.value = details.value.includes(id) ? details.value.filter((d) => d !== id) : [...details.value, id];
};

// --------------------------------------------------------------------- the run

const saving = ref(false);
const saveError = ref('');
const saveOpenGroup = ref<OpenGroup | null>(null);
/** Students the server said had changed since the list was drawn: marked in the list. */
const changedIds = ref<number[]>([]);
const result = ref<ClassMoveAnswer | null>(null);
const lost = ref(false);
/** A run of this dialog moved somebody: the page's roster is out of date however the dialog closes. */
let movedSomebody = false;

const notYet = computed(() => whyNotYet({
    saving: saving.value,
    toGroupId: form.value.toGroupId,
    movedOn: form.value.movedOn,
    check: checkState.value,
    canMove: preview.value?.can_move === true,
    gradeMode: form.value.gradeMode,
    gradeLabel: form.value.gradeLabel,
    ticked: sending.value.length,
}));

const canMove = computed(() => notYet.value === '');
const canMoveTheRest = computed(() => result.value !== null && someWereLeft(result.value));

const save = async () => {
    // The guard, not only the disabled button: a second tap before the redraw sends nothing.
    if (!canMove.value || !preview.value || form.value.toGroupId === null || form.value.gradeMode === null) return;

    saving.value = true;
    saveError.value = '';
    saveOpenGroup.value = null;
    changedIds.value = [];

    try {
        const answer = await groupsStore.moveClass(props.groupId, classMoveFields({
            toGroupId: form.value.toGroupId,
            movedOn: form.value.movedOn,
            gradeMode: form.value.gradeMode,
            gradeLabel: form.value.gradeLabel,
        }, preview.value, sending.value));

        movedSomebody = movedSomebody || answer.moved > 0;
        details.value = [];
        result.value = answer;
    } catch (error: any) {
        const refusal = refusalOf(error);

        if (!refusal.arrived) {
            // Some students may have been moved. The roster is the only thing that knows.
            movedSomebody = true;
            lost.value = true;
            return;
        }

        // Refused before anything was written. Shown in the dialog, which stays
        // open; the ticks are kept and the check runs again by itself, so what
        // the office reads next is what the server would do now.
        saveError.value = refusal.message;
        saveOpenGroup.value = refusal.openGroup;
        changedIds.value = refusal.changed;
        saving.value = false;
        await check();
    } finally {
        saving.value = false;
    }
};

/** A fresh check that ticks only the students who were not reached or were busy. Nothing is sent. */
const moveTheRest = async () => {
    if (!result.value) return;

    restOf = result.value;
    result.value = null;
    saveError.value = '';
    saveOpenGroup.value = null;
    changedIds.value = [];
    await check();
    await nextTick();
    if (open) document.getElementById('move-class-to')?.focus();
};

const outcomeWord = (outcome: ClassMoveOutcome): string =>
    ({ moved: 'Moved', not_moved: 'Not moved', not_reached: 'Not reached' })[outcome];
const outcomeBadge = (outcome: ClassMoveOutcome): string =>
    ({ moved: 'bg-success', not_moved: 'bg-danger', not_reached: 'bg-secondary' })[outcome];

const cancel = () => {
    if (saving.value) return;

    if (result.value || movedSomebody) {
        finish();
    } else {
        emit('close');
    }
};

const finish = () => emit('moved');
const reloadRoster = () => emit('reload');
const openClass = (group: OpenGroup | null | undefined) => {
    if (group) emit('open-class', group.id, group.membership_id);
};

// THE KEYBOARD COMES INTO THE DIALOG AND STAYS IN IT, as in the single-student dialog: this is
// teleported to <body>, so Escape and Tab are heard on the DOCUMENT, and focus is put on
// something inside at every point where the focused control goes away.
const root = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const okButton = ref<HTMLButtonElement | null>(null);
const resultHeading = ref<HTMLElement | null>(null);
let opener: HTMLElement | null = null;
let open = true;

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        cancel();   // Cancel before the run, OK after it; neither while it saves
        return;
    }

    trapTab(event, root.value);
};

// The result has replaced the form: focus goes to its heading, so it is read from the top.
watch(result, async (answer) => {
    if (!answer) return;

    await nextTick();
    resultHeading.value?.focus();
});

onMounted(async () => {
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.addEventListener('keydown', onKeydown);
    // Inside from the first frame, while the classes load.
    closeButton.value?.focus();

    await loadClasses();
    await nextTick();
    // The class picker when there is one; otherwise focus stays on Close.
    if (open) document.getElementById('move-class-to')?.focus();
});

onBeforeUnmount(() => {
    open = false;
    cancelGradeText();
    document.removeEventListener('keydown', onKeydown);
    // Back to "Move the class", which is still on the page.
    if (opener && document.contains(opener)) opener.focus();
});
</script>

<style scoped>
/* Every control is a full touch target, on a tablet as on a phone. */
.move-class .btn,
.move-class .form-check,
.move-class .form-select,
.move-class .form-control {
    min-height: 44px;
}

/*
 * The form stands between the dialog's box and its body and footer. Unless it is itself a
 * column that may shrink, the body never scrolls and the box cuts off everything below the
 * fold, the Move button with it: on a phone the office could not reach it.
 */
.move-class form {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
}

/* A long class name wraps inside its button instead of pushing the dialog sideways. */
.move-class .btn {
    white-space: normal;
}
</style>
