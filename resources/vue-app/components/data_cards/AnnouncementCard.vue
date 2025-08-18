<template>
    <div class="card flex-fill announcement-card">
        <div class="card-body d-flex flex-column gap-2 justify-content-between">
            <div class="d-flex flex-column gap-2">
                <div class="announcement-image-container">
                    <img :src="announcement.image?.original_url" class="announcement-image">
                    <div class="announcement-date">
                        <span class="text-center w-100 fs-7 fw-semibold">
                            {{ announcement.end_date }}
                        </span>
                    </div>
                </div>
                <h5 class="card-title m-0">{{ announcement.title }}</h5>
                <p class="card-text m-0 text-muted fs-7 announcement-details">
                    {{ announcement.details }}
                </p>
            </div>
            <router-link :to="`/masjid/announcements/${announcement.id}`"
                class="text-decoration-none text-success fw-semibold">
                Read More
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Announcement } from '@/core/types/data/masjid-related/Announcement';
import { PropType, toRefs } from 'vue';


const props = defineProps({
    announcement: {
        type: Object as PropType<Announcement>,
        required: true
    }
})

const { announcement } = toRefs(props);

</script>

<style scoped>
/* .announcement-card {
    display: flex;
    flex-direction: column;
    justify-content: stretch !important;
} */

.announcement-image-container {
    width: 100%;
    height: 13rem;
    border-radius: .5rem;
    overflow: hidden;
    margin-bottom: 1rem;
    position: relative;
}

.announcement-image-container .announcement-date {
    background-color: white;
    position: absolute;
    top: .75rem;
    left: .75rem;
    padding: .15rem .25rem;
    border-radius: .25rem;
}

.announcement-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: .5rem;
}

.announcement-details {
    height: 10rem;
    overflow: hidden;
    white-space: normal;
    text-overflow: ellipsis;
}
</style>