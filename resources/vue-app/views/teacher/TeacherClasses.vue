<template>
    <div>
        <h1 class="h4 mb-1">My Classes</h1>
        <p class="text-muted small mb-4">The classes you teach. Tap one to grade, post, and reply.</p>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-success"></span>
        </div>

        <div v-else-if="error" class="alert alert-danger">
            {{ error }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
        </div>

        <div v-else-if="!groups.length" class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <p class="mb-1 fw-semibold">No classes assigned yet</p>
                <p class="text-muted small mb-0">
                    The school office has not assigned you to a class yet. If you think that
                    is wrong, contact an administrator — enrolment is managed by the office.
                </p>
            </div>
        </div>

        <div v-else class="d-flex flex-column gap-3">
            <router-link v-for="group in groups" :key="group.id"
                         :to="`/teacher/classes/${group.id}`"
                         class="card border-0 shadow-sm text-decoration-none text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h2 class="h6 mb-0">{{ group.name }}</h2>
                                <span v-if="group.kind" class="badge bg-light text-dark border text-capitalize">
                                    {{ group.kind }}
                                </span>
                                <span v-if="group.is_active === false"
                                      class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            </div>
                            <p v-if="group.description" class="text-muted small mb-2">{{ group.description }}</p>

                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="d-inline-flex align-items-center">
                                    <PersonAvatar v-for="s in (group.students || []).slice(0, 6)"
                                                  :key="s.membership_id"
                                                  :avatar="s.contact?.avatar"
                                                  :first-name="s.contact?.first_name"
                                                  :last-name="s.contact?.last_name"
                                                  :size="30"
                                                  class="avatar-stack-item" />
                                </span>
                                <span class="text-muted small">
                                    {{ studentCount(group) }} student{{ studentCount(group) === 1 ? '' : 's' }}
                                </span>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>
                </div>
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import TeacherApiService, { rowsOf } from '@/core/services/TeacherApiService';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import { useAuthStore } from '@/stores/authStore';
import { computed, onMounted, ref } from 'vue';

interface TeacherStudent {
    membership_id: number;
    contact?: { id?: number; first_name?: string; last_name?: string; avatar?: any } | null;
}
interface TeacherClass {
    id: number;
    name: string;
    kind?: string;
    description?: string;
    is_active?: boolean;
    arabic_stage?: string | null;
    students?: TeacherStudent[];
}

const authStore = useAuthStore();
const masjidId = computed(() => authStore.dashboardMasjidId ?? 0);

const groups = ref<TeacherClass[]>([]);
const loading = ref(true);
const error = ref('');

const studentCount = (group: TeacherClass) => (group.students?.length ?? 0);

const load = async () => {
    loading.value = true;
    error.value = '';
    try {
        const res = await TeacherApiService.get(`/api/teacher/masjids/${masjidId.value}/groups`);
        groups.value = rowsOf(res.data?.data) as TeacherClass[];
    } catch {
        // A 401 is already handled centrally (redirect to sign-in); anything else
        // is shown here with a retry.
        error.value = 'We could not load your classes just now. Please try again.';
    } finally {
        loading.value = false;
    }
};

onMounted(load);
</script>

<style scoped>
.avatar-stack-item {
    margin-right: -8px;
    box-shadow: 0 0 0 2px #fff;
    border-radius: 50%;
}
.avatar-stack-item:last-child {
    margin-right: 0;
}
</style>
