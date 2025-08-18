<template>
    <DataItemContainer title="User Details"
        @edit-button-click="router.push(`/dashboard/super/users/${route.params.user_id}/edit`)"
        @delete-button-click="deleteUser" @archive-button-click="archiveUser">
        <div v-if="user" class="d-flex flex-column gap-5">
            <!-- User Profile -->
            <div v-if="user" class="d-flex flex-column gap-3 w-100">
                <span class="fs-5 fw-semibold">
                    Main Profile
                </span>
                <div class="d-flex flex-column align-items-start justify-content-start
                    gap-4 w-100 profile-info">
                    <div class="avatar-container">
                        <img :src="user.avatar?.original_url" alt="user-avatar" class="avatar">
                    </div>
                    <!-- User Location Details -->
                    <div v-if="user" class="d-flex flex-column gap-2 w-100">
                        <div v-for="key in PROFILE_ATTRIBUTES" class="d-flex flex-column flex-sm-row gap-1 w-100">
                            <span class="fs-6 text-capitalize info-attribute">
                                {{ key }}
                            </span>
                            <span class="fs-6 fw-semibold text-muted w-100">
                                {{ user[key as keyof User] }}
                            </span>
                        </div>
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
import { User } from '@/core/types/data/User';
import { useUsersStore } from '@/stores/super/usersStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { onBeforeMount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params?.user_id) {
        usersStore.fetchUser(route.params.user_id as string, user);
    } else {
        router.push('/dashboard/super/users');
    }
});

// Routing
const router = useRouter();
const route = useRoute();

// Stores
const usersStore = useUsersStore();

// Computed

// Custom constants
const user = ref<User>();
const PROFILE_ATTRIBUTES = ['name', 'email', 'phone', 'type'];

// Functions
const deleteUser = async () => {
    QSwal.fire("Warning", 'You are going to delete this user !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (user.value?.id) {
                    await ApiService.delete(`/api/admin/users/${user.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "User deleted successfully.";
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
                            await usersStore.fetchUsersList().finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/dashboard/super/users`);
                                });
                            });
                        });
                }
            }
        })
}

const archiveUser = async () => {
    QSwal.fire("Warning", 'You are going to archive this user !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (user.value?.id) {
                    await ApiService.delete(`/api/admin/users/${user.value.id}/trash`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "User archived successfully.";
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
                            await usersStore.fetchUsersList().finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/dashboard/super/users`);
                                });
                            });
                        });
                }
            }
        })
}

</script>

<style scoped>
.avatar-container {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    max-width: 100%;
    height: 8rem;
    object-fit: cover;
    overflow: hidden;
}

.avatar {
    height: 100%;
    border-radius: .5rem;
}

.admin-avatar-container {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    width: 7rem;
    max-height: 7rem;
    object-fit: cover;
}

.admin-avatar {
    width: 100%;
    border-radius: .5rem;
    padding: 1rem;
}

.info-attribute {
    width: 6rem;
}

.profile-info {
    border-left: 8px solid var(--cgreen);
    padding: .25rem 1rem;
    border-radius: .5rem;
}

@media(max-width: 480px) {
    .info-attribute {
        width: 100%;
    }
}
</style>