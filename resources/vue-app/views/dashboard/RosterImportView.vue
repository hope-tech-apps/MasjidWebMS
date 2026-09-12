<template>
    <div>
        <PageDataContainer title="Import Roster" :hideButton="true">
            <div class="container w-100 roster-import">

                <!-- ============================================ 1. PICK A FILE -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-filetype-csv me-2"></i>The file
                        </h6>
                        <p class="text-muted small mb-3">
                            One row per child <em>and</em> guardian — a child with two guardians is two rows
                            with the same student columns. Every class named in the file must already be
                            one of your {{ groupsTerm.toLowerCase() }}; this will never create one.
                        </p>

                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <input
                                ref="fileInput"
                                type="file"
                                class="form-control w-auto flex-grow-1"
                                accept=".csv,text/csv,text/plain"
                                :disabled="busy"
                                @change="onFileChosen"
                            >
                            <button class="btn btn-outline-secondary" type="button" @click="downloadTemplate">
                                <i class="bi bi-download me-1"></i>Blank template
                            </button>
                            <button
                                class="btn btn-primary"
                                type="button"
                                :disabled="!file || busy"
                                @click="runPreview"
                            >
                                <span v-if="previewing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="bi bi-eye me-1"></i>
                                Preview
                            </button>
                        </div>

                        <p class="text-muted small mb-0 font-monospace text-break">{{ HEADERS.join(',') }}</p>
                    </div>
                </div>

                <div v-if="loadError" class="alert alert-danger" role="alert">{{ loadError }}</div>

                <!--
                    THE SERVER'S PER-LINE REFUSALS, AS LINES. `commit()` ships the
                    refused rows in `data.refused` precisely so the office can fix
                    the file without previewing again; run through apiErrorText
                    they arrive as one unbroken paragraph, which is unreadable
                    past about three rows and useless for the exact job it exists
                    to do.
                -->
                <div v-if="commitRefusals.length" class="card border-danger shadow-sm mb-4">
                    <div class="card-body">
                        <h6 class="fw-semibold text-danger mb-2">
                            <i class="bi bi-exclamation-octagon me-2"></i>
                            {{ commitRefusals.length }} row{{ commitRefusals.length === 1 ? '' : 's' }}
                            could not be read when you pressed Import
                        </h6>
                        <ul class="list-unstyled small mb-3">
                            <li v-for="(why, i) in commitRefusals" :key="`c-${i}`" class="text-danger">{{ why }}</li>
                        </ul>
                        <p class="small text-muted mb-0">
                            Nothing was written. The roster changed between your preview and this import —
                            fix the file or preview it again to see where it stands now.
                        </p>
                    </div>
                </div>

                <!-- ============================================== 2. THE PREVIEW -->
                <template v-if="preview && !result">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-3">
                                <i class="bi bi-list-check me-2"></i>What this file would do
                            </h6>

                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th></th>
                                            <th class="text-end">In the file</th>
                                            <th class="text-end">Already on record</th>
                                            <th class="text-end">Would be created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Students</td>
                                            <td class="text-end">{{ preview.totals.students.in_file }}</td>
                                            <td class="text-end">{{ preview.totals.students.existing }}</td>
                                            <td class="text-end fw-semibold">{{ preview.totals.students.to_create }}</td>
                                        </tr>
                                        <tr>
                                            <td>Guardians</td>
                                            <td class="text-end">{{ preview.totals.guardians.in_file }}</td>
                                            <td class="text-end">{{ preview.totals.guardians.existing }}</td>
                                            <td class="text-end fw-semibold">{{ preview.totals.guardians.to_create }}</td>
                                        </tr>
                                        <tr>
                                            <td>Guardian links</td>
                                            <td class="text-end">{{ preview.totals.edges }}</td>
                                            <td class="text-end text-muted">—</td>
                                            <td class="text-end text-muted">—</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!--
                                A guardian match reaches the WHOLE contact table, not
                                only the school's rosters. That is right — a parent who
                                already gives or orders lunch is one person — and it is
                                surprising enough that it gets said out loud.
                            -->
                            <p v-if="preview.totals.guardians.existing > 0" class="small text-muted mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                {{ preview.totals.guardians.existing }} guardian{{ preview.totals.guardians.existing === 1 ? '' : 's' }}
                                already {{ preview.totals.guardians.existing === 1 ? 'matches' : 'match' }} somebody in your
                                contacts — a donor or an event attendee counts. They will be linked, not duplicated.
                            </p>
                        </div>
                    </div>

                    <!-- Refused rows, in red, each naming its line. -->
                    <div v-if="preview.refused.length" class="card border-danger shadow-sm mb-4">
                        <div class="card-body">
                            <h6 class="fw-semibold text-danger mb-2">
                                <i class="bi bi-exclamation-octagon me-2"></i>
                                {{ preview.refused.length }} row{{ preview.refused.length === 1 ? '' : 's' }} cannot be read
                            </h6>
                            <ul class="list-unstyled small mb-3">
                                <li v-for="row in preview.refused" :key="row.line" class="text-danger">
                                    <span class="fw-semibold">Line {{ row.line }}:</span> {{ row.why }}
                                </li>
                            </ul>
                            <p class="small text-muted mb-0">
                                Fix these rows in your spreadsheet and upload it again. Nothing is imported
                                while any row cannot be read — a partly-imported roster is worse than none.
                            </p>
                        </div>
                    </div>

                    <!-- Every person, by name. The totals hide the duplicate; this does not. -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <ul class="nav nav-tabs mb-3">
                                <li class="nav-item">
                                    <button
                                        class="nav-link" :class="{ active: tab === 'students' }"
                                        type="button" @click="tab = 'students'"
                                    >
                                        Students <span class="badge bg-secondary ms-1">{{ preview.students.length }}</span>
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button
                                        class="nav-link" :class="{ active: tab === 'guardians' }"
                                        type="button" @click="tab = 'guardians'"
                                    >
                                        Guardians <span class="badge bg-secondary ms-1">{{ preview.guardians.length }}</span>
                                    </button>
                                </li>
                            </ul>

                            <div class="table-responsive roster-scroll">
                                <table v-if="tab === 'students'" class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th>Line</th><th>Name</th><th>Class</th><th>Grade</th><th>Outcome</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="s in preview.students" :key="`s-${s.line}`">
                                            <td class="text-muted small">{{ s.line }}</td>
                                            <td>{{ s.name }}</td>
                                            <td>{{ s.class }}</td>
                                            <td class="text-muted">{{ s.grade || '—' }}</td>
                                            <td>
                                                <span v-if="s.existing" class="badge bg-light text-dark border">
                                                    <i class="bi bi-link-45deg me-1"></i>Already enrolled
                                                </span>
                                                <span v-else class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="bi bi-plus-lg me-1"></i>Will be created
                                                </span>
                                            </td>
                                        </tr>
                                        <tr v-if="!preview.students.length">
                                            <td colspan="5" class="text-center text-muted py-4">
                                                No readable student rows in this file.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <table v-else class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th>Line</th><th>Name</th><th>Email</th><th>Outcome</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="g in preview.guardians" :key="`g-${g.email}`">
                                            <td class="text-muted small">{{ g.line }}</td>
                                            <td>{{ g.name }}</td>
                                            <td class="text-break">{{ g.email }}</td>
                                            <td>
                                                <span v-if="g.existing" class="badge bg-light text-dark border">
                                                    <i class="bi bi-link-45deg me-1"></i>Already in contacts
                                                </span>
                                                <span v-else class="badge bg-success-subtle text-success border border-success-subtle">
                                                    <i class="bi bi-plus-lg me-1"></i>Will be created
                                                </span>
                                            </td>
                                        </tr>
                                        <tr v-if="!preview.guardians.length">
                                            <td colspan="4" class="text-center text-muted py-4">
                                                No guardians named in this file.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!--
                        WHAT THE IMPORT DOES NOT DO. Always shown, never behind a
                        disclosure triangle: an office that reads "62 students
                        imported" and nothing else will reasonably assume the
                        parents are now set up, and they are not.
                    -->
                    <div class="alert alert-warning" role="alert">
                        <p class="mb-2"><i class="bi bi-shield-exclamation me-2"></i><strong>What this will not do</strong></p>
                        <ul class="mb-0 small">
                            <li>{{ cautions.consent_note }}</li>
                            <li>{{ cautions.login_note }}</li>
                            <li>{{ cautions.classes_note }}</li>
                            <!--
                                Said BEFORE the write. "You can always undo it" is
                                the assumption that makes somebody skip reading
                                sixty rows, and there is no undo here.
                            -->
                            <li class="fw-semibold">{{ cautions.undo_note }}</li>
                        </ul>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 mb-5">
                        <!--
                            "Import this roster", not "Import 4 students". A
                            returning-year file whose children are all already
                            enrolled still writes roster rows and guardian links,
                            and a button reading "Import 0 students" tells that
                            office the file is a no-op. The per-category numbers
                            are in the table above, where they can be read
                            together.
                        -->
                        <button
                            class="btn btn-success"
                            type="button"
                            :disabled="!preview.can_commit || previewExpired || busy"
                            @click="commit"
                        >
                            <span v-if="committing" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="bi bi-upload me-1"></i>
                            Import this roster
                        </button>
                        <button class="btn btn-outline-secondary" type="button" :disabled="busy" @click="startOver">
                            Choose a different file
                        </button>
                        <span v-if="previewExpired" class="text-danger small">
                            This preview has expired. Preview the file again — the roster may have changed since.
                        </span>
                        <span v-else-if="!preview.can_commit" class="text-danger small">
                            {{ staleReason || (preview.refused.length
                                ? 'Fix the refused rows first.'
                                : 'There is nothing in this file to import.') }}
                        </span>
                        <!--
                            The receipt's TTL, on screen. The server has always
                            sent it and nothing rendered it, so a preview read
                            through with a colleague went stale in silence and the
                            office learned about the thirty minutes from a refusal.
                        -->
                        <span v-else-if="previewMinutesLeft !== null" class="text-muted small">
                            This preview is good for {{ previewMinutesLeft }}
                            more minute{{ previewMinutesLeft === 1 ? '' : 's' }}.
                        </span>
                    </div>
                </template>

                <!-- ================================================= 3. THE RECEIPT -->
                <template v-if="result">
                    <div class="card border-success shadow-sm mb-4">
                        <div class="card-body">
                            <h6 class="fw-semibold text-success mb-3">
                                <i class="bi bi-check-circle me-2"></i>Imported
                            </h6>

                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle mb-0">
                                    <tbody>
                                        <tr><td>Students created</td><td class="text-end fw-semibold">{{ result.created.students }}</td></tr>
                                        <tr><td>Students already enrolled</td><td class="text-end">{{ result.matched.students }}</td></tr>
                                        <tr><td>Guardians created</td><td class="text-end fw-semibold">{{ result.created.guardians }}</td></tr>
                                        <tr><td>Guardians already in contacts</td><td class="text-end">{{ result.matched.guardians }}</td></tr>
                                        <tr><td>Roster rows added</td><td class="text-end">{{ result.created.memberships }}</td></tr>
                                        <tr><td>Guardian links added</td><td class="text-end">{{ result.created.edges }}</td></tr>
                                    </tbody>
                                </table>
                            </div>

                            <!--
                                The batch tag is the provenance stamp on every
                                person this wrote, and the handle support needs to
                                identify the file afterwards. On screen and
                                copyable rather than buried in a toast that
                                disappears. It is NOT an undo handle for this
                                screen — there is no undo here.
                            -->
                            <p class="small mb-1 text-muted">
                                Batch tag — every person this created is stamped with it. Keep it if you
                                need to tell us which import a row came from:
                            </p>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                <code class="fs-6">{{ result.batch }}</code>
                                <button class="btn btn-sm btn-outline-secondary" type="button" @click="copyBatch">
                                    <i class="bi bi-clipboard me-1"></i>{{ copied ? 'Copied' : 'Copy' }}
                                </button>
                            </div>

                            <div class="alert alert-warning small mb-3">
                                <ul class="mb-0">
                                    <li>{{ cautions.undo_note }}</li>
                                    <li>{{ cautions.consent_note }}</li>
                                    <li>{{ cautions.login_note }}</li>
                                </ul>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-secondary" type="button" :disabled="busy" @click="startOver">
                                    Import another file
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ApiService from '@/core/services/ApiService';
import { apiErrorText } from '@/core/services/ApiErrors';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * Bulk roster import, as three stages of one screen: pick a file, READ WHAT IT
 * WILL DO, commit.
 *
 * `schools:import-roster` has been correct since the school module shipped and
 * needed a shell on the production host, so the office that owns the roster
 * could not run its own import. This screen is that command's second caller;
 * every rule about what a valid roster is lives on the server, in
 * RosterImportService, and nothing here re-decides any of it.
 *
 * THE PREVIEW IS THE SAFETY MECHANISM, AND IT IS THE SERVER'S, NOT THIS FILE'S.
 * The preview response carries an encrypted receipt naming the digest of the
 * bytes it read; the commit posts the same file back with that receipt, and the
 * server refuses any pair that does not match. Disabling the Import button here
 * is a courtesy — the guarantee is that a commit without a preview of those
 * exact bytes is a 422 no matter what a client sends.
 *
 * The "what this will not do" sentences are printed from the server's `meta`
 * rather than written here, so the API and the screen cannot come to disagree
 * about what an import promises. They are always visible: an office that reads
 * "62 students imported" and nothing else will reasonably conclude the parents
 * are set up, and they are not — no consent is recorded and no family login is
 * enabled.
 *
 * THERE IS NO UNDO BUTTON. A draft of this screen had one, over an endpoint that
 * deleted the batch's contacts and swept their roster rows; those rows carry a
 * child's attendance, marks and report cards, and the tag stays on screen and
 * copyable for as long as the tab is open. The preview is this feature's safety
 * mechanism — read the rows before writing them — and a one-click reversal of a
 * bulk write over children's records is a bigger hazard than the mistake it
 * reverses. The server therefore has no such route, and the fourth caution says
 * so BEFORE the import rather than after it.
 */

interface PreviewStudent {
    line: number;
    lines: number[];
    class: string;
    name: string;
    grade: string | null;
    existing: boolean;
}

interface PreviewGuardian {
    line: number;
    email: string;
    name: string;
    existing: boolean;
}

interface PreviewTotals {
    students: { in_file: number; existing: number; to_create: number };
    guardians: { in_file: number; existing: number; to_create: number };
    edges: number;
    refused: number;
}

interface RosterPreview {
    rows_read: number;
    totals: PreviewTotals;
    students: PreviewStudent[];
    guardians: PreviewGuardian[];
    refused: { line: number; why: string }[];
    can_commit: boolean;
    receipt: string;
    expires_in_minutes: number;
}

interface ImportResult {
    batch: string;
    created: { students: number; guardians: number; memberships: number; edges: number };
    matched: { students: number; guardians: number };
}

/** The eight columns, exactly as RosterImportService::HEADERS states them. */
const HEADERS = [
    'class', 'student_first_name', 'student_last_name', 'grade',
    'guardian_first_name', 'guardian_last_name', 'guardian_email', 'guardian_phone',
];

const authStore = useAuthStore();
const masjidStore = useMasjidStore();

const fileInput = ref<HTMLInputElement | null>(null);
const file = ref<File | null>(null);
const preview = ref<RosterPreview | null>(null);
const result = ref<ImportResult | null>(null);
const loadError = ref('');
const tab = ref<'students' | 'guardians'>('students');
const copied = ref(false);

const previewing = ref(false);
const committing = ref(false);
const busy = computed(() => previewing.value || committing.value);

/**
 * The server's own per-line refusals from a REFUSED COMMIT, kept apart from
 * `loadError` so they can be listed rather than flattened into one line.
 */
const commitRefusals = ref<string[]>([]);

/**
 * Why the plan on screen can no longer be committed, when that is not something
 * `can_commit` said at preview time — the roster moved under it between the two
 * requests.
 */
const staleReason = ref('');

/** What this tenant calls a group — "Classroom", "Halaqa", "Team". */
const groupsTerm = computed<string>(() => masjidStore.term('groups'));

/**
 * The server's own sentences about what an import does not do. Defaulted here
 * only so the panel is never blank on a cold render; every real response
 * replaces all four.
 */
const cautions = ref({
    consent_note: 'This does not record consent for anything.',
    login_note: 'This does not give parents a login.',
    classes_note: 'This never creates a class.',
    undo_note: 'This cannot be undone from this screen.',
});

/**
 * WHEN THIS PREVIEW WAS READ, and a clock coarse enough to notice it lapsing.
 *
 * The receipt is good for `expires_in_minutes` and the server refuses a commit
 * past that. Until this existed the number was sent, typed and never rendered:
 * an admin who walked a sixty-row roster through with a colleague came back to a
 * green Import button that had gone stale in silence, and learned about the
 * thirty minutes from the refusal. The countdown is a courtesy — the guarantee
 * is the server's — so a fifteen-second tick is as precise as it needs to be.
 */
const previewedAt = ref<number | null>(null);
const clockNow = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => { clockNow.value = Date.now(); }, 15000);
});

onUnmounted(() => {
    if (clock) clearInterval(clock);
});

const previewMinutesLeft = computed<number | null>(() => {
    const ttl = preview.value?.expires_in_minutes;

    // No TTL in the payload means no countdown, never a NaN on screen and never
    // a button this file disables on its own guess.
    if (!ttl || previewedAt.value === null) return null;

    const lapsed = (clockNow.value - previewedAt.value) / 60000;

    return Math.max(0, Math.ceil(ttl - lapsed));
});

const previewExpired = computed<boolean>(
    () => previewMinutesLeft.value !== null && previewMinutesLeft.value <= 0
);

const masjidId = () => authStore.dashboardMasjidId ?? masjidStore.masjid?.id;

const base = () => `/api/admin/masjids/${masjidId()}/records/roster-import`;

/**
 * A new file invalidates everything read about the old one. Without this, an
 * office could preview file A, pick file B, and press an Import button that is
 * still showing A's counts — the server would refuse the mismatched receipt, but
 * the screen would have told a lie first.
 */
const onFileChosen = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    preview.value = null;
    previewedAt.value = null;
    result.value = null;
    loadError.value = '';
    commitRefusals.value = [];
    staleReason.value = '';
};

const startOver = (): void => {
    file.value = null;
    preview.value = null;
    previewedAt.value = null;
    result.value = null;
    loadError.value = '';
    commitRefusals.value = [];
    staleReason.value = '';
    if (fileInput.value) fileInput.value.value = '';
};

/**
 * The blank template, built here from the header constant rather than fetched.
 * A server route for eight fixed words would be a route to keep in step with
 * the reader; a constant that drifts shows up as a "missing column" refusal on
 * the very next preview, which is loud and harmless.
 */
const downloadTemplate = (): void => {
    const body = `${HEADERS.join(',')}\n`;
    const url = URL.createObjectURL(new Blob([body], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'roster-template.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
};

const runPreview = async (): Promise<void> => {
    if (!file.value || !masjidId()) return;

    previewing.value = true;
    loadError.value = '';
    commitRefusals.value = [];
    staleReason.value = '';
    result.value = null;

    try {
        const body = new FormData();
        body.append('file', file.value);

        const res = await ApiService.post(`${base()}/preview` as any, body);
        preview.value = res.data.data as RosterPreview;
        previewedAt.value = Date.now();
        clockNow.value = Date.now();
        if (res.data.meta) cautions.value = res.data.meta;
        tab.value = 'students';
    } catch (error) {
        preview.value = null;
        previewedAt.value = null;
        loadError.value = apiErrorText(error, 'Could not read that file.');
    } finally {
        previewing.value = false;
    }
};

/**
 * The structured refusal bag from a refused commit, or null if this failure was
 * not one.
 *
 * `commit()` ships `data.refused` as an ARRAY of per-line sentences so the
 * screen can put them back in front of the office without a second round trip.
 * `apiErrorText` joins every value in the bag with a single space, which turns
 * that array into one unbroken paragraph — readable for two rows, useless for
 * twenty, and the one thing needed to fix the file.
 */
const refusedLines = (error: unknown): { headline: string; refused: string[] } | null => {
    const bag = (error as { response?: { data?: { data?: unknown } } })?.response?.data?.data;

    if (!bag || typeof bag !== 'object') return null;

    const shaped = bag as { file?: unknown; refused?: unknown };
    const refused = Array.isArray(shaped.refused) ? shaped.refused.map((line) => String(line)) : [];

    if (refused.length === 0) return null;

    return {
        headline: Array.isArray(shaped.file) && shaped.file.length ? String(shaped.file[0]) : '',
        refused,
    };
};

const commit = async (): Promise<void> => {
    if (!file.value || !preview.value || !preview.value.can_commit || previewExpired.value) return;

    committing.value = true;
    loadError.value = '';
    commitRefusals.value = [];
    staleReason.value = '';

    try {
        const body = new FormData();
        body.append('file', file.value);
        // The receipt the preview issued for THESE bytes. The server re-digests
        // the upload against it, so a file swapped between the two requests is
        // refused rather than silently imported.
        body.append('receipt', preview.value.receipt);

        const res = await ApiService.post(base() as any, body);
        result.value = res.data.data as ImportResult;
        if (res.data.meta) cautions.value = res.data.meta;
        preview.value = null;
        previewedAt.value = null;
    } catch (error) {
        // THE PLAN ON SCREEN IS NO LONGER TRUE, so it stops being commitable.
        // The refusal means the server re-planned these bytes and got a
        // different answer — a class renamed by a colleague in the minutes since
        // the preview, say. Leaving `can_commit` alone left a red alert sitting
        // directly above a table saying every row was fine and a live green
        // button that produced the identical 422 on every press.
        if (preview.value) preview.value.can_commit = false;

        const refusal = refusedLines(error);

        if (refusal) {
            commitRefusals.value = refusal.refused;
            loadError.value = refusal.headline || 'The import was refused and nothing was written.';
            staleReason.value = 'Preview the file again — the roster has changed since you read it.';
        } else {
            loadError.value = apiErrorText(error, 'The import was refused and nothing was written.');
            staleReason.value = 'Preview the file again before importing it.';
        }
    } finally {
        committing.value = false;
    }
};

const copyBatch = async (): Promise<void> => {
    if (!result.value) return;
    try {
        await navigator.clipboard.writeText(result.value.batch);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        // A clipboard the browser will not open is not worth an error dialog —
        // the tag is on screen and selectable.
    }
};
</script>

<style scoped>
.roster-import code {
    background: #f1f3f5;
    padding: 0.2rem 0.5rem;
    border-radius: 0.25rem;
}

/* Sixty rows must be scrollable without pushing the Import button off screen. */
.roster-scroll {
    max-height: 24rem;
    overflow-y: auto;
}
</style>
