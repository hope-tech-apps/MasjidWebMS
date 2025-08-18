<template>
    <PageDataContainer title="Events List" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/masjid/events/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="row w-100">
                <div v-for="event in azkar" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <EventCard :event="event"></EventCard>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import EventCard from '@/components/data_cards/EventCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { useEventsStore } from '@/stores/masjid/eventsStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    await eventsStore.fetchMasjidEventsPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = eventsStore.eventsPaginated?.total ?? 0;
        paginationOptions.value.currentPage = eventsStore.eventsPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = eventsStore.eventsPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter()

// Stores
const eventsStore = useEventsStore();

// Computed
const azkar = computed(() => {
    return eventsStore.eventsPaginated?.data ?? []
});

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: azkar.value.length,
    currentPage: 0,
    perPage: 9
});

const pageChange = async (data: PageChangeData) => {
    // Bind Server pagination
    await eventsStore.fetchMasjidEventsPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = eventsStore.eventsPaginated?.total ?? 0;
        paginationOptions.value.currentPage = eventsStore.eventsPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = eventsStore.eventsPaginated?.per_page ?? 0;
    });
}

</script>