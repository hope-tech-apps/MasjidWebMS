<template>
    <div class="card">
        <div class="card-body d-flex flex-column gap-3">
            <h5 class="card-title">
                {{ tasbih.text.ar }}
            </h5>
            <div class="d-flex flex-column gap-1">
                <span class="fs-6">
                    {{ tasbih.pronunciation }}
                </span>
                <span class="fs-6">
                    {{ tasbih.text.en }}
                </span>
            </div>
            <router-link :to="`/tasabih/${tasbih.id}`" class="btn btn-success">
                Details
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Tasbih } from '@/core/types/data/Tasabih';
import { PropType, toRefs } from 'vue';


const props = defineProps({
    tasbih: {
        type: Object as PropType<Tasbih>,
        required: true
    }
});

const { tasbih } = toRefs(props);

</script>