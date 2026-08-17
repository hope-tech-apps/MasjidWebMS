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

            WHAT THE BUTTON NO LONGER CLAIMS. It used to say "Confirm all N" and
            mean it. Some claims cannot be swept, because the operator cannot
            read them apart: a second guardian entry over the SAME child under
            the SAME name. Those are counted separately, excluded from this
            button by the client AND refused a place in the sweep by the server,
            and decided one at a time from the row itself.
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
                    behind it. <strong>Read the addresses below</strong>, not just the names: two entries can
                    carry the same name over the same child and be two different people.
                </div>
                <div v-if="contestedClaims > 0" class="small mt-2 mb-0 text-danger-emphasis">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    {{ contestedClaims }} of {{ pendingClaims === 1 ? 'them' : 'those' }}
                    {{ contestedClaims === 1 ? 'claims a child another entry also claims' : 'claim children other entries also claim' }}
                    under the same name. {{ contestedClaims === 1 ? 'It is' : 'They are' }} not included in this
                    button — open {{ contestedClaims === 1 ? 'it' : 'them' }} from the row below and decide
                    {{ contestedClaims === 1 ? 'it' : 'them' }} one at a time.
                </div>
            </div>
            <button
                v-if="sweepableClaims.length > 0"
                class="btn btn-sm btn-warning text-nowrap"
                :disabled="confirming"
                @click="confirmAllClaims"
            >
                <span v-if="confirming" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-patch-check me-1"></i>
                Review &amp; confirm {{ sweepableClaims.length }}
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
                                <!--
                                    WHICH Fatima Ahmed. A name is not an identity
                                    on a roster a public form can write to, and
                                    the office cannot answer "is this the child we
                                    enrolled" from a name it already had.
                                -->
                                <div class="small fw-normal" :class="addressClass(membership.contact)">
                                    {{ addressLabel(membership.contact) }}
                                </div>
                                <div v-if="isPending(membership)" class="small fw-normal" :class="originClass(membership)">
                                    {{ originLabel(membership) }}
                                </div>
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
                            <th>Where this entry came from</th>
                            <th>Consent</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="membership in guardians" :key="membership.id" :class="{ 'table-danger': isContested(membership) }">
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
                                <!--
                                    THE BYTE THAT SEPARATES TWO CLAIMS OVER ONE
                                    CHILD. Measured: a stranger who knew a child's
                                    name and household address typed the MOTHER's
                                    name as payer with his own email, and this
                                    table drew "Aisha Ahmed" for both rows and
                                    nothing else. One press of the bulk button
                                    turned his 403 into her behaviour record.
                                -->
                                <div class="small fw-normal" :class="addressClass(membership.contact)">
                                    {{ addressLabel(membership.contact) }}
                                </div>
                                <div v-if="isContested(membership)" class="small fw-semibold text-danger mt-1">
                                    <i class="bi bi-exclamation-octagon me-1"></i>
                                    {{ membership.claim?.rival_claim_ids?.length ?? 0 }} other
                                    {{ (membership.claim?.rival_claim_ids?.length ?? 0) === 1 ? 'entry claims' : 'entries claim' }}
                                    this child under this name — decide this one on its own
                                </div>
                            </td>
                            <td>
                                <i class="bi bi-arrow-return-right text-muted me-1"></i>
                                {{ fullName(membership.guardian_of) }}
                                <div class="small text-muted">{{ addressLabel(membership.guardian_of) }}</div>
                            </td>
                            <!--
                                PROVENANCE, DRAWN. `index()` has always eager-loaded
                                the asserting registration and this file rendered it
                                zero times, while the request's docblock said it was
                                "on the screen the button lives on". Every way the
                                evidence can be missing gets its own sentence here —
                                a merge nulls the payer, and a blank cell is what
                                produced the defect in the first place.
                            -->
                            <td class="small" :class="originClass(membership)">
                                {{ originLabel(membership) }}
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
                                    class="btn btn-sm me-1"
                                    :class="isContested(membership) ? 'btn-danger' : 'btn-outline-warning'"
                                    :disabled="confirming"
                                    :title="isContested(membership)
                                        ? 'Two entries claim this child under this name — compare them'
                                        : 'Confirm this guardian'"
                                    @click="confirmOne(membership)"
                                >
                                    <i class="bi" :class="isContested(membership) ? 'bi-question-diamond' : 'bi-patch-check'"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" @click="confirmRemove(membership)" title="Remove">
                                    <i class="bi bi-person-dash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="guardians.length === 0">
                            <td colspan="5" class="text-center text-muted py-3">No guardians linked yet</td>
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
 *
 * ==========================================================================
 * THIS SCREEN IS WHERE A CLAIM BECOMES A GRANT, SO IT HAS TO CARRY THE EVIDENCE
 * ==========================================================================
 *
 * `ConfirmGroupMembershipsRequest` justified its bulk button by saying "ward
 * names, the claimed guardian, and which signup asserted it are all on the
 * screen the button lives on." Measured, in this file: `source_registration`
 * appeared 0 times, `confirmed_by` 0 times, and the only two occurrences of
 * `email` were inside the add-to-roster directory picker. Worse, the ward name
 * was not on the screen either — Eloquent's `$snakeAttributes` serialises
 * `with('guardianOf')` as `guardian_of`, this file read `membership.guardianOf`,
 * and the "Guardian of" column rendered `—` on every row. The one column that
 * answers "guardian of WHOM" was empty and the docblock said it was the
 * safeguard.
 *
 * So four public registrations — three real families and one stranger who knew a
 * child's name and household address, typed the MOTHER's name as payer and his
 * own email — drew as:
 *
 *     GUARDIAN ROW #2  "Aisha Ahmed"  ->  —
 *     GUARDIAN ROW #7  "Aisha Ahmed"  ->  —
 *
 * and one press of "Confirm all 6" took his access from 403 to that child's
 * behaviour record. What this file now draws for the same two rows is at the
 * bottom of the guardian table below: the ward, the claimant's address, and the
 * signup and payer that asserted the row — plus a refusal to sweep the pair at
 * all, which the server enforces independently of anything drawn here.
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

/** Every unconfirmed row on this screen, in the order it is drawn. */
const pendingRows = computed<GroupMembership[]>(() => props.memberships.filter(isPending));

/**
 * The claims one press may decide — everything pending EXCEPT the ones the
 * operator cannot read apart.
 *
 * The exclusion is duplicated on the server, which refuses a contested row a
 * place in a sweep whatever the client sends. This half is not the guard; it is
 * the screen agreeing with the guard, so the button's number is the truth rather
 * than a promise the response then walks back.
 */
const sweepableClaims = computed<GroupMembership[]>(
    () => pendingRows.value.filter((m) => !isContested(m) && fingerprintOf(m) !== null)
);

const canAdd = computed<boolean>(() => {
    if (!addForm.value.contact_id) return false;
    return addForm.value.role !== 'guardian' || guardianOfContactId.value !== null;
});

/** Served by the API, never recounted here — see groupsStore.fetchMemberships. */
const pendingClaims = computed<number>(() => groupsStore.pendingClaims);

/** Also served, for the same reason: the definition decides what is refused. */
const contestedClaims = computed<number>(() => groupsStore.contestedClaims);

// Methods
const fullName = (contact: GroupContact | null | undefined): string =>
    contact ? `${contact.first_name} ${contact.last_name}`.trim() : '—';

/**
 * Has anybody at the organisation stood behind this row?
 *
 * Read defensively — ANYTHING that is not exactly `confirmed` is a pending
 * claim, so a value this build does not know about renders as unconfirmed
 * rather than as a grant. Same direction the server reads it in.
 */
const isPending = (membership: GroupMembership): boolean => membership.provenance !== 'confirmed';

/**
 * HOW TO REACH THIS PERSON — the one rendered byte that separates two rows
 * carrying the same name.
 *
 * A missing address is SAID, loudly, rather than left as an empty cell. "No
 * address on file" is a fact the office can act on; a blank is the thing the
 * operator reads straight past, and reading straight past is what the whole
 * finding was.
 */
const addressOf = (contact: GroupContact | null | undefined): string =>
    (contact?.email || contact?.phone || '').trim();

const addressLabel = (contact: GroupContact | null | undefined): string =>
    addressOf(contact) || 'no address on file';

const addressClass = (contact: GroupContact | null | undefined): string =>
    addressOf(contact) ? 'text-muted' : 'text-danger';

/**
 * Is this a claim over a child that another entry also claims, under a name the
 * operator would read as the same person?
 *
 * Read from the server's answer, never recomputed here. Defensive in the safe
 * direction is not available on this one — a build that does not understand the
 * field would read `undefined` as "not contested" — so the SERVER refuses the
 * sweep as well, and this flag only decides what the screen says about it.
 */
const isContested = (membership: GroupMembership): boolean => membership.claim?.contested === true;

/** What the caller must echo back to prove this row has not moved since it was drawn. */
const fingerprintOf = (membership: GroupMembership): string | null => membership.claim?.fingerprint ?? null;

/** The rows a confirm request carries: what was named, and what it said. */
const rowsToSend = (list: GroupMembership[]): { id: number; fingerprint: string }[] =>
    list
        .map((m) => ({ id: m.id, fingerprint: fingerprintOf(m) }))
        .filter((row): row is { id: number; fingerprint: string } => row.fingerprint !== null);

/**
 * WHICH SIGNUP ASSERTED THIS, AND WHO PAID — drawn, at last.
 *
 * Each of the four states is a different thing for the office to do, so each one
 * gets its own sentence instead of a shared blank:
 *
 *   registration                    — judge the payer's address against the row
 *   registration_payer_unavailable  — a merge removed the payer; judge it another way
 *   unrecorded                      — nothing says where it came from at all
 *   confirmed                       — a colleague already stood behind it
 */
const originLabel = (membership: GroupMembership): string => {
    const origin = membership.claim?.origin;

    if (!origin) {
        return 'Origin not shown by this server';
    }

    switch (origin.state) {
        case 'registration': {
            const payer = origin.payer;
            const who = [payer?.first_name, payer?.last_name].filter(Boolean).join(' ').trim();
            const address = (payer?.email || '').trim() || 'no address on the payer record';
            return `Signup #${origin.registration_id} — paid for by ${who || 'someone unnamed'} (${address})`;
        }
        case 'registration_payer_unavailable':
            return `Signup #${origin.registration_id} — the payer record is gone (merged or deleted), `
                + 'so this claim cannot be traced to anybody';
        case 'unrecorded':
            return 'No signup on record — this entry cannot be traced to anybody';
        case 'confirmed':
            return 'Confirmed by this organisation';
        default:
            return 'Origin not recognised by this build';
    }
};

const originClass = (membership: GroupMembership): string => {
    const state = membership.claim?.origin?.state;

    if (state === 'registration') return 'text-muted';
    if (state === 'confirmed') return 'text-success';

    // Missing evidence is not neutral. It is the state in which an operator has
    // the least to go on and used to be shown nothing at all.
    return 'text-danger';
};

/**
 * Swal renders `html` unescaped, and every string below came off a PUBLIC form —
 * the payer's name is literally attacker-supplied. Escaped here rather than in
 * each template literal, because "I remembered to escape it" is exactly the kind
 * of claim this screen has already been caught making.
 */
const escapeHtml = (value: string): string =>
    value.replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[character] as string));

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

/**
 * Add someone to the roster — AND SAY WHAT THE SERVER SAID.
 *
 * `POST …/members` is the OTHER door onto the grant `confirm()` guards. Typing
 * an entry that already exists as a pending claim CONFIRMS it, by the same
 * authenticated person and with the same actor recorded, and the server answers
 * with a warning when the entry it just confirmed has a same-named rival
 * claiming the same child — the one fact that makes the decision worth making
 * twice, and the fact the sweep refuses to decide without.
 *
 * That sentence was computed, serialised and thrown away: the store returned
 * only `data`, and this handler fired `{icon:'success', title:'Added'}` on a
 * 1600ms timer. Measured: the contested stranger row, refused by the sweep
 * seconds earlier, confirmed here with the warning present in the body and
 * invisible on screen.
 *
 * A WARNING DOES NOT AUTO-DISMISS. The timer stays on the ordinary case, where
 * an operator typed a row and got a row; anything the server chose to say needs
 * an acknowledgement, because the act it describes is a disclosure about a child
 * and the remedy is on another part of this same screen.
 */
const submitAdd = async () => {
    if (!canAdd.value) return;
    adding.value = true;
    try {
        const result = await groupsStore.addMembership(props.groupId, {
            ...addForm.value,
            guardian_of_contact_id: addForm.value.role === 'guardian' ? guardianOfContactId.value : null
        });
        showAddModal.value = false;
        emit('changed');

        if (result.message) {
            await Swal.fire({
                icon: result.confirmedAnExistingClaim ? 'warning' : 'success',
                title: result.confirmedAnExistingClaim ? 'Confirmed by you' : 'Added',
                text: result.message,
                confirmButtonText: 'I have read this'
            });
        } else {
            Swal.fire({ icon: 'success', title: 'Added', timer: 1600, showConfirmButton: false });
        }
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to add to the roster.') });
    } finally {
        adding.value = false;
    }
};

/** One guardian claim, written the way the dialog has to name it. */
const claimLine = (membership: GroupMembership): string =>
    `<li class="mb-2"><strong>${escapeHtml(fullName(membership.contact))}</strong> `
    + `&lt;${escapeHtml(addressLabel(membership.contact))}&gt;`
    + `<br><span class="text-muted">guardian of ${escapeHtml(fullName(membership.guardian_of))}`
    + ` — ${escapeHtml(originLabel(membership))}</span></li>`;

/**
 * Stand behind every unconfirmed row ON THIS SCREEN THAT A SWEEP MAY DECIDE.
 *
 * ASKED FIRST, and the question ENUMERATES THE CLAIMANTS BY ADDRESS rather than
 * counting them. "Confirm 6 entries?" is a housekeeping chore; a list of six
 * addresses that are about to be able to read six named children's behaviour and
 * ḥifẓ is the decision actually being made. The previous wording described the
 * grant correctly and named nobody, which is why it read as reasonable to an
 * operator whose list contained a stranger.
 *
 * Enrolment rows are COUNTED and guardian rows are LISTED, deliberately. A
 * participant row is a child's own place in the group and grants its holder
 * nothing (GroupAudience ignores a family credential's own participant rows), so
 * enumerating 200 of them would bury the three lines that matter. The grant is
 * the guardian edge; the guardian edges are what the dialog reads out.
 *
 * SENDS THE IDS AND FINGERPRINTS IT DREW. The ids used to be absent entirely,
 * which the server read as "every pending claim at the moment the request lands"
 * — a different set from the one rendered here. Naming them fixed insertion; the
 * fingerprints fix mutation, which naming ids cannot: a merge re-points a
 * pending claim's `contact_id` and the id does not change.
 *
 * AND IT NEVER SENDS `contested`. That argument is not passed from here at all,
 * so the one shape an operator cannot read apart cannot be decided by this
 * button even if the list, the count and the dialog all agreed.
 */
const confirmAllClaims = async () => {
    const shown = sweepableClaims.value;

    if (shown.length === 0) return;

    const guardianClaims = shown.filter((m) => m.role === 'guardian');
    const enrolments = shown.length - guardianClaims.length;

    const result = await Swal.fire({
        title: guardianClaims.length === 0
            ? `Confirm ${shown.length} ${shown.length === 1 ? 'enrolment' : 'enrolments'}?`
            : `Give ${guardianClaims.length} ${guardianClaims.length === 1 ? 'adult' : 'adults'} access to a child's records?`,
        html: (guardianClaims.length === 0
            ? '<p class="mb-2">These entries put people on the roster. None of them is a guardian link, '
                + 'so none of them opens anybody\'s records.</p>'
            : '<p class="mb-2">Confirming these means each adult below will be able to read that '
                + 'child\'s behaviour, ḥifẓ and message history through the parent portal, and can be '
                + 'given a sign-in. <strong>Check each address</strong> — the names came off a public '
                + 'form and anybody can type one.</p>'
                + `<ul class="text-start small" style="max-height:40vh; overflow-y:auto;">${guardianClaims.map(claimLine).join('')}</ul>`)
            + (enrolments > 0
                ? `<p class="small text-muted mb-0">Also confirming ${enrolments} `
                    + `${enrolments === 1 ? 'enrolment, which grants' : 'enrolments, which grant'} nobody anything.</p>`
                : '')
            + (contestedClaims.value > 0
                ? `<p class="small text-danger mb-0 mt-2">${contestedClaims.value} contested `
                    + `${contestedClaims.value === 1 ? 'entry is' : 'entries are'} NOT included here.</p>`
                : ''),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, confirm them'
    });

    if (!result.isConfirmed) return;

    await runConfirm(rowsToSend(shown));
};

/**
 * ONE ROW, ASKED FOR BY NAME.
 *
 * This used to confirm on a bare click with no question at all, which made the
 * single-row path CHEAPER than the bulk one it sat beside — a one-click grant of
 * a child's records with nothing between the pointer and the disclosure.
 *
 * A contested row gets a different dialog: the rivals are read out beside it, so
 * "decide it individually" means choosing between two addresses rather than
 * looking at the same row twice. Only that dialog passes the row as
 * ACKNOWLEDGED, which is the one way the server will confirm it.
 */
const confirmOne = async (membership: GroupMembership) => {
    const rows = rowsToSend([membership]);

    if (rows.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Reload the roster',
            text: 'This entry was drawn without the information needed to confirm it safely.'
        });
        return;
    }

    if (isContested(membership)) {
        const rivalIds = membership.claim?.rival_claim_ids ?? [];
        const rivals = props.memberships.filter((m) => rivalIds.includes(m.id));

        const result = await Swal.fire({
            title: `Which ${fullName(membership.contact)}?`,
            html: `<p class="mb-2">More than one entry claims to be a guardian of `
                + `<strong>${escapeHtml(fullName(membership.guardian_of))}</strong> under this name. `
                + 'They are different records, and at most one of them is the person you think it is.</p>'
                + '<p class="mb-1 text-start fw-semibold">You are about to confirm:</p>'
                + `<ul class="text-start small">${claimLine(membership)}</ul>`
                + '<p class="mb-1 text-start fw-semibold">Also claiming this child:</p>'
                + `<ul class="text-start small">${rivals.map(claimLine).join('') || '<li>(no longer on this roster)</li>'}</ul>`
                + '<p class="small mb-0">Confirming opens that child\'s behaviour, ḥifẓ and message history to '
                + `<strong>${escapeHtml(addressLabel(membership.contact))}</strong>. `
                + 'If you cannot place that address, remove the entry instead.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: `Confirm ${addressLabel(membership.contact)}`
        });

        if (!result.isConfirmed) return;

        await runConfirm(rows, [membership.id]);
        return;
    }

    if (membership.role === 'guardian') {
        const result = await Swal.fire({
            title: 'Give this adult access to a child\'s records?',
            html: `<ul class="text-start small">${claimLine(membership)}</ul>`
                + '<p class="small mb-0">They will be able to read that child\'s behaviour, ḥifẓ and message '
                + 'history through the parent portal, and can be given a sign-in.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, confirm'
        });

        if (!result.isConfirmed) return;
    }

    await runConfirm(rows);
};

/**
 * REPORTS WHAT ACTUALLY HAPPENED, not what was asked for.
 *
 * A sweep can come back having confirmed fewer rows than it named, for three
 * reasons that call for three different acts. Announcing the confirmed count
 * alone is the same class of mistake as "Confirm all 8" returning 9: a number
 * that is true and describes the wrong set.
 */
const runConfirm = async (rows: { id: number; fingerprint: string }[], contestedIds: number[] = []) => {
    confirming.value = true;
    try {
        const outcome = await groupsStore.confirmClaims(props.groupId, rows, contestedIds);
        emit('changed');

        const held = outcome.changedSinceShown.length + outcome.needsAnIndividualDecision.length;

        if (held > 0) {
            await Swal.fire({
                icon: 'warning',
                title: outcome.confirmed === 1 ? '1 entry confirmed' : `${outcome.confirmed} entries confirmed`,
                text: outcome.message,
                confirmButtonText: 'Reload the roster'
            });
            return;
        }

        Swal.fire({
            icon: 'success',
            title: outcome.confirmed === 1 ? '1 entry confirmed' : `${outcome.confirmed} entries confirmed`,
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
