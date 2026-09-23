import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import {
    AttendanceLog,
    AttendanceLogFilters,
    AttendanceMemberFilters,
    AttendanceMemberRecord
} from "@/core/types/data/masjid-related/Attendance";

/**
 * The attendance log — READ-ONLY over
 * /api/admin/masjids/{masjid_id}/attendance and its per-child record.
 *
 * Two GETs and nothing else. Every write the register has lives in the teacher
 * realm, because a mark carries the marker's name and the office is not the
 * marker — the same boundary GroupGradesTab sits behind. There is no save path
 * here and adding one would be a new decision, not a new method.
 *
 * Nothing is computed in this store and nothing may be. Which days are columns,
 * which of them each class took a register on, where a child's enrolment clips
 * the row and what the six counts come to are all decided once by the server
 * against the rows and App\Support\SchoolCalendar. A second implementation in
 * TypeScript would put one number on the grid row and a different one in the
 * drill-down opened from it.
 *
 * The active masjid comes from the same context every other masjid-scoped store
 * uses; the backend `tenant` middleware and the BelongsToMasjid global scope are
 * the tenant boundary (.claude/rules/tenant-scoping.md).
 */
// Module scope, not store state: it is bookkeeping about requests, not something
// a screen ever renders. See the guard in fetchLog.
let logRequest = 0;

export const useAttendanceLogStore = defineStore('attendanceLogStore', () => {

    // State
    /** The whole grid payload — days, closures, students, class totals, today. */
    const log = ref<AttendanceLog>();

    /** One child's record, or null while the grid is what is on screen. */
    const member = ref<AttendanceMemberRecord | null>(null);

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /**
     * dashboardMasjidId is restored from localStorage at auth time, so it
     * survives a hard refresh that has not yet finished hydrating
     * masjidStore.masjid.
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
     * Serialize the filter set the endpoint understands.
     *
     * Blank values are omitted rather than sent empty: `group_id=` is not "all
     * classes" to the server, it is a string the integer rule rejects, and
     * `search=` would narrow the roster to the children whose name contains
     * nothing. `include_withdrawn` is sent only as the literal '1' the contract
     * names — there is no '0' form, absence is the off state.
     *
     * `page` always travels, even for page one, so a reload after a filter
     * change says out loud which page it wants rather than relying on the
     * server's default matching the pager's idea of where it is.
     */
    function buildLogQuery(filters: AttendanceLogFilters, page: number): string {
        const params = new URLSearchParams();

        if (filters.from) params.append('from', filters.from);
        if (filters.to) params.append('to', filters.to);
        if (filters.group_id) params.append('group_id', String(filters.group_id));
        if (filters.search.trim()) params.append('search', filters.search.trim());
        if (filters.include_withdrawn) params.append('include_withdrawn', '1');
        params.append('page', String(page));

        return params.toString();
    }

    /** The two bounds a child's record takes, and nothing else. */
    function buildMemberQuery(filters: AttendanceMemberFilters): string {
        const params = new URLSearchParams();

        if (filters.from) params.append('from', filters.from);
        if (filters.to) params.append('to', filters.to);

        return params.toString();
    }

    /**
     * The whole grid in one call.
     *
     * Throws rather than returning quietly when no masjid is resolved. A silent
     * return resolves the caller's await, so its try/catch books a successful
     * load and the screen keeps showing the previous organisation's register
     * under the new organisation's name — which on this screen means a list of
     * children who are not enrolled here. Same reasoning as impactReportStore.
     *
     * `log` is only replaced once a well-formed payload arrives. A partial
     * response leaves the previous window on screen with its own dates still in
     * the filter row, which is recoverable; half a grid under today's header is
     * not.
     */
    async function fetchLog(filters: AttendanceLogFilters, page = 1): Promise<void> {
        const id = requireMasjidId();
        const query = buildLogQuery(filters, page);

        // LATEST WINS. Every filter control re-reads on change, so two reads are
        // routinely in flight — a date typed digit by digit fires four. Without
        // this, the slower response writes last and the office is looking at a
        // window the filter row no longer says, with nothing on screen admitting
        // it. A superseded read's FAILURE is dropped for the same reason.
        const seq = ++logRequest;

        // Uncast on purpose. Both shapes are declared members of
        // BackendApiRoute, so a typo here is a compile error rather than a 404
        // that reaches the office as an empty register. Never silence this with
        // `as BackendApiRoute`.
        await ApiService.get(`/api/admin/masjids/${id}/attendance?${query}`)
            .then((res: AxiosResponse) => {
                if (seq !== logRequest) return;

                if (res.data?.status === 'success' && res.data?.data) {
                    log.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                if (seq !== logRequest) return;

                console.error('Fetch attendance log error: ', e);
                throw e;
            });
    }

    /**
     * One child's record over the same window the grid was read for.
     *
     * The caller passes the grid's own bounds. Reading the record under a
     * different window would show totals that disagree with the row it was
     * opened from, and this screen's whole claim is that those two agree.
     */
    async function fetchMember(membershipId: number, filters: AttendanceMemberFilters): Promise<void> {
        const id = requireMasjidId();
        const query = buildMemberQuery(filters);

        await ApiService.get(`/api/admin/masjids/${id}/attendance/members/${membershipId}?${query}`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    member.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch attendance member error: ', e);
                throw e;
            });
    }

    /** Close the drill-down. The grid behind it is untouched and not re-read. */
    function clearMember(): void {
        member.value = null;
    }

    return {
        log,
        member,
        masjidId,
        buildLogQuery,
        buildMemberQuery,
        fetchLog,
        fetchMember,
        clearMember
    }
})
