<template>
    <div class="border rounded bg-white d-flex flex-column">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-bottom bg-light">
            <span class="fw-semibold small">
                Preview
                <span v-if="loading" class="spinner-border spinner-border-sm ms-1 text-secondary" role="status">
                    <span class="visually-hidden">Updating the preview</span>
                </span>
            </span>
            <div class="d-flex gap-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Preview part">
                    <button type="button" class="btn" :class="part === 'html' ? 'btn-secondary' : 'btn-outline-secondary'"
                        :aria-pressed="part === 'html'" @click="part = 'html'">Email</button>
                    <button type="button" class="btn" :class="part === 'text' ? 'btn-secondary' : 'btn-outline-secondary'"
                        :aria-pressed="part === 'text'" @click="part = 'text'">Plain text</button>
                </div>
                <div v-if="part === 'html'" class="btn-group btn-group-sm" role="group" aria-label="Preview width">
                    <button type="button" class="btn" :class="width === 'wide' ? 'btn-secondary' : 'btn-outline-secondary'"
                        :aria-pressed="width === 'wide'" @click="width = 'wide'">Computer</button>
                    <button type="button" class="btn" :class="width === 'phone' ? 'btn-secondary' : 'btn-outline-secondary'"
                        :aria-pressed="width === 'phone'" @click="width = 'phone'">Phone</button>
                </div>
            </div>
        </div>

        <div v-if="stale" class="alert alert-secondary small m-3 mb-0" role="status">
            The preview below is from before your latest changes and will update once these are fixed.
        </div>

        <div v-if="errors.length" class="alert alert-warning small m-3 mb-0" role="status">
            <div class="fw-semibold mb-1">Fix these before sending:</div>
            <ul class="mb-0 ps-3">
                <li v-for="(message, i) in errors" :key="i">{{ message }}</li>
            </ul>
        </div>

        <div v-if="warnings.length" class="alert alert-info small m-3 mb-0" role="status">
            <div v-for="(message, i) in warnings" :key="i">{{ message }}</div>
        </div>

        <div class="p-3 d-flex justify-content-center">
            <!--
                sandbox="" with no tokens: the email runs no script, has an opaque
                origin (it cannot read the admin's session), and cannot navigate the
                admin's tab — its links are inert here, which is right for a preview.
            -->
            <iframe v-if="part === 'html'" ref="frame" sandbox="" :srcdoc="frameDoc" title="Email preview"
                class="border rounded" :style="{ width: width === 'phone' ? '375px' : '100%', height: '720px' }"></iframe>
            <pre v-else class="w-100 mb-0 p-3 bg-light border rounded small" style="white-space: pre-wrap; max-height: 720px; overflow: auto;">{{ text ?? 'This email is sent without a plain-text part, exactly as before.' }}</pre>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * The newsletter's live preview: the real email, rendered by the server.
 *
 * Every edit (debounced) posts the composer's current title, message, link and
 * layout to POST /broadcasts/preview, which renders them through BroadcastMail —
 * the class that sends the email — and returns both parts. There is no second copy
 * of the email's markup in the SPA to drift from the inbox.
 *
 * The server always renders what is finished and lists, in `errors`, what the send
 * would still refuse (worded as the send words it), so the preview keeps following
 * the layout while new blocks are still empty. `warnings` carries the early size
 * warning. Only a request that is not a draft at all is refused; then the last
 * render stays up and is marked out of date rather than passed off as current.
 *
 * Pictures have not been uploaded yet, so the server addresses each at a reserved
 * preview.invalid URL and withLocalImages swaps in the admin's own scaled-down copy
 * (previewCopy), never the full upload.
 *
 * Responses can arrive out of order when the admin types quickly; each request
 * carries a sequence number and only the newest is ever shown.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import ApiService from '@/core/services/ApiService'
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes'
import { toPayload, withLocalImages, type EditorBlock } from '@/core/helpers/newsletterBlocks'

const props = defineProps<{
    masjidId: number | string | undefined;
    title: string;
    body: string;
    link: string;
    blocks: EditorBlock[];
    images: Record<string, string | undefined>;
    /** data URL of the composer image, when one is attached and the email will carry it */
    composerImage?: string;
}>()

const part = ref<'html' | 'text'>('html')
const width = ref<'wide' | 'phone'>('wide')
const html = ref('')
const text = ref<string | null>(null)
const errors = ref<string[]>([])
const warnings = ref<string[]>([])
/** The frame shows an earlier render than the composer's current state. */
const stale = ref(false)
const loading = ref(false)

let sequence = 0
let timer: ReturnType<typeof setTimeout> | undefined

const frameDoc = computed(() => withLocalImages(html.value, props.images, props.composerImage))

async function refresh() {
    if (!props.masjidId) return
    const mine = ++sequence
    loading.value = true

    const fd = new FormData()
    fd.append('title', props.title)
    fd.append('body', props.body)
    // An address still being typed is left out; the send checks it.
    if (/^https?:\/\/\S+$/i.test(props.link)) fd.append('link', props.link)
    fd.append('blocks', JSON.stringify(toPayload(props.blocks)))
    fd.append('with_image', props.composerImage ? '1' : '0')

    await ApiService.post(`/api/admin/masjids/${props.masjidId}/broadcasts/preview` as BackendApiRoute, fd)
        .then(res => {
            if (mine !== sequence) return
            const data = res.data?.data
            html.value = data?.html ?? ''
            text.value = data?.text ?? null
            errors.value = Object.values((data?.errors ?? {}) as Record<string, string>)
            warnings.value = Array.isArray(data?.warnings) ? data.warnings : []
            stale.value = false
        })
        .catch(e => {
            if (mine !== sequence) return
            const data = e?.response?.data?.data
            errors.value = e?.response?.status === 422 && data && typeof data === 'object'
                ? Object.values(data as Record<string, string[]>).flat()
                : ['The preview could not be loaded. Your newsletter is not affected; try again in a moment.']
            warnings.value = []
            stale.value = html.value !== ''
        })
        .finally(() => {
            if (mine === sequence) loading.value = false
        })
}

watch(
    () => [props.masjidId, props.title, props.body, props.link, props.blocks, props.composerImage],
    () => {
        clearTimeout(timer)
        timer = setTimeout(refresh, 500)
    },
    { deep: true, immediate: true },
)

onBeforeUnmount(() => {
    clearTimeout(timer)
    // Anything still in flight is ignored when it lands.
    sequence++
})
</script>
