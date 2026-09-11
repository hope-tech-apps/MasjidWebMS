import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import {
    Form,
    FormFieldTypeInfo,
    FormOption,
    FormPayload,
    FormStaffCode,
    FormStaffCodeIssued,
    FormStaffCodesMeta,
    FORM_FIELD_TYPES
} from "@/core/types/data/masjid-related/Form";

/**
 * Sign-up FORM DEFINITIONS — the builder's half of
 * /api/admin/masjids/{masjid_id}/forms. Submissions live in formResponsesStore.
 *
 * Writes go out as plain objects, which ApiService turns into JSON: the schema is a
 * nested structure (sections -> fields -> options) and urlencoded/multipart bodies
 * would flatten it. StoreFormRequest accepts both a real array and a JSON string, so
 * JSON is the shape that survives the round trip unchanged.
 *
 * The active masjid comes from the same context every masjid-scoped store uses; the
 * backend resolves every form through `$masjid->forms()`, so one tenant can never read
 * or edit another's form by guessing an id.
 */
export const useFormsStore = defineStore('formsStore', () => {

    // State
    const formOptions = ref<FormOption[]>([]);

    // The builder's palette. Seeded with the compiled-in fallback so the editor is
    // usable even before (or without) a successful /field-types call.
    const fieldTypes = ref<FormFieldTypeInfo[]>([...FORM_FIELD_TYPES]);

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /**
     * The masjid whose forms this screen is editing. dashboardMasjidId is set from
     * localStorage at auth time, so it survives a hard refresh that has not yet
     * finished hydrating masjidStore.masjid.
     */
    function masjidId(): number | string | null {
        return authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null;
    }

    /** The picker's list: id, name, slug, is_active, response_count. */
    async function fetchFormOptions(): Promise<FormOption[]> {
        const id = masjidId();
        if (!id) return [];

        await ApiService.get(`/api/admin/masjids/${id}/forms/options`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                    formOptions.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch form options error: ', e);
            });

        return formOptions.value;
    }

    /**
     * The question types the server will accept. Served from the same constant the
     * submission validator uses, so the builder can never offer a type that would be
     * rejected on save. A failure leaves the fallback palette in place.
     */
    async function fetchFieldTypes(): Promise<FormFieldTypeInfo[]> {
        const id = masjidId();
        if (!id) return fieldTypes.value;

        await ApiService.get(`/api/admin/masjids/${id}/forms/field-types`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && Array.isArray(res.data?.data) && res.data.data.length) {
                    fieldTypes.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch form field types error: ', e);
            });

        return fieldTypes.value;
    }

    /** Fetch one form with its full schema and settings — what the builder loads to edit. */
    async function fetchForm(formId: number | string): Promise<Form | null> {
        const id = masjidId();
        if (!id) return null;

        const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${id}/forms/${formId}`);
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        return null;
    }

    /** Create a form. Throws on 422 so the builder can surface the field errors. */
    async function createForm(payload: FormPayload): Promise<Form> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/forms`,
            payload
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to create form.');
    }

    /**
     * Update a form. The whole definition is sent every time — UpdateFormRequest makes
     * each key `sometimes`, but a builder that edits the schema in place has the whole
     * thing in hand and a partial PUT would only invite drift.
     */
    async function updateForm(formId: number | string, payload: FormPayload): Promise<Form> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${id}/forms/${formId}`,
            payload
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to update form.');
    }

    // ------------------------------------------------------------- staff codes
    // /forms/{form_id}/staff-codes (FormStaffCodesController): one secret cash code per
    // staff member. Bodies are FormData, which PHP parses on a POST; nothing sent here is a
    // boolean. The plaintext code exists only in issueStaffCode()'s answer, and it is
    // handed straight to the caller, never kept in store state.

    function requireMasjidId(): number | string {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        return id;
    }

    /** The panel's list, and meta: the timezone, the event day and the default expiry. */
    async function fetchStaffCodes(formId: number | string): Promise<{ codes: FormStaffCode[]; meta: FormStaffCodesMeta }> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${id}/forms/${formId}/staff-codes`);
        if (res.data?.status === 'success' && Array.isArray(res.data?.data) && res.data?.meta) {
            return { codes: res.data.data, meta: res.data.meta };
        }

        throw new Error('Unexpected staff codes response.');
    }

    /**
     * Issue a code. `expiresAt` is a calendar day ("2026-10-17", good to midnight at its
     * end on the masjid's clock) or null for the form's event day. Throws on a 422 so the
     * panel can put each refusal beside its field.
     */
    async function issueStaffCode(
        formId: number | string,
        holderName: string,
        expiresAt: string | null
    ): Promise<{ code: FormStaffCodeIssued; message: string }> {
        const id = requireMasjidId();

        const body = new FormData();
        body.append('holder_name', holderName);
        // Left out rather than sent blank: absent is what asks for the event day.
        if (expiresAt) body.append('expires_at', expiresAt);

        const res: AxiosResponse = await ApiService.post(`/api/admin/masjids/${id}/forms/${formId}/staff-codes`, body);
        if (res.data?.status === 'success' && typeof res.data?.data?.code === 'string' && res.data.data.code) {
            return { code: res.data.data, message: res.data.message ?? '' };
        }

        throw new Error('The server did not return the new code. Refresh the list, revoke the code if it appears, and add it again.');
    }

    /** Revoke: the code stops working at once and its row stays, with the cash it recorded. */
    async function revokeStaffCode(formId: number | string, codeId: number): Promise<{ code: FormStaffCode; message: string }> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.delete(`/api/admin/masjids/${id}/forms/${formId}/staff-codes/${codeId}`);
        return codeAnswer(res, 'Could not revoke the code.');
    }

    /** Release the code from its phone, so the next phone to enter it claims it. */
    async function resetStaffCodeDevice(formId: number | string, codeId: number): Promise<{ code: FormStaffCode; message: string }> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/forms/${formId}/staff-codes/${codeId}/reset-device`,
            new FormData()
        );
        return codeAnswer(res, 'Could not release the phone.');
    }

    /** Lift every wrong-code lockout on the form at once. Answers with the server's message. */
    async function clearStaffCodeLockout(formId: number | string): Promise<string> {
        const id = requireMasjidId();

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/forms/${formId}/staff-codes/clear-lockout`,
            new FormData()
        );
        if (res.data?.status === 'success') {
            return res.data.message ?? '';
        }

        throw new Error('Could not clear the lockouts.');
    }

    function codeAnswer(res: AxiosResponse, failure: string): { code: FormStaffCode; message: string } {
        if (res.data?.status === 'success' && res.data?.data) {
            return { code: res.data.data, message: res.data.message ?? '' };
        }

        throw new Error(failure);
    }

    return {
        formOptions,
        fieldTypes,
        masjidId,
        fetchFormOptions,
        fetchFieldTypes,
        fetchForm,
        createForm,
        updateForm,
        fetchStaffCodes,
        issueStaffCode,
        revokeStaffCode,
        resetStaffCodeDevice,
        clearStaffCodeLockout
    }
})
