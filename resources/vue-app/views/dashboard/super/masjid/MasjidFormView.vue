<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <!-- Title -->
        <div class="card-header bg-white border-0">
            <div class="card-title  fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Masjid</span>
                <span v-else>Add New Masjid</span>
            </div>
        </div>

        <!-- Form Fields -->
        <div class="card-body d-flex flex-column gap-5 w-100">

            <!-- Admin Select Input -->
            <ColumnInputContainer label="Admin" name="admin_id_input" :show_error="true" class="w-100 w-md-50">
                <Field name="admin_id_input" type="text" v-model="admin_id" class="dashboard-input"
                    :class="{ 'placeholder': !admin_id }" v-slot="{ field }">
                    <select v-bind="field" class="dashboard-input" :class="{ 'placeholder': !admin_id }">
                        <option value="" label="select masjid admin" selected>
                            select masjid admin
                        </option>
                        <option v-for="admin in masjidAdmins" :value="admin.id">
                            {{ admin.name }}
                        </option>
                    </select>
                </Field>
            </ColumnInputContainer>

            <!-- Image -->
            <div class="d-flex flex-column">
                <ImageDraggableInput label="Masjid Logo"
                    @imageChange="(data: UploadedImageInfo) => onImageInputChange(data)"
                    :current-image-src="oldLogoImage" type="photo" />
                <Field type="file" v-model="logoSrc" name="logo_image" class="d-none"></Field>
                <div class="error-message">
                    <ErrorMessage name="logo_image" />
                </div>
            </div>

            <!-- Name Input -->
            <ColumnInputContainer label="Name" name="name_input" :show_error="true" class="w-100">
                <Field name="name_input" type="text" v-model="name" class="dashboard-input"
                    placeholder="masjid name goes here"></Field>
            </ColumnInputContainer>

            <!-- Email and Phone -->
            <div class="d-flex flex-column gap-4 flex-md-row justify-content-md-between">
                <!-- Email Input -->
                <ColumnInputContainer label="Email" name="email_input" :show_error="true" class="w-100">
                    <Field name="email_input" type="text" v-model="email" class="dashboard-input"
                        placeholder="masjid email goes here"></Field>
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

            <!-- Location Inputs -->
            <div class="d-flex flex-column gap-4 location-inputs-container">
                <span class="fs-5 text-capitalize info-attribute">
                    Location Info
                </span>

                <!-- Country and City -->
                <div class="d-flex flex-column gap-4 flex-md-row justify-content-md-between">
                    <!-- Country Select -->
                    <ColumnInputContainer label="Country" name="country_id_input" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="country_id_input" type="text" v-model="country_id" class="dashboard-input"
                            :class="{ 'placeholder': !country_id }" v-slot="{ field }">
                            <select v-bind="field" class="dashboard-input" :class="{ 'placeholder': !country_id }">
                                <option value="" label="select masjid country" selected>
                                    select masjid country
                                </option>
                                <option v-for="ctry in countries" :value="ctry.id">
                                    {{ ctry.name }}
                                </option>
                            </select>
                        </Field>
                    </ColumnInputContainer>

                    <!-- City Select -->
                    <ColumnInputContainer label="City" name="city_id_input" :show_error="true" class="w-100 w-md-50">
                        <Field name="city_id_input" type="text" v-model="city_id" class="dashboard-input"
                            :class="{ 'placeholder': !city_id }" v-slot="{ field }">
                            <select v-bind="field" class="dashboard-input" :class="{ 'placeholder': !city_id }">
                                <option value="" label="select masjid city" selected>
                                    select masjid city
                                </option>
                                <option v-for="cty in cities" :value="cty.id">
                                    {{ cty.name }}
                                </option>
                            </select>
                        </Field>
                    </ColumnInputContainer>
                </div>

                <!-- Coordenates Inputs -->
                <div class="d-flex flex-column gap-4 flex-md-row justify-content-md-between">
                    <ColumnInputContainer label="Latitude" name="latitude_input" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="latitude_input" type="number" v-model="latitude" class="dashboard-input"
                            placeholder="masjid latitude goes here"></Field>
                    </ColumnInputContainer>
                    <ColumnInputContainer label="Longitude" name="longitude_input" :show_error="true"
                        class="w-100 w-md-50">
                        <Field name="longitude_input" type="number" v-model="longitude" class="dashboard-input"
                            placeholder="masjid longitude goes here"></Field>
                    </ColumnInputContainer>
                </div>

                <!-- Adress Input -->
                <ColumnInputContainer label="Adress" name="address_input" :show_error="true" class="w-100">
                    <Field name="address_input" type="text" v-model="address" class="dashboard-input"
                        placeholder="masjid address goes here"></Field>
                </ColumnInputContainer>

            </div>

        </div>

        <!-- Form Footer -->
        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update Masjid</span>
                <span v-else>Add New</span>
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
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Admin, MasjidAdmin } from '@/core/types/data/Admin';
import { City, Country } from '@/core/types/data/Country';
import { Masjid } from '@/core/types/data/Masjid';
import { UploadedImageInfo } from '@/core/types/elements/ImageInput';
import { VueTelInputCountry } from '@/core/types/elements/VueTelInput';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { useUsersStore } from '@/stores/super/usersStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { Form, Field, useForm } from 'vee-validate';
import { computed, onBeforeMount, ref, toRefs, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { number, object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {

    // await masjidsStore.fetchMasjidsCategories();

    if (route.params?.masjid_id) {
        masjidId.value = route.params.masjid_id as string;
        isEditForm.value = true;
        await masjidsStore.fetchMasjid(route.params.masjid_id as string, masjid)
    } else {
        masjidId.value = '';
        isEditForm.value = false;
    }

    // await ApiService.get('/api/admin/admins/masjid/available')
    //     .then(res => {
    //         if (res.data?.status === 'success' && res.data?.data) {
    //             fetchedAdmins.value = res.data.data;
    //         }
    //     });

    await usersStore.fetchMasjidAdmins(fetchedAdmins);

    await ApiService.get('/api/admin/countries')
        .then(res => {
            if (res.data?.status === 'success' && res.data?.data) {
                fetchedCountries.value = res.data.data;
            }
        });

});

// Html refs

// Routing
const route = useRoute();
const router = useRouter();

// Stores
const masjidsStore = useMasjidsStore();
const authStore = useAuthStore();
const usersStore = useUsersStore();

// Custom types
export type MasjidEntry = {
    admin_id: number | '',
    name: string,
    email: string,
    email_verified_at: string | null,
    phone: string,
    phone_verified_at: string | null,
    country_id: number,
    city_id: number,
    address: string,
    latitude: number,
    longitude: number,
    logoSrc: string | undefined
}

// Custom constants
const isEditForm = ref(false);
const masjidId = ref<string>('');
const masjid = ref<Masjid>();
const entryModel = ref<MasjidEntry>({
    admin_id: '',
    name: '',
    email: '',
    email_verified_at: null,
    phone: '',
    phone_verified_at: null,
    country_id: 0,
    city_id: 0,
    address: '',
    latitude: 0,
    longitude: 0,
    logoSrc: undefined
});
const logoFile = ref<File | undefined>();

const {
    admin_id,
    name,
    email,
    phone,
    country_id,
    city_id,
    address,
    latitude,
    longitude,
    logoSrc
} = toRefs<MasjidEntry>(entryModel.value);

const isLoading = ref(false);

// Form
const formValidationSchema = object().shape({
    logo_image: string().required(),
    admin_id_input: string().test('is_number', 'The selected value should be for a valid masjid admin.', (val) => {
        if (!val) return true;
        else {
            let intVal = parseInt(val);
            return intVal > 0;
        }
    }),
    name_input: string().required(),
    email_input: string().required(),
    phone_input: string().required(),
    country_id_input: string().required(),
    city_id_input: string().required(),
    latitude_input: number()
        .required('Latitude is required')
        .min(-90, 'Latitude must be between -90 and 90')
        .max(90, 'Latitude must be between -90 and 90'),
    longitude_input: number()
        .required('Longitude is required')
        .min(-180, 'Longitude must be between -180 and 180')
        .max(180, 'Longitude must be between -180 and 180'),
    address_input: string().required()
});
const { setFieldValue } = useForm({ validationSchema: formValidationSchema });
const oldLogoImage = computed(() => masjid.value?.logo?.original_url ?? undefined);

// Fetched data
const fetchedAdmins = ref<MasjidAdmin[]>();
const fetchedCountries = ref<Country[]>();
const fetchedCities = ref<City[]>();

// Computed
const masjidAdmins = computed(() => {
    if (isEditForm.value, masjid.value?.admin) {
        return fetchedAdmins.value ? [...fetchedAdmins.value, masjid.value.admin] : [masjid.value.admin];
    }
    return fetchedAdmins.value ? fetchedAdmins.value : [];
});

const countries = computed(() => {
    return fetchedCountries.value ? fetchedCountries.value : [];
});

const cities = computed(() => {
    return fetchedCities.value ? fetchedCities.value : [];
});

// Watch
watch(() => masjid.value, () => {
    if (masjid.value) {
        admin_id.value = masjid.value?.admin?.id ?? '';
        name.value = masjid.value.name;
        email.value = masjid.value.email;
        phone.value = masjid.value.phone;
        country_id.value = masjid.value.country_id;
        city_id.value = masjid.value.city_id;
        address.value = masjid.value.address;
        longitude.value = masjid.value.longitude;
        latitude.value = masjid.value.latitude;
    }
});

watch(() => country_id.value, async () => {
    await ApiService.get(`/api/admin/countries/${country_id.value}/cities`)
        .then(res => {
            if (res.data?.status === 'success' && res.data?.data) {
                fetchedCities.value = res.data.data;
            }
        });
});

// Functions
const onSubmit = async () => {
    isLoading.value = true;
    let swalMessage = isEditForm.value ? "Update the masjid data?" : "Create a new masjid?";
    QSwal.fire("Question", swalMessage, 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                // Sed Request Sent Data
                let apiRequestData: FormData;

                apiRequestData = new FormData();

                if (admin_id.value) apiRequestData.append('user_id', admin_id.value + '');
                apiRequestData.append('name', name.value);
                apiRequestData.append(`email`, email.value);
                apiRequestData.append(`phone`, phone.value);
                apiRequestData.append(`country_id`, country_id.value + '');
                apiRequestData.append(`city_id`, city_id.value + '');
                apiRequestData.append(`address`, address.value);
                apiRequestData.append(`longitude`, longitude.value + '');
                apiRequestData.append(`latitude`, latitude.value + '');

                if (logoFile.value) {
                    apiRequestData.append(`logo`, logoFile.value);
                }

                // Send the request
                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };
                let apiEndpoint: BackendApiRoute | '' = '';
                if (isEditForm.value) {
                    apiEndpoint = `/api/admin/masjids/${masjidId.value}/`;
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
                            await masjidsStore.fetchMasjid(route.params.masjid_id as string, masjid).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push(`/dashboard/super/masjids/${masjidId.value}`).finally(() => {
                                        isLoading.value = false;
                                    });
                                })
                            });
                            isLoading.value = false;
                        });
                } else {
                    apiEndpoint = `/api/admin/masjids`;
                    await ApiService.post(apiEndpoint as BackendApiRoute, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Data changed successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Unexpected";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "success";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await masjidsStore.fetchMasjid(masjidId.value, masjid).finally(() => {
                                MSwal.fire(swalInstance).finally(() => {
                                    router.push('/dashboard/super/masjids').finally(() => {
                                        isLoading.value = false;
                                    });
                                });
                            });
                        });
                }

            } else {
                isLoading.value = false;
            }
        })
}

const onImageInputChange = (data: UploadedImageInfo) => {
    setFieldValue('logo_image', data.src);
    logoSrc.value = data.src;
    logoFile.value = data.file;
};

</script>

<style scoped>
.location-inputs-container {
    border: 1px solid var(--input-border);
    padding: 1rem;
    border-radius: .5rem;
}
</style>