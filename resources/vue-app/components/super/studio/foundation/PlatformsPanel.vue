<template>
    <StudioPanel title="Platforms" note="What the client gets. Store credentials are entered at Generate and never saved in the draft.">
        <div class="studio-field">
            <span class="studio-label">Platforms <span class="req">*</span></span>
            <div class="d-flex flex-wrap gap-2">
                <label v-for="option in PLATFORM_OPTIONS" :key="option.slug" class="mode-pill"
                    :class="{ selected: chosen.includes(option.slug), 'pill-disabled': option.slug === 'tvos' && !chosen.includes('ios') }">
                    <input type="checkbox" :checked="chosen.includes(option.slug)"
                        :disabled="store.readOnly || (option.slug === 'tvos' && !chosen.includes('ios'))"
                        @change="toggle(option.slug, ($event.target as HTMLInputElement).checked)" />
                    {{ option.label }}
                </label>
            </div>
            <p class="studio-hint">tvOS ships under the iOS Apple account, so it needs iOS.</p>
        </div>

        <div v-for="app in accountApps" :key="app.slug" class="platform-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="fw-semibold">{{ app.title }}</span>
                <div class="d-flex gap-2" role="radiogroup" :aria-label="`${app.title} account`">
                    <label v-for="mode in ACCOUNT_MODES" :key="mode.value" class="mode-pill"
                        :class="{ selected: accountMode(app.slug) === mode.value }">
                        <input type="radio" :name="`studio-account-${app.slug}`" :value="mode.value"
                            :checked="accountMode(app.slug) === mode.value" :disabled="store.readOnly"
                            @change="setAccountMode(app.slug, mode.value)" />
                        {{ mode.label }}
                    </label>
                </div>
            </div>
            <p v-if="accountMode(app.slug) === 'byo'" class="studio-hint mb-0">
                The client publishes under their own account. Their credentials are asked for at Generate.
            </p>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's Platforms panel: the wizard's platform picker and account modes
 * (OnboardingWizardView, the Apps step), without its credential fields. Store
 * credentials are typed at Step 3 and go only in the provision body (R7); the
 * server refuses a draft save that carries one at any depth.
 *
 * Managed is where a new platform starts, as in the wizard. tvOS needs iOS, and
 * the same watch the wizard keeps drops tvOS whenever iOS is taken away.
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { PLATFORM_OPTIONS } from '@/core/studio/platforms';
import { StudioAccountMode, StudioPlatform } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, watch } from 'vue';

type AccountApp = 'ios' | 'android' | 'web';

const ACCOUNT_APPS: { slug: AccountApp; title: string }[] = [
    { slug: 'ios', title: 'iOS, Apple App Store' },
    { slug: 'android', title: 'Android, Google Play' },
    { slug: 'web', title: 'Web' },
];

const ACCOUNT_MODES: { value: StudioAccountMode; label: string }[] = [
    { value: 'managed', label: 'Managed' },
    { value: 'byo', label: 'Bring your own' },
];

const store = useStudioDraftStore();
const section = computed(() => store.answers.platforms);
const chosen = computed<StudioPlatform[]>(() => section.value.platforms ?? []);
const accountApps = computed(() => ACCOUNT_APPS.filter((app) => chosen.value.includes(app.slug)));

function accountMode(app: AccountApp): StudioAccountMode | null {
    return section.value.apps?.[app]?.account_mode ?? null;
}

function setAccountMode(app: AccountApp, mode: StudioAccountMode) {
    section.value.apps = { ...(section.value.apps ?? {}), [app]: { account_mode: mode } };
}

function toggle(slug: StudioPlatform, on: boolean) {
    const next = chosen.value.filter((platform) => platform !== slug);
    if (on) next.push(slug);
    // In the order the options are listed, so the saved list does not depend on click order.
    section.value.platforms = PLATFORM_OPTIONS.map((option) => option.slug).filter((platform) => next.includes(platform));

    if (on && slug !== 'tvos' && !accountMode(slug)) {
        setAccountMode(slug, 'managed');
    }
}

// tvOS cannot ship without iOS (OnboardingWizardView's watch, kept as it was).
watch(() => chosen.value.includes('ios'), (iosSelected) => {
    if (!iosSelected && chosen.value.includes('tvos')) {
        section.value.platforms = chosen.value.filter((platform) => platform !== 'tvos');
    }
});
</script>

<style scoped>
.platform-card {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: .75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
}
</style>
