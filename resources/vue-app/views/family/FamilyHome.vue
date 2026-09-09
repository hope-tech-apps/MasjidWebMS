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

                            <div class="d-flex flex-wrap gap-3">
                                <span v-for="child in group.children" :key="child.membership_id"
                                      class="d-inline-flex align-items-center gap-2">
                                    <PersonAvatar
                                        :avatar="child.contact?.avatar"
                                        :first-name="child.contact?.first_name"
                                        :last-name="child.contact?.last_name"
                                        :size="34" />
                                    <span class="small">{{ childName(child) }}</span>
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

        <!-- SIGNING IN NEXT TIME.
             Offered here rather than buried in a settings screen the portal does
             not have, and placed BELOW the children because that is what a
             parent came for. Codes never go away: this is a convenience laid on
             top of the mailbox, not a replacement for it, so a parent who
             forgets the password is never locked out. -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h6 mb-1">Signing in</h2>
                <p class="text-muted small mb-3">
                    <template v-if="hasPassword">
                        You have a password set. You can still ask for an emailed code any time.
                    </template>
                    <template v-else>
                        You sign in with a six-digit code we email you. If you'd rather use a
                        password, you can set one here — codes will keep working either way.
                    </template>
                </p>

                <template v-if="pwOpen">
                    <div class="row g-2">
                        <div class="col-12 col-sm">
                            <label class="form-label small text-muted mb-1">New password</label>
                            <input v-model="pw" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password">
                        </div>
                        <div class="col-12 col-sm">
                            <label class="form-label small text-muted mb-1">Type it again</label>
                            <input v-model="pw2" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password" @keyup.enter="savePassword">
                        </div>
                    </div>
                    <p class="form-text mb-2">At least 12 characters. A short phrase you'll remember works well.</p>

                    <div v-if="pwError" class="alert alert-danger small py-2 mb-2">{{ pwError }}</div>
                    <div v-if="pwSaved" class="alert alert-success small py-2 mb-2">{{ pwSaved }}</div>

                    <button class="btn btn-sm btn-success" :disabled="pwBusy || !pw || !pw2" @click="savePassword">
                        {{ pwBusy ? 'Saving…' : (hasPassword ? 'Change password' : 'Set password') }}
                    </button>
                    <button class="btn btn-sm btn-link text-decoration-none" :disabled="pwBusy"
                            @click="closePw">Cancel</button>
                </template>

                <template v-else>
                    <button class="btn btn-sm btn-outline-success" @click="pwOpen = true">
                        {{ hasPassword ? 'Change my password' : 'Set a password' }}
                    </button>
                    <button v-if="hasPassword" class="btn btn-sm btn-link text-danger text-decoration-none"
                            :disabled="pwBusy" @click="removePassword">
                        Remove it
                    </button>
                    <div v-if="pwSaved" class="alert alert-success small py-2 mt-2 mb-0">{{ pwSaved }}</div>
                    <div v-if="pwError" class="alert alert-danger small py-2 mt-2 mb-0">{{ pwError }}</div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import FamilyApiService, { rowsOf } from '@/core/services/FamilyApiService';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
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

// ---------- signing in next time ----------
const hasPassword = ref(false);
const pwOpen = ref(false);
const pw = ref('');
const pw2 = ref('');
const pwBusy = ref(false);
const pwError = ref('');
const pwSaved = ref('');

const closePw = () => { pwOpen.value = false; pw.value = ''; pw2.value = ''; pwError.value = ''; };

const loadMe = async () => {
    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${masjidId.value}/me`);
        hasPassword.value = !!res.data?.data?.has_password;
    } catch {
        // Non-fatal: the panel just offers "Set a password", which is harmless
        // to show to someone who already has one — it changes it.
    }
};

const savePassword = async () => {
    pwError.value = '';
    pwSaved.value = '';
    if (pw.value !== pw2.value) { pwError.value = 'The two passwords did not match.'; return; }

    pwBusy.value = true;
    try {
        await familyStore.setPassword(masjidId.value, pw.value, pw2.value);
        hasPassword.value = true;
        closePw();
        pwSaved.value = 'Your password is set. You can sign in with it from now on.';
    } catch (e: any) {
        // The server's own wording — it explains the 12-character minimum and
        // the breached-password refusal better than a generic message could.
        pwError.value = e?.response?.data?.data?.password?.[0] ?? 'That password could not be saved.';
    } finally {
        pwBusy.value = false;
    }
};

const removePassword = async () => {
    pwBusy.value = true;
    pwError.value = '';
    pwSaved.value = '';
    try {
        await familyStore.clearPassword(masjidId.value);
        hasPassword.value = false;
        pwSaved.value = 'Your password has been removed. Sign in with an emailed code from now on.';
    } catch {
        pwError.value = 'That could not be removed.';
    } finally {
        pwBusy.value = false;
    }
};

const childName = (child: any) =>
    [child?.contact?.first_name ?? child?.first_name, child?.contact?.last_name ?? child?.last_name]
        .filter(Boolean).join(' ') || 'Student';

onMounted(async () => {
    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${masjidId.value}/groups`);
        groups.value = rowsOf(res.data?.data);
    } catch (e: any) {
        if (familyStore.handleAuthFailure(e?.response?.status)) {
            router.replace(`/family/${masjidId.value}/sign-in`);
            return;
        }
        error.value = 'We could not load your classes just now. Please try again.';
    } finally {
        loading.value = false;
    }

    // After the classes, never before: the panel is secondary and must not
    // delay the thing the parent opened the portal for.
    loadMe();
});
</script>
