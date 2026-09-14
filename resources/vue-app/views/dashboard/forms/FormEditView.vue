<template>
    <PageDataContainer :title="formId ? 'Edit form' : 'Create a form'" :hideButton="true">
        <template #headerButtons>
            <router-link :to="responsesLink" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                Back to Form Responses
            </router-link>
        </template>

        <div class="container w-100">
            <div v-if="invalidId" class="alert alert-warning" role="alert">
                That form address is not valid.
                <router-link to="/masjid/form-responses">Back to Form Responses</router-link>
            </div>

            <!--
                The router guard covers a navigation; this covers a hard refresh, where the
                organisation loads after the guard has already let the route through. The
                server's any-of gate on form writes refuses the save either way.
            -->
            <div v-else-if="refused" class="alert alert-info" role="alert">
                Editing sign-up forms is not switched on for {{ orgName }}. Ask Manara to switch on
                “Edit sign-up forms” for this organisation.
            </div>

            <template v-else>
                <p v-if="formId" class="small text-muted">
                    A form that is taking responses changes for everyone the moment you save,
                    including people filling it in right now. Answers already collected are kept.
                </p>
                <p v-else-if="webPagesAllowed" class="small text-muted">
                    A new form collects nothing until it is placed on a page: add a
                    <strong>Form</strong> section in Web Pages Management and choose it there.
                </p>
                <!-- No Web Pages Management here (website off, or no web_pages grant): name who places it. -->
                <p v-else class="small text-muted">
                    A new form collects nothing until Manara places it on a page for {{ orgName }}.
                </p>

                <!-- Keyed, so moving between two forms' edit links starts a clean draft. -->
                <FormBuilder
                    :key="formId ?? 'new'"
                    :form-id="formId"
                    @saved="onSaved"
                    @cancel="onCancel"
                />
            </template>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import PageDataContainer from '@/components/PageDataContainer.vue';
import FormBuilder from '@/components/forms/FormBuilder.vue';
import { canEditForms, canUseWebPages } from '@/core/access/orgAccess';
import { Form } from '@/core/types/data/masjid-related/Form';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { computed } from 'vue';
import { RouteLocationRaw, useRoute, useRouter } from 'vue-router';

/**
 * A sign-up form's questions, fees and settings, edited outside the page builder.
 *
 * It mounts the same self-contained FormBuilder the page builder's Form section
 * mounts (formId in, saved / cancel out), so there is one editor with one set of
 * rules. Reached from Form Responses; there is no sidebar item.
 *
 * NO delete button, on purpose. FormsController::destroy guards only the forms an
 * offering takes registrations through, not the pages a form is placed on, so a
 * delete from here would blank a live page. To stop a form taking responses, use
 * the builder's own "accepting responses" switch.
 */

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const masjidStore = useMasjidStore();

const orgName = computed(() => masjidStore.masjid?.name || 'this organisation');

/** Null on forms/new; the numeric id on forms/:formId/edit. */
const formId = computed<number | null>(() => {
    if (route.name === 'masjid.formCreate') return null;
    const id = Number(route.params.formId);
    return Number.isInteger(id) && id > 0 ? id : null;
});

const invalidId = computed(() => route.name === 'masjid.formEdit' && formId.value === null);

// Only once the organisation has loaded: before that, every grant reads as missing.
const refused = computed(() => !!masjidStore.masjid && !canEditForms(authStore.user?.type, masjidStore.masjid));

/** Whether the new-form help may send this person to Web Pages Management. */
const webPagesAllowed = computed(() => canUseWebPages(authStore.user?.type, masjidStore.masjid));

const responsesLink = computed<RouteLocationRaw>(() => ({
    name: 'masjid.formResponses',
    query: formId.value ? { form: String(formId.value) } : {},
}));

// FormBuilder shows its own "Saved!" toast; land on the form's responses.
const onSaved = (form: Form) => {
    router.push({
        name: 'masjid.formResponses',
        query: form?.id ? { form: String(form.id) } : {},
    });
};

const onCancel = () => {
    // Back where the admin came from when there is somewhere to go back to; a link
    // opened in a new tab has no history, so it goes to Form Responses instead.
    if (window.history.state?.back) {
        router.back();
    } else {
        router.push(responsesLink.value);
    }
};
</script>
