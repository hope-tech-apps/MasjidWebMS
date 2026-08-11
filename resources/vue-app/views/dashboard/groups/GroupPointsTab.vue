<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-muted">Points</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" @click="openSkillModal">
                    <i class="bi bi-tags me-1"></i> Manage skills
                </button>
                <button class="btn btn-sm btn-success" :disabled="!participants.length" @click="openAwardModal">
                    <i class="bi bi-star me-1"></i> Give points
                </button>
            </div>
        </div>

        <!--
            WHY THERE IS NO LEADERBOARD ON THIS SCREEN, AND MUST NOT BE ONE.

            A child's behaviour record is disclosed to the group's leaders, to the
            student, and to THAT student's own guardians. Never to another guardian
            in the same group, never to the whole tenant, and never as a class-wide
            ranking. Public point tallies are the loudest documented harm of the
            product this module answers, and being structurally incapable of one is
            the differentiator — not a setting.

            Concretely, for anyone tempted to "just add a top-students card":
              - the API serves no class-wide endpoint, no rank field and no
                comparison payload. Every aggregate is per student;
              - the listing query is audience-constrained server-side, so a
                forbidden award is never fetched — a ranking assembled in the
                browser would therefore be wrong AS WELL AS harmful, silently
                omitting whichever children the caller may not see;
              - looping the per-student summary over the roster to sort it is the
                same feature wearing a different hat. Do not.

            The two panels below are the sanctioned shapes: a per-student total a
            leader is already entitled to, and a chronological log. Neither orders
            students by score. See .claude/rules/groups.md.
        -->

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <GroupForbiddenNotice v-else-if="forbidden" what="Behaviour records" />

        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadAll">Retry</button>
        </div>

        <div v-else>
            <!-- Per-student totals, in ROSTER order. Never sorted by points. -->
            <h6 class="text-muted mb-2">Each student's own total</h6>
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th class="text-center">Awards</th>
                            <th class="text-center">Positive</th>
                            <th class="text-center">Needs work</th>
                            <th class="text-center">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="participant in participants" :key="participant.id">
                            <td class="fw-semibold">{{ fullName(participant) }}</td>
                            <td class="text-center">{{ summaryFor(participant.id)?.totals.awards ?? 0 }}</td>
                            <td class="text-center text-success">
                                {{ summaryFor(participant.id)?.by_polarity.positive.points ?? 0 }}
                            </td>
                            <td class="text-center text-danger">
                                {{ summaryFor(participant.id)?.by_polarity.negative.points ?? 0 }}
                            </td>
                            <td class="text-center fw-semibold">{{ summaryFor(participant.id)?.totals.points ?? 0 }}</td>
                        </tr>
                        <tr v-if="!participants.length">
                            <td colspan="5" class="text-center text-muted py-3">No students on this roster yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- The log, newest first. -->
            <h6 class="text-muted mb-2">Recent awards</h6>
            <div v-if="awards.length === 0" class="text-center py-4 text-muted">
                <i class="bi bi-star fs-3 d-block mb-2"></i>
                <p class="mb-0">Nothing has been recorded yet</p>
            </div>
            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Skill</th>
                            <th class="text-center">Points</th>
                            <th>When</th>
                            <th>By</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="award in awards" :key="award.id">
                            <td class="fw-semibold">{{ studentName(award) }}</td>
                            <td>
                                <span
                                    class="badge"
                                    :class="award.polarity === 'positive' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'"
                                >
                                    {{ award.skill_label }}
                                </span>
                                <div v-if="award.note" class="small text-muted">{{ award.note }}</div>
                            </td>
                            <td class="text-center">{{ award.points }}</td>
                            <td class="small text-muted">{{ formatDate(award.awarded_at) }}</td>
                            <td class="small text-muted">{{ award.awarded_by?.name || '—' }}</td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger" @click="confirmRevoke(award)" title="Revoke">
                                    <i class="bi bi-arrow-counterclockwise"></i>
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

        <!-- Give points -->
        <Teleport to="body">
            <div v-if="showAwardModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showAwardModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-star me-2"></i> Give points</h5>
                            <button type="button" class="btn-close" @click="showAwardModal = false"></button>
                        </div>
                        <form @submit.prevent="submitAward">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Student <span class="text-danger">*</span></label>
                                    <select class="form-select" v-model="awardForm.membership_id">
                                        <option :value="0" disabled>Choose a student…</option>
                                        <option v-for="participant in participants" :key="participant.id" :value="participant.id">
                                            {{ fullName(participant) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Skill <span class="text-danger">*</span></label>
                                    <select class="form-select" v-model="awardForm.behavior_skill_id" @change="applyDefaultPoints">
                                        <option :value="0" disabled>Choose a skill…</option>
                                        <option v-for="skill in activeSkills" :key="skill.id" :value="skill.id">
                                            {{ skill.label }} ({{ skill.polarity === 'positive' ? '+' : '−' }}{{ Math.abs(skill.default_points) }})
                                        </option>
                                    </select>
                                    <div v-if="!activeSkills.length" class="form-text text-warning">
                                        No skills defined yet — add one first with "Manage skills".
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Points</label>
                                    <input type="number" class="form-control" v-model.number="pointsOverride" :max="maxPoints" :min="-maxPoints">
                                    <div class="form-text">Leave as-is to use the skill's own value.</div>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label">Note</label>
                                    <textarea class="form-control" rows="2" v-model.trim="awardForm.note"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="showAwardModal = false" :disabled="awarding">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="awarding || !canAward">
                                    <span v-if="awarding" class="spinner-border spinner-border-sm me-1"></span>
                                    Give
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Manage skills -->
        <Teleport to="body">
            <div v-if="showSkillModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showSkillModal = false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-tags me-2"></i> Behaviour skills</h5>
                            <button type="button" class="btn-close" @click="showSkillModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">
                                What this organization chooses to notice. Manara ships no fixed list &mdash; the
                                vocabulary is yours, and nothing here is limited by a plan.
                            </p>

                            <div class="table-responsive mb-3" style="max-height:32vh; overflow-y:auto;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Label</th><th>Kind</th><th class="text-center">Default</th><th class="text-center">Active</th></tr></thead>
                                    <tbody>
                                        <tr v-for="skill in behaviorStore.skills" :key="skill.id">
                                            <td>{{ skill.label }}</td>
                                            <td class="text-capitalize">{{ skill.polarity }}</td>
                                            <td class="text-center">{{ skill.default_points }}</td>
                                            <td class="text-center">
                                                <i v-if="skill.is_active" class="bi bi-check-circle text-success"></i>
                                                <i v-else class="bi bi-dash-circle text-muted"></i>
                                            </td>
                                        </tr>
                                        <tr v-if="!behaviorStore.skills.length">
                                            <td colspan="4" class="text-center text-muted py-3">No skills defined yet</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <form @submit.prevent="submitSkill" class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label small">New skill</label>
                                    <input class="form-control" v-model.trim="skillForm.label" placeholder="Participation">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Kind</label>
                                    <select class="form-select text-capitalize" v-model="skillForm.polarity">
                                        <option v-for="polarity in polarities" :key="polarity" :value="polarity">{{ polarity }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Points</label>
                                    <input type="number" class="form-control" v-model.number="skillForm.default_points" :max="maxPoints" :min="-maxPoints">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-success w-100" :disabled="savingSkill || !skillForm.label">
                                        <span v-if="savingSkill" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>Add</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="showSkillModal = false">Close</button>
                        </div>
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
    BehaviorAward,
    BehaviorAwardPayload,
    BehaviorAwardSummary,
    BehaviorPolarity,
    BehaviorSkill,
    BehaviorSkillPayload
} from '@/core/types/data/masjid-related/Behavior';
import { useBehaviorStore } from '@/stores/masjid/behaviorStore';
import { apiErrorText, isForbidden } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * Behaviour points for one group.
 *
 * See the long comment in the template for the leaderboard decision — it lives
 * there deliberately, next to the markup a future contributor would edit.
 */

const props = defineProps<{
    groupId: number;
    memberships: GroupMembership[];
}>();

// Stores
const behaviorStore = useBehaviorStore();

// State
const loading = ref(false);
const forbidden = ref(false);
const loadError = ref('');
const showAwardModal = ref(false);
const showSkillModal = ref(false);
const awarding = ref(false);
const savingSkill = ref(false);
/** Null means "use the skill's own value" — distinct from an explicit 0. */
const pointsOverride = ref<number | null>(null);

const emptyAwardForm = (): BehaviorAwardPayload => ({
    membership_id: 0, behavior_skill_id: 0, points: null, note: ''
});
const awardForm = ref<BehaviorAwardPayload>(emptyAwardForm());

const emptySkillForm = (): BehaviorSkillPayload => ({
    label: '', polarity: 'positive', default_points: 1, is_active: true
});
const skillForm = ref<BehaviorSkillPayload>(emptySkillForm());

// Computed
/** Points are given to a PARTICIPANT: a guardian edge names a relationship, not someone to recognise. */
const participants = computed<GroupMembership[]>(
    () => props.memberships.filter((m) => m.role !== 'guardian')
);

const awards = computed<BehaviorAward[]>(() => (behaviorStore.awardsPaginated?.data as BehaviorAward[]) || []);

const activeSkills = computed<BehaviorSkill[]>(() => behaviorStore.skills.filter((skill) => skill.is_active));

const polarities = computed<BehaviorPolarity[]>(() => behaviorStore.behaviorMeta?.polarities ?? ['positive', 'negative']);

const maxPoints = computed<number>(() => behaviorStore.behaviorMeta?.max_points || 100);

const paginationOptions = computed<PaginationOptions | undefined>(() => {
    if (!behaviorStore.awardsPaginated) return undefined;
    return {
        currentPage: behaviorStore.awardsPaginated.current_page,
        itemsTotal: behaviorStore.awardsPaginated.total,
        perPage: behaviorStore.awardsPaginated.per_page
    };
});

const canAward = computed<boolean>(
    () => awardForm.value.membership_id > 0 && awardForm.value.behavior_skill_id > 0
);

// Lifecycle
onBeforeMount(async () => {
    await loadAll();
});

// Methods
const fullName = (membership: GroupMembership): string =>
    membership.contact ? `${membership.contact.first_name} ${membership.contact.last_name}`.trim() : '—';

const studentName = (award: BehaviorAward): string => {
    const contact = award.student?.contact;
    return contact ? `${contact.first_name} ${contact.last_name}`.trim() : '—';
};

const summaryFor = (membershipId: number): BehaviorAwardSummary | undefined =>
    behaviorStore.summaries[membershipId];

const loadAll = async () => {
    loading.value = true;
    loadError.value = '';
    forbidden.value = false;
    try {
        await behaviorStore.fetchAwards(props.groupId, 1);
        await loadSummaries();
    } catch (error) {
        if (isForbidden(error)) {
            forbidden.value = true;
        } else {
            loadError.value = apiErrorText(error, 'Failed to load the behaviour records.');
        }
    } finally {
        loading.value = false;
    }

    // The vocabulary is tenant-level and never audience-gated, so it loads even
    // when the awards above were refused — the "give points" path still works.
    try {
        await behaviorStore.fetchSkills();
    } catch (error) {
        // A missing vocabulary shows as an empty picker with its own hint.
    }
};

/**
 * One per-student summary per roster row.
 *
 * This is NOT a leaderboard fetch: each call returns totals the caller is
 * already entitled to for that one student, the rows render in ROSTER order, and
 * nothing sorts or ranks them. A failure on one student is swallowed so a single
 * refusal does not blank the table.
 */
const loadSummaries = async () => {
    await Promise.all(participants.value.map(async (participant) => {
        try {
            await behaviorStore.fetchSummary(props.groupId, participant.id);
        } catch (error) {
            // That student's cell falls back to zero; the log below is unaffected.
        }
    }));
};

const pageChange = async (data: PageChangeData) => {
    if (data.toPage === (paginationOptions.value?.currentPage ?? 1)) return;
    try {
        await behaviorStore.fetchAwards(props.groupId, data.toPage);
    } catch (error) {
        loadError.value = apiErrorText(error, 'Failed to load the behaviour records.');
    }
};

const openAwardModal = () => {
    awardForm.value = emptyAwardForm();
    pointsOverride.value = null;
    showAwardModal.value = true;
};

const openSkillModal = () => {
    skillForm.value = emptySkillForm();
    showSkillModal.value = true;
};

/** Pre-fill the points box from the chosen skill so a teacher usually just taps "Give". */
const applyDefaultPoints = () => {
    const skill = behaviorStore.skills.find((s) => s.id === awardForm.value.behavior_skill_id);
    pointsOverride.value = skill ? skill.default_points : null;
};

const submitAward = async () => {
    if (!canAward.value) return;
    awarding.value = true;
    try {
        await behaviorStore.awardSkill(props.groupId, {
            ...awardForm.value,
            points: pointsOverride.value
        });
        showAwardModal.value = false;
        await loadAll();
        Swal.fire({ icon: 'success', title: 'Recorded', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to record the award.') });
    } finally {
        awarding.value = false;
    }
};

const submitSkill = async () => {
    if (!skillForm.value.label) return;
    savingSkill.value = true;
    try {
        await behaviorStore.createSkill(skillForm.value);
        skillForm.value = emptySkillForm();
        await behaviorStore.fetchSkills();
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to create the skill.') });
    } finally {
        savingSkill.value = false;
    }
};

const confirmRevoke = async (award: BehaviorAward) => {
    const result = await Swal.fire({
        title: 'Revoke this award?',
        text: 'It leaves every listing and every total at once, and the correction is recorded against your account.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, revoke'
    });

    if (!result.isConfirmed) return;

    try {
        await behaviorStore.revokeAward(props.groupId, award.id);
        await loadAll();
        Swal.fire({ icon: 'success', title: 'Revoked', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to revoke the award.') });
    }
};

const formatDate = (iso: string | null): string => {
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime()) ? iso : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

// Lock body scroll while a modal is open
watch([showAwardModal, showSkillModal], ([award, skill]) => {
    document.body.style.overflow = (award || skill) ? 'hidden' : '';
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
