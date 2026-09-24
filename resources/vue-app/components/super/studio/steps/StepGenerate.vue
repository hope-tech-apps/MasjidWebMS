<template>
    <section class="studio-generate d-flex flex-column gap-3" aria-labelledby="studio-generate-title">
        <header class="d-flex flex-column gap-1">
            <h5 id="studio-generate-title" class="fw-semibold mb-0" tabindex="-1">Generate</h5>
            <p v-if="!store.readOnly" class="studio-hint mb-0">
                Provision turns this draft into the organisation: its settings, features, brand, logo and icons,
                website pages and web address, all at once, and then emails an invitation to the administrator
                the draft names. It happens once; Studio cannot undo it.
            </p>
        </header>

        <!-- This tab provisioned it: the server's report. -->
        <StudioPanel v-if="outcome?.kind === 'created'" title="Created">
            <ProvisionResults :result="outcome.result" />
        </StudioPanel>

        <!-- Created, but the server did not confirm the feature choices (catalogue risk [2]). -->
        <div v-else-if="outcome?.kind === 'unconfirmed'" class="alert alert-danger d-flex flex-column gap-2 mb-0" role="alert">
            <span><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>{{ outcome.message }}</span>
            <span v-if="outcome.masjidId">
                <router-link :to="`/dashboard/super/masjids/${outcome.masjidId}`" class="btn btn-sm btn-outline-danger">
                    Open organisation #{{ outcome.masjidId }}
                </router-link>
            </span>
        </div>

        <!-- Provisioned before this visit, or by someone else first (a 409): what exists, never a retry. -->
        <StudioPanel v-else-if="store.readOnly" title="Already provisioned">
            <p v-if="outcome?.kind === 'conflict'" class="alert alert-warning mb-0" role="status">
                This draft had already been provisioned<template v-if="outcome.masjidId"> as organisation
                #{{ outcome.masjidId }}</template>. Nothing new was created.
            </p>
            <p class="mb-0">
                This draft is organisation #{{ masjidId }}. Whether its invitation went is reported only right after
                provisioning; if in doubt, send it again from the organisation's Team &amp; Access screen.
            </p>
            <span v-if="masjidId">
                <router-link :to="`/dashboard/super/masjids/${masjidId}`" class="btn btn-sm btn-success">
                    Open the organisation
                </router-link>
            </span>
            <StudioDomainAttachPanel v-if="masjidId && webChosen" :masjid-id="masjidId" />
        </StudioPanel>

        <template v-else>
            <StudioPanel title="Review" note="What will be created, as the draft is saved.">
                <ReviewGrid />
            </StudioPanel>

            <StudioPanel v-if="byo.length" title="Store credentials"
                note="Only for the platforms the client publishes under their own account.">
                <ByoCredentialsFields :secrets="secrets" :platforms="byo" :disabled="store.provisioning"
                    @update="updateSecret" />
            </StudioPanel>

            <div v-if="outcome?.kind === 'invalid'" class="alert alert-danger mb-0" role="alert">
                <div class="fw-semibold mb-1">Nothing was created. The server refused the draft:</div>
                <ul class="mb-0 ps-3">
                    <li v-for="message in outcome.messages" :key="message">{{ message }}</li>
                </ul>
            </div>
            <div v-else-if="outcome?.kind === 'failed'" class="alert alert-danger mb-0" role="alert">
                {{ outcome.message }}
            </div>

            <div class="d-flex flex-column gap-2">
                <ul v-if="blockers.length" class="provision-blockers small mb-0">
                    <li v-for="reason in blockers" :key="reason">{{ reason }}</li>
                </ul>
                <div>
                    <button type="button" class="btn btn-success" :disabled="!canProvision" :aria-busy="store.provisioning"
                        @click="provision">
                        <span v-if="store.provisioning" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        {{ store.provisioning ? 'Provisioning…' : 'Provision' }}
                    </button>
                </div>
            </div>
        </template>
    </section>
</template>

<script setup lang="ts">
/**
 * Step 3, Generate (docs/manara-studio-w1.md S8, R7, R24, R27).
 *
 * Before: the review of what will be created, the store credentials for each
 * "Bring your own" platform, and the Provision button. The button is disabled
 * while anything in core/studio/provision.ts generateBlockers() is missing
 * (above all the website's logo, subdomain and approved layout, R27), and from
 * the click until the answer arrives, so it cannot be sent twice.
 *
 * The credentials are this component's own `reactive`, never the store's
 * answers, so the autosave cannot see them (R7). They are handed to
 * store.provision(), which puts them in the provision body and nowhere else,
 * and blanked once an organisation exists. Leaving the page drops them.
 *
 * After: the server's report (ProvisionResults). A 201 without
 * `capabilities_applied` is an error, not success. A draft already
 * provisioned (reopened, or a 409) is read only and shows the organisation
 * that exists, and its web address, never a retry.
 */
import StudioDomainAttachPanel from '@/components/super/studio/StudioDomainAttachPanel.vue';
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import ByoCredentialsFields from '@/components/super/studio/generate/ByoCredentialsFields.vue';
import ProvisionResults from '@/components/super/studio/generate/ProvisionResults.vue';
import ReviewGrid from '@/components/super/studio/generate/ReviewGrid.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { foundationBlockers, webSelected } from '@/core/studio/foundationGate';
import { byoPlatforms, ByoPlatform, clearSecrets, emptySecrets, generateBlockers } from '@/core/studio/provision';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, onMounted, reactive } from 'vue';

const store = useStudioDraftStore();

const secrets = reactive(emptySecrets());

const outcome = computed(() => store.provisionOutcome);
const masjidId = computed(() => store.draft?.provisioned_masjid_id ?? null);
const webChosen = computed(() => webSelected(store.answers));
const byo = computed(() => byoPlatforms(store.answers));

const blockers = computed(() => [
    ...foundationBlockers(store.answers, !!store.draft?.logo),
    ...generateBlockers(store.answers, !!store.draft?.logo, secrets),
].filter((reason, index, all) => all.indexOf(reason) === index));

const canProvision = computed(() => !store.readOnly && !store.provisioning && blockers.value.length === 0);

/** One keystroke from the credential fields, into this component's copy only. */
function updateSecret(platform: ByoPlatform, field: string, value: string) {
    const target = secrets[platform] as Record<string, string>;
    if (field in target) target[field] = value;
}

async function provision() {
    if (!canProvision.value) return;

    const name = store.answers.identity.name?.trim() || 'this organisation';
    const answer = await QSwal.fire({
        icon: 'question',
        title: `Provision ${name}?`,
        text: 'This creates the organisation and invites the administrator the draft names. It cannot be undone from Studio.',
        confirmButtonText: 'Provision',
        cancelButtonText: 'Not yet',
    });
    if (!answer.isConfirmed || !canProvision.value) return;

    const result = await store.provision(secrets);
    if (result && result.kind !== 'invalid' && result.kind !== 'failed') {
        clearSecrets(secrets);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

onMounted(() => {
    // The review names the features and the layout by the server's own labels;
    // either list may not be here when a draft is opened straight on this step.
    const orgType = store.answers.identity.org_type;
    if (!orgType) return;
    if (!store.catalogue || store.catalogue.org_type !== orgType) void store.fetchCatalogue(orgType);
    if (!store.presets && webChosen.value) void store.fetchPresets(orgType);
});
</script>

<style scoped>
.studio-hint {
    color: #6c757d;
    font-size: .85rem;
}

.provision-blockers {
    color: #a02622;
    padding-left: 1.1rem;
}
</style>
