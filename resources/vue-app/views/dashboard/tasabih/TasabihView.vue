<template>
    <PageDataContainer title="Tasabih List" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/tasabih/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="row w-100">
                <div v-for="tasbih in azkar" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <TasbihCard :tasbih="tasbih"></TasbihCard>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import TasbihCard from '@/components/data_cards/TasbihCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { useTasabihStore } from '@/stores/tasabihStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    await tasabihStore.fetchTasabihPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = tasabihStore.tasabihPaginated?.total ?? 0;
        paginationOptions.value.currentPage = tasabihStore.tasabihPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = tasabihStore.tasabihPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter()

// Stores
const tasabihStore = useTasabihStore();

// Computed
const azkar = computed(() => {
    return tasabihStore.tasabihPaginated?.data ?? []
});

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: azkar.value.length,
    currentPage: 0,
    perPage: 9
});

const pageChange = async (data: PageChangeData) => {
    // Bind Server pagination
    await tasabihStore.fetchTasabihPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = tasabihStore.tasabihPaginated?.total ?? 0;
        paginationOptions.value.currentPage = tasabihStore.tasabihPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = tasabihStore.tasabihPaginated?.per_page ?? 0;
    });
}

</script>