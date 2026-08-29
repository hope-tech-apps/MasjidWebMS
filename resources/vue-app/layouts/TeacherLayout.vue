<template>
    <div class="min-vh-100 bg-light">
        <nav class="navbar navbar-expand bg-white border-bottom sticky-top">
            <div class="container-fluid px-3 px-lg-4">
                <router-link to="/teacher" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
                    <img v-if="school?.logo_url" :src="school.logo_url" alt="" width="32" height="32"
                         class="rounded" style="object-fit: cover;">
                    <img v-else :src="'/manara-icon.svg'" alt="" width="32" height="32">
                    <span class="fw-semibold text-dark">{{ school?.name || 'My Classes' }}</span>
                </router-link>

                <div class="ms-auto d-flex align-items-center gap-3">
                    <router-link to="/teacher"
                                 class="nav-link px-0 d-none d-sm-inline"
                                 :class="isClassesActive ? 'fw-semibold text-success' : 'text-muted'">
                        My Classes
                    </router-link>
                    <span v-if="teacherName" class="text-muted small d-none d-md-inline">{{ teacherName }}</span>
                    <button class="btn btn-sm btn-outline-secondary" :disabled="signingOut" @click="signOut">
                        <span v-if="signingOut" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Sign out</span>
                    </button>
                </div>
            </div>
        </nav>

        <main class="container py-4" style="max-width: 960px;">
            <router-view />
        </main>
    </div>
</template>

<script setup lang="ts">
import TeacherApiService from '@/core/services/TeacherApiService';
import { useAuthStore } from '@/stores/authStore';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

interface TeacherSchool {
    id: number;
    name: string;
    logo_url: string | null;
    org_type: string | null;
}

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const school = ref<TeacherSchool | null>(null);
const teacherName = ref('');
const signingOut = ref(false);

// The classes list is the shell's home; keep its nav pill lit while browsing it.
const isClassesActive = computed(() => route.path === '/teacher');

onMounted(async () => {
    // The header comes from the teacher's own self endpoint — the shell never
    // reaches into the admin masjid store, which a teacher token cannot read.
    try {
        const res = await TeacherApiService.get('/api/teacher/user');
        const data = res.data?.data ?? null;
        if (data) {
            school.value = data.masjid ?? null;
            teacherName.value = [data.first_name, data.last_name].filter(Boolean).join(' ');
        }
    } catch {
        // A failure here is not fatal to the shell — the classes screen shows its
        // own error, and a 401 is already handled by TeacherApiService.
        school.value = null;
    }
});

const signOut = async () => {
    signingOut.value = true;
    try {
        await TeacherApiService.post('/api/teacher/logout');
    } catch {
        // Sign out locally regardless of what the server says.
    } finally {
        authStore.removeAuth();
        signingOut.value = false;
        router.push('/auth/sign-in');
    }
};
</script>
