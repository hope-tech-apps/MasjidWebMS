<template>
    <StudioPanel title="Links" note="The client's own donation page and social accounts, if they have them.">
        <div class="studio-field">
            <label for="studio-donation-link">Donation page</label>
            <input id="studio-donation-link" v-model.trim="identity.donation_link" type="url" maxlength="2048"
                placeholder="https://" class="dashboard-input" />
            <p v-if="donationLinkInvalid" class="studio-error">Start the address with https:// or http://.</p>
        </div>
        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-donation-title">Donation button title</label>
                <input id="studio-donation-title" v-model="identity.donation_title" type="text" maxlength="255" class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-donation-message">Donation button message</label>
                <input id="studio-donation-message" v-model="identity.donation_message" type="text" maxlength="255" class="dashboard-input" />
            </div>
        </div>
        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-facebook">Facebook</label>
                <input id="studio-facebook" v-model.trim="identity.facebook_url" type="url" maxlength="255" class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-youtube">YouTube</label>
                <input id="studio-youtube" v-model.trim="identity.youtube_url" type="url" maxlength="255" class="dashboard-input" />
            </div>
        </div>
        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-instagram">Instagram</label>
                <input id="studio-instagram" v-model.trim="identity.instagram_url" type="url" maxlength="255" class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-whatsapp-url">WhatsApp link</label>
                <input id="studio-whatsapp-url" v-model.trim="identity.whatsapp_url" type="url" maxlength="255" class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-whatsapp-number">WhatsApp number</label>
                <input id="studio-whatsapp-number" v-model.trim="identity.whatsapp_number" type="tel" maxlength="255" class="dashboard-input" />
            </div>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's Links panel. These keys live in the `identity` section on the
 * server (UpdateStudioDraftRequest::sectionRules), so they autosave with it;
 * the panel is separate only because the operator thinks of them apart.
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed } from 'vue';

const store = useStudioDraftStore();
const identity = computed(() => store.answers.identity);

/** The wizard's rule for the one link provisioning validates as a URL. */
const donationLinkInvalid = computed(() => {
    const link = identity.value.donation_link ?? '';
    return link !== '' && !/^https?:\/\//i.test(link);
});
</script>
