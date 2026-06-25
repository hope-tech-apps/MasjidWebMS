<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit User</span>
                <span v-else>Add New User</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <!-- Image -->
            <div class="d-flex flex-column">
                <ImageDraggableInput label="User Avatar"
                    @imageChange="(data: UploadedImageInfo) => onImageInputChange(data)"
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
            <div class="d-flex flex-column gap-4 flex-md-row justify-content-md-between">
                <!-- Email Input -->
                <ColumnInputContainer label="Email" name="email_input" :show_error="true" class="w-100">
                    <Field name="email_input" type="text" v-model="email" class="dashboard-input"
                        placeholder="user email goes here"></Field>
                </ColumnInputContainer>

                <!-- Phone Input -->
                <ColumnInputContainer label="Phone" name="phone_input" :show_error="true" class="w-100">
                    <Field name="phone_input" type="text" v-model="phone" v-slot="{ field }"
                        placeholder="+971 *** *** ****">
                        <vue-tel-input v-bind="field" v-model="phone"
                            @country-changed="(country: VueTelInputCountry) => { phone = applyCountryDialCode(phone, country) }"
                            class="dashboard-input">
                        </vue-tel-input>
                    </Field>
                </ColumnInputContainer>
            </div>

            <!-- Type Select Input -->
            <ColumnInputContainer label="Type" name="type_input" :show_error="true" class="w-100 w-md-50">
                <Field name="type_input" type="text" v-model="type" class="dashboard-input"
                    :class="{ 'placeholder': !type }" v-slot="{ field }">
                    <select v-bind="field" class="dashboard-input" :class="{ 'placeholder': !type }">
                        <option value="" label="select user admin" selected>
                            select user admin
                        </option>
                        <option value="User">
                            User
                        </option>
                        <option value="MasjidAdmin">
                            Masjid Admin
                        </option>
                    </select>
                </Field>
            </ColumnInputContainer>

            <!-- Password and Phone -->
            <div class="d-flex flex-column gap-4 w-100">
                <button v-if="isEditForm" @click.prevent="togglePasswordField = !togglePasswordField"
                    class="btn btn-sm btn-warning text-light align-self-start">
                    <span v-if="!togglePasswordField">Change Password</span>
                    <span v-else>Dont Change Password</span>
                </button>
                <div v-if="allowChangePassword"
                    class="d-flex flex-column gap-4 flex-md-row justify-content-md-between overflow-x-auto">
                    <!-- Old Password Input -->
                    <ColumnInputContainer v-if="isEditForm" label="Old Password" name="old_password_input"
                        :show_error="true" class="w-100">
                        <PasswordInput name="old_password_input" v-model="old_password" />
                    </ColumnInputContainer>

                    <!-- Password Input -->
                    <ColumnInputContainer label="Password" name="new_password_input" :show_error="true" class="w-100">
                        <PasswordInput name="new_password_input" v-model="password" />
                    </ColumnInputContainer>

                    <!-- Confirm Password Input -->
                    <ColumnInputContainer label="Password" name="password_confirmation_input" :show_error="true"
                        class="w-100">
                        <PasswordInput name="password_confirmation_input" v-model="confirmation" />
                    </ColumnInputContainer>
                </div>
            </div>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update User</span>
                <span v-else>Add New</span>
            </LoadingButton>
        </div>

    </Form>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue';
import PasswordInput from '@/components/form/PasswordInput.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { User, UserType } from '@/core/types/data/User';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { VueTelInputCountry } from '@/core/types/elements/VueTelInput';
import { applyCountryDialCode } from '@/assets/ts/handleVueTelInput';
import { useUsersStore } from '@/stores/super/usersStore';
import { AxiosError, AxiosResponse } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, useForm, ErrorMessage } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { object, string, ref as yupRef } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    // await usersStore.fetchUsersCategories();

    if (route.params?.user_id) {
        userId.value = route.params.user_id as string;
        isEditForm.value = true;
        await usersStore.fetchUser(route.params.user_id as string, user)
    } else {
        userId.value = '';
        isEditForm.value = false;
    }

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const usersStore = useUsersStore();

// Custom types
type UserEntry = {
    name: string;
    email: string;
    phone: string;
    type: UserType;
    avatarSrc: string | undefined;
    old_password: string;
    password: string;
    confirmation: string;
}

// Custom constants
const isEditForm = ref(false);
const userId = ref<string>('');
const user = ref<User>();
const entryModel = ref<UserEntry>({
    name: '',
    email: '',
    phone: '',
    type: 'User',
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
    type,
    avatarSrc,
    old_password,
    password,
    confirmation
} = toRefs<UserEntry>(entryModel.value);

const isLoading = ref(false);

// Form
const allowChangePassword = computed(() => {
    return (!isEditForm.value) || (isEditForm.value && togglePasswordField.value);
});
const formValidationSchema = computed(() => {
    let initialSchema = {
        avatar_image: string().required(),
        type_input: string().test('is_accepted_type', 'The selected value should be a valid user type.', (val) => {
            return val ? ['MasjidAdmin', 'User'].includes(val) : false;
        }),
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
    let oldPasswordFieldSchema = {
        old_password_input: string()
            .required('Password confirmation is required')
    }

    if (allowChangePassword.value) {
        if (isEditForm.value) {
            return object().shape({ ...initialSchema, ...oldPasswordFieldSchema, ...passwordFieldsSchema });
        } else {
            return object().shape({ ...initialSchema, ...passwordFieldsSchema });
        }
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
        type.value = user.value.type;
        name.value = user.value.name;
        email.value = user.value.email;
        phone.value = user.value.phone;
    }
});

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the user data?" : "Create a new user?";
    QSwal.fire("Question", swalMessage, 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                let apiRequestData: FormData;

                apiRequestData = new FormData();

                if (type.value) apiRequestData.append('user_id', type.value + '');
                apiRequestData.append('name', name.value);
                apiRequestData.append(`email`, email.value);
                apiRequestData.append(`phone`, phone.value);
                apiRequestData.append(`type`, type.value);

                if (avatarFile.value) {
                    apiRequestData.append(`avatar`, avatarFile.value);
                }

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };
                let apiEndpoint: BackendApiRoute | '' = '';
                if (isEditForm.value) {
                    apiEndpoint = `/api/admin/users/${userId.value}/`;
                    if (password.value) {
                        apiRequestData.append(`old_password`, old_password.value);
                        apiRequestData.append(`password`, password.value);
                        apiRequestData.append(`password_confirmation`, confirmation.value);
                    }
                } else {
                    apiEndpoint = `/api/admin/users`;
                    apiRequestData.append(`old_password`, old_password.value);
                    apiRequestData.append(`password`, password.value);
                    apiRequestData.append(`password_confirmation`, confirmation.value);
                }
                let requestSucceeded = false;
                await ApiService.post(apiEndpoint as BackendApiRoute, apiRequestData)
                    .then((res: AxiosResponse<BackendResponseData>) => {
                        if (res.data.status === 'success') {
                            requestSucceeded = true;
                            swalInstance.title = "Success";
                            // Surface a backend message when provided (e.g. an archived user
                            // was restored on email reuse); otherwise show the generic text.
                            swalInstance.text = res.data.message ?? "Data changed successfully.";
                            swalInstance.icon = "success";
                        } else {
                            swalInstance.title = "Sorry";
                            swalInstance.text = getMessageFromObj(res);
                            swalInstance.icon = "warning";
                        }
                    })
                    .catch((e: AxiosError<BackendResponseData>) => {
                        console.log(e);
                        // Surface backend validation errors (e.g. duplicate email) inline; keep the form data.
                        swalInstance.title = e.response?.status === 422 ? "Validation Error" : e.message;
                        swalInstance.text = getMessageFromObj(e);
                        swalInstance.icon = "error";
                    })
                    .finally(async () => {
                        // On validation/backend errors keep the entered values and stay on the form.
                        // Only refetch + navigate away on a successful save.
                        if (requestSucceeded) {
                            if (isEditForm.value) {
                                await usersStore.fetchUser(route.params.user_id as string, user);
                            }
                            MSwal.fire(swalInstance).finally(() => {
                                if (isEditForm.value) {
                                    router.push(`/dashboard/super/users/${route.params.user_id}`).finally(() => {
                                        isLoading.value = false;
                                    });
                                } else {
                                    router.push('/dashboard/super/users').finally(() => {
                                        isLoading.value = false;
                                    });
                                }
                            });
                        } else {
                            MSwal.fire(swalInstance);
                            isLoading.value = false;
                        }
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