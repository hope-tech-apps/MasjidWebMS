import ApiService from "@/core/services/ApiService";
import { TeamAccess, TeamPayload } from "@/core/types/data/Capability";
import { useMasjidStore } from "@/stores/masjidStore";
import { defineStore } from "pinia";
import { ref } from "vue";

/**
 * Team & Access (layer 2 of the access model) — see TeamController.
 *
 * Every staff login of the ACTIVE organisation, plus what the organisation has
 * (layer 1) so the screen can say what an administrator gets. Posts are
 * form-encoded like the rest of the SPA; the server never reads a `type`.
 */
export const useTeamStore = defineStore('teamStore', () => {

    const masjidStore = useMasjidStore();

    const team = ref<TeamPayload | null>(null);

    function base(): string {
        const id = masjidStore.masjid?.id;
        if (!id) throw new Error('No organisation is loaded yet.');
        return `/api/admin/masjids/${id}/team`;
    }

    async function fetchTeam(): Promise<void> {
        const res = await ApiService.get(base());
        team.value = res.data?.data ?? null;
    }

    async function addPerson(payload: { name: string; email: string; phone?: string; access: TeamAccess }): Promise<string> {
        const body = new FormData();
        body.append('name', payload.name.trim());
        body.append('email', payload.email.trim());
        if (payload.phone?.trim()) body.append('phone', payload.phone.trim());
        body.append('access', payload.access);

        const res = await ApiService.post(base(), body);
        await fetchTeam();
        return res.data?.message ?? 'Added.';
    }

    async function resendInvite(userId: number): Promise<string> {
        const res = await ApiService.post(`${base()}/${userId}/invite`, new FormData());
        return res.data?.message ?? 'Invitation sent.';
    }

    async function changeAccess(userId: number, access: TeamAccess): Promise<string> {
        const body = new URLSearchParams();
        body.append('access', access);
        const res = await ApiService.patch(`${base()}/${userId}`, body);
        await fetchTeam();
        return res.data?.message ?? 'Access changed.';
    }

    async function removePerson(userId: number): Promise<string> {
        const res = await ApiService.delete(`${base()}/${userId}`);
        await fetchTeam();
        return res.data?.message ?? 'Removed.';
    }

    return { team, fetchTeam, addPerson, resendInvite, changeAccess, removePerson };
});
