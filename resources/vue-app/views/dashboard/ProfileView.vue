<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                Update Profile
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100 overflow-x-auto">

            <!-- Image -->
            <div class="d-flex flex-column w-100">
                <ImageDraggableInput label="Avatar" @imageChange="(data: UploadedImageInfo) => onImageInputChange(data)"
                    :current-image-src="oldAvatarImage" type="photo" />
                <Field type="file" v-model="avatarSrc" name="avatar_image" class="d-none"></Field>
                <div class="error-message">
                    <ErrorMessage name="avatar_image" class="error-message" />
                </div>
            </div>

            <!-- Name Input -->
            <ColumnInputContainer label="Name" name="name_input" :show_error="true" class="w-100">
                <Field name="name_input" type="text" v-model="name" class="dashboard-input"
                    placeholder="user name goes here"></Field>
            </ColumnInputContainer>

            <!-- Email and Phone -->
            <div class="d-flex flex-column gap-4 flex-md-row justify-content-md-between w-100">
                <!-- Email Input -->
                <ColumnInputContainer label="Email" name="email_input" :show_error="true" class="w-100">
                    <Field name="email_input" type="text" v-model="email" class="dashboard-input"
                        placeholder="user email goes here"></Field>
                </ColumnInputContainer>

                <!-- Phone Input -->
                <ColumnInputContainer label="Phone" name="phone_input" :show_error="true" class="w-100">
                    <Field name="phone_input" type="text" v-model="phone" v-slot="{ field }"
                        placeholder="+971 *** *** ****">
                        <vue-tel-input v-bind="field" v-model="phone" @country-changed="(country: VueTelInputCountry) => {
                            if (!phone)
                                phone = `+${country.dialCode} `
                        }" class="dashboard-input">
                        </vue-tel-input>
                    </Field>
                </ColumnInputContainer>
            </div>

            <!-- Password and Phone -->
            <div class="d-flex flex-column gap-4 w-100">
                <button @click.prevent="togglePasswordField = !togglePasswordField"
                    class="btn btn-sm btn-warning text-light align-self-start">
                    <span v-if="!togglePasswordField">Change Password</span>
                    <span v-else>Dont Change Password</span>
                </button>
                <div v-if="togglePasswordField"
                    class="d-flex flex-column gap-2 flex-md-row justify-content-md-between w-100">

                    <!-- Old Password Input -->
                    <ColumnInputContainer label="Old Password" name="old_password_input" :show_error="true"
                        class="w-100">
                        <Field name="old_password_input" type="password" v-model="old_password" class="dashboard-input"
                            placeholder="********"></Field>
                    </ColumnInputContainer>

                    <!-- Nee Password Input -->
                    <ColumnInputContainer label="New Password" name="new_password_input" :show_error="true"
                        class="w-100">
                        <Field name="new_password_input" type="password" v-model="password" class="dashboard-input"
                            placeholder="********"></Field>
                    </ColumnInputContainer>

                    <!-- Confirm Password Input -->
                    <ColumnInputContainer label="Confirm Password" name="password_confirmation_input" :show_error="true"
                        class="w-100">
                        <Field name="password_confirmation_input" type="password" v-model="confirmation"
                            class="dashboard-input" placeholder="********"></Field>
                    </ColumnInputContainer>

                </div>
            </div>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span>Save Changes</span>
            </LoadingButton>
        </div>

    </Form>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { VueTelInputCountry } from '@/core/types/elements/VueTelInput';
import { useAuthStore } from '@/stores/authStore';
import { useUsersStore } from '@/stores/super/usersStore';
import { AxiosError, AxiosResponse } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, useForm, ErrorMessage } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute } from 'vue-router';
import { object, string, ref as yupRef } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {
    await authStore.fetchAuthUser();
});

// Html refs

// Routing
const route = useRoute();

// Stores
const authStore = useAuthStore();
const usersStore = useUsersStore();

// Custom types
type UserEntry = {
    name: string;
    email: string;
    phone: string;
    avatarSrc: string | undefined;
    old_password: string;
    password: string;
    confirmation: string;
}

// Custom constants
const userId = ref<string>('');
const user = computed(() => {
    return authStore.user;
});
const entryModel = ref<UserEntry>({
    name: '',
    email: '',
    phone: '',
    avatarSrc: undefined,
    old_password: '',
    password: '',
    confirmation: ''
});
const avatarFile = ref<File | undefined>();
const togglePasswordField = ref(false);

const {
    name,
    email,
    phone,
    avatarSrc,
    old_password,
    password,
    confirmation
} = toRefs<UserEntry>(entryModel.value);

const isLoading = ref(false);

// Form
const formValidationSchema = computed(() => {
    let initialSchema = {
        avatar_image: string().required(),
        name_input: string().required(),
        email_input: string().email().required(),
        phone_input: string()
            .matches(/^$|^\+?[0-9 ]+$/, "must have the curruent format: '+[digits and spaces only]'")
            .test(
                'min-length-8',
                'must be at least 8 digits',
                (value) => {
                    if (value === null || value?.length === 0 || (value && value?.length >= 8)) {
                        return true;
                    } else {
                        return false;
                    }
                }).required(),
    }
    let passwordFieldsSchema = {
        old_password_input: string()
            .required('Password confirmation is required'),
        new_password_input: string()
            .required('Password is required')
            .min(8, 'Password must be at least 8 characters')
            .matches(/[A-Z]/, 'Password must contain at least one uppercase letter')
            .matches(/[a-z]/, 'Password must contain at least one lowercase letter')
            .matches(/[0-9]/, 'Password must contain at least one number')
            .matches(/[@$!%*?&#]/, 'Password must contain at least one special character'),
        password_confirmation_input: string()
            .required('Password confirmation is required')
            .oneOf([yupRef('new_password_input')], 'Passwords must match'), // Ensures confirmation matches the original password
    }

    if (togglePasswordField.value) {
        return object().shape({ ...initialSchema, ...passwordFieldsSchema });
    } else {
        return object().shape(initialSchema);
    }
});
// const formValidationSchema = object().shape(formValidationSchemaObject.value);
const { setFieldValue } = useForm({ validationSchema: formValidationSchema });
const oldAvatarImage = computed(() => user.value?.avatar?.original_url ?? undefined);

// Fetched data


// Watch
watch(() => user.value, () => {
    if (user.value) {
        name.value = user.value.name;
        email.value = user.value.email;
        phone.value = user.value.phone;
    }
});

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    QSwal.fire("Question", "Update your profile?", 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                let apiRequestData: FormData;

                apiRequestData = new FormData();

                apiRequestData.append('name', name.value);
                apiRequestData.append(`email`, email.value);
                apiRequestData.append(`phone`, phone.value);

                if (avatarFile.value) {
                    apiRequestData.append(`avatar`, avatarFile.value);
                }

                if (togglePasswordField.value) {
                    apiRequestData.append(`old_password`, old_password.value);
                    apiRequestData.append(`password`, password.value);
                    apiRequestData.append(`password_confirmation`, confirmation.value);
                }

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                await ApiService.post(`/api/admin/profile`, apiRequestData)
                    .then((res: AxiosResponse<BackendResponseData>) => {
                        if (res.data.status === 'success') {
                            swalInstance.title = "Success";
                            swalInstance.text = "Data changed successfully.";
                            swalInstance.icon = "success";
                        } else {
                            swalInstance.title = "Unexpected";
                            swalInstance.text = getMessageFromObj(res);
                            swalInstance.icon = "error";
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                    .finally(async () => {
                        await authStore.fetchAuthUser().finally(() => {
                            MSwal.fire(swalInstance);
                        });
                        isLoading.value = false;
                    });

            } else {
                isLoading.value = false;
            }
        })
}

const onImageInputChange = (data: UploadedImageInfo) => {
    setFieldValue('avatar_image', data.src);
    avatarSrc.value = data.src;
    avatarFile.value = data.file;
};

</script>

<style scoped>
.location-inputs-container {
    border: 1px solid var(--input-border);
    padding: 1rem;
    border-radius: .5rem;
}
</style>