<template>
    <div class="card">
        <div class="card-body d-flex flex-column gap-3">
            <h5 class="card-title">
                {{ zikr.title.en }}
            </h5>
            <div class="d-flex flex-column gap-1">
                <span class="fs-6">
                    {{ zikr.pronunciation }}
                </span>
                <span class="fs-6">
                    {{ zikr.text.ar }}
                </span>
                <p class="fs-6">
                    {{ zikr.text.en }}
                </p>
            </div>
            <router-link :to="`/azkar/${zikr.id}`" class="btn btn-success">
                Details
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Zikr } from '@/core/types/data/Azkar';
import { PropType, toRefs } from 'vue';


const props = defineProps({
    zikr: {
        type: Object as PropType<Zikr>,
        required: true
    }
});

const { zikr } = toRefs(props);

</script>