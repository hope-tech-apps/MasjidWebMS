<template>
    <section class="d-flex flex-column gap-3 w-100" aria-labelledby="studio-domains-title">
        <div class="d-flex flex-column gap-1">
            <h2 id="studio-domains-title" class="fs-5 fw-semibold mb-0">Web address</h2>
            <span v-if="panel && !panel.cloudflare.configured" class="small text-muted">
                Studio has no Cloudflare token yet, so it attaches nothing itself. Follow the steps under each
                address, then press Check now: Studio visits the address and marks it serving only when it
                answers for this organisation.
            </span>
            <span v-else-if="panel" class="small text-muted">
                Studio attaches new addresses through Cloudflare and checks them every five minutes.
                <template v-if="panel.cloudflare.pages_domains_used !== null">
                    {{ panel.cloudflare.pages_project }} has {{ panel.cloudflare.pages_domains_used }} of its
                    {{ panel.cloudflare.pages_domains_ceiling }} custom domains.
                </template>
            </span>
        </div>

        <div v-if="store.isLoading" class="text-muted small" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            Loading the web addresses…
        </div>

        <div v-else-if="!panel" class="alert alert-danger py-2 px-3 mb-0 d-flex flex-wrap align-items-center gap-2" role="alert">
            <span>
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                The web addresses could not be loaded. Nothing has changed.
            </span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="load">Try again</button>
        </div>

        <template v-else>
            <p v-if="panel.domains.length === 0" class="text-muted small mb-0">This organisation has no web address yet.</p>

            <div v-if="actionError" class="alert alert-danger py-2 px-3 mb-0 small" role="alert">
                <div>{{ actionError }}</div>
                <ol v-if="actionSteps.length" class="mb-0 mt-2 ps-3">
                    <li v-for="(step, index) in actionSteps" :key="index">{{ step }}</li>
                </ol>
            </div>

            <article v-for="domain in panel.domains" :key="domain.id" class="domain-row d-flex flex-column gap-2">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <!-- The host as text, never a link: until it is confirmed it may not
                         answer, answer with a certificate error, or answer for someone else. -->
                    <code class="fs-6 text-body">{{ domain.host }}</code>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0"
                        :aria-label="`Copy ${domain.host}`" @click="copyHost(domain)">
                        <i class="bi bi-clipboard me-1" aria-hidden="true"></i>{{ copied === domain.id ? 'Copied' : 'Copy' }}
                    </button>

                    <span v-if="isConfirmedServing(domain) && domain.status === 'active'" class="badge text-bg-success">
                        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Serving (verified by Cloudflare and confirmed by visiting it)
                    </span>
                    <span v-else-if="isConfirmedServing(domain)" class="badge text-bg-success">
                        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Serving (confirmed by visiting it; Cloudflare not checked)
                    </span>
                    <span v-else-if="domain.status === 'reserved'" class="badge text-bg-secondary">
                        Held for this organisation; not served
                    </span>
                    <span v-else-if="domain.status === 'failed'" class="badge text-bg-danger">Setting up failed</span>
                    <span v-else-if="!tokenConfigured" class="badge text-bg-warning">
                        Waiting for the Cloudflare token: nothing has been sent to Cloudflare
                    </span>
                    <span v-else class="badge text-bg-warning">{{ waitingLabel(domain) }}</span>

                    <span v-if="domain.source === 'imported'" class="badge text-bg-light border">Imported from the live map</span>
                </div>

                <p v-if="domain.last_error && domain.status !== 'failed'" class="small text-muted mb-0">
                    Last check: {{ domain.last_error }}
                </p>

                <!-- Case 3: the domain's zone is new to Cloudflare and waits on the registrar. -->
                <div v-if="domain.waiting_on === 'nameservers' && domain.nameservers?.length" class="alert alert-warning py-2 px-3 mb-0 small">
                    <div class="fw-semibold mb-1">Nameservers for {{ domain.zone_apex }}</div>
                    <ul class="mb-2 ps-3">
                        <li v-for="server in domain.nameservers" :key="server"><code>{{ server }}</code></li>
                    </ul>
                    <p class="mb-1">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        Changing nameservers moves <strong>all</strong> of {{ domain.zone_apex }}'s DNS to Cloudflare,
                        email (MX) included. Check the records Cloudflare imported before the registrar switches.
                    </p>
                    <p class="mb-0">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        Cloudflare deletes a zone left pending for 28 days.
                    </p>
                </div>

                <ol v-if="domain.manual_steps.length" class="small mb-0 ps-3">
                    <li v-for="(step, index) in domain.manual_steps" :key="index">{{ step }}</li>
                </ol>

                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary"
                        :disabled="store.busy !== null || domain.status === 'reserved'"
                        @click="checkNow(domain)">
                        <span v-if="store.busy === domain.id" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Check now
                    </button>

                    <a v-if="domain.live_url" class="btn btn-sm btn-primary" :href="domain.live_url" target="_blank" rel="noopener noreferrer">
                        Open live site
                    </a>
                    <button v-else type="button" class="btn btn-sm btn-primary" disabled
                        title="Available once Studio has seen the site answer on this address">
                        Open live site
                    </button>

                    <button v-if="domain.deletable" type="button" class="btn btn-sm btn-outline-danger"
                        :disabled="store.busy !== null" @click="removeDomain(domain)">
                        Remove
                    </button>
                </div>
            </article>
        </template>
    </section>
</template>

<script setup lang="ts">
/**
 * One organisation's web addresses in Manara Studio (W1, S7), for Step 3 (S8)
 * and any SuperAdmin screen about an organisation.
 *
 * It restates nothing the server decides. The green tick appears only for the
 * two states isConfirmedServing() names; `manual_steps` are shown as the
 * server wrote them; "Open live site" is a link only once `live_url` exists,
 * which the server sets only after its probe saw our site answer on the host
 * (R24). Without a token the amber badge says, in so many words, that nothing
 * has been sent to Cloudflare, because that is exactly the case.
 */
import { computed, onBeforeMount, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import { useMasjidDomainsStore } from '@/stores/super/masjidDomainsStore';
import { isConfirmedServing, MasjidDomain } from '@/core/types/data/MasjidDomain';

const props = defineProps<{ masjidId: number | string }>();

const store = useMasjidDomainsStore();
const panel = computed(() => store.panel);
const tokenConfigured = computed(() => panel.value?.cloudflare.configured ?? false);
const copied = ref<number | null>(null);
const actionError = ref('');
const actionSteps = ref<string[]>([]);

const load = async (): Promise<void> => {
    actionError.value = '';
    actionSteps.value = [];
    try {
        await store.list(props.masjidId);
    } catch {
        // `panel` stays null and the template says the list could not load.
    }
};

onBeforeMount(load);
watch(() => props.masjidId, load);

const waitingLabel = (domain: MasjidDomain): string => {
    switch (domain.waiting_on) {
        case 'token_scope': return 'Cloudflare refused the token for this step';
        case 'nameservers': return 'Waiting for the registrar to switch nameservers';
        case 'certificate': return 'Waiting for Cloudflare to issue the certificate';
        case 'capacity': return 'The Pages project is at its custom-domain limit';
        default: return domain.status === 'active' ? 'Attached; not yet seen serving' : 'Being attached';
    }
};

/** Clipboard write; says so when the browser refuses (insecure context, denied). */
const copyHost = async (domain: MasjidDomain): Promise<void> => {
    try {
        await navigator.clipboard.writeText(domain.host);
        copied.value = domain.id;
    } catch {
        copied.value = null;
        actionError.value = `Copying was refused by the browser. Select ${domain.host} and press Ctrl+C.`;
    }
};

const failureOf = (e: unknown, fallback: string): { message: string; steps: string[] } => {
    const data = (e as { response?: { data?: { message?: string; manual_steps?: string[] } } })?.response?.data;
    return {
        message: data?.message || fallback,
        steps: Array.isArray(data?.manual_steps) ? data.manual_steps : [],
    };
};

const checkNow = async (domain: MasjidDomain): Promise<void> => {
    actionError.value = '';
    actionSteps.value = [];
    try {
        await store.refresh(props.masjidId, domain.id);
    } catch (e) {
        actionError.value = failureOf(e, `Could not check ${domain.host}. Please try again.`).message;
    }
};

const removeDomain = async (domain: MasjidDomain): Promise<void> => {
    const confirmed = await Swal.fire({
        icon: 'warning',
        title: `Remove ${domain.host}?`,
        text: 'Studio forgets this address. Nothing was made for it in Cloudflare, so nothing there changes.',
        showCancelButton: true,
        confirmButtonText: 'Remove',
        cancelButtonText: 'Keep it',
    });

    if (!confirmed.isConfirmed) return;

    actionError.value = '';
    actionSteps.value = [];
    try {
        await store.remove(props.masjidId, domain.id);
    } catch (e) {
        const failure = failureOf(e, `Could not remove ${domain.host}.`);
        actionError.value = failure.message;
        actionSteps.value = failure.steps;
    }
};
</script>

<style scoped>
.domain-row {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
}
</style>
