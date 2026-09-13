<template>
    <Teleport to="body">
        <div
            class="modal fade show d-block"
            tabindex="-1"
            style="background: rgba(0, 0, 0, 0.5)"
            @click.self="close"
        >
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-pencil-square me-2"></i>Add a registration
                        </h5>
                        <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                    </div>

                    <div class="modal-body">
                        <div v-if="loading" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading…</span>
                            </div>
                        </div>

                        <div v-else-if="loadError" class="alert alert-danger mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>{{ loadError }}
                        </div>

                        <form v-else @submit.prevent="submit">
                            <!--
                                THE MONEY SENTENCE, said before anything is typed
                                rather than after it is submitted. The absence of
                                a "they paid cash" box is a decision, and an
                                admin who does not read it here will look for
                                that box, not find it, and invent something.
                            -->
                            <div class="alert alert-light border small">
                                <i class="bi bi-shield-check me-1"></i>
                                A registration entered here takes a seat and records what the
                                plan costs — it never records a payment. On a paid plan it is
                                created <strong>unpaid</strong>: take the money through Stripe,
                                or waive it with <strong>Grant aid</strong> on the registration
                                itself. Payment states come only from Stripe's webhooks.
                            </div>

                            <!-- A refusal the server made, in its own words. -->
                            <div v-if="submitError" class="alert alert-danger small">
                                <i class="bi bi-exclamation-triangle me-2"></i>{{ submitError }}
                            </div>

                            <!-- ---------------------------------------- plan -->
                            <div class="mb-3">
                                <label class="form-label small text-muted mb-1">Fee plan <span class="text-danger">*</span></label>
                                <select
                                    class="form-select"
                                    :class="{ 'is-invalid': !!fieldError('fee_plan_id') }"
                                    v-model.number="form.fee_plan_id"
                                >
                                    <option :value="0">Choose a plan…</option>
                                    <option v-for="plan in sellablePlans" :key="plan.id" :value="plan.id">
                                        {{ plan.label }} — {{ formatMinor(plan.amount_minor, plan.currency) }}
                                        ({{ describePlanTerms(plan) }})
                                    </option>
                                </select>
                                <div v-if="fieldError('fee_plan_id')" class="invalid-feedback d-block">
                                    {{ fieldError('fee_plan_id') }}
                                </div>
                                <!--
                                    No plan means no registration is possible AT ALL,
                                    on either door — `register` 404s the fee_plan_id.
                                    Say that here rather than letting the admin fill
                                    in a whole form and be refused at the end.
                                -->
                                <div v-if="!sellablePlans.length" class="form-text text-danger">
                                    This offering has no active fee plan, so it cannot take a
                                    registration from anybody. Add one on the Fee plans tab first.
                                </div>
                                <div v-else-if="selectedPlanIsFree" class="form-text text-success">
                                    <i class="bi bi-check-circle me-1"></i>
                                    A free plan is confirmed straight away — there is nothing to pay.
                                </div>
                                <div v-else-if="form.fee_plan_id" class="form-text">
                                    Will be created unpaid, holding a seat.
                                </div>
                            </div>

                            <!-- --------------------------------------- payer -->
                            <fieldset class="mb-3">
                                <legend class="form-label small text-muted mb-1">
                                    Who is paying <span class="text-danger">*</span>
                                </legend>
                                <ContactPicker
                                    v-model:contact="payerContact"
                                    v-model:typed="payerTyped"
                                    placeholder="Search this organization's people, or type a new name"
                                    :error="fieldError('payer_contact_id') || fieldError('payer.name')"
                                    @search="search"
                                />
                                <div v-if="!payerContact && payerTyped.name.trim()" class="row g-2 mt-1">
                                    <div class="col-md-6">
                                        <input class="form-control form-control-sm" type="email" placeholder="Email (optional)" v-model="payerTyped.email">
                                    </div>
                                    <div class="col-md-6">
                                        <input class="form-control form-control-sm" placeholder="Phone (optional)" v-model="payerTyped.phone">
                                    </div>
                                </div>
                                <div class="form-text">
                                    This person becomes the recorded guardian of everybody listed below.
                                </div>
                            </fieldset>

                            <!-- ---------------------------------- registrants -->
                            <fieldset class="mb-3">
                                <legend class="form-label small text-muted mb-1">Who it is for</legend>

                                <p v-if="!registrantTyped.length" class="form-text mb-2">
                                    Nobody listed — the payer is registering themselves.
                                </p>

                                <div v-for="(_row, index) in registrantTyped" :key="index" class="border rounded p-2 mb-2">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="flex-grow-1">
                                            <ContactPicker
                                                v-model:contact="registrantContacts[index]"
                                                v-model:typed="registrantTyped[index]"
                                                placeholder="Search, or type a new name"
                                                :error="fieldError(`registrants.${index}.name`) || fieldError(`registrants.${index}.contact_id`)"
                                                @search="search"
                                            />
                                            <div v-if="!registrantContacts[index] && registrantTyped[index].name.trim()" class="row g-2 mt-1">
                                                <div class="col-md-6">
                                                    <input class="form-control form-control-sm" type="email" placeholder="Email (optional)" v-model="registrantTyped[index].email">
                                                </div>
                                                <div class="col-md-6">
                                                    <input class="form-control form-control-sm" placeholder="Phone (optional)" v-model="registrantTyped[index].phone">
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" @click="removeRegistrant(index)" title="Remove">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRegistrant">
                                    <i class="bi bi-plus-lg me-1"></i>Add a person
                                </button>
                            </fieldset>

                            <!-- --------------------------------- intake form -->
                            <div v-if="intakeSections.length" class="mb-3">
                                <h6 class="small text-uppercase text-muted">{{ intakeFormName }}</h6>

                                <!--
                                    A REQUIRED UPLOAD MAKES THIS SCREEN THE WRONG
                                    DOOR, and it says so instead of collecting
                                    eight fields and being refused on the ninth.
                                -->
                                <div v-if="blockingUploads.length" class="alert alert-warning small">
                                    <i class="bi bi-paperclip me-1"></i>
                                    This offering's form requires an upload
                                    ({{ blockingUploads.join(', ') }}), which cannot be attached
                                    from this screen. Send the family the sign-up link instead.
                                </div>

                                <div v-for="section in intakeSections" :key="section.id" class="mb-3">
                                    <div v-if="section.title" class="fw-semibold small mb-1">{{ section.title }}</div>
                                    <p v-if="section.description" class="text-muted small">{{ section.description }}</p>

                                    <!-- A repeating list: attendees, children, tickets. -->
                                    <template v-if="section.repeatable">
                                        <div
                                            v-for="(row, rowIndex) in repeatingRows(section.id)"
                                            :key="rowIndex"
                                            class="border rounded p-2 mb-2"
                                        >
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="small text-muted">{{ rowIndex + 1 }}</span>
                                                <button type="button" class="btn btn-sm btn-outline-danger" @click="removeRow(section, rowIndex)">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                            <div class="row g-2">
                                                <div v-for="field in section.fields" :key="field.name" class="col-md-6">
                                                    <IntakeFieldInput
                                                        :field="field"
                                                        :input-id="`intake-${section.id}-${rowIndex}-${field.name}`"
                                                        :error="fieldError(`${section.id}.${rowIndex}.${field.name}`)"
                                                        :model-value="row[field.name]"
                                                        @update:modelValue="(value) => (row[field.name] = value)"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRow(section)">
                                            <i class="bi bi-plus-lg me-1"></i>{{ section.addButtonLabel || 'Add another' }}
                                        </button>
                                        <div v-if="fieldError(section.id)" class="text-danger small mt-1">
                                            {{ fieldError(section.id) }}
                                        </div>
                                    </template>

                                    <div v-else class="row g-2">
                                        <div v-for="field in section.fields" :key="field.name" class="col-md-6">
                                            <IntakeFieldInput
                                                :field="field"
                                                :input-id="`intake-${section.id}-${field.name}`"
                                                :error="fieldError(field.name)"
                                                :model-value="answers[field.name]"
                                                @update:modelValue="(value) => (answers[field.name] = value)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ---------------------------------------- note -->
                            <div class="mb-1">
                                <label class="form-label small text-muted mb-1">Why is this being entered by hand?</label>
                                <input
                                    class="form-control"
                                    maxlength="500"
                                    placeholder="Paid cash at the desk / phoned in / no email address"
                                    v-model="form.note"
                                >
                                <div class="form-text">
                                    Kept on the registration with your name, for whoever reads it later.
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" :disabled="saving" @click="close">Cancel</button>
                        <button
                            type="button"
                            class="btn btn-success"
                            :disabled="!canSubmit"
                            @click="submit"
                        >
                            <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            Add registration
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, reactive, ref } from 'vue';
import IntakeFieldInput from './IntakeFieldInput.vue';
import ContactPicker from './ContactPicker.vue';
import { FormSchemaSection } from '@/core/types/data/masjid-related/Form';
import { Contact } from '@/core/types/data/masjid-related/Contact';
import {
    FeePlan,
    ManualRegistrationPayload,
    Offering,
    Registration
} from '@/core/types/data/masjid-related/Offering';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { formatMinor } from '@/composables/useMinorUnits';
import { apiErrorText } from '@/core/services/ApiErrors';

/**
 * Enter a registration BY HAND (T-041i): a family paying at the desk, a phone
 * call, a household with no email address.
 *
 * ------------------------------------------------------ WHAT IT DOES NOT DO
 *
 * IT DOES NOT RECORD A PAYMENT, and there is no box on it that could. No
 * amount, no currency, no "paid", no payment method: the price is the server's,
 * snapshotted from the immutable fee plan at intake, and `payment_status` is
 * advanced only by signature-verified Stripe webhooks. A hand-entered
 * registration on a paid plan is created UNPAID and every screen says so; the
 * two honest ways to a paid one are a FREE plan (confirmed in the same request)
 * or Grant aid waiving the total to zero. The alert at the top of the modal
 * says this before the admin types anything, because the absence of a "they
 * paid cash" box is a decision and an admin who does not see it explained will
 * assume it is an omission and work around it.
 *
 * IT DOES NOT VALIDATE THE INTAKE ANSWERS. `App\Support\FormSchema` derives
 * every rule from the form's STORED schema, on the same path the public intake
 * uses. This modal draws the questions and paints the server's own messages
 * onto the fields that earned them; the red asterisks are a convenience. The
 * one thing it decides for itself is whether it is the WRONG DOOR — a required
 * file question cannot be answered over JSON, so it blocks and says to send the
 * family the sign-up link.
 *
 * IT DOES NOT COMPUTE ANYTHING ABOUT SEATS. A full offering waitlists the
 * registration under the offering's row lock, server-side, and the roster shows
 * the result. Checking `remaining` in the browser first would be a second
 * answer that is stale the moment another family submits.
 *
 * ------------------------------------------------------------ THE PICKERS
 *
 * The payer and every registrant are EITHER an existing contact OR a typed
 * name, never both — the server refuses a row carrying both, because attaching
 * the id would ignore a name the admin typed on purpose and using the name
 * would ignore the person they picked.
 *
 * Picking an existing person as a REGISTRANT is the thing the public endpoint
 * refuses outright: confirming the registration writes a guardian edge from the
 * payer over them, and a guardian edge is what the parent portal reads to open
 * a child's records. It is allowed here because this route is authenticated and
 * gated on `manage contacts`, and because the edge is still written
 * `self_asserted` — it lists the child and opens nothing until staff confirm it
 * on the group screen.
 */

const props = defineProps<{ offeringId: number | string }>();

const emit = defineEmits<{
    (event: 'close'): void;
    (event: 'created', registration: Registration): void;
}>();

const offeringsStore = useOfferingsStore();
const { describePlanTerms } = useOfferingDisplay();

// Loading the two things the modal cannot be drawn without.
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const submitError = ref('');

const offering = ref<Offering | null>(null);
/** The server's field bag from the last refusal: request rules AND intake schema. */
const errors = ref<Record<string, string[]>>({});

const form = reactive<{ fee_plan_id: number; note: string }>({
    fee_plan_id: 0,
    note: ''
});

const payerContact = ref<Contact | null>(null);
/**
 * A `ref`, not a `reactive`: `v-model:typed` REPLACES the whole object when the
 * picker clears or commits a pick, and a `reactive` const cannot be reassigned
 * — the compiled `payerTyped = $event` would fail. Same shape as the registrant
 * rows below, which are replaced by index for the same reason.
 */
const payerTyped = ref({ name: '', email: '', phone: '' });

/**
 * The registrant rows, held as TWO parallel arrays rather than one list of
 * "either" objects, because that is what the picker binds to: index N is either
 * a picked contact or a typed name, never both. `registrantTyped.length` is the
 * row count — there is no third array to fall out of step with these two.
 */
const registrantContacts = ref<(Contact | null)[]>([]);
const registrantTyped = ref<{ name: string; email: string; phone: string }[]>([]);

/** The intake answers, keyed exactly as FormSchema expects them. */
const answers = reactive<Record<string, unknown>>({});

onBeforeMount(async () => {
    try {
        // Both reads, because a plan list without the intake form (or the other
        // way round) draws half a modal that cannot be submitted.
        const [loaded] = await Promise.all([
            offeringsStore.fetchOffering(props.offeringId),
            offeringsStore.fetchFeePlans(props.offeringId)
        ]);

        offering.value = loaded;
        seedAnswers();
    } catch (error) {
        loadError.value = apiErrorText(error, 'Could not load this offering.');
    } finally {
        loading.value = false;
    }
});

/**
 * Plans a NEW registration may be put on.
 *
 * `is_active` only, because plans are immutable and are deactivated-and-replaced
 * rather than edited: a deactivated plan is a price that is no longer offered,
 * and the server refuses one (`planInactive`). Existing registrations keep
 * resolving through theirs, which is why the row survives.
 */
const sellablePlans = computed<FeePlan[]>(() => offeringsStore.feePlans.filter((plan) => plan.is_active));

const selectedPlan = computed<FeePlan | null>(
    () => sellablePlans.value.find((plan) => plan.id === form.fee_plan_id) ?? null
);

/**
 * Read from the plan's KIND, not from `amount_minor === 0`: `free` is the kind
 * that has no Stripe leg, and a paid plan whose column happens to hold 0 is a
 * data fault the server refuses rather than a free registration.
 */
const selectedPlanIsFree = computed<boolean>(() => selectedPlan.value?.kind === 'free');

const intakeFormName = computed<string>(() => offering.value?.intake_form?.name || 'Registration questions');

const intakeSections = computed<FormSchemaSection[]>(
    () => offering.value?.intake_form?.schema?.sections ?? []
);

/** Required file questions — the one shape this screen cannot answer at all. */
const blockingUploads = computed<string[]>(() =>
    intakeSections.value
        .flatMap((section) => section.fields ?? [])
        .filter((field) => field.type === 'file' && field.required)
        .map((field) => field.label || field.name)
);

const canSubmit = computed<boolean>(() =>
    !saving.value
    && !loading.value
    && !loadError.value
    && !blockingUploads.value.length
    && form.fee_plan_id > 0
    && (!!payerContact.value || payerTyped.value.name.trim() !== '')
);

/**
 * Seed every answer key the schema declares, so a field the admin never touches
 * is sent as an explicit empty rather than absent. The server's `nullable` and
 * `required` rules then say the same thing about it that they would about the
 * public form, instead of the key's absence changing which rule applies.
 */
function seedAnswers(): void {
    for (const section of intakeSections.value) {
        if (section.repeatable) {
            const rows = Math.max(1, Number(section.minEntries ?? 1) || 1);
            answers[section.id] = Array.from({ length: rows }, () => blankRow(section));
            continue;
        }

        for (const field of section.fields ?? []) {
            if (field.type === 'file') continue;
            answers[field.name] = field.type === 'checkboxGroup'
                ? []
                : (field.type === 'checkbox' ? false : '');
        }
    }
}

function blankRow(section: FormSchemaSection): Record<string, unknown> {
    const row: Record<string, unknown> = {};

    for (const field of section.fields ?? []) {
        if (field.type === 'file') continue;
        row[field.name] = field.type === 'checkboxGroup' ? [] : (field.type === 'checkbox' ? false : '');
    }

    return row;
}

const repeatingRows = (sectionId: string): Record<string, unknown>[] =>
    (answers[sectionId] as Record<string, unknown>[]) ?? [];

const addRow = (section: FormSchemaSection): void => {
    const rows = repeatingRows(section.id);
    const max = Number(section.maxEntries ?? 0);

    if (max > 0 && rows.length >= max) return;

    rows.push(blankRow(section));
};

/**
 * Removing a row RE-KEYS every server error under this section, because the bag
 * is keyed by position (`attendees.1.age`). Leaving them would paint row 2's
 * message onto whoever moved into row 2 — the same positional trap the section
 * editors hit with queued file uploads.
 */
const removeRow = (section: FormSchemaSection, index: number): void => {
    repeatingRows(section.id).splice(index, 1);
    clearErrorsUnder(section.id);
};

const addRegistrant = (): void => {
    registrantContacts.value.push(null);
    registrantTyped.value.push({ name: '', email: '', phone: '' });
};

/**
 * Removing a row RE-KEYS every server error under `registrants`, because the
 * bag is keyed by position (`registrants.1.name`). Leaving them would paint
 * row 2's message onto whoever moved into row 2.
 */
const removeRegistrant = (index: number): void => {
    registrantContacts.value.splice(index, 1);
    registrantTyped.value.splice(index, 1);
    clearErrorsUnder('registrants');
};

const search = async (term: string, resolve: (people: Contact[]) => void): Promise<void> => {
    try {
        resolve(await offeringsStore.searchContacts(term));
    } catch (error) {
        // A failed lookup must not block typing a NEW name — that is the whole
        // reason the picker has a free-text half.
        resolve([]);
    }
};

const fieldError = (key: string): string => errors.value[key]?.[0] ?? '';

const clearErrorsUnder = (prefix: string): void => {
    errors.value = Object.fromEntries(
        Object.entries(errors.value).filter(([key]) => !key.startsWith(`${prefix}.`))
    );
};

const close = (): void => {
    if (saving.value) return;
    emit('close');
};

const submit = async (): Promise<void> => {
    if (!canSubmit.value) return;

    saving.value = true;
    submitError.value = '';
    errors.value = {};

    const payload: ManualRegistrationPayload = {
        fee_plan_id: form.fee_plan_id,
        // The id WINS when a person was picked, and the typed half is not sent
        // beside it: the server refuses a row carrying both.
        payer_contact_id: payerContact.value?.id ?? null,
        payer: payerContact.value
            ? null
            : {
                name: payerTyped.value.name.trim(),
                email: payerTyped.value.email.trim(),
                phone: payerTyped.value.phone.trim()
            },
        registrants: registrantTyped.value.map((_, index) => ({
            contact_id: registrantContacts.value[index]?.id ?? null,
            name: registrantContacts.value[index] ? '' : registrantTyped.value[index].name.trim(),
            email: registrantContacts.value[index] ? '' : registrantTyped.value[index].email.trim(),
            phone: registrantContacts.value[index] ? '' : registrantTyped.value[index].phone.trim()
        })),
        data: { ...answers },
        note: form.note.trim()
    };

    try {
        emit('created', await offeringsStore.createRegistration(props.offeringId, payload));
    } catch (error) {
        // TWO SHAPES, AND THEY MEAN DIFFERENT THINGS. A `data` OBJECT is a field
        // bag — this request's own rules, or the offering's intake schema —
        // and belongs on the fields. A `data` STRING is the service refusing
        // outright (a closed offering, an inactive plan) and belongs at the top,
        // in the server's own words.
        const body = (error as { response?: { data?: { data?: unknown } } })?.response?.data?.data;

        if (body && typeof body === 'object' && !Array.isArray(body)) {
            errors.value = body as Record<string, string[]>;
            submitError.value = 'Some answers need fixing before this can be recorded.';
        } else {
            submitError.value = apiErrorText(error, 'Could not record the registration.');
        }
    } finally {
        saving.value = false;
    }
};
</script>
