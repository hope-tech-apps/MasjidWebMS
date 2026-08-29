<template>
    <div class="offering-section-editor">
        <!--
            THE FIRST THING THIS EDITOR SAYS, because it used to be the thing it never
            said. Everything below previews what the published block will do; the site
            has no component to draw it with yet, so today it does none of it. The
            sentence is SERVER-SUPPLIED (SectionType::rendererNote) and printed verbatim
            here, in the palette and in the type's own description — one string, so
            these three surfaces cannot go on disagreeing.
        -->
        <div v-if="rendererNote" class="alert alert-warning py-2 px-3 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            {{ rendererNote }}
        </div>

        <div class="row">
            <!-- Which offering this section takes registrations for -->
            <div class="col-12 mb-3">
                <label class="form-label">
                    {{ offeringLabelSingular }} <span class="text-danger">*</span>
                </label>
                <select
                    class="form-select"
                    :value="localContent.offering_id"
                    @change="onOfferingSelected(($event.target as HTMLSelectElement).value)"
                    :disabled="loadingOptions"
                >
                    <option :value="null">-- Select one --</option>
                    <!--
                        One verdict per row, from the server. This used to combine is_open,
                        is_full and active_fee_plan_count by hand here — a fourth
                        reimplementation of "can somebody register", and the only one of the
                        four that happened to be right.
                    -->
                    <option v-for="option in options" :key="option.id" :value="option.id">
                        {{ option.name }} ({{ option.slug }})
                        · {{ registrationStateLabel(option.registration_state, option.registration_state_reason).toLowerCase() }}
                    </option>
                </select>
                <small class="form-text text-muted">
                    This section stores only a reference. The name, the description, the prices, the
                    registration window and the places left all come from the
                    {{ offeringLabelSingular.toLowerCase() }} itself, so editing it there updates every
                    page it is published on.
                </small>
            </div>

            <!-- What the reference currently points at, and every way it can be dead -->
            <div class="col-12 mb-3">
                <div v-if="loadingOptions" class="text-muted small">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading…
                </div>

                <div v-else-if="optionsError" class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    {{ optionsError }}
                </div>

                <div v-else-if="!localContent.offering_id" class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Nothing selected — this section renders nothing on the site until you choose one.
                </div>

                <div v-else-if="!selected" class="alert alert-danger mb-0">
                    <i class="bi bi-x-octagon me-2"></i>
                    This section points at something that no longer exists, or that has been switched
                    off. Pick another — the site renders nothing for a missing
                    {{ offeringLabelSingular.toLowerCase() }}.
                </div>

                <div
                    v-else
                    class="alert mb-0"
                    :class="selectedIsFault ? 'alert-danger' : 'alert-info'"
                >
                    <h6 class="alert-heading">
                        <i class="bi bi-ui-checks me-2"></i>{{ selected.name }}
                    </h6>

                    <p class="mb-1">
                        <!--
                            "Will" and not "does": the block is not drawn yet (the banner at
                            the top of this editor). This describes the offering's own state,
                            which is real and which is what the block will report once the
                            renderer ships.
                        -->
                        <strong>Registration state:</strong>
                        <span class="badge" :class="selectedStateBadge">{{ selectedStateLabel }}</span>
                    </p>

                    <p class="mb-1">
                        <strong>Fee plans:</strong>
                        {{ selected.active_fee_plan_count }} active
                    </p>

                    <p class="mb-1">
                        <strong>Sign-up form:</strong>
                        {{ selected.has_intake_form ? 'attached' : 'deleted' }}
                    </p>

                    <!--
                        The misconfigurations an admin cannot see from the name — no active fee
                        plan for the register endpoint's `fee_plan_id`, or a sign-up form that has
                        been deleted out from under the offering. Either one renders the page and
                        refuses every submission. The wording is the SHARED one
                        (useOfferingDisplay), so this editor, the offerings list and the offering
                        detail header say the same sentence.
                    -->
                    <template v-if="selectedStateHint">
                        <hr />
                        <p class="mb-0">
                            <i class="bi bi-info-circle me-1"></i>{{ selectedStateHint }}
                        </p>
                    </template>
                </div>
            </div>

            <!-- Page-level wording. The offering carries its own name and description. -->
            <div class="col-12 mb-3">
                <label class="form-label">Section heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.title"
                    @input="emitUpdate"
                    placeholder="e.g. Register for the autumn semester"
                />
            </div>

            <div class="col-12 mb-3">
                <label class="form-label">Section intro</label>
                <textarea
                    class="form-control"
                    v-model="localContent.intro"
                    @input="emitUpdate"
                    rows="3"
                    placeholder="Shown above the registration block on the page."
                ></textarea>
                <small class="form-text text-muted">
                    Page-level wording only. The {{ offeringLabelSingular.toLowerCase() }}'s own name
                    and description are edited with it, not here.
                </small>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Button text</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.button_text"
                    @input="emitUpdate"
                    placeholder="Register"
                />
                <small class="form-text text-muted">
                    Wording only — it never decides whether registration is open.
                </small>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">Background colour</label>
                <input
                    type="color"
                    class="form-control form-control-color"
                    v-model="localContent.background_color"
                    @input="emitUpdate"
                />
            </div>

            <div class="col-12 mb-3">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="offeringShowFeePlans"
                        v-model="localContent.show_fee_plans"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="offeringShowFeePlans">
                        Show the price table above the form
                    </label>
                </div>
                <small class="form-text text-muted">
                    Turn this off for something that is free, or that has a single obvious price.
                    The amounts always come from the fee plans — they are never typed onto the page.
                </small>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { OfferingSectionContent } from '@/core/types/data/masjid-related/PageSection';
import { OfferingOption } from '@/core/types/data/masjid-related/Offering';
import { useOfferingDisplay } from '@/composables/useOfferingDisplay';
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
import { usePagesStore } from '@/stores/masjid/pagesStore';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * The `offering` page section: it points at one registerable thing and adds
 * page-level wording around it.
 *
 * WHAT THIS EDITOR DELIBERATELY CANNOT DO: type a price, a capacity, a
 * registration window or a question. All four live on the offering, its fee
 * plans and its intake form, and `SectionContentBinder` inlines them when the
 * site fetches the page. A price typed into a section's JSON would go stale the
 * moment a fee plan was replaced — fee plans are immutable and are replaced
 * rather than edited — and the page would then advertise one number while Stripe
 * charged another. Only `offering_id`, `title`, `intro`, `show_fee_plans`,
 * `button_text` and `background_color` are ever stored here.
 *
 * The editor's real job is therefore to WARN, about two different things.
 *
 * 1. THIS SECTION TYPE IS NOT DRAWN YET. The banner at the top is the server's
 *    own sentence (SectionType::rendererNote), printed verbatim here, in the
 *    palette and inside the type's description. It used to be printed in none
 *    of those places: this editor previewed the states the published block
 *    would show, the type's description promised "its fee plans, the places
 *    left, and the registration form", and the only surface telling the truth
 *    was a note on the Programs screen an admin need never open.
 *
 * 2. THE OFFERING ITSELF MAY BE UNABLE TO TAKE A REGISTRATION. A closed window,
 *    a sign-up form deleted out from under it, or no active fee plan for the
 *    register endpoint's `fee_plan_id`. That verdict is the SERVER's
 *    (`registration_state`, from App\Support\OfferingRegistrationState) and is
 *    the same one the public payload publishes and the offerings list renders —
 *    this editor used to compute its own, which is how four surfaces came to
 *    disagree about one program.
 */
const props = defineProps<{
    modelValue: OfferingSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: OfferingSectionContent];
}>();

const offeringsStore = useOfferingsStore();
const pagesStore = usePagesStore();

const {
    registrationStateLabel,
    registrationStateBadge,
    registrationStateHint,
    registrationStateIsFault
} = useOfferingDisplay();

const options = ref<OfferingOption[]>([]);
const loadingOptions = ref(false);
const optionsError = ref<string | null>(null);

/**
 * Default EVERY field. Content authored before a field existed must not blow up
 * a binding — the local idiom in components/sections/editors/CLAUDE.md.
 *
 * `offering` is NOT copied: it is server-supplied read-only data that the binder
 * adds when the page is served, and echoing it back would store a snapshot of a
 * price in the section — the exact thing this type exists to avoid.
 */
const normalize = (value?: OfferingSectionContent): OfferingSectionContent => ({
    offering_id: value?.offering_id ?? null,
    title: value?.title || '',
    intro: value?.intro || '',
    show_fee_plans: value?.show_fee_plans ?? true,
    button_text: value?.button_text || 'Register',
    background_color: value?.background_color || '#ffffff',
});

const localContent = ref<OfferingSectionContent>(normalize(props.modelValue));

watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    emit('update:modelValue', localContent.value);
};

/**
 * The tenant's own word for the concept — "Programs" for a masjid or a school,
 * "Services" for a community org — served by the offerings endpoints' `meta`
 * and never hardcoded (.claude/rules/verticals.md). Falls back only until the
 * first response lands.
 */
const offeringLabelSingular = computed(() => {
    const plural = offeringsStore.offeringsMeta?.offering_label || 'Programs';

    return plural.endsWith('s') ? plural.slice(0, -1) : plural;
});

const selected = computed<OfferingOption | null>(() => {
    if (!localContent.value.offering_id) return null;

    return options.value.find(o => o.id === localContent.value.offering_id) ?? null;
});

/**
 * The server's sentence about this type's missing public renderer, or null once
 * one exists. Read from the section-types payload rather than kept as a literal
 * here — a copy is how this editor came to promise a rendering that the
 * offerings screen was simultaneously warning did not exist.
 */
const rendererNote = computed<string | null>(
    () => pagesStore.sectionTypes.find(t => t.value === 'offering')?.renderer_note ?? null
);

/**
 * What the published block will actually do — the SERVER's `registration_state`,
 * spelled by the shared vocabulary in useOfferingDisplay.
 *
 * This block used to re-derive the verdict from is_open / is_full /
 * active_fee_plan_count. It was the only surface that got the fee-plan clause
 * right, and it was still a fourth copy of a judgement the write path owns: the
 * public payload, the offerings list and the offering detail header each had
 * their own, and all four disagreed. There is now one.
 */
const selectedStateLabel = computed(() => selected.value
    ? registrationStateLabel(selected.value.registration_state, selected.value.registration_state_reason)
    : '');

const selectedStateBadge = computed(() => selected.value
    ? registrationStateBadge(selected.value.registration_state, selected.value.registration_state_reason)
    : '');

const selectedStateHint = computed(() => selected.value
    ? registrationStateHint(selected.value.registration_state, selected.value.registration_state_reason)
    : '');

/** A misconfiguration rather than a decision — worth colouring the whole panel. */
const selectedIsFault = computed(() => !!selected.value
    && registrationStateIsFault(selected.value.registration_state, selected.value.registration_state_reason));

const onOfferingSelected = (value: string) => {
    localContent.value.offering_id = value ? Number(value) : null;
    emitUpdate();
};

onMounted(async () => {
    // The banner at the top of this editor must appear when it is opened from a
    // saved section too, not only through the palette that happens to have
    // loaded the list already. A caveat that shows on some entry paths and not
    // others is the same defect one layer down.
    if (pagesStore.sectionTypes.length === 0) {
        await pagesStore.fetchSectionTypes();
    }

    loadingOptions.value = true;
    optionsError.value = null;

    try {
        options.value = await offeringsStore.fetchOfferingOptions();
    } catch (error: any) {
        console.error('Fetch offering options error: ', error);

        // A 403 here is a real, distinct state: the CRM is off for this
        // organization, or this admin's role does not include programs. Saying
        // "you have none" would send them looking for a Create button they are
        // not allowed to use.
        optionsError.value = error?.response?.status === 403
            ? `You do not have access to ${offeringLabelSingular.value.toLowerCase()} records. Ask an administrator who does to attach one.`
            : 'Could not load the list. Close this dialog and try again.';
    } finally {
        loadingOptions.value = false;
    }
});
</script>

<style scoped>
.offering-section-editor .alert-info {
    background-color: #e7f3ff;
    border-color: #b3d9ff;
    color: #004085;
}
</style>
