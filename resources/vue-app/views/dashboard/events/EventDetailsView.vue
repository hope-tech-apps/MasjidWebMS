<template>
    <DataItemContainer title="Event Details" @edit-button-click="router.push(`/masjid/events/${route.params.event_id}/edit`)"
        @delete-button-click="deleteEvent" :show-archive="false">
        <div v-if="event" class="d-flex flex-column gap-4">
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Title
                </span>
                <span class="fs-6 m-0">
                    {{ event.title }}
                </span>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Date
                </span>
                <div class="d-flex flex-col flex-md-row gap-2">
                    <span class="fs-6 m-0">
                        <b>From: </b>{{ event.start }}
                    </span>
                    |
                    <span class="fs-6 m-0">
                        <b>To: </b>{{ event.end }}
                    </span>
                </div>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Details
                </span>
                <p class="fs-6 m-0">
                    {{ event.details }}
                </p>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Place
                </span>
                <span class="fs-6 m-0">
                    {{ event.place }}
                </span>
            </div>
            <div class="d-flex flex-column gap-1 event-attribute">
                <span class="fs-5 fw-bold text-muted text-capitalize">
                    Link
                </span>
                <span class="fs-6 m-0">
                    {{ event.link }}
                </span>
            </div>
        </div>
    </DataItemContainer>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import DataItemContainer from '@/components/DataItemContainer.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { Event } from '@/core/types/data/masjid-related/Event';
import { useEventsStore } from '@/stores/masjid/eventsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.event_id) {
        eventsStore.fetchEvent(route.params.event_id as string, event);
    } else {
        router.push('/masjid/events');
    }
});

// Routing
const router = useRouter()
const route = useRoute()

// Stores
const eventsStore = useEventsStore();
const masjidStore = useMasjidStore();

// Computed

// Custom constants
const event = ref<Event>();

// Functions
const deleteEvent = async () => {
    QSwal.fire("Warning", 'You are going to delete this event !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (event.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjidStore.masjid?.id}/events/${event.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Event deleted successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await eventsStore.fetchMasjidEventsPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/masjid/events`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.event-attribute {
    background-color: var(--cgreen-light);
    border-left: 5px solid var(--cgreen);
    padding: .5rem;
}
</style>