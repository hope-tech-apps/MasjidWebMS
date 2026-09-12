<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-muted">Roster</h6>
            <button class="btn btn-sm btn-success" @click="openAddModal">
                <i class="bi bi-person-plus me-1"></i> Add to roster
            </button>
        </div>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>

        <div v-else-if="loadError" class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            {{ loadError }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="reload">Retry</button>
        </div>

        <!--
            UNCONFIRMED CLAIMS — the office's whole view of provenance.

            A row a public registration form wrote says a person is on this
            roster; it does NOT say anybody here agreed they are. Until a staff
            member confirms it, a guardian entry among these opens none of that
            child's records, and the parent cannot be given a portal sign-in. The
            banner exists because the alternative is an office reading a normal
            roster and never learning that a family's access is being withheld.

            ONE BUTTON FOR THE WHOLE GROUP is the point: a school with 200 camp
            signups must not face 200 dialogs, and the group is the smallest
            scope in which the person clicking can actually see what they are
            vouching for.

            WHAT THE BUTTON NO LONGER CLAIMS. It used to say "Confirm all N" and
            mean it. Some claims cannot be swept, because the operator cannot
            read them apart: a second guardian entry over the SAME child under
            the SAME name. Those are counted separately, excluded from this
            button by the client AND refused a place in the sweep by the server,
            and decided one at a time from the row itself.
        -->
        <template v-else>
        <div v-if="pendingClaims > 0" class="alert alert-warning d-flex align-items-start gap-3">
            <i class="bi bi-patch-question fs-4 mt-1"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold mb-1">
                    {{ pendingClaims }} {{ pendingClaims === 1 ? 'entry' : 'entries' }} on this roster came from a
                    registration form and {{ pendingClaims === 1 ? 'has' : 'have' }} not been confirmed
                </div>
                <div class="small mb-0">
                    Whoever filled the form in was not signed in, so these are claims rather than records.
                    They are listed and counted here, and a teacher can already work from them — but an
                    unconfirmed <strong>guardian</strong> entry opens none of that child's behaviour, ḥifẓ or
                    message history, and the parent cannot be given a sign-in until somebody here stands
                    behind it. <strong>Read the addresses below</strong>, not just the names: two entries can
                    carry the same name over the same child and be two different people.
                </div>
                <div v-if="contestedClaims > 0" class="small mt-2 mb-0 text-danger-emphasis">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    {{ contestedClaims }} of {{ pendingClaims === 1 ? 'them' : 'those' }}
                    {{ contestedClaims === 1 ? 'claims a child another entry also claims' : 'claim children other entries also claim' }}
                    under the same name. {{ contestedClaims === 1 ? 'It is' : 'They are' }} not included in this
                    button — open {{ contestedClaims === 1 ? 'it' : 'them' }} from the row below and decide
                    {{ contestedClaims === 1 ? 'it' : 'them' }} one at a time.
                </div>
            </div>
            <button
                v-if="sweepableClaims.length > 0"
                class="btn btn-sm btn-warning text-nowrap"
                :disabled="confirming"
                @click="confirmAllClaims"
            >
                <span v-if="confirming" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="bi bi-patch-check me-1"></i>
                Review &amp; confirm {{ sweepableClaims.length }}
            </button>
        </div>

        <div v-if="memberships.length === 0" class="text-center py-5 text-muted">
            <i class="bi bi-person-x fs-1 d-block mb-3"></i>
            <p class="mb-0">Nobody is on this roster yet</p>
        </div>

        <div v-else>
            <!-- PARTICIPANTS: the people who are in the group in their own right. -->
            <div class="table-responsive mb-4">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Role</th>
                            <th>Grade</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="membership in participants" :key="membership.id"
                            :class="{ 'opacity-75': membership.left_on }">
                            <td class="fw-semibold">
                                <!-- The student's own face, so a roster reads as
                                     thirty children rather than thirty rows. -->
                                <button type="button"
                                        class="btn btn-link p-0 align-middle me-2"
                                        :title="`Choose an avatar for ${fullName(membership.contact)}`"
                                        @click="openAvatarPicker(membership)">
                                    <PersonAvatar
                                        :avatar="membership.contact?.avatar"
                                        :first-name="membership.contact?.first_name"
                                        :last-name="membership.contact?.last_name"
                                        :size="34" />
                                </button>
                                {{ fullName(membership.contact) }}
                                <span v-if="isPending(membership)" class="badge bg-warning-subtle text-warning ms-1">
                                    Unconfirmed
                                </span>
                                <!--
                                    THE ROW IS STILL HERE BECAUSE THE RECORDS ARE.
                                    A child who left keeps their place on this
                                    list — and only this list — so the office can
                                    see who left, when, and undo it. Every other
                                    screen in the school stopped counting them.
                                -->
                                <span v-if="membership.left_on" class="badge bg-secondary-subtle text-secondary ms-1">
                                    Left {{ formatStoredDay(membership.left_on) }}
                                </span>
                                <!--
                                    WHICH Fatima Ahmed. A name is not an identity
                                    on a roster a public form can write to, and
                                    the office cannot answer "is this the child we
                                    enrolled" from a name it already had.
                                -->
                                <div class="small fw-normal" :class="addressClass(membership.contact)">
                                    {{ addressLabel(membership.contact) }}
                                </div>
                                <div v-if="isPending(membership)" class="small fw-normal" :class="originClass(membership)">
                                    {{ originLabel(membership) }}
                                </div>
                            </td>
                            <td>
                                <span
                                    class="badge text-capitalize"
                                    :class="membership.role === 'leader' ? 'bg-primary-subtle text-primary' : 'bg-light text-dark border'"
                                >
                                    {{ membership.role }}
                                </span>
                            </td>
                            <!-- Which grade, inside a class that spans several.
                                 Free text: the vocabulary is the school's. -->
                            <td style="min-width: 110px">
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    :value="membership.grade_label ?? ''"
                                    :disabled="savingGrade === membership.id"
                                    placeholder="—"
                                    maxlength="32"
                                    @change="saveGrade(membership, ($event.target as HTMLInputElement).value)"
                                />
                            </td>
                            <td class="text-muted small">{{ formatDate(membership.joined_at) }}</td>
                            <td class="text-end">
                                <button
                                    v-if="isPending(membership)"
                                    class="btn btn-sm btn-outline-warning me-1"
                                    :disabled="confirming"
                                    title="Confirm this entry"
                                    @click="confirmOne(membership)"
                                >
                                    <i class="bi bi-patch-check"></i>
                                </button>
                                <button
                                    v-if="!membership.left_on"
                                    class="btn btn-sm btn-outline-secondary me-1"
                                    title="Record that this student has left the class"
                                    @click="openWithdraw(membership)"
                                >
                                    <i class="bi bi-box-arrow-right"></i>
                                </button>
                                <button
                                    v-else
                                    class="btn btn-sm btn-outline-success me-1"
                                    :disabled="savingWithdrawal"
                                    title="Put this student back on the roster"
                                    @click="undoWithdrawal(membership)"
                                >
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" @click="confirmRemove(membership)" title="Remove">
                                    <i class="bi bi-person-dash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="participants.length === 0">
                            <td colspan="5" class="text-center text-muted py-3">No members yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!--
                GUARDIAN EDGES, rendered as what they actually are.

                `role = guardian` alone is ambiguous the moment a group holds two
                children of the same parent: it says an adult is *a* guardian here
                without saying *of whom*, and no permission question can be
                answered from that. So the row names its ward, one row per
                (guardian, ward, group) edge, and this table says
                "X — guardian of Y" instead of listing a bare "Guardian" role.
                That relationship is the thing this product models properly.
            -->
            <h6 class="text-muted mb-2">Guardians</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Guardian</th>
                            <th>Guardian of</th>
                            <th>Where this entry came from</th>
                            <th>Consent</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="membership in guardians" :key="membership.id" :class="{ 'table-danger': isContested(membership) }">
                            <td class="fw-semibold">
                                {{ fullName(membership.contact) }}
                                <!--
                                    THE ROW THIS BADGE MATTERS MOST ON. An
                                    unconfirmed guardian edge is a claim somebody
                                    typed into a public form about a child, so it
                                    opens nothing — and the office has to be able
                                    to see which of these are records and which
                                    are claims before deciding.
                                -->
                                <span v-if="isPending(membership)" class="badge bg-warning-subtle text-warning ms-1">
                                    Unconfirmed claim
                                </span>
                                <!-- A guardian leaves the class with their child;
                                     this is why their class-wide access stopped. -->
                                <span v-if="membership.left_on" class="badge bg-secondary-subtle text-secondary ms-1">
                                    Left {{ formatStoredDay(membership.left_on) }}
                                </span>
                                <!--
                                    THE BYTE THAT SEPARATES TWO CLAIMS OVER ONE
                                    CHILD. Measured: a stranger who knew a child's
                                    name and household address typed the MOTHER's
                                    name as payer with his own email, and this
                                    table drew "Aisha Ahmed" for both rows and
                                    nothing else. One press of the bulk button
                                    turned his 403 into her behaviour record.
                                -->
                                <div class="small fw-normal" :class="addressClass(membership.contact)">
                                    {{ addressLabel(membership.contact) }}
                                </div>
                                <div v-if="isContested(membership)" class="small fw-semibold text-danger mt-1">
                                    <i class="bi bi-exclamation-octagon me-1"></i>
                                    {{ membership.claim?.rival_claim_ids?.length ?? 0 }} other
                                    {{ (membership.claim?.rival_claim_ids?.length ?? 0) === 1 ? 'entry claims' : 'entries claim' }}
                                    this child under this name — decide this one on its own
                                </div>
                            </td>
                            <td>
                                <i class="bi bi-arrow-return-right text-muted me-1"></i>
                                {{ fullName(membership.guardian_of) }}
                                <div class="small text-muted">{{ addressLabel(membership.guardian_of) }}</div>
                            </td>
                            <!--
                                PROVENANCE, DRAWN. `index()` has always eager-loaded
                                the asserting registration and this file rendered it
                                zero times, while the request's docblock said it was
                                "on the screen the button lives on". Every way the
                                evidence can be missing gets its own sentence here —
                                a merge nulls the payer, and a blank cell is what
                                produced the defect in the first place.
                            -->
                            <td class="small" :class="originClass(membership)">
                                {{ originLabel(membership) }}
                            </td>
                            <td>
                                <!--
                                    Absence of a record means NO consent — never
                                    an unknown or a pending state. `media` covers
                                    `feed`, because a photograph is a sharper
                                    disclosure than a note.
                                -->
                                <span v-if="membership.consent_scope === 'media'" class="badge bg-success-subtle text-success">
                                    Photos &amp; notes
                                </span>
                                <span v-else-if="membership.consent_scope === 'feed'" class="badge bg-info-subtle text-info">
                                    Notes only
                                </span>
                                <span v-else class="badge bg-secondary-subtle text-secondary">Not given</span>
                                <!--
                                    WHEN it was given. A consent is a dated act, and an
                                    office checking this row against the paper form in
                                    front of them is comparing dates — a badge on its own
                                    cannot be checked against anything.
                                -->
                                <div v-if="membership.consent_scope && membership.consent_granted_at" class="small text-muted">
                                    {{ formatStoredDay(membership.consent_granted_at) }}
                                </div>
                                <!--
                                    An unconfirmed claim may not hold consent, and the
                                    server refuses one (422). So the cell says what to do
                                    instead of offering a control that fails.
                                -->
                                <div v-else-if="isPending(membership)" class="small text-muted">
                                    Confirm this entry first
                                </div>
                            </td>
                            <td class="text-end">
                                <button
                                    v-if="isPending(membership)"
                                    class="btn btn-sm me-1"
                                    :class="isContested(membership) ? 'btn-danger' : 'btn-outline-warning'"
                                    :disabled="confirming"
                                    :title="isContested(membership)
                                        ? 'Two entries claim this child under this name — compare them'
                                        : 'Confirm this guardian'"
                                    @click="confirmOne(membership)"
                                >
                                    <i class="bi" :class="isContested(membership) ? 'bi-question-diamond' : 'bi-patch-check'"></i>
                                </button>
                                <!--
                                    RECORDING CONSENT BELONGS ON THIS ROW, which already
                                    names whose parent this is and which signup asserted
                                    it. The endpoint has existed as long as the columns;
                                    nothing in this app ever called it, so an office could
                                    read "Not given" here and had no way to set it.
                                -->
                                <button
                                    v-if="!isPending(membership)"
                                    class="btn btn-sm me-1"
                                    :class="membership.consent_scope ? 'btn-outline-secondary' : 'btn-outline-primary'"
                                    :title="membership.consent_scope
                                        ? 'Change or withdraw what this guardian consented to'
                                        : 'Record what this guardian consented to'"
                                    @click="openConsent(membership)"
                                >
                                    <i class="bi bi-file-earmark-check"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" @click="confirmRemove(membership)" title="Remove">
                                    <i class="bi bi-person-dash"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="guardians.length === 0">
                            <td colspan="5" class="text-center text-muted py-3">No guardians linked yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        </template>

        <!-- Add to roster -->
        <Teleport to="body">
            <div v-if="showAddModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);" @click.self="showAddModal = false">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Add to roster</h5>
                            <button type="button" class="btn-close" @click="showAddModal = false"></button>
                        </div>
                        <form @submit.prevent="submitAdd">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Role <span class="text-danger">*</span></label>
                                    <select class="form-select text-capitalize" v-model="addForm.role">
                                        <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">
                                        {{ membersTerm }} <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        class="form-control mb-2"
                                        v-model="contactSearch"
                                        @input="searchContacts"
                                        placeholder="Search the directory by name or email…"
                                    >
                                    <div class="list-group" style="max-height:28vh; overflow-y:auto;">
                                        <button
                                            v-for="contact in contactResults"
                                            :key="contact.id"
                                            type="button"
                                            class="list-group-item list-group-item-action d-flex justify-content-between"
                                            :class="{ active: addForm.contact_id === contact.id }"
                                            @click="addForm.contact_id = contact.id"
                                        >
                                            <span>{{ contact.first_name }} {{ contact.last_name }}</span>
                                            <small class="text-muted">{{ contact.email || '' }}</small>
                                        </button>
                                        <div v-if="!contactResults.length" class="text-muted small p-2">Type to search…</div>
                                    </div>
                                </div>

                                <!--
                                    A guardian edge MUST name its ward, and the ward
                                    must already hold a participant membership here
                                    — otherwise the edge would grant access to a
                                    child nobody put in this group.
                                -->
                                <div v-if="addForm.role === 'guardian'" class="mb-1">
                                    <label class="form-label">Guardian of <span class="text-danger">*</span></label>
                                    <select class="form-select" v-model="guardianOfContactId">
                                        <option :value="null" disabled>Choose the member…</option>
                                        <option v-for="p in participants" :key="p.id" :value="p.contact_id">
                                            {{ fullName(p.contact) }}
                                        </option>
                                    </select>
                                    <div class="form-text">
                                        One row per child: a parent with two children in this group is added twice.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="showAddModal = false" :disabled="adding">Cancel</button>
                                <button type="submit" class="btn btn-success" :disabled="adding || !canAdd">
                                    <span v-if="adding" class="spinner-border spinner-border-sm me-1"></span>
                                    Add
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>

        <!-- A student leaving the class -->
        <Teleport to="body">
            <div v-if="withdrawFor" class="modal fade show d-block" tabindex="-1"
                 style="background:rgba(0,0,0,.5)" @click.self="withdrawFor = null">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-box-arrow-right me-2"></i> Left the class</h5>
                            <button type="button" class="btn-close" @click="withdrawFor = null"></button>
                        </div>
                        <form @submit.prevent="saveWithdrawal">
                            <div class="modal-body">
                                <p class="mb-3">
                                    <span class="fw-semibold">{{ fullName(withdrawFor.contact) }}</span>
                                    has left this class.
                                </p>
                                <div class="mb-3">
                                    <label class="form-label" for="left-on">Last day in the class</label>
                                    <input id="left-on" type="date" class="form-control" :max="today" v-model="withdrawForm.left_on">
                                    <div class="form-text">Defaults to today. Never in the future.</div>
                                </div>
                                <!--
                                    Said plainly, because the office is choosing
                                    between this and Remove, and the difference
                                    between them is the whole point.
                                -->
                                <ul class="small text-muted mb-0 ps-3">
                                    <li>Everything on their record stays: the register, marks, report cards, ḥifẓ and points.</li>
                                    <li>They come off the register, the gradebook and every class list from that day.</li>
                                    <li>Their guardians leave the class too, so the class story and its emails stop for them.</li>
                                    <li>Their family can still open their own child's records, and you can undo this at any time.</li>
                                </ul>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" @click="withdrawFor = null" :disabled="savingWithdrawal">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-success" :disabled="savingWithdrawal || !withdrawForm.left_on">
                                    <span v-if="savingWithdrawal" class="spinner-border spinner-border-sm me-1"></span>
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- What a guardian consented to -->
        <Teleport to="body">
            <div v-if="consentFor" class="modal fade show d-block" tabindex="-1"
                 style="background:rgba(0,0,0,.5)" @click.self="consentFor = null">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-file-earmark-check me-2"></i> Consent</h5>
                            <button type="button" class="btn-close" @click="consentFor = null"></button>
                        </div>
                        <form @submit.prevent="saveConsent">
                            <div class="modal-body">
                                <p class="mb-3">
                                    <span class="fw-semibold">{{ fullName(consentFor.contact) }}</span>
                                    &mdash; guardian of {{ fullName(consentFor.guardian_of) }}.
                                </p>
                                <!--
                                    THE OFFICE IS RECORDING SOMETHING A PARENT DID, not
                                    switching a feature on for them. So each scope is
                                    described by what it OPENS — that is what the parent
                                    agreed to, and what this row will be read as later.
                                    Nothing is pre-selected on a row that has no record:
                                    a default is exactly what consent may not be.
                                -->
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" id="consent-feed" value="feed" v-model="consentForm.scope">
                                    <label class="form-check-label" for="consent-feed">
                                        <span class="fw-semibold">Notes only</span>
                                        <span class="d-block small text-muted">
                                            The class story, class-wide messages and handouts — written updates about the class.
                                        </span>
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" id="consent-media" value="media" v-model="consentForm.scope">
                                    <label class="form-check-label" for="consent-media">
                                        <span class="fw-semibold">Photos &amp; notes</span>
                                        <span class="d-block small text-muted">
                                            Everything above, and photographs of their own child in the class story.
                                        </span>
                                    </label>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label" for="consent-date">Date on the signed form</label>
                                    <input id="consent-date" type="date" class="form-control" :max="today" v-model="consentForm.granted_at">
                                    <div class="form-text">
                                        The date the parent signed, not the date you are typing it. Defaults to today.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer justify-content-between">
                                <!--
                                    Withdrawal sits in the same dialog because it corrects
                                    the same fact, and it is the one direction this screen
                                    must never make hard to find.
                                -->
                                <button
                                    v-if="consentFor.consent_scope"
                                    type="button"
                                    class="btn btn-outline-danger"
                                    :disabled="savingConsent"
                                    @click="withdrawConsent"
                                >
                                    Withdraw
                                </button>
                                <span v-else></span>
                                <span>
                                    <button type="button" class="btn btn-secondary me-2" @click="consentFor = null" :disabled="savingConsent">
                                        Cancel
                                    </button>
                                    <button type="submit" class="btn btn-success" :disabled="savingConsent || !consentForm.scope">
                                        <span v-if="savingConsent" class="spinner-border spinner-border-sm me-1"></span>
                                        Save
                                    </button>
                                </span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Choosing a student's avatar -->
        <Teleport to="body">
            <div v-if="avatarFor" class="modal fade show d-block" tabindex="-1"
                 style="background:rgba(0,0,0,.5)" @click.self="avatarFor = null">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Avatar</h5>
                            <button type="button" class="btn-close" @click="avatarFor = null"></button>
                        </div>
                        <div class="modal-body">
                            <AvatarPicker
                                :masjid-id="masjidId"
                                :contact-id="avatarFor.contact?.id"
                                :avatar="avatarFor.contact?.avatar"
                                :first-name="avatarFor.contact?.first_name"
                                :last-name="avatarFor.contact?.last_name"
                                @saved="onAvatarSaved" />
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { AxiosResponse } from 'axios';
import ApiService from '@/core/services/ApiService';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import AvatarPicker from '@/components/common/AvatarPicker.vue';
import { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
import { Contact } from '@/core/types/data/masjid-related/Contact';
import { ConsentScope, GroupContact, GroupMembership, GroupMembershipPayload, GroupRole } from '@/core/types/data/masjid-related/Group';
import { useGroupsStore } from '@/stores/masjid/groupsStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { apiErrorText } from '@/core/services/ApiErrors';
import Swal from 'sweetalert2';

/**
 * The roster — who is in this group, and how.
 *
 * The part worth getting right is the GUARDIAN EDGE. Competitors model a parent
 * as a role on the group and then cannot answer "may this adult see this child's
 * record?", because the row does not say whose parent they are. Here a guardian
 * row carries its ward, so it renders as "Fatima Ahmed — guardian of Yusuf
 * Ahmed", and a parent with two children in one classroom holds two rows.
 *
 * Groups reference people, they never duplicate them: everyone added here is an
 * existing Contact from the member directory. See .claude/rules/groups.md.
 *
 * ==========================================================================
 * THIS SCREEN IS WHERE A CLAIM BECOMES A GRANT, SO IT HAS TO CARRY THE EVIDENCE
 * ==========================================================================
 *
 * `ConfirmGroupMembershipsRequest` justified its bulk button by saying "ward
 * names, the claimed guardian, and which signup asserted it are all on the
 * screen the button lives on." Measured, in this file: `source_registration`
 * appeared 0 times, `confirmed_by` 0 times, and the only two occurrences of
 * `email` were inside the add-to-roster directory picker. Worse, the ward name
 * was not on the screen either — Eloquent's `$snakeAttributes` serialises
 * `with('guardianOf')` as `guardian_of`, this file read `membership.guardianOf`,
 * and the "Guardian of" column rendered `—` on every row. The one column that
 * answers "guardian of WHOM" was empty and the docblock said it was the
 * safeguard.
 *
 * So four public registrations — three real families and one stranger who knew a
 * child's name and household address, typed the MOTHER's name as payer and his
 * own email — drew as:
 *
 *     GUARDIAN ROW #2  "Aisha Ahmed"  ->  —
 *     GUARDIAN ROW #7  "Aisha Ahmed"  ->  —
 *
 * and one press of "Confirm all 6" took his access from 403 to that child's
 * behaviour record. What this file now draws for the same two rows is at the
 * bottom of the guardian table below: the ward, the claimant's address, and the
 * signup and payer that asserted the row — plus a refusal to sweep the pair at
 * all, which the server enforces independently of anything drawn here.
 */

const props = defineProps<{
    groupId: number;
    memberships: GroupMembership[];
    loading: boolean;
    loadError: string;
}>();

/** The student whose avatar is being chosen, or null when the picker is shut. */
const avatarFor = ref<any>(null);

const masjidId = computed(() => masjidStore.masjid?.id ?? 0);

// Which grade a student is in, inside a class that spans more than one. Saved on
// change rather than behind a Save button: it is one short field on a row the
// office is already looking at, and a per-row button would be a third control in
// a cell that is mostly empty. Written straight through ApiService instead of the
// groups store because nothing else in the app needs to react to it.
const savingGrade = ref<number | null>(null);

const saveGrade = async (membership: GroupMembership, raw: string) => {
    const next = raw.trim() === '' ? null : raw.trim();
    if ((membership.grade_label ?? null) === next) return;

    savingGrade.value = membership.id;
    try {
        await ApiService.put(
            `/api/admin/masjids/${masjidId.value}/groups/${props.groupId}/members/${membership.id}` as BackendApiRoute,
            // Empty string, never null: this PUT is form-encoded by default, and
            // a null would arrive as the STRING "null" or vanish entirely. The
            // controller normalises '' back to NULL.
            { grade_label: next ?? '' }
        );
        membership.grade_label = next;
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Could not save the grade', text: apiErrorText(e) });
    } finally {
        savingGrade.value = null;
    }
};

const openAvatarPicker = (membership: any) => { avatarFor.value = membership; };

/**
 * Write the saved avatar back onto the row in place. Re-fetching the whole
 * roster would lose the office's scroll position and any pending-claim banner
 * state for the sake of one image.
 */
const onAvatarSaved = (contact: any) => {
    if (avatarFor.value?.contact && contact) {
        avatarFor.value.contact.avatar = contact.avatar ?? null;
        avatarFor.value.contact.avatar_character = contact.avatar_character ?? null;
        avatarFor.value.contact.avatar_tone = contact.avatar_tone ?? null;
        avatarFor.value.contact.avatar_color = contact.avatar_color ?? null;
    }
    avatarFor.value = null;
};

/**
 * ---------------------------------------------------------------------------
 * CONSENT — THE COLUMN THIS SCREEN COULD READ AND NOT WRITE
 * ---------------------------------------------------------------------------
 *
 * `group_memberships.consent_granted_at` / `consent_scope` are what
 * App\Support\GroupAudience checks at the point of every class-wide disclosure.
 * With no record a parent sees no class story, no class-wide message and no
 * handout, and the notification emails about them are never sent either — the
 * parent portal tells them so in as many words ("your consent for class updates
 * is not on file. The school office can record it for you.").
 *
 * THE OFFICE HAD NO WAY TO RECORD IT. The endpoints have existed as long as the
 * columns (PUT and DELETE .../members/{membership_id}/consent, both behind
 * `manage contacts`), and nothing in this app ever called them: this table drew
 * the badge and stopped, so the only route left was a database prompt. Found at
 * Al-Razi School, where seven guardian edges carried no record and two teachers'
 * welcome messages consequently reached one parent each.
 *
 * Recording here asserts something a PARENT did, which is why this control is per
 * row and never bulk, why no scope is pre-selected on a row that has none, and
 * why the date is the date on their form rather than the moment of typing.
 */
/**
 * ---------------------------------------------------------------------------
 * LEAVING — the third state, and the only one a departing family could use
 * ---------------------------------------------------------------------------
 *
 * Removing a roster row is for a row that should not exist, and it is refused
 * outright once the child holds any academic history, because that row is what
 * every register mark, score, report card, ḥifẓ entry, award and letter-progress
 * row hangs off. A child who LEAVES has that history by definition — so the row
 * stays, carrying a date, and the class stops counting them from it.
 *
 * Recording it RELOADS the roster rather than patching the row: the server also
 * marks every guardian edge pointing at this child, and those rows are on this
 * same screen.
 */
const withdrawFor = ref<GroupMembership | null>(null);
const withdrawForm = ref<{ left_on: string }>({ left_on: '' });
const savingWithdrawal = ref(false);

const withdrawalUrl = (membership: GroupMembership): BackendApiRoute =>
    `/api/admin/masjids/${masjidId.value}/groups/${props.groupId}/members/${membership.id}/withdrawal` as BackendApiRoute;

const openWithdraw = (membership: GroupMembership) => {
    today.value = todayLocal();
    withdrawFor.value = membership;
    withdrawForm.value = { left_on: membership.left_on ? storedDay(membership.left_on) : today.value };
};

const saveWithdrawal = async () => {
    const membership = withdrawFor.value;
    if (!membership || !withdrawForm.value.left_on) return;

    savingWithdrawal.value = true;
    try {
        const res: AxiosResponse = await ApiService.put(withdrawalUrl(membership), {
            left_on: withdrawForm.value.left_on,
        });
        withdrawFor.value = null;
        emit('changed');
        Swal.fire({
            icon: 'success',
            title: 'Recorded',
            text: res.data?.message ?? undefined,
            timer: 2400,
            showConfirmButton: false,
        });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Could not record that they left',
            text: apiErrorText(error, 'That change could not be saved.'),
        });
    } finally {
        savingWithdrawal.value = false;
    }
};

const undoWithdrawal = async (membership: GroupMembership) => {
    const result = await Swal.fire({
        title: 'Put them back on the roster?',
        text: `${fullName(membership.contact)} will be back on the register and every class list, and their guardians `
            + 'will be back in the class with them.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, put them back',
    });

    if (!result.isConfirmed) return;

    savingWithdrawal.value = true;
    try {
        await ApiService.delete(withdrawalUrl(membership));
        emit('changed');
        Swal.fire({ icon: 'success', title: 'Back on the roster', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Could not undo that',
            text: apiErrorText(error, 'That change could not be undone.'),
        });
    } finally {
        savingWithdrawal.value = false;
    }
};

const consentFor = ref<GroupMembership | null>(null);
const consentForm = ref<{ scope: ConsentScope | null; granted_at: string }>({ scope: null, granted_at: '' });
const savingConsent = ref(false);
/** Today where the OFFICE is. A consent cannot have been given tomorrow. */
const today = ref('');

/**
 * A CONSENT DATE IS A CALENDAR DAY, NOT AN INSTANT — and the two must not be
 * confused, because the office checks this row against a paper form.
 *
 * The API stores it as midnight and serialises it as UTC ('2026-09-05T00:00:00Z').
 * Passing that through `new Date()` and rendering it in the reader's timezone
 * draws the day BEFORE for everyone west of UTC: measured, a consent recorded
 * for the 5th displayed as "Sep 4, 2026", and re-opening the dialog would have
 * silently re-dated it a day earlier on the next save. So a STORED day is read
 * literally off the string, and only TODAY is computed from the local clock.
 */
const storedDay = (iso: string): string => iso.slice(0, 10);

const todayLocal = (): string => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const formatStoredDay = (iso: string | null): string => {
    if (!iso) return '—';
    const [y, m, d] = storedDay(iso).split('-').map(Number);
    if (!y || !m || !d) return formatDate(iso);
    return new Date(y, m - 1, d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const openConsent = (membership: GroupMembership) => {
    today.value = todayLocal();
    consentFor.value = membership;
    consentForm.value = {
        // Pre-filled with what already stands, so widening "notes" to "photos" is
        // one click — and re-saving never silently re-dates a standing consent.
        scope: membership.consent_scope ?? null,
        granted_at: membership.consent_granted_at ? storedDay(membership.consent_granted_at) : today.value,
    };
};

const consentUrl = (membership: GroupMembership): BackendApiRoute =>
    `/api/admin/masjids/${masjidId.value}/groups/${props.groupId}/members/${membership.id}/consent` as BackendApiRoute;

/**
 * Write the SERVER's own row back onto the one the office is looking at, rather
 * than what we hoped we sent: the badge and the date then cannot drift from the
 * record, and re-fetching the whole roster would lose their place on the page.
 */
const applyConsent = (membership: GroupMembership, data: any) => {
    membership.consent_scope = data?.consent_scope ?? null;
    membership.consent_granted_at = data?.consent_granted_at ?? null;
};

const saveConsent = async () => {
    const membership = consentFor.value;
    if (!membership || !consentForm.value.scope) return;

    savingConsent.value = true;
    try {
        const res: AxiosResponse = await ApiService.put(consentUrl(membership), {
            scope: consentForm.value.scope,
            // The chosen DAY, sent as a day. The server stores midnight of it and
            // refuses anything in the future, which is what the input's `max`
            // already prevents the office from picking.
            granted_at: consentForm.value.granted_at,
        });
        applyConsent(membership, res.data?.data);
        consentFor.value = null;
        Swal.fire({ icon: 'success', title: 'Consent recorded', timer: 1600, showConfirmButton: false });
    } catch (error) {
        // The server's own sentence is worth showing verbatim: it refuses an
        // unconfirmed claim and names the fix (confirm the entry first).
        Swal.fire({
            icon: 'error',
            title: 'Could not record the consent',
            text: apiErrorText(error, 'That consent could not be recorded.'),
        });
    } finally {
        savingConsent.value = false;
    }
};

const withdrawConsent = async () => {
    const membership = consentFor.value;
    if (!membership) return;

    const result = await Swal.fire({
        title: 'Withdraw this consent?',
        text: `${fullName(membership.contact)} will stop seeing the class story, class-wide messages and handouts about `
            + `${fullName(membership.guardian_of)} — the same position as never having consented.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, withdraw'
    });

    if (!result.isConfirmed) return;

    savingConsent.value = true;
    try {
        const res: AxiosResponse = await ApiService.delete(consentUrl(membership));
        applyConsent(membership, res.data?.data);
        consentFor.value = null;
        Swal.fire({ icon: 'success', title: 'Consent withdrawn', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Could not withdraw the consent',
            text: apiErrorText(error, 'That consent could not be withdrawn.'),
        });
    } finally {
        savingConsent.value = false;
    }
};


const emit = defineEmits<{ (event: 'changed'): void; (event: 'reload'): void }>();

// Stores
const groupsStore = useGroupsStore();
const masjidStore = useMasjidStore();

// State
const showAddModal = ref(false);
const adding = ref(false);
const confirming = ref(false);
const contactSearch = ref('');
const contactResults = ref<Contact[]>([]);
/** Kept apart from `addForm` so switching away from `guardian` cannot leave a stale ward behind. */
const guardianOfContactId = ref<number | null>(null);
let contactSearchTimer: ReturnType<typeof setTimeout> | null = null;

const emptyAddForm = (): GroupMembershipPayload => ({
    contact_id: 0, role: 'member', guardian_of_contact_id: null, joined_at: ''
});
const addForm = ref<GroupMembershipPayload>(emptyAddForm());

// Computed
const membersTerm = computed<string>(() => masjidStore.term('members'));

const roles = computed<GroupRole[]>(() => groupsStore.groupsMeta?.roles ?? ['leader', 'member', 'guardian']);

/** People who are in the group in their own right — the only rows a ward may be chosen from. */
const participants = computed<GroupMembership[]>(
    () => props.memberships.filter((m) => m.role !== 'guardian')
);

const guardians = computed<GroupMembership[]>(
    () => props.memberships.filter((m) => m.role === 'guardian')
);

/** Every unconfirmed row on this screen, in the order it is drawn. */
const pendingRows = computed<GroupMembership[]>(() => props.memberships.filter(isPending));

/**
 * The claims one press may decide — everything pending EXCEPT the ones the
 * operator cannot read apart.
 *
 * The exclusion is duplicated on the server, which refuses a contested row a
 * place in a sweep whatever the client sends. This half is not the guard; it is
 * the screen agreeing with the guard, so the button's number is the truth rather
 * than a promise the response then walks back.
 */
const sweepableClaims = computed<GroupMembership[]>(
    () => pendingRows.value.filter((m) => !isContested(m) && fingerprintOf(m) !== null)
);

const canAdd = computed<boolean>(() => {
    if (!addForm.value.contact_id) return false;
    return addForm.value.role !== 'guardian' || guardianOfContactId.value !== null;
});

/** Served by the API, never recounted here — see groupsStore.fetchMemberships. */
const pendingClaims = computed<number>(() => groupsStore.pendingClaims);

/** Also served, for the same reason: the definition decides what is refused. */
const contestedClaims = computed<number>(() => groupsStore.contestedClaims);

// Methods
const fullName = (contact: GroupContact | null | undefined): string =>
    contact ? `${contact.first_name} ${contact.last_name}`.trim() : '—';

/**
 * Has anybody at the organisation stood behind this row?
 *
 * Read defensively — ANYTHING that is not exactly `confirmed` is a pending
 * claim, so a value this build does not know about renders as unconfirmed
 * rather than as a grant. Same direction the server reads it in.
 */
const isPending = (membership: GroupMembership): boolean => membership.provenance !== 'confirmed';

/**
 * HOW TO REACH THIS PERSON — the one rendered byte that separates two rows
 * carrying the same name.
 *
 * A missing address is SAID, loudly, rather than left as an empty cell. "No
 * address on file" is a fact the office can act on; a blank is the thing the
 * operator reads straight past, and reading straight past is what the whole
 * finding was.
 */
const addressOf = (contact: GroupContact | null | undefined): string =>
    (contact?.email || contact?.phone || '').trim();

const addressLabel = (contact: GroupContact | null | undefined): string =>
    addressOf(contact) || 'no address on file';

const addressClass = (contact: GroupContact | null | undefined): string =>
    addressOf(contact) ? 'text-muted' : 'text-danger';

/**
 * Is this a claim over a child that another entry also claims, under a name the
 * operator would read as the same person?
 *
 * Read from the server's answer, never recomputed here. Defensive in the safe
 * direction is not available on this one — a build that does not understand the
 * field would read `undefined` as "not contested" — so the SERVER refuses the
 * sweep as well, and this flag only decides what the screen says about it.
 */
const isContested = (membership: GroupMembership): boolean => membership.claim?.contested === true;

/** What the caller must echo back to prove this row has not moved since it was drawn. */
const fingerprintOf = (membership: GroupMembership): string | null => membership.claim?.fingerprint ?? null;

/** The rows a confirm request carries: what was named, and what it said. */
const rowsToSend = (list: GroupMembership[]): { id: number; fingerprint: string }[] =>
    list
        .map((m) => ({ id: m.id, fingerprint: fingerprintOf(m) }))
        .filter((row): row is { id: number; fingerprint: string } => row.fingerprint !== null);

/**
 * WHICH SIGNUP ASSERTED THIS, AND WHO PAID — drawn, at last.
 *
 * Each of the four states is a different thing for the office to do, so each one
 * gets its own sentence instead of a shared blank:
 *
 *   registration                    — judge the payer's address against the row
 *   registration_payer_unavailable  — a merge removed the payer; judge it another way
 *   unrecorded                      — nothing says where it came from at all
 *   confirmed                       — a colleague already stood behind it
 */
const originLabel = (membership: GroupMembership): string => {
    const origin = membership.claim?.origin;

    if (!origin) {
        return 'Origin not shown by this server';
    }

    switch (origin.state) {
        case 'registration': {
            const payer = origin.payer;
            const who = [payer?.first_name, payer?.last_name].filter(Boolean).join(' ').trim();
            const address = (payer?.email || '').trim() || 'no address on the payer record';
            return `Signup #${origin.registration_id} — paid for by ${who || 'someone unnamed'} (${address})`;
        }
        case 'registration_payer_unavailable':
            return `Signup #${origin.registration_id} — the payer record is gone (merged or deleted), `
                + 'so this claim cannot be traced to anybody';
        case 'unrecorded':
            return 'No signup on record — this entry cannot be traced to anybody';
        case 'confirmed':
            return 'Confirmed by this organisation';
        default:
            return 'Origin not recognised by this build';
    }
};

const originClass = (membership: GroupMembership): string => {
    const state = membership.claim?.origin?.state;

    if (state === 'registration') return 'text-muted';
    if (state === 'confirmed') return 'text-success';

    // Missing evidence is not neutral. It is the state in which an operator has
    // the least to go on and used to be shown nothing at all.
    return 'text-danger';
};

/**
 * Swal renders `html` unescaped, and every string below came off a PUBLIC form —
 * the payer's name is literally attacker-supplied. Escaped here rather than in
 * each template literal, because "I remembered to escape it" is exactly the kind
 * of claim this screen has already been caught making.
 */
const escapeHtml = (value: string): string =>
    value.replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[character] as string));

const formatDate = (iso: string | null): string => {
    if (!iso) return '—';
    const date = new Date(iso);
    return isNaN(date.getTime()) ? iso : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

const reload = () => emit('reload');

const openAddModal = () => {
    addForm.value = emptyAddForm();
    guardianOfContactId.value = null;
    contactSearch.value = '';
    contactResults.value = [];
    showAddModal.value = true;
};

const searchContacts = () => {
    if (contactSearchTimer) clearTimeout(contactSearchTimer);
    contactSearchTimer = setTimeout(async () => {
        const query = contactSearch.value.trim();
        if (!query) { contactResults.value = []; return; }

        const masjidId = masjidStore.masjid?.id;
        if (!masjidId) return;

        try {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidId}/contacts?search=${encodeURIComponent(query)}&per_page=8` as BackendApiRoute
            );
            contactResults.value = res.data?.data?.data ?? [];
        } catch (error) {
            contactResults.value = [];
        }
    }, 300);
};

/**
 * Add someone to the roster — AND SAY WHAT THE SERVER SAID.
 *
 * `POST …/members` is the OTHER door onto the grant `confirm()` guards. Typing
 * an entry that already exists as a pending claim CONFIRMS it, by the same
 * authenticated person and with the same actor recorded, and the server answers
 * with a warning when the entry it just confirmed has a same-named rival
 * claiming the same child — the one fact that makes the decision worth making
 * twice, and the fact the sweep refuses to decide without.
 *
 * That sentence was computed, serialised and thrown away: the store returned
 * only `data`, and this handler fired `{icon:'success', title:'Added'}` on a
 * 1600ms timer. Measured: the contested stranger row, refused by the sweep
 * seconds earlier, confirmed here with the warning present in the body and
 * invisible on screen.
 *
 * A WARNING DOES NOT AUTO-DISMISS. The timer stays on the ordinary case, where
 * an operator typed a row and got a row; anything the server chose to say needs
 * an acknowledgement, because the act it describes is a disclosure about a child
 * and the remedy is on another part of this same screen.
 */
const submitAdd = async () => {
    if (!canAdd.value) return;
    adding.value = true;
    try {
        const result = await groupsStore.addMembership(props.groupId, {
            ...addForm.value,
            guardian_of_contact_id: addForm.value.role === 'guardian' ? guardianOfContactId.value : null
        });
        showAddModal.value = false;
        emit('changed');

        if (result.message) {
            await Swal.fire({
                icon: result.confirmedAnExistingClaim ? 'warning' : 'success',
                title: result.confirmedAnExistingClaim ? 'Confirmed by you' : 'Added',
                text: result.message,
                confirmButtonText: 'I have read this'
            });
        } else {
            Swal.fire({ icon: 'success', title: 'Added', timer: 1600, showConfirmButton: false });
        }
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to add to the roster.') });
    } finally {
        adding.value = false;
    }
};

/** One guardian claim, written the way the dialog has to name it. */
const claimLine = (membership: GroupMembership): string =>
    `<li class="mb-2"><strong>${escapeHtml(fullName(membership.contact))}</strong> `
    + `&lt;${escapeHtml(addressLabel(membership.contact))}&gt;`
    + `<br><span class="text-muted">guardian of ${escapeHtml(fullName(membership.guardian_of))}`
    + ` — ${escapeHtml(originLabel(membership))}</span></li>`;

/**
 * Stand behind every unconfirmed row ON THIS SCREEN THAT A SWEEP MAY DECIDE.
 *
 * ASKED FIRST, and the question ENUMERATES THE CLAIMANTS BY ADDRESS rather than
 * counting them. "Confirm 6 entries?" is a housekeeping chore; a list of six
 * addresses that are about to be able to read six named children's behaviour and
 * ḥifẓ is the decision actually being made. The previous wording described the
 * grant correctly and named nobody, which is why it read as reasonable to an
 * operator whose list contained a stranger.
 *
 * Enrolment rows are COUNTED and guardian rows are LISTED, deliberately. A
 * participant row is a child's own place in the group and grants its holder
 * nothing (GroupAudience ignores a family credential's own participant rows), so
 * enumerating 200 of them would bury the three lines that matter. The grant is
 * the guardian edge; the guardian edges are what the dialog reads out.
 *
 * SENDS THE IDS AND FINGERPRINTS IT DREW. The ids used to be absent entirely,
 * which the server read as "every pending claim at the moment the request lands"
 * — a different set from the one rendered here. Naming them fixed insertion; the
 * fingerprints fix mutation, which naming ids cannot: a merge re-points a
 * pending claim's `contact_id` and the id does not change.
 *
 * AND IT NEVER SENDS `contested`. That argument is not passed from here at all,
 * so the one shape an operator cannot read apart cannot be decided by this
 * button even if the list, the count and the dialog all agreed.
 */
const confirmAllClaims = async () => {
    const shown = sweepableClaims.value;

    if (shown.length === 0) return;

    const guardianClaims = shown.filter((m) => m.role === 'guardian');
    const enrolments = shown.length - guardianClaims.length;

    const result = await Swal.fire({
        title: guardianClaims.length === 0
            ? `Confirm ${shown.length} ${shown.length === 1 ? 'enrolment' : 'enrolments'}?`
            : `Give ${guardianClaims.length} ${guardianClaims.length === 1 ? 'adult' : 'adults'} access to a child's records?`,
        html: (guardianClaims.length === 0
            ? '<p class="mb-2">These entries put people on the roster. None of them is a guardian link, '
                + 'so none of them opens anybody\'s records.</p>'
            : '<p class="mb-2">Confirming these means each adult below will be able to read that '
                + 'child\'s behaviour, ḥifẓ and message history through the parent portal, and can be '
                + 'given a sign-in. <strong>Check each address</strong> — the names came off a public '
                + 'form and anybody can type one.</p>'
                + `<ul class="text-start small" style="max-height:40vh; overflow-y:auto;">${guardianClaims.map(claimLine).join('')}</ul>`)
            + (enrolments > 0
                ? `<p class="small text-muted mb-0">Also confirming ${enrolments} `
                    + `${enrolments === 1 ? 'enrolment, which grants' : 'enrolments, which grant'} nobody anything.</p>`
                : '')
            + (contestedClaims.value > 0
                ? `<p class="small text-danger mb-0 mt-2">${contestedClaims.value} contested `
                    + `${contestedClaims.value === 1 ? 'entry is' : 'entries are'} NOT included here.</p>`
                : ''),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, confirm them'
    });

    if (!result.isConfirmed) return;

    await runConfirm(rowsToSend(shown));
};

/**
 * ONE ROW, ASKED FOR BY NAME.
 *
 * This used to confirm on a bare click with no question at all, which made the
 * single-row path CHEAPER than the bulk one it sat beside — a one-click grant of
 * a child's records with nothing between the pointer and the disclosure.
 *
 * A contested row gets a different dialog: the rivals are read out beside it, so
 * "decide it individually" means choosing between two addresses rather than
 * looking at the same row twice. Only that dialog passes the row as
 * ACKNOWLEDGED, which is the one way the server will confirm it.
 */
const confirmOne = async (membership: GroupMembership) => {
    const rows = rowsToSend([membership]);

    if (rows.length === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Reload the roster',
            text: 'This entry was drawn without the information needed to confirm it safely.'
        });
        return;
    }

    if (isContested(membership)) {
        const rivalIds = membership.claim?.rival_claim_ids ?? [];
        const rivals = props.memberships.filter((m) => rivalIds.includes(m.id));

        const result = await Swal.fire({
            title: `Which ${fullName(membership.contact)}?`,
            html: `<p class="mb-2">More than one entry claims to be a guardian of `
                + `<strong>${escapeHtml(fullName(membership.guardian_of))}</strong> under this name. `
                + 'They are different records, and at most one of them is the person you think it is.</p>'
                + '<p class="mb-1 text-start fw-semibold">You are about to confirm:</p>'
                + `<ul class="text-start small">${claimLine(membership)}</ul>`
                + '<p class="mb-1 text-start fw-semibold">Also claiming this child:</p>'
                + `<ul class="text-start small">${rivals.map(claimLine).join('') || '<li>(no longer on this roster)</li>'}</ul>`
                + '<p class="small mb-0">Confirming opens that child\'s behaviour, ḥifẓ and message history to '
                + `<strong>${escapeHtml(addressLabel(membership.contact))}</strong>. `
                + 'If you cannot place that address, remove the entry instead.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: `Confirm ${addressLabel(membership.contact)}`
        });

        if (!result.isConfirmed) return;

        await runConfirm(rows, [membership.id]);
        return;
    }

    if (membership.role === 'guardian') {
        const result = await Swal.fire({
            title: 'Give this adult access to a child\'s records?',
            html: `<ul class="text-start small">${claimLine(membership)}</ul>`
                + '<p class="small mb-0">They will be able to read that child\'s behaviour, ḥifẓ and message '
                + 'history through the parent portal, and can be given a sign-in.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, confirm'
        });

        if (!result.isConfirmed) return;
    }

    await runConfirm(rows);
};

/**
 * REPORTS WHAT ACTUALLY HAPPENED, not what was asked for.
 *
 * A sweep can come back having confirmed fewer rows than it named, for three
 * reasons that call for three different acts. Announcing the confirmed count
 * alone is the same class of mistake as "Confirm all 8" returning 9: a number
 * that is true and describes the wrong set.
 */
const runConfirm = async (rows: { id: number; fingerprint: string }[], contestedIds: number[] = []) => {
    confirming.value = true;
    try {
        const outcome = await groupsStore.confirmClaims(props.groupId, rows, contestedIds);
        emit('changed');

        const held = outcome.changedSinceShown.length + outcome.needsAnIndividualDecision.length;

        if (held > 0) {
            await Swal.fire({
                icon: 'warning',
                title: outcome.confirmed === 1 ? '1 entry confirmed' : `${outcome.confirmed} entries confirmed`,
                text: outcome.message,
                confirmButtonText: 'Reload the roster'
            });
            return;
        }

        Swal.fire({
            icon: 'success',
            title: outcome.confirmed === 1 ? '1 entry confirmed' : `${outcome.confirmed} entries confirmed`,
            timer: 1800,
            showConfirmButton: false
        });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to confirm the roster entries.') });
    } finally {
        confirming.value = false;
    }
};

const confirmRemove = async (membership: GroupMembership) => {
    // Removing a PARTICIPANT also removes the guardian edges pointing at them —
    // say so, because the admin is authorising more than the row they clicked.
    const alsoDropsGuardians = membership.role !== 'guardian'
        && guardians.value.some((g) => g.guardian_of_contact_id === membership.contact_id);

    const result = await Swal.fire({
        title: 'Remove from roster?',
        text: alsoDropsGuardians
            ? `${fullName(membership.contact)} will be removed, along with the guardian links pointing at them.`
            : `${fullName(membership.contact)} will be removed from this roster.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, remove'
    });

    if (!result.isConfirmed) return;

    try {
        await groupsStore.removeMembership(props.groupId, membership.id);
        emit('changed');
        Swal.fire({ icon: 'success', title: 'Removed', timer: 1600, showConfirmButton: false });
    } catch (error) {
        Swal.fire({ icon: 'error', title: 'Error!', text: apiErrorText(error, 'Failed to remove the member.') });
    }
};

// Lock body scroll while either dialog is open. One watcher for both: two of them
// writing the same style property would have the first to close clear the lock
// while the other was still up.
watch([showAddModal, consentFor, withdrawFor], ([adding, consenting, leaving]) => {
    document.body.style.overflow = (adding || consenting || leaving) ? 'hidden' : '';
});
</script>

<style scoped>
.modal {
    display: block;
    z-index: 1055;
}

.modal-dialog {
    margin: 1.75rem auto;
}
</style>
