<template>
    <PageDataContainer title="Services" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/masjid/services/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="mb-3">
                <label class="form-label small text-muted mb-1" for="services-status">Status</label>
                <select id="services-status" class="form-select w-auto" v-model="status" :disabled="restoring !== null" @change="load(1)">
                    <option value="current">Current</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <p v-if="notice" class="alert alert-success" role="status">{{ notice }}</p>
            <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
            <p v-if="loading" role="status">Loading...</p>
            <p v-else-if="!error && !services.length" class="text-muted">{{ status === 'archived' ? 'No archived services.' : 'No services.' }}</p>
            <div v-if="!loading" class="row w-100">
                <div v-for="service in services" :key="service.id" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <ServiceCard :service="service"></ServiceCard>
                    <div v-if="status === 'archived'" class="mt-2">
                        <p class="small text-muted mb-2">Archived on <time :datetime="service.deleted_at || undefined">{{ archiveDate(service.deleted_at) }}</time></p>
                        <button type="button" class="btn btn-outline-success" :disabled="restoring !== null" @click="restore(service)">Restore</button>
                    </div>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import ServiceCard from '@/components/data_cards/ServiceCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Service } from '@/core/types/data/masjid-related/Service';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import ApiService from '@/core/services/ApiService';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { useMasjidStore } from '@/stores/masjidStore';
import { onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const masjidStore = useMasjidStore();
const status = ref('current');
const services = ref<Service[]>([]);
const loading = ref(false);
const restoring = ref<number | null>(null);
const error = ref('');
const notice = ref('');
const paginationOptions = ref<PaginationOptions>({ itemsTotal: 0, currentPage: 1, perPage: 9 });
let request = 0;
let loadedFor: number | undefined;

async function load(page = 1) {
    const orgId = masjidStore.masjid?.id;
    const currentRequest = ++request;
    loadedFor = undefined;
    services.value = [];
    Object.assign(paginationOptions.value, { itemsTotal: 0, currentPage: 1, perPage: 9 });
    error.value = '';
    loading.value = false;
    if (!orgId) return;
    loading.value = true;
    const suffix = status.value === 'archived' ? '/archived' : '';
    try {
        const res = await ApiService.get(`/api/admin/masjids/${orgId}/services${suffix}?page=${page}`);
        if (currentRequest !== request || orgId !== masjidStore.masjid?.id) return;
        if (res.data?.status !== 'success' || !res.data?.data) throw new Error('Invalid list response');
        const result = res.data.data;
        services.value = result.data;
        Object.assign(paginationOptions.value, { itemsTotal: result.total, currentPage: result.current_page, perPage: result.per_page });
        loadedFor = orgId;
    } catch (e: any) {
        if (currentRequest === request && orgId === masjidStore.masjid?.id) {
            error.value = e.response?.data?.message || 'Could not load services. Please try again.';
        }
    } finally {
        if (currentRequest === request) loading.value = false;
    }
}

const pageChange = (data: PageChangeData) => load(data.toPage);

function archiveDate(date: string | null): string {
    return date ? new Date(date).toLocaleString() : '';
}

async function restore(service: Service) {
    if (restoring.value !== null || loading.value) return;
    const orgId = loadedFor;
    if (!orgId || orgId !== masjidStore.masjid?.id) return;
    restoring.value = service.id;
    error.value = '';
    notice.value = '';
    try {
        const result = await QSwal.fire({
            title: 'Restore service?',
            text: 'Restore this service? It becomes available to the public website and apps immediately. Cached pages and apps may take time to refresh. Its media and original list position are kept.',
            icon: 'warning', confirmButtonText: 'Restore', cancelButtonText: 'Cancel',
        });
        // A dialog may stay open while the selected organisation or list changes.
        if (!result.isConfirmed || orgId !== masjidStore.masjid?.id || loadedFor !== orgId) return;
        const res = await ApiService.post(`/api/admin/masjids/${orgId}/services/${service.id}/restore`, {});
        if (orgId !== masjidStore.masjid?.id) return;
        if (res.data?.status !== 'success') throw new Error('Restore failed');
        notice.value = 'Service restored successfully.';
        status.value = 'current';
        await load(1);
    } catch (e: any) {
        if (orgId === masjidStore.masjid?.id) {
            error.value = e.response?.data?.message || 'Could not restore service. Please try again.';
        }
    } finally {
        restoring.value = null;
    }
}

watch(() => masjidStore.masjid?.id, () => {
    notice.value = '';
    status.value = 'current';
    void load(1);
}, { immediate: true });
onBeforeUnmount(() => { ++request; loadedFor = undefined; });
</script>
