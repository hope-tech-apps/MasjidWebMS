<template>
    <section>
        <p class="text-muted small mb-1">
            Manara Bucks: each week's positive points turn into Bucks, and students spend them here.
            <span v-if="settings">One point makes {{ pointsPerBuckText }}.</span>
            Negative points never take Bucks away.
        </p>
        <p class="text-muted small">
            Bucks are worked out once the week is over, so this week's points show up next week. A prize you give is a
            record you can undo, and undoing it writes a new line rather than erasing the old one.
        </p>

        <div v-if="loading" class="text-center py-4"><span class="spinner-border spinner-border-sm text-success"></span></div>
        <div v-else-if="error" class="alert alert-danger py-2 small">
            {{ error }}
            <button class="btn btn-sm btn-outline-danger ms-2" @click="load">Retry</button>
        </div>

        <template v-else>
            <div class="row g-4">
                <!-- ROSTER ORDER. Never sorted by Bucks and never totalled: this is the
                     teacher's overview, not a leaderboard (.claude/rules/groups.md). -->
                <div class="col-lg-5">
                    <h2 class="h6 text-uppercase text-muted small">Students</h2>
                    <p v-if="!students.length" class="text-muted small">No students in this class yet.</p>
                    <!-- Real buttons, so a keyboard reaches every student (Tab, then Enter or Space). -->
                    <div class="list-group">
                        <button v-for="s in students" :key="s.membership_id" type="button"
                                class="list-group-item list-group-item-action d-flex align-items-center gap-2 text-start"
                                :class="{ active: selectedId === s.membership_id }"
                                :aria-pressed="selectedId === s.membership_id ? 'true' : 'false'"
                                @click="select(s.membership_id)">
                            <PersonAvatar :avatar="s.contact?.avatar" :first-name="s.contact?.first_name"
                                          :last-name="s.contact?.last_name" :size="32" />
                            <span class="flex-grow-1">{{ name(s.contact) }}</span>
                            <span class="fw-semibold">{{ bucksLabel(s.balance) }}</span>
                        </button>
                    </div>
                </div>

                <div class="col-lg-7">
                    <template v-if="selected">
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <h2 class="h6 mb-0">{{ name(selected.contact) }}</h2>
                            <span class="fw-semibold">{{ balance === null ? 'Balance unavailable' : bucksLabel(balance) }}</span>
                        </div>

                        <h3 class="text-uppercase text-muted small">Give a prize</h3>
                        <p v-if="!offered.length" class="text-muted small">There are no prizes yet. Add one below.</p>
                        <ul class="list-unstyled mb-3">
                            <li v-for="p in offered" :key="p.id" class="d-flex align-items-center gap-2 py-1 border-bottom">
                                <span class="flex-grow-1">
                                    <span class="fw-semibold" dir="auto">{{ p.title }}</span>
                                    <span class="badge bg-light text-dark border ms-1">{{ p.scope === 'school' ? 'School-wide' : 'This class' }}</span>
                                    <span class="text-muted small d-block">
                                        {{ bucksLabel(p.cost_bucks) }} · {{ stockNote(p) }}<span v-if="p.why"> · {{ p.why }}</span>
                                    </span>
                                </span>
                                <button class="btn btn-sm btn-success" :disabled="!p.available || busy"
                                        @click="give(p)">Give</button>
                            </li>
                        </ul>
                        <p v-if="giveError" class="text-danger small">{{ giveError }}</p>
                        <p v-else-if="giveNote" class="text-muted small">{{ giveNote }}</p>

                        <template v-if="settings?.paper_bucks_enabled">
                            <h3 class="text-uppercase text-muted small">Pay out on paper</h3>
                            <form class="d-flex gap-2 align-items-center mb-3" @submit.prevent="cashOut">
                                <input v-model="cashAmount" type="number" min="1" step="1" class="form-control form-control-sm"
                                       style="max-width: 8rem" placeholder="Bucks" aria-label="Bucks to pay out">
                                <button class="btn btn-sm btn-outline-success" :disabled="busy || !cashAmountOk">Pay out</button>
                                <span v-if="cashAmountOk" class="text-muted small">{{ breakdownLine(previewNotes) }}</span>
                            </form>
                        </template>

                        <h3 class="text-uppercase text-muted small">History</h3>
                        <p v-if="historyError" class="text-danger small">{{ historyError }}</p>
                        <p v-else-if="!history.length" class="text-muted small">Nothing yet.</p>
                        <ul v-else class="list-unstyled mb-0">
                            <li v-for="e in history" :key="e.id" class="d-flex gap-2 align-items-baseline py-1 border-bottom">
                                <span class="badge" :class="e.amount < 0 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                    {{ signedBucks(e.amount) }}
                                </span>
                                <span class="small flex-grow-1" dir="auto">
                                    {{ entryText(e) }}
                                    <span class="text-muted">· {{ when(e.occurred_at) }}</span>
                                    <span v-if="e.is_reversed" class="badge bg-secondary-subtle text-secondary ms-1">Undone</span>
                                    <span v-if="e.note" class="text-muted d-block">{{ e.note }}</span>
                                </span>
                                <button v-if="e.reversible" class="btn btn-sm btn-link text-danger p-0"
                                        :disabled="busy" @click="undo(e)">Undo</button>
                            </li>
                        </ul>
                        <!-- Older lines, 25 at a time, as the family screen pages them: an older prize
                             can still be undone from here. -->
                        <button v-if="historyHasMore" type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                                :disabled="historyMoreBusy" @click="loadEarlier">Show earlier</button>
                        <p v-if="historyMoreError" class="text-danger small mb-0">{{ historyMoreError }}</p>
                    </template>
                    <p v-else class="text-muted small mt-4">Choose a student to give a prize or see their history.</p>
                </div>
            </div>

            <!-- THE SHELF: the school-wide list is the office's; this class's own prizes are yours. -->
            <h2 class="h6 text-uppercase text-muted small mt-4">Prizes</h2>
            <ul class="list-unstyled">
                <li v-for="p in prizes" :key="p.id" class="d-flex align-items-center gap-2 py-1 border-bottom"
                    :class="{ 'text-muted': !p.is_active }">
                    <span class="flex-grow-1">
                        <span dir="auto">{{ p.title }}</span>
                        <span class="badge bg-light text-dark border ms-1">{{ p.scope === 'school' ? 'School-wide' : 'This class' }}</span>
                        <span v-if="!p.is_active" class="badge bg-secondary-subtle text-secondary ms-1">Retired</span>
                        <span class="small d-block">{{ bucksLabel(p.cost_bucks) }} · {{ stockNote(p) }}</span>
                    </span>
                    <template v-if="p.editable">
                        <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="editPrize(p)">Edit</button>
                        <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="toggleActive(p)">
                            {{ p.is_active ? 'Retire' : 'Bring back' }}
                        </button>
                    </template>
                    <span v-else class="small text-muted">Set by the office</span>
                </li>
            </ul>

            <form class="card card-body border-0 bg-light mb-3" @submit.prevent="savePrize">
                <h3 class="h6">{{ editingId ? 'Edit this prize' : 'Add a prize for this class' }}</h3>
                <div class="row g-2">
                    <div class="col-sm-6">
                        <label class="form-label small text-muted mb-0" for="cs-title">Name</label>
                        <input id="cs-title" v-model="form.title" class="form-control form-control-sm" maxlength="120">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small text-muted mb-0" for="cs-cost">Cost (Bucks)</label>
                        <input id="cs-cost" v-model="form.cost" inputmode="numeric" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small text-muted mb-0" for="cs-stock">How many</label>
                        <input id="cs-stock" v-model="form.stock" inputmode="numeric" class="form-control form-control-sm"
                               placeholder="Leave blank for no limit">
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted mb-0" for="cs-desc">Details (optional)</label>
                        <input id="cs-desc" v-model="form.description" class="form-control form-control-sm" maxlength="500">
                    </div>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-sm btn-success" :disabled="busy || !prizeFormReady(form)">{{ editingId ? 'Save' : 'Add prize' }}</button>
                    <button v-if="editingId" type="button" class="btn btn-sm btn-link" @click="resetForm">Cancel</button>
                </div>
                <p v-if="prizeError" class="text-danger small mb-0 mt-1">{{ prizeError }}</p>
            </form>

            <!-- BUILT AND OFF: only a school that has switched paper Bucks on is shown this. -->
            <template v-if="settings?.paper_bucks_enabled">
                <h2 class="h6 text-uppercase text-muted small mt-4">Paper hand-out</h2>
                <div class="d-flex gap-2 align-items-center mb-2">
                    <input v-model="handoutDate" type="date" class="form-control form-control-sm" style="max-width: 11rem" aria-label="Day">
                    <button class="btn btn-sm btn-outline-secondary" @click="loadHandout">Show</button>
                    <button v-if="handout?.entries?.length" class="btn btn-sm btn-outline-secondary" @click="printHandout">Print</button>
                </div>
                <p v-if="handoutError" class="text-danger small">{{ handoutError }}</p>
                <div v-if="handout" id="cs-handout">
                    <p v-if="!handout.entries.length" class="text-muted small">No Bucks were paid out on {{ handout.date }}.</p>
                    <table v-else class="table table-sm">
                        <thead><tr><th>Student</th><th>Bucks</th><th>Notes</th></tr></thead>
                        <tbody>
                            <tr v-for="e in handout.entries" :key="e.id">
                                <td>{{ name(e.student?.contact) }}</td>
                                <td>{{ e.amount }}</td>
                                <td>{{ breakdownLine(e.breakdown) }}</td>
                            </tr>
                        </tbody>
                        <tfoot><tr><th>Notes to count out</th><th>{{ handout.totals.amount }}</th><th>{{ breakdownLine(handout.totals.notes) }}</th></tr></tfoot>
                    </table>
                </div>
            </template>
        </template>
    </section>
</template>

<script setup lang="ts">
import TeacherApiService, { rowsOf } from '@/core/services/TeacherApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import {
    appendPage, blankPrizeForm, breakdownLine, bucksLabel, createRequestIds, entryText, prizeEditRequest, prizeFormFrom,
    prizeFormReady, prizeRequest, shelfFor, signedBucks, stockNote,
} from '@/core/helpers/classStore';
import type { PrizeForm, StorePrize } from '@/core/helpers/classStore';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The teacher's class store (T-003.4): balances in roster order, the shelf, giving a prize,
 * undoing one, this class's own prizes, and (only where paper Bucks are switched on) paying
 * out on paper with the day's hand-out.
 *
 * Shown only when the class payload says `class_store: true`; the server refuses every route
 * of it (403) for a school that has not switched the store on, so this is a courtesy and the
 * server is the boundary. Every figure on screen is the server's: a balance is never worked
 * out here from the history, and a failed read shows "unavailable", never a made-up 0.
 * Students are listed in the order the server sent them; nothing here sorts or totals Bucks.
 */
const props = defineProps<{ base: string }>();

/** The request id each write in flight is sent with, kept across a retry (see createRequestIds). */
const requestIds = createRequestIds();

const loading = ref(true);
const error = ref('');
const students = ref<any[]>([]);
const settings = ref<{ points_per_buck: number; paper_bucks_enabled: boolean } | null>(null);
const prizes = ref<StorePrize[]>([]);
const selectedId = ref<number | null>(null);
const history = ref<any[]>([]);
const historyError = ref('');
const historyPage = ref(1);
const historyLastPage = ref(1);
const historyMoreBusy = ref(false);
const historyMoreError = ref('');
const balance = ref<number | null>(null);
const busy = ref(false);
const giveError = ref('');
// Not an error: the server had already recorded this exact write (a replay), so nothing new was taken.
const giveNote = ref('');
const prizeError = ref('');
const form = ref<PrizeForm>(blankPrizeForm());
const editingId = ref<number | null>(null);
/** The prize as it was when the edit form opened: its stock is what a changed count is checked against. */
const editingFrom = ref<StorePrize | null>(null);
const cashAmount = ref('');
const handoutDate = ref('');
const handout = ref<any>(null);
const handoutError = ref('');

/** A response that lands after the screen is gone, or after another student was picked, is dropped. */
let alive = true;
let pickSeq = 0;
onBeforeUnmount(() => { alive = false; });

const name = (c: any) => [c?.first_name, c?.last_name].filter(Boolean).join(' ') || 'Student';
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '');

const selected = computed(() => students.value.find((s) => s.membership_id === selectedId.value) ?? null);
const historyHasMore = computed(() => historyPage.value < historyLastPage.value);
const offered = computed(() => shelfFor(prizes.value, balance.value));
const pointsPerBuckText = computed(() => {
    const n = settings.value?.points_per_buck ?? 1;
    return n === 1 ? 'Buck from one point' : `Buck from ${n} points`;
});

const cashAmountOk = computed(() => /^\d+$/.test(cashAmount.value.trim()) && Number(cashAmount.value) >= 1 && balance.value !== null && Number(cashAmount.value) <= balance.value);
const previewNotes = computed(() => {
    let left = Number(cashAmount.value) || 0;
    const out: Record<string, number> = {};
    for (const note of [20, 10, 5, 1]) { out[String(note)] = Math.floor(left / note); left -= out[String(note)] * note; }
    return out;
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const [bucks, shelf] = await Promise.all([
            TeacherApiService.get(`${props.base}/bucks`),
            TeacherApiService.get(`${props.base}/prizes`),
        ]);
        if (!alive) return;
        students.value = rowsOf(bucks.data?.data?.students);
        settings.value = bucks.data?.data?.settings ?? null;
        prizes.value = rowsOf(shelf.data?.data) as StorePrize[];
        if (selectedId.value !== null) await select(selectedId.value);
    } catch (e) {
        if (alive) error.value = apiErrorText(e, 'The class store could not be loaded.');
    } finally {
        if (alive) loading.value = false;
    }
}

/**
 * Open a student: their balance and the newest page of their history. A teacher's own pick clears
 * the last message; the reload after a write passes `keepMessage`, because that message (a refused
 * prize: not enough Bucks, out of stock, retired) is the answer to what the teacher just did, and
 * clearing it on the way back is how a refusal used to show no message at all.
 */
async function select(id: number, keepMessage = false) {
    selectedId.value = id;
    const seq = ++pickSeq;
    history.value = [];
    historyError.value = '';
    historyMoreError.value = '';
    historyPage.value = 1;
    historyLastPage.value = 1;
    if (!keepMessage) { giveError.value = ''; giveNote.value = ''; }
    balance.value = students.value.find((s) => s.membership_id === id)?.balance ?? null;
    try {
        const res = await TeacherApiService.get(`${props.base}/members/${id}/bucks?page=1&per_page=25`);
        if (!alive || seq !== pickSeq) return;
        history.value = rowsOf(res.data?.data);
        historyPage.value = Number(res.data?.data?.current_page ?? 1);
        historyLastPage.value = Number(res.data?.data?.last_page ?? 1);
        balance.value = res.data?.meta?.balance ?? balance.value;
    } catch (e) {
        if (alive && seq === pickSeq) { historyError.value = apiErrorText(e, 'The history could not be loaded.'); balance.value = null; }
    }
}

/** The next 25 older lines of the open student's history, added under the ones shown. */
async function loadEarlier() {
    if (selectedId.value === null || historyMoreBusy.value || !historyHasMore.value) return;
    const seq = pickSeq;
    historyMoreBusy.value = true;
    historyMoreError.value = '';
    try {
        const res = await TeacherApiService.get(`${props.base}/members/${selectedId.value}/bucks?page=${historyPage.value + 1}&per_page=25`);
        if (!alive || seq !== pickSeq) return;
        history.value = appendPage(history.value, rowsOf(res.data?.data));
        historyPage.value = Number(res.data?.data?.current_page ?? historyPage.value + 1);
        historyLastPage.value = Number(res.data?.data?.last_page ?? historyLastPage.value);
    } catch (e) {
        if (alive && seq === pickSeq) historyMoreError.value = apiErrorText(e, 'The earlier lines could not be loaded.');
    } finally {
        historyMoreBusy.value = false;
    }
}

/** Re-read the roster's balances and the open student after a write, so the numbers are the server's. */
async function refresh() {
    const [bucks, shelf] = await Promise.all([
        TeacherApiService.get(`${props.base}/bucks`),
        TeacherApiService.get(`${props.base}/prizes`),
    ]);
    if (!alive) return;
    students.value = rowsOf(bucks.data?.data?.students);
    prizes.value = rowsOf(shelf.data?.data) as StorePrize[];
    if (selectedId.value !== null) await select(selectedId.value, true);
}

async function give(p: StorePrize) {
    if (selectedId.value === null || busy.value) return;
    const key = `redeem:${selectedId.value}:${p.id}`;
    busy.value = true;
    giveError.value = '';
    giveNote.value = '';
    let saved = false;
    try {
        // One id per WRITE: a double-tap, or a retry after a lost response, is a replay on the server,
        // not a second deduction. The id is kept until the write succeeds or is refused for good.
        const res = await TeacherApiService.post(`${props.base}/members/${selectedId.value}/prizes/redeem`, { prize_id: p.id, request_id: requestIds.idFor(key) });
        requestIds.succeeded(key);
        saved = true;
        // A replay answers with the row an earlier tap wrote (its response was lost). Said, so a
        // teacher who meant a SECOND gift knows this one was not it; the id is spent, so the next
        // tap is a new gift.
        if (res?.data?.data?.replayed) giveNote.value = 'That gift had already gone through, so no more Bucks were taken. Give it again if you meant a second one.';
    } catch (e) {
        requestIds.failed(key, (e as any)?.response?.status);
        giveError.value = apiErrorText(e, 'That prize could not be given.');
    }

    try {
        await refresh();
    } catch {
        // A reload that fails AFTER the prize was given must not say it failed: the teacher would tap
        // again and the Bucks would go twice.
        if (saved) giveError.value = 'That prize was given, but the screen could not reload. Refresh the page to see the new balance.';
    } finally {
        busy.value = false;
    }
}

async function undo(e: any) {
    if (busy.value || !window.confirm('Undo this? The Bucks go back to the student in a new line; the old line stays.')) return;
    busy.value = true;
    giveError.value = '';
    giveNote.value = '';
    let saved = false;
    try {
        await TeacherApiService.post(`${props.base}/prize-entries/${e.id}/reverse`, {});
        saved = true;
    } catch (err) {
        giveError.value = apiErrorText(err, 'That could not be undone.');
    }

    try {
        if (saved) await refresh();
    } catch {
        // The undo stands; only the reload failed. Saying it failed would be untrue.
        giveError.value = 'That was undone, but the screen could not reload. Refresh the page to see the new balance.';
    } finally {
        busy.value = false;
    }
}

async function cashOut() {
    if (selectedId.value === null || !cashAmountOk.value || busy.value) return;
    const key = `cashout:${selectedId.value}:${Number(cashAmount.value)}`;
    busy.value = true;
    giveError.value = '';
    giveNote.value = '';
    let saved = false;
    try {
        await TeacherApiService.post(`${props.base}/members/${selectedId.value}/prizes/cash-out`, { amount: Number(cashAmount.value), request_id: requestIds.idFor(key) });
        requestIds.succeeded(key);
        cashAmount.value = '';
        saved = true;
    } catch (e) {
        requestIds.failed(key, (e as any)?.response?.status);
        giveError.value = apiErrorText(e, 'That could not be paid out.');
    }

    try {
        await refresh();
    } catch {
        if (saved) giveError.value = 'That was paid out, but the screen could not reload. Refresh the page to see the new balance.';
    } finally {
        busy.value = false;
    }
}

function resetForm() { editingId.value = null; editingFrom.value = null; form.value = blankPrizeForm(); prizeError.value = ''; }
function editPrize(p: StorePrize) { editingId.value = p.id; editingFrom.value = { ...p }; form.value = prizeFormFrom(p); prizeError.value = ''; }

async function savePrize() {
    if (!prizeFormReady(form.value) || busy.value) return;
    busy.value = true;
    prizeError.value = '';
    let saved = false;
    try {
        if (editingId.value === null) await TeacherApiService.post(`${props.base}/prizes`, prizeRequest(form.value));
        else await TeacherApiService.put(`${props.base}/prizes/${editingId.value}`, prizeEditRequest(form.value, editingFrom.value ?? { stock: null }));
        saved = true;
        resetForm();
    } catch (e) {
        prizeError.value = apiErrorText(e, 'That prize could not be saved.');
        // The count moved under the form (a prize was given): the message says so, and the form now
        // compares against the count as it is, so the teacher's next Save is checked against that.
        if ((e as any)?.response?.data?.reason === 'stock_changed' && editingId.value !== null) {
            try {
                await refresh();
                editingFrom.value = prizes.value.find((p) => p.id === editingId.value) ?? editingFrom.value;
            } catch { /* the message above already says what happened */ }
        }
    }

    try {
        if (saved) await refresh();
    } catch {
        // Saved, and the form is already cleared: a teacher told it failed would add it again (a
        // duplicate title the server refuses) or edit it twice.
        prizeError.value = 'That prize was saved, but the screen could not reload. Refresh the page to see it.';
    } finally {
        busy.value = false;
    }
}

async function toggleActive(p: StorePrize) {
    if (busy.value) return;
    busy.value = true;
    prizeError.value = '';
    let saved = false;
    try {
        await TeacherApiService.put(`${props.base}/prizes/${p.id}`, { is_active: !p.is_active });
        saved = true;
    } catch (e) {
        prizeError.value = apiErrorText(e, 'That prize could not be changed.');
    }

    try {
        if (saved) await refresh();
    } catch {
        prizeError.value = 'That prize was changed, but the screen could not reload. Refresh the page to see it.';
    } finally {
        busy.value = false;
    }
}

async function loadHandout() {
    handoutError.value = '';
    try {
        const q = handoutDate.value ? `?date=${encodeURIComponent(handoutDate.value)}` : '';
        const res = await TeacherApiService.get(`${props.base}/bucks/handout${q}`);
        if (alive) handout.value = res.data?.data ?? null;
    } catch (e) {
        if (alive) { handout.value = null; handoutError.value = apiErrorText(e, 'The hand-out could not be loaded.'); }
    }
}

function printHandout() { window.print(); }

onMounted(load);
</script>
