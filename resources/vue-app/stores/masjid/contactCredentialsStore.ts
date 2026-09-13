import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import {
    ContactCredential,
    CredentialMeta,
    CredentialPayload
} from "@/core/types/data/masjid-related/ContactCredential";

/**
 * Volunteer credentials over
 * /api/admin/masjids/{masjid_id}/contacts/{contact_id}/credentials (T-023).
 *
 * Same shape as the other masjid-scoped stores: the active masjid comes from
 * masjidStore, and isolation is the server's — `tenant` + BelongsToMasjid, with
 * the controller re-resolving masjid -> contact -> credential so a foreign id
 * anywhere in the chain is a 404 MISS. Nothing here filters by tenant, and
 * nothing here may start.
 *
 * FOUR THINGS THIS STORE DELIBERATELY DOES NOT DO, each of them a rule in
 * `.claude/rules/credentials.md` that a store is tempted to break:
 *
 *  1. IT DOES NOT COMPUTE STATUS. `valid / expiring / expired` arrives on the
 *     payload, derived by the server from `expires_at` against
 *     `config('credentials.expiring_within_days')`. A `computed` here that read
 *     an expiry date and decided for itself would be a second copy of that
 *     window rule — right today, silently wrong the day the config moves, and
 *     wrong in the direction that badges an expired background check green.
 *  2. IT DOES NOT KNOW THE KINDS — OR THE DOCUMENT ALLOWLIST. `meta` is kept in
 *     state precisely so the form can render its options from the SERVER's
 *     `meta.kinds` and filter its file picker with the SERVER's
 *     `meta.document_mime_types`. Adding a kind to `ContactCredential::KINDS`,
 *     or widening `CREDENTIAL_DOCUMENT_MIME_TYPES` in one tenant's .env, must
 *     reach the screen with no Vue edit at all.
 *  3. IT NEVER LOGS. The same reasoning as appointmentRequestsStore: an axios
 *     error carries `response.data` (here: a licence number, decrypted) and
 *     `config.data` (here: the form the admin just typed). `console.error(e)`
 *     on this file would print a provider's medical licence number into the
 *     browser console and into anything that ever wraps it. Errors are rethrown
 *     untouched; the view renders a message, never a payload.
 *  4. IT NEVER BUILDS A FILE URL. The only route to a scanned document is the
 *     server-supplied `document.download_url`, fetched WITH the Authorization
 *     header (`.claude/rules/private-uploads.md`). Constructing a `/storage/...`
 *     path would make a safeguarding document world-readable to anyone who
 *     guessed it, which is the exact failure that rule exists to prevent.
 *
 * AND ONE THING IT MUST DO: SEQUENCE ITS READS. See `fetchCredentials`. This is
 * a single shared store rendered inside a per-member card, so "the reply that
 * arrived last wins" is not a race-condition curiosity here — it is a
 * disclosure. The guard below is the only thing that makes the panel's promise
 * ("one member's licences can never be on screen under another member's name")
 * true.
 */
export const useContactCredentialsStore = defineStore('contactCredentialsStore', () => {

    // State
    const credentials = ref<ContactCredential[]>([]);
    /**
     * The read this store is currently willing to accept an answer for.
     *
     * Bumped by every `fetchCredentials` and by `clear()`, and captured at the
     * top of each request; a reply whose ticket is no longer the current one is
     * dropped on the floor. Deliberately a plain `let` and not a `ref`: nothing
     * renders it, and making it reactive would only invite a `watch` on it.
     */
    let currentRead = 0;
    /** The contact id `currentRead` was issued for — what makes the guard legible on a per-person screen. */
    let currentContactId: number | string | null = null;
    /**
     * The last `meta` any endpoint returned — the server's vocabulary.
     *
     * Kept across calls rather than cleared per fetch, so re-opening a member's
     * card does not blank the form's options for a frame. Undefined only before
     * the very first response.
     */
    const meta = ref<CredentialMeta>();

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * This contact's credentials, soonest expiry first (the server orders them;
     * the screen must not re-sort by a status it did not compute).
     *
     * `expiringWithinDays` is the RENEWAL CHASE read — the same list narrowed to
     * credentials falling due inside that window. It is a query parameter on an
     * endpoint that already exists, and it is not a reminder: nothing here
     * schedules, queues or emails anything, and nothing may be added that does
     * (expiry notifications are explicitly out of scope in
     * `.claude/rules/credentials.md` and would need their own task).
     *
     * The window sent is the one the SERVER named in `meta.expiring_within_days`,
     * never a literal typed here.
     */
    async function fetchCredentials(
        contactId: number | string,
        expiringWithinDays?: number
    ): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        // THE READ IS SEQUENCED, AND THIS IS A DISCLOSURE CONTROL, NOT A TIDINESS
        // ONE.
        //
        // ContactsView destroys and re-creates this panel per member card while
        // leaving `selectedContact` set, and every instance shares this one
        // store. So an admin who opens member A's card, closes it and opens
        // member B's has A's request still in flight. Without this guard the
        // assignment below is "whoever answers last wins": A's slow reply lands
        // after B's and the card headed with B's NAME then lists A's licence
        // numbers, A's document file names, and a paperclip that downloads A's
        // background check. Clearing the list before the request — which the
        // panel does — cannot help, because the reply was already on the wire.
        //
        // Each read takes a ticket. A reply presenting a stale ticket is
        // discarded entirely: neither `credentials` nor `meta` is touched, so a
        // late answer cannot even repaint the vocabulary out from under the
        // person now on screen.
        const ticket = ++currentRead;
        currentContactId = contactId;

        const base = `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/credentials`;
        const url = expiringWithinDays
            ? `${base}?expiring_within_days=${expiringWithinDays}`
            : base;

        let res: AxiosResponse;

        try {
            res = await ApiService.get(url as BackendApiRoute);
        } catch (error) {
            // A FAILURE IS SEQUENCED TOO, and this half is easy to miss.
            //
            // The view's catch used to be the thing that emptied the list. That
            // put the same disclosure back the other way round: A's read fails
            // slowly, B's has already succeeded, and A's handler — running in a
            // component the router destroyed two clicks ago — wipes the list
            // under B's name, leaving B's card saying nobody recorded anything.
            // So the stale failure is swallowed here: nobody is waiting for it.
            // The current one clears the list (a failed read must not leave the
            // previous answer on screen) and is rethrown untouched for the view
            // to render as a message — never as a payload.
            if (ticket !== currentRead || contactId !== currentContactId) return;

            credentials.value = [];
            throw error;
        }

        // Both halves of the guard: the ticket catches two reads for the SAME
        // person (the expiring-window chip toggled twice), the contact id is
        // what makes the intent readable to the next person to touch this file.
        if (ticket !== currentRead || contactId !== currentContactId) return;

        if (res.data?.status === 'success') {
            credentials.value = res.data.data ?? [];
            if (res.data.meta) meta.value = res.data.meta;
        }
    }

    /**
     * Drop the list when a member's card closes, so the next one cannot flash
     * the previous person's licences.
     *
     * Invalidates the in-flight read as well. Emptying the array alone would be
     * undone the moment a reply already on the wire arrived — the card is shut,
     * nobody asked for anything, and the previous member's credentials would
     * quietly repopulate the store for whichever card opens next.
     */
    function clear(): void {
        currentRead++;
        currentContactId = null;
        credentials.value = [];
    }

    /**
     * The multipart body both writes share.
     *
     * Empty strings are sent as empty strings rather than omitted: on an UPDATE
     * every field is `sometimes`, so omitting a cleared field would silently
     * keep the old value and an admin who deleted a wrong licence number would
     * watch it come back. `document` is appended only when a file was chosen —
     * sending nothing leaves the stored scan alone, which is the whole
     * difference between "no new scan" and "replace the scan".
     *
     * `masjid_id` and `contact_id` are absent and must stay absent: the server
     * derives both, and neither is even validated, so a payload cannot re-parent
     * a credential onto somebody else's record.
     */
    function toFormData(payload: CredentialPayload): FormData {
        const body = new FormData();

        body.append('kind', payload.kind);
        body.append('label', payload.label ?? '');
        body.append('issuing_body', payload.issuing_body ?? '');
        body.append('identifier', payload.identifier ?? '');
        body.append('issued_at', payload.issued_at ?? '');
        body.append('expires_at', payload.expires_at ?? '');
        body.append('notes', payload.notes ?? '');

        if (payload.document) {
            body.append('document', payload.document);
        }

        return body;
    }

    /** Record a credential on this contact. 201 on success; a 422 is a readable refusal. */
    async function createCredential(
        contactId: number | string,
        payload: CredentialPayload
    ): Promise<ContactCredential> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/credentials` as BackendApiRoute,
            toFormData(payload)
        );

        if (res.data?.status === 'success' && res.data?.data) {
            if (res.data.meta) meta.value = res.data.meta;
            return res.data.data;
        }
        throw new Error('Failed to save the credential.');
    }

    /**
     * Update one credential — the renewal path (new expiry date, usually a new
     * scan with it).
     *
     * POSTed to the PUT path with `_method=PUT` rather than sent as a real PUT:
     * PHP does not parse a multipart PUT body, so an actual `ApiService.put()`
     * carrying a file would arrive with every field empty and answer 422. The
     * same spoof SectionFormModal uses for section images, and required here
     * because the update path really does accept a replacement document.
     */
    async function updateCredential(
        contactId: number | string,
        credentialId: number | string,
        payload: CredentialPayload
    ): Promise<ContactCredential> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = toFormData(payload);
        body.append('_method', 'PUT');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/credentials/${credentialId}` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            if (res.data.meta) meta.value = res.data.meta;
            return res.data.data;
        }
        throw new Error('Failed to update the credential.');
    }

    /**
     * Remove a credential. The server's model-layer `deleting` hook takes the
     * scanned document off the private disk with it — which is why the screen
     * says so before asking for confirmation.
     */
    async function deleteCredential(
        contactId: number | string,
        credentialId: number | string
    ): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/credentials/${credentialId}` as BackendApiRoute
        );

        return res.data?.status === 'success';
    }

    /**
     * Pull a scanned document down as a Blob.
     *
     * Through the axios instance rather than `ApiService.get`, for the reason
     * flyersStore and groupFeedStore already record: these bytes are served by
     * an AUTHENTICATED endpoint, so the request needs the Authorization header
     * the instance already carries, and `ApiService.get()` cannot ask for a
     * blob. A plain `<a href target="_blank">` would 401.
     *
     * `downloadUrl` is whatever the SERVER put in `document.download_url` and is
     * passed through untouched — this function must never be handed a path
     * assembled in the SPA.
     */
    async function fetchDocumentBlob(downloadUrl: string): Promise<Blob> {
        const res: AxiosResponse = await ApiService.VueApp.axios.get(downloadUrl, { responseType: 'blob' });

        return res.data as Blob;
    }

    return {
        credentials,
        meta,
        fetchCredentials,
        clear,
        createCredential,
        updateCredential,
        deleteCredential,
        fetchDocumentBlob
    }
})
