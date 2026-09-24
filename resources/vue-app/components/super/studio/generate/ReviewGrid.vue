<template>
    <div class="review-grid">
        <div v-for="item in items" :key="item.label" class="review-item">
            <span>{{ item.label }}</span>
            <strong class="text-break">{{ item.value || '—' }}</strong>
        </div>

        <div class="review-item">
            <span>Platforms</span>
            <span v-if="platforms.length" class="d-flex flex-wrap gap-1 mt-1">
                <span v-for="platform in platforms" :key="platform.slug" class="review-pill">
                    {{ platform.label }}<template v-if="platform.mode"> · {{ platform.mode }}</template>
                </span>
            </span>
            <strong v-else>—</strong>
        </div>

        <div class="review-item">
            <span>Brand</span>
            <span class="d-flex align-items-center gap-2 mt-1">
                <img v-if="store.logoUrl" :src="store.logoUrl" alt="The client's logo" class="review-logo" />
                <span v-else-if="!store.draft?.logo" class="small text-muted">No logo</span>
                <span v-for="key in BRAND_COLOUR_KEYS" :key="key" class="mini-swatch"
                    :style="{ backgroundColor: store.answers.brand[key] ?? undefined }"
                    :title="store.answers.brand[key] ?? undefined"></span>
            </span>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * What Step 3 is about to create, read back from the draft as it is saved
 * (docs/manara-studio-w1.md S8, from the wizard's review at
 * OnboardingWizardView :443-477). Nothing here is decided: every value is an
 * answer, or a label the server served for one (the organisation type and
 * prayer settings from /onboarding/options, the layout's name from the
 * presets, the website address from the preview, which derives it the way
 * provisioning will). An answer not given shows as a dash.
 */
import { asksPrayer, BRAND_COLOUR_KEYS, webSelected } from '@/core/studio/foundationGate';
import { accountModeLabel, PLATFORM_OPTIONS } from '@/core/studio/platforms';
import { featureSummary, IqamaStatus, iqamaStatus } from '@/core/studio/provision';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, ref, watch } from 'vue';

/** What the organisation will show, in the order of provision.ts iqamaStatus (a partial set is also a blocker). */
const IQAMA_REVIEW: Record<IqamaStatus['state'], string> = {
    not_asked: '',
    not_given: 'Not given: hidden until the client gives them',
    none: 'None entered: hidden until the client gives them',
    partial: 'Incomplete: all five are needed to show them',
    given: 'Given: shown',
};

const store = useStudioDraftStore();
const identity = computed(() => store.answers.identity);

const cityName = ref('');

function optionLabel(options: { value: string; label: string }[] | undefined, value: string | null | undefined): string {
    if (!value) return '';
    return options?.find((option) => option.value === value)?.label ?? value;
}

// The city list is per country and not part of the options, so it is fetched
// for the one country the draft names.
watch(() => [identity.value.country_id, identity.value.city_id] as const, async ([countryId, cityId]) => {
    cityName.value = '';
    if (!countryId || !cityId) return;
    const cities = await store.fetchCities(countryId);
    if (identity.value.country_id === countryId && identity.value.city_id === cityId) {
        cityName.value = cities.find((city) => city.id === cityId)?.name ?? '';
    }
}, { immediate: true });

const items = computed(() => {
    const answers = store.answers;
    const options = store.options;
    const vertical = options?.verticals.find((v) => v.org_type === identity.value.org_type);
    const country = options?.countries.find((c) => c.id === identity.value.country_id)?.name ?? '';
    const admin = identity.value.admin;

    const rows: { label: string; value: string }[] = [
        { label: 'Organisation type', value: vertical?.label ?? identity.value.org_type ?? '' },
        { label: 'Name', value: identity.value.name ?? '' },
        { label: 'Email', value: identity.value.email ?? '' },
        { label: 'Phone', value: identity.value.phone ?? '' },
        { label: 'Address', value: identity.value.address ?? '' },
        { label: 'Location', value: [country, cityName.value].filter(Boolean).join(' / ') },
        { label: 'Timezone', value: identity.value.timezone ?? '' },
        {
            label: 'Administrator',
            value: identity.value.user_id
                ? `Existing account #${identity.value.user_id}`
                : [admin?.name, admin?.email].filter(Boolean).join(', '),
        },
        { label: 'Description (published)', value: identity.value.description ?? '' },
    ];

    if (asksPrayer(answers)) {
        rows.push(
            { label: 'Prayer method', value: optionLabel(options?.prayer.methods, answers.prayer.method) },
            { label: 'Madhab', value: optionLabel(options?.prayer.madhabs, answers.prayer.madhab) },
            { label: 'Iqama times', value: IQAMA_REVIEW[iqamaStatus(answers, true).state] },
        );
    }

    const features = featureSummary(answers.features.capabilities, store.catalogue);
    rows.push({
        label: 'Features',
        value: features.total
            ? `${features.on} of ${features.total} on` + (features.departures === null ? '' : `, ${features.departures} changed from the defaults`)
            : '',
    });

    if (webSelected(answers)) {
        const preset = store.presets?.find((p) => p.key === answers.layout.preset);
        const approved = answers.layout.approved_at ? new Date(answers.layout.approved_at) : null;
        rows.push(
            { label: 'Website address', value: store.preview?.org.host ?? '' },
            { label: 'Subdomain', value: identity.value.slug ?? '' },
            {
                label: 'Layout',
                value: answers.layout.preset
                    ? `${preset?.label ?? answers.layout.preset}${approved && !isNaN(approved.getTime()) ? `, approved ${approved.toLocaleDateString()}` : ', not approved'}`
                    : '',
            },
        );
    }

    return rows;
});

const platforms = computed(() => {
    const chosen = store.answers.platforms.platforms ?? [];
    const apps = store.answers.platforms.apps ?? {};
    return PLATFORM_OPTIONS.filter((option) => chosen.includes(option.slug)).map((option) => ({
        slug: option.slug,
        label: option.label,
        // tvOS ships under the iOS account and has no mode of its own.
        mode: option.slug === 'tvos' ? '' : accountModeLabel(apps[option.slug]?.account_mode),
    }));
});
</script>

<style scoped>
.review-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
    gap: .75rem;
}

.review-item {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--input-border, #eee);
    border-radius: .4rem;
    padding: .5rem .75rem;
}

.review-item > span:first-child {
    font-size: .75rem;
    color: #6c757d;
    text-transform: uppercase;
}

.review-pill {
    border: 1px solid var(--cgreen, #01b151);
    border-radius: 2rem;
    padding: .1rem .6rem;
    font-size: .8rem;
}

.mini-swatch {
    display: inline-block;
    width: 1.1rem;
    height: 1.1rem;
    border-radius: .2rem;
    border: 1px solid #ddd;
}

.review-logo {
    max-height: 2rem;
    max-width: 5rem;
    object-fit: contain;
}
</style>
