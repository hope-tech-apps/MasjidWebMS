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
                        <span>{{ donors.length }} donor{{ donors.length === 1 ? '' : 's' }} · {{ year }}</span>
                        <strong>Total eligible: {{ formatCents(totalEligible) }}</strong>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Donor</th>
                                    <th>Email</th>
                                    <th class="text-center">Gifts</th>
                                    <th class="text-end">Total eligible</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in donors" :key="d.contact_id">
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
const totalEligible = ref(0);

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
            totalEligible.value = res.data.data.total_eligible || 0;
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
            Swal.fire({ icon: 'success', title: 'Sent', text: `Statement emailed to ${d.name}.` });
        } else {
            Swal.fire({ icon: 'warning', title: 'Not sent', text: res.data?.message || 'Could not send.' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to send statement.' });
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
        text: `This emails a statement to every donor with an email on file.`,
        showCancelButton: true,
        confirmButtonText: 'Send all',
        confirmButtonColor: '#2f9e57',
    });
    if (!confirm.isConfirmed) return;

    sendingAll.value = true;
    try {
        const res = await ApiService.post(`/api/admin/masjids/${id}/annual-statements/send-all?year=${year.value}` as any, {});
        if (res.data?.status === 'success') {
            const { queued, skipped } = res.data.data;
            Swal.fire({ icon: 'success', title: 'Done', text: `${queued} statement(s) queued${skipped ? `, ${skipped} skipped (no email)` : ''}.` });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to send statements.' });
    } finally {
        sendingAll.value = false;
    }
};

const formatCents = (cents: number, currency: string = 'usd'): string => {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format((cents ?? 0) / 100);
    } catch (e) {
        return `$${((cents ?? 0) / 100).toFixed(2)}`;
    }
};
</script>

<style scoped>
</style>
