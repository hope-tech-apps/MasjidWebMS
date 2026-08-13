import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    Group,
    GroupMembership,
    GroupMembershipPayload,
    GroupPayload,
    GroupsMeta
} from "@/core/types/data/masjid-related/Group";

/**
 * Groups store — CRUD over /api/admin/masjids/{masjid_id}/groups and the roster
 * nested under it.
 *
 * The active masjid comes from masjidStore (the same context every other
 * masjid-scoped store uses); the backend `tenant` middleware + BelongsToMasjid
 * enforce that this admin only ever touches their own organization's groups.
 *
 * `meta` is kept because it is the ONLY authority on what a group is called for
 * this tenant ("Halaqat" / "Classrooms" / "Teams") and on the kind/role
 * vocabularies. Nothing here hardcodes either.
 */
export const useGroupsStore = defineStore('groupsStore', () => {

    // State
    const groupsPaginated = ref<PaginatedData<Group>>();
    const memberships = ref<GroupMembership[]>([]);
    /** How many rows on the loaded roster nobody at the organisation has confirmed. */
    const pendingClaims = ref<number>(0);
    const groupsMeta = ref<GroupsMeta>();

    // Stores
    const masjidStore = useMasjidStore();

    /** Fetch a page of groups, optionally narrowed by a free-text search. */
    async function fetchGroups(page: number = 1, search: string = ''): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (groupsPaginated.value) {
            groupsPaginated.value.data = [];
        }

        let url = `/api/admin/masjids/${masjidStore.masjid.id}/groups?page=${page}`;
        if (search) {
            url += `&search=${encodeURIComponent(search)}`;
        }

        await ApiService.get(url as BackendApiRoute)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    groupsPaginated.value = res.data.data;
                    groupsMeta.value = res.data.meta;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch groups error: ', e);
                throw e;
            });
    }

    /** Fetch one group (its roster rides along on this endpoint). */
    async function fetchGroup(id: number | string): Promise<Group | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${id}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            groupsMeta.value = res.data.meta;
            return res.data.data;
        }
        return null;
    }

    /** Create a group. Returns the created row on success. */
    async function createGroup(payload: GroupPayload): Promise<Group> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        // POST goes out as multipart/form-data (ApiService default). Booleans are
        // serialized to '1'/'0' so Laravel's `boolean` rule accepts them, and
        // blank dates are OMITTED rather than sent empty — `starts_on=` fails
        // `nullable|date`.
        const body = new FormData();
        body.append('name', payload.name);
        body.append('slug', payload.slug);
        body.append('kind', payload.kind);
        body.append('description', payload.description);
        body.append('is_active', payload.is_active ? '1' : '0');
        if (payload.starts_on) body.append('starts_on', payload.starts_on);
        if (payload.ends_on) body.append('ends_on', payload.ends_on);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to create group.');
    }

    /** Update a group. Returns the updated row on success. */
    async function updateGroup(id: number | string, payload: GroupPayload): Promise<Group> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        // ApiService.put sends application/x-www-form-urlencoded; serialize to
        // URLSearchParams (the proven edit path in contactsStore/fundsStore).
        const body = new URLSearchParams();
        body.append('name', payload.name);
        body.append('slug', payload.slug);
        body.append('kind', payload.kind);
        body.append('description', payload.description);
        body.append('is_active', payload.is_active ? '1' : '0');
        if (payload.starts_on) body.append('starts_on', payload.starts_on);
        if (payload.ends_on) body.append('ends_on', payload.ends_on);

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${id}` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to update group.');
    }

    /**
     * Deactivate a group.
     *
     * The endpoint SOFT-deletes, deliberately: the roster underneath can be a
     * list of children and a mis-click must not destroy it. The UI says
     * "deactivate" rather than "delete" because that is what actually happens.
     */
    async function deleteGroup(id: number | string): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${id}` as BackendApiRoute
        );
        return res.data?.status === 'success';
    }

    // ------------------------------------------------------------------ roster

    /**
     * The roster of one group: participants and the guardian edges attached to
     * them.
     *
     * `pendingClaims` comes from the server's `meta` rather than being counted
     * off `memberships` here. The count decides whether a banner appears saying
     * children's records are being withheld, and a second implementation of
     * "which rows are unconfirmed" in TypeScript is a copy that agrees today —
     * the same call this codebase makes for the family-login state word.
     */
    async function fetchMemberships(groupId: number | string): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        memberships.value = [];
        pendingClaims.value = 0;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members` as BackendApiRoute
        );
        if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
            memberships.value = res.data.data;
            pendingClaims.value = Number(res.data?.meta?.pending_claims ?? 0);
        }
    }

    /**
     * Stand behind the roster claims a PUBLIC registration form asserted.
     *
     * `membershipIds` is REQUIRED by the endpoint and the caller must name every
     * row it is vouching for. An absent list used to mean "everything pending in
     * this group", which is not the set the operator read — a registration
     * landing between the render and the click was confirmed by it. A school's
     * 200-signup intake is still one button; it just carries 200 ids.
     * Resolves to how many were confirmed.
     */
    async function confirmClaims(
        groupId: number | string,
        membershipIds: number[]
    ): Promise<number> {
        if (!masjidStore.masjid?.id || membershipIds.length === 0) return 0;

        const body = new FormData();
        membershipIds.forEach((id) => body.append('membership_ids[]', String(id)));

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members/confirm` as BackendApiRoute,
            body
        );

        if (res.data?.status !== 'success') {
            throw new Error('Failed to confirm the roster entries.');
        }

        pendingClaims.value = Number(res.data?.data?.pending_claims ?? 0);

        return Number(res.data?.data?.confirmed ?? 0);
    }

    /**
     * Add someone to the roster.
     *
     * `guardian_of_contact_id` is sent ONLY for a guardian row: the request
     * rejects it with `prohibited_unless` on any other role, so sending an empty
     * string would fail a plain member add.
     */
    async function addMembership(
        groupId: number | string,
        payload: GroupMembershipPayload
    ): Promise<GroupMembership> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new FormData();
        body.append('contact_id', String(payload.contact_id));
        body.append('role', payload.role);
        if (payload.role === 'guardian' && payload.guardian_of_contact_id !== null) {
            body.append('guardian_of_contact_id', String(payload.guardian_of_contact_id));
        }
        if (payload.joined_at) body.append('joined_at', payload.joined_at);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to add the member.');
    }

    /**
     * Remove a membership. Removing a PARTICIPANT also removes the guardian
     * edges that pointed at them — the server does that in a model hook, so the
     * roster is re-fetched rather than spliced locally.
     */
    async function removeMembership(
        groupId: number | string,
        membershipId: number | string
    ): Promise<boolean> {
        if (!masjidStore.masjid?.id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members/${membershipId}` as BackendApiRoute
        );
        return res.data?.status === 'success';
    }

    return {
        groupsPaginated,
        groupsMeta,
        memberships,
        pendingClaims,
        fetchGroups,
        fetchGroup,
        createGroup,
        updateGroup,
        deleteGroup,
        fetchMemberships,
        addMembership,
        confirmClaims,
        removeMembership
    }
})
