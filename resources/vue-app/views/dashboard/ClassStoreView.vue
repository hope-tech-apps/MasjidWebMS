<template>
    <div>
        <PageDataContainer title="Class Store" :hideButton="true">
            <div class="container w-100">
                <p class="text-muted small mb-1">
                    Manara Bucks: each week's positive points turn into Bucks, and each class's teachers run a store
                    where their students spend them. You keep the school-wide prize list, and read the totals below.
                </p>
                <p class="text-muted small mb-4">
                    The totals are for each class as a whole. You cannot see one child's balance here: a child's
                    Bucks are seen by their own teachers and their own family, like their points.
                </p>

                <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    {{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
                </div>

                <template v-else>
                    <!-- ============================================ SCHOOL-WIDE PRIZES -->
                    <h2 class="h5">School-wide prizes</h2>
                    <p class="text-muted small">
                        Every class can give these. A teacher adds their own class's prizes separately. A prize is
                        retired, never deleted, because the history names it. Leave "How many" blank for no limit.
                    </p>

                    <p v-if="!prizes.length" class="text-muted">No school-wide prizes yet.</p>
                    <div v-else class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Prize</th><th>Cost</th><th>Left</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="p in prizes" :key="p.id" :class="{ 'text-muted': !p.is_active }">
                                    <td dir="auto">
                                        {{ p.title }}
                                        <span v-if="!p.is_active" class="badge bg-secondary-subtle text-secondary ms-1">Retired</span>
                                        <span v-if="p.description" class="d-block small text-muted" dir="auto">{{ p.description }}</span>
                                    </td>
                                    <td>{{ bucksLabel(p.cost_bucks) }}</td>
                                    <td>{{ p.stock === null ? 'No limit' : p.stock }}</td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-secondary me-1" :disabled="busy" @click="edit(p)">Edit</button>
                                        <button class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="toggle(p)">
                                            {{ p.is_active ? 'Retire' : 'Bring back' }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <form class="card card-body border-0 bg-light mb-4" @submit.prevent="save">
                        <h3 class="h6">{{ editingId ? 'Edit this prize' : 'Add a school-wide prize' }}</h3>
                        <div class="row g-2">
                            <div class="col-sm-5">
                                <label class="form-label small text-muted mb-0" for="cs-o-title">Name</label>
                                <input id="cs-o-title" v-model="form.title" class="form-control form-control-sm" maxlength="120">
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label small text-muted mb-0" for="cs-o-cost">Cost (Bucks)</label>
                                <input id="cs-o-cost" v-model="form.cost" inputmode="numeric" class="form-control form-control-sm">
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label small text-muted mb-0" for="cs-o-stock">How many</label>
                                <input id="cs-o-stock" v-model="form.stock" inputmode="numeric" class="form-control form-control-sm" placeholder="No limit">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small text-muted mb-0" for="cs-o-desc">Details (optional)</label>
                                <input id="cs-o-desc" v-model="form.description" class="form-control form-control-sm" maxlength="500">
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-success" :disabled="busy || !prizeFormReady(form)">{{ editingId ? 'Save' : 'Add prize' }}</button>
                            <button v-if="editingId" type="button" class="btn btn-sm btn-link" @click="reset">Cancel</button>
                        </div>
                        <p v-if="formError" class="text-danger small mb-0 mt-1">{{ formError }}</p>
                    </form>

                    <!-- ============================================ RECONCILIATION -->
                    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2">
                        <h2 class="h5 mb-0">Reconciliation</h2>
                        <label class="small text-muted">
                            Weeks in view
                            <select v-model.number="weeks" class="form-select form-select-sm d-inline-block w-auto ms-1" @change="loadReconciliation">
                                <option v-for="n in [4, 8, 13, 26]" :key="n" :value="n">{{ n }}</option>
                            </select>
                        </label>
                    </div>
                    <p v-if="reconError" class="text-danger small">{{ reconError }}</p>
                    <template v-else-if="recon">
                        <p class="text-muted small mt-2">
                            <template v-if="recon.settings.bucks_from">
                                Points count from {{ recon.settings.bucks_from }}, at
                                {{ recon.settings.points_per_buck }} point{{ recon.settings.points_per_buck === 1 ? '' : 's' }} for each Buck.
                            </template>
                            <template v-else>The store has not started counting yet: it starts at the next weekly run.</template>
                            <template v-if="recon.window.weeks"> Comparing the last {{ recon.window.weeks }} finished week{{ recon.window.weeks === 1 ? '' : 's' }}.</template>
                        </p>

                        <p v-if="recon.totals.negative_balances > 0" class="alert alert-warning py-2 small">
                            {{ recon.totals.negative_balances }} balance{{ recon.totals.negative_balances === 1 ? ' is' : 's are' }} below zero.
                            That should never happen: tell whoever looks after Manara.
                        </p>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Class</th><th class="text-end">Earned</th><th class="text-end">Spent on prizes</th>
                                        <th class="text-end">Given back</th>
                                        <th v-if="recon.settings.paper_bucks_enabled" class="text-end">Paid out on paper</th>
                                        <th class="text-end">Expired</th><th class="text-end">Held now</th>
                                        <th class="text-end">Points vs Bucks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="c in recon.classes" :key="c.group_id">
                                    <!-- A class too small to show: in it a total IS a child's balance. -->
                                    <tr v-if="c.suppressed" class="text-muted">
                                        <td dir="auto">{{ c.name }}</td>
                                        <td :colspan="recon.settings.paper_bucks_enabled ? 7 : 6" class="small">
                                            Fewer than {{ recon.min_class_size }} students: not shown, so no child's balance can be read from it.
                                        </td>
                                    </tr>
                                    <tr v-else>
                                        <td dir="auto">{{ c.name }}</td>
                                        <td class="text-end">{{ c.minted }}</td>
                                        <td class="text-end">{{ c.redeemed }}</td>
                                        <td class="text-end">{{ c.reversed }}</td>
                                        <td v-if="recon.settings.paper_bucks_enabled" class="text-end">{{ c.cashed_out }}</td>
                                        <td class="text-end">{{ c.expired }}</td>
                                        <td class="text-end fw-semibold">{{ c.outstanding }}</td>
                                        <td class="text-end">
                                            <span v-if="c.window_difference === 0" class="text-success">Agrees</span>
                                            <span v-else class="text-warning-emphasis">{{ differenceText(c.window_difference) }}</span>
                                        </td>
                                    </tr>
                                    </template>
                                </tbody>
                                <tfoot>
                                    <tr class="fw-semibold">
                                        <td>{{ recon.suppressed_classes ? 'Classes shown' : 'All classes' }}</td>
                                        <td class="text-end">{{ recon.totals.minted }}</td>
                                        <td class="text-end">{{ recon.totals.redeemed }}</td>
                                        <td class="text-end">{{ recon.totals.reversed }}</td>
                                        <td v-if="recon.settings.paper_bucks_enabled" class="text-end">{{ recon.totals.cashed_out }}</td>
                                        <td class="text-end">{{ recon.totals.expired }}</td>
                                        <td class="text-end">{{ recon.totals.outstanding }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p class="text-muted small">
                            "Points vs Bucks" compares the Bucks the class was paid with the Bucks its points work out to.
                            A difference is a question, not an error: it can come from a change to points made more than two
                            weeks after the week ended, or from a student who spent Bucks that a later correction would have taken back.
                        </p>
                    </template>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { blankPrizeForm, bucksLabel, prizeEditRequest, prizeFormFrom, prizeFormReady, prizeRequest } from '@/core/helpers/classStore';
import type { PrizeEditBody, PrizeForm, StorePrize } from '@/core/helpers/classStore';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * The OFFICE's class store screen (T-003.4, W6): the school-wide prize list and the
 * reconciliation of the store's totals.
 *
 * Class totals only, by design: the office administers the store but does not stand in a class,
 * so no child's name or balance is anywhere on this screen or in the endpoint behind it. There is
 * no delete: a prize is retired. Gated by the `class_store` capability on the route and in the
 * menu; the server's `capability:class_store` gate is the boundary.
 *
 * The prize writes go through the admin ApiService, whose PUT is form-encoded: an empty "How
 * many" is sent as the empty string (which the server reads as "no limit") and a switch as
 * "1" or "0", because a null would be dropped from a form body and an edit to "no limit" would
 * silently change nothing.
 */
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const masjidId = computed(() => authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null);
const url = (suffix: string): BackendApiRoute => `/api/admin/masjids/${masjidId.value}/${suffix}` as BackendApiRoute;

const loading = ref(true);
const loadError = ref('');
const prizes = ref<StorePrize[]>([]);
const form = ref<PrizeForm>(blankPrizeForm());
const editingId = ref<number | null>(null);
/** The prize as it was when the edit form opened: its stock is what a changed count is checked against. */
const editingFrom = ref<StorePrize | null>(null);
const busy = ref(false);
const formError = ref('');
const weeks = ref(8);
const recon = ref<any>(null);
const reconError = ref('');

const differenceText = (d: number) => `${d > 0 ? '+' : ''}${d} Bucks`;

async function load() {
    if (!masjidId.value) return;
    loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(url('prizes'));
        prizes.value = (res.data?.data ?? []) as StorePrize[];
        await loadReconciliation();
    } catch (e) {
        loadError.value = apiErrorText(e, 'The class store could not be loaded.');
    } finally {
        loading.value = false;
    }
}

async function loadReconciliation() {
    reconError.value = '';
    try {
        const res = await ApiService.get(url(`prize-reconciliation?weeks=${weeks.value}`));
        recon.value = res.data?.data ?? null;
    } catch (e) {
        recon.value = null;
        reconError.value = apiErrorText(e, 'The totals could not be loaded.');
    }
}

function reset() { editingId.value = null; editingFrom.value = null; form.value = blankPrizeForm(); formError.value = ''; }
function edit(p: StorePrize) { editingId.value = p.id; editingFrom.value = { ...p }; form.value = prizeFormFrom(p); formError.value = ''; }

/**
 * The form-encoded body the admin PUT needs (see the note above). A stock is sent only when it was
 * changed, and then with the count the form loaded (`expected_stock`, '' for no limit), which the
 * server checks under the prize's lock: a 409 means a prize was given meanwhile.
 */
function encoded(body: PrizeEditBody) {
    const out: Record<string, string | number> = {
        title: body.title,
        description: body.description ?? '',
        cost_bucks: body.cost_bucks,
        is_active: body.is_active ? '1' : '0',
    };
    if ('stock' in body) {
        out.stock = body.stock === null || body.stock === undefined ? '' : body.stock;
        out.expected_stock = body.expected_stock === null || body.expected_stock === undefined ? '' : body.expected_stock;
    }

    return out;
}

async function save() {
    if (!prizeFormReady(form.value) || busy.value) return;
    busy.value = true;
    formError.value = '';
    try {
        if (editingId.value === null) await ApiService.post(url('prizes'), prizeRequest(form.value));
        else await ApiService.put(url(`prizes/${editingId.value}`), encoded(prizeEditRequest(form.value, editingFrom.value ?? { stock: null })));
        reset();
        await load();
    } catch (e) {
        formError.value = apiErrorText(e, 'That prize could not be saved.');
        // The count moved under the form: the next Save is checked against the count as it is now.
        if ((e as any)?.response?.data?.reason === 'stock_changed' && editingId.value !== null) {
            try {
                const res = await ApiService.get(url('prizes'));
                prizes.value = (res.data?.data ?? []) as StorePrize[];
                editingFrom.value = prizes.value.find((p) => p.id === editingId.value) ?? editingFrom.value;
            } catch { /* the message above already says what happened */ }
        }
    } finally {
        busy.value = false;
    }
}

async function toggle(p: StorePrize) {
    if (busy.value) return;
    busy.value = true;
    formError.value = '';
    try {
        await ApiService.put(url(`prizes/${p.id}`), { is_active: p.is_active ? '0' : '1' });
        await load();
    } catch (e) {
        formError.value = apiErrorText(e, 'That prize could not be changed.');
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>
