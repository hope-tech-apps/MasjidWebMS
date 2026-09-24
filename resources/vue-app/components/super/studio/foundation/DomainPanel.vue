<template>
    <StudioPanel title="Domain" note="Where the website will answer.">
        <div class="studio-field">
            <span class="studio-label">Manara address</span>
            <p v-if="managed.state === 'none'" class="studio-hint">Choose the website address in Identity.</p>
            <p v-else-if="managed.state === 'checking'" class="studio-hint">Checking…</p>
            <p v-else-if="managed.state === 'invalid'" class="studio-error">{{ managed.message }}</p>
            <template v-else>
                <span class="host">{{ managed.host }}</span>
                <p v-if="managed.state === 'taken'" class="studio-error">
                    Already held by organisation #{{ managed.takenBy }}. Choose another address in Identity.
                </p>
                <p v-else class="studio-hint">Free. The site goes live here first.</p>
            </template>
        </div>

        <div class="studio-field">
            <span class="studio-label">The client's own domain <span class="text-muted fw-normal">(optional)</span></span>
            <div class="d-flex flex-column flex-md-row gap-3">
                <div class="studio-field w-100">
                    <label for="studio-custom-host">Host</label>
                    <input id="studio-custom-host" :value="custom.host ?? ''" type="text" maxlength="253" placeholder="www.example.org"
                        autocapitalize="off" spellcheck="false" class="dashboard-input" :disabled="!store.editable"
                        @input="setCustom('host', $event)" />
                </div>
                <div class="studio-field w-100">
                    <label for="studio-zone-apex">Zone</label>
                    <input id="studio-zone-apex" :value="custom.zone_apex ?? ''" type="text" maxlength="253" placeholder="example.org"
                        autocapitalize="off" spellcheck="false" class="dashboard-input" :disabled="!store.editable"
                        @input="setCustom('zone_apex', $event)" />
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" :disabled="!canCheck || checking" @click="checkCustom">
                    {{ checking ? 'Checking…' : 'Check' }}
                </button>
                <span v-if="customResult?.ok && customResult.data.available" class="studio-hint text-success">
                    {{ customResult.data.host }} is free.
                </span>
                <span v-else-if="customResult?.ok" class="studio-error">
                    {{ customResult.data.host }} already belongs to organisation #{{ customResult.data.taken_by_masjid_id }}.
                </span>
                <span v-else-if="customResult" class="studio-error">{{ customResult.message }}</span>
            </div>
            <p class="studio-hint">The zone is the domain the host sits in, as it is set up on Cloudflare.</p>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's Domain panel. The Manara address is the slug from Identity,
 * shown as the host the server derives from it (StudioDomainCheckRequest adds
 * the managed suffix; this screen never spells the suffix itself). The
 * client's own domain is optional and stored as `domain.custom`; it is checked
 * on request through the same endpoint, which validates the host against its
 * zone exactly as provisioning will.
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { StudioDomainCheck, StudioSlugCheck } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, ref, watch } from 'vue';

defineProps<{ managed: StudioSlugCheck }>();

const store = useStudioDraftStore();
const custom = computed(() => store.answers.domain.custom ?? {});

const checking = ref(false);
const customResult = ref<{ ok: true; data: StudioDomainCheck } | { ok: false; message: string } | null>(null);

const canCheck = computed(() => !!(custom.value.host ?? '').trim() && !!(custom.value.zone_apex ?? '').trim());

function setCustom(key: 'host' | 'zone_apex', event: Event) {
    const value = (event.target as HTMLInputElement).value.trim();
    store.answers.domain.custom = { ...(store.answers.domain.custom ?? {}), [key]: value };
}

// A result describes the host it was asked about; an edit makes it stale.
watch(() => [custom.value.host, custom.value.zone_apex], () => { customResult.value = null; });

async function checkCustom() {
    checking.value = true;
    customResult.value = await store.checkDomain({
        kind: 'custom',
        host: (custom.value.host ?? '').trim(),
        zone_apex: (custom.value.zone_apex ?? '').trim(),
    });
    checking.value = false;
}
</script>

<style scoped>
.host {
    font-family: monospace;
    font-size: .95rem;
    word-break: break-all;
}
</style>
