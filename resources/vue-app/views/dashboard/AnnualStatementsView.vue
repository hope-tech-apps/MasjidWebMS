<template>
    <div>
        <PageDataContainer title="Year-End Statements" :hideButton="true">
            <div class="container w-100">
                <!-- Controls -->
                <div class="row g-3 mb-4 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Tax year</label>
                        <select class="form-select" v-model.number="year" @change="loadData">
                            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                    <div class="col-md-9 text-md-end">
                        <button
                            class="btn btn-success"
                            :disabled="loading || sendingAll || donors.length === 0"
                            @click="sendAll"
                        >
                            <span v-if="sendingAll" class="spinner-border spinner-border-sm me-1"></span>
                            Email all statements
                        </button>
                    </div>
                </div>

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>

                <div v-else-if="donors.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-file-earmark-text fs-1 d-block mb-3"></i>
                    <p>No receipted giving in {{ year }}</p>
                </div>

                <template v-else>
                    <div class="alert alert-light border d-flex justify-content-between align-items-center mb-3">
                        <span>{{ donorCount }} donor{{ donorCount === 1 ? '' : 's' }} · {{ year }}</span>
                        <strong>Total eligible: {{ totalLabel }}</strong>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Donor</th>
                                    <th>Email</th>
                                    <th class="text-center">Donations</th>
                                    <th class="text-end">Total eligible</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in donors" :key="`${d.contact_id}-${d.currency}`">
                                    <td>{{ d.name }}</td>
                                    <td>
                                        <span v-if="d.email">{{ d.email }}</span>
                                        <span v-else class="badge bg-warning-subtle text-warning">no email</span>
                                    </td>
                                    <td class="text-center">{{ d.gift_count }}</td>
                                    <td class="text-end"><strong>{{ formatCents(d.total_eligible, d.currency) }}</strong></td>
                                    <td class="text-end">
                                        <button
                                            class="btn btn-sm btn-outline-secondary me-1"
                                            :disabled="downloadingId === d.contact_id"
                                            @click="downloadPdf(d)"
                                            title="Download the letter PDF"
                                        >
                                            <span v-if="downloadingId === d.contact_id" class="spinner-border spinner-border-sm"></span>
                                            <span v-else><i class="bi bi-download"></i> PDF</span>
                                        </button>
                                        <button
                                            class="btn btn-sm btn-outline-primary"
                                            :disabled="!d.email || sendingId === d.contact_id"
                                            @click="sendOne(d)"
                                            :title="d.email ? 'Email this statement (PDF attached)' : 'No email on file'"
                                        >
                                            <span v-if="sendingId === d.contact_id" class="spinner-border spinner-border-sm"></span>
                                            <span v-else>Email</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import Swal from 'sweetalert2';

interface DonorRow {
    contact_id: number;
    name: string;
    email: string | null;
    total_eligible: number;
    gift_count: number;
    currency: string;
}

const authStore = useAuthStore();
const masjidStore = useMasjidStore();

const loading = ref(false);
const sendingId = ref<number | null>(null);
const downloadingId = ref<number | null>(null);
const sendingAll = ref(false);
const donors = ref<DonorRow[]>([]);
// Summary rows already separate donor/currency. Derive totals from those rows
// so a cached API's old combined total can never be displayed.
const donorCount = computed(() => new Set(donors.value.map(d => d.contact_id)).size);
const totalsByCurrency = computed(() => {
    const totals: Record<string, number> = {};
    for (const d of donors.value) {
        const currency = d.currency.toUpperCase();
        totals[currency] = (totals[currency] ?? 0) + d.total_eligible;
    }
    return Object.entries(totals);
});
const totalLabel = computed(() => totalsByCurrency.value.length === 1
    ? formatCents(totalsByCurrency.value[0][1], totalsByCurrency.value[0][0])
    : totalsByCurrency.value.map(([currency, cents]) => `${currency} ${(cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`).join('; '));

const currentYear = new Date().getFullYear();
const year = ref(currentYear - 1); // statements default to last completed year
const years = Array.from({ length: 6 }, (_, i) => currentYear - i);

const masjidId = () => authStore.dashboardMasjidId ?? masjidStore.masjid?.id;

onBeforeMount(async () => { await loadData(); });

const loadData = async () => {
    const id = masjidId();
    if (!id) return;
    loading.value = true;
    try {
        const res = await ApiService.get(`/api/admin/masjids/${id}/annual-statements?year=${year.value}` as any);
        if (res.data?.status === 'success') {
            donors.value = res.data.data.donors || [];
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load statements.' });
    } finally {
        loading.value = false;
    }
};

const sendOne = async (d: DonorRow) => {
    const id = masjidId();
    if (!id || !d.email) return;
    sendingId.value = d.contact_id;
    try {
        const res = await ApiService.post(`/api/admin/masjids/${id}/annual-statements/${d.contact_id}/send?year=${year.value}` as any, {});
        if (res.data?.status === 'success') {
            Swal.fire({ icon: 'success', title: 'Queued', text: `Statement queued for delivery to ${d.name}.` });
        } else {
            Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to queue statement.' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to queue statement.' });
    } finally {
        sendingId.value = null;
    }
};

const downloadPdf = async (d: DonorRow) => {
    const id = masjidId();
    if (!id) return;
    downloadingId.value = d.contact_id;
    try {
        // Blob fetch (not the JSON ApiService) so the Authorization header carries
        // and the browser downloads the PDF.
        const token = localStorage.getItem('MASJID_APP_AUTH_TOKEN');
        const resp = await fetch(`/api/admin/masjids/${id}/annual-statements/${d.contact_id}/pdf?year=${year.value}`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
        });
        if (!resp.ok) throw new Error('pdf');
        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${year.value}-statement-${d.name.replace(/[^A-Za-z0-9]+/g, '-')}.pdf`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not generate the PDF.' });
    } finally {
        downloadingId.value = null;
    }
};

const sendAll = async () => {
    const id = masjidId();
    if (!id) return;
    const confirm = await Swal.fire({
        icon: 'question',
        title: `Email all ${year.value} statements?`,
        text: `This queues a statement for every donor with an email on file.`,
        showCancelButton: true,
        confirmButtonText: 'Send all',
        confirmButtonColor: '#2f9e57',
    });
    if (!confirm.isConfirmed) return;

    sendingAll.value = true;
    try {
        const res = await ApiService.post(`/api/admin/masjids/${id}/annual-statements/send-all?year=${year.value}` as any, {});
        if (res.data?.status === 'success') {
            const { queued, skipped, failed } = res.data.data;
            Swal.fire({ icon: failed ? 'warning' : 'success', title: failed ? 'Some statements failed' : 'Done', text: `${queued} statement(s) queued, ${skipped} skipped (no email), ${failed} failed.` });
        } else {
            Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to queue statements.' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to queue statements.' });
    } finally {
        sendingAll.value = false;
    }
};

const formatCents = (cents: number, currency: string = 'usd'): string => {
    try {
        // Two decimals always, as the letter and the email print them: left to itself Intl
        // rounds a zero-decimal currency (JPY 10.50 would read 11).
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase(), minimumFractionDigits: 2, maximumFractionDigits: 2 }).format((cents ?? 0) / 100);
    } catch (e) {
        return `$${((cents ?? 0) / 100).toFixed(2)}`;
    }
};
</script>

<style scoped>
</style>
