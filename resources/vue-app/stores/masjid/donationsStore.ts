import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Donation, DonationStatus } from "@/core/types/data/masjid-related/Donation";
import { DonationLedgerFilters } from "@/core/types/data/masjid-related/DonationStats";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";

/**
 * Donations ledger store — READ-ONLY over /api/admin/masjids/{masjid_id}/donations.
 *
 * Stripe gifts are created and advanced ONLY by webhooks, so there is no
 * update/delete here by design (offline gifts are recorded by DonationsView's own
 * POST). The active masjid comes from the shared active-masjid context; the
 * backend `tenant` middleware + BelongsToMasjid trait scope every read to this
 * admin's own masjid.
 *
 * buildLedgerQuery() is the single place filters become a query string, so the
 * list on screen, the page the admin pages through, and the CSV the accountant
 * downloads can never describe different sets of gifts — the server backs this
 * with one shared query builder (DonationsController::filteredQuery).
 */
export const useDonationsStore = defineStore('donationsStore', () => {

    // State
    const donationsPaginated = ref<PaginatedData<Donation>>();

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /**
     * dashboardMasjidId is restored from localStorage at auth time, so it survives
     * a hard refresh that has not yet finished hydrating masjidStore.masjid.
     */
    function masjidId(): number | string | null {
        return authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null;
    }

    /**
     * Serialize the filter set the server understands. Blank values are omitted
     * rather than sent empty — `status=` fails the server's `in:` rule.
     *
     * `page` is optional so the export can reuse this untouched: a CSV covers the
     * whole filtered result set, not the page that happens to be on screen.
     */
    function buildLedgerQuery(filters: DonationLedgerFilters, page: number | null = null): string {
        const params = new URLSearchParams();

        if (filters.status) params.append('status', filters.status);
        if (filters.fund_id !== '' && filters.fund_id !== null) params.append('fund_id', String(filters.fund_id));
        if (filters.source) params.append('source', filters.source);
        if (filters.search.trim()) params.append('search', filters.search.trim());
        // from/to bound the canonical gift date, COALESCE(donated_at, created_at) —
        // not created_at, or every offline gift would be dated by when it was typed in.
        if (filters.from) params.append('from', filters.from);
        if (filters.to) params.append('to', filters.to);
        if (page !== null) params.append('page', String(page));

        return params.toString();
    }

    /** Fetch a page of the ledger under the full filter set. */
    async function fetchLedger(filters: DonationLedgerFilters, page: number = 1): Promise<void> {
        const id = masjidId();
        // Throw rather than return quietly: a silent return resolves the caller's
        // await, so its try/catch books a successful load and the screen keeps the
        // rows it already had, indistinguishable from a fresh page of gifts.
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        if (donationsPaginated.value) {
            donationsPaginated.value.data = [];
        }

        const query = buildLedgerQuery(filters, page);

        // BackendApiRoute only models `?page=N` on this endpoint, not a full filter
        // string. The cast narrows the QUERY, never the path — that is still the
        // union's own `.../donations` member.
        await ApiService.get(`/api/admin/masjids/${id}/donations?${query}` as BackendApiRoute)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    donationsPaginated.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch donations error: ', e);
                throw e;
            });
    }

    /**
     * Positional convenience for the plain ledger screen, which has no date or
     * source filter. Kept as the narrow front door onto fetchLedger so both
     * screens go through one request path.
     */
    async function fetchDonations(
        page: number = 1,
        status: DonationStatus | '' = '',
        fundId: number | string | '' = '',
        search: string = ''
    ): Promise<void> {
        await fetchLedger({
            from: '',
            to: '',
            fund_id: typeof fundId === 'string' && fundId !== '' ? Number(fundId) : (fundId as number | ''),
            source: '',
            status,
            search
        }, page);
    }

    /** Fetch a single donation (with its fund + receipt eager-loaded) by id. */
    async function fetchDonation(id: number | string): Promise<Donation | null> {
        const masjid = masjidId();
        if (masjid) {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjid}/donations/${id}`
            );
            if (res.data?.status === 'success' && res.data?.data) {
                return res.data.data;
            }
        }
        return null;
    }

    /**
     * Download the filtered ledger as CSV.
     *
     * A raw fetch rather than ApiService: axios here is configured for JSON, and
     * the browser needs both the bytes and the server's Content-Disposition to
     * save a file. The query comes from the same builder the list uses, so the
     * export can only ever contain what the admin is looking at.
     */
    async function exportCsv(filters: DonationLedgerFilters): Promise<void> {
        const id = masjidId();
        // Same reason as fetchLedger, and louder here: a silent return ends the
        // export with the spinner stopping and no file, no error, nothing.
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
        const res = await fetch(`/api/admin/masjids/${id}/donations/export?${buildLedgerQuery(filters)}`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'text/csv' }
        });

        // fetch only rejects on a network failure, so a 403/422 would otherwise be
        // saved to disk as a file full of JSON.
        if (!res.ok) throw new Error(`Donation export failed with ${res.status}`);

        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = csvFilename(res.headers.get('Content-Disposition'));
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    /** Prefer the name the server chose (it carries the masjid name); fall back to a dated default. */
    function csvFilename(contentDisposition: string | null): string {
        const match = contentDisposition?.match(/filename="?([^";]+)"?/i);
        if (match?.[1]) return match[1];

        return `donations-${new Date().toISOString().slice(0, 10)}.csv`;
    }

    return {
        donationsPaginated,
        buildLedgerQuery,
        fetchLedger,
        fetchDonations,
        fetchDonation,
        exportCsv
    }
})
