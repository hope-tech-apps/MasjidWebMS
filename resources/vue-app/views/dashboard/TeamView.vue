<template>
    <div>
        <PageDataContainer
            title="Team & Access"
            :buttonProps="{ title: 'Add a person', type: 'button', class: 'btn btn-success', disabled: !canAdd.length }"
            @headerButtonClick="openAdd"
        >
            <div class="container w-100">

                <!-- Layer 1: what this organisation has. Administrators get all of it. -->
                <section class="team-has mb-4" aria-labelledby="team-has-title">
                    <h2 id="team-has-title" class="fs-6 fw-semibold mb-1">What {{ orgName }} has</h2>
                    <p class="small text-muted mb-3">
                        Administrators can use everything that is switched on.
                        {{ isSuper
                            ? 'Switch these on the organisation’s page in the Super dashboard.'
                            : 'To add or remove one, contact Manara.' }}
                    </p>
                    <ul class="list-unstyled d-flex flex-wrap gap-2 m-0">
                        <li v-for="c in capabilities" :key="c.key"
                            class="team-chip" :class="c.enabled ? 'team-chip--on' : 'team-chip--off'" :title="c.description">
                            <i :class="c.enabled ? 'bi bi-check-circle-fill' : 'bi bi-dash-circle'" aria-hidden="true"></i>
                            {{ c.label }}
                            <span class="visually-hidden">{{ c.enabled ? '(switched on)' : '(not switched on)' }}</span>
                        </li>
                    </ul>
                </section>

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading…</span>
                    </div>
                </div>

                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>{{ loadError }}
                    <button class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
                </div>

                <!-- Layer 2: who can sign in, and what each of them can do. -->
                <div v-else class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Access</th>
                                <th scope="col">Last sign-in</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in people" :key="p.user_id">
                                <td class="fw-semibold">
                                    {{ p.name }}
                                    <span v-if="p.is_you" class="badge text-bg-light border ms-1">You</span>
                                </td>
                                <td class="text-break">{{ p.email }}</td>
                                <td>
                                    <span class="badge" :class="accessBadge(p)">{{ accessLabel(p) }}</span>
                                    <div class="small text-muted mt-1">{{ accessHint(p) }}</div>
                                </td>
                                <td class="small">
                                    <span v-if="p.last_sign_in_at">{{ formatDate(p.last_sign_in_at) }}</span>
                                    <span v-else class="text-warning-emphasis">Not signed in yet</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <router-link v-if="p.access === 'teacher'" to="/masjid/teachers"
                                        class="btn btn-sm btn-outline-secondary">Manage on Teachers</router-link>
                                    <template v-else>
                                        <button class="btn btn-sm btn-outline-secondary" :disabled="busyId === p.user_id"
                                            @click="resend(p)">Resend invite</button>
                                        <button v-if="p.removable" class="btn btn-sm btn-outline-danger ms-2"
                                            :disabled="busyId === p.user_id" @click="remove(p)">Remove</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="small text-muted">
                        The owner can't be removed here, and nobody can remove their own access.
                        <template v-if="hasCrm">Teachers are added and removed on the Teachers screen, with the classes they lead.</template>
                    </p>
                </div>
            </div>
        </PageDataContainer>

        <!-- Add a person -->
        <div v-if="showAdd" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true"
            aria-labelledby="team-add-title" @keydown.esc="closeAdd">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" @submit.prevent="submitAdd" novalidate>
                    <div class="modal-header">
                        <h2 id="team-add-title" class="modal-title fs-5">Add a person to {{ orgName }}</h2>
                        <button type="button" class="btn-close" aria-label="Close" @click="closeAdd"></button>
                    </div>
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label for="team-name" class="form-label">Full name</label>
                            <input id="team-name" v-model="form.name" type="text" class="form-control" maxlength="190" required autocomplete="off" />
                        </div>
                        <div>
                            <label for="team-email" class="form-label">Email</label>
                            <input id="team-email" v-model="form.email" type="email" class="form-control" maxlength="190" required autocomplete="off" />
                            <div class="form-text">They'll get a link to set their own password. The link works for 60 minutes; you can resend it.</div>
                        </div>
                        <div>
                            <label for="team-phone" class="form-label">Phone <span class="text-muted">(optional)</span></label>
                            <input id="team-phone" v-model="form.phone" type="tel" class="form-control" maxlength="40" autocomplete="off" />
                        </div>
                        <fieldset>
                            <legend class="form-label fs-6">What can they do?</legend>
                            <div class="d-flex flex-column gap-2">
                                <label v-for="opt in accessOptions" :key="opt.value" class="team-option"
                                    :class="{ 'team-option--on': form.access === opt.value }">
                                    <input v-model="form.access" type="radio" class="form-check-input mt-1" name="team-access" :value="opt.value" />
                                    <span>
                                        <span class="fw-semibold d-block">{{ opt.title }}</span>
                                        <span class="small text-muted">{{ opt.help }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                        <div v-if="formError" class="alert alert-danger py-2 mb-0" role="alert">{{ formError }}</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="closeAdd">Cancel</button>
                        <button type="submit" class="btn btn-success" :disabled="saving">
                            {{ saving ? 'Adding…' : 'Add and send invite' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div v-if="showAdd" class="modal-backdrop show"></div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import PageDataContainer from '@/components/PageDataContainer.vue';
import { apiErrorText } from '@/core/services/ApiErrors';
import { CapabilityInfo, TeamAccess, TeamMember } from '@/core/types/data/Capability';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useTeamStore } from '@/stores/masjid/teamStore';

const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const teamStore = useTeamStore();

const loading = ref(true);
const loadError = ref('');
const busyId = ref<number | null>(null);

const showAdd = ref(false);
const saving = ref(false);
const formError = ref('');
const form = ref<{ name: string; email: string; phone: string; access: TeamAccess }>({ name: '', email: '', phone: '', access: 'admin' });

const isSuper = computed(() => authStore.user?.type === 'SuperAdmin');
const orgName = computed(() => masjidStore.masjid?.name || 'this organisation');
const people = computed<TeamMember[]>(() => teamStore.team?.people ?? []);
const capabilities = computed<CapabilityInfo[]>(() => teamStore.team?.capabilities ?? []);
const canAdd = computed<TeamAccess[]>(() => teamStore.team?.can_add ?? []);
const hasCrm = computed(() => capabilities.value.some(c => c.key === 'crm' && c.enabled));

const enabledLabels = computed(() => capabilities.value.filter(c => c.enabled).map(c => c.label));

const accessOptions = computed(() => canAdd.value.map((value) => value === 'admin'
    ? {
        value,
        title: 'Administrator',
        help: `Everything ${orgName.value} has${enabledLabels.value.length ? ` — ${enabledLabels.value.join(', ')} —` : ''} plus announcements, events, services and settings.`,
    }
    : {
        value,
        title: 'Friday lunch only',
        help: 'Runs the Jummah lunch board: menus, orders and payments. Sees nothing else.',
    }));

function accessLabel(p: TeamMember): string {
    if (p.access === 'admin') return p.is_owner ? 'Owner · Administrator' : 'Administrator';
    if (p.access === 'jummah_lunch') return 'Friday lunch only';
    return 'Teacher';
}

function accessHint(p: TeamMember): string {
    if (p.access === 'admin') return 'Everything this organisation has';
    if (p.access === 'jummah_lunch') return 'The lunch board and nothing else';
    const n = p.classes ?? 0;
    return `${n} ${n === 1 ? 'class' : 'classes'} they lead`;
}

function accessBadge(p: TeamMember): string {
    if (p.access === 'admin') return 'text-bg-primary';
    if (p.access === 'jummah_lunch') return 'text-bg-warning';
    return 'text-bg-info';
}

function formatDate(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

async function load(): Promise<void> {
    if (!masjidStore.masjid?.id) return;
    loading.value = true;
    loadError.value = '';
    try {
        await teamStore.fetchTeam();
    } catch (e) {
        loadError.value = apiErrorText(e, 'Could not load the team.');
    } finally {
        loading.value = false;
    }
}

// The organisation may still be loading when the screen opens.
watch(() => masjidStore.masjid?.id, (id) => { if (id) load(); }, { immediate: true });

function openAdd(): void {
    form.value = { name: '', email: '', phone: '', access: canAdd.value[0] ?? 'admin' };
    formError.value = '';
    showAdd.value = true;
}

function closeAdd(): void {
    if (!saving.value) showAdd.value = false;
}

async function submitAdd(): Promise<void> {
    formError.value = '';
    if (!form.value.name.trim() || !form.value.email.trim()) {
        formError.value = 'Enter their name and email.';
        return;
    }
    saving.value = true;
    try {
        const message = await teamStore.addPerson(form.value);
        showAdd.value = false;
        Swal.fire({ icon: 'success', title: 'Added', text: message });
    } catch (e) {
        formError.value = apiErrorText(e, 'Could not add this person.');
    } finally {
        saving.value = false;
    }
}

async function resend(p: TeamMember): Promise<void> {
    busyId.value = p.user_id;
    try {
        const message = await teamStore.resendInvite(p.user_id);
        Swal.fire({ icon: 'success', title: 'Invitation sent', text: message });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Not sent', text: apiErrorText(e, 'Could not send the invitation.') });
    } finally {
        busyId.value = null;
    }
}

async function remove(p: TeamMember): Promise<void> {
    const answer = await Swal.fire({
        icon: 'warning',
        title: `Remove ${p.name}?`,
        text: `They'll be signed out and can no longer sign in to ${orgName.value}.`,
        showCancelButton: true,
        confirmButtonText: 'Remove',
        confirmButtonColor: '#dc3545',
    });
    if (!answer.isConfirmed) return;

    busyId.value = p.user_id;
    try {
        const message = await teamStore.removePerson(p.user_id);
        Swal.fire({ icon: 'success', title: 'Removed', text: message });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Not removed', text: apiErrorText(e, 'Could not remove this person.') });
    } finally {
        busyId.value = null;
    }
}
</script>

<style scoped>
.team-has {
    padding: 1rem 1.25rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.75rem;
    background: var(--bs-tertiary-bg);
}
.team-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    font-size: 0.875rem;
    border: 1px solid var(--bs-border-color);
}
.team-chip--on { background: var(--bs-success-bg-subtle); color: var(--bs-success-text-emphasis); border-color: var(--bs-success-border-subtle); }
.team-chip--off { color: var(--bs-secondary-color); }
.team-option {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    padding: 0.75rem 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    cursor: pointer;
}
.team-option--on { border-color: var(--bs-success); background: var(--bs-success-bg-subtle); }
.team-option:focus-within { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
</style>
