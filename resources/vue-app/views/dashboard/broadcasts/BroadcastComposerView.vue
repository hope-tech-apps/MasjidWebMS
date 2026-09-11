<template>
    <Form :validationSchema="formValidationSchema" class="card border-0 py-4 px-3 w-100" @submit="onSubmit()">

        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">Compose a broadcast</div>
            <div class="text-muted small mt-1">
                Write once and send to as many channels as you need. Each channel is delivered on its own —
                if one fails the others still go, and you'll see exactly which did what.
            </div>
        </div>

        <div class="card-body d-flex flex-column gap-4 w-100">

            <!-- Message -->
            <ColumnInputContainer label="Title" name="title" :show_error="true" class="w-100">
                <Field name="title" type="text" v-model="form.title" class="dashboard-input"
                    placeholder="e.g. Jumu'ah is at 1:15pm this week" />
            </ColumnInputContainer>

            <ColumnInputContainer label="Message" name="body" :show_error="true" class="w-100">
                <Field name="body" as="textarea" v-model="form.body" class="dashboard-input" rows="5"
                    placeholder="The message people will read." />
            </ColumnInputContainer>

            <ColumnInputContainer label="Link (optional)" name="link" :show_error="true" class="w-100">
                <Field name="link" type="url" v-model="form.link" class="dashboard-input" placeholder="https://..." />
                <small class="text-muted">Added to the announcement, email and signage. Push carries the message only.</small>
            </ColumnInputContainer>

            <!-- Channels -->
            <div class="d-flex flex-column">
                <label class="form-label fw-semibold">Send to</label>
                <div class="d-flex flex-column gap-2">
                    <div v-for="c in CHANNELS" :key="c.value" class="form-check">
                        <input class="form-check-input" type="checkbox" :id="`ch-${c.value}`" :value="c.value"
                            v-model="form.channels">
                        <label class="form-check-label ms-2" :for="`ch-${c.value}`">
                            <span class="fw-semibold">{{ c.label }}</span>
                            <span class="text-muted small d-block">{{ c.hint }}</span>
                        </label>
                    </div>
                </div>
                <div v-if="form.channels.length === 0" class="error-message">Choose at least one channel.</div>
            </div>

            <!-- Announcement leg needs a picture and a run of dates -->
            <div v-if="hasAnnouncement" class="border rounded p-3 d-flex flex-column gap-3">
                <div class="fw-semibold">Announcement details</div>
                <div class="text-muted small">
                    The announcements feed shows a picture and runs between two dates, so these are required
                    when that channel is selected.
                </div>

                <ImageDraggableInput label="Image" @imageChange="onImageInputChange" type="photo" />
                <small class="text-muted">PNG, JPG, GIF or WebP up to 25MB.</small>

                <div class="d-flex flex-column flex-md-row gap-3">
                    <ColumnInputContainer label="Starts on" name="starts_on" :show_error="true" class="w-100 w-md-50">
                        <Field name="starts_on" type="date" v-model="form.starts_on" class="dashboard-input" />
                    </ColumnInputContainer>
                    <ColumnInputContainer label="Ends on" name="ends_on" :show_error="true" class="w-100 w-md-50">
                        <Field name="ends_on" type="date" v-model="form.ends_on" class="dashboard-input" />
                    </ColumnInputContainer>
                </div>
            </div>

            <!-- Audience -->
            <div class="d-flex flex-column gap-2">
                <label class="form-label fw-semibold">Who should get it</label>

                <div class="form-check">
                    <input class="form-check-input" type="radio" id="aud-everyone" value="everyone"
                        v-model="form.audience">
                    <label class="form-check-label ms-2" for="aud-everyone">
                        <span class="fw-semibold">Everyone</span>
                        <span class="text-muted small d-block">Every registered device, and every contact with an email.</span>
                    </label>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="radio" id="aud-service" value="service"
                        v-model="form.audience" :disabled="!crmEnabled">
                    <label class="form-check-label ms-2" for="aud-service">
                        <span class="fw-semibold">People interested in one service</span>
                        <span class="text-muted small d-block">
                            Only members who asked to hear about it in the app, on the phones they signed in on.
                        </span>
                    </label>
                </div>

                <div v-if="!crmEnabled" class="text-muted small ms-4">
                    Interest-based sending needs the CRM enabled for this organization.
                </div>

                <div v-if="form.audience === 'service'" class="ms-4 mt-2">
                    <ColumnInputContainer label="Service" name="service_id" :show_error="true" class="w-100 w-md-50">
                        <Field name="service_id" as="select" v-model="form.service_id" class="dashboard-input">
                            <option value="">Choose a service…</option>
                            <option v-for="s in services" :key="s.id" :value="s.id">{{ s.title }}</option>
                        </Field>
                    </ColumnInputContainer>
                    <div class="text-muted small mt-1">
                        Nobody is added or removed by sending — the list is worked out when the message goes,
                        so anyone who turned this service off beforehand won't receive it.
                    </div>
                </div>
            </div>

            <!-- The one combination the server refuses -->
            <div v-if="pushWarning" class="alert alert-warning mb-0 py-2 px-3 small">{{ pushWarning }}</div>

            <!-- Schedule -->
            <ColumnInputContainer label="Send at (optional)" name="scheduled_at" :show_error="true" class="w-100 w-md-50">
                <Field name="scheduled_at" type="datetime-local" v-model="form.scheduled_at" class="dashboard-input" />
                <small class="text-muted">Leave blank to send immediately.</small>
            </ColumnInputContainer>

        </div>

        <div class="card-footer d-flex align-items-center justify-content-end gap-2 w-100 bg-white border-0">
            <button type="button" class="btn btn-light" @click="router.push('/masjid/broadcasts')">Cancel</button>
            <LoadingButton type="submit" :is-loading="isLoading" classes="btn btn-success">
                <span>{{ form.scheduled_at ? 'Schedule' : 'Send now' }}</span>
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
import { Service } from '@/core/types/data/masjid-related/Service'
import { UploadedImageInfo } from '@/core/types/elements/ImageInput'
import { useMasjidStore } from '@/stores/masjidStore'
import { useBroadcastsStore } from '@/stores/masjid/broadcastsStore'
import { AxiosError, AxiosResponse } from 'axios'
import { SweetAlertOptions } from 'sweetalert2'
import { Form, Field } from 'vee-validate'
import { computed, onBeforeMount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { object, string, array, number } from 'yup'

const router = useRouter()
const masjidStore = useMasjidStore()
const store = useBroadcastsStore()

const CHANNELS = [
    { value: 'announcement', label: 'Announcements feed', hint: 'Adds a post to the app and website feed. Needs a picture and a date range.' },
    { value: 'push', label: 'Push notification', hint: 'A notification on people\'s phones.' },
    { value: 'signage', label: 'Lobby screen', hint: 'Puts it on the TV board while it is running.' },
    { value: 'email', label: 'Email', hint: 'Emails contacts. Needs the CRM.' },
    { value: 'sms', label: 'Text message', hint: 'Only reaches contacts who gave written consent, from your registered number.' },
]

const isLoading = ref(false)
const imageFile = ref<File | undefined>(undefined)
const services = ref<Service[]>([])

const form = ref({
    title: '',
    body: '',
    link: '',
    channels: [] as string[],
    audience: 'everyone' as 'everyone' | 'service',
    service_id: '' as string | number,
    starts_on: '',
    ends_on: '',
    scheduled_at: '',
})

const crmEnabled = computed(() => !!masjidStore.masjid?.crm_enabled)
const hasAnnouncement = computed(() => form.value.channels.includes('announcement'))

/**
 * The server refuses push to a hand-picked list of contacts, because most
 * devices are not signed in and the send would quietly reach a fraction of the
 * people chosen. That audience is not offered here, so this only ever fires as
 * a guard if the options change — but the sentence is the one the admin needs.
 */
const pushWarning = computed(() => {
    // A service audience now narrows every channel (email and SMS included), so
    // the only refused combination left is push to a hand-picked list.
    if (form.value.audience === 'contacts' && form.value.channels.includes('push')) {
        return 'Push cannot be narrowed to chosen contacts: most devices are not signed in, so it would reach only a few of them. Send push to everyone, address a service instead, or drop push.'
    }
    return ''
})

const formValidationSchema = object().shape({
    title: string().required('A title is required').max(255),
    body: string().required('A message is required'),
    link: string().nullable().url('Must be a full URL, starting https://').max(2048),
    starts_on: string().when([], {
        is: () => hasAnnouncement.value,
        then: (s) => s.required('The announcement needs a start date'),
        otherwise: (s) => s.nullable(),
    }),
    ends_on: string().when([], {
        is: () => hasAnnouncement.value,
        then: (s) => s.required('The announcement needs an end date')
            .test('after-start', 'The end date must be after the start date', function (value) {
                if (!value) return false
                return new Date(value).getTime() > new Date(form.value.starts_on).getTime()
            }),
        otherwise: (s) => s.nullable(),
    }),
    service_id: number().transform(v => (isNaN(v) ? undefined : v)).when([], {
        is: () => form.value.audience === 'service',
        then: (s) => s.required('Choose the service this is for'),
        otherwise: (s) => s.nullable(),
    }),
})

onBeforeMount(async () => {
    if (!masjidStore.masjid?.id) return
    // The full catalogue, not a page of it — this is a picker, and a service on
    // page two would simply be unreachable.
    await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/services`)
        .then((res: AxiosResponse) => {
            if (res.data?.status === 'success') {
                services.value = res.data.data?.data ?? res.data.data ?? []
            }
        })
        .catch((e: Error) => console.log('Fetch services error: ', e))
})

function onImageInputChange(data: UploadedImageInfo) {
    imageFile.value = data.file
}

async function onSubmit() {
    if (form.value.channels.length === 0) return
    if (hasAnnouncement.value && !imageFile.value) {
        await MSwal.fire({ title: 'Picture needed', text: 'The announcements feed needs an image.', icon: 'warning' })
        return
    }

    isLoading.value = true

    // Naming the channels back to the admin before anything leaves: a push
    // cannot be recalled, and "Send now" is the last reversible moment.
    const names = CHANNELS.filter(c => form.value.channels.includes(c.value)).map(c => c.label).join(', ')
    const who = form.value.audience === 'service'
        ? `people interested in ${services.value.find(s => String(s.id) === String(form.value.service_id))?.title ?? 'that service'}`
        : 'everyone'
    const when = form.value.scheduled_at ? 'This will be scheduled.' : 'This sends immediately and cannot be recalled.'

    const confirmed = await QSwal.fire('Confirm', `Send "${form.value.title}" to ${who} via ${names}? ${when}`, 'question')
    if (!confirmed.isConfirmed) {
        isLoading.value = false
        return
    }

    const fd = new FormData()
    fd.append('title', form.value.title)
    fd.append('body', form.value.body)
    if (form.value.link) fd.append('link', form.value.link)
    form.value.channels.forEach(c => fd.append('channels[]', c))
    fd.append('audience', form.value.audience)
    if (form.value.audience === 'service') fd.append('service_id', String(form.value.service_id))
    if (hasAnnouncement.value) {
        fd.append('starts_on', form.value.starts_on)
        fd.append('ends_on', form.value.ends_on)
    }
    if (form.value.scheduled_at) fd.append('scheduled_at', new Date(form.value.scheduled_at).toISOString())
    if (imageFile.value) fd.append('image', imageFile.value)

    let endpoint: BackendApiRoute | '' = ''
    if (masjidStore.masjid?.id) {
        endpoint = `/api/admin/masjids/${masjidStore.masjid.id}/broadcasts`
    }

    const swal: SweetAlertOptions = { title: 'Info', text: '', icon: 'info' }
    await ApiService.post(endpoint as BackendApiRoute, fd)
        .then(res => {
            if (res.data?.status === 'success') {
                // `partial` is a real outcome, not an error — some channels went
                // and some did not, and the admin has to know which.
                const status = res.data?.data?.status
                swal.title = status === 'partial' ? 'Partly sent' : 'Sent'
                swal.text = status === 'partial'
                    ? 'Some channels went out and some did not. Open the broadcast to see which.'
                    : (form.value.scheduled_at ? 'Scheduled.' : 'On its way.')
                swal.icon = status === 'partial' ? 'warning' : 'success'
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
            await store.fetchBroadcastsPaginated(1)
            await MSwal.fire(swal)
            isLoading.value = false
            router.push('/masjid/broadcasts')
        })
}
</script>
