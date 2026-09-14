<template>
    <DataItemContainer title="Masjid Details"
        @edit-button-click="router.push(`/dashboard/super/masjids/${route.params.masjid_id}/edit`)" @delete-button-click="deleteMasjid"
        @archive-button-click="archiveMasjid">
        <div v-if="masjid" class="d-flex flex-column gap-5">
            <!-- Masjid Profile -->
            <div v-if="masjid" class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Main Profile
                </span>
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-start
                gap-5 w-100">
                    <div class="logo-container">
                        <img :src="masjid.logo?.original_url" alt="masjid-logo" class="logo">
                    </div>

                    <div class="d-flex flex-wrap gap-4 info-container">
                        <div v-for="key in PROFILE_ATTRIBUTES" class="d-flex flex-column gap-1">
                            <span class="fs-6 text-capitalize">
                                {{ key }}
                            </span>
                            <span class="fs-6 fw-semibold text-muted">
                                {{ masjid[key as keyof Masjid] }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Masjid Location Details -->
            <div v-if="masjid" class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Location Details
                </span>
                <div v-for="key in LOCATION_ATTRIBUTES" class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        {{ key }}
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid[key as keyof Masjid] }}
                    </span>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        Country
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid?.country?.name }}
                    </span>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        City
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid?.city?.name }}
                    </span>
                </div>
            </div>

            <!-- Masjid Admin Details -->
            <div v-if="masjid.admin" class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Admin Details
                </span>
                <div v-if="masjid?.admin?.avatar" class="admin-logo-container">
                    <img :src="masjid?.admin?.avatar?.original_url" alt="masjid-admin-avatar" class="admin-logo">
                </div>
                <div v-for="key in ADMIN_ATTRIBUTES" class="d-flex flex-column flex-sm-row gap-1 w-100">
                    <span class="fs-6 text-capitalize info-attribute">
                        {{ key }}
                    </span>
                    <span class="fs-6 fw-semibold text-muted w-100">
                        {{ masjid.admin[key as keyof Admin] }}
                    </span>
                </div>
            </div>

            <!-- Public directory listing (SuperAdmin-only; masjids.listed_at).
                 Creating an organization no longer publishes it, so this is how
                 one is put in front of app users when it is actually ready. -->
            <div class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    App Directory Listing
                </span>
                <div class="d-flex align-items-center gap-3 w-100">
                    <span class="fs-6 fw-semibold text-muted">
                        Show this organization in the mobile app's organization picker.
                        Off means it exists and its admins can work on it, but nobody can find it in the app.
                    </span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input bg-danger" type="checkbox"
                            @click.prevent="toggleDirectoryListing(!masjid.listed_at)"
                            :checked="masjid.listed_at ? true : false" />
                    </div>
                </div>
            </div>

            <!-- CRM Access (SuperAdmin-only screen; toggles the per-masjid CRM gate) -->
            <div class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    CRM Access
                </span>
                <div class="d-flex align-items-center gap-3 w-100">
                    <span class="fs-6 fw-semibold text-muted">
                        Enable the CRM (Member Directory, funds &amp; donations) for this masjid.
                    </span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input bg-danger" type="checkbox"
                            @click.prevent="toggleCrmAccess(!masjid.crm_enabled)"
                            :checked="masjid.crm_enabled ? true : false" />
                    </div>
                </div>
            </div>

            <!-- Manara Assistant (SuperAdmin-only; toggles the per-masjid AI assistant gate) -->
            <div class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Manara Assistant
                </span>
                <div class="d-flex align-items-center gap-3 w-100">
                    <span class="fs-6 fw-semibold text-muted">
                        Let this masjid's admins use the AI assistant to manage their content.
                        It can only do what those admins are already permitted to do.
                    </span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input bg-danger" type="checkbox"
                            @click.prevent="toggleAssistantAccess(!masjid.assistant_enabled)"
                            :checked="masjid.assistant_enabled ? true : false" />
                    </div>
                </div>
            </div>

            <!-- Organisation capabilities (SuperAdmin-only; config/capabilities.php).
                 Layer 1 of the access model: what this organisation HAS, grants and
                 default-on modules alike, grouped, with defaults and change history.
                 The CRM and Assistant switches above stay the only writers of the two
                 column-backed members of the same catalogue. -->
            <OrganisationSwitchesPanel :masjid="masjid" @updated="onSwitchesUpdated" />

            <!--
                Form card payments through the parent organisation (SuperAdmin-only;
                DECISIONS.md 2026-09-15). Shown only for an organisation with a parent,
                because the link must equal parent_id. It moves where FORM card payments
                land and nothing else. No Stripe account id is read or shown here.
            -->
            <div v-if="parentId" class="d-flex flex-column gap-2 w-100">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <span class="fs-5 fw-semibold">
                        Form card payments
                    </span>
                    <span v-if="!formsCard.loading && formsCard.account" class="badge"
                        :class="formsCardReady ? 'bg-success' : 'bg-secondary'">
                        {{ formsCardReady ? 'Can take card payments' : 'Card payments refused' }}
                    </span>
                </div>

                <span class="fs-6 text-muted">
                    A program of {{ parentLabel }} can take card payments on its forms through
                    {{ parentLabel }}'s existing Stripe account instead of connecting its own. Donations,
                    lunch orders and every other payment for this organisation are not affected.
                </span>

                <div v-if="formsCard.loading" class="fs-6 text-muted">
                    Checking…
                </div>

                <template v-else>
                    <span class="fs-6 fw-semibold">
                        <template v-if="formsCardLinked">
                            Card payments on this organisation's forms go through {{ formsCardHolderName }}'s Stripe account.
                        </template>
                        <template v-else-if="formsCard.account?.state === 'own'">
                            Card payments on this organisation's forms go to its own Stripe account.
                        </template>
                        <template v-else-if="formsCard.account">
                            This organisation's forms cannot take card payments: it has no ready Stripe account of its
                            own and is not linked to {{ parentLabel }}.
                        </template>
                    </span>

                    <div v-if="formsCardProblem" class="alert alert-warning py-2 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Card payment on forms is refused right now, because {{ formsCardProblem }}.
                    </div>

                    <div v-if="formsCardLinked && linkFields.forms_card_via_set_at" class="fs-6 text-muted">
                        Linked {{ formatLinkDate(linkFields.forms_card_via_set_at) }}.
                    </div>

                    <div v-if="formsCard.loadError" class="fs-6 text-muted">
                        {{ formsCard.loadError }}
                    </div>

                    <div>
                        <button v-if="!formsCardLinked" type="button" class="btn btn-sm btn-primary"
                            :disabled="formsCard.saving || !formsCard.parentName || !!linkBlockedReason"
                            :aria-describedby="linkBlockedReason && formsCard.parentName ? 'forms-card-link-blocked' : undefined"
                            @click="openLinkDialog">
                            Charge through {{ parentLabel }}
                        </button>
                        <button v-else type="button" class="btn btn-sm btn-outline-danger"
                            :disabled="formsCard.saving" @click="unlinkFormsCard">
                            <span v-if="formsCard.saving" class="spinner-border spinner-border-sm me-2"></span>
                            Stop charging through {{ formsCardHolderName }}
                        </button>
                    </div>

                    <div v-if="!formsCardLinked && formsCard.parentName && linkBlockedReason"
                        id="forms-card-link-blocked" class="fs-6 text-muted">
                        This cannot be linked: {{ linkBlockedReason }}
                    </div>
                </template>

                <!-- The confirm dialog: what linking means, in plain words, then the parent's
                     name typed exactly and where the consent is recorded. -->
                <Teleport to="body">
                    <div v-if="linkDialog.open" ref="linkDialogRoot" class="modal fade show d-block" role="dialog"
                        aria-modal="true" aria-labelledby="forms-card-link-title" tabindex="-1"
                        style="background: rgba(0,0,0,0.5);" @click.self="closeLinkDialog"
                        @keydown="onLinkDialogKeydown">
                        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 id="forms-card-link-title" class="modal-title">
                                        Charge {{ childLabel }}'s form card payments through {{ parentLabel }}?
                                    </h5>
                                    <button type="button" class="btn-close" aria-label="Close without linking"
                                        :disabled="formsCard.saving" @click="closeLinkDialog"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="mb-2">This is what it means:</p>
                                    <ul class="mb-3">
                                        <li class="mb-1">
                                            <strong>Card payments on {{ childLabel }}'s forms land in {{ parentLabel }}'s
                                                Stripe account</strong>, not in an account of {{ childLabel }}'s own.
                                            {{ parentLabel }} is the business the family pays.
                                        </li>
                                        <li class="mb-1">
                                            <strong>Everyone who can see {{ parentLabel }}'s Stripe account sees these
                                                payments</strong>: the family's email address, the amount and what they paid for.
                                        </li>
                                        <li class="mb-1">
                                            <strong>Refunds and disputes are handled in {{ parentLabel }}'s Stripe
                                                dashboard.</strong> {{ childLabel }}'s admins cannot refund a card payment from
                                            Manara, and a dispute counts against {{ parentLabel }}'s account.
                                        </li>
                                        <li class="mb-1">
                                            <strong>Families' card statements show {{ parentLabel }}</strong>, with a short
                                            tag for {{ childLabel }} after it.
                                        </li>
                                        <li class="mb-1">
                                            Donations, lunch orders and every other payment for {{ childLabel }} stay as they
                                            are. Only its forms take card payments this way.
                                        </li>
                                    </ul>
                                    <p class="small text-muted mb-3">
                                        Only do this when {{ childLabel }} is a program of {{ parentLabel }} (the same legal
                                        organisation) and {{ parentLabel }} has agreed.
                                        <!-- The holder's Stop button needs the `crm` gate and manage donations
                                             (routes/admin.php, connect group); without CRM it never renders. -->
                                        <template v-if="formsCard.parentCrmEnabled">
                                            {{ parentLabel }}'s admins who manage donations can stop it at any time from
                                            their Stripe settings (the Giving Dashboard, or {{ parentDetailsTitle }} › Online
                                            payments when Giving is switched off), and a Manara super admin can remove it here.
                                        </template>
                                        <template v-else>
                                            {{ parentLabel }} does not use Manara's CRM, so its admins cannot stop it from
                                            Manara themselves: a Manara super admin can remove it here.
                                        </template>
                                    </p>

                                    <div class="mb-3">
                                        <label class="form-label fs-6" for="forms-card-typed-name">
                                            Type <strong>{{ formsCard.parentName }}</strong> to confirm
                                        </label>
                                        <input id="forms-card-typed-name" v-model="linkDialog.typedName" type="text"
                                            class="form-control" autocomplete="off" spellcheck="false"
                                            :disabled="formsCard.saving" />
                                        <div v-if="linkDialog.typedName && !typedNameMatches" class="form-text text-danger">
                                            This must match the name exactly, including capitals and spaces.
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label fs-6" for="forms-card-consent">
                                            Where is {{ parentLabel }}'s agreement recorded?
                                        </label>
                                        <textarea id="forms-card-consent" v-model="linkDialog.consent" class="form-control"
                                            rows="3" :maxlength="FORMS_CARD_CONSENT_MAX" :disabled="formsCard.saving"
                                            placeholder="For example: the owner's decision of 2026-09-13, recorded in DECISIONS.md"></textarea>
                                        <div class="form-text">
                                            Kept in the link's permanent history. {{ linkDialog.consent.length }} of
                                            {{ FORMS_CARD_CONSENT_MAX }} characters.
                                        </div>
                                    </div>

                                    <div v-if="linkDialog.error" class="alert alert-danger py-2 mb-0" role="alert">
                                        {{ linkDialog.error }}
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" :disabled="formsCard.saving"
                                        @click="closeLinkDialog">
                                        Cancel
                                    </button>
                                    <button type="button" class="btn btn-danger" :disabled="!canConfirmLink"
                                        @click="confirmLink">
                                        <span v-if="formsCard.saving" class="spinner-border spinner-border-sm me-2"></span>
                                        Charge through {{ parentLabel }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Teleport>
            </div>

            <!--
                Text messaging (SMS) — the A2P 10DLC registration OUTCOME.

                Not a capability toggle, which is why it does not live in the
                loop above: those say what an organisation may USE, this records
                what the carriers decided. It is SuperAdmin-only for the reason
                .claude/rules/broadcasts.md names — a masjid admin who could
                declare their own sender "approved" would be putting
                unregistered traffic on the carrier network in the platform's
                name, and the refusal that protects them from that is the whole
                mechanism.

                Everything the panel asserts about sending comes off the wire.
                `can_send` and `refusal_reason` are MasjidSmsSender::canSend()
                and ::refusalReason() — the same two methods the sending path
                calls — so this screen and a failed delivery row say the same
                sentence rather than two that drift.
            -->
            <div class="d-flex flex-column gap-3 w-100">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <span class="fs-5 fw-semibold">
                        Text messaging (SMS)
                    </span>
                    <span v-if="smsPanel?.sender" class="badge bg-secondary">
                        {{ SMS_SENDER_STATUS_LABELS[smsPanel.sender.registration_status]
                            ?? smsPanel.sender.registration_status }}
                    </span>
                    <span v-if="smsPanel" class="badge" :class="smsSendBadgeClass">
                        {{ smsPanel.can_send ? 'Can send' : 'Cannot send' }}
                    </span>
                </div>

                <span class="fs-6 text-muted">
                    Carriers require each organization to register its own A2P 10DLC brand, campaign and
                    sending number before it may send bulk texts. There is no shared number. Record what the
                    carriers approved here — this does not perform the registration.
                </span>

                <div v-if="smsSenderStore.isLoading" class="fs-6 text-muted">
                    Loading sender…
                </div>

                <template v-else-if="smsPanel">
                    <!--
                        PLATFORM-level, and louder than the tenant refusal
                        because it is not this organisation's problem to fix: no
                        provider credentials means nobody sends, however well
                        registered they are.
                    -->
                    <div v-if="smsPanel.provider_configured === false" class="alert alert-danger py-2 mb-0">
                        <i class="bi bi-exclamation-octagon me-1"></i>
                        No SMS provider is configured on this deployment, so no organization can send —
                        approved or not.
                    </div>

                    <!-- The server's own refusal sentence, verbatim. -->
                    <div v-if="smsPanel.refusal_reason" class="alert alert-warning py-2 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        {{ smsPanel.refusal_reason }}
                    </div>

                    <div v-if="smsPanel.sender?.approved_at" class="fs-6 text-muted">
                        Approved {{ formatSmsDate(smsPanel.sender.approved_at) }}.
                    </div>

                    <!--
                        Which provider account this deployment sends through.
                        Platform-level and read-only: it is not a per-tenant
                        choice, and there is no field for it below because an
                        operator changing it here would be pointing one
                        organisation at credentials that do not exist.
                    -->
                    <div v-if="smsPanel.provider" class="fs-6 text-muted">
                        Provider on this deployment: <span class="fw-semibold">{{ smsPanel.provider }}</span>.
                    </div>

                    <form class="d-flex flex-column gap-3 w-100" @submit.prevent="saveSmsSender">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fs-6" for="sms-phone-number">Sending number</label>
                                <input id="sms-phone-number" type="text" class="form-control"
                                    v-model.trim="smsForm.phone_number" placeholder="+16135550142"
                                    :disabled="smsSenderStore.isSaving" />
                                <div class="form-text">
                                    Full international form. Inbound STOP messages are matched to this
                                    organization by this number, so it must be exact.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-6" for="sms-messaging-service">Messaging Service SID</label>
                                <input id="sms-messaging-service" type="text" class="form-control"
                                    v-model.trim="smsForm.messaging_service_sid" placeholder="MG…"
                                    :disabled="smsSenderStore.isSaving" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-6" for="sms-sender-label">Sender label</label>
                                <input id="sms-sender-label" type="text" class="form-control"
                                    v-model.trim="smsForm.sender_label" :disabled="smsSenderStore.isSaving" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-6" for="sms-registration-status">Registration status</label>
                                <select id="sms-registration-status" class="form-select"
                                    v-model="smsForm.registration_status" :disabled="smsSenderStore.isSaving">
                                    <option v-for="option in SMS_SENDER_STATUS_OPTIONS" :key="option.value"
                                        :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-6" for="sms-brand-id">Brand registration id</label>
                                <input id="sms-brand-id" type="text" class="form-control"
                                    v-model.trim="smsForm.brand_registration_id" :disabled="smsSenderStore.isSaving" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-6" for="sms-campaign-id">Campaign registration id</label>
                                <input id="sms-campaign-id" type="text" class="form-control"
                                    v-model.trim="smsForm.campaign_registration_id"
                                    :disabled="smsSenderStore.isSaving" />
                            </div>
                            <div class="col-12">
                                <label class="form-label fs-6" for="sms-notes">Notes</label>
                                <textarea id="sms-notes" class="form-control" rows="2" v-model.trim="smsForm.notes"
                                    :disabled="smsSenderStore.isSaving"></textarea>
                            </div>
                        </div>

                        <div>
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="smsSenderStore.isSaving">
                                <span v-if="smsSenderStore.isSaving"
                                    class="spinner-border spinner-border-sm me-2"></span>
                                {{ smsSenderStore.isSaving ? 'Saving…' : 'Save sender' }}
                            </button>
                        </div>
                    </form>
                </template>

                <span v-else class="fs-6 text-muted">
                    The sender could not be loaded for this organization.
                </span>
            </div>

            <!-- Layer 2: who can sign in to this organisation and what each can do. -->
            <div class="d-flex flex-column gap-2 w-100">
                <span class="fs-5 fw-semibold">
                    Team &amp; Access
                </span>
                <div class="d-flex flex-wrap align-items-center gap-3 w-100">
                    <span class="fs-6 fw-semibold text-muted">
                        Who can sign in to this organisation, and what each person can do.
                    </span>
                    <button type="button" class="btn btn-sm btn-success" @click="openTeam">Open Team &amp; Access</button>
                </div>
            </div>

            <!-- Generate Apps (SuperAdmin-only; dispatches the provisioning pipeline) -->
            <div class="d-flex flex-column gap-3 w-100">
                <span class="fs-5 fw-semibold">
                    Generate Apps
                </span>
                <span class="fs-6 text-muted">
                    Dispatch the build pipeline to scaffold, build, and upload this masjid's
                    mobile apps. Pick the platforms and start — progress appears below.
                </span>

                <div class="d-flex flex-wrap align-items-center gap-4">
                    <div class="form-check m-0 d-flex align-items-center gap-2">
                        <input class="form-check-input gen-check m-0" type="checkbox" id="gen-ios"
                            v-model="platforms.ios" :disabled="generating" />
                        <label class="form-check-label fs-6" for="gen-ios">iOS</label>
                    </div>
                    <div class="form-check m-0 d-flex align-items-center gap-2">
                        <input class="form-check-input gen-check m-0" type="checkbox" id="gen-android"
                            v-model="platforms.android" :disabled="generating" />
                        <label class="form-check-label fs-6" for="gen-android">Android</label>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm"
                        :disabled="generating || (!platforms.ios && !platforms.android)"
                        @click="generateApps">
                        <span v-if="generating" class="spinner-border spinner-border-sm me-2"></span>
                        {{ generating ? 'Dispatching…' : 'Generate Apps' }}
                    </button>
                </div>

                <!-- Live status list (polled every few seconds). -->
                <div v-if="jobs.length" class="d-flex flex-column gap-2 w-100">
                    <div v-for="job in jobs" :key="job.job_id"
                        class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center
                        justify-content-between gap-2 job-row">
                        <div class="d-flex align-items-center gap-3">
                            <span class="fw-semibold text-capitalize job-platform">
                                {{ job.platform }}
                            </span>
                            <span class="badge" :class="statusBadgeClass(job.status)">
                                {{ job.status }}
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <span v-if="job.detail" class="fs-6 text-muted job-detail">
                                {{ job.detail }}
                            </span>
                            <a v-if="job.artifact_url" :href="job.artifact_url" target="_blank"
                                rel="noopener noreferrer" class="fs-6">
                                View artifact
                            </a>
                        </div>
                    </div>
                </div>
                <span v-else class="fs-6 text-muted">
                    No provisioning jobs yet for this masjid.
                </span>
            </div>

        </div>
    </DataItemContainer>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import DataItemContainer from '@/components/DataItemContainer.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { Admin } from '@/core/types/data/Admin';
import { Masjid } from '@/core/types/data/Masjid';
import OrganisationSwitchesPanel from '@/components/super/OrganisationSwitchesPanel.vue';
import { useMasjidStore } from '@/stores/masjidStore';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { useSmsSenderStore } from '@/stores/super/smsSenderStore';
import {
    SMS_SENDER_STATUS_LABELS,
    SMS_SENDER_STATUS_OPTIONS,
    SmsSenderPayload,
    SmsSenderStatus,
} from '@/core/types/data/masjid-related/SmsSender';
import { useConnectStore } from '@/stores/masjid/connectStore';
import {
    FORMS_CARD_CONSENT_MAX,
    FormsCardAccount,
    FormsCardVia,
    formsCardProblemText,
} from '@/core/types/data/masjid-related/StripeConnect';
import { serverMessage } from '@/core/helpers/serverMessage';
import { trapTab } from '@/core/helpers/focusTrap';
import { detailsScreenTitle } from '@/core/access/orgAccess';
import { MASJID_TERMINOLOGY, Terminology, Vertical } from '@/core/types/data/Vertical';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { computed, nextTick, onBeforeMount, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Lifecycle hooks
onBeforeMount(async () => {
    if (route.params.masjid_id) {
        masjidsStore.fetchMasjid(route.params.masjid_id as string, masjid);
        // Load any existing provisioning jobs and resume polling if some are
        // still in flight (e.g. a build started in a previous session).
        await fetchProvisioningJobs();
        if (hasActiveJobs()) startPolling();
        await loadSmsSender();
    } else {
        router.push('/dashboard/super/masjids');
    }
});

// Stop the poll timer when leaving the screen so it never leaks.
onBeforeUnmount(() => stopPolling());

// Routing
const router = useRouter();
const route = useRoute();

// Stores
const masjidsStore = useMasjidsStore();
const smsSenderStore = useSmsSenderStore();

// Computed

// Custom constants
const masjid = ref<Masjid>();
const PROFILE_ATTRIBUTES = ['name', 'email', 'phone'];
const LOCATION_ATTRIBUTES = ['longitude', 'latitude', 'address'];
const ADMIN_ATTRIBUTES = ['name', 'email', 'phone', 'type'];

// ---- App provisioning (control plane) ----
type ProvisioningJob = {
    id: number;
    job_id: string;
    platform: 'ios' | 'android';
    status: string;
    detail: string | null;
    artifact_url: string | null;
    github_repo: string;
    created_at: string;
    updated_at: string;
};

// Platform selection for the "Generate Apps" action.
const platforms = ref<{ ios: boolean; android: boolean }>({ ios: false, android: false });
const generating = ref(false);
const jobs = ref<ProvisioningJob[]>([]);
// Statuses with no further updates coming — polling can stop once every job
// reaches one of these. (uploaded/built = success terminals, failed = failure.)
const TERMINAL_STATUSES = ['uploaded', 'built', 'failed'];
let pollTimer: ReturnType<typeof setInterval> | null = null;

// Functions
// const getAttributeValues = (key: keyof Zikr, masjid: Zikr) => {
//     let text = '';
//     if (typeof masjid[key] === 'object') {
//         let obj = masjid[key] as TranslatableObject;
//         if (obj) {
//             text = `AR: ${obj.ar}<br />`;
//             text += `EN: ${obj.en}`;
//         }
//     } else {
//         text = masjid[key] + '';
//     }

//     return text;
// }

const deleteMasjid = async () => {
    QSwal.fire("Warning", 'You are going to delete this masjid !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjid.value.id}/`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Masjid deleted successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await masjidsStore.fetchMasjidsList().finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/dashboard/super/masjids`);
                                });
                            });
                        });
                }
            }
        })
}

const archiveMasjid = async () => {
    QSwal.fire("Warning", 'You are going to archive this masjid !', 'warning')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {
                    await ApiService.delete(`/api/admin/masjids/${masjid.value.id}/trash`)
                        .then(res => {
                            if (res.data.status === 'success') {
                                swalInstance.title = "Success";
                                swalInstance.text = "Masjid archived successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(async () => {
                            await masjidsStore.fetchMasjidsList().finally(() => {
                                MSwal.fire(swalInstance).then(async () => {
                                    await router.push(`/dashboard/super/masjids`);
                                });
                            });
                        });
                }
            }
        })
}

/**
 * Publish / unpublish this organization in the mobile app's public directory
 * (PATCH .../directory-listing -> masjids.listed_at).
 *
 * The server owns the timestamp; the response carries the saved masjid, so the
 * locally loaded copy is refreshed from it rather than from a guess.
 */
const toggleDirectoryListing = (listed: boolean) => {
    QSwal.fire(
        "Question",
        listed
            ? "List this organization in the mobile app's directory? App users will be able to find it."
            : "Remove this organization from the mobile app's directory? New app users will no longer find it.",
        'question'
    )
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {

                    const apiRequestData = new URLSearchParams();
                    apiRequestData.append('listed', listed ? "1" : "0");

                    await ApiService.patch(`/api/admin/masjids/${masjid.value.id}/directory-listing`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                if (masjid.value) masjid.value.listed_at = res.data.data?.listed_at ?? null;
                                swalInstance.title = "Success";
                                swalInstance.text = listed
                                    ? "Organization listed in the app directory."
                                    : "Organization removed from the app directory.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(() => {
                            MSwal.fire(swalInstance);
                        });
                } else {
                    MSwal.fire('Sorry', 'The masjid ID missed.', 'error');
                }
            }
        })
}

const toggleCrmAccess = (enabled: boolean) => {
    QSwal.fire("Question", "Are you sure that you want to change CRM access for this masjid?", 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {

                    const apiRequestData = new URLSearchParams();
                    apiRequestData.append('enabled', enabled ? "1" : "0");

                    await ApiService.patch(`/api/admin/masjids/${masjid.value.id}/crm-access`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                // Reflect the new gate value on the locally loaded masjid.
                                if (masjid.value) masjid.value.crm_enabled = enabled;
                                swalInstance.title = "Success";
                                swalInstance.text = "CRM access updated successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(() => {
                            MSwal.fire(swalInstance);
                        });
                } else {
                    MSwal.fire('Sorry', 'The masjid ID missed.', 'error');
                }
            }
        })
}

/**
 * A switch in OrganisationSwitchesPanel was saved: keep this screen's copy of the
 * organisation in step with the server's, so the panel's fallback rows (and anything
 * else here that reads `capabilities`) show what was just saved.
 */
const onSwitchesUpdated = (saved: { capabilities?: Masjid['capabilities']; modules_off?: Masjid['modules_off']; modules_on?: Masjid['modules_on'] }) => {
    if (!masjid.value) return;
    if (saved.capabilities) masjid.value.capabilities = saved.capabilities;
    if (saved.modules_off) masjid.value.modules_off = saved.modules_off;
    if (saved.modules_on) masjid.value.modules_on = saved.modules_on;
}

// Enter this organisation's dashboard on its Team & Access screen — the same way
// the Masjids list enters a dashboard.
const openTeam = async () => {
    if (!masjid.value?.id) return;
    const id = masjid.value.id;
    const orgStore = useMasjidStore();
    const auth = useAuthStore();
    await orgStore.fetchMasjid(id).finally(async () => {
        auth.saveDashboardMasjidId(id);
        await router.push('/masjid/team');
    });
}

const toggleAssistantAccess = (enabled: boolean) => {
    QSwal.fire("Question", "Are you sure that you want to change Manara Assistant access for this masjid?", 'question')
        .then(async (result) => {
            if (result.isConfirmed) {

                let swalInstance: SweetAlertOptions = {
                    title: "Info",
                    text: "Nothing",
                    icon: "info"
                };

                if (masjid.value?.id) {

                    const apiRequestData = new URLSearchParams();
                    apiRequestData.append('enabled', enabled ? "1" : "0");

                    await ApiService.patch(`/api/admin/masjids/${masjid.value.id}/assistant-access`, apiRequestData)
                        .then(res => {
                            if (res.data.status === 'success') {
                                if (masjid.value) masjid.value.assistant_enabled = enabled;
                                swalInstance.title = "Success";
                                swalInstance.text = "Manara Assistant access updated successfully.";
                                swalInstance.icon = "success";
                            } else {
                                swalInstance.title = "Sorry";
                                swalInstance.text = getMessageFromObj(res);
                                swalInstance.icon = "warning";
                            }
                        })
                        .catch((e: AxiosError<BackendResponseData>) => {
                            console.log(e);
                            swalInstance.title = e.message;
                            swalInstance.text = getMessageFromObj(e);
                            swalInstance.icon = "error";
                        })
                        .finally(() => {
                            MSwal.fire(swalInstance);
                        });
                } else {
                    MSwal.fire('Sorry', 'The masjid ID missed.', 'error');
                }
            }
        })
}

// ---- Form card payments through the parent organisation (DECISIONS.md 2026-09-15) ----
//
// SuperAdmin-only. A child program organisation's FORM card payments may go through its
// parent's existing Stripe account. The PATCH answers a non-SuperAdmin with 403 and no
// validation keys, and 422s unless the target is the parent, onboarded and not linked
// itself; both are shown as the server wrote them.
//
// Account ids: the admin show of this organisation and of its parent (GET masjids/{id})
// return the whole masjids row, stripe_account_id included. The page is SuperAdmin-only,
// so that shows nobody more than they could already read. This panel keeps only booleans
// derived from those columns (has its own account, can take charges) and never stores or
// renders an id. The booleans only disable the link button with a reason; the PATCH's
// refusal stays the authority.

/** The admin show returns the whole masjids row; Masjid.ts does not type these columns. */
type MasjidFormsCardFields = {
    parent_id?: number | null;
    forms_card_via_masjid_id?: number | null;
    forms_card_via_set_at?: string | null;
    stripe_account_id?: string | null;
    stripe_charges_enabled?: boolean | null;
};

type FormsCardPanel = {
    loading: boolean;
    saving: boolean;
    /** The parent's name, read from its own admin record. '' until read. */
    parentName: string;
    /**
     * Facts about the parent from that same record, null until read. crmEnabled decides
     * whether its admins can stop the link themselves (the revoke route is in the `crm`
     * group); chargeReady mirrors FormChargeAccount's holder rules (acct_ id, charges on);
     * linked is the parent's own link, which the server refuses as holder_linked.
     */
    parentCrmEnabled: boolean | null;
    parentChargeReady: boolean | null;
    parentLinked: boolean | null;
    /** The parent's own words (its vertical pack), for naming its Details screen; null until read. */
    parentTerminology: Terminology | null;
    /** GET forms/card-account for this organisation, or null when it could not be read. */
    account: FormsCardAccount | null;
    /** The link as the server last described it, or null when not linked. */
    via: FormsCardVia | null;
    loadError: string;
};

const connectStore = useConnectStore();

const linkFields = computed<MasjidFormsCardFields>(() => (masjid.value ?? {}) as MasjidFormsCardFields);
const parentId = computed<number | null>(() => linkFields.value.parent_id ?? null);

const formsCard = ref<FormsCardPanel>({
    loading: false, saving: false, parentName: '',
    parentCrmEnabled: null, parentChargeReady: null, parentLinked: null, parentTerminology: null,
    account: null, via: null, loadError: ''
});

/** Names with a neutral stand-in, so no sentence on the screen has a hole in it. */
const parentLabel = computed(() => formsCard.value.parentName || 'its parent organisation');
/** The parent's Details screen as its own sidebar names it ("Masjid Details"); the masjid pack until read. */
const parentDetailsTitle = computed(() => detailsScreenTitle(
    key => formsCard.value.parentTerminology?.[key] || MASJID_TERMINOLOGY[key]));
const childLabel = computed(() => masjid.value?.name || 'this organisation');

const formsCardLinked = computed(() => formsCard.value.via !== null);
const formsCardHolderName = computed(() => formsCard.value.via?.holder.name || parentLabel.value);

/** The server's answer (forms/card-account), never re-derived here. */
const formsCardReady = computed(() =>
    formsCard.value.account?.state === 'own' || formsCard.value.account?.state === 'linked');

const formsCardProblem = computed(() => formsCardProblemText(
    formsCardLinked.value
        ? formsCard.value.via?.problem
        : (formsCard.value.account?.state === 'unavailable' ? formsCard.value.account.problem : null)
));

const formatLinkDate = (iso: string): string => {
    const d = new Date(iso);
    return isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

/**
 * The link as the card-account read describes it. A link on the row that the read could not
 * confirm still shows as a link (not ready), so the Unlink button is never hidden from it.
 */
const viaFromAccount = (account: FormsCardAccount | null): FormsCardVia | null => {
    if (account?.holder) {
        return { holder: account.holder, ready: account.state === 'linked', problem: account.problem };
    }

    const linkedTo = linkFields.value.forms_card_via_masjid_id ?? null;
    if (linkedTo === null) return null;

    const name = linkedTo === parentId.value && formsCard.value.parentName
        ? formsCard.value.parentName
        : `organisation #${linkedTo}`;

    return { holder: { id: linkedTo, name }, ready: false, problem: account?.problem ?? null };
};

const loadFormsCard = async (): Promise<void> => {
    const childId = masjid.value?.id;
    const parent = parentId.value;
    if (!childId || !parent) return;

    formsCard.value.loading = true;
    formsCard.value.loadError = '';
    formsCard.value.parentCrmEnabled = null;
    formsCard.value.parentChargeReady = null;
    formsCard.value.parentLinked = null;
    formsCard.value.parentTerminology = null;

    const [parentRead, accountRead] = await Promise.allSettled([
        ApiService.get(`/api/admin/masjids/${parent}/`),
        connectStore.fetchFormsCardAccount(childId),
    ]);

    if (parentRead.status === 'fulfilled'
        && parentRead.value.data?.status === 'success'
        && typeof parentRead.value.data?.data?.name === 'string') {
        const row = parentRead.value.data.data as MasjidFormsCardFields & { name: string; crm_enabled?: boolean | null; vertical?: Vertical };

        formsCard.value.parentName = row.name;
        formsCard.value.parentCrmEnabled = row.crm_enabled === true;
        formsCard.value.parentTerminology = row.vertical?.terminology ?? null;
        // Booleans only: the id itself is never kept (see the note at the top of this section).
        formsCard.value.parentChargeReady = typeof row.stripe_account_id === 'string'
            && row.stripe_account_id.length > 5
            && row.stripe_account_id.startsWith('acct_')
            && row.stripe_charges_enabled === true;
        formsCard.value.parentLinked = row.forms_card_via_masjid_id !== null && row.forms_card_via_masjid_id !== undefined;
    }

    const account = accountRead.status === 'fulfilled' ? accountRead.value : null;
    formsCard.value.account = account;
    formsCard.value.via = viaFromAccount(account);

    if (!formsCard.value.parentName) {
        formsCard.value.loadError = 'The parent organisation could not be read, so this cannot be linked from here right now.';
    } else if (accountRead.status === 'rejected') {
        formsCard.value.loadError = serverMessage(accountRead.reason,
            'Whether this organisation can take card payments on its forms could not be checked just now.');
    }

    formsCard.value.loading = false;
};

// The masjid loads without being awaited, so the panel loads when it (or its link) arrives.
watch(
    () => [masjid.value?.id, parentId.value, linkFields.value.forms_card_via_masjid_id],
    () => { loadFormsCard(); },
    { immediate: true }
);

/** Put the server's answer on screen, and on the loaded row (which re-reads the panel). */
const applyFormsCardResult = (via: FormsCardVia | null): void => {
    formsCard.value.via = via;
    if (masjid.value) {
        (masjid.value as Masjid & MasjidFormsCardFields).forms_card_via_masjid_id = via?.holder.id ?? null;
    }
};

type LinkDialogState = { open: boolean; typedName: string; consent: string; error: string };

const linkDialog = ref<LinkDialogState>({ open: false, typedName: '', consent: '', error: '' });
const linkDialogRoot = ref<HTMLElement | null>(null);
let linkDialogReturnFocus: HTMLElement | null = null;

/** Exactly, as the server compares it: no trimming, no case folding. */
const typedNameMatches = computed(() =>
    !!formsCard.value.parentName && linkDialog.value.typedName === formsCard.value.parentName);

const canConfirmLink = computed(() =>
    typedNameMatches.value
    && linkDialog.value.consent.trim() !== ''
    && linkDialog.value.consent.length <= FORMS_CARD_CONSENT_MAX
    && !formsCard.value.saving);

/**
 * Why a link would certainly be refused, read from the rows this page already has, else ''.
 * Each mirrors a FormChargeAccount::linkProblem() code (has_own_account, holder_linked,
 * holder_not_onboarded / holder_charges_disabled). It only disables the button with the
 * reason shown; the PATCH's refusal stays the authority for everything else (is_holder).
 */
const linkBlockedReason = computed<string>(() => {
    const ownAccount = linkFields.value.stripe_account_id;

    if (formsCard.value.account?.state === 'own' || (typeof ownAccount === 'string' && ownAccount !== '')) {
        return `${childLabel.value} has its own Stripe account, so its forms charge on that.`;
    }

    if (formsCard.value.parentLinked === true) {
        return `${parentLabel.value} charges its own form payments through another organisation.`;
    }

    if (formsCard.value.parentChargeReady === false) {
        return `${parentLabel.value} has no connected Stripe account that can take card payments right now.`;
    }

    return '';
});

const openLinkDialog = async (): Promise<void> => {
    if (!parentId.value || !formsCard.value.parentName || linkBlockedReason.value) return;

    linkDialogReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    linkDialog.value = { open: true, typedName: '', consent: '', error: '' };

    await nextTick();
    linkDialogRoot.value?.focus();
};

const closeLinkDialog = (): void => {
    if (formsCard.value.saving) return;

    linkDialog.value = { open: false, typedName: '', consent: '', error: '' };

    const returnTo = linkDialogReturnFocus;
    linkDialogReturnFocus = null;
    nextTick(() => { if (returnTo?.isConnected) returnTo.focus(); });
};

const onLinkDialogKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        closeLinkDialog();
        return;
    }

    trapTab(event, linkDialogRoot.value);
};

/** PATCH the link. A refusal stays in the dialog, word for word. */
const confirmLink = async (): Promise<void> => {
    const childId = masjid.value?.id;
    const parent = parentId.value;
    if (!childId || !parent || !canConfirmLink.value) return;

    formsCard.value.saving = true;
    linkDialog.value.error = '';

    let via: FormsCardVia | null = null;
    let saved = false;

    try {
        const result = await connectStore.setFormsCardAccount(childId, {
            via_masjid_id: parent,
            typed_holder_name: linkDialog.value.typedName,
            consent_reference: linkDialog.value.consent,
        });
        via = result.forms_card_via;
        saved = true;
    } catch (e) {
        linkDialog.value.error = serverMessage(e, 'The link was not saved.');
    } finally {
        formsCard.value.saving = false;
    }

    if (!saved) return;

    closeLinkDialog();
    applyFormsCardResult(via);

    if (!via) {
        MSwal.fire('Check this organisation',
            'The server did not confirm the link. Reload the page to see what was saved.', 'warning');
    } else if (via.ready) {
        MSwal.fire('Linked', `Card payments on ${childLabel.value}'s forms now go through ${via.holder.name ?? parentLabel.value}.`, 'success');
    } else {
        const problem = formsCardProblemText(via.problem);
        MSwal.fire('Linked, but not ready',
            `The link is saved, but card payments on ${childLabel.value}'s forms are refused right now`
            + `${problem ? `, because ${problem}` : ''}.`, 'warning');
    }
};

const unlinkFormsCard = async (): Promise<void> => {
    const childId = masjid.value?.id;
    if (!childId || !formsCard.value.via) return;

    const holder = formsCardHolderName.value;

    const confirmed = await QSwal.fire(
        'Question',
        `Stop charging ${childLabel.value}'s form card payments through ${holder}? New card payments on its `
        + `forms will be refused until this is set again, and families will be sent to pay the office where a `
        + `form offers it. Payments already made stay in ${holder}'s Stripe account, and a card payment page `
        + `opened in the last half hour can still be paid and recorded.`,
        'question'
    );
    if (!confirmed.isConfirmed) return;

    formsCard.value.saving = true;
    let swalInstance: SweetAlertOptions = { title: 'Info', text: 'Nothing', icon: 'info' };

    try {
        const result = await connectStore.setFormsCardAccount(childId, { via_masjid_id: null });
        applyFormsCardResult(result.forms_card_via);
        swalInstance = result.forms_card_via
            ? { title: 'Check this organisation', text: 'The server still reports a link. Reload the page to see what was saved.', icon: 'warning' }
            : { title: 'Unlinked', text: `${childLabel.value}'s forms no longer take card payments through ${holder}.`, icon: 'success' };
    } catch (e) {
        swalInstance = { title: 'Sorry', text: serverMessage(e, 'The link was not removed.'), icon: 'error' };
    } finally {
        formsCard.value.saving = false;
        MSwal.fire(swalInstance);
    }
};

// ---- Text messaging (SMS) sender identity (T-009) ----
//
// The panel records the OUTCOME of an A2P 10DLC registration. Nothing here
// registers anything: brand and campaign registration happens in the provider
// console and at the carriers, takes days, and can be refused. The steps an
// operator performs are written out in .claude/rules/broadcasts.md.
//
// SuperAdmin-only, and it must stay on this screen. A self-serve "our number is
// approved" control on the masjid dashboard is the specific failure that rule
// names, because the organisation whose reputation it burns is every other
// tenant on the provider account.

const smsPanel = computed(() => smsSenderStore.panel);

/**
 * The form is seeded from the SAVED row, and from nothing else.
 *
 * `registration_status` falls back to `unregistered` — the value that means
 * "nothing has been submitted", which is the truth about an organisation with
 * no row. It is deliberately not `pending`: a default that quietly claims
 * paperwork is in flight is a default that lies on every new organisation.
 */
const smsForm = ref<SmsSenderPayload>({
    phone_number: '',
    messaging_service_sid: '',
    sender_label: '',
    registration_status: 'unregistered',
    brand_registration_id: '',
    campaign_registration_id: '',
    notes: '',
});

/**
 * "Can send" is the SERVER's answer, never re-derived here.
 *
 * MasjidSmsSender::canSend() is approval AND an originating identity, and the
 * sending path calls that method. A second copy of the rule in this component
 * would show a green badge over a channel that then refuses.
 */
const smsSendBadgeClass = computed<string>(() =>
    smsPanel.value?.can_send ? 'bg-success' : 'bg-secondary');

const formatSmsDate = (iso: string): string => {
    const d = new Date(iso);
    return isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const loadSmsSender = async (): Promise<void> => {
    const id = route.params.masjid_id as string;
    if (!id) return;

    try {
        const panel = await smsSenderStore.fetchSender(id);
        const sender = panel?.sender;
        smsForm.value = {
            phone_number: sender?.phone_number ?? '',
            messaging_service_sid: sender?.messaging_service_sid ?? '',
            sender_label: sender?.sender_label ?? '',
            registration_status: (sender?.registration_status ?? 'unregistered') as SmsSenderStatus,
            brand_registration_id: sender?.brand_registration_id ?? '',
            campaign_registration_id: sender?.campaign_registration_id ?? '',
            notes: sender?.notes ?? '',
        };
    } catch (e) {
        // Non-fatal: the section says it could not load rather than inventing a
        // state for a sender it has not read.
        console.error('Fetch SMS sender error:', e);
    }
};

/**
 * Record the registration outcome.
 *
 * Approving is confirmed out loud because it is the moment this organisation
 * starts putting traffic on the carrier network — and because "approved" here
 * is a claim about what the carriers decided, not a wish.
 */
const saveSmsSender = async (): Promise<void> => {
    const id = route.params.masjid_id as string;
    if (!id) {
        MSwal.fire('Sorry', 'The masjid ID is missing.', 'error');
        return;
    }

    if (smsForm.value.registration_status === 'approved') {
        const confirmed = await QSwal.fire(
            'Question',
            'Mark this organization as approved by the carriers? Only record this once the A2P 10DLC '
            + 'brand and campaign have actually been approved — from this point its admins can send '
            + 'text messages from this number.',
            'question'
        );
        if (!confirmed.isConfirmed) return;
    }

    let swalInstance: SweetAlertOptions = { title: 'Info', text: 'Nothing', icon: 'info' };

    try {
        const saved = await smsSenderStore.saveSender(id, smsForm.value);
        swalInstance.title = 'Success';
        swalInstance.text = saved.can_send
            ? 'Sender saved. This organization can send text messages.'
            : `Sender saved. ${saved.refusal_reason ?? ''}`.trim();
        swalInstance.icon = saved.can_send ? 'success' : 'warning';
    } catch (e) {
        // A 422 here is the validator's sentence — an unnormalisable number, or
        // an "approved" sender with nothing to send from. Shown as written.
        const error = e as AxiosError<BackendResponseData>;
        swalInstance.title = 'Sorry';
        swalInstance.text = getMessageFromObj(error) || 'Could not save the sender.';
        swalInstance.icon = 'error';
    } finally {
        MSwal.fire(swalInstance);
    }
};

// ---- App provisioning control plane ----

/** True while any job is still short of a terminal status. */
const hasActiveJobs = (): boolean =>
    jobs.value.some(j => !TERMINAL_STATUSES.includes(j.status));

/** Map a job status to a Bootstrap badge class. */
const statusBadgeClass = (status: string): string => {
    switch (status) {
        case 'failed':
            return 'bg-danger';
        case 'uploaded':
        case 'built':
            return 'bg-success';
        case 'queued':
        case 'dispatched':
            return 'bg-secondary';
        default: // scaffolding / building
            return 'bg-info text-dark';
    }
};

/** Fetch the latest provisioning jobs for the status panel. */
const fetchProvisioningJobs = async (): Promise<void> => {
    const id = route.params.masjid_id as string;
    if (!id) return;

    await ApiService.get(`/api/admin/masjids/${id}/provisioning-jobs`)
        .then(res => {
            if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                jobs.value = res.data.data as ProvisioningJob[];
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            // Non-fatal: the panel just keeps its last known state.
            console.error('Fetch provisioning jobs error:', e);
        });
};

const startPolling = (): void => {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
        await fetchProvisioningJobs();
        if (!hasActiveJobs()) stopPolling();
    }, 4000);
};

const stopPolling = (): void => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

/** Dispatch the provisioning pipeline for the selected platforms. */
const generateApps = async (): Promise<void> => {
    const id = masjid.value?.id ?? (route.params.masjid_id as string);
    if (!id) {
        MSwal.fire('Sorry', 'The masjid ID is missing.', 'error');
        return;
    }
    if (!platforms.value.ios && !platforms.value.android) {
        MSwal.fire('Info', 'Select at least one platform.', 'info');
        return;
    }

    const chosen: string[] = [];
    if (platforms.value.ios) chosen.push('ios');
    if (platforms.value.android) chosen.push('android');

    const result = await QSwal.fire(
        'Question',
        `Dispatch the build pipeline for: ${chosen.join(', ')}?`,
        'question'
    );
    if (!result.isConfirmed) return;

    // POST goes out as multipart/form-data (ApiService default); the array is
    // sent as platforms[] so Laravel re-parses it into an array.
    const body = new FormData();
    chosen.forEach(p => body.append('platforms[]', p));

    generating.value = true;

    let swalInstance: SweetAlertOptions = { title: 'Info', text: 'Nothing', icon: 'info' };

    await ApiService.post(`/api/admin/masjids/${id}/provision-apps`, body)
        .then(res => {
            if (res.data?.status === 'success') {
                swalInstance.title = 'Success';
                swalInstance.text = 'Provisioning dispatched. Watch the status below.';
                swalInstance.icon = 'success';
            } else {
                swalInstance.title = 'Sorry';
                swalInstance.text = getMessageFromObj(res);
                swalInstance.icon = 'warning';
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            console.error(e);
            swalInstance.title = e.message;
            swalInstance.text = getMessageFromObj(e);
            swalInstance.icon = 'error';
        })
        .finally(async () => {
            generating.value = false;
            await fetchProvisioningJobs();
            if (hasActiveJobs()) startPolling();
            MSwal.fire(swalInstance);
        });
};

</script>

<style scoped>
.logo-container {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    overflow: hidden;
    /* background-color: bisque; */
    max-width: 100%;
    height: 8rem;
    object-fit: contain;
}

.logo {
    border-radius: .5rem;
    padding: 1rem;
    height: 100%;
}

.admin-logo-container {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    width: 7rem;
    max-height: 7rem;
    object-fit: cover;
}

.admin-logo {
    width: 100%;
    border-radius: .5rem;
    padding: 1rem;
}

.info-attribute {
    width: 6rem;
}

@media(max-width: 480px) {
    .info-attribute {
        width: 100%;
    }
}

.form-check-input,
.form-check-input:focus {
    width: 4rem;
    height: 2rem;
    border: none;
    --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23F3F8FB'/%3e%3ctext x='0' y='0.1' font-size='2.5' font-weight='bold' text-anchor='middle' alignment-baseline='middle' fill='black' style='font-family:Poppins, sans-serif;'%3eOff%3c/text%3e%3c/svg%3e");
}

.form-check-input:checked,
.form-check-input:checked:focus {
    --bs-form-switch-bg: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23F3F8FB'/%3e%3ctext x='0' y='0.1' font-size='2.5' font-weight='bold' text-anchor='middle' alignment-baseline='middle' fill='black' style='font-family:Poppins, sans-serif;'%3eOn%3c/text%3e%3c/svg%3e");
    background-color: var(--cgreen) !important;
}

/* Plain multi-select checkboxes for the Generate Apps platform picker — reset
   the switch sizing the rules above impose on every .form-check-input here. */
.gen-check.form-check-input,
.gen-check.form-check-input:focus {
    width: 1.15rem;
    height: 1.15rem;
    border: 1px solid var(--input-border);
    border-radius: .25rem;
}

.gen-check.form-check-input:checked,
.gen-check.form-check-input:checked:focus {
    background-color: var(--cgreen) !important;
    border-color: var(--cgreen);
}

.job-row {
    border: 1px solid var(--input-border);
    border-radius: .5rem;
    padding: .75rem 1rem;
}

.job-platform {
    min-width: 4rem;
}

.job-detail {
    word-break: break-word;
}
</style>
