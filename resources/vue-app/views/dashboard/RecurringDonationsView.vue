<template>
    <div>
        <PageDataContainer
            title="Recurring Donations"
            :paginationOptions="paginationOptions"
            :hideButton="true"
            @pageChange="pageChange"
        >
            <div class="container w-100">
                <!-- Filter -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label small text-muted mb-1">Status</label>
                        <select class="form-select" v-model="statusFilter">
                            <option value="">All statuses</option>
                            <option v-for="s in statuses" :key="s" :value="s" class="text-capitalize">{{ s }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>

                <div v-else-if="subscriptions.length === 0" class="text-center py-5 text-muted">
                    <i class="bi bi-calendar-heart fs-1 d-block mb-3"></i>
                    <p>No recurring donations yet</p>
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Donor</th>
                                <th>Amount</th>
                                <th>Fund</th>
                                <th>Charges</th>
                                <th>Started</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="sub in subscriptions" :key="sub.id">
                                <td>
                                    <span v-if="sub.contact">{{ sub.contact.first_name }} {{ sub.contact.last_name }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <strong>{{ formatCents(sub.charged_amount, sub.currency) }}</strong>
                                    <span class="text-muted small"> / {{ sub.interval }}</span>
                                </td>
                                <td>
                                    <span v-if="sub.fund">{{ sub.fund.name }}</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>{{ sub.donations_count ?? 0 }}</td>
                                <td>{{ formatDate(sub.created_at) }}</td>
                                <td>
                                    <span class="badge text-capitalize" :class="statusClass(sub.status)">{{ sub.status }}</span>
                                </td>
                                <td class="text-end">
                                    <button
                                        v-if="sub.status !== 'canceled'"
                                        class="btn btn-sm btn-outline-danger"
                                        :disabled="cancellingId === sub.id"
                                        @click="cancelSubscription(sub)"
                                        title="Cancel this recurring gift"
                                    >
                                        <span v-if="cancellingId === sub.id" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>Cancel</span>
                                    </button>
                                    <span v-else class="text-muted small">Canceled</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, onBeforeMount, computed, watch } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import ApiService from '@/core/services/ApiService';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import Swal from 'sweetalert2';

type SubStatus = 'pending' | 'active' | 'past_due' | 'canceled';

interface RecurringSubscription {
    id: number;
    charged_amount: number;
    currency: string;
    interval: string;
    status: SubStatus;
    created_at: string;
    donations_count?: number;
    fund?: { id: number; name: string } | null;
    contact?: { id: number; first_name: string; last_name: string } | null;
}

const authStore = useAuthStore();
const masjidStore = useMasjidStore();

const loading = ref(false);
const cancellingId = ref<number | null>(null);
const statusFilter = ref<SubStatus | ''>('');
const statuses: SubStatus[] = ['pending', 'active', 'past_due', 'canceled'];
const paginated = ref<any>(null);

const subscriptions = computed<RecurringSubscription[]>(() => (paginated.value?.data as RecurringSubscription[]) || []);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!paginated.value) return undefined;
    return {
        currentPage: paginated.value.current_page,
        itemsTotal: paginated.value.total,
        perPage: paginated.value.per_page,
    };
});

const masjidId = () => authStore.dashboardMasjidId ?? masjidStore.masjid?.id;

onBeforeMount(async () => { await loadData(); });
watch(statusFilter, async () => { await loadData(1); });

const loadData = async (page: number = 1) => {
    const id = masjidId();
    if (!id) return;
    loading.value = true;
    try {
        const status = statusFilter.value ? `&status=${statusFilter.value}` : '';
        const res = await ApiService.get(`/api/admin/masjids/${id}/recurring-donations?page=${page}${status}` as any);
        if (res.data?.status === 'success') paginated.value = res.data.data;
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to load recurring donations.' });
    } finally {
        loading.value = false;
    }
};

const pageChange = async (data: PageChangeData) => { await loadData(data.toPage); };

const cancelSubscription = async (sub: RecurringSubscription) => {
    const confirm = await Swal.fire({
        icon: 'warning',
        title: 'Cancel recurring donation?',
        text: 'No further charges will be made. This cannot be undone — the donor would need to set it up again.',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Keep it',
    });
    if (!confirm.isConfirmed) return;

    const id = masjidId();
    if (!id) return;
    cancellingId.value = sub.id;
    try {
        const res = await ApiService.post(`/api/admin/masjids/${id}/recurring-donations/${sub.id}/cancel` as any, {});
        if (res.data?.status === 'success') {
            sub.status = 'canceled';
            Swal.fire({ icon: 'success', title: 'Canceled', text: 'The recurring donation was canceled.' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Failed to cancel. Please try again.' });
    } finally {
        cancellingId.value = null;
    }
};

const formatCents = (cents: number, currency: string = 'usd'): string => {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format((cents ?? 0) / 100);
    } catch (e) {
        return `$${((cents ?? 0) / 100).toFixed(2)}`;
    }
};

const formatDate = (iso: string): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const statusClass = (status: SubStatus): string => {
    switch (status) {
        case 'active': return 'bg-success-subtle text-success';
        case 'pending': return 'bg-warning-subtle text-warning';
        case 'past_due': return 'bg-danger-subtle text-danger';
        case 'canceled': return 'bg-secondary-subtle text-secondary';
        default: return 'bg-light text-muted';
    }
};
</script>

<style scoped>
.card { border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
</style>
