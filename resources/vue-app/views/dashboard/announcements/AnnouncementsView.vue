<template>
    <PageDataContainer title="Announcements" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/masjid/announcements/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="row w-100">
                <div v-for="announcement in announcements" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <AnnouncementCard :announcement="announcement"></AnnouncementCard>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import AnnouncementCard from '@/components/data_cards/AnnouncementCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Announcement } from '@/core/types/data/masjid-related/Announcement';
import { PageChangeData, PaginationIndicies, PaginationOptions } from '@/core/types/elements/Pagination';
import { useAnnouncementsStore } from '@/stores/masjid/announcementsStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    await announcementsStore.fetchMasjidAnnouncementsPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = announcementsStore.announcementsPaginated?.total ?? 0;
        paginationOptions.value.currentPage = announcementsStore.announcementsPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = announcementsStore.announcementsPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter()

// Stores
const announcementsStore = useAnnouncementsStore();

// Computed
const announcements = computed(() => {
    return announcementsStore.announcementsPaginated?.data ?? []
})

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: announcements.value.length,
    currentPage: 0,
    perPage: 9
});

const pageChange = async (data: PageChangeData) => {
    // Bind Server pagination
    await announcementsStore.fetchMasjidAnnouncementsPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = announcementsStore.announcementsPaginated?.total ?? 0;
        paginationOptions.value.currentPage = announcementsStore.announcementsPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = announcementsStore.announcementsPaginated?.per_page ?? 0;
    });
}

</script>