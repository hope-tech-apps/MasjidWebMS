<template>
    <PageDataContainer title="Hadith List" :paginationOptions="paginationOptions"
        @headerButtonClick="() => {router.push('/hadith/create')}"
        @pageChange="pageChange">
        <div class="container w-100">
            <div class="row w-100">
                <div v-for="hadith in hadiths" class="col-12 col-md-6 col-xl-4 mb-3 d-flex flex-column">
                    <HadithCard :hadith="hadith"></HadithCard>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import HadithCard from '@/components/data_cards/HadithCard.vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { Hadith } from '@/core/types/data/Hadith';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { useHadithStore } from '@/stores/hadithStore';
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    await hadithsStore.fetchHadithsPaginated(1).then(() => {
        paginationOptions.value.itemsTotal = hadithsStore.hadithsPaginated?.total ?? 0;
        paginationOptions.value.currentPage = hadithsStore.hadithsPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = hadithsStore.hadithsPaginated?.per_page ?? 0;
    });
});

// Routing
const router = useRouter()

// Stores
const hadithsStore = useHadithStore();
const dataToDisplay = ref<Array<Hadith|any>>([]);

// Computed
const hadiths = computed(() => {
    return hadithsStore.hadithsPaginated?.data ?? []
});

// Custom constants
const paginationOptions = ref<PaginationOptions>({
    itemsTotal: hadiths.value.length,
    currentPage: 0,
    perPage: 9
});

const pageChange = async (data: PageChangeData) => {
    // Bind Server pagination
    await hadithsStore.fetchHadithsPaginated(data.toPage).then(() => {
        paginationOptions.value.itemsTotal = hadithsStore.hadithsPaginated?.total ?? 0;
        paginationOptions.value.currentPage = hadithsStore.hadithsPaginated?.current_page ?? 0;
        paginationOptions.value.perPage = hadithsStore.hadithsPaginated?.per_page ?? 0;
    });
}

</script>