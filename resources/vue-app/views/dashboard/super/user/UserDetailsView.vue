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

            <!-- Access: what this login can do, per organisation (the layered access model). -->
            <div class="d-flex flex-column gap-3 w-100">
                <span class="fs-5 fw-semibold">Access</span>
                <div v-if="!user.organisations?.length" class="text-muted">
                    This login doesn't belong to any organisation{{ user.type === 'User' ? ' — it is an app user.' : '.' }}
                </div>
                <div v-for="org in user.organisations ?? []" :key="org.masjid_id" class="access-card">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">{{ org.name }}<span v-if="org.archived" class="badge text-bg-secondary ms-2">Archived</span></div>
                            <span class="badge mt-1" :class="accessBadge(org)">{{ accessLabel(org) }}</span>
                        </div>
                        <button v-if="!org.archived" type="button" class="btn btn-sm btn-outline-success" @click="openTeam(org.masjid_id)">
                            Open Team &amp; Access
                        </button>
                    </div>
                    <p class="small text-muted mt-2 mb-2">{{ accessIncludes(org) }}</p>
                    <div v-if="canChange(org)" class="d-flex flex-wrap align-items-center gap-2">
                        <label :for="`access-${org.masjid_id}`" class="small fw-semibold mb-0">Change access</label>
                        <select :id="`access-${org.masjid_id}`" class="form-select form-select-sm w-auto"
                            :value="org.access ?? ''" :disabled="changing"
                            @change="changeAccess(org, ($event.target as HTMLSelectElement).value as TeamAccess)">
                            <option value="admin">Administrator — everything {{ org.name }} has</option>
                            <option value="jummah_lunch" :disabled="!org.capabilities?.includes('jummah_lunch')">Friday lunch only</option>
                        </select>
                    </div>
                    <p v-else class="small text-muted mb-0">{{ whyFixed(org) }}</p>
                </div>
            </div>

            <!--
                Two-step sign-in: the ONE place on the platform where one person
                acts on another's second factor. Shown only when there is
                something to clear, and written as a deliberate, slow form
                rather than a button — see resetTwoFactor() below.
            -->
            <div v-if="canSeeTwoFactorSection" class="d-flex flex-column gap-3 w-100">
                <span class="fs-5 fw-semibold">Two-step sign-in</span>

                <div v-if="!user.two_factor_confirmed_at" class="text-muted">
                    This login has no second factor set up, so there is nothing to clear.
                </div>

                <div v-else class="access-card">
                    <p class="mb-2">
                        On since {{ twoFactorOnSince }}.
                        Clear it only when they have lost <strong>both</strong> their authenticator
                        and their printed recovery codes — anyone who still has either should turn it
                        off themselves from their own profile.
                    </p>
                    <p class="small text-muted">
                        This removes their second factor. It does not change their password and does
                        not sign anybody in. {{ user.name }} is emailed about it, and your name and
                        the reason below are kept on the account record permanently.
                    </p>

                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1" for="tfa-reason">
                                Why are you clearing it?
                            </label>
                            <textarea id="tfa-reason" v-model="resetReason" class="form-control form-control-sm"
                                rows="2" :disabled="twoFactorStore.isLoading"
                                placeholder="e.g. Phone lost 12 Sep, no recovery sheet. Identity confirmed by video call."></textarea>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold mb-1" for="tfa-email">
                                Type their email address to confirm
                            </label>
                            <input id="tfa-email" v-model="resetEmail" type="email" autocomplete="off"
                                class="form-control form-control-sm" :disabled="twoFactorStore.isLoading"
                                :placeholder="user.email">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold mb-1" for="tfa-code">
                                A code from <em>your</em> authenticator
                            </label>
                            <input id="tfa-code" v-model="resetCode" type="text" inputmode="numeric"
                                autocomplete="one-time-code" maxlength="6"
                                class="form-control form-control-sm" :disabled="twoFactorStore.isLoading"
                                placeholder="123456">
                        </div>
                    </div>

                    <div v-if="twoFactorStore.errorMessage" class="alert alert-danger py-2 px-3 small mt-3 mb-0">
                        {{ twoFactorStore.errorMessage }}
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-danger mt-3"
                        :disabled="!canSubmitReset || twoFactorStore.isLoading" @click="resetTwoFactor">
                        {{ twoFactorStore.isLoading ? 'Clearing…' : 'Clear two-step sign-in' }}
                    </button>
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
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useTwoFactorStore } from '@/stores/twoFactorStore';
import { CAPABILITY_LABELS, TeamAccess, UserOrganisation } from '@/core/types/data/Capability';
import { accessBadge, accessLabel } from '@/core/helpers/access';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { computed, onBeforeMount, ref } from 'vue';
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

// ---- Access (the layered access model) -------------------------------------
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const changing = ref(false);

function accessIncludes(org: UserOrganisation): string {
    if (org.access === 'admin') {
        const has = (org.capabilities ?? []).map(k => CAPABILITY_LABELS[k] ?? k);
        return `Can use everything ${org.name} has${has.length ? `: ${has.join(', ')}` : ''}, plus announcements, events, services and settings.`;
    }
    if (org.access === 'jummah_lunch') return 'Can only run the Friday lunch board: menus, orders and payments.';
    if (org.access === 'teacher') return 'Can only see and manage the classes they lead.';
    return '';
}

// Same rules the server applies (TeamController::update); the server decides.
function canChange(org: UserOrganisation): boolean {
    return (org.access === 'admin' || org.access === 'jummah_lunch')
        && !org.is_owner
        && (user.value?.organisations?.length ?? 0) === 1;
}

function whyFixed(org: UserOrganisation): string {
    if (org.is_owner) return 'The owner is always an administrator.';
    if (org.access === 'teacher') return "Teachers are managed on that organisation's Teachers screen, with their classes.";
    if ((user.value?.organisations?.length ?? 0) > 1) return 'This login belongs to more than one organisation, and its access applies to all of them.';
    return '';
}

const changeAccess = async (org: UserOrganisation, access: TeamAccess) => {
    const reload = () => usersStore.fetchUser(route.params.user_id as string, user);
    if (!user.value?.id || access === org.access) return;

    const label = access === 'admin' ? 'an Administrator' : 'Friday lunch only';
    const answer = await QSwal.fire("Question", `Make ${user.value.name} ${label} at ${org.name}? They'll be signed out and sign in again with the new access.`, 'question');
    if (!answer.isConfirmed) { await reload(); return; }

    changing.value = true;
    const body = new URLSearchParams();
    body.append('access', access);
    let swalInstance: SweetAlertOptions = { title: "Info", text: "Nothing", icon: "info" };
    await ApiService.patch(`/api/admin/masjids/${org.masjid_id}/team/${user.value.id}`, body)
        .then(res => {
            swalInstance = { title: "Success", text: res.data?.message ?? 'Access changed.', icon: "success" };
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            swalInstance = { title: "Not changed", text: getMessageFromObj(e), icon: "error" };
        })
        .finally(async () => {
            changing.value = false;
            await reload();
            MSwal.fire(swalInstance);
        });
}

// ---- Two-step sign-in: the operator door -----------------------------------
//
// The one act on this screen that touches somebody else's CREDENTIALS rather
// than their access, and the only reason it exists is that without it a
// confirmed enrolment whose phone and printed sheet are both gone is a
// permanent lockout with no path back except an UPDATE typed into the
// production database. The server enforces every rule that matters (SuperAdmin
// only, the operator's own live code, never on yourself, a permanent record,
// an email to the person it was done to) — the form's job is to make the act
// feel like what it is, which is why it asks for three things and offers no
// one-click version.
const twoFactorStore = useTwoFactorStore();
const resetReason = ref('');
const resetEmail = ref('');
const resetCode = ref('');

// The section is rendered for SuperAdmins only. The route is already behind the
// super guard, so this is not the security boundary — it is what keeps the
// screen honest if these views are ever reused somewhere less protected.
const canSeeTwoFactorSection = computed(() => authStore.user?.type === 'SuperAdmin');

// Formatted here rather than in the template: `two_factor_confirmed_at` is
// `string | null | undefined`, and a `v-else` does not narrow it for the type
// checker even though the branch cannot be reached with a null.
const twoFactorOnSince = computed(() => {
    const at = user.value?.two_factor_confirmed_at;
    return at ? new Date(at).toLocaleDateString() : '';
});

const canSubmitReset = computed(() =>
    resetReason.value.trim().length >= 10
    && resetEmail.value.trim().length > 0
    && resetCode.value.trim().length > 0);

const resetTwoFactor = async () => {
    if (!user.value?.id || !canSubmitReset.value) return;

    const answer = await QSwal.fire(
        'Warning',
        `Clear two-step sign-in for ${user.value.name}? They will be emailed, and this is recorded against your name.`,
        'warning',
    );
    if (!answer.isConfirmed) return;

    const result = await twoFactorStore.clearForUser(
        user.value.id,
        resetCode.value.trim(),
        resetEmail.value.trim(),
        resetReason.value.trim(),
    );

    // The code is single-use whatever the outcome, so it never survives a
    // submit — leaving it in the box invites a second press that can only fail.
    resetCode.value = '';

    if (!result.ok) return;

    resetReason.value = '';
    resetEmail.value = '';
    await usersStore.fetchUser(route.params.user_id as string, user);

    // The server's own sentence, because it says whether the notice to the
    // affected admin actually went out — a mail outage does not undo the reset,
    // and an operator who thinks they were told when they were not is the
    // failure this whole design is built to avoid.
    MSwal.fire({ title: 'Cleared', text: result.message, icon: 'success' });
}

// Enter that organisation's dashboard on its Team & Access screen, the same
// way the Masjids list enters a dashboard.
const openTeam = async (masjidId: number) => {
    await masjidStore.fetchMasjid(masjidId).finally(async () => {
        authStore.saveDashboardMasjidId(masjidId);
        await router.push('/masjid/team');
    });
}

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

.access-card {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    padding: .75rem 1rem;
}
</style>