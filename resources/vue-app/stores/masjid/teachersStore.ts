import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { Teacher, TeacherDetail, TeacherPayload, TeacherUpdatePayload } from "@/core/types/data/masjid-related/Teacher";

/**
 * Teachers store — the ADMIN provisioning surface over
 * /api/admin/masjids/{masjid_id}/teachers.
 *
 * The active masjid comes from masjidStore (the same context every other
 * masjid-scoped store uses); the backend `tenant` middleware + BelongsToMasjid
 * enforce that this admin only ever touches their own organization's teachers.
 *
 * The index answers a PLAIN ARRAY (not a paginator), so there is no pagination
 * state here — a masjid's teaching staff is a short list, unlike the member
 * directory. The class multiselect that the create form needs is NOT loaded
 * here: the view reuses `useGroupsStore().fetchGroups()` (the existing
 * Classrooms endpoint) so there is one source of truth for "the tenant's
 * classes".
 */
export const useTeachersStore = defineStore('teachersStore', () => {

    // State
    const teachers = ref<Teacher[]>([]);

    // Stores
    const masjidStore = useMasjidStore();

    /** Fetch the masjid's teachers. */
    async function fetchTeachers(): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/teachers` as BackendApiRoute
        )
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                    teachers.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch teachers error: ', e);
                throw e;
            });
    }

    /**
     * Create a teacher and assign the classes they lead.
     *
     * The POST goes out as JSON (ApiService.post routes a plain object to
     * `application/json`, which Laravel parses natively) so `class_ids` arrives
     * as a real array rather than a set of `class_ids[]` form keys. A validation
     * failure rejects with the axios error carrying the `422` envelope
     * (`{status:'failed', data:{field:[msg]}}`), which the view maps to inline
     * field errors — so it is re-thrown, not swallowed.
     */
    async function createTeacher(payload: TeacherPayload): Promise<Teacher> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body: Record<string, unknown> = {
            name: payload.name,
            email: payload.email,
            class_ids: payload.class_ids
        };
        // Phone is optional; omit it entirely rather than send an empty string
        // (an empty `phone` can trip a `nullable` + format rule server-side).
        if (payload.phone) body.phone = payload.phone;

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/teachers` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to create teacher.');
    }

    /**
     * Read one teacher to PRE-FILL the edit form.
     *
     * The index row only carries the `{id,name}` class chips; the edit modal
     * needs the raw `class_ids` (to tick the multiselect) plus the phone, so it
     * hits GET /teachers/{id} rather than reusing the row already in `teachers`.
     * Errors propagate so the view can fall back to a load error / toast.
     */
    async function fetchTeacher(id: number | string): Promise<TeacherDetail> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/teachers/${id}` as BackendApiRoute
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to load teacher.');
    }

    /**
     * Edit a teacher: their name/phone and the full set of classes they lead.
     *
     * Email is not sent — it is not editable. The body goes out as a plain
     * object so the request interceptor stamps `application/json` (overriding
     * PUT's urlencoded default): that lets `class_ids` arrive as a REAL array
     * the server can sync against, rather than URLSearchParams' repeated keys.
     * A `422` rejects with the axios error carrying the validation bag, so it is
     * re-thrown (not swallowed) for the view to map to inline field errors.
     */
    async function updateTeacher(id: number | string, payload: TeacherUpdatePayload): Promise<Teacher> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body: Record<string, unknown> = {
            name: payload.name,
            class_ids: payload.class_ids
        };
        // Phone is optional; omit it rather than send an empty string.
        if (payload.phone) body.phone = payload.phone;

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${masjidStore.masjid.id}/teachers/${id}` as BackendApiRoute,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to update teacher.');
    }

    /**
     * Remove a teacher FROM THIS SCHOOL (the server retires the login only when
     * this was their last school). Errors propagate so the view can toast them.
     */
    async function deleteTeacher(id: number | string): Promise<boolean> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/teachers/${id}` as BackendApiRoute
        );
        return res.data?.status === 'success';
    }

    /**
     * Re-send the set-password invite to a teacher who has not accepted yet.
     *
     * Returns the server's own success message for the toast. A `422` (the
     * teacher has no email on file) rejects with the axios error so the view can
     * surface that reason instead — hence it is re-thrown, not swallowed.
     */
    async function resendInvite(id: number | string): Promise<string> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/teachers/${id}/invite` as BackendApiRoute,
            {}
        );
        if (res.data?.status === 'success') {
            return res.data?.message || 'Invitation re-sent.';
        }
        throw new Error('Failed to re-send the invitation.');
    }

    return {
        teachers,
        fetchTeachers,
        createTeacher,
        fetchTeacher,
        updateTeacher,
        deleteTeacher,
        resendInvite
    }
})
