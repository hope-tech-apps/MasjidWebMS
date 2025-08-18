<template>
    <div class="card">
        <div class="card-body d-flex flex-column gap-3">
            <h5 class="card-title">
                {{ event.title }}
            </h5>
            <div class="d-flex flex-column gap-1">
                <span class="fs-6">
                    {{ event.details }}
                </span>
                <span class="fs-6">
                    {{ event.place }}
                </span>
                <span class="fs-6">
                    {{ event.link }}
                </span>
            </div>
            <router-link :to="`/masjid/events/${event.id}`" class="btn btn-success">
                Details
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Event } from '@/core/types/data/masjid-related/Event';
import { PropType, toRefs } from 'vue';


const props = defineProps({
    event: {
        type: Object as PropType<Event>,
        required: true
    }
});

const { event } = toRefs(props);

</script>