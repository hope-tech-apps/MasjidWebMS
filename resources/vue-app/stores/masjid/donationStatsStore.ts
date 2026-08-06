import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import {
    DonationStatsFilters,
    DonationStatsMeta,
    DonationSummary,
    FundBreakdownRow
} from "@/core/types/data/masjid-related/DonationStats";

/**
 * Giving-dashboard numbers — READ-ONLY over
 * /api/admin/masjids/{masjid_id}/donations/stats.
 *
 * Nothing is computed here. The money rules (which status counts as received,
 * how the net falls back to the gross, where a month starts in the masjid's own
 * timezone) live once in App\Support\DonationMetrics; re-deriving any of them in
 * TypeScript would give the treasurer two answers to the same question.
 *
 * The active masjid comes from the same context every other masjid-scoped store
 * uses; the backend `tenant` middleware + BelongsToMasjid scope every read.
 */
export const useDonationStatsStore = defineStore('donationStatsStore', () => {

    // State
    const summary = ref<DonationSummary>();
    const byFund = ref<FundBreakdownRow[]>([]);

    /** Timezone/currency/echoed-filters the two endpoints agree on — whichever
     *  call lands last writes it, and they are called with identical filters. */
    const statsMeta = ref<DonationStatsMeta>();

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
     * Serialize the filters the stats endpoints understand. Blank values are
     * omitted rather than sent empty — `source=` fails the server's Rule::in().
     */
    function buildStatsQuery(filters: DonationStatsFilters): string {
        const params = new URLSearchParams();

        if (filters.from) params.append('from', filters.from);
        if (filters.to) params.append('to', filters.to);
        if (filters.source) params.append('source', filters.source);
        if (filters.status) params.append('status', filters.status);

        return params.toString();
    }

    /** Header totals: this month, year to date, all time (all intersected with the filters). */
    async function fetchSummary(filters: DonationStatsFilters): Promise<void> {
        const id = masjidId();
        // Throw rather than return quietly. A silent return resolves the caller's
        // await, so its try/catch books a successful load and the dashboard keeps
        // showing whatever figures were already there as if they were fresh —
        // money on screen that nothing on this request actually produced.
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const query = buildStatsQuery(filters);

        await ApiService.get(`/api/admin/masjids/${id}/donations/stats/summary?${query}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    summary.value = res.data.data;
                    statsMeta.value = res.data.meta;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch donation summary error: ', e);
                throw e;
            });
    }

    /** Per-fund breakdown, biggest first. Includes funds sitting at $0 — a
     *  designation taking nothing is exactly what a treasurer needs to see. */
    async function fetchByFund(filters: DonationStatsFilters): Promise<void> {
        const id = masjidId();
        // Same reason as fetchSummary: no masjid is a failed read, not an empty one.
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const query = buildStatsQuery(filters);

        await ApiService.get(`/api/admin/masjids/${id}/donations/stats/by-fund?${query}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                    byFund.value = res.data.data;
                    statsMeta.value = res.data.meta;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch donation fund breakdown error: ', e);
                throw e;
            });
    }

    /**
     * Both halves of the dashboard header in parallel. Deliberately NOT
     * Promise.allSettled: a half-loaded page of money figures is worse than an
     * error, so one failure rejects and the view shows its error state.
     */
    async function fetchStats(filters: DonationStatsFilters): Promise<void> {
        await Promise.all([fetchSummary(filters), fetchByFund(filters)]);
    }

    return {
        summary,
        byFund,
        statsMeta,
        buildStatsQuery,
        fetchSummary,
        fetchByFund,
        fetchStats
    }
})
