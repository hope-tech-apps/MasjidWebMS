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
                <div v-if="masjid?.admin?.avatar" class="admin-logo-container">
                    <img :src="masjid?.admin?.avatar?.original_url" alt="masjid-admin-avatar" class="admin-logo">
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

            <!-- CRM Access (SuperAdmin-only screen; toggles the per-masjid CRM gate) -->
            <div class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    CRM Access
                </span>
                <div class="d-flex align-items-center gap-3 w-100">
                    <span class="fs-6 fw-semibold text-muted">
                        Enable the CRM (Member Directory, funds &amp; donations) for this masjid.
                    </span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input bg-danger" type="checkbox"
                            @click.prevent="toggleCrmAccess(!masjid.crm_enabled)"
                            :checked="masjid.crm_enabled ? true : false" />
                    </div>
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

const toggleCrmAccess = (enabled: boolean) => {
    QSwal.fire("Question", "Are you sure that you want to change CRM access for this masjid?", 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {

                    const apiRequestData = new URLSearchParams();
                    apiRequestData.append('enabled', enabled ? "1" : "0");

                    await ApiService.patch(`/api/admin/masjids/${masjid.value.id}/crm-access`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                // Reflect the new gate value on the locally loaded masjid.
                                if (masjid.value) masjid.value.crm_enabled = enabled;
                                swalInstance.title = "Success";
                                swalInstance.text = "CRM access updated successfully.";
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
                        .finally(() => {
                            MSwal.fire(swalInstance);
                        });
                } else {
                    MSwal.fire('Sorry', 'The masjid ID missed.', 'error');
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

.form-check-input,
.form-check-input:focus {
    width: 4rem;
    height: 2rem;
    border: none;
    --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23F3F8FB'/%3e%3ctext x='0' y='0.1' font-size='2.5' font-weight='bold' text-anchor='middle' alignment-baseline='middle' fill='black' style='font-family:Poppins, sans-serif;'%3eOff%3c/text%3e%3c/svg%3e");
}

.form-check-input:checked,
.form-check-input:checked:focus {
    --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23F3F8FB'/%3e%3ctext x='0' y='0.1' font-size='2.5' font-weight='bold' text-anchor='middle' alignment-baseline='middle' fill='black' style='font-family:Poppins, sans-serif;'%3eOn%3c/text%3e%3c/svg%3e");
    background-color: var(--cgreen) !important;
}
</style>
