<template>
    <PageDataContainer title="Users List" @headerButtonClick="router.push('/dashboard/super/users/create')">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="table-responsive bg-white">
                        <table class="table m-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="th-border">Avatar</th>
                                    <th scope="col" class="th-border">Name</th>
                                    <th scope="col" class="th-border">Email</th>
                                    <th scope="col" class="th-border">Phone</th>
                                    <th scope="col" class="th-border">Type</th>
                                    <th scope="col" class="th-border">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="user in users">
                                    <tr class="border-0">
                                        <td class="border-0 align-middle">
                                            <div class="avatar-container">
                                                <img :src="user.avatar?.original_url" alt="icon" />
                                            </div>
                                        </td>
                                        <td class="border-0 fw-bold align-middle">
                                            {{ user.name }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ user.email }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ user.phone }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ user.type }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            <div class="d-flex align-items-start gap-2 flex-wrap">
                                                <router-link :to="`/dashboard/super/users/${user.id}`"
                                                    class="btn btn-sm btn-success">
                                                    Details
                                                </router-link>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import PageDataContainer from '@/components/PageDataContainer.vue';
import router from '@/router/router';
import { useAuthStore } from '@/stores/authStore';
import { useUsersStore } from '@/stores/super/usersStore';
import { computed, onBeforeMount } from 'vue';

// Lifecycle hooks
onBeforeMount(async () => {
    await usersStore.fetchUsersList();
});

// Stores
const usersStore = useUsersStore();
const authStore = useAuthStore();

// Custom constants

// Computed
const users = computed(() => {
    return usersStore.users;
});

// Function

</script>

<style scoped>
.th-border {
    border: none;
    border-bottom: 1px solid var(--input-border);
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

.avatar-container {
    width: 5rem;
    height: 5rem;
    border: 1px solid var(--input-border);
    border-radius: 50%;
    overflow: hidden;
    object-fit: contain;
    display: flex;
    align-items: center;
}

.avatar-container img {
    height: 100%;
}
</style>