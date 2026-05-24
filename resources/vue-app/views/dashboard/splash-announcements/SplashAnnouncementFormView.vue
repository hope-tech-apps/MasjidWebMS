<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">
                <span v-if="isEditForm">Edit Splash Announcement</span>
                <span v-else>Add Splash Announcement</span>
            </div>
            <div class="text-muted small mt-1">
                Shows as a modal on the public site and as an in-app message on mobile (via OneSignal).
                One splash is shown at a time per masjid — priority breaks ties.
            </div>
        </div>

        <div class="card-body d-flex flex-column gap-4 w-100">

            <!-- Image -->
            <div class="d-flex flex-column">
                <ImageDraggableInput label="Image" @imageChange="onImageInputChange"
                    :current-image-src="oldImage" type="photo" />
                <Field type="file" v-model="imageSrc" name="image" class="d-none" />
                <div class="error-message">
                    <ErrorMessage v-if="errorMessage" name="image">{{ errorMessage }}</ErrorMessage>
                </div>
                <small class="text-muted">PNG, JPG, GIF, or WebP up to 25MB. SVG is not allowed.</small>
            </div>

            <!-- Title -->
            <ColumnInputContainer label="Title" name="title" :show_error="true" class="w-100">
                <Field name="title" type="text" v-model="form.title" class="dashboard-input"
                    placeholder="e.g. Eid Mubarak" />
            </ColumnInputContainer>

            <!-- Body -->
            <ColumnInputContainer label="Body" name="body" :show_error="false" class="w-100">
                <Field name="body" as="textarea" v-model="form.body" class="dashboard-input"
                    placeholder="Optional short message shown below the title" rows="4" />
                <small class="text-muted">Plain text. HTML is sanitized before display.</small>
            </ColumnInputContainer>

            <!-- CTA -->
            <div class="d-flex flex-column flex-md-row gap-3">
                <ColumnInputContainer label="CTA Button Label" name="cta_label" :show_error="true" class="w-100 w-md-50">
                    <Field name="cta_label" type="text" v-model="form.cta_label" class="dashboard-input"
                        placeholder="e.g. Donate Now" />
                </ColumnInputContainer>
                <ColumnInputContainer label="CTA URL" name="cta_url" :show_error="true" class="w-100 w-md-50">
                    <Field name="cta_url" type="url" v-model="form.cta_url" class="dashboard-input"
                        placeholder="https://..." />
                </ColumnInputContainer>
            </div>
            <small class="text-muted">Leave both blank if you don't want a button. Both fields must be set together.</small>

            <!-- Schedule -->
            <div class="d-flex flex-column flex-md-row gap-3">
                <ColumnInputContainer label="Starts At" name="starts_at" :show_error="true" class="w-100 w-md-50">
                    <Field name="starts_at" type="datetime-local" v-model="form.starts_at" class="dashboard-input" />
                </ColumnInputContainer>
                <ColumnInputContainer label="Ends At" name="ends_at" :show_error="true" class="w-100 w-md-50">
                    <Field name="ends_at" type="datetime-local" v-model="form.ends_at" class="dashboard-input" />
                </ColumnInputContainer>
            </div>

            <!-- Priority + Active -->
            <div class="d-flex flex-column flex-md-row gap-3">
                <ColumnInputContainer label="Priority (0–100)" name="priority" :show_error="true" class="w-100 w-md-50">
                    <Field name="priority" type="number" min="0" max="100" v-model="form.priority"
                        class="dashboard-input" />
                    <small class="text-muted">Higher wins when two splashes overlap.</small>
                </ColumnInputContainer>
                <div class="w-100 w-md-50 d-flex align-items-center">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" id="splash-active" v-model="form.is_active">
                        <label class="form-check-label ms-2" for="splash-active">Active</label>
                    </div>
                </div>
            </div>

        </div>

        <div class="card-footer d-flex align-items-center justify-content-end w-100 bg-white border-0">
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span v-if="isEditForm">Update</span>
                <span v-else>Create</span>
            </LoadingButton>
        </div>

    </Form>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods'
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue'
import ImageDraggableInput from '@/components/form/ImageDraggableInput.vue'
import LoadingButton from '@/components/form/LoadingButton.vue'
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2'
import ApiService from '@/core/services/ApiService'
import { BackendResponseData } from '@/core/types/config/AxiosCustom'
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes'
import { SplashAnnouncement } from '@/core/types/data/masjid-related/SplashAnnouncement'
import { UploadedImageInfo } from '@/core/types/elements/ImageInput'
import { useMasjidStore } from '@/stores/masjidStore'
import { useSplashAnnouncementsStore } from '@/stores/masjid/splashAnnouncementsStore'
import { AxiosError } from 'axios'
import { SweetAlertOptions } from 'sweetalert2'
import { Form, Field, ErrorMessage, useForm, useField } from 'vee-validate'
import { computed, onBeforeMount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { object, string, number, boolean } from 'yup'

const route = useRoute()
const router = useRouter()

const masjidStore = useMasjidStore()
const store = useSplashAnnouncementsStore()

const isEditForm = ref(false)
const splashId = ref<string>('')
const splash = ref<SplashAnnouncement>()
const isLoading = ref(false)
const imageFile = ref<File | undefined>(undefined)

const form = ref({
    title: '',
    body: '',
    cta_label: '',
    cta_url: '',
    starts_at: '',
    ends_at: '',
    priority: 0,
    is_active: true,
})
const imageSrc = ref<string | undefined>('')

const oldImage = computed(() => splash.value?.image?.original_url ?? '')

const formValidationSchema = object().shape({
    title: string().required().max(255),
    body: string().nullable().max(5000),
    cta_label: string().nullable().max(120).test('paired-with-url', 'CTA label requires a URL', function (value) {
        return !value || !!this.parent.cta_url
    }),
    cta_url: string().nullable().url().max(2048).test('paired-with-label', 'CTA URL requires a label', function (value) {
        return !value || !!this.parent.cta_label
    }),
    starts_at: string().required('Start time is required'),
    ends_at: string().required('End time is required').test('after-start', 'End must be after start', function (value) {
        if (!value) return false
        return new Date(value).getTime() > new Date(this.parent.starts_at).getTime()
    }),
    priority: number().min(0).max(100),
    is_active: boolean(),
    image: string().when([], {
        is: () => !isEditForm.value && !imageFile.value,
        then: (s) => s.required('An image is required'),
        otherwise: (s) => s.nullable(),
    }),
})
const { setFieldValue } = useForm({ validationSchema: formValidationSchema })
const { errorMessage } = useField('image')

onBeforeMount(async () => {
    if (route.params?.splash_id) {
        splashId.value = route.params.splash_id as string
        isEditForm.value = true
        await store.fetchSplash(splashId.value, splash)
    }
})

watch(() => splash.value, (s) => {
    if (!s) return
    form.value.title = s.title
    form.value.body = s.body ?? ''
    form.value.cta_label = s.cta_label ?? ''
    form.value.cta_url = s.cta_url ?? ''
    // <input type="datetime-local"> wants `YYYY-MM-DDTHH:mm` — strip seconds and tz.
    form.value.starts_at = toLocalInput(s.starts_at)
    form.value.ends_at = toLocalInput(s.ends_at)
    form.value.priority = s.priority
    form.value.is_active = s.is_active
    imageSrc.value = s.image?.original_url
})

function toLocalInput(iso: string): string {
    const d = new Date(iso)
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function onImageInputChange(data: UploadedImageInfo) {
    setFieldValue('image', data.src)
    imageSrc.value = data.src
    imageFile.value = data.file
}

async function onSubmit() {
    isLoading.value = true
    const msg = isEditForm.value ? 'Update splash announcement?' : 'Create splash announcement?'
    const result = await QSwal.fire('Confirm', msg, 'question')
    if (!result.isConfirmed) {
        isLoading.value = false
        return
    }

    const fd = new FormData()
    fd.append('title', form.value.title)
    fd.append('body', form.value.body ?? '')
    if (form.value.cta_label) fd.append('cta_label', form.value.cta_label)
    if (form.value.cta_url) fd.append('cta_url', form.value.cta_url)
    // Convert local time to ISO so the backend's `date` validator accepts it deterministically.
    fd.append('starts_at', new Date(form.value.starts_at).toISOString())
    fd.append('ends_at', new Date(form.value.ends_at).toISOString())
    fd.append('priority', String(form.value.priority ?? 0))
    fd.append('is_active', form.value.is_active ? '1' : '0')
    if (imageFile.value) fd.append('image', imageFile.value)

    let endpoint: BackendApiRoute | '' = ''
    if (masjidStore.masjid?.id) {
        endpoint = isEditForm.value
            ? `/api/admin/masjids/${masjidStore.masjid.id}/splash-announcements/${splashId.value}`
            : `/api/admin/masjids/${masjidStore.masjid.id}/splash-announcements`
    }

    const swal: SweetAlertOptions = { title: 'Info', text: '', icon: 'info' }
    await ApiService.post(endpoint as BackendApiRoute, fd)
        .then(res => {
            if (res.data?.status === 'success') {
                swal.title = 'Success'
                swal.text = isEditForm.value ? 'Splash updated.' : 'Splash created.'
                swal.icon = 'success'
            } else {
                swal.title = 'Sorry'
                swal.text = getMessageFromObj(res)
                swal.icon = 'warning'
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            swal.title = e.message
            swal.text = getMessageFromObj(e)
            swal.icon = 'error'
        })
        .finally(async () => {
            await store.fetchSplashesPaginated(1)
            await MSwal.fire(swal)
            isLoading.value = false
            router.push('/masjid/splash-announcements')
        })
}
</script>
