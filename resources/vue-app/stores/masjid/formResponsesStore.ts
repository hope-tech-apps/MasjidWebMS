import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    FormOption,
    FormResponseDetail,
    FormResponseFilters,
    FormResponseRow,
    FormResponsesMeta,
    FormResponseUpdatePayload
} from "@/core/types/data/masjid-related/Form";

/**
 * Form Responses store — reads and triages submissions to one masjid's sign-up forms
 * over /api/admin/masjids/{masjid_id}/forms.
 *
 * The active masjid comes from the same active-masjid context every other
 * masjid-scoped store uses; the backend resolves every form through `$masjid->forms()`
 * so one tenant can never read another's registrations by guessing a form id.
 *
 * Search, filtering, sorting and pagination are ALL server-side. buildResponsesQuery()
 * is the single place filters become a query string, so the list and the CSV export
 * can never disagree about what is being shown.
 */
export const useFormResponsesStore = defineStore('formResponsesStore', () => {

    // State
    const formOptions = ref<FormOption[]>([]);
    const responsesPaginated = ref<PaginatedData<FormResponseRow>>();
    const responsesMeta = ref<FormResponsesMeta>();

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /**
     * The masjid whose data this screen is showing. dashboardMasjidId is set from
     * localStorage at auth time, so it survives a hard refresh that has not yet
     * finished hydrating masjidStore.masjid.
     */
    function masjidId(): number | string | null {
        return authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null;
    }

    /**
     * Serialize the filter set the server understands. Blank values are omitted rather
     * than sent empty — `status=` would fail the Rule::in() check.
     *
     * `page` is optional so the export can reuse this untouched: a CSV covers the whole
     * filtered result set, not the page the admin happens to be looking at.
     */
    function buildResponsesQuery(filters: FormResponseFilters, page: number | null = null): string {
        const params = new URLSearchParams();

        if (filters.q.trim()) params.append('q', filters.q.trim());
        if (filters.status) params.append('status', filters.status);
        if (filters.from) params.append('from', filters.from);
        if (filters.to) params.append('to', filters.to);
        params.append('sort', filters.sort);
        params.append('direction', filters.direction);
        if (page !== null) params.append('page', String(page));

        return params.toString();
    }

    /** The form picker's list. Empty when this masjid has no forms yet. */
    async function fetchFormOptions(): Promise<FormOption[]> {
        const id = masjidId();
        if (!id) return [];

        const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${id}/forms/options`);
        if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
            formOptions.value = res.data.data;
        }

        return formOptions.value;
    }

    /**
     * Fetch a page of responses for one form. The response carries both the paginator
     * and a `meta` block (the form, its schema-derived columns, the status list and the
     * sortable allowlist) — both are stored so the table can render headers and the
     * detail modal can label answers without re-parsing the schema.
     */
    async function fetchResponses(
        formId: number | string,
        filters: FormResponseFilters,
        page: number = 1
    ): Promise<void> {
        const id = masjidId();
        if (!id) return;

        if (responsesPaginated.value) {
            responsesPaginated.value.data = [];
        }

        const query = buildResponsesQuery(filters, page);

        await ApiService.get(`/api/admin/masjids/${id}/forms/${formId}/responses?${query}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    responsesPaginated.value = res.data.data;
                    responsesMeta.value = res.data.meta;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch form responses error: ', e);
                throw e;
            });
    }

    /** Fetch one response — the only endpoint that returns the full submission. */
    async function fetchResponse(
        formId: number | string,
        responseId: number | string
    ): Promise<FormResponseDetail | null> {
        const id = masjidId();
        if (!id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}`
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        return null;
    }

    /**
     * Triage a response. Only status and admin_notes are accepted by the API — the
     * submitted answers are evidence of what somebody agreed to and stay immutable.
     */
    async function updateResponse(
        formId: number | string,
        responseId: number | string,
        payload: FormResponseUpdatePayload
    ): Promise<FormResponseDetail> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        // ApiService.put sends application/x-www-form-urlencoded; serialize the body to
        // URLSearchParams (matches contactsStore.updateContact).
        const body = new URLSearchParams();
        body.append('status', payload.status);
        body.append('admin_notes', payload.admin_notes ?? '');

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}`,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to update response.');
    }

    /** Delete a response outright — used for spam and test submissions. */
    async function deleteResponse(
        formId: number | string,
        responseId: number | string
    ): Promise<boolean> {
        const id = masjidId();
        if (!id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}`
        );

        return res.data?.status === 'success';
    }

    return {
        formOptions,
        responsesPaginated,
        responsesMeta,
        masjidId,
        buildResponsesQuery,
        fetchFormOptions,
        fetchResponses,
        fetchResponse,
        updateResponse,
        deleteResponse
    }
})
