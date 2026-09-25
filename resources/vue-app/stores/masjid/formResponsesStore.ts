import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse, isAxiosError } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    FORM_CARD_PAGES,
    FormCashTotals,
    FormInsights,
    FormInsightsMeta,
    FormOption,
    FormPageUnreachable,
    FormPaidVia,
    FormResponseActionResult,
    FormResponseDetail,
    FormResponseFilters,
    FormResponseRow,
    FormResponsesMeta,
    FormResponseUpdatePayload,
    FormReservationsBoard,
    FormRosterMeta
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
 * is the single place filters become a query string, so the list, the CSV export, the
 * roster and the cash totals can never disagree about what is being shown.
 *
 * The door (DECISIONS.md 2026-09-11): collect / uncollect, take-cash and
 * mark-paid-external each answer with the row as it now stands. Their POSTs carry
 * FormData, the encoding PHP parses. Mark-paid-external's may hold `via`, how the money
 * came, which an office registration must send; take-cash's and mark-paid-external's hold
 * `confirm_holder_checked` only on the retry after a 409 page_unreachable.
 */
/**
 * A take-cash or mark-paid refusal saying the card page is on a Stripe account Manara can no
 * longer check: HTTP 409 with `code: 'page_unreachable'`. Anything else is null, and the
 * caller shows it as any other refusal.
 */
export function pageUnreachable(error: unknown): FormPageUnreachable | null {
    if (!isAxiosError(error) || error.response?.status !== 409) return null;

    const body: any = error.response?.data;
    if (!body || typeof body !== 'object' || body.code !== 'page_unreachable') return null;

    return {
        code: 'page_unreachable',
        message: typeof body.message === 'string' && body.message.trim()
            ? body.message
            : 'The card payment page for this registration can no longer be checked, so nothing was recorded.',
        holder_name: typeof body.holder_name === 'string' && body.holder_name.trim() ? body.holder_name : null,
        expires_at: typeof body.expires_at === 'string' && body.expires_at ? body.expires_at : null
    };
}

export const useFormResponsesStore = defineStore('formResponsesStore', () => {

    // State
    const formOptions = ref<FormOption[]>([]);
    const responsesPaginated = ref<PaginatedData<FormResponseRow>>();
    const responsesMeta = ref<FormResponsesMeta>();

    /**
     * The attendee roster: one row per PERSON rather than per submission. Kept in its own
     * slice so switching views does not blank the other one. Its meta carries the door
     * filters and payment block for the form it was read for, as the list's does.
     */
    const rosterPaginated = ref<PaginatedData<any>>();
    const rosterMeta = ref<FormRosterMeta>();

    /**
     * Manara Insights: aggregates over the same filtered set, in their own slice so
     * switching views does not blank the list or the roster. Never paginated — the whole
     * summary is one payload.
     */
    const insights = ref<FormInsights | null>(null);
    const insightsMeta = ref<FormInsightsMeta | null>(null);

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

    function requireMasjidId(): number | string {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        return id;
    }

    /**
     * Serialize the filter set the server understands. Blank values are omitted rather
     * than sent empty — `status=` would fail the Rule::in() check.
     *
     * `sort` is omitted when unset for the same reason: the screen clears it when it flips
     * between the registrations and the roster, and URLSearchParams would otherwise send
     * the word "undefined", which the list's sort allowlist refuses with a 422.
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
        if (filters.payment) params.append('payment', filters.payment);
        if (filters.collected) params.append('collected', filters.collected);
        if (filters.staff_code_id !== '' && filters.staff_code_id !== null && filters.staff_code_id !== undefined) {
            params.append('staff_code_id', String(filters.staff_code_id));
        }
        if (filters.sort) {
            params.append('sort', filters.sort);
            params.append('direction', filters.direction);
        }
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
     * and a `meta` block (the form, its schema-derived columns, the status list, the
     * sortable allowlist and the payment block) — both are stored so the table can render
     * headers and the detail modal can label answers without re-parsing the schema.
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

    /**
     * Fetch the attendee roster. Same filters as the list, so what an organiser is
     * looking at stays consistent when they flip between the two views.
     */
    async function fetchRoster(
        formId: number | string,
        filters: FormResponseFilters,
        page: number = 1
    ): Promise<void> {
        const id = masjidId();
        if (!id) return;

        if (rosterPaginated.value) {
            rosterPaginated.value.data = [];
        }

        const query = buildResponsesQuery(filters, page);

        await ApiService.get(`/api/admin/masjids/${id}/forms/${formId}/responses/roster?${query}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    rosterPaginated.value = res.data.data;
                    rosterMeta.value = res.data.meta;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch attendee roster error: ', e);
                throw e;
            });
    }

    /**
     * The cash each staff member holds, over the list's own filters (FormCashTotals).
     * The sort is left off: the totals are grouped, and on the roster the sort may be an
     * attendee column the submission endpoints refuse.
     */
    async function fetchCashTotals(formId: number | string, filters: FormResponseFilters): Promise<FormCashTotals> {
        const id = requireMasjidId();

        const params = new URLSearchParams(buildResponsesQuery(filters));
        params.delete('sort');
        params.delete('direction');

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/forms/${formId}/responses/cash-totals?${params.toString()}`
        );
        if (res.data?.status === 'success' && res.data?.data && Array.isArray(res.data.data.holders)) {
            return res.data.data;
        }

        throw new Error('Unexpected cash totals response.');
    }

    /**
     * The form's reservable dates and who holds each (FormResponsesController::reservations();
     * Ramadan giving, 2026-09-25). Not filtered: a date is held or not whatever the list shows.
     */
    async function fetchReservations(formId: number | string): Promise<FormReservationsBoard> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${id}/forms/${formId}/responses/reservations`);
        if (res.data?.status === 'success' && res.data?.data && Array.isArray(res.data.data.dates)) {
            return res.data.data;
        }

        throw new Error('Unexpected reservations response.');
    }

    /**
     * Manara Insights for one form, over the list's own filters (FormInsights).
     *
     * The sort is stripped for the same reason fetchCashTotals() strips it: a roster sort
     * key is not on the submission endpoints' allowlist and comes back a 422. Everything
     * else goes through buildResponsesQuery() untouched, so the summary asks about the
     * same set the table is showing.
     *
     * CAVEAT the screen must respect: at HEAD the server applies only q / status / from /
     * to here, and silently ignores the door's `payment`, `collected` and `staff_code_id`
     * — which the list, the roster, the CSV and the cash totals all honour. They are still
     * sent (so this starts working the day FormInsightsController routes through
     * FormResponsesController::query()), but until then the view REFUSES to render the
     * summary while a door filter is on, rather than quietly disagreeing with the table
     * above it. `meta.filtered` has the same gap and is trustworthy only in that state.
     *
     * On failure the previous summary is cleared before the error is rethrown: a stale
     * summary sitting under a fresh filter row is a wrong answer, not a slow one.
     */
    async function fetchInsights(formId: number | string, filters: FormResponseFilters): Promise<void> {
        const id = masjidId();
        if (!id) return;

        const params = new URLSearchParams(buildResponsesQuery(filters));
        params.delete('sort');
        params.delete('direction');

        await ApiService.get(`/api/admin/masjids/${id}/forms/${formId}/insights?${params.toString()}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    insights.value = res.data.data;
                    insightsMeta.value = res.data.meta ?? null;
                    return;
                }

                // A 200 THAT IS NOT A SUMMARY IS A FAILURE, and it has to be raised as
                // one. FormInsightsController answers its own catch with
                // `{status:'error'}` and a 500, but a proxy, a maintenance page or an
                // expired session redirected to HTML all arrive here as a 2xx whose body
                // is not this shape. Falling through silently left the previous summary
                // in place (wrong, under a fresh filter row) or the slice empty with no
                // error worded — which the panel could only render as a blank region.
                // fetchCashTotals() in this file already refuses the same way. The wording
                // is the admin's, not a developer's, because serverMessage() prints a bare
                // Error's own message straight into the panel.
                throw new Error('The summary did not come back in a readable form. Try again.');
            })
            .catch((e: Error) => {
                insights.value = null;
                insightsMeta.value = null;
                console.error('Fetch form insights error: ', e);
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
     *
     * Only the keys present in `payload` are sent. A request saying "cancelled" is answered
     * with `card_page` (what that did about the card payment page), and with a `message`
     * and `warning` when there is something to say.
     */
    async function updateResponse(
        formId: number | string,
        responseId: number | string,
        payload: FormResponseUpdatePayload
    ): Promise<FormResponseActionResult> {
        const id = requireMasjidId();

        // ApiService.put sends application/x-www-form-urlencoded, the encoding PHP parses
        // on a PUT; serialize the body to URLSearchParams (matches contactsStore.updateContact).
        const body = new URLSearchParams();
        if (payload.status !== undefined) body.append('status', payload.status);
        if (payload.admin_notes !== undefined) body.append('admin_notes', payload.admin_notes);

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}`,
            body
        );

        return actionResult(res, 'Failed to update response.');
    }

    /** Delete a response outright — spam and test submissions. Refused for money rows. */
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

    // ---------------------------------------------------------------- the door

    /** Mark collected: stamped by the first press. Refused unless settled and not cancelled. */
    async function collectResponse(formId: number | string, responseId: number | string): Promise<FormResponseActionResult> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}/collect`,
            new FormData()
        );

        return actionResult(res, 'Could not mark this registration collected.');
    }

    /** Undo "Mark collected". */
    async function uncollectResponse(formId: number | string, responseId: number | string): Promise<FormResponseActionResult> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}/collect`
        );

        return actionResult(res, 'Could not undo the collection.');
    }

    /**
     * Cash taken at the table for an unpaid registration. The server closes any open card
     * payment page first, and records nothing if Stripe says the payer has just paid.
     *
     * `confirmHolderChecked` is sent only after a 409 page_unreachable (pageUnreachable()),
     * once the admin has ticked that they checked the holder's Stripe dashboard. It is never
     * sent otherwise, so every other take-cash posts exactly what it did before.
     */
    async function takeCash(
        formId: number | string,
        responseId: number | string,
        confirmHolderChecked: boolean = false
    ): Promise<FormResponseActionResult> {
        const id = requireMasjidId();

        const body = new FormData();
        if (confirmHolderChecked) body.append('confirm_holder_checked', '1');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}/take-cash`,
            body
        );

        return actionResult(res, 'Could not record the cash.');
    }

    /**
     * "Mark paid (external)": paid somewhere else, the Wix page. Same card-page rule as take-cash.
     *
     * `via` is how the money came (zelle / cashapp / venmo / check, meta.payment.paid_via). The server REQUIRES it on
     * a registration whose family chose to pay the office, and refuses without it in words the
     * screen shows. It is not sent when null, so every other registration posts exactly what
     * it did before.
     */
    async function markPaidExternal(
        formId: number | string,
        responseId: number | string,
        via: FormPaidVia | null = null,
        confirmHolderChecked: boolean = false
    ): Promise<FormResponseActionResult> {
        const id = requireMasjidId();

        const body = new FormData();
        if (via) body.append('via', via);
        // As on takeCash(): only on the retry after a 409 page_unreachable.
        if (confirmHolderChecked) body.append('confirm_holder_checked', '1');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/forms/${formId}/responses/${responseId}/mark-paid-external`,
            body
        );

        return actionResult(res, 'Could not mark this registration paid.');
    }

    function actionResult(res: AxiosResponse, failure: string): FormResponseActionResult {
        if (res.data?.status === 'success' && res.data?.data) {
            return {
                data: res.data.data,
                message: typeof res.data.message === 'string' && res.data.message ? res.data.message : null,
                warning: res.data.warning === true,
                // Only a triage save saying "cancelled" carries it (FormResponsesController::update()).
                card_page: FORM_CARD_PAGES.includes(res.data.card_page) ? res.data.card_page : null
            };
        }

        throw new Error(failure);
    }

    return {
        pageUnreachable,
        rosterPaginated,
        rosterMeta,
        fetchRoster,
        insights,
        insightsMeta,
        fetchInsights,
        formOptions,
        responsesPaginated,
        responsesMeta,
        masjidId,
        buildResponsesQuery,
        fetchFormOptions,
        fetchResponses,
        fetchResponse,
        fetchCashTotals,
        fetchReservations,
        updateResponse,
        deleteResponse,
        collectResponse,
        uncollectResponse,
        takeCash,
        markPaidExternal
    }
})
