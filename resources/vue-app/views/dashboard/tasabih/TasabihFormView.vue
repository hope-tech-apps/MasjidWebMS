<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Tasbih</span>
                <span v-else>Add New Tasbih</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

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

            <!-- Pronunciation Input -->
            <ColumnInputContainer label="Pronunciation" name="pronunciation_input" :show_error="true" class="w-100">
                <Field name="pronunciation_input" type="text" v-model="pronunciation" class="dashboard-input"
                    placeholder="pronunciation goes here"></Field>
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
                <span v-if="isEditForm">Update Tasbih</span>
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
import { Tasbih } from '@/core/types/data/Tasabih';
import { TranslatableObject } from '@/core/types/data/interfaces/TranslatableObject';
import { useTasabihStore } from '@/stores/tasabihStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field } from 'vee-validate';
import { onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    if (route.params?.tasbih_id) {
        tasbihId.value = route.params.tasbih_id as string;
        isEditForm.value = true;
        await tasabihStore.fetchTasbih(route.params.tasbih_id as string, tasbih)
    } else {
        tasbihId.value = '';
        isEditForm.value = false;
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const tasabihStore = useTasabihStore();

// Custom types
type TasbihEntry = {
    text: TranslatableObject;
    pronunciation: string;
    reference: string;
};

// Custom constants
const isEditForm = ref(false);
const tasbihId = ref<string>('');
const tasbih = ref<Tasbih>();
const entryModel = ref<TasbihEntry>({
    text: { en: '', ar: '' },
    pronunciation: '',
    reference: ''
});

const {
    text,
    pronunciation,
    reference
} = toRefs<TasbihEntry>(entryModel.value);

const isLoading = ref(false);

// Form
const formValidationSchema = object().shape({
    text_en_input: string().required().label('Text'),
    text_ar_input: string().required().label('Text'),
    pronunciation_input: string().required().label('Pronunciation'),
    reference_input: string().optional().label('reference')
});

// Computed

// Watch
watch(() => tasbih.value, () => {
    if (tasbih.value) {
        text.value = tasbih.value.text;
        pronunciation.value = tasbih.value.pronunciation;
        reference.value = tasbih.value.reference;
    }
})

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the tasbih data?" : "Create a new tasbih?";
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

                apiRequestData.append('text[ar]', text.value.ar);
                apiRequestData.append('text[en]', text.value.en);
                apiRequestData.append('pronunciation', pronunciation.value);
                apiRequestData.append(`reference`, reference.value);

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };
                let apiEndpoint: BackendApiRoute | '' = '';
                if (isEditForm.value) {
                    apiEndpoint = `/api/admin/tasabih/${tasbihId.value}/`;
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
                            await tasabihStore.fetchTasbih(route.params.tasbih_id as string, tasbih).finally(() => {
                                MSwal.fire(swalInstance);
                            });
                            isLoading.value = false;
                        });
                } else {
                    apiEndpoint = `/api/admin/tasabih`;
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
                            await tasabihStore.fetchTasabihPaginated(1).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push('/tasabih');
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