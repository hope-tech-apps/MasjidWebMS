import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    HifzEntry,
    HifzEntryPayload,
    HifzMeta,
    HifzProgress
} from "@/core/types/data/masjid-related/Hifz";

/**
 * Hifz tracking — the halaqa's recitation log over
 * .../groups/{group_id}/hifz, and a student's derived position under
 * .../members/{membership_id}/hifz/progress.
 *
 * ## What this store must never grow
 *
 * - **No group-wide progress call.** A leader's view is a list of per-student
 *   rows they are already entitled to; a "top memorisers" board is a public
 *   ranking aimed at Qur'an, which is exactly what the behaviour module refused.
 * - **No percentage.** Progress is a POSITION — surah, ayah, juz. `total_ayahs`
 *   rides along so a client can render "231 of 6236" without hardcoding the
 *   denominator, NOT so it can divide. No halaqa uses "62% memorised" and no
 *   ijaza recognises it.
 * - **No update call.** There is no update endpoint on purpose: striking an
 *   entry and re-recording leaves an audit trail where an in-place edit would
 *   quietly rewrite what a teacher said they heard. `strikeEntry` is the only
 *   correction there is — and because the position is DERIVED from sabak
 *   history, striking a mis-recorded lesson genuinely moves the child back.
 *
 * Reading is refusable exactly as the feed and the awards are: a caller with no
 * standing in the group gets a 403. See .claude/rules/groups.md.
 */
export const useHifzStore = defineStore('hifzStore', () => {

    // State
    const entriesPaginated = ref<PaginatedData<HifzEntry>>();
    const hifzMeta = ref<HifzMeta>();

    /** Per-student progress, keyed by membership id — a lookup, never a ranking. */
    const progressByMembership = ref<Record<number, HifzProgress>>({});

    // Stores
    const masjidStore = useMasjidStore();

    /** The halaqa's recitation log, newest first, pre-filtered to what the caller may read. */
    async function fetchEntries(groupId: number | string, page: number = 1): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (entriesPaginated.value) {
            entriesPaginated.value.data = [];
        }

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/hifz?page=${page}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            entriesPaginated.value = res.data.data;
            hifzMeta.value = res.data.meta;
        }
    }

    /**
     * ONE student's position and coverage. Everything in the payload is derived
     * from their sabak history; nothing is stored.
     */
    async function fetchProgress(
        groupId: number | string,
        membershipId: number
    ): Promise<HifzProgress | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members/${membershipId}/hifz/progress` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            progressByMembership.value = { ...progressByMembership.value, [membershipId]: res.data.data };
            return res.data.data;
        }
        return null;
    }

    /** Record one recitation heard from one student. */
    async function recordEntry(
        groupId: number | string,
        payload: HifzEntryPayload
    ): Promise<HifzEntry> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('membership_id', String(payload.membership_id));
        body.append('kind', payload.kind);
        body.append('from_surah', String(payload.from_surah));
        body.append('from_ayah', String(payload.from_ayah));
        body.append('to_surah', String(payload.to_surah));
        body.append('to_ayah', String(payload.to_ayah));
        body.append('quality', payload.quality);
        body.append('major_mistakes', String(payload.major_mistakes));
        body.append('minor_mistakes', String(payload.minor_mistakes));
        if (payload.note) body.append('note', payload.note);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/hifz` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to record the recitation.');
    }

    /**
     * Strike a mis-recorded entry. The only correction there is — see the header
     * for why there is no update path.
     */
    async function strikeEntry(groupId: number | string, entryId: number | string): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/hifz/${entryId}` as BackendApiRoute
        );
        return res.data?.status === 'success';
    }

    return {
        entriesPaginated,
        hifzMeta,
        progressByMembership,
        fetchEntries,
        fetchProgress,
        recordEntry,
        strikeEntry
    }
})
