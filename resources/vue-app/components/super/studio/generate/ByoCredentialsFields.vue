<template>
    <div class="d-flex flex-column gap-3">
        <div v-if="platforms.includes('ios')" class="platform-card">
            <span class="fw-semibold">iOS, the client's App Store Connect account</span>
            <div class="studio-field">
                <label for="studio-byo-ios-p8">App Store Connect API key (.p8 contents) <span class="req">*</span></label>
                <textarea id="studio-byo-ios-p8" :value="secrets.ios.asc_key_p8" rows="4" class="dashboard-input secret-input"
                    autocomplete="off" spellcheck="false" :disabled="disabled"
                    placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
                    @input="set('ios', 'asc_key_p8', $event)"></textarea>
            </div>
            <div class="d-flex flex-column flex-md-row gap-3">
                <div class="studio-field w-100">
                    <label for="studio-byo-ios-key">Key ID <span class="req">*</span></label>
                    <input id="studio-byo-ios-key" :value="secrets.ios.asc_key_id" type="text" class="dashboard-input"
                        autocomplete="off" spellcheck="false" :disabled="disabled" @input="set('ios', 'asc_key_id', $event)" />
                </div>
                <div class="studio-field w-100">
                    <label for="studio-byo-ios-issuer">Issuer ID <span class="req">*</span></label>
                    <input id="studio-byo-ios-issuer" :value="secrets.ios.asc_issuer_id" type="text" class="dashboard-input"
                        autocomplete="off" spellcheck="false" :disabled="disabled" @input="set('ios', 'asc_issuer_id', $event)" />
                </div>
            </div>
        </div>

        <div v-if="platforms.includes('android')" class="platform-card">
            <span class="fw-semibold">Android, the client's Google Play account</span>
            <div class="studio-field">
                <label for="studio-byo-android-json">Play service-account JSON <span class="req">*</span></label>
                <textarea id="studio-byo-android-json" :value="secrets.android.play_service_account_json" rows="5"
                    class="dashboard-input secret-input" autocomplete="off" spellcheck="false" :disabled="disabled"
                    placeholder='{ "type": "service_account", ... }'
                    @input="set('android', 'play_service_account_json', $event)"></textarea>
            </div>
        </div>

        <p class="studio-hint">
            Sent only when you press Provision, and stored encrypted with the organisation's app settings. They are
            never saved in this draft: leaving this page forgets them.
        </p>
    </div>
</template>

<script setup lang="ts">
/**
 * The BYO store credentials, typed at Step 3 (docs/manara-studio-w1.md S8, R7).
 *
 * They live only in StepGenerate's memory and leave the browser only in the
 * provision body (core/studio/provision.ts provisionBody). This component owns
 * no copy: it shows what it is given and reports each keystroke upward, so
 * there is one place the values are, and it is not the draft store, whose
 * answers are autosaved. The fields are the wizard's (OnboardingWizardView,
 * the Apps step), for the selected platforms set to "Bring your own" only.
 */
import type { ByoPlatform, ProvisionSecrets } from '@/core/studio/provision';

defineProps<{ secrets: ProvisionSecrets; platforms: ByoPlatform[]; disabled?: boolean }>();

const emit = defineEmits<{ (event: 'update', platform: ByoPlatform, field: string, value: string): void }>();

function set(platform: ByoPlatform, field: string, event: Event) {
    emit('update', platform, field, (event.target as HTMLInputElement | HTMLTextAreaElement).value);
}
</script>

<style scoped>
.platform-card {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: .75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
}

.secret-input {
    font-family: monospace;
    font-size: .8rem;
}
</style>
