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

    return {
        formOptions,
        fieldTypes,
        masjidId,
        fetchFormOptions,
        fetchFieldTypes,
        fetchForm,
        createForm,
        updateForm
    }
})
