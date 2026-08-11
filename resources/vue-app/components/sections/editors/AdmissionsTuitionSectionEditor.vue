<template>
    <div class="admissions-tuition-editor">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    placeholder="e.g., Tuition &amp; Fee Schedule"
                />
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">School Year</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.school_year"
                    @input="emitUpdate"
                    placeholder="e.g., 2026-2027 School Year"
                />
                <div class="form-text">Shown as a small label above the heading.</div>
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Description</label>
                <textarea
                    class="form-control"
                    v-model="localContent.description"
                    @input="emitUpdate"
                    rows="2"
                    placeholder="Optional text shown under the heading"
                ></textarea>
            </div>

            <!-- Tuition tiers -->
            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Tuition</h6>
                    <button type="button" class="btn btn-sm btn-primary" @click="addTier">
                        <i class="bi bi-plus-circle"></i> Add Rate
                    </button>
                </div>

                <div class="alert alert-info py-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Amounts are written exactly as they should appear
                    &mdash; <code>$8,000</code>, <code>Included</code> or
                    <code>Contact us</code> are all fine. Nothing is charged from
                    this page; it is the published price list.
                </div>

                <div
                    v-for="(tier, index) in localContent.tiers"
                    :key="index"
                    class="card mb-3"
                >
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">{{ tier.name || `Rate ${index + 1}` }}</h6>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveTier(index, -1)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveTier(index, 1)"
                                    :disabled="index === localContent.tiers.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeTier(index)"
                                    title="Remove Rate"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="tier.name"
                                    @input="emitUpdate"
                                    placeholder="e.g., Kindergarten - 2nd Grade"
                                    required
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Badge</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="tier.badge"
                                    @input="emitUpdate"
                                    placeholder="e.g., Full-Time School"
                                />
                                <div class="form-text">Small pill above the name.</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="tier.amount"
                                    @input="emitUpdate"
                                    placeholder="e.g., $8,000"
                                    required
                                />
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Per</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="tier.period"
                                    @input="emitUpdate"
                                    placeholder="e.g., /year"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <label class="form-label">Note</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="tier.note"
                                    @input="emitUpdate"
                                    placeholder="e.g., Sibling discount applies from the second child"
                                />
                            </div>

                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">What's Included</label>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="addInclude(index)"
                                    >
                                        <i class="bi bi-plus-circle"></i> Add Point
                                    </button>
                                </div>

                                <div
                                    v-for="(include, iIndex) in tier.includes"
                                    :key="iIndex"
                                    class="input-group mb-2"
                                >
                                    <input
                                        type="text"
                                        class="form-control"
                                        :value="include"
                                        @input="onIncludeInput(index, iIndex, $event)"
                                        placeholder="e.g., Monday - Friday, 8:00 AM - 3:00 PM"
                                    />
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        @click="removeInclude(index, iIndex)"
                                        title="Remove Point"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.tiers.length === 0" class="alert alert-warning">
                    No rates added yet. Click "Add Rate" to publish your tuition.
                </div>
            </div>

            <!-- Additional fees -->
            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Additional Fees</h6>
                    <button type="button" class="btn btn-sm btn-primary" @click="addFee">
                        <i class="bi bi-plus-circle"></i> Add Fee
                    </button>
                </div>

                <div
                    v-for="(fee, index) in localContent.fees"
                    :key="index"
                    class="row g-2 align-items-start mb-2"
                >
                    <div class="col-md-4">
                        <input
                            type="text"
                            class="form-control"
                            v-model="fee.label"
                            @input="emitUpdate"
                            placeholder="e.g., Registration Fee"
                        />
                    </div>
                    <div class="col-md-3">
                        <input
                            type="text"
                            class="form-control"
                            v-model="fee.amount"
                            @input="emitUpdate"
                            placeholder="e.g., $200"
                        />
                    </div>
                    <div class="col-md-4">
                        <input
                            type="text"
                            class="form-control"
                            v-model="fee.note"
                            @input="emitUpdate"
                            placeholder="e.g., non-refundable"
                        />
                    </div>
                    <div class="col-md-1 d-grid">
                        <button
                            type="button"
                            class="btn btn-outline-danger"
                            @click="removeFee(index)"
                            title="Remove Fee"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <div v-if="localContent.fees.length === 0" class="form-text">
                    Registration, books and materials, late payment &mdash; anything
                    charged on top of tuition.
                </div>
            </div>

            <!-- Payment plans -->
            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Payment Plans</h6>
                    <button type="button" class="btn btn-sm btn-primary" @click="addPlan">
                        <i class="bi bi-plus-circle"></i> Add Plan
                    </button>
                </div>

                <div
                    v-for="(plan, index) in localContent.payment_plans"
                    :key="index"
                    class="row g-2 align-items-start mb-2"
                >
                    <div class="col-md-3">
                        <input
                            type="text"
                            class="form-control"
                            v-model="plan.label"
                            @input="emitUpdate"
                            placeholder="e.g., Annual"
                        />
                    </div>
                    <div class="col-md-8">
                        <input
                            type="text"
                            class="form-control"
                            v-model="plan.detail"
                            @input="emitUpdate"
                            placeholder="e.g., Full tuition due Aug 1st ($250 discount per child)"
                        />
                    </div>
                    <div class="col-md-1 d-grid">
                        <button
                            type="button"
                            class="btn btn-outline-danger"
                            @click="removePlan(index)"
                            title="Remove Plan"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <div v-if="localContent.payment_plans.length === 0" class="form-text">
                    How families may pay &mdash; annual, monthly, and any processing fee.
                </div>
            </div>

            <!-- Enrollment steps -->
            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">How to Enrol</h6>
                    <button type="button" class="btn btn-sm btn-primary" @click="addStep">
                        <i class="bi bi-plus-circle"></i> Add Step
                    </button>
                </div>

                <div
                    v-for="(step, index) in localContent.steps"
                    :key="index"
                    class="card mb-2"
                >
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-secondary">Step {{ index + 1 }}</span>
                            <div class="btn-group">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveStep(index, -1)"
                                    :disabled="index === 0"
                                    title="Move Up"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="moveStep(index, 1)"
                                    :disabled="index === localContent.steps.length - 1"
                                    title="Move Down"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    @click="removeStep(index)"
                                    title="Remove Step"
                                >
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-2">
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="step.title"
                                    @input="emitUpdate"
                                    placeholder="e.g., Submit the application"
                                />
                            </div>
                            <div class="col-md-8 mb-2">
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="step.description"
                                    @input="emitUpdate"
                                    placeholder="What the family has to do at this step"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="localContent.steps.length === 0" class="form-text">
                    The ordered list a family follows to enrol.
                </div>
            </div>

            <!-- Apply button -->
            <div class="col-12 mb-3">
                <h6 class="mb-2">Apply Button</h6>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Button Text</label>
                        <input
                            type="text"
                            class="form-control"
                            v-model="localContent.button_text"
                            @input="emitUpdate"
                            placeholder="e.g., Start an Application"
                        />
                        <div class="form-text">Leave blank to hide the button.</div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label d-block">Button Goes To</label>
                        <div class="btn-group w-100 mb-2" role="group">
                            <input
                                type="radio"
                                class="btn-check"
                                name="admissionsButtonLinkType"
                                id="admissionsLinkTypePage"
                                value="page"
                                v-model="buttonLinkType"
                                @change="onLinkTypeChange"
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary" for="admissionsLinkTypePage">
                                Internal Page
                            </label>

                            <input
                                type="radio"
                                class="btn-check"
                                name="admissionsButtonLinkType"
                                id="admissionsLinkTypeExternal"
                                value="external"
                                v-model="buttonLinkType"
                                @change="onLinkTypeChange"
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary" for="admissionsLinkTypeExternal">
                                External Link
                            </label>
                        </div>

                        <div v-if="buttonLinkType === 'page'">
                            <select
                                class="form-select"
                                v-model="localContent.button_page_id"
                                @change="emitUpdate"
                            >
                                <option :value="null">-- Select a page --</option>
                                <option
                                    v-for="page in availablePages"
                                    :key="page.id"
                                    :value="page.id"
                                >
                                    {{ page.title }}
                                </option>
                            </select>
                            <div class="form-text">
                                Point this at the page holding your application form, so
                                the link keeps working if the page is renamed.
                            </div>
                        </div>

                        <div v-else>
                            <input
                                type="url"
                                class="form-control"
                                v-model="localContent.button_link"
                                @input="emitUpdate"
                                placeholder="https://example.com"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8 mb-3">
                <label class="form-label">Disclaimer</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.disclaimer"
                    @input="emitUpdate"
                    placeholder="e.g., Tuition and fees are subject to change."
                />
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Background Color</label>
                <input
                    type="color"
                    class="form-control form-control-color flex-shrink-0"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import {
    AdmissionsTuitionSectionContent,
    AdmissionsFee,
    AdmissionsPaymentPlan,
    AdmissionsStep,
    TuitionTier,
} from '@/core/types/data/masjid-related/PageSection';
import { Page } from '@/core/types/data/masjid-related/Page';
import { ref, watch, onMounted } from 'vue';
import { usePagesStore } from '@/stores/masjid/pagesStore';

const props = defineProps<{
    modelValue: AdmissionsTuitionSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: AdmissionsTuitionSectionContent];
}>();

const pagesStore = usePagesStore();

const availablePages = ref<Page[]>([]);
const buttonLinkType = ref<'page' | 'external'>('page');

const newTier = (): TuitionTier => ({
    name: '',
    badge: '',
    amount: '',
    period: '',
    note: '',
    includes: [],
});

const newFee = (): AdmissionsFee => ({ label: '', amount: '', note: '' });
const newPlan = (): AdmissionsPaymentPlan => ({ label: '', detail: '' });
const newStep = (): AdmissionsStep => ({ title: '', description: '' });

const normalize = (value?: AdmissionsTuitionSectionContent): AdmissionsTuitionSectionContent => ({
    heading: value?.heading || '',
    description: value?.description || '',
    school_year: value?.school_year || '',
    // A tier saved without its bullet list must not crash the v-for.
    tiers: value?.tiers
        ? value.tiers.map((tier) => ({
            ...tier,
            includes: tier.includes ? [...tier.includes] : [],
        }))
        : [],
    fees: value?.fees ? [...value.fees] : [],
    payment_plans: value?.payment_plans ? [...value.payment_plans] : [],
    steps: value?.steps ? [...value.steps] : [],
    disclaimer: value?.disclaimer || '',
    button_text: value?.button_text || '',
    button_page_id: value?.button_page_id ?? null,
    button_link: value?.button_link ?? null,
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<AdmissionsTuitionSectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/* ---------------- tuition tiers ---------------- */

const addTier = () => {
    localContent.value.tiers.push(newTier());
    emitUpdate();
};

const removeTier = (index: number) => {
    localContent.value.tiers.splice(index, 1);
    emitUpdate();
};

const moveTier = (index: number, offset: number) => {
    const target = index + offset;
    if (target < 0 || target >= localContent.value.tiers.length) {
        return;
    }
    const tiers = [...localContent.value.tiers];
    [tiers[index], tiers[target]] = [tiers[target], tiers[index]];
    localContent.value.tiers = tiers;
    emitUpdate();
};

const addInclude = (index: number) => {
    localContent.value.tiers[index].includes.push('');
    emitUpdate();
};

const removeInclude = (index: number, includeIndex: number) => {
    localContent.value.tiers[index].includes.splice(includeIndex, 1);
    emitUpdate();
};

// `includes` is an array of plain strings, so v-model on the element cannot write
// back into the array slot — the value is bound and written by index here.
const onIncludeInput = (index: number, includeIndex: number, event: Event) => {
    const target = event.target as HTMLInputElement;
    localContent.value.tiers[index].includes[includeIndex] = target.value;
    emitUpdate();
};

/* ---------------- fees / plans / steps ---------------- */

const addFee = () => {
    localContent.value.fees.push(newFee());
    emitUpdate();
};

const removeFee = (index: number) => {
    localContent.value.fees.splice(index, 1);
    emitUpdate();
};

const addPlan = () => {
    localContent.value.payment_plans.push(newPlan());
    emitUpdate();
};

const removePlan = (index: number) => {
    localContent.value.payment_plans.splice(index, 1);
    emitUpdate();
};

const addStep = () => {
    localContent.value.steps.push(newStep());
    emitUpdate();
};

const removeStep = (index: number) => {
    localContent.value.steps.splice(index, 1);
    emitUpdate();
};

const moveStep = (index: number, offset: number) => {
    const target = index + offset;
    if (target < 0 || target >= localContent.value.steps.length) {
        return;
    }
    const steps = [...localContent.value.steps];
    [steps[index], steps[target]] = [steps[target], steps[index]];
    localContent.value.steps = steps;
    emitUpdate();
};

/* ---------------- apply button ---------------- */

const onLinkTypeChange = () => {
    // Clear the other field when switching types, so the two can never disagree
    // about where the button goes.
    if (buttonLinkType.value === 'page') {
        localContent.value.button_link = null;
    } else {
        localContent.value.button_page_id = null;
    }
    emitUpdate();
};

onMounted(async () => {
    await pagesStore.fetchMasjidPagesPaginated(1);
    if (pagesStore.pagesPaginated?.data) {
        availablePages.value = pagesStore.pagesPaginated.data;
    }

    buttonLinkType.value = localContent.value.button_link ? 'external' : 'page';
});
</script>

<style scoped>
.card {
    border: 1px solid #dee2e6;
}

.card-body {
    background-color: #f8f9fa;
}
</style>
