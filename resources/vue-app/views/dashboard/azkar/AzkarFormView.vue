<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between gap-2">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Zikr</span>
                <span v-else>Add New Zikr</span>
            </div>
            <button v-if="!isEditForm" type="button" class="btn btn-outline-primary"
                @click.prevent="showLibrary = true">
                <i class="bi bi-collection me-1"></i> Choose from Library
            </button>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <!-- Categories Select Input -->
            <ColumnInputContainer label="Category" name="azkar_category_id_input" :show_error="true"
                class="w-100 w-md-50">
                <Field name="azkar_category_id_input" type="text" v-model="azkar_category_id" class="dashboard-input"
                    :class="{ 'placeholder': !azkar_category_id }" v-slot="{ field }">
                    <select v-bind="field" class="dashboard-input" :class="{ 'placeholder': !azkar_category_id }">
                        <option value="" label="select adhkar category" selected>
                            select adhkar category
                        </option>
                        <option v-for="categ in categories" :value="categ.id">
                            {{ categ.title }}
                        </option>
                    </select>
                </Field>
            </ColumnInputContainer>

            <!-- Title Inputs -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Title in Arabic" name="title_ar_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="title_ar_input" type="text" v-model="title.ar" class="dashboard-input"
                        placeholder="title in Arabic goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="Title in English" name="title_en_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="title_en_input" type="text" v-model="title.en" class="dashboard-input"
                        placeholder="title in English goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- Text Inputs -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Text in Arabic" name="text_ar_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="text_ar_input" type="text" v-model="text.ar" class="dashboard-input"
                        placeholder="text in Arabic goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="Text in English" name="text_en_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="text_en_input" type="text" v-model="text.en" class="dashboard-input"
                        placeholder="text in English goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- Bless Inputs -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Bless in Arabic" name="bless_ar_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="bless_ar_input" type="text" v-model="bless.ar" class="dashboard-input"
                        placeholder="bless in Arabic goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="Bless in English" name="bless_en_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="bless_en_input" type="text" v-model="bless.en" class="dashboard-input"
                        placeholder="bless in English goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- Pronunciation Input -->
            <ColumnInputContainer label="Pronunciation" name="pronunciation_input" :show_error="true" class="w-100">
                <Field name="pronunciation_input" type="text" v-model="pronunciation" class="dashboard-input"
                    placeholder="pronunciation goes here"></Field>
            </ColumnInputContainer>

            <!-- Frequency Input -->
            <ColumnInputContainer label="Frequency" name="frequency_input" :show_error="true" class="w-100">
                <Field name="frequency_input" type="number" v-model="frequency" class="dashboard-input"
                    placeholder="frequency goes here"></Field>
            </ColumnInputContainer>

            <!-- Reference Input -->
            <ColumnInputContainer label="Reference" name="reference_input" :show_error="true" class="w-100 w-md-50">
                <Field name="reference_input" type="text" v-model="reference" class="dashboard-input reference-input"
                    placeholder="reference goes here"></Field>
            </ColumnInputContainer>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update Zikr</span>
                <span v-else>Add New</span>
            </LoadingButton>
        </div>

        <!-- Curated library picker -->
        <LibraryPickerModal v-if="showLibrary" type="azkar" @close="showLibrary = false"
            @prefill="onLibraryPrefill" @added="onLibraryAdded" />

    </Form>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import LibraryPickerModal from '@/components/modals/LibraryPickerModal.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Zikr } from '@/core/types/data/Azkar';
import { LibraryAzkarPreset } from '@/core/types/data/LibraryPresets';
import { TranslatableObject } from '@/core/types/data/interfaces/TranslatableObject';
import { useAzkarStore } from '@/stores/azkarStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { number, object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    await azkarStore.fetchAzkarCategories();

    if (route.params?.zikr_id) {
        zikrId.value = route.params.zikr_id as string;
        isEditForm.value = true;
        await azkarStore.fetchZikr(route.params.zikr_id as string, zikr)
    } else {
        zikrId.value = '';
        isEditForm.value = false;
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const azkarStore = useAzkarStore();

// Custom types
type ZikrEntry = {
    azkar_category_id: number;
    title: TranslatableObject;
    text: TranslatableObject;
    bless: TranslatableObject;
    pronunciation: string;
    frequency: number;
    reference: string;
};

// Custom constants
const isEditForm = ref(false);
const zikrId = ref<string>('');
const zikr = ref<Zikr>();
const entryModel = ref<ZikrEntry>({
    azkar_category_id: 0,
    title: { en: '', ar: '' },
    text: { en: '', ar: '' },
    bless: { en: '', ar: '' },
    pronunciation: '',
    frequency: 0,
    reference: ''
});

const {
    azkar_category_id,
    title,
    text,
    bless,
    pronunciation,
    frequency,
    reference
} = toRefs<ZikrEntry>(entryModel.value);

const isLoading = ref(false);

// Library picker
const showLibrary = ref(false);

// Prefill the form from a chosen library preset (admin can still edit before saving).
// The preset's morning/evening category is freeform text, so it is left out of the
// category <select> (which is keyed on existing AzkarCategory ids); the admin picks
// a category as usual, or uses "Add directly" which maps the tag server-side.
const onLibraryPrefill = (preset: LibraryAzkarPreset) => {
    title.value = { ar: preset.title?.ar ?? '', en: preset.title?.en ?? '' };
    text.value = { ar: preset.text?.ar ?? '', en: preset.text?.en ?? '' };
    bless.value = { ar: preset.bless?.ar ?? '', en: preset.bless?.en ?? '' };
    pronunciation.value = preset.pronunciation ?? '';
    frequency.value = preset.frequency ?? 0;
    reference.value = preset.reference ?? '';
};

// "Add directly" copied the preset server-side — refresh the list and go back to it.
const onLibraryAdded = async () => {
    await azkarStore.fetchAzkarPaginated(1);
    router.push('/azkar');
};

// Form
const formValidationSchema = object().shape({
    azkar_category_id_input: string().test('number-or-null', 'value should be numeric or nullable', (val) => {
        let intVal = parseInt(val as string);
        return typeof intVal === 'number' || val == '' || val == null;
    }).optional().label('Category'),
    title_en_input: string().required().label('Title'),
    text_en_input: string().required().label('Text'),
    bless_en_input: string().optional().label('Bless'),
    title_ar_input: string().required().label('Title'),
    text_ar_input: string().required().label('Text'),
    bless_ar_input: string().optional().label('Bless'),
    pronunciation_input: string().required().label('Pronunciation'),
    frequency_input: string().test('number-or-null', 'value should be numeric or nullable', (val) => {
        let intVal = parseInt(val as string);
        return typeof intVal === 'number' || val == '' || val == null;
    }).optional().label('frequency'),
    reference_input: string().optional().label('reference')
});

// Computed
const categories = computed(() => {
    return azkarStore.categories;
});

// Watch
watch(() => zikr.value, () => {
    if (zikr.value) {
        azkar_category_id.value = zikr.value.azkar_category_id;
        title.value = zikr.value.title;
        text.value = zikr.value.text;
        bless.value = zikr.value.bless;
        pronunciation.value = zikr.value.pronunciation;
        frequency.value = zikr.value.frequency;
        reference.value = zikr.value.reference;
    }
})

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the zikr data?" : "Create a new zikr?";
    QSwal.fire("Question", swalMessage, 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                let apiRequestData: FormData | URLSearchParams;

                if (isEditForm.value) {
                    apiRequestData = new URLSearchParams();
                } else {
                    apiRequestData = new FormData();
                }

                apiRequestData.append('azkar_category_id', azkar_category_id.value + '');
                apiRequestData.append('title[ar]', title.value.ar);
                apiRequestData.append('title[en]', title.value.en);
                apiRequestData.append('text[ar]', text.value.ar);
                apiRequestData.append('text[en]', text.value.en);
                apiRequestData.append('bless[ar]', bless.value.ar);
                apiRequestData.append('bless[en]', bless.value.en);
                apiRequestData.append('pronunciation', pronunciation.value);
                apiRequestData.append(`frequency`, frequency.value + '');
                apiRequestData.append(`reference`, reference.value);

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };
                let apiEndpoint: BackendApiRoute | '' = '';
                if (isEditForm.value) {
                    apiEndpoint = `/api/admin/azkar/${zikrId.value}/`;
                    await ApiService.put(apiEndpoint as BackendApiRoute, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Data changed successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await azkarStore.fetchZikr(route.params.zikr_id as string, zikr).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push(`/azkar/${route.params.zikr_id}`);
                                });
                            });
                            isLoading.value = false;
                        });
                } else {
                    apiEndpoint = `/api/admin/azkar`;
                    await ApiService.post(apiEndpoint as BackendApiRoute, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Data changed successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await azkarStore.fetchAzkarPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push('/azkar');
                                });
                            });
                            isLoading.value = false;
                        });
                }

            } else {
                isLoading.value = false;
            }
        })
}

</script>

<style scoped></style>