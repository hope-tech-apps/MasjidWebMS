<template>
    <PageDataContainer title="Splash Announcements" :paginationOptions="paginationOptions"
        @headerButtonClick="() => router.push('/masjid/splash-announcements/create')"
        @pageChange="pageChange">
        <div class="container w-100">
            <div v-if="splashes.length === 0" class="text-center text-muted py-5">
                No splash announcements yet. Create one to display a modal on the public site and a mobile in-app
                message to your users.
            </div>
            <div class="row w-100">
                <div v-for="splash in splashes" :key="splash.id"
                    class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <div class="card h-100 border-0 shadow-sm">
                        <img v-if="splash.image?.original_url" :src="splash.image.original_url" class="card-img-top"
                            :alt="splash.title" style="object-fit: cover; height: 180px;">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start justify-content-between mb-2">
                                <h5 class="card-title mb-0">{{ splash.title }}</h5>
                                <span :class="['badge', statusBadge(splash).klass]">{{ statusBadge(splash).label }}</span>
                            </div>
                            <p class="card-text text-muted small flex-grow-1">
                                {{ window(splash) }}
                            </p>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary"
                                    @click="router.push(`/masjid/splash-announcements/${splash.id}`)">View</button>
                                <button class="btn btn-sm btn-outline-secondary"
                                    @click="router.push(`/masjid/splash-announcements/${splash.id}/edit`)">Edit</button>
                                <button class="btn btn-sm btn-outline-danger ms-auto"
                                    @click="confirmDelete(splash)">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import PageDataContainer from '@/components/PageDataContainer.vue'
import { QSwal, MSwal } from '@/core/plugins/SweetAlerts2'
import { SplashAnnouncement } from '@/core/types/data/masjid-related/SplashAnnouncement'
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination'
import { useSplashAnnouncementsStore } from '@/stores/masjid/splashAnnouncementsStore'
import { computed, onBeforeMount, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const store = useSplashAnnouncementsStore()

const splashes = computed(() => store.splashesPaginated?.data ?? [])

const paginationOptions = ref<PaginationOptions>({
    itemsTotal: 0,
    currentPage: 0,
    perPage: 15,
})

onBeforeMount(async () => {
    await loadPage(1)
})

async function loadPage(page: number) {
    await store.fetchSplashesPaginated(page)
    paginationOptions.value.itemsTotal = store.splashesPaginated?.total ?? 0
    paginationOptions.value.currentPage = store.splashesPaginated?.current_page ?? 0
    paginationOptions.value.perPage = store.splashesPaginated?.per_page ?? 15
}

const pageChange = async (data: PageChangeData) => {
    await loadPage(data.toPage)
}

/**
 * Three states the admin cares about:
 *  - Live: is_active && now is between starts_at and ends_at
 *  - Scheduled: is_active && starts_at is in the future
 *  - Ended: is_active && ends_at is in the past
 *  - Disabled: !is_active
 */
function statusBadge(s: SplashAnnouncement): { label: string; klass: string } {
    if (!s.is_active) return { label: 'Disabled', klass: 'bg-secondary' }
    const now = Date.now()
    const start = new Date(s.starts_at).getTime()
    const end = new Date(s.ends_at).getTime()
    if (now < start) return { label: 'Scheduled', klass: 'bg-info text-dark' }
    if (now > end) return { label: 'Ended', klass: 'bg-light text-muted' }
    return { label: 'Live', klass: 'bg-success' }
}

function window(s: SplashAnnouncement): string {
    const fmt = (iso: string) => new Date(iso).toLocaleString()
    return `${fmt(s.starts_at)} → ${fmt(s.ends_at)}`
}

async function confirmDelete(s: SplashAnnouncement) {
    const r = await QSwal.fire('Delete?', `Delete "${s.title}" permanently?`, 'warning')
    if (!r.isConfirmed) return
    await store.deleteSplash(s.id)
    await loadPage(paginationOptions.value.currentPage || 1)
    MSwal.fire('Deleted', 'Splash announcement removed.', 'success')
}
</script>
