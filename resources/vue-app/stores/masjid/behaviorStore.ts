import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    BehaviorAward,
    BehaviorAwardPayload,
    BehaviorAwardSummary,
    BehaviorMeta,
    BehaviorSkill,
    BehaviorSkillPayload
} from "@/core/types/data/masjid-related/Behavior";

/**
 * Behaviour points — the per-tenant skill vocabulary
 * (/api/admin/masjids/{masjid_id}/behavior-skills) and the awards given on it
 * (.../groups/{group_id}/awards).
 *
 * ## THERE IS NO LEADERBOARD FUNCTION IN THIS STORE, AND THERE MUST NOT BE ONE.
 *
 * A child's behaviour record reaches the group's leaders, the student, and THAT
 * student's own guardians — never another guardian in the same group, never the
 * whole tenant, and never as a class-wide ranking. The API serves no such
 * endpoint: every aggregate it exposes (`fetchSummary` below) is per student,
 * computed over rows the caller was already entitled to, and the listing query
 * is audience-constrained server-side so a forbidden award is never even
 * fetched.
 *
 * Public behaviour tallies are the loudest documented complaint about the
 * product this module answers, and being structurally incapable of one is the
 * differentiator — not a setting. So: do not add a `fetchLeaderboard`, do not
 * add a rank field, and do not build a class-wide ranking by looping
 * `fetchSummary` over the roster in a component. See .claude/rules/groups.md.
 *
 * Nothing here is paywalled: no plan check exists on either side of the wire.
 */
export const useBehaviorStore = defineStore('behaviorStore', () => {

    // State
    const skills = ref<BehaviorSkill[]>([]);
    const awardsPaginated = ref<PaginatedData<BehaviorAward>>();
    const behaviorMeta = ref<BehaviorMeta>();

    /**
     * Per-student totals, keyed by membership id.
     *
     * A map rather than a sorted list ON PURPOSE: a list would be one `.sort()`
     * away from the ranking this module exists to refuse. The Points tab reads
     * it as a lookup beside each roster row.
     */
    const summaries = ref<Record<number, BehaviorAwardSummary>>({});

    // Stores
    const masjidStore = useMasjidStore();

    // ------------------------------------------------------- the vocabulary

    /** The tenant's skills, positives first then by label — the order a teacher reaches for. */
    async function fetchSkills(activeOnly: boolean = false): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        skills.value = [];

        const query = activeOnly ? '?active_only=1' : '';
        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/behavior-skills${query}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data?.data) {
            skills.value = res.data.data.data;
            behaviorMeta.value = res.data.meta;
        }
    }

    /** Define a skill. A tenant writes its own list; Manara ships none. */
    async function createSkill(payload: BehaviorSkillPayload): Promise<BehaviorSkill> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('label', payload.label);
        body.append('polarity', payload.polarity);
        body.append('default_points', String(payload.default_points));
        body.append('is_active', payload.is_active ? '1' : '0');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/behavior-skills` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to create the skill.');
    }

    // ------------------------------------------------------------- the awards

    /**
     * This group's awards, newest first, PRE-FILTERED server-side to what this
     * caller may read. Throws 403 when the caller has no standing in the group.
     */
    async function fetchAwards(groupId: number | string, page: number = 1): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (awardsPaginated.value) {
            awardsPaginated.value.data = [];
        }

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/awards?page=${page}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            awardsPaginated.value = res.data.data;
            behaviorMeta.value = res.data.meta;
        }
    }

    /**
     * ONE student's totals. Per student, by design — see the header. Stored in
     * the `summaries` map under the membership id it was asked for.
     */
    async function fetchSummary(
        groupId: number | string,
        membershipId: number
    ): Promise<BehaviorAwardSummary | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members/${membershipId}/awards/summary` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            summaries.value = { ...summaries.value, [membershipId]: res.data.data };
            return res.data.data;
        }
        return null;
    }

    /**
     * Award a skill to one student. `points` omitted takes the skill's default;
     * the label, polarity and points are SNAPSHOTTED onto the award server-side,
     * so re-weighting the skill later never restates this child's record.
     */
    async function awardSkill(
        groupId: number | string,
        payload: BehaviorAwardPayload
    ): Promise<BehaviorAward> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('membership_id', String(payload.membership_id));
        body.append('behavior_skill_id', String(payload.behavior_skill_id));
        if (payload.points !== null) body.append('points', String(payload.points));
        if (payload.note) body.append('note', payload.note);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/awards` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to record the award.');
    }

    /**
     * Revoke an award — a teacher tapped the wrong child. Revocation IS the soft
     * delete, so it drops out of every listing and every total at once.
     */
    async function revokeAward(groupId: number | string, awardId: number | string): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/awards/${awardId}` as BackendApiRoute
        );
        return res.data?.status === 'success';
    }

    return {
        skills,
        awardsPaginated,
        behaviorMeta,
        summaries,
        fetchSkills,
        createSkill,
        fetchAwards,
        fetchSummary,
        awardSkill,
        revokeAward
    }
})
