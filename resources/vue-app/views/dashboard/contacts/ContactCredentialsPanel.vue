<template>
    <!--
        Volunteer credentials on one member's record (T-023).

        Hidden for placeholder card stubs for the same reason the parent-portal
        block is: they name no person, so there is nobody to be licensed.
    -->
    <div v-if="contact && !isPlaceholder" class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-muted mb-0">
                    Credentials
                    <span v-if="credentials.length" class="badge bg-secondary-subtle text-secondary ms-1">
                        {{ credentials.length }}
                    </span>
                </h6>
                <!--
                    Disabled until the server's vocabulary has arrived, WITH a
                    reason. The Type options are `meta.kinds` (never a list
                    typed here), so before the first successful response the
                    form has nothing to offer and its submit could never pass
                    `canSubmit`. An enabled button opening a dead modal is the
                    failure this replaces.

                    AND disabled while the read for the person on screen was
                    refused or failed. `meta` is kept ACROSS members on purpose
                    (re-opening a card must not blank the form's options for a
                    frame), so a 403 on member B still leaves `kinds` populated
                    from member A's successful read — the button would stay lit
                    and offer to add a credential to a record this admin was
                    just told they may not read. The write would 403 too, but
                    only after the form was filled in.
                -->
                <button
                    type="button"
                    class="btn btn-sm btn-success"
                    @click="openCreate"
                    :disabled="!kinds.length || Boolean(loadError)"
                    :title="addCredentialBlockedReason"
                >
                    <i class="bi bi-patch-check me-1"></i> Add credential
                </button>
            </div>

            <!--
                THE RENEWAL CHASE, and nothing more.

                This chip re-reads the SAME list endpoint with
                ?expiring_within_days=N. It is not a reminder, it does not
                schedule anything and it never will: expiry notifications are
                explicitly out of scope in .claude/rules/credentials.md.

                The number in the label is the SERVER's
                (`meta.expiring_within_days`), never a literal typed here — the
                same value its status accessor uses. Writing "30" would let the
                chip and the amber badges disagree the day the config moves.
            -->
            <div v-if="expiringWindow" class="mb-2">
                <button
                    type="button"
                    class="btn btn-sm"
                    :class="expiringOnly ? 'btn-warning' : 'btn-outline-secondary'"
                    @click="toggleExpiringOnly"
                    :disabled="loading"
                >
                    <i class="bi bi-hourglass-split me-1"></i>
                    Expiring within {{ expiringWindow }} days
                </button>
            </div>

            <div v-if="loading" class="text-muted small">Loading credentials…</div>

            <!--
                THE FAILED READ SAYS SO.

                This branch sits ABOVE the empty state on purpose: without it a
                403 or a 500 falls into "No credentials recorded for this
                person.", which is a statement about a safeguarding record that
                the screen has no evidence for. An empty list and an unanswered
                question look identical to the reader and must not read
                identically.
            -->
            <div v-else-if="loadError" class="alert alert-danger py-2 small mb-0" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                {{ loadError }}
                <button type="button" class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
            </div>

            <div v-else-if="!credentials.length" class="text-muted small">
                {{ expiringOnly
                    ? `Nothing expiring in the next ${expiringWindow} days.`
                    : 'No credentials recorded for this person.' }}
            </div>

            <ul v-else class="list-unstyled mb-0">
                <li
                    v-for="credential in credentials"
                    :key="credential.id"
                    class="border rounded p-2 mb-2"
                >
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="me-2">
                            <div class="mb-1">
                                <span class="fw-semibold">{{ kindLabel(credential) }}</span>
                                <!--
                                    THE BADGE IS THE SERVER'S ANSWER, read
                                    straight off the payload.

                                    `status` is derived by the model from
                                    expires_at against
                                    config('credentials.expiring_within_days').
                                    Recomputing it here from `expires_at` is the
                                    single most likely defect on this screen, and
                                    it fails in the worst direction: a second copy
                                    of the window rule agrees on the day it is
                                    written and then badges an expired background
                                    check green.
                                -->
                                <span class="badge ms-1" :class="statusBadgeClass(credential.status)">
                                    {{ statusLabel(credential.status) }}
                                </span>
                            </div>

                            <div class="small text-muted">
                                <span v-if="credential.issuing_body">{{ credential.issuing_body }}</span>
                                <span v-if="credential.issuing_body && credential.expires_at" class="mx-1">·</span>
                                <span v-if="credential.expires_at">
                                    Expires {{ formatDate(credential.expires_at) }}
                                </span>
                                <span v-else>Does not expire</span>
                            </div>

                            <!--
                                A LICENCE NUMBER IS MASKED UNTIL SOMEBODY ASKS.

                                It is encrypted at rest and decrypts into this
                                payload on purpose, so the screen MAY show it —
                                but a member card is read over a shoulder at a
                                front desk, and a list of eight providers should
                                not print eight licence numbers to get one.
                                Last four, and a deliberate act to see the rest.
                            -->
                            <div v-if="credential.identifier" class="small mt-1">
                                <span class="text-muted me-1">Number:</span>
                                <span class="font-monospace">
                                    {{ revealed.has(credential.id) ? credential.identifier : maskIdentifier(credential.identifier) }}
                                </span>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm p-0 ms-2 align-baseline"
                                    @click="toggleRevealed(credential.id)"
                                >
                                    {{ revealed.has(credential.id) ? 'Hide' : 'Show' }}
                                </button>
                            </div>

                            <p
                                v-if="credential.notes"
                                class="small text-muted mb-0 mt-1"
                                style="white-space: pre-wrap;"
                            >{{ credential.notes }}</p>

                            <!--
                                The document is reached ONLY through the
                                authenticated endpoint the server named, and only
                                as a download.

                                Not an <a href> (the bearer token does not travel
                                on a browser navigation, so it would 401), not an
                                <img>/<iframe>, and never a /storage/ path — the
                                bytes sit on a disk with no public URL precisely
                                so a scanned safeguarding document cannot be read
                                by anyone who guesses at one
                                (.claude/rules/private-uploads.md).
                            -->
                            <div v-if="credential.document" class="small mt-1">
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm p-0 align-baseline"
                                    @click="downloadDocument(credential)"
                                    :disabled="downloadingId === credential.id"
                                >
                                    <span
                                        v-if="downloadingId === credential.id"
                                        class="spinner-border spinner-border-sm me-1"
                                        role="status"
                                    ></span>
                                    <i v-else class="bi bi-paperclip me-1"></i>
                                    {{ credential.document.file_name }}
                                </button>
                                <span class="text-muted ms-1">({{ documentSize(credential.document) }})</span>
                            </div>
                        </div>

                        <div class="btn-group btn-group-sm flex-shrink-0">
                            <button type="button" class="btn btn-outline-secondary" @click="openEdit(credential)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-danger"
                                @click="confirmDelete(credential)"
                                :disabled="deletingId === credential.id"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>

    <!-- Add / edit credential -->
    <Teleport to="body">
        <div
            v-if="showFormModal && contact"
            class="modal fade show d-block"
            tabindex="-1"
            style="background: rgba(0,0,0,0.5);"
            @click.self="closeFormModal"
        >
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-patch-check me-2"></i>
                            {{ editingId ? 'Edit credential' : 'Add credential' }}
                        </h5>
                        <button type="button" class="btn-close" @click="closeFormModal"></button>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Type <span class="text-danger">*</span></label>
                                    <!--
                                        OPTIONS COME FROM THE SERVER.

                                        `meta.kinds` is ContactCredential::KINDS —
                                        PHP constants, never a DB enum, so a new
                                        kind is one constant and no migration. A
                                        hardcoded array here would mean adding one
                                        silently required a Vue edit too, and the
                                        new kind would simply be missing from the
                                        form until somebody noticed.

                                        The LABELS below are cosmetic only, and
                                        fall back to a readable form of the
                                        constant, so an unlisted kind still shows
                                        up rather than rendering as a blank row.
                                    -->
                                    <select class="form-select" v-model="form.kind" required>
                                        <option v-for="kind in kinds" :key="kind" :value="kind">
                                            {{ kindName(kind) }}
                                        </option>
                                    </select>
                                </div>

                                <!--
                                    `other` is the escape hatch that lets KINDS
                                    stay small, and the free-text name is what
                                    stops it from losing information — which is
                                    why the server makes it required exactly then
                                    (required_if:kind,other) and so does this.
                                -->
                                <div class="col-md-6" v-if="form.kind === KIND_OTHER">
                                    <label class="form-label">Description <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" maxlength="255" v-model.trim="form.label" required>
                                    <div class="form-text">What is this credential called?</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Issuing body</label>
                                    <input type="text" class="form-control" maxlength="255" v-model.trim="form.issuing_body">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Licence / ID number</label>
                                    <!--
                                        Masked in the FORM too, not only in the
                                        list. Editing a record is not a reason to
                                        print a licence number onto a shared
                                        screen; the eye toggle is the same
                                        deliberate act the list asks for.
                                    -->
                                    <div class="input-group">
                                        <input
                                            :type="showIdentifierField ? 'text' : 'password'"
                                            class="form-control font-monospace"
                                            maxlength="255"
                                            autocomplete="off"
                                            v-model.trim="form.identifier"
                                        >
                                        <button
                                            class="btn btn-outline-secondary"
                                            type="button"
                                            @click="showIdentifierField = !showIdentifierField"
                                            :title="showIdentifierField ? 'Hide' : 'Show'"
                                        >
                                            <i class="bi" :class="showIdentifierField ? 'bi-eye-slash' : 'bi-eye'"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Issued on</label>
                                    <input type="date" class="form-control" v-model="form.issued_at">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Expires on</label>
                                    <input type="date" class="form-control" v-model="form.expires_at">
                                    <div class="form-text">Leave blank if it does not expire.</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" rows="2" v-model.trim="form.notes"></textarea>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Scanned document</label>
                                    <input
                                        type="file"
                                        class="form-control"
                                        :accept="acceptedDocumentTypes"
                                        @change="onFileChange"
                                    >
                                    <!--
                                        The allowlist and the size ceiling are
                                        config (config/credentials.php), env-
                                        overridable per deployment, and are
                                        enforced against the type SNIFFED from the
                                        bytes. No number is printed here on
                                        purpose: a second copy of the ceiling in
                                        TypeScript would be wrong on any fleet
                                        that tuned it, and a wrong number is worse
                                        than none. The server's own refusal is
                                        shown verbatim below if a file is too big
                                        or the wrong type.

                                        `accept` obeys the same rule, which is
                                        why it is BOUND rather than typed. It
                                        used to be a literal PDF/JPEG/PNG list
                                        written out here — a second copy of an
                                        env-tunable allowlist, in the very file
                                        whose comment above says not to keep one.
                                        A deployment that widens the list for
                                        phone-camera scans would then have a
                                        server that accepts the scan and a file
                                        picker that greys it out, with nothing on
                                        screen explaining why it cannot be
                                        chosen. The list comes from
                                        `meta.document_mime_types`, which is
                                        `config('credentials.document.mime_types')`.
                                    -->
                                    <div class="form-text">
                                        PDF or an image scan (JPG/PNG). Stored privately — it is only ever
                                        reachable through this screen, signed in.
                                    </div>
                                    <div v-if="editingId && editingDocumentName" class="form-text">
                                        Currently attached: <span class="fw-semibold">{{ editingDocumentName }}</span>.
                                        Choosing a file replaces it and deletes the old one.
                                    </div>
                                </div>
                            </div>

                            <div v-if="formError" class="alert alert-danger mt-3 mb-0 py-2 small">
                                {{ formError }}
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="closeFormModal" :disabled="saving">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-success" :disabled="saving || !canSubmit">
                                <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                {{ editingId ? 'Save changes' : 'Add credential' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import { Contact } from '@/core/types/data/masjid-related/Contact';
import {
    ContactCredential,
    CredentialDocument,
    CredentialPayload,
    CredentialStatus
} from '@/core/types/data/masjid-related/ContactCredential';
import { useContactCredentialsStore } from '@/stores/masjid/contactCredentialsStore';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * The credentials a Community org records against one volunteer: a DBS/background
 * check, a first-aid or BLS card, a medical or teaching licence (T-023).
 *
 * The server has held these since T-023 and nothing could see them. This panel is
 * the additive follow-on `.claude/rules/credentials.md` leaves room for, and it
 * respects all five of that rule's constraints:
 *
 *  1. NO NEW PERMISSION. Every call below rides the CONTACTS permissions inside
 *     the `crm` group — `view contacts` to read, `manage contacts` to write, the
 *     same gate the member directory this panel lives inside already passed. The
 *     seeded set stays at eight. Nothing here implies a "credentials" role, and
 *     nothing here is hidden or shown based on one.
 *  2. KINDS COME FROM `meta.kinds`, AND THE DOCUMENT ALLOWLIST FROM
 *     `meta.document_mime_types`. See the `<select>` and the file input above.
 *     Both lists are the server's — one a set of PHP constants, the other
 *     env-tunable config — and a copy of either typed here is wrong on the
 *     first deployment that changes it, silently: a kind missing from the form,
 *     or a scan the server accepts and the file picker greys out.
 *  3. STATUS IS READ, NEVER COMPUTED. See the badge above. There is deliberately
 *     no function in this file that takes an expiry date and returns a status.
 *  4. THE DOCUMENT IS REACHED ONLY THROUGH ITS AUTHENTICATED DOWNLOAD URL, as a
 *     download. Never inline, never `/storage/`.
 *  5. THE LICENCE NUMBER IS MASKED BY DEFAULT, in the list and in the form.
 *
 * WHAT THIS PANEL IS NOT: it sends nothing, schedules nothing and queues nothing.
 * The "expiring within N days" chip is a re-read of a list endpoint that already
 * exists. Expiry reminders and background-check integrations are out of scope by
 * rule, and adding either here — rather than as its own task with its own rule
 * entry — would put an automated email about a named person's safeguarding
 * paperwork on a path nobody reviewed.
 */
const props = defineProps<{
    contact: Contact | null;
}>();

const credentialsStore = useContactCredentialsStore();

/** Mirrors ContactCredential::KIND_OTHER — the one constant the FORM must branch on. */
const KIND_OTHER = 'other';

const loading = ref(false);
/**
 * Why the list is empty, when it is empty because something went wrong.
 *
 * Held separately from `formError` because they answer different questions and
 * appear in different places: this one replaces the list, that one sits under
 * the form in the modal.
 */
const loadError = ref('');
const saving = ref(false);
const deletingId = ref<number | null>(null);
const downloadingId = ref<number | null>(null);
const expiringOnly = ref(false);
/** Ids whose licence number the admin deliberately revealed. Reset whenever the list reloads. */
const revealed = ref<Set<number>>(new Set());

const showFormModal = ref(false);
const showIdentifierField = ref(false);
const editingId = ref<number | null>(null);
const editingDocumentName = ref<string | null>(null);
const formError = ref('');

const emptyForm = (): CredentialPayload => ({
    kind: '',
    label: '',
    issuing_body: '',
    identifier: '',
    issued_at: '',
    expires_at: '',
    notes: '',
    document: null
});

const form = ref<CredentialPayload>(emptyForm());

const credentials = computed<ContactCredential[]>(() => credentialsStore.credentials);
const kinds = computed<string[]>(() => credentialsStore.meta?.kinds ?? []);
/**
 * The file picker's filter, as the SERVER's `config('credentials.document.
 * mime_types')` names it — the same list the `mimetypes` rule enforces against
 * the type sniffed from the bytes.
 *
 * `undefined` — no `accept` attribute at all, so the picker offers everything
 * and the boundary answers — until meta has arrived. An empty string would not
 * do: Vue renders `accept=""`, and a picker filtered by a guess is worse than
 * one that is not filtered.
 */
const acceptedDocumentTypes = computed<string | undefined>(() => {
    const types = credentialsStore.meta?.document_mime_types ?? [];
    return types.length ? types.join(',') : undefined;
});
const expiringWindow = computed<number | undefined>(() => credentialsStore.meta?.expiring_within_days);

/**
 * Why "Add credential" is greyed out, in the tooltip, for the two reasons it
 * ever is — a disabled control with no explanation is the thing this screen
 * keeps being asked not to ship.
 *
 * Empty string when the button is live: Vue renders `title=""`, which is no
 * tooltip at all.
 */
const addCredentialBlockedReason = computed<string>(() => {
    if (loadError.value) return 'This person’s credentials could not be read.';
    if (!kinds.value.length) return 'Credential types have not loaded yet.';
    return '';
});

/**
 * Placeholder card stubs name nobody — they are the unidentified-card rows the
 * donation importer mints — so there is no person here to be licensed, and the
 * panel stays off exactly as the parent-portal block does.
 */
const isPlaceholder = computed<boolean>(() => Boolean((props.contact as any)?.is_placeholder));

const canSubmit = computed<boolean>(() => {
    if (!form.value.kind) return false;
    // Mirrors the server's `required_if:kind,other`, so the button does not
    // offer a submit the boundary is going to refuse.
    if (form.value.kind === KIND_OTHER && !form.value.label) return false;
    return true;
});

// --- Loading -----------------------------------------------------------------

const load = async (): Promise<void> => {
    if (!props.contact || isPlaceholder.value) return;

    loading.value = true;
    loadError.value = '';
    revealed.value = new Set();
    try {
        await credentialsStore.fetchCredentials(
            props.contact.id,
            expiringOnly.value ? expiringWindow.value : undefined
        );
    } catch (error) {
        // A FAILED READ IS NOT AN EMPTY RECORD, and on this screen the
        // difference matters more than on any other.
        //
        // This catch used to clear the list and say nothing, so a 403 or a 500
        // rendered as "No credentials recorded for this person." — a false
        // factual statement about somebody's background check, made to the one
        // person who consults this card to decide whether a volunteer may work
        // unsupervised, and made in the direction that hides a record rather
        // than inventing one. It also left `meta` unset, so "Add credential"
        // opened a form whose Type select had no options and whose submit was
        // greyed out forever with nothing on screen explaining why.
        //
        // Still no console call and still no payload: an axios error on this
        // endpoint carries decrypted licence numbers in `response.data`.
        // `apiErrorText` returns the server's SENTENCE, or the fallback.
        //
        // Emptying the list is the STORE's job, not this handler's, and
        // deliberately so: this catch also runs for a read whose card was
        // closed two clicks ago, and clearing from here would wipe the member
        // now on screen. The store drops a failure that is no longer the
        // current read, so reaching this line means the failure is ours.
        loadError.value = apiErrorText(error, 'Could not load this person’s credentials.');
    } finally {
        loading.value = false;
    }
};

/**
 * Reload when the card switches to a different member.
 *
 * Keyed on the ID rather than the object: ContactsView shows row data first and
 * then swaps in the hydrated contact, which is a new object for the same person
 * and must not cause a second fetch.
 *
 * `clear()` here is what keeps one member's licences off another member's card,
 * and it is only load-bearing because the STORE makes it so: emptying the array
 * would be undone the instant a reply already on the wire for the previous
 * member arrived, so clear() invalidates the in-flight read as well. This
 * comment used to make that promise on its own, and a cleared array could not
 * keep it.
 */
watch(
    () => props.contact?.id,
    (id, previous) => {
        if (id === previous) return;
        credentialsStore.clear();
        // A refusal belongs to the member it was refused for.
        loadError.value = '';
        expiringOnly.value = false;
        if (id) load();
    },
    { immediate: true }
);

const toggleExpiringOnly = async (): Promise<void> => {
    expiringOnly.value = !expiringOnly.value;
    await load();
};

// --- Rendering ---------------------------------------------------------------

/**
 * Human names for the kinds the server ships today.
 *
 * COSMETIC ONLY. It is not the option list — `meta.kinds` is — and anything it
 * does not know about falls through to a readable form of the constant, so a
 * kind added server-side appears in this screen immediately, spelled out from
 * its own value, with no Vue edit required.
 */
const KIND_NAMES: Record<string, string> = {
    medical_license: 'Medical licence',
    nursing_license: 'Nursing licence',
    background_check: 'Background check',
    bls_certification: 'BLS certification',
    acls_certification: 'ACLS certification',
    liability_insurance: 'Liability insurance',
    other: 'Other'
};

const kindName = (kind: string): string =>
    KIND_NAMES[kind] ?? kind.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** `other` carries its real name in `label`; everything else may use `label` as a subtitle. */
const kindLabel = (credential: ContactCredential): string =>
    credential.kind === KIND_OTHER && credential.label
        ? credential.label
        : (credential.label || kindName(credential.kind));

const STATUS_BADGES: Record<string, string> = {
    valid: 'text-bg-success',
    expiring: 'text-bg-warning',
    expired: 'text-bg-danger'
};

/** Unknown status -> a neutral badge that still SHOWS the value, never a hidden row. */
const statusBadgeClass = (status: CredentialStatus): string =>
    STATUS_BADGES[status] ?? 'text-bg-secondary';

const STATUS_NAMES: Record<string, string> = {
    valid: 'Valid',
    expiring: 'Expiring soon',
    expired: 'Expired'
};

const statusLabel = (status: CredentialStatus): string =>
    STATUS_NAMES[status] ?? String(status);

/**
 * `•••• 4821`. Anything shorter than five characters is masked WHOLE — a
 * four-character number is not partially hidden by showing four characters.
 */
const maskIdentifier = (identifier: string): string => {
    const value = identifier.trim();
    if (value.length < 5) return '••••';
    return `•••• ${value.slice(-4)}`;
};

const toggleRevealed = (id: number): void => {
    const next = new Set(revealed.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    revealed.value = next;
};

/**
 * An expiry date is a DAY, not an instant.
 *
 * The column is a `date` cast and serialises as midnight UTC, so
 * `new Date(iso).toLocaleDateString()` renders the day BEFORE for every admin
 * west of Greenwich — which on a compliance date is a real answer to a real
 * question, off by one. Parsed as a local calendar day instead, the way
 * JummahLunchView already does for service dates.
 */
const formatDate = (iso: string | null): string => {
    if (!iso) return '—';
    const day = String(iso).slice(0, 10);
    const parsed = new Date(`${day}T00:00:00`);
    return isNaN(parsed.getTime())
        ? day
        : parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

/** "PDF · 240 KB" — enough to tell a scan from a form before pulling it down. */
const documentSize = (document: CredentialDocument): string => {
    const kb = Math.max(1, Math.round(document.size_bytes / 1024));
    return kb < 1024 ? `${kb} KB` : `${(kb / 1024).toFixed(1)} MB`;
};

// --- The document ------------------------------------------------------------

/**
 * Pull the scan down and hand it to the browser as a FILE.
 *
 * Two rules meet here. The bytes live on a disk with no public URL and are
 * served by an authenticated endpoint, so the request has to carry the bearer
 * token — hence a blob fetch through the store rather than a link
 * (.claude/rules/private-uploads.md). And the document is a stranger's
 * safeguarding paperwork, so it is never rendered inline: no <img>, no <iframe>,
 * no new tab holding an object URL that outlives this click. The URL is revoked
 * immediately.
 */
const downloadDocument = async (credential: ContactCredential): Promise<void> => {
    const scan = credential.document;
    if (!scan) return;

    downloadingId.value = credential.id;
    try {
        const blob = await credentialsStore.fetchDocumentBlob(scan.download_url);

        const objectUrl = URL.createObjectURL(blob);
        const anchor = window.document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = scan.file_name;
        window.document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(objectUrl);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not download the document.' });
    } finally {
        downloadingId.value = null;
    }
};

// --- Writes ------------------------------------------------------------------

const openCreate = (): void => {
    editingId.value = null;
    editingDocumentName.value = null;
    formError.value = '';
    showIdentifierField.value = false;
    form.value = emptyForm();
    // Default to the server's first kind rather than a constant named here.
    form.value.kind = kinds.value[0] ?? '';
    showFormModal.value = true;
};

const openEdit = (credential: ContactCredential): void => {
    editingId.value = credential.id;
    editingDocumentName.value = credential.document?.file_name ?? null;
    formError.value = '';
    showIdentifierField.value = false;
    form.value = {
        kind: credential.kind,
        label: credential.label ?? '',
        issuing_body: credential.issuing_body ?? '',
        identifier: credential.identifier ?? '',
        // <input type="date"> wants a bare Y-m-d; the payload is ISO8601.
        issued_at: credential.issued_at ? String(credential.issued_at).slice(0, 10) : '',
        expires_at: credential.expires_at ? String(credential.expires_at).slice(0, 10) : '',
        notes: credential.notes ?? '',
        document: null
    };
    showFormModal.value = true;
};

const closeFormModal = (): void => {
    showFormModal.value = false;
    // The form held a decrypted licence number; do not leave it in component
    // state behind a closed modal.
    form.value = emptyForm();
    showIdentifierField.value = false;
};

const onFileChange = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    form.value.document = input.files?.[0] ?? null;
};

/**
 * A refusal in the server's own words — via the shared ladder, not a private
 * copy of it.
 *
 * The hand-rolled version this replaces read `data.data` only when it was a
 * STRING, then `data.message`, then `data.errors` — and this API returns none
 * of those on a field-level refusal. BaseFormRequest::failedValidation answers
 * `{status:'failed', data:{document:["The document may not be greater than
 * 8192 kilobytes."]}}`: a validation bag under `data`, no top-level `message`,
 * no `errors` key. So every 422 fell through to the generic sentence and an
 * admin whose 12MB scan was refused, or who left Description blank on
 * kind=other, was told only "please try again" — with nothing on screen naming
 * the field or the boundary. `apiErrorText` already handles both of this API's
 * envelopes and is what the other dashboard screens use.
 *
 * The privacy note that guarded the old copy still holds and is still
 * satisfied: this renders the MESSAGE, never the payload. A Laravel bag holds
 * the validator's sentences — a field name and a constraint — not the licence
 * number that was submitted, and nothing here touches `error.config.data`.
 */
const refusalText = (error: unknown): string =>
    apiErrorText(error, 'Could not save the credential. Please try again.');

const submit = async (): Promise<void> => {
    if (!props.contact || !canSubmit.value) return;

    saving.value = true;
    formError.value = '';
    try {
        if (editingId.value) {
            await credentialsStore.updateCredential(props.contact.id, editingId.value, form.value);
        } else {
            await credentialsStore.createCredential(props.contact.id, form.value);
        }
        closeFormModal();
        await load();
    } catch (error: any) {
        formError.value = refusalText(error);
    } finally {
        saving.value = false;
    }
};

/**
 * The confirmation says what actually happens, because it is not only a row
 * that goes: the model's `deleting` hook takes the scanned document off the
 * private disk with it, and there is no second copy.
 */
const confirmDelete = async (credential: ContactCredential): Promise<void> => {
    if (!props.contact) return;

    const result = await Swal.fire({
        icon: 'warning',
        title: 'Remove this credential?',
        text: credential.document
            ? 'The scanned document will be deleted too. This cannot be undone.'
            : 'This cannot be undone.',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        confirmButtonColor: '#dc3545'
    });
    if (!result.isConfirmed) return;

    deletingId.value = credential.id;
    try {
        // The boolean is READ, not discarded. `deleteCredential` answers false
        // for a 200 whose envelope is not `status: 'success'`, and a delete
        // that quietly did nothing while the screen refreshed and said nothing
        // is the worst outcome here: the coordinator believes a credential —
        // and its scan — is gone, and it is still on the disk.
        const removed = await credentialsStore.deleteCredential(props.contact.id, credential.id);
        await load();
        if (!removed) {
            Swal.fire({ icon: 'error', title: 'Error!', text: 'The credential was not removed.' });
        }
    } catch (error) {
        // The server's own sentence, same ladder as the form's refusal — a 403
        // here means "you may not manage contacts", which the admin can act on;
        // "Could not remove the credential." tells them nothing.
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: apiErrorText(error, 'Could not remove the credential.')
        });
    } finally {
        deletingId.value = null;
    }
};
</script>
