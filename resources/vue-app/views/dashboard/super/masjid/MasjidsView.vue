<template>
    <PageDataContainer title="Masjids List" @headerButtonClick="router.push('/dashboard/super/masjids/create')">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="table-responsive bg-white">
                        <table class="table m-0">
                            <thead>
                                <tr>
                                    <th scope="col" class="th-border">Logo</th>
                                    <th scope="col" class="th-border">Name</th>
                                    <th scope="col" class="th-border">Email</th>
                                    <th scope="col" class="th-border">Admin Name</th>
                                    <th scope="col" class="th-border">Admin Email</th>
                                    <th scope="col" class="th-border">Address</th>
                                    <th scope="col" class="th-border">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="masjid in masjids">
                                    <tr class="border-0">
                                        <td class="border-0 align-middle">
                                            <div class="logo-container">
                                                <img :src="masjid.logo?.original_url" alt="icon" />
                                            </div>
                                        </td>
                                        <td class="border-0 fw-bold align-middle">
                                            {{ masjid.name }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ masjid.email }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ masjid.admin?.name }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ masjid.admin?.email }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            {{ masjid.address }}
                                        </td>
                                        <td class="border-0 align-middle">
                                            <div class="d-flex align-items-start gap-2 flex-wrap">
                                                <router-link :to="`/dashboard/super/masjids/${masjid.id}`"
                                                    class="btn btn-sm btn-success">
                                                    Details
                                                </router-link>
                                                <button type="button" @click.prevent="toMasjidDashboard(masjid.id)"
                                                    class="btn btn-sm btn-light-success">
                                                    Dashboard
                                                </button>
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
import { LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import router from '@/router/router';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { computed, onBeforeMount } from 'vue';

// Lifecycle hooks
onBeforeMount(async () => {
    await masjidsStore.fetchMasjidsList();
});

// Stores
const masjidsStore = useMasjidsStore();
const masjidStore = useMasjidStore();
const authStore = useAuthStore();

// Custom constants

// Computed
const masjids = computed(() => {
    return masjidsStore.masjids;
});

// Function
const toMasjidDashboard = async (id: number) => {
    await masjidStore.fetchMasjid(id).finally(async () => {
        authStore.dashboardMasjidId = id;
        localStorage.setItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id, id + '');
        await router.push('/masjid');
    });
}

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

.logo-container {
    width: 5rem;
    height: 5rem;
    border: 1px solid var(--input-border);
    border-radius: 50%;
    overflow: hidden;
    object-fit: contain;
    display: flex;
    align-items: center;
}

.logo-container img {
    width: 100%;
}
</style>