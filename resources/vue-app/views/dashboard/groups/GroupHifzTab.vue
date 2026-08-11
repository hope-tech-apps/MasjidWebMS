<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-muted">Qur'an memorization</h6>
            <button class="btn btn-sm btn-success" :disabled="!participants.length" @click="openRecordModal">
                <i class="bi bi-mic me-1"></i> Record recitation
            </button>
        </div>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <GroupForbiddenNotice v-else-if="forbidden" what="Memorization records" />

        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadAll">Retry</button>
        </div>

        <div v-else>
            <!--
                Where each student IS.

                PROGRESS IS A POSITION, NEVER A PERCENTAGE — surah, ayah and juz,
                which is what every halaqa and every ijaza actually recognises.
                "Ayahs" is shown against the mushaf's own total so the number reads
                honestly without being divided into a score.

                Current and furthest are BOTH shown because they are different
                facts: a student sent back over earlier material is at that lesson
                today, and showing only the high-water mark would tell a parent
                their child is somewhere they are not. And only SABAK moves either
                one — a manzil entry over al-Baqarah is revision, not regression.
            -->
            <h6 class="text-muted mb-2">Where each student is</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Current lesson</th>
                            <th>Furthest reached</th>
                            <th class="text-center">Ayahs</th>
                            <th class="text-center">Juz complete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="participant in participants" :key="participant.id">
                            <td class="fw-semibold">{{ fullName(participant) }}</td>
                            <td>{{ positionLabel(progressFor(participant.id)?.current_position ?? null) }}</td>
                            <td class="text-muted">{{ positionLabel(progressFor(participant.id)?.furthest_position ?? null) }}</td>
                            <td class="text-center">
                                <span v-if="progressFor(participant.id)">
                                    {{ progressFor(participant.id)?.memorized.ayahs }}
                                    <span class="text-muted small">of {{ progressFor(participant.id)?.memorized.total_ayahs }}</span>
                                </span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="text-center">{{ progressFor(participant.id)?.memorized.juz_completed ?? 0 }}</td>
                        </tr>
                        <tr v-if="!participants.length">
                            <td colspan="5" class="text-center text-muted py-3">No students on this roster yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- The recitation log. -->
            <h6 class="text-muted mb-2">Recent recitations</h6>
            <div v-if="entries.length === 0" class="text-center py-4 text-muted">
                <i class="bi bi-book fs-3 d-block mb-2"></i>
                <p class="mb-0">Nothing has been recorded yet</p>
            </div>
            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Kind</th>
                            <th>Portion</th>
                            <th class="text-center">Mistakes</th>
                            <th>Quality</th>
                            <th>When</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries" :key="entry.id">
                            <td class="fw-semibold">{{ studentName(entry) }}</td>
                            <td>
                                <!--
                                    The classical names are kept as-is: they are
                                    what every hifz teacher already says. The
                                    tooltip explains rather than renaming them.
                                -->
                                <span class="badge bg-light text-dark border text-capitalize" :title="kindHint(entry.kind)">
                                    {{ entry.kind }}
                                </span>
                            </td>
                            <td>{{ rangeLabel(entry) }}</td>
                            <td class="text-center">
                                <span class="text-danger">{{ entry.major_mistakes }}</span>
                                <span class="text-muted"> / </span>
                                <span class="text-warning">{{ entry.minor_mistakes }}</span>
                            </td>
                            <td class="text-capitalize">{{ entry.quality }}</td>
                            <td class="small text-muted">{{ formatDate(entry.recited_at) }}</td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger" @click="confirmStrike(entry)" title="Strike">
                                    <i class="bi bi-eraser"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="d-flex justify-content-center">
                    <Pagination v-if="paginationOptions" :options="paginationOptions" @page-change="pageChange" />
                </div>
            </div>
        </div>

        <!-- Record a recitation -->
        <Teleport to="body">
            <div v-if="showRecordModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showRecordModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-mic me-2"></i> Record recitation</h5>
                            <button type="button" class="btn-close" @click="showRecordModal = false"></button>
                        </div>
                        <form @submit.prevent="submitEntry">
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="form-label">Student <span class="text-danger">*</span></label>
                                        <select class="form-select" v-model="entryForm.membership_id">
                                            <option :value="0" disabled>Choose a student…</option>
                                            <option v-for="participant in participants" :key="participant.id" :value="participant.id">
                                                {{ fullName(participant) }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Kind <span class="text-danger">*</span></label>
                                        <select class="form-select text-capitalize" v-model="entryForm.kind">
                                            <option v-for="kind in kinds" :key="kind" :value="kind">{{ kind }}</option>
                                        </select>
                                        <div class="form-text">{{ kindHint(entryForm.kind) }}</div>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">From surah</label>
                                        <input type="number" class="form-control" v-model.number="entryForm.from_surah" min="1" :max="surahCount">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">From ayah</label>
                                        <input type="number" class="form-control" v-model.number="entryForm.from_ayah" min="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">To surah</label>
                                        <input type="number" class="form-control" v-model.number="entryForm.to_surah" min="1" :max="surahCount">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">To ayah</label>
                                        <input type="number" class="form-control" v-model.number="entryForm.to_ayah" min="1">
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label">Quality</label>
                                        <select class="form-select text-capitalize" v-model="entryForm.quality">
                                            <option v-for="quality in qualities" :key="quality" :value="quality">{{ quality }}</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Major mistakes</label>
                                        <input type="number" class="form-control" v-model.number="entryForm.major_mistakes" min="0" :max="maxMistakes">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Minor mistakes</label>
                                        <input type="number" class="form-control" v-model.number="entryForm.minor_mistakes" min="0" :max="maxMistakes">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Note</label>
                                        <textarea class="form-control" rows="2" v-model.trim="entryForm.note"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="showRecordModal = false" :disabled="recording">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="recording || !canRecord">
                                    <span v-if="recording" class="spinner-border spinner-border-sm me-1"></span>
                                    Record
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onBeforeMount, watch } from 'vue';
import Pagination from '@/components/partials/Pagination.vue';
import GroupForbiddenNotice from './GroupForbiddenNotice.vue';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import { GroupMembership } from '@/core/types/data/masjid-related/Group';
import {
    HifzEntry,
    HifzEntryPayload,
    HifzKind,
    HifzPosition,
    HifzProgress,
    HifzQuality
} from '@/core/types/data/masjid-related/Hifz';
import { useHifzStore } from '@/stores/masjid/hifzStore';
import { apiErrorText, isForbidden } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * Hifz tracking for one halaqa.
 *
 * The two domain rules this screen must never break:
 *
 * 1. ONLY SABAK ADVANCES A STUDENT. Nothing here treats a sabqi or manzil entry
 *    as progress — the position columns come straight from the server's
 *    derivation, which filters to sabak before deriving anything.
 * 2. PROGRESS IS A POSITION, NEVER A PERCENTAGE. There is no division anywhere in
 *    this file. `memorized.ayahs` is shown "231 of 6236", which is a count
 *    against the mushaf, not a score.
 *
 * There is also deliberately no class-wide progress panel and no "top
 * memorisers" ordering: the same privacy rule as behaviour points, through the
 * same server-side code. See .claude/rules/groups.md.
 */

const props = defineProps<{
    groupId: number;
    memberships: GroupMembership[];
}>();

// Stores
const hifzStore = useHifzStore();

// State
const loading = ref(false);
const forbidden = ref(false);
const loadError = ref('');
const showRecordModal = ref(false);
const recording = ref(false);

const emptyEntryForm = (): HifzEntryPayload => ({
    membership_id: 0,
    kind: 'sabak',
    from_surah: 1,
    from_ayah: 1,
    to_surah: 1,
    to_ayah: 7,
    quality: 'good',
    major_mistakes: 0,
    minor_mistakes: 0,
    note: ''
});
const entryForm = ref<HifzEntryPayload>(emptyEntryForm());

// Computed
/** A recitation is heard from a PARTICIPANT; a guardian edge names a relationship. */
const participants = computed<GroupMembership[]>(
    () => props.memberships.filter((m) => m.role !== 'guardian')
);

const entries = computed<HifzEntry[]>(() => (hifzStore.entriesPaginated?.data as HifzEntry[]) || []);

const kinds = computed<HifzKind[]>(() => hifzStore.hifzMeta?.kinds ?? ['sabak', 'sabqi', 'manzil']);
const qualities = computed<HifzQuality[]>(
    () => hifzStore.hifzMeta?.qualities ?? ['excellent', 'good', 'fair', 'repeat']
);
const surahCount = computed<number>(() => hifzStore.hifzMeta?.surah_count || 114);
const maxMistakes = computed<number>(() => hifzStore.hifzMeta?.max_mistakes || 100);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!hifzStore.entriesPaginated) return undefined;
    return {
        currentPage: hifzStore.entriesPaginated.current_page,
        itemsTotal: hifzStore.entriesPaginated.total,
        perPage: hifzStore.entriesPaginated.per_page
    };
});

const canRecord = computed<boolean>(() => entryForm.value.membership_id > 0);

// Lifecycle
onBeforeMount(async () => {
    await loadAll();
});

// Methods
const fullName = (membership: GroupMembership): string =>
    membership.contact ? `${membership.contact.first_name} ${membership.contact.last_name}`.trim() : '—';

const studentName = (entry: HifzEntry): string => {
    const contact = entry.student?.contact;
    return contact ? `${contact.first_name} ${contact.last_name}`.trim() : '—';
};

const progressFor = (membershipId: number): HifzProgress | undefined =>
    hifzStore.progressByMembership[membershipId];

/** "An-Naba 1, juz 30" — surah name, ayah and derived juz, exactly as served. */
const positionLabel = (position: HifzPosition | null): string => {
    if (!position) return '—';
    return `${position.surah_name} ${position.ayah}, juz ${position.juz}`;
};

const rangeLabel = (entry: HifzEntry): string => {
    if (!entry.from || !entry.to) return '—';
    if (entry.from.surah === entry.to.surah) {
        return `${entry.from.surah_name} ${entry.from.ayah}–${entry.to.ayah}`;
    }
    return `${entry.from.surah_name} ${entry.from.ayah} – ${entry.to.surah_name} ${entry.to.ayah}`;
};

/** Explain the classical cycle without renaming it — what a UI LABELS them is presentation. */
const kindHint = (kind: HifzKind): string => {
    if (kind === 'sabak') return 'The new lesson memorised today — the only kind that advances a student.';
    if (kind === 'sabqi') return 'Recent memorisation under active revision.';
    return 'Older, consolidated memorisation on the long rotation.';
};

const loadAll = async () => {
    loading.value = true;
    loadError.value = '';
    forbidden.value = false;
    try {
        await hifzStore.fetchEntries(props.groupId, 1);
        await loadProgress();
    } catch (error) {
        if (isForbidden(error)) {
            forbidden.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load the memorization records.');
        }
    } finally {
        loading.value = false;
    }
};

/**
 * One per-student position per roster row.
 *
 * Per student, in roster order — not a ranking, and nothing here sorts by how
 * much anybody has memorised. A refusal on one student is swallowed so a single
 * one does not blank the table.
 */
const loadProgress = async () => {
    await Promise.all(participants.value.map(async (participant) => {
        try {
            await hifzStore.fetchProgress(props.groupId, participant.id);
        } catch (error) {
            // That student's row falls back to "—".
        }
    }));
};

const pageChange = async (data: PageChangeData) => {
    if (data.toPage === (paginationOptions.value?.currentPage ?? 1)) return;
    try {
        await hifzStore.fetchEntries(props.groupId, data.toPage);
    } catch (error) {
        loadError.value = apiErrorText(error, 'Failed to load the memorization records.');
    }
};

const openRecordModal = () => {
    entryForm.value = emptyEntryForm();
    showRecordModal.value = true;
};

const submitEntry = async () => {
    if (!canRecord.value) return;
    recording.value = true;
    try {
        await hifzStore.recordEntry(props.groupId, entryForm.value);
        showRecordModal.value = false;
        await loadAll();
        Swal.fire({ icon: 'success', title: 'Recorded', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to record the recitation.') });
    } finally {
        recording.value = false;
    }
};

/**
 * Striking is the ONLY correction there is: there is no update endpoint, because
 * an in-place edit would quietly rewrite what a teacher said they heard. And
 * because the position is derived, striking a mis-recorded SABAK genuinely moves
 * the student back — which the confirmation says out loud.
 */
const confirmStrike = async (entry: HifzEntry) => {
    const result = await Swal.fire({
        title: 'Strike this entry?',
        text: entry.kind === 'sabak'
            ? "This was a new lesson, so striking it moves the student's recorded position back. The correction is recorded against your account."
            : 'It leaves every listing and every total at once. The correction is recorded against your account.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, strike'
    });

    if (!result.isConfirmed) return;

    try {
        await hifzStore.strikeEntry(props.groupId, entry.id);
        await loadAll();
        Swal.fire({ icon: 'success', title: 'Struck', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to strike the entry.') });
    }
};

const formatDate = (iso: string | null): string => {
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime()) ? iso : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

// Lock body scroll while the modal is open
watch(showRecordModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});
</script>

<style scoped>
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
