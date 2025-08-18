<template>
    <div class="card flex-fill service-card">
        <div class="card-body d-flex flex-column gap-2 align-items-center justify-content-between">
            <div class="d-flex flex-column align-items-center justify-content-center gap-2">
                <div class="service-icon-container">
                    <img :src="service.icon?.original_url" class="service-icon">
                </div>
                <h5 class="card-title m-0">{{ service.title }}</h5>
                <p class="card-text m-0 text-muted fs-7 service-description">
                    {{ service.description }}
                </p>
            </div>
            <router-link :to="`/masjid/services/${service.id}`"
                class="text-decoration-none btn btn-success fw-semibold">
                Read More
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Service } from '@/core/types/data/masjid-related/Service';
import { PropType, toRefs } from 'vue';


const props = defineProps({
    service: {
        type: Object as PropType<Service>,
        required: true
    }
})

const { service } = toRefs(props);

</script>

<style scoped>
/* .service-card {
    display: flex;
    flex-direction: column;
    justify-content: stretch !important;
} */

.service-icon-container {
    width: 5rem;
    height: 5rem;
    border-radius: .5rem;
    overflow: hidden;
    margin-bottom: 1rem;
    position: relative;
    padding: .5rem;
}

.service-icon-container .service-date {
    background-color: white;
    position: absolute;
    top: .75rem;
    left: .75rem;
    padding: .15rem .25rem;
    border-radius: .25rem;
}

.service-icon {
    height: 100% !important;
    object-fit: cover;
    border-radius: .5rem;
}

.service-description {
    height: 6rem;
    overflow: hidden;
    white-space: normal;
    text-overflow: ellipsis;
}
</style>