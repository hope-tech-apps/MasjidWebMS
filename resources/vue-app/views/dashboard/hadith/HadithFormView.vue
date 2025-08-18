<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Hadith</span>
                <span v-else>Add New Hadith</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <!-- Title Input -->
            <ColumnInputContainer label="Title" name="title_input" :show_error="true" class="w-100">
                <Field name="title_input" type="text" v-model="title" class="dashboard-input"
                    placeholder="title goes here"></Field>
            </ColumnInputContainer>

            <!-- Isnad Input -->
            <ColumnInputContainer label="Isnad" name="isnad_input" :show_error="true" class="w-100">
                <Field name="isnad_input" as="textarea" v-model="isnad" class="dashboard-input isnad-input"
                    placeholder="isnad goes here"></Field>
            </ColumnInputContainer>

            <!-- Matn Input -->
            <ColumnInputContainer label="Matn" name="matn_input" :show_error="true" class="w-100">
                <Field name="matn_input" as="textarea" v-model="matn" class="dashboard-input matn-input"
                    placeholder="matn goes here"></Field>
            </ColumnInputContainer>

            <!-- Description Input -->
            <ColumnInputContainer label="Description" name="description_input" :show_error="true" class="w-100 w-md-50">
                <Field name="description_input" as="textarea" v-model="description"
                    class="dashboard-input description-input" placeholder="description goes here"></Field>
            </ColumnInputContainer>

            <!-- Strength Select Input -->
            <ColumnInputContainer label="Strength" name="strength_input" :show_error="true" class="w-100 w-md-50">
                <Field name="strength_input" type="text" v-model="strength" v-slot="{ field }" class="dashboard-input">
                    <select v-bind="field" class="dashboard-input">
                        <option v-for="value in HADITH_STRENGTHS" :value="value.en">
                            {{ JSON.stringify(value) }}
                        </option>
                    </select>
                </Field>
            </ColumnInputContainer>

            <!-- Muhaddith Inputs -->
            <div class="d-flex flex-column gap-5 flex-md-row justify-content-md-between">
                <ColumnInputContainer label="Muhaddith in Arabic" name="muhaddith_ar_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="muhaddith_ar_input" type="text" v-model="muhaddith.ar" class="dashboard-input"
                        placeholder="muhaddith in Arabic goes here"></Field>
                </ColumnInputContainer>
                <ColumnInputContainer label="Muhaddith in English" name="muhaddith_en_input" :show_error="true"
                    class="w-100 w-md-50">
                    <Field name="muhaddith_en_input" type="text" v-model="muhaddith.en" class="dashboard-input"
                        placeholder="muhaddith in English goes here"></Field>
                </ColumnInputContainer>
            </div>

            <!-- References Inputs -->
            <div class="d-flex flex-column gap-2">
                <template v-if="referencesFieldsNumber">
                    <div v-for="x in referencesFieldsNumber"
                        class="d-flex flex-column gap-2 flex-md-row justify-content-mb-between">
                        <ColumnInputContainer :label="`Reference ${x} Title`" :name="`reference_${x}_title_input`"
                            :show_error="true" class="w-100 w-md-25">
                            <Field :name="`reference_${x}_title_input`" type="text"
                                v-model="references[x - 1].title" class="dashboard-input"
                                :placeholder="`reference ${x} title goes here`"></Field>
                        </ColumnInputContainer>
                        <ColumnInputContainer :label="`Reference ${x}`" :name="`reference_${x}_ref_input`"
                            :show_error="true" class="w-100 w-md-75">
                            <Field :name="`reference_${x}_ref_input`" type="text"
                                v-model="references[x - 1].reference" class="dashboard-input"
                                :placeholder="`reference ${x} goes here`"></Field>
                        </ColumnInputContainer>
                    </div>
                </template>
                <button type="button" @click.prevent="addReferenceField"
                    class="btn btn-primary btn-sm align-self-end add-ref-btn">
                    + Reference
                </button>
            </div>

            <ColumnInputContainer label="Display Date" name="show_date_input" :show_error="true" class="w-100">
                <Field name="show_date_input" type="date" v-model="show_date" class="dashboard-input"></Field>
            </ColumnInputContainer>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update Hadith</span>
                <span v-else>Add New</span>
            </LoadingButton>
        </div>

    </Form>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Hadith, HADITH_STRENGTHS, HadithReference } from '@/core/types/data/Hadith';
import { useHadithStore } from '@/stores/hadithStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    if (route.params?.hadith_id) {
        hadithId.value = route.params.hadith_id as string;
        isEditForm.value = true;
        hadithStore.fetchHadith(route.params.hadith_id as string, hadith)
    } else {
        hadithId.value = '';
        isEditForm.value = false;
    }

    for (let i = 0; i < numberOfReferences.value; i++) {
        references.value.push({ title: "", reference: "" });
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const hadithStore = useHadithStore();

// Custom types
type HadithEntry = {
    title: string;
    isnad: string;
    matn: string;
    strength: string;
    muhaddith: {
        en: string;
        ar: string;
    };
    references: HadithReference[],
    ref_1: string;
    ref_2: string;
    ref_3: string;
    description: string;
    show_date: string;
};

// Custom constants
const isEditForm = ref(false);
const hadithId = ref<string>('');
const hadith = ref<Hadith>();
const entryModel = ref<HadithEntry>({
    title: "",
    isnad: "",
    matn: "",
    strength: "",
    muhaddith: {
        en: "",
        ar: ""
    },
    references: [],
    ref_1: "",
    ref_2: "",
    ref_3: "",
    description: "",
    show_date: ""
});

const {
    title,
    isnad,
    matn,
    strength,
    muhaddith,
    references,
    description,
    show_date
} = toRefs<HadithEntry>(entryModel.value);

const isLoading = ref(false);
const numberOfReferences = ref<number>(4);

// Form
const formValidationSchema = object().shape({
    title_input: string().required('Title is required'),
    isnad_input: string().required('Isnad is required'),
    matn_input: string().required('Matn is required'),
    strength_input: string().required('Strength (English) is required'),
    muhaddith_ar_input: string().required('Muhaddith (Arabic) is required'),
    muhaddith_en_input: string().required('Muhaddith (English) is required'),
    reference_1_title_input: string().required().label('First Reference'),
    reference_1_ref_input: string().required().label('First Reference'),
    description_input: string().required('Description is required'),
    show_date_input: string()
        .required('Show date is required')
        .test('is-date', 'value should be a valid date', (value) => {
            if (value) {
                let date = new Date(value);
                return !isNaN(date.getTime());
            }
        }).required()
});

// Computed
const referencesFieldsNumber = computed(() => {
    return numberOfReferences.value;
});

// Watch
watch(() => hadith.value, () => {
    if (hadith.value) {
        title.value = hadith.value.title;
        isnad.value = hadith.value.isnad;
        matn.value = hadith.value.matn;
        strength.value = hadith.value.strength?.en ?? '';
        muhaddith.value = hadith.value.muhaddith as { ar: string; en: string };
        references.value = hadith.value.references;
        numberOfReferences.value = references.value.length;
        show_date.value = hadith.value.show_date;
        description.value = hadith.value.description;
    }
})

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the hadith data?" : "Create a new hadith?";
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

                apiRequestData.append('title', title.value);
                apiRequestData.append('isnad', isnad.value);
                apiRequestData.append('matn', matn.value);
                apiRequestData.append('strength', strength.value);
                apiRequestData.append('muhaddith_ar', muhaddith.value.ar);
                apiRequestData.append('muhaddith_en', muhaddith.value.en);
                for (let i = 0; i < references.value.length; i++) {
                    if (references.value[i].title && references.value[i].reference) {
                        apiRequestData.append(`references[${i}][title]`, references.value[i].title);
                        apiRequestData.append(`references[${i}][reference]`, references.value[i].reference);
                    }
                }
                apiRequestData.append('description', description.value);
                apiRequestData.append('show_date', show_date.value);


                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };
                let apiEndpoint: BackendApiRoute | '' = '';
                if (isEditForm.value) {
                    apiEndpoint = `/api/admin/hadiths/${hadithId.value}/`;
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
                            await hadithStore.fetchHadith(hadithId.value, hadith).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push(`/hadith/${hadithId.value}`);
                                    isLoading.value = false;
                                });
                            });
                        });
                } else {
                    apiEndpoint = `/api/admin/hadiths`;
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
                            await hadithStore.fetchHadithsPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push('/hadith');
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

const addReferenceField = () => {
    numberOfReferences.value++;
    references.value.push({ title: "", reference: "" });
}

</script>

<style scoped>
.isnad-input {
    height: 6rem;
}

.matn-input {
    height: 9rem;
}

.description-input {
    height: 12rem;
}

.add-ref-btn {
    width: 8rem;
}
</style>