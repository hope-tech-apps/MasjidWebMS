<template>
    <PageDataContainer title="Announcements" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/masjid/announcements/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="mb-3">
                <label class="form-label small text-muted mb-1" for="announcements-status">Status</label>
                <select id="announcements-status" class="form-select w-auto" v-model="status" :disabled="restoring !== null" @change="load(1)">
                    <option value="current">Current</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <p v-if="notice" class="alert alert-success" role="status">{{ notice }}</p>
            <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
            <p v-if="loading" role="status">Loading...</p>
            <p v-else-if="!error && !announcements.length" class="text-muted">{{ status === 'archived' ? 'No archived announcements.' : 'No announcements.' }}</p>
            <div v-if="!loading" class="row w-100">
                <div v-for="announcement in announcements" :key="announcement.id" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <AnnouncementCard :announcement="announcement"></AnnouncementCard>
                    <p v-if="isExpired(announcement)" class="small text-warning mb-2">Expired — dates unchanged.</p>
                    <div v-if="status === 'archived'" class="mt-2">
                        <p class="small text-muted mb-2">Archived on <time :datetime="announcement.deleted_at || undefined">{{ archiveDate(announcement.deleted_at) }}</time></p>
                        <button type="button" class="btn btn-outline-success" :disabled="restoring !== null" @click="restore(announcement)">Restore</button>
                    </div>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import AnnouncementCard from '@/components/data_cards/AnnouncementCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Announcement } from '@/core/types/data/masjid-related/Announcement';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import ApiService from '@/core/services/ApiService';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { useMasjidStore } from '@/stores/masjidStore';
import { onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const masjidStore = useMasjidStore();
const status = ref('current');
const announcements = ref<Announcement[]>([]);
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
    announcements.value = [];
    Object.assign(paginationOptions.value, { itemsTotal: 0, currentPage: 1, perPage: 9 });
    error.value = '';
    loading.value = false;
    if (!orgId) return;
    loading.value = true;
    const suffix = status.value === 'archived' ? '/archived' : '';
    try {
        const res = await ApiService.get(`/api/admin/masjids/${orgId}/announcements${suffix}?page=${page}`);
        if (currentRequest !== request || orgId !== masjidStore.masjid?.id) return;
        if (res.data?.status !== 'success' || !res.data?.data) throw new Error('Invalid list response');
        const result = res.data.data;
        announcements.value = result.data;
        Object.assign(paginationOptions.value, { itemsTotal: result.total, currentPage: result.current_page, perPage: result.per_page });
        loadedFor = orgId;
    } catch (e: any) {
        if (currentRequest === request && orgId === masjidStore.masjid?.id) {
            error.value = e.response?.data?.message || 'Could not load announcements. Please try again.';
        }
    } finally {
        if (currentRequest === request) loading.value = false;
    }
}

const pageChange = (data: PageChangeData) => load(data.toPage);

function archiveDate(date: string | null): string {
    return date ? new Date(date).toLocaleString() : '';
}

function isExpired(announcement: Announcement): boolean {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: masjidStore.masjid?.timezone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date());
    return !!announcement.end_date && announcement.end_date.slice(0, 10) < today;
}

async function restore(announcement: Announcement) {
    if (restoring.value !== null || loading.value) return;
    const orgId = loadedFor;
    if (!orgId || orgId !== masjidStore.masjid?.id) return;
    restoring.value = announcement.id;
    error.value = '';
    notice.value = '';
    try {
        const result = await QSwal.fire({
            title: 'Restore announcement?',
            text: 'Restore this announcement? It becomes available to the public website and app feeds immediately. Cached pages and apps may take time to refresh. Its dates are unchanged: expired announcements stay expired and stay off date-filtered displays. No broadcast, push, email or SMS is sent.',
            icon: 'warning', confirmButtonText: 'Restore', cancelButtonText: 'Cancel',
        });
        // A dialog may stay open while the selected organisation or list changes.
        if (!result.isConfirmed || orgId !== masjidStore.masjid?.id || loadedFor !== orgId) return;
        const res = await ApiService.post(`/api/admin/masjids/${orgId}/announcements/${announcement.id}/restore`, {});
        if (orgId !== masjidStore.masjid?.id) return;
        if (res.data?.status !== 'success') throw new Error('Restore failed');
        notice.value = 'Announcement restored successfully.';
        status.value = 'current';
        await load(1);
    } catch (e: any) {
        if (orgId === masjidStore.masjid?.id) {
            error.value = e.response?.data?.message || 'Could not restore announcement. Please try again.';
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
