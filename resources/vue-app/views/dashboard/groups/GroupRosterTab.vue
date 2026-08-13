<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-muted">Roster</h6>
            <button class="btn btn-sm btn-success" @click="openAddModal">
                <i class="bi bi-person-plus me-1"></i> Add to roster
            </button>
        </div>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="reload">Retry</button>
        </div>

        <!--
            UNCONFIRMED CLAIMS — the office's whole view of provenance.

            A row a public registration form wrote says a person is on this
            roster; it does NOT say anybody here agreed they are. Until a staff
            member confirms it, a guardian entry among these opens none of that
            child's records, and the parent cannot be given a portal sign-in. The
            banner exists because the alternative is an office reading a normal
            roster and never learning that a family's access is being withheld.

            ONE BUTTON FOR THE WHOLE GROUP is the point: a school with 200 camp
            signups must not face 200 dialogs, and the group is the smallest
            scope in which the person clicking can actually see what they are
            vouching for.
        -->
        <template v-else>
        <div v-if="pendingClaims > 0" class="alert alert-warning d-flex align-items-start gap-3">
            <i class="bi bi-patch-question fs-4 mt-1"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold mb-1">
                    {{ pendingClaims }} {{ pendingClaims === 1 ? 'entry' : 'entries' }} on this roster came from a
                    registration form and {{ pendingClaims === 1 ? 'has' : 'have' }} not been confirmed
                </div>
                <div class="small mb-0">
                    Whoever filled the form in was not signed in, so these are claims rather than records.
                    They are listed and counted here, and a teacher can already work from them — but an
                    unconfirmed <strong>guardian</strong> entry opens none of that child's behaviour, ḥifẓ or
                    message history, and the parent cannot be given a sign-in until somebody here stands
                    behind it. Check the names below, then confirm — or remove anything that should not be
                    on this list.
                </div>
            </div>
            <button class="btn btn-sm btn-warning text-nowrap" :disabled="confirming" @click="confirmAllClaims">
                <span v-if="confirming" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-patch-check me-1"></i>
                Confirm all {{ pendingClaims }}
            </button>
        </div>

        <div v-if="memberships.length === 0" class="text-center py-5 text-muted">
            <i class="bi bi-person-x fs-1 d-block mb-3"></i>
            <p class="mb-0">Nobody is on this roster yet</p>
        </div>

        <div v-else>
            <!-- PARTICIPANTS: the people who are in the group in their own right. -->
            <div class="table-responsive mb-4">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="membership in participants" :key="membership.id">
                            <td class="fw-semibold">
                                {{ fullName(membership.contact) }}
                                <span v-if="isPending(membership)" class="badge bg-warning-subtle text-warning ms-1">
                                    Unconfirmed
                                </span>
                            </td>
                            <td>
                                <span
                                    class="badge text-capitalize"
                                    :class="membership.role === 'leader' ? 'bg-primary-subtle text-primary' : 'bg-light text-dark border'"
                                >
                                    {{ membership.role }}
                                </span>
                            </td>
                            <td class="text-muted small">{{ formatDate(membership.joined_at) }}</td>
                            <td class="text-end">
                                <button
                                    v-if="isPending(membership)"
                                    class="btn btn-sm btn-outline-warning me-1"
                                    :disabled="confirming"
                                    title="Confirm this entry"
                                    @click="confirmOne(membership)"
                                >
                                    <i class="bi bi-patch-check"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" @click="confirmRemove(membership)" title="Remove">
                                    <i class="bi bi-person-dash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="participants.length === 0">
                            <td colspan="4" class="text-center text-muted py-3">No members yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!--
                GUARDIAN EDGES, rendered as what they actually are.

                `role = guardian` alone is ambiguous the moment a group holds two
                children of the same parent: it says an adult is *a* guardian here
                without saying *of whom*, and no permission question can be
                answered from that. So the row names its ward, one row per
                (guardian, ward, group) edge, and this table says
                "X — guardian of Y" instead of listing a bare "Guardian" role.
                That relationship is the thing this product models properly.
            -->
            <h6 class="text-muted mb-2">Guardians</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Guardian</th>
                            <th>Guardian of</th>
                            <th>Consent</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="membership in guardians" :key="membership.id">
                            <td class="fw-semibold">
                                {{ fullName(membership.contact) }}
                                <!--
                                    THE ROW THIS BADGE MATTERS MOST ON. An
                                    unconfirmed guardian edge is a claim somebody
                                    typed into a public form about a child, so it
                                    opens nothing — and the office has to be able
                                    to see which of these are records and which
                                    are claims before deciding.
                                -->
                                <span v-if="isPending(membership)" class="badge bg-warning-subtle text-warning ms-1">
                                    Unconfirmed claim
                                </span>
                            </td>
                            <td>
                                <i class="bi bi-arrow-return-right text-muted me-1"></i>
                                {{ fullName(membership.guardianOf) }}
                            </td>
                            <td>
                                <!--
                                    Absence of a record means NO consent — never
                                    an unknown or a pending state. `media` covers
                                    `feed`, because a photograph is a sharper
                                    disclosure than a note.
                                -->
                                <span v-if="membership.consent_scope === 'media'" class="badge bg-success-subtle text-success">
                                    Photos &amp; notes
                                </span>
                                <span v-else-if="membership.consent_scope === 'feed'" class="badge bg-info-subtle text-info">
                                    Notes only
                                </span>
                                <span v-else class="badge bg-secondary-subtle text-secondary">Not given</span>
                            </td>
                            <td class="text-end">
                                <button
                                    v-if="isPending(membership)"
                                    class="btn btn-sm btn-outline-warning me-1"
                                    :disabled="confirming"
                                    title="Confirm this guardian"
                                    @click="confirmOne(membership)"
                                >
                                    <i class="bi bi-patch-check"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" @click="confirmRemove(membership)" title="Remove">
                                    <i class="bi bi-person-dash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="guardians.length === 0">
                            <td colspan="4" class="text-center text-muted py-3">No guardians linked yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        </template>

        <!-- Add to roster -->
        <Teleport to="body">
            <div v-if="showAddModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showAddModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Add to roster</h5>
                            <button type="button" class="btn-close" @click="showAddModal = false"></button>
                        </div>
                        <form @submit.prevent="submitAdd">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Role <span class="text-danger">*</span></label>
                                    <select class="form-select text-capitalize" v-model="addForm.role">
                                        <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">
                                        {{ membersTerm }} <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        class="form-control mb-2"
                                        v-model="contactSearch"
                                        @input="searchContacts"
                                        placeholder="Search the directory by name or email…"
                                    >
                                    <div class="list-group" style="max-height:28vh; overflow-y:auto;">
                                        <button
                                            v-for="contact in contactResults"
                                            :key="contact.id"
                                            type="button"
                                            class="list-group-item list-group-item-action d-flex justify-content-between"
                                            :class="{ active: addForm.contact_id === contact.id }"
                                            @click="addForm.contact_id = contact.id"
                                        >
                                            <span>{{ contact.first_name }} {{ contact.last_name }}</span>
                                            <small class="text-muted">{{ contact.email || '' }}</small>
                                        </button>
                                        <div v-if="!contactResults.length" class="text-muted small p-2">Type to search…</div>
                                    </div>
                                </div>

                                <!--
                                    A guardian edge MUST name its ward, and the ward
                                    must already hold a participant membership here
                                    — otherwise the edge would grant access to a
                                    child nobody put in this group.
                                -->
                                <div v-if="addForm.role === 'guardian'" class="mb-1">
                                    <label class="form-label">Guardian of <span class="text-danger">*</span></label>
                                    <select class="form-select" v-model="guardianOfContactId">
                                        <option :value="null" disabled>Choose the member…</option>
                                        <option v-for="p in participants" :key="p.id" :value="p.contact_id">
                                            {{ fullName(p.contact) }}
                                        </option>
                                    </select>
                                    <div class="form-text">
                                        One row per child: a parent with two children in this group is added twice.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="showAddModal = false" :disabled="adding">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="adding || !canAdd">
                                    <span v-if="adding" class="spinner-border spinner-border-sm me-1"></span>
                                    Add
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { AxiosResponse } from 'axios';
import ApiService from '@/core/services/ApiService';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Contact } from '@/core/types/data/masjid-related/Contact';
import { GroupContact, GroupMembership, GroupMembershipPayload, GroupRole } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * The roster — who is in this group, and how.
 *
 * The part worth getting right is the GUARDIAN EDGE. Competitors model a parent
 * as a role on the group and then cannot answer "may this adult see this child's
 * record?", because the row does not say whose parent they are. Here a guardian
 * row carries its ward, so it renders as "Fatima Ahmed — guardian of Yusuf
 * Ahmed", and a parent with two children in one classroom holds two rows.
 *
 * Groups reference people, they never duplicate them: everyone added here is an
 * existing Contact from the member directory. See .claude/rules/groups.md.
 */

const props = defineProps<{
    groupId: number;
    memberships: GroupMembership[];
    loading: boolean;
    loadError: string;
}>();

const emit = defineEmits<{ (event: 'changed'): void; (event: 'reload'): void }>();

// Stores
const groupsStore = useGroupsStore();
const masjidStore = useMasjidStore();

// State
const showAddModal = ref(false);
const adding = ref(false);
const confirming = ref(false);
const contactSearch = ref('');
const contactResults = ref<Contact[]>([]);
/** Kept apart from `addForm` so switching away from `guardian` cannot leave a stale ward behind. */
const guardianOfContactId = ref<number | null>(null);
let contactSearchTimer: ReturnType<typeof setTimeout> | null = null;

const emptyAddForm = (): GroupMembershipPayload => ({
    contact_id: 0, role: 'member', guardian_of_contact_id: null, joined_at: ''
});
const addForm = ref<GroupMembershipPayload>(emptyAddForm());

// Computed
const membersTerm = computed<string>(() => masjidStore.term('members'));

const roles = computed<GroupRole[]>(() => groupsStore.groupsMeta?.roles ?? ['leader', 'member', 'guardian']);

/** People who are in the group in their own right — the only rows a ward may be chosen from. */
const participants = computed<GroupMembership[]>(
    () => props.memberships.filter((m) => m.role !== 'guardian')
);

const guardians = computed<GroupMembership[]>(
    () => props.memberships.filter((m) => m.role === 'guardian')
);

const canAdd = computed<boolean>(() => {
    if (!addForm.value.contact_id) return false;
    return addForm.value.role !== 'guardian' || guardianOfContactId.value !== null;
});

/** Served by the API, never recounted here — see groupsStore.fetchMemberships. */
const pendingClaims = computed<number>(() => groupsStore.pendingClaims);

// Methods
const fullName = (contact: GroupContact | null): string =>
    contact ? `${contact.first_name} ${contact.last_name}`.trim() : '—';

/**
 * Has anybody at the organisation stood behind this row?
 *
 * Read defensively — ANYTHING that is not exactly `confirmed` is a pending
 * claim, so a value this build does not know about renders as unconfirmed
 * rather than as a grant. Same direction the server reads it in.
 */
const isPending = (membership: GroupMembership): boolean => membership.provenance !== 'confirmed';

const formatDate = (iso: string | null): string => {
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime()) ? iso : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const reload = () => emit('reload');

const openAddModal = () => {
    addForm.value = emptyAddForm();
    guardianOfContactId.value = null;
    contactSearch.value = '';
    contactResults.value = [];
    showAddModal.value = true;
};

const searchContacts = () => {
    if (contactSearchTimer) clearTimeout(contactSearchTimer);
    contactSearchTimer = setTimeout(async () => {
        const query = contactSearch.value.trim();
        if (!query) { contactResults.value = []; return; }

        const masjidId = masjidStore.masjid?.id;
        if (!masjidId) return;

        try {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidId}/contacts?search=${encodeURIComponent(query)}&per_page=8` as BackendApiRoute
            );
            contactResults.value = res.data?.data?.data ?? [];
        } catch (error) {
            contactResults.value = [];
        }
    }, 300);
};

const submitAdd = async () => {
    if (!canAdd.value) return;
    adding.value = true;
    try {
        await groupsStore.addMembership(props.groupId, {
            ...addForm.value,
            guardian_of_contact_id: addForm.value.role === 'guardian' ? guardianOfContactId.value : null
        });
        showAddModal.value = false;
        emit('changed');
        Swal.fire({ icon: 'success', title: 'Added', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to add to the roster.') });
    } finally {
        adding.value = false;
    }
};

/**
 * Stand behind every unconfirmed row ON THIS SCREEN.
 *
 * ASKED FIRST, and the question names what is being granted rather than how
 * many rows are changing: this is the act that opens a child's behaviour, ḥifẓ
 * and message history to the adults these rows name, and "Confirm 200 entries?"
 * reads like a housekeeping chore.
 *
 * SENDS THE IDS IT DREW. This used to post an empty body, which the server read
 * as "every pending claim at the moment the request lands" — a different set
 * from the one rendered here. Measured: a registration arriving while this
 * dialog was open was confirmed by a click on the eight rows above it, and that
 * ninth row was a stranger's claim over a named child. The count in the prompt
 * and the rows in the request now come from the same array.
 */
const confirmAllClaims = async () => {
    const shown = props.memberships.filter(isPending).map((m) => m.id);

    if (shown.length === 0) return;

    const result = await Swal.fire({
        title: `Confirm ${shown.length} roster ${shown.length === 1 ? 'entry' : 'entries'}?`,
        html: 'You are recording that this organisation stands behind these entries. '
            + 'Every <strong>guardian</strong> among them will then be able to read that '
            + 'child\'s behaviour, ḥifẓ and message history through the parent portal, and can '
            + 'be given a sign-in. Remove anything that should not be on this list first.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, confirm them'
    });

    if (!result.isConfirmed) return;

    await runConfirm(shown);
};

const confirmOne = async (membership: GroupMembership) => {
    await runConfirm([membership.id]);
};

const runConfirm = async (ids: number[]) => {
    confirming.value = true;
    try {
        const confirmed = await groupsStore.confirmClaims(props.groupId, ids);
        emit('changed');
        Swal.fire({
            icon: 'success',
            title: confirmed === 1 ? '1 entry confirmed' : `${confirmed} entries confirmed`,
            timer: 1800,
            showConfirmButton: false
        });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to confirm the roster entries.') });
    } finally {
        confirming.value = false;
    }
};

const confirmRemove = async (membership: GroupMembership) => {
    // Removing a PARTICIPANT also removes the guardian edges pointing at them —
    // say so, because the admin is authorising more than the row they clicked.
    const alsoDropsGuardians = membership.role !== 'guardian'
        && guardians.value.some((g) => g.guardian_of_contact_id === membership.contact_id);

    const result = await Swal.fire({
        title: 'Remove from roster?',
        text: alsoDropsGuardians
            ? `${fullName(membership.contact)} will be removed, along with the guardian links pointing at them.`
            : `${fullName(membership.contact)} will be removed from this roster.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, remove'
    });

    if (!result.isConfirmed) return;

    try {
        await groupsStore.removeMembership(props.groupId, membership.id);
        emit('changed');
        Swal.fire({ icon: 'success', title: 'Removed', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to remove the member.') });
    }
};

// Lock body scroll while the modal is open
watch(showAddModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
