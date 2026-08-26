<template>
    <div>
        <h1 class="h4 mb-1">Assalamu alaikum{{ firstName ? ', ' + firstName : '' }}</h1>
        <p class="text-muted small mb-4">Your children's classes at this school.</p>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-success"></span>
        </div>

        <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

        <div v-else-if="!groups.length" class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <p class="mb-1 fw-semibold">Nothing here yet</p>
                <p class="text-muted small mb-0">
                    The school has not added you to a class yet. If you think that is wrong,
                    contact the office — they can see your record.
                </p>
            </div>
        </div>

        <div v-else class="d-flex flex-column gap-3">
            <router-link v-for="group in groups" :key="group.id"
                         :to="`/family/${masjidId}/classes/${group.id}`"
                         class="card border-0 shadow-sm text-decoration-none text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h2 class="h6 mb-1">{{ group.name }}</h2>
                            <p v-if="group.description" class="text-muted small mb-2">{{ group.description }}</p>

                            <div class="d-flex flex-wrap gap-1">
                                <span v-for="child in group.children" :key="child.id"
                                      class="badge bg-success-subtle text-success-emphasis">
                                    {{ childName(child) }}
                                </span>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>

                    <!-- Stated, not inferred. A parent who has not consented must not be
                         shown an empty class story and left to think the teacher posts nothing. -->
                    <div v-if="!group.may_receive_feed" class="alert alert-warning small mt-3 mb-0 py-2">
                        You have not given consent for class updates, so the class story is hidden.
                        The school office can record your consent.
                    </div>
                </div>
            </router-link>
        </div>
    </div>
</template>

<script setup lang="ts">
import FamilyApiService from '@/core/services/FamilyApiService';
import { useFamilyStore } from '@/stores/familyStore';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();

const masjidId = computed(() => String(route.params.masjidId));
const firstName = computed(() => familyStore.contact?.first_name ?? '');

const groups = ref<any[]>([]);
const loading = ref(true);
const error = ref('');

const childName = (child: any) =>
    [child?.contact?.first_name ?? child?.first_name, child?.contact?.last_name ?? child?.last_name]
        .filter(Boolean).join(' ') || 'Student';

onMounted(async () => {
    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${masjidId.value}/groups`);
        groups.value = res.data?.data ?? [];
    } catch (e: any) {
        if (familyStore.handleAuthFailure(e?.response?.status)) {
            router.replace(`/family/${masjidId.value}/sign-in`);
            return;
        }
        error.value = 'We could not load your classes just now. Please try again.';
    } finally {
        loading.value = false;
    }
});
</script>
