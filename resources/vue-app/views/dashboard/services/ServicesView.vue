<template>
    <PageDataContainer title="Services" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/masjid/services/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="row w-100">
                <div v-for="service in services" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <ServiceCard :service="service"></ServiceCard>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import ServiceCard from '@/components/data_cards/ServiceCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Announcement } from '@/core/types/data/masjid-related/Announcement';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { useServicesStore } from '@/stores/masjid/servicesStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    await servicesStore.fetchMasjidServicesPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = servicesStore.servicesPaginated?.total ?? 0;
        paginationOptions.value.currentPage = servicesStore.servicesPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = servicesStore.servicesPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter()

// Stores
const servicesStore = useServicesStore();
const dataToDisplay = ref<Array<Announcement|any>>([]);

// Computed
const services = computed(() => {
    return servicesStore.servicesPaginated?.data ?? []
})

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: services.value.length,
    currentPage: 0,
    perPage: 9
});

const pageChange = async (data: PageChangeData) => {
    // // Local pagination
    // if(data.indicies) {
    //     dataToDisplay.value = services.value.slice(data.indicies.from, data.indicies.to+1);
    // }

    // Server pagination
    await servicesStore.fetchMasjidServicesPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = servicesStore.servicesPaginated?.total ?? 0;
        paginationOptions.value.currentPage = servicesStore.servicesPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = servicesStore.servicesPaginated?.per_page ?? 0;
    });
}

</script>