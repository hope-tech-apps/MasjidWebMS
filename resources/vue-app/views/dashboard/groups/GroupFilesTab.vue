<template>
    <div>
        <!-- READ ONLY, said once at the top, the way the gradebook and the
             lesson plans beside it do. Uploading, renaming and removing are the
             teacher's: publishing a file to families mails every guardian in
             the room, and removing one destroys the only copy of the bytes. -->
        <p class="text-muted small mb-3">
            The files this class keeps.
            They are uploaded and removed by the class teacher — this screen shows them, it does not change them.
        </p>

        <div v-if="loadError" class="alert alert-warning py-2 small">{{ loadError }}</div>

        <div v-if="loading" class="text-muted small">Loading…</div>

        <template v-else>
            <!-- The LIST leads and the empty sentence is the guarded branch, not
                 the other way round: `loadError` is also written by a failed
                 download, when the files are on screen and perfectly real. Only
                 a list that actually came back empty may say the class keeps
                 nothing — a fetch that failed knows nothing about what it keeps,
                 and the alert above has already said so. -->
            <div v-if="files.length" class="list-group">
                <div v-for="file in files" :key="file.id"
                     class="list-group-item d-flex align-items-center gap-3 flex-wrap">
                    <i class="bi bi-file-earmark fs-5 text-muted"></i>

                    <div class="flex-grow-1" style="min-width:14rem">
                        <div class="fw-semibold small">{{ file.title }}</div>
                        <div v-if="file.description" class="text-muted small">{{ file.description }}</div>
                        <div class="text-muted small">
                            {{ file.original_name }} · {{ sizeText(file.size_bytes) }} · {{ typeText(file.mime_type) }}
                        </div>
                        <div v-if="file.created_at" class="text-muted small">Added {{ when(file.created_at) }}</div>
                    </div>

                    <!-- WHO CAN SEE THIS, on every row and never only on the
                         shared ones. The office is the desk that fields the
                         phone call about what a parent saw, and "staff only"
                         has to be a thing this screen SAYS rather than a thing
                         it leaves out. A file addressed to NAMED STUDENTS says
                         how many, never who: the office can see the row and can
                         fetch the bytes, and the names of the children a handout
                         went to are the teacher's screen, not a badge. -->
                    <span class="badge" :class="audienceBadgeClass(file)">{{ audienceLabel(file) }}</span>

                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            :disabled="downloadingId === file.id" @click="download(file)">
                        <i class="bi bi-download me-1"></i>
                        {{ downloadingId === file.id ? 'Downloading…' : 'Download' }}
                    </button>
                </div>
            </div>

            <p v-else-if="!loadError" class="text-muted small">No files have been kept for this class yet.</p>
        </template>
    </div>
</template>

<script setup lang="ts">
import ApiService from '@/core/services/ApiService';
import { AxiosResponse } from 'axios';
import { computed, onMounted, ref } from 'vue';

/**
 * The class's files, for the office.
 *
 * Two GETs and nothing else: the list, and the bytes of one row. Every write
 * the teacher realm exposes here is absent by construction — an upload set to
 * `families` dispatches a notification to every guardian in the class, a
 * visibility change publishes a file that was private, and a delete takes the
 * bytes off the disk with the row (GroupResource's `deleting` hook). None of
 * those are the office's to perform on a teacher's behalf. See routes/admin.php.
 *
 * The rows are `GroupResource::toStaffArray()`, the serializer the TEACHER realm
 * reads — this mount is the same controller, and the office is staff. It is
 * deliberately NOT `toAudienceArray()`, which the FAMILY realm reads: anything
 * added to that one is published to parents, which is why who a file was
 * addressed to lives on the staff shape instead.
 */
const props = defineProps<{ groupId: number; masjidId: number }>();

const base = computed(() => `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}`);

// Pinned rather than the browser's, matching the other admin school screens.
const LOCALE = 'en-US';

const loading = ref(true);
const loadError = ref('');
const files = ref<any[]>([]);
const downloadingId = ref<number | null>(null);

/**
 * The types the upload form accepts, in words.
 *
 * The fallback is the RAW mime type rather than "File" or a guess off the
 * extension: an unrecognised type is a file the office may need to name to
 * somebody, and the string the server recorded is the only honest answer.
 */
const TYPE_LABELS: Record<string, string> = {
    'application/pdf': 'PDF',
    'application/msword': 'Word document',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word document',
    'image/jpeg': 'JPEG image',
    'image/png': 'PNG image',
};

const typeText = (mime: string | null): string => (mime ? TYPE_LABELS[mime] ?? mime : 'Unknown type');

/**
 * Who a file reaches, in words — the three audiences, on every row.
 *
 * `recipient_count` comes from `GroupResource::toStaffArray()` and is served for
 * EVERY visibility (0 on a whole-class or staff-only file), so this never has to
 * special-case an absent key. Zero on a TARGETED file is reachable and is not
 * "everyone": every student it named has come off the roster, so it reaches
 * staff and nobody else — which is exactly the state the desk fielding the phone
 * call needs named.
 */
const audienceLabel = (file: any): string => {
    if (file?.visibility === 'families') return 'Shared with families';
    if (file?.visibility !== 'students') return 'Staff only';

    const n = Number(file?.recipient_count ?? 0);

    if (n === 0) return 'No students left';

    return `${n} ${n === 1 ? 'student' : 'students'}`;
};

const audienceBadgeClass = (file: any): string => (file?.visibility === 'families'
    ? 'bg-warning-subtle text-warning-emphasis'
    : file?.visibility === 'students'
        ? 'bg-info-subtle text-info-emphasis'
        : 'bg-light text-muted');

// Rounded up to a whole KB, as the teacher's and the family's lists do. A file
// measured in bytes is a file nobody can compare to another one at a glance.
const sizeText = (bytes: number | null): string => `${Math.max(1, Math.round(Number(bytes ?? 0) / 1024))} KB`;

const when = (iso: string | null): string => {
    if (!iso) return '';
    const d = new Date(iso);

    return Number.isNaN(d.getTime())
        ? ''
        : d.toLocaleDateString(LOCALE, { month: 'short', day: 'numeric', year: 'numeric' });
};

/**
 * Newest first, restated here rather than taken on trust.
 *
 * The endpoint orders by `created_at` then `id` descending, and this repeats
 * that order instead of relying on it, because the order is the whole of what
 * makes a growing file list readable and a list that quietly reorders itself
 * after a backend change is a bug nobody reports. `id` breaks the tie for the
 * same reason the server needs it to: two files uploaded in the same second
 * have the same timestamp.
 */
const sortedNewestFirst = (rows: any[]): any[] => [...rows].sort((a, b) => {
    const at = Date.parse(a?.created_at ?? '');
    const bt = Date.parse(b?.created_at ?? '');
    if (Number.isFinite(at) && Number.isFinite(bt) && at !== bt) return bt - at;

    return Number(b?.id ?? 0) - Number(a?.id ?? 0);
});

const load = async () => {
    loading.value = true;
    loadError.value = '';
    try {
        const res = await ApiService.get(`${base.value}/resources` as any);
        files.value = sortedNewestFirst(res.data?.data ?? []);
    } catch (e: any) {
        loadError.value = e?.response?.data?.message ?? 'The files for this class could not be loaded.';
    } finally {
        loading.value = false;
    }
};

/**
 * Pull the bytes down and hand them to the browser as a file.
 *
 * Through the axios instance rather than `ApiService.get`, for the reason
 * flyersStore, groupFeedStore and contactCredentialsStore already record: these
 * bytes are served by an AUTHENTICATED endpoint, so the request needs the
 * Authorization header the instance already carries, and `ApiService.get()`
 * cannot ask for a blob. A plain `<a href>` would 401.
 *
 * Saved under the teacher's own filename and never rendered inline — no new
 * tab, no object URL that outlives the click. A class file can be a worksheet
 * or it can be a scan that names a child, and this screen cannot tell which,
 * so it treats every one of them the way ContactCredentialsPanel treats a scan.
 */
const download = async (file: any) => {
    downloadingId.value = file.id;
    loadError.value = '';
    try {
        const res: AxiosResponse = await ApiService.VueApp.axios.get(
            `${base.value}/resources/${file.id}/download`,
            { responseType: 'blob' }
        );

        const objectUrl = URL.createObjectURL(res.data as Blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = file.original_name;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(objectUrl);
    } catch {
        loadError.value = `"${file.title}" could not be downloaded.`;
    } finally {
        downloadingId.value = null;
    }
};

onMounted(load);
</script>
