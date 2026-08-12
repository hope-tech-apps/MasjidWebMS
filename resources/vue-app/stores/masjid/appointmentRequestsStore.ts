import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    AppointmentRequest,
    AppointmentRequestFilters,
    AppointmentRequestNote,
    AppointmentRequestQueueRow,
    AppointmentRequestStatus,
    AppointmentRequestsMeta
} from "@/core/types/data/masjid-related/AppointmentRequest";

/**
 * Appointment requests — the clinic's triage queue over
 * /api/admin/masjids/{masjid_id}/appointment-requests.
 *
 * Same shape as groupsStore: the active masjid comes from masjidStore, the
 * backend `tenant` middleware + BelongsToMasjid keep this admin inside their own
 * organization, and `meta` is kept because the SERVER is the authority on the
 * triage vocabulary — nothing here hardcodes the status set.
 *
 * TWO DELIBERATE DIFFERENCES FROM THE GROUP STORES, both because these rows are
 * PHI-adjacent (.claude/rules/appointments.md):
 *
 *  1. NOTHING IS LOGGED. groupsStore does `console.error('Fetch groups error',
 *     e)` on a failed fetch; an Axios error carries `response.data` (here: a
 *     patient's record) and `config.data` (here: a note body), so the same line
 *     on this store would print a health record into the browser console and
 *     into any error-reporting shim that ever wraps it. Errors are rethrown
 *     untouched and the VIEW renders `apiErrorText(error)` — a message, never a
 *     payload. Do not add a console call to this file.
 *  2. Filters are passed as arguments and held in component state, never pushed
 *     into the SPA's own route query. A search term here is a patient's name;
 *     putting it in the address bar would write it into browser history.
 */
export const useAppointmentRequestsStore = defineStore('appointmentRequestsStore', () => {

    // State
    const requestsPaginated = ref<PaginatedData<AppointmentRequestQueueRow>>();
    const requestsMeta = ref<AppointmentRequestsMeta>();

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * A page of the queue.
     *
     * Every filter is optional and omitted when empty, so the request URL
     * carries a patient's name only when someone actually searched for one.
     */
    async function fetchRequests(
        page: number = 1,
        filters: Partial<AppointmentRequestFilters> = {}
    ): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (requestsPaginated.value) {
            requestsPaginated.value.data = [];
        }

        const query = new URLSearchParams({ page: String(page) });
        if (filters.status) query.append('status', filters.status);
        if (filters.search) query.append('search', filters.search);
        // Only the non-default ordering needs saying.
        if (filters.sort === 'oldest') query.append('sort', 'oldest');

        await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/appointment-requests?${query.toString()}` as BackendApiRoute
        ).then((res: AxiosResponse) => {
            if (res.data?.status === 'success' && res.data?.data) {
                requestsPaginated.value = res.data.data;
                requestsMeta.value = res.data.meta;
            }
        });
    }

    /**
     * One request in full: the encrypted fields and the notes thread ride along
     * on this endpoint, which is why it is a separate, deliberate read rather
     * than something the list already holds.
     *
     * A cross-tenant or deleted id is a 404 MISS by design; the caller decides
     * how to render that (see .claude/rules/tenant-scoping.md).
     */
    async function fetchRequest(id: number | string): Promise<AppointmentRequest | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/appointment-requests/${id}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            requestsMeta.value = res.data.meta;
            return res.data.data;
        }
        return null;
    }

    /**
     * Move a request through triage.
     *
     * The server validates against its own STATUSES with Rule::in and accepts
     * ANY of them in ANY order — triage is a label, not a workflow. The UI must
     * not add ordering the backend does not have.
     */
    async function updateStatus(
        id: number | string,
        status: AppointmentRequestStatus
    ): Promise<AppointmentRequest> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        // Urlencoded, like every other edit path in this SPA (ApiService.patch
        // defaults to that content type).
        const body = new URLSearchParams();
        body.append('status', status);

        const res: AxiosResponse = await ApiService.patch(
            `/api/admin/masjids/${masjidStore.masjid.id}/appointment-requests/${id}/status` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to update the status.');
    }

    /**
     * Add an internal staff note. The author and the tenant are server-derived —
     * neither is sent from here, and sending them would be ignored anyway.
     */
    async function addNote(id: number | string, body: string): Promise<AppointmentRequestNote> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const payload = new FormData();
        payload.append('body', body);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/appointment-requests/${id}/notes` as BackendApiRoute,
            payload
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to save the note.');
    }

    return {
        requestsPaginated,
        requestsMeta,
        fetchRequests,
        fetchRequest,
        updateStatus,
        addNote
    }
})
