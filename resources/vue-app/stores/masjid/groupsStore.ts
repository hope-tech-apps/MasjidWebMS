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
    GroupsMeta,
    MovePreview,
    RosterMeta
} from "@/core/types/data/masjid-related/Group";

/**
 * What one press of the confirm button actually did.
 *
 * The counts are NOT a formality. A sweep can now come back having confirmed
 * fewer rows than it named for three different reasons, and the office has to be
 * told which — a row that changed under them is still waiting and needs another
 * look, a contested one needs a decision the sweep is not allowed to make, and a
 * settled one needs nothing at all. "8 confirmed" on a list of 10 is the exact
 * shape the earlier defect hid inside.
 */
export type ConfirmClaimsResult = {
    confirmed: number;
    skipped: number;
    changedSinceShown: number[];
    needsAnIndividualDecision: number[];
    message: string;
};

/**
 * What adding somebody to a roster actually did — the ROW AND WHAT THE SERVER
 * SAID ABOUT IT.
 *
 * `addMembership` used to return `res.data.data` and drop `res.data.message`,
 * which was fine while the message was decoration and stopped being fine when
 * `store()` began answering a second, sharper thing: `POST …/members` is the
 * OTHER door onto the same grant `confirm()` guards, and the server now names
 * the case where the entry it just confirmed has a same-named rival claiming
 * the same child. That sentence was computed, serialised, and thrown away here;
 * the screen fired a hardcoded "Added" toast. Measured: the contested stranger
 * row, refused by the sweep seconds earlier, confirmed through this call with
 * the warning present in the body and invisible on screen.
 *
 * `confirmedAnExistingClaim` is separated from the message rather than sniffed
 * out of it: a 200 means "a claim that was already sitting there is now
 * confirmed by you" and a 201 means "a new row was typed", and those are two
 * different things for an operator to have done.
 */
export type AddMembershipResult = {
    membership: GroupMembership;
    /** The server's own sentence, or '' when it said nothing beyond success. */
    message: string;
    /** True when this stood behind a pending claim rather than creating a row. */
    confirmedAnExistingClaim: boolean;
};

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
    /**
     * How many of those are claims the operator cannot tell apart from another
     * one over the same child — served by the API, never recounted here, for the
     * same reason `pendingClaims` is not: the definition of "contested" decides
     * what the bulk button refuses, and a second copy in TypeScript is a copy
     * that agrees today.
     */
    const contestedClaims = ref<number>(0);
    /**
     * What the roster list says about the class itself: whether it is a class
     * (the Move button hangs on it), today on the school's clock, the class's
     * name. Served, never worked out here.
     */
    const rosterMeta = ref<RosterMeta | null>(null);
    /** The class and day last chosen in the Move dialog, kept for this visit so a roster is two taps per student. */
    const lastMoveChoice = ref<{ toGroupId: number | null; movedOn: string }>({ toGroupId: null, movedOn: '' });
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
        contestedClaims.value = 0;

        await refreshMemberships(groupId);
    }

    /**
     * The same read, QUIETLY: nothing is emptied first, so the table stays on
     * screen and the office keeps its place. For the reload after a move, where
     * a spinner over thirty rows would lose the row being worked on.
     */
    async function refreshMemberships(groupId: number | string): Promise<void> {
        const roster = await readRoster(groupId);
        if (!roster) return;

        memberships.value = roster.rows;
        pendingClaims.value = Number(roster.meta?.pending_claims ?? 0);
        contestedClaims.value = Number(roster.meta?.contested_claims ?? 0);
        rosterMeta.value = roster.meta && typeof roster.meta.teaches_students === 'boolean' ? roster.meta : null;
    }

    /**
     * The roster as it is NOW, handed to the caller and written to no state.
     * The "Put back" dialog reads it when it opens, so what it decides from is
     * seconds old, not as old as the page.
     */
    async function readRoster(groupId: number | string): Promise<{ rows: GroupMembership[]; meta: any } | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members` as BackendApiRoute
        );
        if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
            return { rows: res.data.data, meta: res.data?.meta ?? null };
        }
        return null;
    }

    // ---------------------------------------------- moving a student to another class

    /**
     * Every class of this school, for the Move dialog's "Move to" list.
     *
     * Its OWN request and its own result, never `groupsPaginated`: that is the
     * Classes page's state, and a dialog must not turn that page's list into
     * page 3 of something else. Asks until the last page.
     */
    async function fetchClassesForMove(): Promise<Group[]> {
        if (!masjidStore.masjid?.id) return [];

        const classes: Group[] = [];
        let page = 1;
        let last = 1;

        do {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidStore.masjid.id}/groups?kind=class&active_only=1&per_page=100&page=${page}` as BackendApiRoute
            );
            const paginated = res.data?.data;
            if (res.data?.status !== 'success' || !Array.isArray(paginated?.data)) {
                throw new Error('Could not load the classes.');
            }
            classes.push(...paginated.data);
            last = Number(paginated.last_page ?? 1);
            page += 1;
        } while (page <= last);

        return classes;
    }

    /** What moving this student there would do. Reads only; the sentences are the server's. */
    async function previewMove(
        groupId: number | string,
        membershipId: number | string,
        toGroupId: number,
        movedOn: string
    ): Promise<MovePreview> {
        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid?.id}/groups/${groupId}/members/${membershipId}/move`
                + `?to_group_id=${toGroupId}&moved_on=${encodeURIComponent(movedOn)}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Could not check this move.');
    }

    /** Move the student. Returns the server's lines: what happened and what is left to do. */
    async function moveMembership(
        groupId: number | string,
        membershipId: number | string,
        fields: Record<string, string>
    ): Promise<string[]> {
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid?.id}/groups/${groupId}/members/${membershipId}/move` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success') {
            const lines = res.data?.data?.lines;
            return Array.isArray(lines) && lines.length ? lines : [res.data?.message ?? 'Moved.'];
        }
        throw new Error('The move could not be saved.');
    }

    /** Put a student who left back on the roster (the withdrawal's own undo). */
    async function putBack(groupId: number | string, membershipId: number | string): Promise<void> {
        await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid?.id}/groups/${groupId}/members/${membershipId}/withdrawal` as BackendApiRoute
        );
    }

    /**
     * Stand behind the roster claims a PUBLIC registration form asserted.
     *
     * THE CALLER SENDS WHAT IT SAW, and that is now three things rather than one.
     *
     *  - the IDS it drew. An absent list used to mean "everything pending in this
     *    group", which is not the set the operator read — a registration landing
     *    between the render and the click was confirmed by it.
     *  - the FINGERPRINT each row was drawn with. An id names a row; it does not
     *    name what the row said. A merge re-points a pending claim's `contact_id`
     *    and the id does not move, so without this the operator confirms a
     *    relationship they never read. Rows whose fingerprint no longer matches
     *    come back under `changed_since_shown` and are left alone.
     *  - the ids of any CONTESTED claims the operator decided one at a time.
     *    Never populated by the bulk sweep, by construction: `confirmAllClaims`
     *    does not pass it, so a claim the operator cannot tell apart from a rival
     *    can only be confirmed from the dialog that shows both addresses.
     *
     * A school's 200-signup intake is still one button and one POST; it just
     * carries 200 ids and 200 fingerprints.
     */
    async function confirmClaims(
        groupId: number | string,
        rows: { id: number; fingerprint: string }[],
        contestedIds: number[] = []
    ): Promise<ConfirmClaimsResult> {
        const empty: ConfirmClaimsResult = {
            confirmed: 0,
            skipped: 0,
            changedSinceShown: [],
            needsAnIndividualDecision: [],
            message: ''
        };

        if (!masjidStore.masjid?.id || rows.length === 0) return empty;

        const body = new FormData();
        rows.forEach((row) => {
            body.append('membership_ids[]', String(row.id));
            body.append(`fingerprints[${row.id}]`, row.fingerprint);
        });
        contestedIds.forEach((id) => body.append('contested_membership_ids[]', String(id)));

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members/confirm` as BackendApiRoute,
            body
        );

        if (res.data?.status !== 'success') {
            throw new Error('Failed to confirm the roster entries.');
        }

        pendingClaims.value = Number(res.data?.data?.pending_claims ?? 0);

        return {
            confirmed: Number(res.data?.data?.confirmed ?? 0),
            skipped: Number(res.data?.data?.skipped ?? 0),
            changedSinceShown: (res.data?.data?.changed_since_shown ?? []).map(Number),
            needsAnIndividualDecision: (res.data?.data?.needs_an_individual_decision ?? []).map(Number),
            message: String(res.data?.message ?? '')
        };
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
    ): Promise<AddMembershipResult> {
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
            return {
                membership: res.data.data,
                // Carried, not dropped. See AddMembershipResult.
                message: String(res.data?.message ?? ''),
                confirmedAnExistingClaim: res.status === 200
            };
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
    ): Promise<string | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/groups/${groupId}/members/${membershipId}` as BackendApiRoute
        );
        // THE SERVER'S OWN SENTENCE, not a boolean. It says what went with the
        // row (guardian entries, a sign-in left opening nothing) and, for a
        // guardian entry, where else this adult is still listed for the same
        // child. The screen used to print a fixed "Removed" over all of it.
        return res.data?.status === 'success' ? String(res.data?.message ?? 'Removed from the roster.') : null;
    }

    return {
        groupsPaginated,
        groupsMeta,
        memberships,
        pendingClaims,
        contestedClaims,
        fetchGroups,
        fetchGroup,
        createGroup,
        updateGroup,
        deleteGroup,
        rosterMeta,
        lastMoveChoice,
        fetchMemberships,
        refreshMemberships,
        readRoster,
        fetchClassesForMove,
        previewMove,
        moveMembership,
        putBack,
        addMembership,
        confirmClaims,
        removeMembership
    }
})
