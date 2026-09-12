import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Donation, DonationReceipt, DonationStatus } from "@/core/types/data/masjid-related/Donation";
import { DonationLedgerFilters } from "@/core/types/data/masjid-related/DonationStats";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";

/**
 * Donations ledger store over /api/admin/masjids/{masjid_id}/donations.
 *
 * Read-only over the GIFTS themselves: Stripe rows are created and advanced ONLY
 * by webhooks, so there is no update/delete here by design (offline gifts are
 * recorded by DonationsView's own POST). The one write that lives here is
 * issueReceipt — a tax document is a separate record from the gift, and the
 * treasurer issues it deliberately, per gift, once. The active masjid comes from
 * the shared active-masjid context; the backend `tenant` middleware +
 * BelongsToMasjid trait scope every call to this admin's own masjid.
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
        // Zakat is the one filter whose FALSE is a request rather than an absence:
        // "show me the gifts carrying no zakat restriction" is what a treasurer
        // reconciling the unrestricted pot asks for. So only an unset value ('' or
        // a screen that never offered the control) omits the key, and false is sent
        // as `0` — the truthiness check the other filters use would silently turn
        // it into "no filter" and hand back the whole ledger, and the CSV with it.
        //
        // `1`/`0`, never `true`/`false`: Laravel's `boolean` rule rejects those two
        // strings, and this query string is also what the export reads.
        if (filters.zakat !== '' && filters.zakat !== undefined) {
            params.append('zakat', filters.zakat ? '1' : '0');
        }
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
     * Positional convenience for the plain ledger screen, which has no date,
     * source or zakat filter. Kept as the narrow front door onto fetchLedger so
     * both screens go through one request path — the unset filters are spelled
     * out rather than defaulted, because a filter this function forgets is a set
     * of gifts one of the two screens can no longer reach.
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
            search,
            zakat: ''
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

    /**
     * Issue the official tax receipt for one offline gift.
     *
     * A WRITE, and an irreversible one: it consumes the next serial in this
     * masjid's single gap-free sequence and produces a document the donor may
     * file with their taxes. The caller therefore confirms with the treasurer
     * first — this function does not ask, and must never be reached by anything
     * automatic.
     *
     * Safe to call twice and unsafe to RETRY: the server is idempotent (a second
     * call returns the same receipt at 200 instead of minting a second serial),
     * but a request that failed at the network layer may still have committed, so
     * a failure is surfaced to the treasurer rather than retried here.
     *
     * The server refuses with a named reason — a Stripe gift (its receipt is the
     * webhook's job), a gift that has not succeeded, or a fund the org set not to
     * issue receipts — so the 422's own message is what the caller shows.
     *
     * `created` carries the server's 201-vs-200 through to the caller, because on
     * this screen the two are DIFFERENT news. 201 means a serial was consumed;
     * 200 means the gift already had one and the server handed the existing
     * receipt back (a colleague issuing from another tab, or a row left open).
     * Collapsing them told a treasurer a number had been burned when none had, on
     * the one screen whose whole subject is that gap-free sequence.
     */
    async function issueReceipt(donationId: number): Promise<{ receipt: DonationReceipt; created: boolean }> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/donations/${donationId}/receipt` as BackendApiRoute,
            {}
        );

        // A 2xx that carried no receipt is not a success: the button would flip to
        // "Download" over a document that does not exist.
        if (res.data?.status !== 'success' || !res.data?.data) {
            throw new Error(res.data?.message || 'The receipt was not issued.');
        }

        return { receipt: res.data.data as DonationReceipt, created: res.status === 201 };
    }

    /**
     * Re-download the printable copy of a receipt already issued — for the donor
     * who lost theirs. Issues nothing; the PDF is rendered from the stored row.
     *
     * A raw fetch rather than ApiService for the same reason exportCsv uses one:
     * axios here is configured for JSON and would hand back a corrupted file.
     * Nothing is cached — the bytes are a tax document naming a donor (the server
     * sends `Cache-Control: private, no-store`), so the blob is written to the
     * download and the object URL revoked immediately.
     */
    async function downloadReceiptPdf(donation: Donation): Promise<void> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const token = localStorage.getItem(LOCAL_STORAGE_KEYS.token);
        const res = await fetch(`/api/admin/masjids/${id}/donations/${donation.id}/receipt/pdf`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' }
        });

        // fetch only rejects on a network failure, so the 404 the server returns
        // for a gift with no receipt would otherwise be saved to disk as a PDF
        // full of JSON.
        if (!res.ok) throw new Error(`Receipt download failed with ${res.status}`);

        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filenameFromDisposition(res.headers.get('Content-Disposition'))
            ?? `receipt-${donation.receipt?.serial_number ?? donation.id}.pdf`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    /** The name the SERVER chose for a download, or null when it named none.
     *  Preferred over anything built here: the server's carries the masjid. */
    function filenameFromDisposition(contentDisposition: string | null): string | null {
        return contentDisposition?.match(/filename="?([^";]+)"?/i)?.[1] ?? null;
    }

    /** Prefer the name the server chose (it carries the masjid name); fall back to a dated default. */
    function csvFilename(contentDisposition: string | null): string {
        return filenameFromDisposition(contentDisposition)
            ?? `donations-${new Date().toISOString().slice(0, 10)}.csv`;
    }

    return {
        donationsPaginated,
        buildLedgerQuery,
        fetchLedger,
        fetchDonations,
        fetchDonation,
        exportCsv,
        issueReceipt,
        downloadReceiptPdf
    }
})
