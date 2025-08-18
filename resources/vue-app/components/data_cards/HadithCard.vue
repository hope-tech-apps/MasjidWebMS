<template>
    <div class="card">
        <div class="card-body d-flex flex-column">
            <h5 class="card-title">
                {{ hadith.title }}
            </h5>
            <p class="card-text text-semibold">
                <span>{{ hadith.isnad }}</span>
                <br />
                <span>{{ hadith.matn }}</span>
            </p>
            <router-link :to="`/hadith/${hadith.id}`" class="btn btn-success">
                Details
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Hadith } from '@/core/types/data/Hadith';
import { PropType, toRefs } from 'vue';


const props = defineProps({
    hadith: {
        type: Object as PropType<Hadith>,
        required: true
    }
});

const { hadith } = toRefs(props);

</script>