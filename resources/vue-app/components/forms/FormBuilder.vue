<template>
    <div class="form-builder">
        <div v-if="loading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm me-2"></span>
            Loading form…
        </div>

        <template v-else>
            <!-- ------------------------------------------------------------ basics -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-card-heading me-2"></i>Form details</h6>

                    <div class="row">
                        <div class="col-md-7 mb-3">
                            <label class="form-label">Form name <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                :value="draft.name"
                                @input="onNameInput(($event.target as HTMLInputElement).value)"
                                placeholder="e.g. Ashab al-Kahf Youth Retreat 2026"
                            />
                        </div>

                        <div class="col-md-5 mb-3">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control font-monospace"
                                :class="{ 'is-invalid': !!slugProblem }"
                                v-model.trim="draft.slug"
                                placeholder="camp-2026"
                            />
                            <div v-if="slugProblem" class="invalid-feedback d-block">{{ slugProblem }}</div>
                            <small v-else class="form-text text-muted">
                                Lowercase letters, numbers and dashes. Unique within this masjid.
                            </small>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea
                                class="form-control"
                                v-model="draft.description"
                                rows="2"
                                placeholder="One line the admin list shows — dates, venue, who it is for."
                            ></textarea>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label d-block">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="formBuilderActive"
                                    v-model="draft.is_active"
                                />
                                <label class="form-check-label" for="formBuilderActive">
                                    {{ draft.is_active ? 'Accepting' : 'Switched off' }}
                                </label>
                            </div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Capacity</label>
                            <input
                                type="number"
                                class="form-control"
                                min="1"
                                :value="draft.capacity ?? ''"
                                @input="draft.capacity = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                placeholder="Unlimited"
                            />
                            <small class="form-text text-muted">Total entries, not submissions.</small>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Opens</label>
                            <input type="datetime-local" class="form-control" v-model="draft.opens_at" />
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Closes</label>
                            <input type="datetime-local" class="form-control" v-model="draft.closes_at" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---------------------------------------------------------- sections -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-list-task me-2"></i>Questions</h6>
                <button type="button" class="btn btn-sm btn-primary" @click="addSection">
                    <i class="bi bi-plus-circle"></i> Add Section
                </button>
            </div>

            <div
                v-for="(section, sectionIndex) in draft.sections"
                :key="sectionIndex"
                class="card mb-3 section-card"
            >
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                        <div class="text-truncate">
                            <strong>{{ section.title || `Section ${sectionIndex + 1}` }}</strong>
                            <span class="text-muted small ms-2">
                                {{ section.fields.length }} question{{ section.fields.length === 1 ? '' : 's' }}
                                <template v-if="section.repeatable">
                                    · repeats {{ section.minEntries ?? 0 }}–{{ section.maxEntries || '∞' }}
                                </template>
                            </span>
                        </div>
                        <div class="btn-group flex-shrink-0">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="moveSection(sectionIndex, -1)"
                                :disabled="sectionIndex === 0"
                                title="Move Up"
                            >
                                <i class="bi bi-arrow-up"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="moveSection(sectionIndex, 1)"
                                :disabled="sectionIndex === draft.sections.length - 1"
                                title="Move Down"
                            >
                                <i class="bi bi-arrow-down"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                @click="removeSection(sectionIndex)"
                                :disabled="draft.sections.length === 1"
                                title="Remove Section"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-7 mb-3">
                            <label class="form-label">Section heading</label>
                            <input
                                type="text"
                                class="form-control"
                                :value="section.title ?? ''"
                                @input="onSectionTitleInput(section, ($event.target as HTMLInputElement).value)"
                                placeholder="e.g. Who Is Attending?"
                            />
                        </div>

                        <div class="col-md-5 mb-3">
                            <label class="form-label">Section key</label>
                            <input
                                type="text"
                                class="form-control form-control-sm font-monospace"
                                :class="{ 'is-invalid': !!sectionIdProblems[sectionIndex] }"
                                v-model.trim="section.id"
                            />
                            <div v-if="sectionIdProblems[sectionIndex]" class="invalid-feedback d-block">
                                {{ sectionIdProblems[sectionIndex] }}
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Section note</label>
                            <input
                                type="text"
                                class="form-control"
                                v-model="section.description"
                                placeholder="Shown under the heading"
                            />
                        </div>

                        <!-- Repeatable -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label d-block">Repeats?</label>
                            <div class="form-check form-switch mt-2">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    :id="`sectionRepeatable${sectionIndex}`"
                                    :checked="!!section.repeatable"
                                    :disabled="!section.repeatable && hasRepeatableSection"
                                    @change="onRepeatableToggle(section, ($event.target as HTMLInputElement).checked)"
                                />
                                <label class="form-check-label" :for="`sectionRepeatable${sectionIndex}`">
                                    {{ section.repeatable ? 'One entry per person' : 'Asked once' }}
                                </label>
                            </div>
                            <small v-if="!section.repeatable && hasRepeatableSection" class="form-text text-muted">
                                A form can have only one repeating section.
                            </small>
                        </div>

                        <template v-if="section.repeatable">
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Min entries</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    min="0"
                                    :value="section.minEntries ?? ''"
                                    @input="section.minEntries = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                    placeholder="0"
                                />
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Max entries</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    min="1"
                                    :value="section.maxEntries ?? ''"
                                    @input="section.maxEntries = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                    placeholder="No limit"
                                />
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Add button label</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="section.addButtonLabel"
                                    placeholder="Add another attendee"
                                />
                            </div>
                        </template>
                    </div>

                    <!-- Questions -->
                    <FormFieldEditor
                        v-for="(field, fieldIndex) in section.fields"
                        :key="fieldIndex"
                        :field="field"
                        :index="fieldIndex"
                        :total="section.fields.length"
                        :field-types="formsStore.fieldTypes"
                        :id-prefix="`form_s${sectionIndex}_f${fieldIndex}`"
                        :name-problem="fieldNameProblems[`${sectionIndex}:${fieldIndex}`] ?? null"
                        :conditional-sources="conditionalSourcesFor(section)"
                        @label-input="onFieldLabelInput(section, field, $event)"
                        @type-change="onFieldTypeChange(field, $event)"
                        @move-up="moveField(section, fieldIndex, -1)"
                        @move-down="moveField(section, fieldIndex, 1)"
                        @remove="removeField(section, fieldIndex)"
                    />

                    <div v-if="section.fields.length === 0" class="alert alert-warning py-2 small">
                        Every section needs at least one question.
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addField(section)">
                        <i class="bi bi-plus-circle"></i> Add Question
                    </button>
                </div>
            </div>

            <!-- ---------------------------------------------------- identity + fee -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-1"><i class="bi bi-person-vcard me-2"></i>Who is this response from?</h6>
                    <p class="text-muted small">
                        These three answers are copied onto the response so the Form Responses list can
                        search and contact people. Every choice must be a question this form actually asks.
                    </p>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Name question</label>
                            <select class="form-select" v-model="draft.settings.identityName">
                                <option :value="null">Not captured</option>
                                <option v-for="field in flatFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Email question</label>
                            <select class="form-select" v-model="draft.settings.identityEmail">
                                <option :value="null">Not captured</option>
                                <option v-for="field in flatFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Phone question</label>
                            <select class="form-select" v-model="draft.settings.identityPhone">
                                <option :value="null">Not captured</option>
                                <option v-for="field in flatFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <h6 class="mb-1 mt-2"><i class="bi bi-cash-coin me-2"></i>Fee</h6>
                    <p class="text-muted small">
                        Recorded as the amount owed on each response. No payment is taken here — the
                        total is worked out when the form is submitted and then frozen, so a later
                        price change never restates what somebody already agreed to pay.
                    </p>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Amount</label>
                            <input
                                type="number"
                                class="form-control"
                                min="0"
                                step="0.01"
                                :value="draft.settings.feeAmount ?? ''"
                                @input="draft.settings.feeAmount = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                placeholder="No fee"
                            />
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Currency</label>
                            <input
                                type="text"
                                class="form-control text-uppercase"
                                maxlength="3"
                                v-model.trim="draft.settings.feeCurrency"
                                placeholder="USD"
                            />
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label">Charged</label>
                            <select class="form-select" v-model="draft.settings.feePerEntryOfSection">
                                <option :value="null">Once per submission</option>
                                <option
                                    v-for="section in repeatableSections"
                                    :key="section.id"
                                    :value="section.id"
                                >
                                    Per entry of "{{ section.title || section.id }}"
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------------- copy/wording -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-chat-left-text me-2"></i>Wording</h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Submit button</label>
                            <input
                                type="text"
                                class="form-control"
                                v-model="draft.settings.submitButtonLabel"
                                placeholder="Submit"
                            />
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Notify these emails</label>
                            <div
                                v-for="(email, emailIndex) in draft.settings.notifyEmails"
                                :key="emailIndex"
                                class="input-group input-group-sm mb-1"
                            >
                                <input type="email" class="form-control" v-model.trim="draft.settings.notifyEmails[emailIndex]" />
                                <button type="button" class="btn btn-outline-danger" @click="draft.settings.notifyEmails.splice(emailIndex, 1)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="draft.settings.notifyEmails.push('')">
                                <i class="bi bi-plus-circle"></i> Add Email
                            </button>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Introduction</label>
                            <textarea
                                class="form-control"
                                v-model="draft.settings.intro"
                                rows="4"
                                placeholder="Shown above the first question. Basic HTML is allowed."
                            ></textarea>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Thank-you heading</label>
                            <input
                                type="text"
                                class="form-control"
                                v-model="draft.settings.successTitle"
                                placeholder="Jazak Allahu Khairan — your registration is in!"
                            />
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Thank-you message</label>
                            <textarea class="form-control" v-model="draft.settings.successBody" rows="2"></textarea>
                        </div>

                        <div class="col-12 mb-2">
                            <label class="form-label">What happens next</label>
                            <div
                                v-for="(step, stepIndex) in draft.settings.successNextSteps"
                                :key="stepIndex"
                                class="input-group input-group-sm mb-1"
                            >
                                <input type="text" class="form-control" v-model="draft.settings.successNextSteps[stepIndex]" />
                                <button type="button" class="btn btn-outline-danger" @click="draft.settings.successNextSteps.splice(stepIndex, 1)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="draft.settings.successNextSteps.push('')">
                                <i class="bi bi-plus-circle"></i> Add Step
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------------------ footer -->
            <div v-if="problems.length" class="alert alert-warning">
                <strong><i class="bi bi-exclamation-triangle me-2"></i>Fix these before saving</strong>
                <ul class="mb-0 mt-2 ps-3">
                    <li v-for="(problem, problemIndex) in problems" :key="problemIndex">{{ problem }}</li>
                </ul>
            </div>

            <div v-if="serverErrors.length" class="alert alert-danger">
                <strong><i class="bi bi-x-octagon me-2"></i>The server rejected this form</strong>
                <ul class="mb-0 mt-2 ps-3">
                    <li v-for="(error, errorIndex) in serverErrors" :key="errorIndex">{{ error }}</li>
                </ul>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" @click="emit('cancel')" :disabled="saving">
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="save"
                    :disabled="saving || problems.length > 0"
                >
                    <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                    <i class="bi bi-check-circle me-1"></i>
                    {{ props.formId ? 'Save Form' : 'Create Form' }}
                </button>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import {
    CHOICE_FIELD_TYPES,
    Form,
    FormField,
    FormFieldOption,
    FormFieldType,
    FormIdentityMap,
    FormPayload,
    FormSchemaSection,
    FormSettings,
    FORM_IDENTIFIER_PATTERN,
    deriveFormIdentifier,
    deriveFormSlug,
    uniqueFormIdentifier
} from '@/core/types/data/masjid-related/Form';
import FormFieldEditor from '@/components/forms/FormFieldEditor.vue';
import { useFormsStore } from '@/stores/masjid/formsStore';
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2';

/**
 * The sign-up form builder: sections, questions, the identity map and the fee rule.
 *
 * It mirrors App\Rules\ValidFormSchema client-side — every rule the server enforces is
 * also computed here as a `problem` and blocks the Save button. That is a courtesy, not
 * the enforcement: the server re-checks everything, and a 422 is surfaced verbatim.
 *
 * Answer keys and section keys are derived from their labels but stay editable, because
 * a key is the storage key of every answer already collected under it.
 */
const props = defineProps<{
    /** Null / omitted builds a new form; an id loads that form for editing. */
    formId?: number | null;
}>();

const emit = defineEmits<{
    saved: [form: Form];
    cancel: [];
}>();

const formsStore = useFormsStore();

/**
 * The settings block, flattened for binding. FormSettings nests identity and fee, both
 * of which the server rejects when half-filled (`fee.amount` is required_with:fee), so
 * the draft keeps them flat and buildPayload() reassembles — or omits — them.
 */
type DraftSettings = {
    submitButtonLabel: string;
    successTitle: string;
    successBody: string;
    successNextSteps: string[];
    notifyEmails: string[];
    intro: string;
    identityName: string | null;
    identityEmail: string | null;
    identityPhone: string | null;
    feeAmount: number | null;
    feeCurrency: string;
    feePerEntryOfSection: string | null;
};

type Draft = {
    name: string;
    slug: string;
    description: string;
    is_active: boolean;
    /** datetime-local strings ("2026-09-04T18:00"), '' meaning unbounded. */
    opens_at: string;
    closes_at: string;
    capacity: number | null;
    sections: FormSchemaSection[];
    settings: DraftSettings;
};

const loading = ref(false);
const saving = ref(false);
const serverErrors = ref<string[]>([]);

const blankSettings = (): DraftSettings => ({
    submitButtonLabel: 'Submit',
    successTitle: '',
    successBody: '',
    successNextSteps: [],
    notifyEmails: [],
    intro: '',
    identityName: null,
    identityEmail: null,
    identityPhone: null,
    feeAmount: null,
    feeCurrency: 'USD',
    feePerEntryOfSection: null
});

/**
 * A new form starts with the three questions every sign-up needs, already wired into the
 * identity map — so a form is searchable and contactable by default rather than only if
 * the admin remembers to set it up.
 */
const blankDraft = (): Draft => ({
    name: '',
    slug: '',
    description: '',
    is_active: true,
    opens_at: '',
    closes_at: '',
    capacity: null,
    sections: [
        {
            id: 'yourInformation',
            title: 'Your Information',
            description: '',
            fields: [
                { name: 'fullName', label: 'Full name', type: 'text', required: true, autocomplete: 'name' },
                { name: 'email', label: 'Email address', type: 'email', required: true, autocomplete: 'email' },
                { name: 'phone', label: 'Phone number', type: 'tel', required: true, autocomplete: 'tel' }
            ]
        }
    ],
    settings: {
        ...blankSettings(),
        identityName: 'fullName',
        identityEmail: 'email',
        identityPhone: 'phone'
    }
});

const draft = ref<Draft>(blankDraft());

// ------------------------------------------------------------------- load / save

/** ISO 8601 from the API -> the value a datetime-local input understands. */
const toDateTimeLocal = (iso: string | null): string => (iso ? iso.slice(0, 16) : '');

/** '' -> null so an emptied box clears the value rather than storing 0 or NaN. */
const toNumberOrNull = (value: string): number | null => {
    if (value === null || value.trim() === '') return null;
    const parsed = Number(value);
    return Number.isNaN(parsed) ? null : parsed;
};

/** The address follows the name until an admin edits the address by hand. */
const onNameInput = (name: string) => {
    const wasAuto = !draft.value.slug || draft.value.slug === deriveFormSlug(draft.value.name);

    draft.value.name = name;

    if (wasAuto) {
        draft.value.slug = deriveFormSlug(name);
    }
};

const load = async () => {
    serverErrors.value = [];

    if (!props.formId) {
        draft.value = blankDraft();
        return;
    }

    loading.value = true;

    try {
        const form = await formsStore.fetchForm(props.formId);

        if (!form) {
            draft.value = blankDraft();
            return;
        }

        const settings: FormSettings = form.settings ?? {};

        draft.value = {
            name: form.name,
            slug: form.slug,
            description: form.description ?? '',
            is_active: form.is_active,
            opens_at: toDateTimeLocal(form.opens_at),
            closes_at: toDateTimeLocal(form.closes_at),
            capacity: form.capacity,
            sections: (form.schema?.sections ?? []).map(section => ({
                ...section,
                description: section.description ?? '',
                fields: (section.fields ?? []).map(field => ({ ...field }))
            })),
            settings: {
                ...blankSettings(),
                submitButtonLabel: settings.submitButtonLabel ?? 'Submit',
                successTitle: settings.successTitle ?? '',
                successBody: settings.successBody ?? '',
                successNextSteps: [...(settings.successNextSteps ?? [])],
                notifyEmails: [...(settings.notifyEmails ?? [])],
                intro: settings.intro ?? '',
                identityName: settings.identity?.name ?? null,
                identityEmail: settings.identity?.email ?? null,
                identityPhone: settings.identity?.phone ?? null,
                feeAmount: settings.fee?.amount ?? null,
                feeCurrency: settings.fee?.currency ?? 'USD',
                feePerEntryOfSection: settings.fee?.perEntryOfSection ?? null
            }
        };
    } catch (error: any) {
        console.error('Load form error: ', error);
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not load this form.' });
    } finally {
        loading.value = false;
    }
};

watch(() => props.formId, load, { immediate: true });

// The palette is fetched once; the store keeps the compiled-in fallback until it lands.
formsStore.fetchFieldTypes();

/**
 * Draft -> the exact payload the API expects. Empty optional values are dropped rather
 * than sent as '' or null, so the stored schema stays the shape the renderer reads.
 */
const buildPayload = (): FormPayload => {
    const sections: FormSchemaSection[] = draft.value.sections.map(section => {
        const clean: FormSchemaSection = {
            id: section.id.trim(),
            fields: section.fields.map(field => buildField(field))
        };

        if (section.title) clean.title = section.title;
        if (section.description) clean.description = section.description;

        if (section.repeatable) {
            clean.repeatable = true;
            clean.minEntries = section.minEntries ?? 0;
            if (section.maxEntries) clean.maxEntries = section.maxEntries;
            if (section.addButtonLabel) clean.addButtonLabel = section.addButtonLabel;
        }

        return clean;
    });

    const settings: FormSettings = {};
    const draftSettings = draft.value.settings;

    if (draftSettings.submitButtonLabel) settings.submitButtonLabel = draftSettings.submitButtonLabel;
    if (draftSettings.intro) settings.intro = draftSettings.intro;
    if (draftSettings.successTitle) settings.successTitle = draftSettings.successTitle;
    if (draftSettings.successBody) settings.successBody = draftSettings.successBody;

    const nextSteps = draftSettings.successNextSteps.map(step => step.trim()).filter(Boolean);
    if (nextSteps.length) settings.successNextSteps = nextSteps;

    const notifyEmails = draftSettings.notifyEmails.map(email => email.trim()).filter(Boolean);
    if (notifyEmails.length) settings.notifyEmails = notifyEmails;

    const identity: FormIdentityMap = {};
    if (draftSettings.identityName) identity.name = draftSettings.identityName;
    if (draftSettings.identityEmail) identity.email = draftSettings.identityEmail;
    if (draftSettings.identityPhone) identity.phone = draftSettings.identityPhone;
    if (Object.keys(identity).length) settings.identity = identity;

    // `fee.amount` is required_with:fee, so a fee block without an amount is never sent.
    if (draftSettings.feeAmount !== null) {
        settings.fee = {
            amount: draftSettings.feeAmount,
            currency: (draftSettings.feeCurrency || 'USD').toUpperCase(),
            perEntryOfSection: draftSettings.feePerEntryOfSection || null
        };
    }

    return {
        name: draft.value.name.trim(),
        slug: draft.value.slug.trim(),
        description: draft.value.description.trim() || null,
        schema: { sections },
        settings,
        is_active: draft.value.is_active,
        opens_at: draft.value.opens_at || null,
        closes_at: draft.value.closes_at || null,
        capacity: draft.value.capacity
    };
};

const buildField = (field: FormField): FormField => {
    const clean: FormField = {
        name: field.name.trim(),
        label: field.label.trim(),
        type: field.type,
        required: !!field.required
    };

    if (field.help) clean.help = field.help;
    if (field.bodyText) clean.bodyText = field.bodyText;

    if (['text', 'email', 'tel', 'number', 'date', 'textarea'].includes(field.type)) {
        if (field.placeholder) clean.placeholder = field.placeholder;
        if (field.autocomplete) clean.autocomplete = field.autocomplete;
    }

    if (field.type === 'number') {
        if (field.min !== null && field.min !== undefined) clean.min = field.min;
        if (field.max !== null && field.max !== undefined) clean.max = field.max;
    }

    if (CHOICE_FIELD_TYPES.includes(field.type)) {
        clean.options = (field.options ?? []).map(option => {
            const cleanOption: FormFieldOption = {
                value: option.value.trim(),
                label: option.label.trim()
            };
            if (option.detail) cleanOption.detail = option.detail;
            return cleanOption;
        });
    }

    if (field.requiredIf) {
        clean.requiredIf = { ...field.requiredIf };
    }

    return clean;
};

const save = async () => {
    if (problems.value.length) return;

    saving.value = true;
    serverErrors.value = [];

    try {
        const payload = buildPayload();

        const saved = props.formId
            ? await formsStore.updateForm(props.formId, payload)
            : await formsStore.createForm(payload);

        // The picker reads this list, so keep it in step with what was just saved.
        await formsStore.fetchFormOptions();

        Swal.fire({
            icon: 'success',
            title: 'Saved!',
            text: props.formId ? 'Form updated.' : 'Form created.',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });

        emit('saved', saved);
    } catch (error: any) {
        console.error('Save form error: ', error);

        // 422 -> { status: 'failed', data: { field: [message] } }
        const validation = error.response?.data?.data;

        if (error.response?.status === 422 && validation && typeof validation === 'object') {
            serverErrors.value = Object.values(validation).flat().map(message => String(message));
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: error.response?.data?.message || 'Failed to save the form. Please try again.'
            });
        }
    } finally {
        saving.value = false;
    }
};

// --------------------------------------------------------------------- structure

const hasRepeatableSection = computed(() => draft.value.sections.some(section => section.repeatable));

const repeatableSections = computed(() => draft.value.sections.filter(section => section.repeatable));

/** Questions outside the repeating section — the only ones the identity map may use. */
const flatFields = computed(() =>
    draft.value.sections
        .filter(section => !section.repeatable)
        .flatMap(section => section.fields.map(field => ({ name: field.name, label: field.label })))
);

/**
 * Repeating sections a conditional rule can watch. Nothing is offered inside a repeating
 * section: the backend only evaluates conditionals on flat questions, so a rule there
 * would never fire.
 */
const conditionalSourcesFor = (section: FormSchemaSection) => {
    if (section.repeatable) return [];

    return repeatableSections.value
        .map(candidate => ({
            id: candidate.id,
            title: candidate.title || candidate.id,
            fields: candidate.fields
                .filter(field => field.type === 'number')
                .map(field => ({ name: field.name, label: field.label }))
        }))
        .filter(candidate => candidate.fields.length > 0);
};

/**
 * New sections and questions start with an EMPTY key on purpose: the key follows the
 * heading/question only while it still matches what that text would derive, and a
 * placeholder like "section2" would never match — the key would then stay frozen at the
 * placeholder while the admin typed a real heading.
 */
const addSection = () => {
    draft.value.sections.push({
        id: '',
        title: '',
        description: '',
        fields: [{ name: '', label: '', type: 'text', required: false }]
    });
};

const removeSection = (index: number) => {
    if (draft.value.sections.length <= 1) return;
    draft.value.sections.splice(index, 1);
};

const moveSection = (index: number, direction: number) => {
    const target = index + direction;
    const sections = draft.value.sections;

    if (target < 0 || target >= sections.length) return;

    [sections[target], sections[index]] = [sections[index], sections[target]];
};

/**
 * Anything that referenced the old key follows it, so typing a heading cannot quietly
 * break the fee rule or a conditional that pointed at this section.
 */
const retargetSectionId = (previous: string, next: string) => {
    if (!previous || previous === next) return;

    if (draft.value.settings.feePerEntryOfSection === previous) {
        draft.value.settings.feePerEntryOfSection = next;
    }

    draft.value.sections.forEach(section => {
        section.fields.forEach(field => {
            if (field.requiredIf?.section === previous) {
                field.requiredIf.section = next;
            }
        });
    });
};

/** The section key follows the heading until an admin edits the key by hand. */
const onSectionTitleInput = (section: FormSchemaSection, title: string) => {
    const wasAuto = !section.id || section.id === deriveFormIdentifier(section.title ?? '', 'section');

    section.title = title;

    if (wasAuto) {
        const previous = section.id;
        const taken = draft.value.sections.filter(other => other !== section).map(other => other.id);
        section.id = uniqueFormIdentifier(deriveFormIdentifier(title, 'section'), taken);
        retargetSectionId(previous, section.id);
    }
};

const onRepeatableToggle = (section: FormSchemaSection, repeatable: boolean) => {
    section.repeatable = repeatable;

    if (repeatable) {
        section.minEntries = section.minEntries ?? 1;
        section.addButtonLabel = section.addButtonLabel || 'Add another';
        return;
    }

    // The fee and any conditional rules pointed at this section are now meaningless.
    if (draft.value.settings.feePerEntryOfSection === section.id) {
        draft.value.settings.feePerEntryOfSection = null;
    }

    draft.value.sections.forEach(other => {
        other.fields.forEach(field => {
            if (field.requiredIf?.section === section.id) {
                field.requiredIf = null;
            }
        });
    });
};

const addField = (section: FormSchemaSection) => {
    section.fields.push({
        name: '',
        label: '',
        type: 'text',
        required: false
    });
};

const removeField = (section: FormSchemaSection, index: number) => {
    section.fields.splice(index, 1);
};

const moveField = (section: FormSchemaSection, index: number, direction: number) => {
    const target = index + direction;

    if (target < 0 || target >= section.fields.length) return;

    [section.fields[target], section.fields[index]] = [section.fields[index], section.fields[target]];
};

/**
 * The identity map and any conditional rule that named the old key follow the rename.
 * Scoped to the section the question lives in, because keys are only unique within a
 * repeating section — a same-named question elsewhere must not be re-pointed.
 */
const retargetFieldName = (section: FormSchemaSection, previous: string, next: string) => {
    if (!previous || previous === next) return;

    // The identity map may only name questions outside the repeating section.
    if (!section.repeatable) {
        const settings = draft.value.settings;
        if (settings.identityName === previous) settings.identityName = next;
        if (settings.identityEmail === previous) settings.identityEmail = next;
        if (settings.identityPhone === previous) settings.identityPhone = next;
    }

    draft.value.sections.forEach(other => {
        other.fields.forEach(candidate => {
            if (candidate.requiredIf?.section === section.id && candidate.requiredIf.field === previous) {
                candidate.requiredIf.field = next;
            }
        });
    });
};

/** The answer key follows the question until an admin edits the key by hand. */
const onFieldLabelInput = (section: FormSchemaSection, field: FormField, label: string) => {
    const wasAuto = !field.name || field.name === deriveFormIdentifier(field.label);

    field.label = label;

    if (wasAuto) {
        const previous = field.name;
        const taken = section.fields.filter(other => other !== field).map(other => other.name);
        field.name = uniqueFormIdentifier(deriveFormIdentifier(label), taken);
        retargetFieldName(section, previous, field.name);
    }
};

const onFieldTypeChange = (field: FormField, type: FormFieldType) => {
    field.type = type;

    // A choice question is refused without options, so open one empty row straight away.
    if (CHOICE_FIELD_TYPES.includes(type) && !field.options?.length) {
        field.options = [{ value: '', label: '', detail: null }];
    }

    // Only a number question can be the subject of a conditional rule.
    if (type !== 'number') {
        draft.value.sections.forEach(section => {
            section.fields.forEach(other => {
                if (other.requiredIf?.field === field.name) {
                    other.requiredIf = null;
                }
            });
        });
    }
};

// -------------------------------------------------------------------- validation
// A client-side mirror of App\Rules\ValidFormSchema + StoreFormRequest, so an admin sees
// what is wrong while they are looking at it instead of after a rejected save.

const slugProblem = computed(() => {
    const slug = draft.value.slug.trim();

    if (!slug) return 'An address is required.';
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
        return 'Use lowercase letters, numbers and single dashes — no spaces.';
    }

    return null;
});

/** Key problems by section index, for the inline invalid state on the key input. */
const sectionIdProblems = computed(() => {
    const problems: Record<number, string> = {};
    const seen: string[] = [];

    draft.value.sections.forEach((section, index) => {
        const id = (section.id || '').trim();

        if (!FORM_IDENTIFIER_PATTERN.test(id)) {
            problems[index] = 'Letters, numbers and underscores, starting with a letter.';
        } else if (seen.includes(id)) {
            problems[index] = `Another section already uses the key "${id}".`;
        }

        seen.push(id);
    });

    return problems;
});

/** Key problems by "sectionIndex:fieldIndex". */
const fieldNameProblems = computed(() => {
    const problems: Record<string, string> = {};
    const flatNames: string[] = [];
    const sectionIds = draft.value.sections.map(section => (section.id || '').trim());

    draft.value.sections.forEach((section, sectionIndex) => {
        const namesInSection: string[] = [];

        section.fields.forEach((field, fieldIndex) => {
            const key = `${sectionIndex}:${fieldIndex}`;
            const name = (field.name || '').trim();

            if (!FORM_IDENTIFIER_PATTERN.test(name)) {
                problems[key] = 'Letters, numbers and underscores, starting with a letter.';
            } else if (namesInSection.includes(name)) {
                problems[key] = `Another question in this section already uses "${name}".`;
            } else if (!section.repeatable && flatNames.includes(name)) {
                problems[key] = `The key "${name}" is already used elsewhere in this form.`;
            } else if (!section.repeatable && sectionIds.includes(name) && name !== sectionIds[sectionIndex]) {
                problems[key] = `The key "${name}" collides with a section key.`;
            }

            namesInSection.push(name);
            if (!section.repeatable) flatNames.push(name);
        });
    });

    return problems;
});

const problems = computed<string[]>(() => {
    const found: string[] = [];
    const label = (section: FormSchemaSection, index: number) => section.title || `Section ${index + 1}`;

    if (!draft.value.name.trim()) found.push('The form needs a name.');
    if (slugProblem.value) found.push(`Address: ${slugProblem.value}`);

    if (draft.value.sections.length === 0) {
        found.push('A form needs at least one section.');
    }

    Object.entries(sectionIdProblems.value).forEach(([index, problem]) => {
        found.push(`${label(draft.value.sections[Number(index)], Number(index))}: ${problem}`);
    });

    if (repeatableSections.value.length > 1) {
        found.push('A form can have at most one repeating section.');
    }

    draft.value.sections.forEach((section, sectionIndex) => {
        const name = label(section, sectionIndex);

        if (section.fields.length === 0) {
            found.push(`${name} needs at least one question.`);
        }

        if (section.repeatable) {
            const min = section.minEntries ?? 0;
            const max = section.maxEntries ?? 0;

            if (min < 0) found.push(`${name}: the minimum number of entries must be zero or more.`);
            if (max !== 0 && max < 1) found.push(`${name}: the maximum number of entries must be at least one.`);
            if (max !== 0 && max < min) found.push(`${name}: the maximum number of entries cannot be lower than the minimum.`);
        }

        section.fields.forEach((field, fieldIndex) => {
            const question = field.label?.trim() || field.name || `Question ${fieldIndex + 1}`;

            if (!field.label?.trim()) {
                found.push(`${name}: question ${fieldIndex + 1} needs a label.`);
            }

            const keyProblem = fieldNameProblems.value[`${sectionIndex}:${fieldIndex}`];
            if (keyProblem) {
                found.push(`${name} — "${question}": ${keyProblem}`);
            }

            if (CHOICE_FIELD_TYPES.includes(field.type)) {
                const options = field.options ?? [];

                if (options.length === 0) {
                    found.push(`${name} — "${question}" is a choice question, so it needs at least one choice.`);
                }

                if (options.some(option => !option.value?.trim() || !option.label?.trim())) {
                    found.push(`${name} — "${question}": every choice needs wording and a stored value.`);
                }

                const values = options.map(option => option.value?.trim());
                if (new Set(values).size !== values.length) {
                    found.push(`${name} — "${question}": two choices share the same stored value.`);
                }
            }

            if (
                field.type === 'number' &&
                field.min !== null && field.min !== undefined &&
                field.max !== null && field.max !== undefined &&
                field.max < field.min
            ) {
                found.push(`${name} — "${question}" has a maximum lower than its minimum.`);
            }

            if (field.requiredIf) {
                const source = draft.value.sections.find(candidate => candidate.id === field.requiredIf?.section);

                if (!source || !source.repeatable) {
                    found.push(`${name} — "${question}": the conditional rule points at a section that no longer repeats.`);
                } else {
                    const target = source.fields.find(candidate => candidate.name === field.requiredIf?.field);

                    if (!target) {
                        found.push(`${name} — "${question}": the conditional rule points at a question that no longer exists.`);
                    } else if (target.type !== 'number') {
                        found.push(`${name} — "${question}": the conditional rule compares a question that is not a number.`);
                    }
                }
            }
        });
    });

    // Identity and fee must reference things that exist, or the server refuses the save.
    const flatNames = flatFields.value.map(field => field.name);

    ([
        ['identityName', 'name'],
        ['identityEmail', 'email'],
        ['identityPhone', 'phone']
    ] as const).forEach(([key, slot]) => {
        const value = draft.value.settings[key];

        if (value && !flatNames.includes(value)) {
            found.push(`The ${slot} question points at "${value}", which is not a question in this form.`);
        }
    });

    if (draft.value.settings.feeAmount !== null && draft.value.settings.feeAmount < 0) {
        found.push('The fee cannot be negative.');
    }

    const perEntry = draft.value.settings.feePerEntryOfSection;
    if (perEntry && !repeatableSections.value.some(section => section.id === perEntry)) {
        found.push(`The fee is charged per entry of "${perEntry}", which is not a repeating section.`);
    }

    return found;
});
</script>

<style scoped>
.section-card {
    border: 1px solid #dee2e6;
}

.section-card > .card-body {
    background-color: #f8f9fa;
}
</style>
