<template>
    <fieldset class="step-foundation" :disabled="!store.editable">
        <IdentityPanel @slug-check="slugCheck = $event" />
        <PrayerPanel v-if="asksPrayer(store.answers)" />
        <BrandPanel />
        <AboutPanel />
        <LinksPanel />
        <PlatformsPanel />
        <DomainPanel :managed="slugCheck" />
    </fieldset>
</template>

<script setup lang="ts">
/**
 * Step 0, Foundation: who the client is, how they look, and what they get
 * (docs/manara-studio-w1.md S5). Seven panels over the store's answers; each
 * edits its own section and the store autosaves it. A provisioned draft is
 * shown read-only, since it is the record of what Step 3 created.
 *
 * The Identity panel's live address check is kept here and handed to the
 * Domain panel, so the address is checked once and shown in both places.
 */
import AboutPanel from '@/components/super/studio/foundation/AboutPanel.vue';
import BrandPanel from '@/components/super/studio/foundation/BrandPanel.vue';
import DomainPanel from '@/components/super/studio/foundation/DomainPanel.vue';
import IdentityPanel from '@/components/super/studio/foundation/IdentityPanel.vue';
import LinksPanel from '@/components/super/studio/foundation/LinksPanel.vue';
import PlatformsPanel from '@/components/super/studio/foundation/PlatformsPanel.vue';
import PrayerPanel from '@/components/super/studio/foundation/PrayerPanel.vue';
import { asksPrayer } from '@/core/studio/foundationGate';
import { StudioSlugCheck } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { ref } from 'vue';

const store = useStudioDraftStore();

const slugCheck = ref<StudioSlugCheck>({ state: 'none', host: null, takenBy: null, message: null });
</script>

<style scoped>
.step-foundation {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
</style>
