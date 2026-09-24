<template>
    <div class="d-flex flex-column gap-3">
        <ul class="results list-unstyled d-flex flex-column gap-3 mb-0">
            <li class="result-row">
                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                <div class="d-flex flex-column gap-1 min-w-0">
                    <span class="fw-semibold">{{ result.masjid.name }} created, organisation #{{ result.masjid_id }}</span>
                    <!-- Creating does not publish: masjids.listed_at starts NULL (OnboardingWizardView says the same). -->
                    <span class="small text-muted">
                        It is not listed in the apps' organisation picker yet. Publish it from the organisation's page
                        once it is ready to be seen.
                    </span>
                    <span>
                        <router-link :to="`/dashboard/super/masjids/${result.masjid_id}`" class="btn btn-sm btn-success">
                            Open the organisation
                        </router-link>
                    </span>
                </div>
            </li>

            <li v-if="applied" class="result-row">
                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                <div class="d-flex flex-column gap-1 min-w-0">
                    <span class="fw-semibold">
                        Features: {{ applied.changed.length }} changed from the defaults,
                        {{ applied.unchanged.length }} left as they start
                    </span>
                    <ul v-if="applied.changed.length" class="small mb-0 ps-3">
                        <li v-for="change in applied.changed" :key="change.key">
                            {{ featureLabel(change.key) }}: {{ change.enabled ? 'on' : 'off' }}
                        </li>
                    </ul>
                </div>
            </li>

            <li v-if="site" class="result-row">
                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                <div class="d-flex flex-column gap-1 min-w-0">
                    <!-- "switched on", not "live": nothing is served until the domain panel below confirms it (S11). -->
                    <span class="fw-semibold">
                        Website pages: {{ site.created.length }} created, {{ site.sections_active }}
                        {{ site.sections_active === 1 ? 'section' : 'sections' }} switched on
                    </span>
                    <span v-if="site.created.length" class="small">{{ site.created.map(pageTitle).join(', ') }}</span>
                    <span v-if="site.skipped.length" class="small text-muted">
                        Already there, left as they were: {{ site.skipped.map(pageTitle).join(', ') }}
                    </span>
                    <template v-if="site.sections_inactive.length">
                        <span class="small">
                            Written but not shown until they are filled in, in the page builder:
                        </span>
                        <ul class="small mb-0 ps-3">
                            <li v-for="section in site.sections_inactive" :key="`${section.page}-${section.slot}`">
                                <strong>{{ section.title }}</strong> on {{ pageTitle(section.page) }}<template
                                    v-if="section.hints.length">: {{ section.hints.join(' ') }}</template>
                            </li>
                        </ul>
                    </template>
                </div>
            </li>

            <li class="result-row">
                <i v-if="invite.sent" class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                <i v-else class="bi bi-exclamation-triangle-fill text-warning" aria-hidden="true"></i>
                <span :class="{ 'fw-semibold': invite.sent }">{{ invite.text }}</span>
            </li>

            <li v-if="warnings.length" class="result-row">
                <i class="bi bi-exclamation-triangle-fill text-warning" aria-hidden="true"></i>
                <div class="d-flex flex-column gap-1 min-w-0">
                    <span class="fw-semibold">Needs attention</span>
                    <ul class="small mb-0 ps-3">
                        <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
                    </ul>
                </div>
            </li>
        </ul>

        <StudioDomainAttachPanel v-if="hasWebAddress" :masjid-id="result.masjid_id" />
    </div>
</template>

<script setup lang="ts">
/**
 * What one provision created, straight from its 201 (docs/manara-studio-w1.md
 * S8). Every line restates the server's report and claims nothing more:
 *
 *  - the invitation is ticked only when the server says one went and none
 *    failed (core/studio/provision.ts inviteOutcome); otherwise the reason. It
 *    names the administrator the draft held when Provision was pressed
 *    (`invitee`), never the answers as they stand now;
 *  - website sections are "switched on", not live: the site is not served
 *    until the domain panel says so;
 *  - an after-commit step that failed is listed in the server's own words;
 *  - the web address is S7's panel for the new organisation, with Check now,
 *    and its "Open live site" is a link only once the server has seen the site
 *    answer on the host (R24). Until S11 a Studio site does not render, so the
 *    panel stays unconfirmed, which is the truth.
 *
 * Feature and page names are the catalogue's and the preset's; a key or slug
 * with none loaded is shown as it came.
 */
import StudioDomainAttachPanel from '@/components/super/studio/StudioDomainAttachPanel.vue';
import { Invitee, inviteOutcome } from '@/core/studio/provision';
import { StudioProvisionResult } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed } from 'vue';

const props = defineProps<{ result: StudioProvisionResult; invitee: Invitee }>();

const store = useStudioDraftStore();

const applied = computed(() => props.result.capabilities_applied ?? null);
const site = computed(() => props.result.starter_site ?? null);
const invite = computed(() => inviteOutcome(props.result.after_commit, props.invitee));
const warnings = computed(() => props.result.after_commit?.warnings ?? []);
const hasWebAddress = computed(() => !!props.result.web || (props.result.domains ?? []).length > 0);

function featureLabel(key: string): string {
    for (const group of store.catalogue?.groups ?? []) {
        const entry = group.entries.find((e) => e.key === key);
        if (entry) return entry.label;
    }
    return key;
}

function pageTitle(slug: string): string {
    const preset = store.presets?.find((p) => p.key === site.value?.preset);
    return preset?.pages.find((page) => page.slug === slug)?.title ?? slug;
}
</script>

<style scoped>
.result-row {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
}

.result-row > i {
    font-size: 1.1rem;
    line-height: 1.4;
}

.min-w-0 {
    min-width: 0;
}
</style>
