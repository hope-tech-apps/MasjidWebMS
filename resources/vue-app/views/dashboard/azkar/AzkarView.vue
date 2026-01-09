<template>
    <PageDataContainer title="Adhkar List" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/azkar/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="row w-100">
                <div v-for="zikr in azkar" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <ZikrCard :zikr="zikr"></ZikrCard>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import ZikrCard from '@/components/data_cards/ZikrCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { useAzkarStore } from '@/stores/azkarStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    await azkarStore.fetchAzkarPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = azkarStore.azkarPaginated?.total ?? 0;
        paginationOptions.value.currentPage = azkarStore.azkarPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = azkarStore.azkarPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter()

// Stores
const azkarStore = useAzkarStore();

// Computed
const azkar = computed(() => {
    return azkarStore.azkarPaginated?.data ?? []
});

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: azkar.value.length,
    currentPage: 0,
    perPage: 9
});

const pageChange = async (data: PageChangeData) => {
    // Bind Server pagination
    await azkarStore.fetchAzkarPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = azkarStore.azkarPaginated?.total ?? 0;
        paginationOptions.value.currentPage = azkarStore.azkarPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = azkarStore.azkarPaginated?.per_page ?? 0;
    });
}

</script>
