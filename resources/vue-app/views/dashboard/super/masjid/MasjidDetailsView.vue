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

            <!-- Masjid Assistant (SuperAdmin-only; toggles the per-masjid AI assistant gate) -->
            <div class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Masjid Assistant
                </span>
                <div class="d-flex align-items-center gap-3 w-100">
                    <span class="fs-6 fw-semibold text-muted">
                        Let this masjid's admins use the AI assistant to manage their content.
                        It can only do what those admins are already permitted to do.
                    </span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input bg-danger" type="checkbox"
                            @click.prevent="toggleAssistantAccess(!masjid.assistant_enabled)"
                            :checked="masjid.assistant_enabled ? true : false" />
                    </div>
                </div>
            </div>

            <!-- Generate Apps (SuperAdmin-only; dispatches the provisioning pipeline) -->
            <div class="d-flex flex-column gap-3 w-100">
                <span class="fs-5 fw-semibold">
                    Generate Apps
                </span>
                <span class="fs-6 text-muted">
                    Dispatch the build pipeline to scaffold, build, and upload this masjid's
                    mobile apps. Pick the platforms and start — progress appears below.
                </span>

                <div class="d-flex flex-wrap align-items-center gap-4">
                    <div class="form-check m-0 d-flex align-items-center gap-2">
                        <input class="form-check-input gen-check m-0" type="checkbox" id="gen-ios"
                            v-model="platforms.ios" :disabled="generating" />
                        <label class="form-check-label fs-6" for="gen-ios">iOS</label>
                    </div>
                    <div class="form-check m-0 d-flex align-items-center gap-2">
                        <input class="form-check-input gen-check m-0" type="checkbox" id="gen-android"
                            v-model="platforms.android" :disabled="generating" />
                        <label class="form-check-label fs-6" for="gen-android">Android</label>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm"
                        :disabled="generating || (!platforms.ios && !platforms.android)"
                        @click="generateApps">
                        <span v-if="generating" class="spinner-border spinner-border-sm me-2"></span>
                        {{ generating ? 'Dispatching…' : 'Generate Apps' }}
                    </button>
                </div>

                <!-- Live status list (polled every few seconds). -->
                <div v-if="jobs.length" class="d-flex flex-column gap-2 w-100">
                    <div v-for="job in jobs" :key="job.job_id"
                        class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center
                        justify-content-between gap-2 job-row">
                        <div class="d-flex align-items-center gap-3">
                            <span class="fw-semibold text-capitalize job-platform">
                                {{ job.platform }}
                            </span>
                            <span class="badge" :class="statusBadgeClass(job.status)">
                                {{ job.status }}
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span v-if="job.detail" class="fs-6 text-muted job-detail">
                                {{ job.detail }}
                            </span>
                            <a v-if="job.artifact_url" :href="job.artifact_url" target="_blank"
                                rel="noopener noreferrer" class="fs-6">
                                View artifact
                            </a>
                        </div>
                    </div>
                </div>
                <span v-else class="fs-6 text-muted">
                    No provisioning jobs yet for this masjid.
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
import { Admin } from '@/core/types/data/Admin';
import { Masjid } from '@/core/types/data/Masjid';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.masjid_id) {
        masjidsStore.fetchMasjid(route.params.masjid_id as string, masjid);
        // Load any existing provisioning jobs and resume polling if some are
        // still in flight (e.g. a build started in a previous session).
        await fetchProvisioningJobs();
        if (hasActiveJobs()) startPolling();
    } else {
        router.push('/dashboard/super/masjids');
    }
});

// Stop the poll timer when leaving the screen so it never leaks.
onBeforeUnmount(() => stopPolling());

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

// ---- App provisioning (control plane) ----
type ProvisioningJob = {
    id: number;
    job_id: string;
    platform: 'ios' | 'android';
    status: string;
    detail: string | null;
    artifact_url: string | null;
    github_repo: string;
    created_at: string;
    updated_at: string;
};

// Platform selection for the "Generate Apps" action.
const platforms = ref<{ ios: boolean; android: boolean }>({ ios: false, android: false });
const generating = ref(false);
const jobs = ref<ProvisioningJob[]>([]);
// Statuses with no further updates coming — polling can stop once every job
// reaches one of these. (uploaded/built = success terminals, failed = failure.)
const TERMINAL_STATUSES = ['uploaded', 'built', 'failed'];
let pollTimer: ReturnType<typeof setInterval> | null = null;

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

const toggleAssistantAccess = (enabled: boolean) => {
    QSwal.fire("Question", "Are you sure that you want to change Masjid Assistant access for this masjid?", 'question')
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

                    await ApiService.patch(`/api/admin/masjids/${masjid.value.id}/assistant-access`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                if (masjid.value) masjid.value.assistant_enabled = enabled;
                                swalInstance.title = "Success";
                                swalInstance.text = "Masjid Assistant access updated successfully.";
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

// ---- App provisioning control plane ----

/** True while any job is still short of a terminal status. */
const hasActiveJobs = (): boolean =>
    jobs.value.some(j => !TERMINAL_STATUSES.includes(j.status));

/** Map a job status to a Bootstrap badge class. */
const statusBadgeClass = (status: string): string => {
    switch (status) {
        case 'failed':
            return 'bg-danger';
        case 'uploaded':
        case 'built':
            return 'bg-success';
        case 'queued':
        case 'dispatched':
            return 'bg-secondary';
        default: // scaffolding / building
            return 'bg-info text-dark';
    }
};

/** Fetch the latest provisioning jobs for the status panel. */
const fetchProvisioningJobs = async (): Promise<void> => {
    const id = route.params.masjid_id as string;
    if (!id) return;

    await ApiService.get(`/api/admin/masjids/${id}/provisioning-jobs`)
        .then(res => {
            if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                jobs.value = res.data.data as ProvisioningJob[];
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            // Non-fatal: the panel just keeps its last known state.
            console.error('Fetch provisioning jobs error:', e);
        });
};

const startPolling = (): void => {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
        await fetchProvisioningJobs();
        if (!hasActiveJobs()) stopPolling();
    }, 4000);
};

const stopPolling = (): void => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

/** Dispatch the provisioning pipeline for the selected platforms. */
const generateApps = async (): Promise<void> => {
    const id = masjid.value?.id ?? (route.params.masjid_id as string);
    if (!id) {
        MSwal.fire('Sorry', 'The masjid ID is missing.', 'error');
        return;
    }
    if (!platforms.value.ios && !platforms.value.android) {
        MSwal.fire('Info', 'Select at least one platform.', 'info');
        return;
    }

    const chosen: string[] = [];
    if (platforms.value.ios) chosen.push('ios');
    if (platforms.value.android) chosen.push('android');

    const result = await QSwal.fire(
        'Question',
        `Dispatch the build pipeline for: ${chosen.join(', ')}?`,
        'question'
    );
    if (!result.isConfirmed) return;

    // POST goes out as multipart/form-data (ApiService default); the array is
    // sent as platforms[] so Laravel re-parses it into an array.
    const body = new FormData();
    chosen.forEach(p => body.append('platforms[]', p));

    generating.value = true;

    let swalInstance: SweetAlertOptions = { title: 'Info', text: 'Nothing', icon: 'info' };

    await ApiService.post(`/api/admin/masjids/${id}/provision-apps`, body)
        .then(res => {
            if (res.data?.status === 'success') {
                swalInstance.title = 'Success';
                swalInstance.text = 'Provisioning dispatched. Watch the status below.';
                swalInstance.icon = 'success';
            } else {
                swalInstance.title = 'Sorry';
                swalInstance.text = getMessageFromObj(res);
                swalInstance.icon = 'warning';
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            console.error(e);
            swalInstance.title = e.message;
            swalInstance.text = getMessageFromObj(e);
            swalInstance.icon = 'error';
        })
        .finally(async () => {
            generating.value = false;
            await fetchProvisioningJobs();
            if (hasActiveJobs()) startPolling();
            MSwal.fire(swalInstance);
        });
};

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

/* Plain multi-select checkboxes for the Generate Apps platform picker — reset
   the switch sizing the rules above impose on every .form-check-input here. */
.gen-check.form-check-input,
.gen-check.form-check-input:focus {
    width: 1.15rem;
    height: 1.15rem;
    border: 1px solid var(--input-border);
    border-radius: .25rem;
}

.gen-check.form-check-input:checked,
.gen-check.form-check-input:checked:focus {
    background-color: var(--cgreen) !important;
    border-color: var(--cgreen);
}

.job-row {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    padding: .75rem 1rem;
}

.job-platform {
    min-width: 4rem;
}

.job-detail {
    word-break: break-word;
}
</style>
