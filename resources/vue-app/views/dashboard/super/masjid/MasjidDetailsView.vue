<template>
    <DataItemContainer title="Masjid Details"
        @edit-button-click="router.push(`/dashboard/super/masjids/${route.params.masjid_id}/edit`)" @delete-button-click="deleteMasjid"
        @archive-button-click="archiveMasjid">
        <div v-if="masjid" class="d-flex flex-column gap-5">
            <!-- Masjid Profile -->
            <div v-if="masjid" class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Main Profile
                </span>
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-start
                gap-5 w-100">
                    <div class="logo-container">
                        <img :src="masjid.logo?.original_url" alt="masjid-logo" class="logo">
                    </div>

                    <div class="d-flex flex-wrap gap-4 info-container">
                        <div v-for="key in PROFILE_ATTRIBUTES" class="d-flex flex-column gap-1">
                            <span class="fs-6 text-capitalize">
                                {{ key }}
                            </span>
                            <span class="fs-6 fw-semibold text-muted">
                                {{ masjid[key as keyof Masjid] }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Masjid Location Details -->
            <div v-if="masjid" class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Location Details
                </span>
                <div v-for="key in LOCATION_ATTRIBUTES" class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        {{ key }}
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid[key as keyof Masjid] }}
                    </span>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        Country
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid?.country?.name }}
                    </span>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        City
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid?.city?.name }}
                    </span>
                </div>
            </div>

            <!-- Masjid Admin Details -->
            <div v-if="masjid.admin" class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Admin Details
                </span>
                <div v-if="masjid.admin.avatar" class="admin-logo-container">
                    <img :src="masjid.admin.avatar.original_url" alt="masjid-admin-avatar" class="admin-logo">
                </div>
                <div v-for="key in ADMIN_ATTRIBUTES" class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        {{ key }}
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid.admin[key as keyof Admin] }}
                    </span>
                </div>
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
import { Admin } from '@/core/types/data/Admin';
import { Masjid } from '@/core/types/data/Masjid';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.masjid_id) {
        masjidsStore.fetchMasjid(route.params.masjid_id as string, masjid);
    } else {
        router.push('/dashboard/super/masjids');
    }
});

// Routing
const router = useRouter();
const route = useRoute();

// Stores
const masjidsStore = useMasjidsStore();

// Computed

// Custom constants
const masjid = ref<Masjid>();
const PROFILE_ATTRIBUTES = ['name', 'email', 'phone'];
const LOCATION_ATTRIBUTES = ['longitude', 'latitude', 'address'];
const ADMIN_ATTRIBUTES = ['name', 'email', 'phone', 'type'];

// Functions
// const getAttributeValues = (key: keyof Zikr, masjid: Zikr) => {
//     let text = '';
//     if (typeof masjid[key] === 'object') {
//         let obj = masjid[key] as TranslatableObject;
//         if (obj) {
//             text = `AR: ${obj.ar}<br />`;
//             text += `EN: ${obj.en}`;
//         }
//     } else {
//         text = masjid[key] + '';
//     }

//     return text;
// }

const deleteMasjid = async () => {
    QSwal.fire("Warning", 'You are going to delete this masjid !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjid.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Masjid deleted successfully.";
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
                            await masjidsStore.fetchMasjidsList().finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/dashboard/super/masjids`);
                                });
                            });
                        });
                }
            }
        })
}

const archiveMasjid = async () => {
    QSwal.fire("Warning", 'You are going to archive this masjid !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjid.value.id}/trash`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Masjid archived successfully.";
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
                            await masjidsStore.fetchMasjidsList().finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/dashboard/super/masjids`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.logo-container {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    overflow: hidden;
    /* background-color: bisque; */
    max-width: 100%;
    height: 8rem;
    object-fit: contain;
}

.logo {
    border-radius: .5rem;
    padding: 1rem;
    height: 100%;
}

.admin-logo-container {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    width: 7rem;
    max-height: 7rem;
    object-fit: cover;
}

.admin-logo {
    width: 100%;
    border-radius: .5rem;
    padding: 1rem;
}

.info-attribute {
    width: 6rem;
}

@media(max-width: 480px) {
    .info-attribute {
        width: 100%;
    }
}
</style>