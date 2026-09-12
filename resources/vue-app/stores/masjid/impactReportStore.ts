import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import {
    ImpactMetric,
    ImpactOmission,
    ImpactReportFilters,
    ImpactReportMeta
} from "@/core/types/data/masjid-related/ImpactReport";

/**
 * The impact report — READ-ONLY over
 * /api/admin/masjids/{masjid_id}/impact/report.
 *
 * Nothing is computed here, and nothing may be. What each figure counts, which
 * figures a vertical is asked for, where a period starts in the organization's
 * own timezone and how money is formatted all live once in
 * App\Support\ImpactMetrics; a second implementation in TypeScript would put a
 * different number on the funder's page than the one the server can defend.
 *
 * This store also WRITES NOTHING, anywhere. There is no publish path from the
 * report to a page section: `impact_stats` stays the display text an admin
 * typed, and copying a figure across is a human, editorial act done from the
 * view (.claude/rules/impact-metrics.md, "The T-020 boundary").
 *
 * The active masjid comes from the same context every other masjid-scoped store
 * uses; the backend `tenant` middleware + BelongsToMasjid scope every read, and
 * ImpactMetrics re-binds the tenant explicitly on top of that.
 */
export const useImpactReportStore = defineStore('impactReportStore', () => {

    // State
    const metrics = ref<ImpactMetric[]>([]);

    /** `{key, reason}` for every figure the server left out, so the screen can
     *  say "we did not ask" apart from "the answer was zero". */
    const omitted = ref<ImpactOmission[]>([]);

    /** Org type, timezone, currency, the period the report was computed under,
     *  and when it was generated — the letterhead of the printed document. */
    const reportMeta = ref<ImpactReportMeta>();

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
     * Serialize the two bounds the endpoint understands. Blank values are
     * omitted rather than sent empty: `from=` is not a null bound to the
     * server's `nullable|date` rule, it is an empty string, and both bounds
     * absent is the documented way to ask for all time.
     */
    function buildImpactQuery(filters: ImpactReportFilters): string {
        const params = new URLSearchParams();

        if (filters.from) params.append('from', filters.from);
        if (filters.to) params.append('to', filters.to);

        return params.toString();
    }

    /**
     * The whole report in one call: the selected metrics plus the meta that
     * says what they were computed under.
     *
     * Throws rather than returning quietly when no masjid is resolved. A silent
     * return resolves the caller's await, so its try/catch books a successful
     * load and the screen keeps showing whatever figures were already there as
     * if they were fresh — and this screen is printed and handed to a funder,
     * so a stale figure under a new date header is a false document, not a
     * cosmetic bug. Same reasoning as donationStatsStore, and it applies harder
     * here.
     */
    async function fetchReport(filters: ImpactReportFilters): Promise<void> {
        const id = masjidId();

        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const query = buildImpactQuery(filters);

        // Uncast on purpose. `/api/admin/masjids/${string}/impact/report?${string}`
        // is a declared member of BackendApiRoute now, so a typo in this path is
        // a compile error rather than a 404 that reaches a funder-facing screen
        // as an empty page. Never silence this call with `as BackendApiRoute`:
        // the cast would exempt the one screen whose output leaves the building
        // from the only check on its URL.
        await ApiService.get(`/api/admin/masjids/${id}/impact/report?${query}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    metrics.value = res.data.data.metrics ?? [];
                    reportMeta.value = res.data.meta;
                    // Read off meta, where the server puts it — never left at
                    // its previous value, or a report run by an admin WITH the
                    // donations permission would keep claiming money figures
                    // were withheld from the one run before it.
                    omitted.value = res.data.meta?.omitted ?? [];
                }
            })
            .catch((e: Error) => {
                console.error('Fetch impact report error: ', e);
                throw e;
            });
    }

    return {
        metrics,
        omitted,
        reportMeta,
        buildImpactQuery,
        fetchReport
    }
})
