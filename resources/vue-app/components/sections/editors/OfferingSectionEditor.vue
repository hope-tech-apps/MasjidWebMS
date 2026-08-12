<template>
    <div class="offering-section-editor">
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
                    <option v-for="option in options" :key="option.id" :value="option.id">
                        {{ option.name }} ({{ option.slug }})
                        <template v-if="!option.is_open"> · not open</template>
                        <template v-else-if="option.is_full"> · full, waitlisting</template>
                        <template v-if="option.active_fee_plan_count === 0"> · no fee plan</template>
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

                <div v-else class="alert alert-info mb-0">
                    <h6 class="alert-heading">
                        <i class="bi bi-ui-checks me-2"></i>{{ selected.name }}
                    </h6>

                    <p class="mb-1">
                        <strong>Registration:</strong>
                        <span :class="stateClass">{{ stateLabel }}</span>
                    </p>

                    <p class="mb-0">
                        <strong>Fee plans:</strong>
                        {{ selected.active_fee_plan_count }} active
                    </p>

                    <!--
                        The one misconfiguration an admin cannot see from the name. The public
                        register endpoint takes a fee_plan_id, so with no ACTIVE plan the page
                        renders and every submission is refused.
                    -->
                    <hr v-if="selected.active_fee_plan_count === 0" />
                    <p v-if="selected.active_fee_plan_count === 0" class="mb-0">
                        <i class="bi bi-cash-coin me-1"></i>
                        <strong>No active fee plan.</strong> Nobody can register until this
                        {{ offeringLabelSingular.toLowerCase() }} has one — even a free one. Add it
                        under its Fee Plans tab.
                    </p>
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
import { useOfferingsStore } from '@/stores/masjid/offeringsStore';
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
 * The editor's real job is therefore to WARN. An offering can be attached and
 * look perfectly fine while being unable to take a single registration — a
 * closed window, or no active fee plan for the register endpoint's
 * `fee_plan_id`. Both are surfaced at the moment of attaching, because the
 * alternative is finding out from a family who could not sign up.
 */
const props = defineProps<{
    modelValue: OfferingSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: OfferingSectionContent];
}>();

const offeringsStore = useOfferingsStore();

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
 * What the published block will actually do. Mirrors the server's
 * `registration_state`, which is what the renderer switches on: a FULL offering
 * still accepts sign-ups — they are waitlisted, not refused — so "full" must not
 * read as "closed" to the admin either.
 */
const stateLabel = computed(() => {
    const option = selected.value;
    if (!option) return '';

    if (!option.is_open) {
        if (option.closed_reason === 'not_yet_open') return 'Opens later — the block will say so';
        if (option.closed_reason === 'closed') return 'Window closed — the block will say so';

        return 'Switched off — the block renders nothing';
    }

    return option.is_full
        ? 'Full — sign-ups join the waitlist'
        : 'Open for registration';
});

const stateClass = computed(() => {
    const option = selected.value;
    if (!option) return '';

    if (!option.is_open) return 'text-danger';

    return option.is_full ? 'text-warning-emphasis' : 'text-success';
});

const onOfferingSelected = (value: string) => {
    localContent.value.offering_id = value ? Number(value) : null;
    emitUpdate();
};

onMounted(async () => {
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
