<template>
    <div v-if="itemsTotal > 0" class="d-flex flex-row align-items-center justify-content-center gap-2">

        <!-- Previous button -->
        <button type="button" @click.prevent="pageChange((activePage - 1))"
            class="btn btn-icon btn-success rounded-1 text-center pagination-btn"
            :class="{'btn-alice-blue-success': (activePage <= 1)}"  :disabled="activePage <= 1">
            <i class="bi bi-arrow-left"></i>
        </button>

        <!-- hidden sign -->
        <span v-if="allowedRange[0] > 1" class="fs-6 fw-bold text-muted text-center">...</span>

        <!-- Pages buttons -->
        <button v-for="index in pagesNumber" type="button" @click.prevent="pageChange(index)"
            class="btn btn-icon btn-alice-blue-success rounded-1 text-center pagination-btn"
            :class="{ 'active': index == activePage, 'd-none': !allowedRange.includes(index) }">
            {{ index }}
        </button>

        <!-- hidden sign -->
        <span v-if="allowedRange[allowedRange.length - 1] < pagesNumber" class="fs-6 fw-bold text-muted text-center">...</span>

        <!-- Next button -->
        <button type="button" @click.prevent="pageChange((activePage + 1))"
            class="btn btn-icon btn-success rounded-1 text-center pagination-btn"
            :class="{'btn-alice-blue-success': (pagesNumber <= activePage)}" :disabled="pagesNumber <= activePage">
            <i class="bi bi-arrow-right"></i>
        </button>

    </div>
</template>

<script setup lang="ts">
import { PageChangeData, PaginationIndicies, PaginationOptions } from '@/core/types/elements/Pagination';
import { computed, onBeforeMount, PropType, ref, toRefs, watch } from 'vue';

// Props
const props = defineProps({
    options: {
        type: Object as PropType<PaginationOptions>,
        required: true
    }
});

// Emits
const emits = defineEmits(["pageChange"])

// Lifecycle hooks
onBeforeMount(() => {
    activePage.value = currentPage.value;
    pageChange(activePage.value);
});

const { itemsTotal, perPage, currentPage } = toRefs(props.options);
const activePage = ref<number>(0);

const pagesNumber = computed(() => {
    return Math.ceil(itemsTotal.value / perPage.value);
});

const allowedRange = computed(() => {

    let pagesRange = Array.from({length: pagesNumber.value}, (_, i) => i+1);
    let start = activePage.value - 4;
    let end = activePage.value + 1;

    if (start <= 0) {
        end = activePage.value + (-1 * start) + 1;
        start = end - 4;
    }

    if (end > pagesNumber.value) {
        end = pagesNumber.value;
    }
    
    return pagesRange.slice(start-1, end);

})

watch(() => currentPage.value, () => {
    activePage.value = currentPage.value
})

function pageChange (toPage: number) {
    
    if((toPage > 0) && (toPage <= pagesNumber.value)) {
        activePage.value = toPage;
    }

    let indicies: PaginationIndicies = {
        from: ((activePage.value - 1) * perPage.value),
        to: (activePage.value * perPage.value) - 1
    }

    let paginationData: PageChangeData = {
        indicies: indicies,
        toPage: toPage
    }

    emits("pageChange", paginationData);

}

</script>

<style scoped>
.pagination-btn {
    width: 2rem;
    height: 2rem;
    padding: 0;
}
</style>