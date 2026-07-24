<template>
    <div>
        <PageDataContainer
            title="Properties & Rent"
            :buttonProps="{ title: 'Add property', type: 'button', class: 'btn btn-success', disabled: false }"
            @headerButtonClick="openCreate"
        >
            <div class="container w-100">
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>

                <div v-else-if="properties.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-houses fs-1 d-block mb-3"></i>
                    <p>No properties yet</p>
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Property</th>
                                <th>Tenant</th>
                                <th>Monthly rent</th>
                                <th class="text-end">Collected</th>
                                <th class="text-center">Payments</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in properties" :key="p.id">
                                <td>
                                    <div class="fw-semibold">{{ p.name }}</div>
                                    <div class="text-muted small" v-if="p.address">{{ p.address }}</div>
                                </td>
                                <td>{{ p.tenant_name || '—' }}</td>
                                <td>{{ p.monthly_rent ? formatCents(p.monthly_rent) : '—' }}</td>
                                <td class="text-end">{{ formatCents(p.rent_payments_sum_amount || 0) }}</td>
                                <td class="text-center">{{ p.rent_payments_count ?? 0 }}</td>
                                <td>
                                    <span class="badge" :class="p.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                                        {{ p.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary me-1" @click="openDetail(p)" title="Rent payments"><i class="bi bi-cash-stack"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary me-1" @click="openEdit(p)" title="Edit"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" @click="remove(p)" title="Archive"><i class="bi bi-archive"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>

        <!-- Create / edit property -->
        <Teleport to="body">
            <div v-if="showForm" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" @click.self="showForm=false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ editing ? 'Edit property' : 'Add property' }}</h5>
                            <button type="button" class="btn-close" @click="showForm=false"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Name *</label>
                                <input class="form-control" v-model="form.name" placeholder="Brick House 2">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <input class="form-control" v-model="form.address" placeholder="1910 S Mebane St">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tenant</label>
                                    <input class="form-control" v-model="form.tenant_name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Monthly rent ($)</label>
                                    <input class="form-control" type="number" step="0.01" v-model="form.monthly_rent">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" rows="2" v-model="form.notes"></textarea>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" v-model="form.is_active" id="propActive">
                                <label class="form-check-label" for="propActive">Active</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" @click="showForm=false">Cancel</button>
                            <button class="btn btn-success" :disabled="!form.name || saving" @click="save">
                                <span v-if="saving" class="spinner-border spinner-border-sm"></span><span v-else>Save</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Detail: rent payments -->
        <Teleport to="body">
            <div v-if="showDetail && selected" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" @click.self="showDetail=false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ selected.name }} — rent</h5>
                            <button type="button" class="btn-close" @click="showDetail=false"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Log a payment -->
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Date</label>
                                    <input class="form-control" type="date" v-model="rentForm.paid_on">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1">Amount ($)</label>
                                    <input class="form-control" type="number" step="0.01" v-model="rentForm.amount" placeholder="800">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted mb-1">Method</label>
                                    <select class="form-select text-capitalize" v-model="rentForm.payment_method">
                                        <option v-for="m in rentMethods" :key="m" :value="m" class="text-capitalize">{{ m }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2" v-if="rentForm.payment_method === 'check'">
                                    <label class="form-label small text-muted mb-1">Check #</label>
                                    <input class="form-control" v-model="rentForm.check_number" placeholder="1234">
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-success w-100" :disabled="!rentForm.paid_on || rentForm.amount==='' || savingRent" @click="logRent">
                                        <span v-if="savingRent" class="spinner-border spinner-border-sm"></span><span v-else>Log payment</span>
                                    </button>
                                </div>
                            </div>
                            <p class="text-muted small">A negative amount records a vacancy or adjustment.</p>

                            <div class="table-responsive" style="max-height:45vh; overflow-y:auto;">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Date</th><th>Method</th><th class="text-end">Amount</th><th></th></tr></thead>
                                    <tbody>
                                        <tr v-for="r in (selected.rent_payments || [])" :key="r.id">
                                            <td>{{ formatDate(r.paid_on) }}</td>
                                            <td class="text-capitalize">{{ r.payment_method || '—' }}<span v-if="r.check_number" class="text-muted text-lowercase"> · #{{ r.check_number }}</span></td>
                                            <td class="text-end" :class="{ 'text-danger': r.amount < 0 }">{{ formatCents(r.amount) }}</td>
                                            <td class="text-end"><button class="btn btn-sm btn-outline-danger" @click="removeRent(r)"><i class="bi bi-x"></i></button></td>
                                        </tr>
                                        <tr v-if="(selected.rent_payments || []).length === 0"><td colspan="4" class="text-center text-muted py-3">No payments recorded</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import Swal from 'sweetalert2';

interface RentPayment { id: number; paid_on: string; amount: number; payment_method?: string; note?: string; }
interface PropertyRow {
    id: number; name: string; address?: string; tenant_name?: string; monthly_rent?: number | null;
    notes?: string; is_active: boolean; rent_payments_count?: number; rent_payments_sum_amount?: number;
    rent_payments?: RentPayment[];
}

const authStore = useAuthStore();
const masjidStore = useMasjidStore();

const loading = ref(false);
const saving = ref(false);
const savingRent = ref(false);
const properties = ref<PropertyRow[]>([]);
const showForm = ref(false);
const editing = ref<PropertyRow | null>(null);
const showDetail = ref(false);
const selected = ref<PropertyRow | null>(null);

const form = ref<any>({ name: '', address: '', tenant_name: '', monthly_rent: '', notes: '', is_active: true });
const rentForm = ref<any>({ paid_on: '', amount: '', payment_method: 'cash', check_number: '' });
const rentMethods = ['cash', 'check', 'zelle', 'venmo', 'credit', 'other'];

const masjidId = () => authStore.dashboardMasjidId ?? masjidStore.masjid?.id;
const base = computed(() => `/api/admin/masjids/${masjidId()}/properties`);

onBeforeMount(loadData);

async function loadData() {
    if (!masjidId()) return;
    loading.value = true;
    try {
        const res = await ApiService.get(base.value as any);
        if (res.data?.status === 'success') properties.value = res.data.data;
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load properties.' });
    } finally { loading.value = false; }
}

function openCreate() {
    editing.value = null;
    form.value = { name: '', address: '', tenant_name: '', monthly_rent: '', notes: '', is_active: true };
    showForm.value = true;
}
function openEdit(p: PropertyRow) {
    editing.value = p;
    form.value = {
        name: p.name, address: p.address ?? '', tenant_name: p.tenant_name ?? '',
        monthly_rent: p.monthly_rent != null ? (p.monthly_rent / 100).toFixed(2) : '',
        notes: p.notes ?? '', is_active: p.is_active,
    };
    showForm.value = true;
}

async function save() {
    const payload = new URLSearchParams();
    payload.append('name', form.value.name);
    payload.append('address', form.value.address || '');
    payload.append('tenant_name', form.value.tenant_name || '');
    if (form.value.monthly_rent !== '') payload.append('monthly_rent', form.value.monthly_rent);
    payload.append('notes', form.value.notes || '');
    payload.append('is_active', form.value.is_active ? '1' : '0');
    saving.value = true;
    try {
        if (editing.value) await ApiService.put(`${base.value}/${editing.value.id}` as any, payload);
        else await ApiService.post(base.value as any, payload);
        showForm.value = false;
        await loadData();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not save the property.' });
    } finally { saving.value = false; }
}

async function remove(p: PropertyRow) {
    const c = await Swal.fire({ icon: 'warning', title: `Archive ${p.name}?`, text: 'Rent history is kept.', showCancelButton: true, confirmButtonText: 'Archive', confirmButtonColor: '#dc3545' });
    if (!c.isConfirmed) return;
    try { await ApiService.delete(`${base.value}/${p.id}` as any); await loadData(); }
    catch (e) { Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not archive.' }); }
}

async function openDetail(p: PropertyRow) {
    selected.value = p;
    showDetail.value = true;
    rentForm.value = { paid_on: new Date().toISOString().slice(0, 10), amount: '', payment_method: 'cash', check_number: '' };
    try {
        const res = await ApiService.get(`${base.value}/${p.id}` as any);
        if (res.data?.status === 'success') selected.value = res.data.data;
    } catch (e) { /* keep row data */ }
}

async function logRent() {
    if (!selected.value) return;
    const payload = new URLSearchParams();
    payload.append('paid_on', rentForm.value.paid_on);
    payload.append('amount', rentForm.value.amount);
    if (rentForm.value.payment_method) payload.append('payment_method', rentForm.value.payment_method);
    if (rentForm.value.payment_method === 'check' && rentForm.value.check_number) payload.append('check_number', rentForm.value.check_number);
    savingRent.value = true;
    try {
        await ApiService.post(`${base.value}/${selected.value.id}/rent` as any, payload);
        await openDetail(selected.value);   // refresh
        await loadData();                    // update totals on the list
        rentForm.value.amount = '';
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not log the payment.' });
    } finally { savingRent.value = false; }
}

async function removeRent(r: RentPayment) {
    if (!selected.value) return;
    try {
        await ApiService.delete(`${base.value}/${selected.value.id}/rent/${r.id}` as any);
        await openDetail(selected.value);
        await loadData();
    } catch (e) { Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not remove.' }); }
}

const formatCents = (cents: number): string => {
    try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format((cents ?? 0) / 100); }
    catch (e) { return `$${((cents ?? 0) / 100).toFixed(2)}`; }
};
const formatDate = (iso: string): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};
</script>

<style scoped>
.modal { display: block; z-index: 1055; }
</style>
