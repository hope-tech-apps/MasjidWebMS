<template>
    <div class="form-builder">
        <div v-if="loading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm me-2"></span>
            Loading form…
        </div>

        <template v-else>
            <!-- ------------------------------------------------------------ basics -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-card-heading me-2"></i>Form details</h6>

                    <div class="row">
                        <div class="col-md-7 mb-3">
                            <label class="form-label">Form name <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                :value="draft.name"
                                @input="onNameInput(($event.target as HTMLInputElement).value)"
                                placeholder="e.g. Ashab al-Kahf Youth Retreat 2026"
                            />
                        </div>

                        <div class="col-md-5 mb-3">
                            <label class="form-label">Address <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control font-monospace"
                                :class="{ 'is-invalid': !!slugProblem }"
                                v-model.trim="draft.slug"
                                placeholder="camp-2026"
                            />
                            <div v-if="slugProblem" class="invalid-feedback d-block">{{ slugProblem }}</div>
                            <small v-else class="form-text text-muted">
                                Lowercase letters, numbers and dashes. Unique within this masjid.
                            </small>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea
                                class="form-control"
                                v-model="draft.description"
                                rows="2"
                                placeholder="One line the admin list shows — dates, venue, who it is for."
                            ></textarea>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label d-block">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="formBuilderActive"
                                    v-model="draft.is_active"
                                />
                                <label class="form-check-label" for="formBuilderActive">
                                    {{ draft.is_active ? 'Accepting' : 'Switched off' }}
                                </label>
                            </div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Capacity</label>
                            <input
                                type="number"
                                class="form-control"
                                min="1"
                                :value="draft.capacity ?? ''"
                                @input="draft.capacity = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                placeholder="Unlimited"
                            />
                            <small class="form-text text-muted">Total entries, not submissions.</small>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Opens</label>
                            <input type="datetime-local" class="form-control" v-model="draft.opens_at" />
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Closes</label>
                            <input type="datetime-local" class="form-control" v-model="draft.closes_at" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---------------------------------------------------------- sections -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-list-task me-2"></i>Questions</h6>
                <button type="button" class="btn btn-sm btn-primary" @click="addSection">
                    <i class="bi bi-plus-circle"></i> Add Section
                </button>
            </div>

            <div
                v-for="(section, sectionIndex) in draft.sections"
                :key="sectionIndex"
                class="card mb-3 section-card"
            >
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                        <div class="text-truncate">
                            <strong>{{ section.title || `Section ${sectionIndex + 1}` }}</strong>
                            <span class="text-muted small ms-2">
                                {{ section.fields.length }} question{{ section.fields.length === 1 ? '' : 's' }}
                                <template v-if="section.repeatable">
                                    · repeats {{ section.minEntries ?? 0 }}–{{ section.maxEntries || '∞' }}
                                </template>
                            </span>
                        </div>
                        <div class="btn-group flex-shrink-0">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="moveSection(sectionIndex, -1)"
                                :disabled="sectionIndex === 0"
                                title="Move Up"
                            >
                                <i class="bi bi-arrow-up"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="moveSection(sectionIndex, 1)"
                                :disabled="sectionIndex === draft.sections.length - 1"
                                title="Move Down"
                            >
                                <i class="bi bi-arrow-down"></i>
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                @click="removeSection(sectionIndex)"
                                :disabled="draft.sections.length === 1"
                                title="Remove Section"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-7 mb-3">
                            <label class="form-label">Section heading</label>
                            <input
                                type="text"
                                class="form-control"
                                :value="section.title ?? ''"
                                @input="onSectionTitleInput(section, ($event.target as HTMLInputElement).value)"
                                placeholder="e.g. Who Is Attending?"
                            />
                        </div>

                        <div class="col-md-5 mb-3">
                            <label class="form-label">Section key</label>
                            <input
                                type="text"
                                class="form-control form-control-sm font-monospace"
                                :class="{ 'is-invalid': !!sectionIdProblems[sectionIndex] }"
                                v-model.trim="section.id"
                            />
                            <div v-if="sectionIdProblems[sectionIndex]" class="invalid-feedback d-block">
                                {{ sectionIdProblems[sectionIndex] }}
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Section note</label>
                            <input
                                type="text"
                                class="form-control"
                                v-model="section.description"
                                placeholder="Shown under the heading"
                            />
                        </div>

                        <!-- Repeatable -->
                        <div class="col-md-4 mb-3">
                            <label class="form-label d-block">Repeats?</label>
                            <div class="form-check form-switch mt-2">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    :id="`sectionRepeatable${sectionIndex}`"
                                    :checked="!!section.repeatable"
                                    :disabled="!section.repeatable && hasRepeatableSection"
                                    @change="onRepeatableToggle(section, ($event.target as HTMLInputElement).checked)"
                                />
                                <label class="form-check-label" :for="`sectionRepeatable${sectionIndex}`">
                                    {{ section.repeatable ? 'One entry per person' : 'Asked once' }}
                                </label>
                            </div>
                            <small v-if="!section.repeatable && hasRepeatableSection" class="form-text text-muted">
                                A form can have only one repeating section.
                            </small>
                        </div>

                        <template v-if="section.repeatable">
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Min entries</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    min="0"
                                    :value="section.minEntries ?? ''"
                                    @input="section.minEntries = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                    placeholder="0"
                                />
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Max entries</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    min="1"
                                    :value="section.maxEntries ?? ''"
                                    @input="section.maxEntries = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                    placeholder="No limit"
                                />
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Add button label</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="section.addButtonLabel"
                                    placeholder="Add another attendee"
                                />
                            </div>
                        </template>
                    </div>

                    <!-- Questions -->
                    <FormFieldEditor
                        v-for="(field, fieldIndex) in section.fields"
                        :key="fieldIndex"
                        :field="field"
                        :index="fieldIndex"
                        :total="section.fields.length"
                        :field-types="formsStore.fieldTypes"
                        :id-prefix="`form_s${sectionIndex}_f${fieldIndex}`"
                        :name-problem="fieldNameProblems[`${sectionIndex}:${fieldIndex}`] ?? null"
                        :conditional-sources="conditionalSourcesFor(section)"
                        @label-input="onFieldLabelInput(section, field, $event)"
                        @type-change="onFieldTypeChange(field, $event)"
                        @move-up="moveField(section, fieldIndex, -1)"
                        @move-down="moveField(section, fieldIndex, 1)"
                        @remove="removeField(section, fieldIndex)"
                    />

                    <div v-if="section.fields.length === 0" class="alert alert-warning py-2 small">
                        Every section needs at least one question.
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addField(section)">
                        <i class="bi bi-plus-circle"></i> Add Question
                    </button>
                </div>
            </div>

            <!-- ---------------------------------------------------- identity + fee -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-1"><i class="bi bi-person-vcard me-2"></i>Who is this response from?</h6>
                    <p class="text-muted small">
                        These three answers are copied onto the response so the Form Responses list can
                        search and contact people. Every choice must be a question this form actually asks.
                    </p>

                    <div class="row">
                        <div v-for="slot in IDENTITY_SLOTS" :key="slot.key" class="col-md-4 mb-3">
                            <label class="form-label" :for="`formIdentity_${slot.slot}`">{{ slot.label }}</label>
                            <select
                                :id="`formIdentity_${slot.slot}`"
                                class="form-select"
                                :class="{ 'is-invalid': !!fieldIssue(`settings.identity.${slot.slot}`) }"
                                v-model="draft.settings[slot.key]"
                                @change="clearServerError(`settings.identity.${slot.slot}`)"
                            >
                                <option :value="null">Not captured</option>
                                <!-- Several questions joined (first + last name) arrive from an import
                                     file; they are kept as they are unless one question is picked. -->
                                <option v-if="Array.isArray(draft.settings[slot.key])" :value="draft.settings[slot.key]">
                                    {{ joinedQuestionLabels(draft.settings[slot.key]) }}
                                </option>
                                <option v-for="field in flatFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>
                            <div v-if="fieldIssue(`settings.identity.${slot.slot}`)" class="invalid-feedback d-block">
                                {{ fieldIssue(`settings.identity.${slot.slot}`) }}
                            </div>
                        </div>
                    </div>

                    <h6 class="mb-1 mt-2"><i class="bi bi-cash-coin me-2"></i>Fee</h6>
                    <p v-if="!paymentOn" class="text-muted small">
                        Recorded as the amount owed on each response. No payment is taken here: the
                        total is worked out when the form is submitted and then frozen, so a later
                        price change never restates what somebody already agreed to pay. To take
                        payment, switch it on under Payment below.
                    </p>
                    <p v-else class="text-muted small">
                        This form takes payment (see Payment below), so this is the price people pay,
                        per entry of the section chosen here. The total is worked out when the form is
                        submitted and then frozen, so a later price change never restates what somebody
                        already agreed to pay.
                    </p>

                    <div v-if="fieldIssue('settings.fee')" class="alert alert-danger py-2 small" role="alert">
                        {{ fieldIssue('settings.fee') }}
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="formFeeAmount">
                                {{ draft.settings.feeTiers.length ? 'Price when no step applies' : 'Amount' }}
                            </label>
                            <input
                                id="formFeeAmount"
                                type="number"
                                class="form-control"
                                :class="{ 'is-invalid': !!fieldIssue('settings.fee.amount') }"
                                min="0"
                                step="0.01"
                                :value="draft.settings.feeAmount ?? ''"
                                @input="draft.settings.feeAmount = toNumberOrNull(($event.target as HTMLInputElement).value); clearServerError('settings.fee.amount'); clearServerError('settings.fee')"
                                :placeholder="draft.settings.feeTiers.length ? 'Optional' : 'No fee'"
                            />
                            <div v-if="fieldIssue('settings.fee.amount')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.fee.amount') }}
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="formFeeCurrency">Currency</label>
                            <input
                                id="formFeeCurrency"
                                type="text"
                                class="form-control text-uppercase"
                                :class="{ 'is-invalid': !!fieldIssue('settings.fee.currency') }"
                                maxlength="3"
                                v-model.trim="draft.settings.feeCurrency"
                                @input="clearServerError('settings.fee.currency')"
                                placeholder="USD"
                            />
                            <div v-if="fieldIssue('settings.fee.currency')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.fee.currency') }}
                            </div>
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label" for="formFeePerEntry">Charged</label>
                            <select
                                id="formFeePerEntry"
                                class="form-select"
                                :class="{ 'is-invalid': !!fieldIssue('settings.fee.perEntryOfSection') }"
                                v-model="draft.settings.feePerEntryOfSection"
                                @change="clearServerError('settings.fee.perEntryOfSection')"
                            >
                                <option :value="null">Once per submission</option>
                                <option
                                    v-for="section in repeatableSections"
                                    :key="section.id"
                                    :value="section.id"
                                >
                                    Per entry of "{{ section.title || section.id }}"
                                </option>
                            </select>
                            <div v-if="fieldIssue('settings.fee.perEntryOfSection')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.fee.perEntryOfSection') }}
                            </div>
                        </div>
                    </div>

                    <!-- Price steps: an early-bird price, then a standard one. Carried through a
                         save even when nobody edits them, or saving would drop the price. -->
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                            <span class="form-label mb-0">Price steps by date</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                :disabled="draft.settings.feeTiers.length >= 10"
                                @click="addTier"
                            >
                                <i class="bi bi-plus-circle"></i> Add a price step
                            </button>
                        </div>
                        <p class="form-text mt-0">
                            Optional. Each step's price applies up to and including its date; leave the last
                            step's date empty so it applies from then on. When a step applies, it replaces the
                            amount above.
                        </p>

                        <div
                            v-for="(tier, tierIndex) in draft.settings.feeTiers"
                            :key="tierIndex"
                            class="row g-2 align-items-start mb-2"
                            role="group"
                            :aria-label="`Price step ${tierIndex + 1}`"
                        >
                            <div class="col-md-4">
                                <label class="form-label small mb-1" :for="`formTierLabel${tierIndex}`">Name</label>
                                <input
                                    :id="`formTierLabel${tierIndex}`"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': !!fieldIssue(`settings.fee.tiers.${tierIndex}.label`) }"
                                    maxlength="60"
                                    v-model="tier.label"
                                    placeholder="Early bird"
                                />
                                <div v-if="fieldIssue(`settings.fee.tiers.${tierIndex}.label`)" class="invalid-feedback d-block">
                                    {{ fieldIssue(`settings.fee.tiers.${tierIndex}.label`) }}
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`formTierAmount${tierIndex}`">Price</label>
                                <input
                                    :id="`formTierAmount${tierIndex}`"
                                    type="number"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': !!fieldIssue(`settings.fee.tiers.${tierIndex}.amount`) }"
                                    min="0"
                                    step="0.01"
                                    :value="tier.amount ?? ''"
                                    @input="tier.amount = toNumberOrNull(($event.target as HTMLInputElement).value); clearServerError(`settings.fee.tiers.${tierIndex}.amount`); clearServerError('settings.fee')"
                                />
                                <div v-if="fieldIssue(`settings.fee.tiers.${tierIndex}.amount`)" class="invalid-feedback d-block">
                                    {{ fieldIssue(`settings.fee.tiers.${tierIndex}.amount`) }}
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1" :for="`formTierUntil${tierIndex}`">Until (inclusive)</label>
                                <input
                                    :id="`formTierUntil${tierIndex}`"
                                    type="date"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': !!fieldIssue(`settings.fee.tiers.${tierIndex}.until`) }"
                                    v-model="tier.until"
                                    @input="clearServerError(`settings.fee.tiers.${tierIndex}.until`)"
                                />
                                <div v-if="fieldIssue(`settings.fee.tiers.${tierIndex}.until`)" class="invalid-feedback d-block">
                                    {{ fieldIssue(`settings.fee.tiers.${tierIndex}.until`) }}
                                </div>
                            </div>
                            <div class="col-md-1">
                                <span class="form-label small mb-1 d-none d-md-block" aria-hidden="true">&nbsp;</span>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :aria-label="`Remove price step ${tierIndex + 1}`"
                                    @click="removeTier(tierIndex)"
                                >
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------------------ payment -->
            <!-- settings.payment (DECISIONS.md 2026-09-11). Off unless switched on here: the block
                 is written once card payment or staff codes is on, and kept on a form that loaded
                 with one (buildPaymentBlock()). -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-1"><i class="bi bi-credit-card me-2"></i>Payment</h6>
                    <p class="text-muted small">
                        Off by default. With either kind of payment on, the fee above must be charged per
                        entry of a section that needs at least one entry, and every price must be at least
                        $0.50.
                    </p>

                    <!-- Card -->
                    <div class="form-check form-switch">
                        <input
                            id="formPaymentOnline"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            v-model="draft.settings.paymentOnline"
                            aria-describedby="formPaymentOnlineHelp"
                            @change="clearServerError('settings.payment.online'); clearServerError('settings.fee.currency')"
                        />
                        <label class="form-check-label" for="formPaymentOnline">Take card payment online</label>
                    </div>
                    <div id="formPaymentOnlineHelp" class="form-text mb-2">
                        After submitting, people go to a Stripe page to pay by card. The money goes to this
                        organisation's own Stripe account, so card payment works only once that account is
                        connected. Card payment is in US dollars only.
                    </div>
                    <div v-if="fieldIssue('settings.payment.online')" class="invalid-feedback d-block mb-2">
                        {{ fieldIssue('settings.payment.online') }}
                    </div>

                    <div v-if="draft.settings.paymentOnline" class="mb-3" role="status">
                        <div v-if="connectState === 'loading'" class="small text-muted">
                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                            Checking this organisation's Stripe connection…
                        </div>
                        <div v-else-if="connectState === 'ready'" class="small text-success">
                            <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                            This organisation's Stripe account is connected and can take card payments.
                        </div>
                        <div v-else-if="connectState === 'none'" class="alert alert-warning py-2 small mb-0">
                            This organisation has not connected a Stripe account, so card payment will be refused.
                            Connect it on the
                            <a v-if="donationsHref" :href="donationsHref" target="_blank" rel="noopener">Giving Dashboard (opens in a new tab)</a><span v-else>Giving Dashboard</span>.
                        </div>
                        <div v-else-if="connectState === 'unfinished'" class="alert alert-warning py-2 small mb-0">
                            This organisation's Stripe setup is not finished, so card payment will be refused until it
                            is. Finish it on the
                            <a v-if="donationsHref" :href="donationsHref" target="_blank" rel="noopener">Giving Dashboard (opens in a new tab)</a><span v-else>Giving Dashboard</span>.
                        </div>
                        <div v-else-if="connectState === 'forbidden'" class="small text-muted">
                            This account cannot see whether Stripe is connected. Ask an admin who manages donations
                            to check before switching this on.
                        </div>
                        <div v-else-if="connectState === 'failed'" class="small text-muted">
                            Could not check the Stripe connection just now. Card payment works only once it is connected.
                        </div>
                    </div>

                    <!-- Card fee -->
                    <div class="form-check form-switch ms-md-4">
                        <input
                            id="formPaymentFeeCover"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            v-model="draft.settings.paymentAllowFeeCoverage"
                            :disabled="!draft.settings.paymentOnline"
                            aria-describedby="formPaymentFeeCoverHelp"
                            @change="clearServerError('settings.payment.allowFeeCoverage')"
                        />
                        <label class="form-check-label" for="formPaymentFeeCover">Offer to cover the card fee</label>
                    </div>
                    <div id="formPaymentFeeCoverHelp" class="form-text ms-md-4 mb-3">
                        Adds an optional checkbox so a card payer can add the card processing fee to their total.
                        Card payments only.
                    </div>
                    <div v-if="fieldIssue('settings.payment.allowFeeCoverage')" class="invalid-feedback d-block ms-md-4 mb-2">
                        {{ fieldIssue('settings.payment.allowFeeCoverage') }}
                    </div>

                    <!-- Staff codes -->
                    <div class="form-check form-switch">
                        <input
                            id="formPaymentStaffCodes"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            v-model="draft.settings.paymentStaffCodes"
                            aria-describedby="formPaymentStaffCodesHelp"
                            @change="clearServerError('settings.payment.staffCodes')"
                        />
                        <label class="form-check-label" for="formPaymentStaffCodes">Staff cash codes</label>
                    </div>
                    <div id="formPaymentStaffCodesHelp" class="form-text mb-2">
                        Each staff member gets a secret code for recording walk-up entries paid in cash on this
                        form's page, and the cash is counted against them. Staff entries still work after the
                        closing time, but not while the form is switched off or full.
                    </div>
                    <div v-if="fieldIssue('settings.payment.staffCodes')" class="invalid-feedback d-block mb-2">
                        {{ fieldIssue('settings.payment.staffCodes') }}
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label" for="formPaymentEventDate">Event date</label>
                            <!-- Locked until a kind of payment is on: a date alone would write the
                                 payment block (buildPaymentBlock()). -->
                            <input
                                id="formPaymentEventDate"
                                type="date"
                                class="form-control"
                                :class="{ 'is-invalid': !!fieldIssue('settings.payment.eventDate') }"
                                v-model="draft.settings.paymentEventDate"
                                :disabled="!paymentOn"
                                :aria-describedby="paymentOn ? 'formPaymentEventDateHelp' : 'formPaymentEventDateLocked formPaymentEventDateHelp'"
                                @input="clearServerError('settings.payment.eventDate')"
                            />
                            <div v-if="!paymentOn" id="formPaymentEventDateLocked" class="form-text">
                                Switch on card payment or staff cash codes above to set the event date.
                            </div>
                            <div v-if="fieldIssue('settings.payment.eventDate')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.payment.eventDate') }}
                            </div>
                        </div>
                        <div class="col-md-8 mb-2 d-flex align-items-md-end">
                            <div id="formPaymentEventDateHelp" class="form-text">
                                Staff codes stop working at midnight at the end of this day, on this organisation's
                                clock, unless a code is given its own last day. Save the form for a new date to
                                apply to codes added afterwards.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <button
                            v-if="props.formId"
                            type="button"
                            class="btn btn-sm btn-outline-primary"
                            @click="showStaffCodes = true"
                        >
                            <i class="bi bi-key me-1" aria-hidden="true"></i>Manage staff codes
                        </button>
                        <span v-else class="small text-muted">Save the form first, then add staff codes here.</span>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------------- copy/wording -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-3"><i class="bi bi-chat-left-text me-2"></i>Wording</h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Submit button</label>
                            <input
                                type="text"
                                class="form-control"
                                v-model="draft.settings.submitButtonLabel"
                                placeholder="Submit"
                            />
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Notify these emails</label>
                            <div
                                v-for="(email, emailIndex) in draft.settings.notifyEmails"
                                :key="emailIndex"
                                class="input-group input-group-sm mb-1"
                            >
                                <input type="email" class="form-control" v-model.trim="draft.settings.notifyEmails[emailIndex]" />
                                <button type="button" class="btn btn-outline-danger" @click="draft.settings.notifyEmails.splice(emailIndex, 1)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="draft.settings.notifyEmails.push('')">
                                <i class="bi bi-plus-circle"></i> Add Email
                            </button>
                            <div class="form-text">
                                Emailed whenever someone submits. Leave empty and it goes to the
                                masjid's own contact address.
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="form-confirmation-email"
                                    v-model="draft.settings.confirmationEmail"
                                />
                                <label class="form-check-label" for="form-confirmation-email">
                                    Email a confirmation to whoever fills this in
                                </label>
                            </div>
                            <div class="form-text">
                                Sends a copy of what they submitted, including the total owed.
                                Turning this off usually means people submit twice because they
                                aren't sure it worked.
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Payment note</label>
                            <textarea
                                class="form-control"
                                v-model="draft.settings.paymentNote"
                                rows="2"
                                placeholder="When payment is due, how to pay, any card surcharge."
                            ></textarea>
                            <div class="form-text">
                                Shown on the confirmation email beside the total, until the registration
                                is paid. Only useful when this form charges money.
                            </div>
                        </div>

                        <div class="col-md-8 mb-3">
                            <label class="form-label" for="formWhatsappUrl">WhatsApp group link</label>
                            <input
                                id="formWhatsappUrl"
                                type="url"
                                inputmode="url"
                                class="form-control"
                                :class="{ 'is-invalid': !!fieldIssue('settings.whatsappUrl') }"
                                maxlength="120"
                                v-model.trim="draft.settings.whatsappUrl"
                                placeholder="https://chat.whatsapp.com/…"
                                aria-describedby="formWhatsappUrlHelp"
                                @input="clearServerError('settings.whatsappUrl')"
                            />
                            <div v-if="fieldIssue('settings.whatsappUrl')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.whatsappUrl') }}
                            </div>
                            <div id="formWhatsappUrlHelp" class="form-text">
                                Given to each person once their registration is settled (paid, or nothing to
                                pay): on the page they see after registering, and in their confirmation email
                                when this form sends one. It is never shown on the form's page itself. Only a
                                https://chat.whatsapp.com/ invite link is accepted.
                            </div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="formWhatsappLabel">WhatsApp button label</label>
                            <input
                                id="formWhatsappLabel"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': !!fieldIssue('settings.whatsappLabel') }"
                                maxlength="80"
                                v-model="draft.settings.whatsappLabel"
                                placeholder="Join the WhatsApp group"
                                @input="clearServerError('settings.whatsappLabel')"
                            />
                            <div v-if="fieldIssue('settings.whatsappLabel')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.whatsappLabel') }}
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Introduction</label>
                            <textarea
                                class="form-control"
                                v-model="draft.settings.intro"
                                rows="4"
                                placeholder="Shown above the first question. Basic HTML is allowed."
                            ></textarea>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Thank-you heading</label>
                            <input
                                type="text"
                                class="form-control"
                                v-model="draft.settings.successTitle"
                                placeholder="Jazak Allahu Khairan — your registration is in!"
                            />
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Thank-you message</label>
                            <textarea class="form-control" v-model="draft.settings.successBody" rows="2"></textarea>
                        </div>

                        <div class="col-12 mb-2">
                            <label class="form-label">What happens next</label>
                            <div
                                v-for="(step, stepIndex) in draft.settings.successNextSteps"
                                :key="stepIndex"
                                class="input-group input-group-sm mb-1"
                            >
                                <input type="text" class="form-control" v-model="draft.settings.successNextSteps[stepIndex]" />
                                <button type="button" class="btn btn-outline-danger" @click="draft.settings.successNextSteps.splice(stepIndex, 1)">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="draft.settings.successNextSteps.push('')">
                                <i class="bi bi-plus-circle"></i> Add Step
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------------------------------------ footer -->
            <div v-if="problems.length" class="alert alert-warning">
                <strong><i class="bi bi-exclamation-triangle me-2"></i>Fix these before saving</strong>
                <ul class="mb-0 mt-2 ps-3">
                    <li v-for="(problem, problemIndex) in problems" :key="problemIndex">{{ problem }}</li>
                </ul>
            </div>

            <div v-if="serverErrors.length" class="alert alert-danger">
                <strong><i class="bi bi-x-octagon me-2"></i>The server rejected this form</strong>
                <ul class="mb-0 mt-2 ps-3">
                    <li v-for="(error, errorIndex) in serverErrors" :key="errorIndex">{{ error }}</li>
                </ul>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" @click="emit('cancel')" :disabled="saving">
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="save"
                    :disabled="saving || problems.length > 0"
                >
                    <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                    <i class="bi bi-check-circle me-1"></i>
                    {{ props.formId ? 'Save Form' : 'Create Form' }}
                </button>
            </div>
        </template>

        <FormStaffCodesModal
            v-if="props.formId"
            :show="showStaffCodes"
            :form-id="props.formId"
            :form-name="draft.name"
            @close="showStaffCodes = false"
        />
    </div>
</template>

<script setup lang="ts">
import {
    CHOICE_FIELD_TYPES,
    Form,
    FormField,
    FormFieldOption,
    FormFieldType,
    FormIdentityMap,
    FormPayload,
    FormSchemaSection,
    FormSettings,
    FormFeeRule,
    FormFeeTier,
    FormPaymentSettings,
    FORM_IDENTIFIER_PATTERN,
    FORM_WHATSAPP_URL_PATTERN,
    deriveFormIdentifier,
    deriveFormSlug,
    uniqueFormIdentifier
} from '@/core/types/data/masjid-related/Form';
import FormFieldEditor from '@/components/forms/FormFieldEditor.vue';
import FormStaffCodesModal from '@/components/forms/FormStaffCodesModal.vue';
import { useFormsStore } from '@/stores/masjid/formsStore';
import { isForbidden, useConnectStore } from '@/stores/masjid/connectStore';
import { serverFieldErrors, serverMessage } from '@/core/helpers/serverMessage';
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';

/**
 * The sign-up form builder: sections, questions, the identity map, the fee rule (with its
 * date-stepped prices), payment (card, staff cash codes, the event day) and the WhatsApp
 * group link.
 *
 * Settings travel in BOTH directions through load() and buildPayload(), and a key needs
 * both edits or saving silently drops it (.claude/rules/shipping.md): the festival form's
 * price lives in fee.tiers, and a save that forgot them would make it free.
 *
 * Any settings key the builder does not show, and any extra key on the fee, a price step
 * or the payment block, is sent back as it was loaded. That keeps a key the server has a
 * rule for (StoreFormRequest::settingsRules()) and this screen has no control for. It does
 * NOT keep a key no rule names: Laravel's validated() leaves those out, so the PUT and
 * form:import alike store only the ruled keys, and the save still reports success. A key
 * that must survive a save needs a rule there.
 *
 * It mirrors App\Rules\ValidFormSchema client-side — every rule the server enforces is
 * also computed here as a `problem` and blocks the Save button. That is a courtesy, not
 * the enforcement: the server re-checks everything, and a 422 is surfaced verbatim.
 *
 * Answer keys and section keys are derived from their labels but stay editable, because
 * a key is the storage key of every answer already collected under it.
 */
const props = defineProps<{
    /** Null / omitted builds a new form; an id loads that form for editing. */
    formId?: number | null;
}>();

const emit = defineEmits<{
    saved: [form: Form];
    cancel: [];
}>();

const formsStore = useFormsStore();

/**
 * The settings block, flattened for binding. FormSettings nests identity and fee, both
 * of which the server rejects when half-filled (`fee.amount` is required_with:fee), so
 * the draft keeps them flat and buildPayload() reassembles — or omits — them.
 */
type IdentityValue = string | string[] | null;

/**
 * One price step as the builder edits it. `extra` carries any key it does not show, sent
 * back as loaded; the server keeps one only if a rule names it (see the top of this file).
 */
type DraftTier = {
    label: string;
    amount: number | null;
    /** 'YYYY-MM-DD', or '' for the last, open-ended step. */
    until: string;
    extra: Record<string, unknown>;
};

type DraftSettings = {
    submitButtonLabel: string;
    successTitle: string;
    successBody: string;
    successNextSteps: string[];
    notifyEmails: string[];
    confirmationEmail: boolean;
    paymentNote: string;
    intro: string;
    identityName: IdentityValue;
    identityEmail: IdentityValue;
    identityPhone: IdentityValue;
    feeAmount: number | null;
    feeCurrency: string;
    feePerEntryOfSection: string | null;
    feeTiers: DraftTier[];
    // settings.payment, flattened like identity and fee.
    paymentOnline: boolean;
    paymentStaffCodes: boolean;
    paymentAllowFeeCoverage: boolean;
    /** 'YYYY-MM-DD', or '' for none. */
    paymentEventDate: string;
    whatsappUrl: string;
    whatsappLabel: string;
};

/**
 * What the loaded form carried that the builder does not edit, sent back as loaded by
 * buildPayload(); the server stores only the keys a rule names (see the top of this file).
 * `hadPaymentBlock` is whether settings.payment existed at all: its presence alone
 * (Form::hasPaymentSettings()) changes the list and both CSVs, so it is kept on a form that
 * had it, and written for one that did not only once a kind of payment is switched on.
 */
type Preserved = {
    settings: Record<string, unknown>;
    fee: Record<string, unknown>;
    payment: Record<string, unknown>;
    hadPaymentBlock: boolean;
};

/** The settings keys buildPayload() writes itself; every other key is sent back as loaded. */
const MANAGED_SETTINGS_KEYS = [
    'submitButtonLabel', 'successTitle', 'successBody', 'successNextSteps', 'notifyEmails',
    'confirmationEmail', 'paymentNote', 'intro', 'identity', 'fee', 'payment',
    'whatsappUrl', 'whatsappLabel'
] as const;
const MANAGED_FEE_KEYS = ['amount', 'currency', 'perEntryOfSection', 'tiers'] as const;
const MANAGED_PAYMENT_KEYS = ['online', 'staffCodes', 'allowFeeCoverage', 'eventDate'] as const;

const IDENTITY_SLOTS = [
    { key: 'identityName', slot: 'name', label: 'Name question' },
    { key: 'identityEmail', slot: 'email', label: 'Email question' },
    { key: 'identityPhone', slot: 'phone', label: 'Phone question' }
] as const;

/** FormPayment::MIN_CHARGE_MINOR: Stripe's smallest card charge. */
const MIN_CHARGE_MINOR = 50;

type Draft = {
    name: string;
    slug: string;
    description: string;
    is_active: boolean;
    /** datetime-local strings ("2026-09-04T18:00"), '' meaning unbounded. */
    opens_at: string;
    closes_at: string;
    capacity: number | null;
    sections: FormSchemaSection[];
    settings: DraftSettings;
};

const loading = ref(false);
const saving = ref(false);
const serverErrors = ref<string[]>([]);

const blankSettings = (): DraftSettings => ({
    submitButtonLabel: 'Submit',
    successTitle: '',
    successBody: '',
    successNextSteps: [],
    notifyEmails: [],
    // On by default: a form that collects an email should acknowledge it.
    confirmationEmail: true,
    paymentNote: '',
    intro: '',
    identityName: null,
    identityEmail: null,
    identityPhone: null,
    feeAmount: null,
    feeCurrency: 'USD',
    feePerEntryOfSection: null,
    feeTiers: [],
    paymentOnline: false,
    paymentStaffCodes: false,
    paymentAllowFeeCoverage: false,
    paymentEventDate: '',
    whatsappUrl: '',
    whatsappLabel: ''
});

/**
 * A new form starts with the three questions every sign-up needs, already wired into the
 * identity map — so a form is searchable and contactable by default rather than only if
 * the admin remembers to set it up.
 */
const blankDraft = (): Draft => ({
    name: '',
    slug: '',
    description: '',
    is_active: true,
    opens_at: '',
    closes_at: '',
    capacity: null,
    sections: [
        {
            id: 'yourInformation',
            title: 'Your Information',
            description: '',
            fields: [
                { name: 'fullName', label: 'Full name', type: 'text', required: true, autocomplete: 'name' },
                { name: 'email', label: 'Email address', type: 'email', required: true, autocomplete: 'email' },
                { name: 'phone', label: 'Phone number', type: 'tel', required: true, autocomplete: 'tel' }
            ]
        }
    ],
    settings: {
        ...blankSettings(),
        identityName: 'fullName',
        identityEmail: 'email',
        identityPhone: 'phone'
    }
});

const draft = ref<Draft>(blankDraft());

const blankPreserved = (): Preserved => ({ settings: {}, fee: {}, payment: {}, hadPaymentBlock: false });

const preserved = ref<Preserved>(blankPreserved());

/** The 422's field errors, keyed as the server named them, for the inline messages. */
const serverFieldErrorsByKey = ref<Record<string, string[]>>({});

const showStaffCodes = ref(false);

// ------------------------------------------------------------ settings helpers

/** A plain object copy, or {} — PHP sends an empty array as [], never {}. */
const asRecord = (value: unknown): Record<string, any> =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? { ...(value as Record<string, any>) }
        : {};

const omit = (record: Record<string, any>, keys: readonly string[]): Record<string, any> => {
    const copy = { ...record };
    keys.forEach(key => delete copy[key]);
    return copy;
};

/** A price as a number: import files may carry "25.00". Null when it is not one. */
const toAmount = (value: unknown): number | null => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;

    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);
        return Number.isNaN(parsed) ? null : parsed;
    }

    return null;
};

/** A payment switch read the way Form::paymentFlag() reads the stored value. */
const readFlag = (value: unknown): boolean => {
    if (value === true || value === 1) return true;
    if (typeof value === 'string') return ['1', 'true', 'on', 'yes'].includes(value.trim().toLowerCase());
    return false;
};

const toDraftTier = (tier: unknown): DraftTier => {
    const record = asRecord(tier);

    return {
        label: typeof record.label === 'string' ? record.label : '',
        amount: toAmount(record.amount),
        until: typeof record.until === 'string' ? record.until : '',
        extra: omit(record, ['label', 'amount', 'until'])
    };
};

const buildTier = (tier: DraftTier): FormFeeTier => {
    // A missing price goes as null so the server names the step, as the problems list does.
    const clean: FormFeeTier = { ...tier.extra, amount: tier.amount as number };

    const label = tier.label.trim();
    if (label) clean.label = label;
    if (tier.until) clean.until = tier.until;

    return clean;
};

/**
 * settings.payment, or null to leave it out. Written for a form that already had one
 * (a festival form with both switches off after the day is still reconciled from its
 * payment columns), or once card payment or staff codes is switched on. Nothing else
 * writes it: its presence alone (Form::hasPaymentSettings()) puts a fee form's list and
 * both CSVs into payment mode, badging every family "Unpaid", and the builder has no way
 * back. So the card-fee switch alone does not (it does nothing without card payment), and
 * neither does the Event date, which is locked until one of the two is on.
 */
const buildPaymentBlock = (): FormPaymentSettings | null => {
    const s = draft.value.settings;
    const eventDate = s.paymentEventDate.trim();

    if (!preserved.value.hadPaymentBlock && !paymentOn.value) return null;

    const payment: FormPaymentSettings = {
        ...preserved.value.payment,
        online: s.paymentOnline,
        staffCodes: s.paymentStaffCodes,
        allowFeeCoverage: s.paymentAllowFeeCoverage
    };

    if (eventDate) payment.eventDate = eventDate;

    return payment;
};

const hasIdentity = (value: IdentityValue): boolean =>
    Array.isArray(value) ? value.length > 0 : !!value;

const identityNames = (value: IdentityValue): string[] =>
    Array.isArray(value) ? value.filter(name => typeof name === 'string') : (value ? [value] : []);

/** "First name + Last name" for an identity slot that joins several questions. */
const joinedQuestionLabels = (value: IdentityValue): string =>
    identityNames(value)
        .map(name => flatFields.value.find(field => field.name === name)?.label || name)
        .join(' + ');

// ------------------------------------------------------------------- load / save

/** ISO 8601 from the API -> the value a datetime-local input understands. */
const toDateTimeLocal = (iso: string | null): string => (iso ? iso.slice(0, 16) : '');

/** '' -> null so an emptied box clears the value rather than storing 0 or NaN. */
const toNumberOrNull = (value: string): number | null => {
    if (value === null || value.trim() === '') return null;
    const parsed = Number(value);
    return Number.isNaN(parsed) ? null : parsed;
};

/** The address follows the name until an admin edits the address by hand. */
const onNameInput = (name: string) => {
    const wasAuto = !draft.value.slug || draft.value.slug === deriveFormSlug(draft.value.name);

    draft.value.name = name;

    if (wasAuto) {
        draft.value.slug = deriveFormSlug(name);
    }
};

const load = async () => {
    serverErrors.value = [];
    serverFieldErrorsByKey.value = {};
    preserved.value = blankPreserved();

    if (!props.formId) {
        draft.value = blankDraft();
        return;
    }

    loading.value = true;

    try {
        const form = await formsStore.fetchForm(props.formId);

        if (!form) {
            draft.value = blankDraft();
            return;
        }

        const settings = asRecord(form.settings) as FormSettings;
        const identity = asRecord(settings.identity);
        const fee = asRecord(settings.fee);
        // An empty payment block arrives as [] and still counts: Form::hasPaymentSettings()
        // is is_array().
        const hadPaymentBlock = settings.payment !== null && settings.payment !== undefined && typeof settings.payment === 'object';
        const payment = asRecord(settings.payment);

        preserved.value = {
            settings: omit(settings, MANAGED_SETTINGS_KEYS),
            fee: omit(fee, MANAGED_FEE_KEYS),
            payment: omit(payment, MANAGED_PAYMENT_KEYS),
            hadPaymentBlock
        };

        draft.value = {
            name: form.name,
            slug: form.slug,
            description: form.description ?? '',
            is_active: form.is_active,
            opens_at: toDateTimeLocal(form.opens_at),
            closes_at: toDateTimeLocal(form.closes_at),
            capacity: form.capacity,
            sections: (form.schema?.sections ?? []).map(section => ({
                ...section,
                description: section.description ?? '',
                fields: (section.fields ?? []).map(field => ({ ...field }))
            })),
            settings: {
                ...blankSettings(),
                submitButtonLabel: settings.submitButtonLabel ?? 'Submit',
                successTitle: settings.successTitle ?? '',
                successBody: settings.successBody ?? '',
                successNextSteps: [...(settings.successNextSteps ?? [])],
                notifyEmails: [...(settings.notifyEmails ?? [])],
                confirmationEmail: settings.confirmationEmail !== false,
                paymentNote: settings.paymentNote ?? '',
                intro: settings.intro ?? '',
                identityName: identity.name ?? null,
                identityEmail: identity.email ?? null,
                identityPhone: identity.phone ?? null,
                feeAmount: toAmount(fee.amount),
                feeCurrency: typeof fee.currency === 'string' && fee.currency ? fee.currency : 'USD',
                feePerEntryOfSection: typeof fee.perEntryOfSection === 'string' && fee.perEntryOfSection
                    ? fee.perEntryOfSection
                    : null,
                feeTiers: (Array.isArray(fee.tiers) ? fee.tiers : []).map(toDraftTier),
                paymentOnline: readFlag(payment.online),
                paymentStaffCodes: readFlag(payment.staffCodes),
                paymentAllowFeeCoverage: readFlag(payment.allowFeeCoverage),
                paymentEventDate: typeof payment.eventDate === 'string' ? payment.eventDate : '',
                whatsappUrl: typeof settings.whatsappUrl === 'string' ? settings.whatsappUrl : '',
                whatsappLabel: typeof settings.whatsappLabel === 'string' ? settings.whatsappLabel : ''
            }
        };
    } catch (error: any) {
        console.error('Load form error: ', error);
        Swal.fire({ icon: 'error', title: 'Error!', text: 'Could not load this form.' });
    } finally {
        loading.value = false;
    }
};

watch(() => props.formId, load, { immediate: true });

// The palette is fetched once; the store keeps the compiled-in fallback until it lands.
formsStore.fetchFieldTypes();

/**
 * Draft -> the exact payload the API expects. Empty optional values are dropped rather
 * than sent as '' or null, so the stored schema stays the shape the renderer reads.
 */
const buildPayload = (): FormPayload => {
    const sections: FormSchemaSection[] = draft.value.sections.map(section => {
        const clean: FormSchemaSection = {
            id: section.id.trim(),
            fields: section.fields.map(field => buildField(field))
        };

        if (section.title) clean.title = section.title;
        if (section.description) clean.description = section.description;

        if (section.repeatable) {
            clean.repeatable = true;
            clean.minEntries = section.minEntries ?? 0;
            if (section.maxEntries) clean.maxEntries = section.maxEntries;
            if (section.addButtonLabel) clean.addButtonLabel = section.addButtonLabel;
        }

        return clean;
    });

    // Keys the builder does not edit go back as loaded (preserved, see load()); the server
    // keeps only the ones a rule names.
    const settings: FormSettings = { ...preserved.value.settings };
    const draftSettings = draft.value.settings;

    if (draftSettings.submitButtonLabel) settings.submitButtonLabel = draftSettings.submitButtonLabel;
    if (draftSettings.intro) settings.intro = draftSettings.intro;
    if (draftSettings.successTitle) settings.successTitle = draftSettings.successTitle;
    if (draftSettings.successBody) settings.successBody = draftSettings.successBody;

    const nextSteps = draftSettings.successNextSteps.map(step => step.trim()).filter(Boolean);
    if (nextSteps.length) settings.successNextSteps = nextSteps;

    const notifyEmails = draftSettings.notifyEmails.map(email => email.trim()).filter(Boolean);
    if (notifyEmails.length) settings.notifyEmails = notifyEmails;

    // Only sent when switched off — absent means on, which is the server's default too.
    if (!draftSettings.confirmationEmail) settings.confirmationEmail = false;

    const paymentNote = draftSettings.paymentNote.trim();
    if (paymentNote) settings.paymentNote = paymentNote;

    const identity: FormIdentityMap = {};
    if (hasIdentity(draftSettings.identityName)) identity.name = draftSettings.identityName;
    if (hasIdentity(draftSettings.identityEmail)) identity.email = draftSettings.identityEmail;
    if (hasIdentity(draftSettings.identityPhone)) identity.phone = draftSettings.identityPhone;
    if (Object.keys(identity).length) settings.identity = identity;

    // A fee is an amount, price steps, or both: the festival form has steps and no amount.
    // With neither there is no fee, and the form is free.
    const tiers = draftSettings.feeTiers.map(buildTier);

    if (draftSettings.feeAmount !== null || tiers.length) {
        const fee: FormFeeRule = {
            ...preserved.value.fee,
            currency: (draftSettings.feeCurrency || 'USD').toUpperCase(),
            perEntryOfSection: draftSettings.feePerEntryOfSection || null
        };

        if (draftSettings.feeAmount !== null) fee.amount = draftSettings.feeAmount;
        if (tiers.length) fee.tiers = tiers;

        settings.fee = fee;
    }

    const payment = buildPaymentBlock();
    if (payment) settings.payment = payment;

    const whatsappUrl = draftSettings.whatsappUrl.trim();
    if (whatsappUrl) settings.whatsappUrl = whatsappUrl;

    const whatsappLabel = draftSettings.whatsappLabel.trim();
    if (whatsappLabel) settings.whatsappLabel = whatsappLabel;

    return {
        name: draft.value.name.trim(),
        slug: draft.value.slug.trim(),
        description: draft.value.description.trim() || null,
        schema: { sections },
        settings,
        is_active: draft.value.is_active,
        opens_at: draft.value.opens_at || null,
        closes_at: draft.value.closes_at || null,
        capacity: draft.value.capacity
    };
};

const buildField = (field: FormField): FormField => {
    const clean: FormField = {
        name: field.name.trim(),
        label: field.label.trim(),
        type: field.type,
        required: !!field.required
    };

    if (field.help) clean.help = field.help;
    if (field.bodyText) clean.bodyText = field.bodyText;

    if (['text', 'email', 'tel', 'number', 'date', 'textarea'].includes(field.type)) {
        if (field.placeholder) clean.placeholder = field.placeholder;
        if (field.autocomplete) clean.autocomplete = field.autocomplete;
    }

    if (field.type === 'number') {
        if (field.min !== null && field.min !== undefined) clean.min = field.min;
        if (field.max !== null && field.max !== undefined) clean.max = field.max;
    }

    if (CHOICE_FIELD_TYPES.includes(field.type)) {
        clean.options = (field.options ?? []).map(option => {
            const cleanOption: FormFieldOption = {
                value: option.value.trim(),
                label: option.label.trim()
            };
            if (option.detail) cleanOption.detail = option.detail;
            return cleanOption;
        });
    }

    if (field.requiredIf) {
        clean.requiredIf = { ...field.requiredIf };
    }

    return clean;
};

const save = async () => {
    if (problems.value.length) return;

    saving.value = true;
    serverErrors.value = [];
    serverFieldErrorsByKey.value = {};

    try {
        const payload = buildPayload();

        const saved = props.formId
            ? await formsStore.updateForm(props.formId, payload)
            : await formsStore.createForm(payload);

        // The picker reads this list, so keep it in step with what was just saved.
        await formsStore.fetchFormOptions();

        Swal.fire({
            icon: 'success',
            title: 'Saved!',
            text: props.formId ? 'Form updated.' : 'Form created.',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });

        emit('saved', saved);
    } catch (error: any) {
        console.error('Save form error: ', error);

        // 422 -> { status: 'failed', data: { field: [message] } }. Each refusal shows beside
        // its field (crossCheck names them: settings.fee.tiers.1.amount, settings.whatsappUrl…)
        // and in the list above the Save button, so none is lost off-screen.
        const fields = serverFieldErrors(error);

        if (Object.keys(fields).length) {
            serverFieldErrorsByKey.value = fields;
            serverErrors.value = Object.values(fields).flat();
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: serverMessage(error, 'Failed to save the form. Please try again.')
            });
        }
    } finally {
        saving.value = false;
    }
};

// --------------------------------------------------------------------- structure

const hasRepeatableSection = computed(() => draft.value.sections.some(section => section.repeatable));

const repeatableSections = computed(() => draft.value.sections.filter(section => section.repeatable));

/** Questions outside the repeating section — the only ones the identity map may use. */
const flatFields = computed(() =>
    draft.value.sections
        .filter(section => !section.repeatable)
        .flatMap(section => section.fields.map(field => ({ name: field.name, label: field.label })))
);

/**
 * Repeating sections a conditional rule can watch. Nothing is offered inside a repeating
 * section: the backend only evaluates conditionals on flat questions, so a rule there
 * would never fire.
 */
const conditionalSourcesFor = (section: FormSchemaSection) => {
    if (section.repeatable) return [];

    return repeatableSections.value
        .map(candidate => ({
            id: candidate.id,
            title: candidate.title || candidate.id,
            fields: candidate.fields
                .filter(field => field.type === 'number')
                .map(field => ({ name: field.name, label: field.label }))
        }))
        .filter(candidate => candidate.fields.length > 0);
};

/**
 * New sections and questions start with an EMPTY key on purpose: the key follows the
 * heading/question only while it still matches what that text would derive, and a
 * placeholder like "section2" would never match — the key would then stay frozen at the
 * placeholder while the admin typed a real heading.
 */
const addSection = () => {
    draft.value.sections.push({
        id: '',
        title: '',
        description: '',
        fields: [{ name: '', label: '', type: 'text', required: false }]
    });
};

const removeSection = (index: number) => {
    if (draft.value.sections.length <= 1) return;
    draft.value.sections.splice(index, 1);
};

const moveSection = (index: number, direction: number) => {
    const target = index + direction;
    const sections = draft.value.sections;

    if (target < 0 || target >= sections.length) return;

    [sections[target], sections[index]] = [sections[index], sections[target]];
};

/**
 * Anything that referenced the old key follows it, so typing a heading cannot quietly
 * break the fee rule or a conditional that pointed at this section.
 */
const retargetSectionId = (previous: string, next: string) => {
    if (!previous || previous === next) return;

    if (draft.value.settings.feePerEntryOfSection === previous) {
        draft.value.settings.feePerEntryOfSection = next;
    }

    draft.value.sections.forEach(section => {
        section.fields.forEach(field => {
            if (field.requiredIf?.section === previous) {
                field.requiredIf.section = next;
            }
        });
    });
};

/** The section key follows the heading until an admin edits the key by hand. */
const onSectionTitleInput = (section: FormSchemaSection, title: string) => {
    const wasAuto = !section.id || section.id === deriveFormIdentifier(section.title ?? '', 'section');

    section.title = title;

    if (wasAuto) {
        const previous = section.id;
        const taken = draft.value.sections.filter(other => other !== section).map(other => other.id);
        section.id = uniqueFormIdentifier(deriveFormIdentifier(title, 'section'), taken);
        retargetSectionId(previous, section.id);
    }
};

const onRepeatableToggle = (section: FormSchemaSection, repeatable: boolean) => {
    section.repeatable = repeatable;

    if (repeatable) {
        section.minEntries = section.minEntries ?? 1;
        section.addButtonLabel = section.addButtonLabel || 'Add another';
        return;
    }

    // The fee and any conditional rules pointed at this section are now meaningless.
    if (draft.value.settings.feePerEntryOfSection === section.id) {
        draft.value.settings.feePerEntryOfSection = null;
    }

    draft.value.sections.forEach(other => {
        other.fields.forEach(field => {
            if (field.requiredIf?.section === section.id) {
                field.requiredIf = null;
            }
        });
    });
};

const addField = (section: FormSchemaSection) => {
    section.fields.push({
        name: '',
        label: '',
        type: 'text',
        required: false
    });
};

const removeField = (section: FormSchemaSection, index: number) => {
    section.fields.splice(index, 1);
};

const moveField = (section: FormSchemaSection, index: number, direction: number) => {
    const target = index + direction;

    if (target < 0 || target >= section.fields.length) return;

    [section.fields[target], section.fields[index]] = [section.fields[index], section.fields[target]];
};

/**
 * The identity map and any conditional rule that named the old key follow the rename.
 * Scoped to the section the question lives in, because keys are only unique within a
 * repeating section — a same-named question elsewhere must not be re-pointed.
 */
const retargetFieldName = (section: FormSchemaSection, previous: string, next: string) => {
    if (!previous || previous === next) return;

    // The identity map may only name questions outside the repeating section.
    if (!section.repeatable) {
        const settings = draft.value.settings;
        const follow = (value: IdentityValue): IdentityValue => Array.isArray(value)
            ? value.map(name => (name === previous ? next : name))
            : (value === previous ? next : value);

        settings.identityName = follow(settings.identityName);
        settings.identityEmail = follow(settings.identityEmail);
        settings.identityPhone = follow(settings.identityPhone);
    }

    draft.value.sections.forEach(other => {
        other.fields.forEach(candidate => {
            if (candidate.requiredIf?.section === section.id && candidate.requiredIf.field === previous) {
                candidate.requiredIf.field = next;
            }
        });
    });
};

/** The answer key follows the question until an admin edits the key by hand. */
const onFieldLabelInput = (section: FormSchemaSection, field: FormField, label: string) => {
    const wasAuto = !field.name || field.name === deriveFormIdentifier(field.label);

    field.label = label;

    if (wasAuto) {
        const previous = field.name;
        const taken = section.fields.filter(other => other !== field).map(other => other.name);
        field.name = uniqueFormIdentifier(deriveFormIdentifier(label), taken);
        retargetFieldName(section, previous, field.name);
    }
};

const onFieldTypeChange = (field: FormField, type: FormFieldType) => {
    field.type = type;

    // A choice question is refused without options, so open one empty row straight away.
    if (CHOICE_FIELD_TYPES.includes(type) && !field.options?.length) {
        field.options = [{ value: '', label: '', detail: null }];
    }

    // Only a number question can be the subject of a conditional rule.
    if (type !== 'number') {
        draft.value.sections.forEach(section => {
            section.fields.forEach(other => {
                if (other.requiredIf?.field === field.name) {
                    other.requiredIf = null;
                }
            });
        });
    }
};

// -------------------------------------------------------------------- validation
// A client-side mirror of App\Rules\ValidFormSchema + StoreFormRequest, so an admin sees
// what is wrong while they are looking at it instead of after a rejected save.

const slugProblem = computed(() => {
    const slug = draft.value.slug.trim();

    if (!slug) return 'An address is required.';
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
        return 'Use lowercase letters, numbers and single dashes — no spaces.';
    }

    return null;
});

/** Key problems by section index, for the inline invalid state on the key input. */
const sectionIdProblems = computed(() => {
    const problems: Record<number, string> = {};
    const seen: string[] = [];

    draft.value.sections.forEach((section, index) => {
        const id = (section.id || '').trim();

        if (!FORM_IDENTIFIER_PATTERN.test(id)) {
            problems[index] = 'Letters, numbers and underscores, starting with a letter.';
        } else if (seen.includes(id)) {
            problems[index] = `Another section already uses the key "${id}".`;
        }

        seen.push(id);
    });

    return problems;
});

/** Key problems by "sectionIndex:fieldIndex". */
const fieldNameProblems = computed(() => {
    const problems: Record<string, string> = {};
    const flatNames: string[] = [];
    const sectionIds = draft.value.sections.map(section => (section.id || '').trim());

    draft.value.sections.forEach((section, sectionIndex) => {
        const namesInSection: string[] = [];

        section.fields.forEach((field, fieldIndex) => {
            const key = `${sectionIndex}:${fieldIndex}`;
            const name = (field.name || '').trim();

            if (!FORM_IDENTIFIER_PATTERN.test(name)) {
                problems[key] = 'Letters, numbers and underscores, starting with a letter.';
            } else if (namesInSection.includes(name)) {
                problems[key] = `Another question in this section already uses "${name}".`;
            } else if (!section.repeatable && flatNames.includes(name)) {
                problems[key] = `The key "${name}" is already used elsewhere in this form.`;
            } else if (!section.repeatable && sectionIds.includes(name) && name !== sectionIds[sectionIndex]) {
                problems[key] = `The key "${name}" collides with a section key.`;
            }

            namesInSection.push(name);
            if (!section.repeatable) flatNames.push(name);
        });
    });

    return problems;
});

const problems = computed<string[]>(() => {
    const found: string[] = [];
    const label = (section: FormSchemaSection, index: number) => section.title || `Section ${index + 1}`;

    if (!draft.value.name.trim()) found.push('The form needs a name.');
    if (slugProblem.value) found.push(`Address: ${slugProblem.value}`);

    if (draft.value.sections.length === 0) {
        found.push('A form needs at least one section.');
    }

    Object.entries(sectionIdProblems.value).forEach(([index, problem]) => {
        found.push(`${label(draft.value.sections[Number(index)], Number(index))}: ${problem}`);
    });

    if (repeatableSections.value.length > 1) {
        found.push('A form can have at most one repeating section.');
    }

    draft.value.sections.forEach((section, sectionIndex) => {
        const name = label(section, sectionIndex);

        if (section.fields.length === 0) {
            found.push(`${name} needs at least one question.`);
        }

        if (section.repeatable) {
            const min = section.minEntries ?? 0;
            const max = section.maxEntries ?? 0;

            if (min < 0) found.push(`${name}: the minimum number of entries must be zero or more.`);
            if (max !== 0 && max < 1) found.push(`${name}: the maximum number of entries must be at least one.`);
            if (max !== 0 && max < min) found.push(`${name}: the maximum number of entries cannot be lower than the minimum.`);
        }

        section.fields.forEach((field, fieldIndex) => {
            const question = field.label?.trim() || field.name || `Question ${fieldIndex + 1}`;

            if (!field.label?.trim()) {
                found.push(`${name}: question ${fieldIndex + 1} needs a label.`);
            }

            const keyProblem = fieldNameProblems.value[`${sectionIndex}:${fieldIndex}`];
            if (keyProblem) {
                found.push(`${name} — "${question}": ${keyProblem}`);
            }

            if (CHOICE_FIELD_TYPES.includes(field.type)) {
                const options = field.options ?? [];

                if (options.length === 0) {
                    found.push(`${name} — "${question}" is a choice question, so it needs at least one choice.`);
                }

                if (options.some(option => !option.value?.trim() || !option.label?.trim())) {
                    found.push(`${name} — "${question}": every choice needs wording and a stored value.`);
                }

                const values = options.map(option => option.value?.trim());
                if (new Set(values).size !== values.length) {
                    found.push(`${name} — "${question}": two choices share the same stored value.`);
                }
            }

            if (
                field.type === 'number' &&
                field.min !== null && field.min !== undefined &&
                field.max !== null && field.max !== undefined &&
                field.max < field.min
            ) {
                found.push(`${name} — "${question}" has a maximum lower than its minimum.`);
            }

            if (field.requiredIf) {
                const source = draft.value.sections.find(candidate => candidate.id === field.requiredIf?.section);

                if (!source || !source.repeatable) {
                    found.push(`${name} — "${question}": the conditional rule points at a section that no longer repeats.`);
                } else {
                    const target = source.fields.find(candidate => candidate.name === field.requiredIf?.field);

                    if (!target) {
                        found.push(`${name} — "${question}": the conditional rule points at a question that no longer exists.`);
                    } else if (target.type !== 'number') {
                        found.push(`${name} — "${question}": the conditional rule compares a question that is not a number.`);
                    }
                }
            }
        });
    });

    // Identity and fee must reference things that exist, or the server refuses the save.
    const flatNames = flatFields.value.map(field => field.name);

    ([
        ['identityName', 'name'],
        ['identityEmail', 'email'],
        ['identityPhone', 'phone']
    ] as const).forEach(([key, slot]) => {
        const missing = identityNames(draft.value.settings[key]).filter(name => !flatNames.includes(name));

        if (missing.length) {
            found.push(`The ${slot} question points at "${missing.join('", "')}", which is not a question in this form.`);
        }
    });

    if (draft.value.settings.feeAmount !== null && draft.value.settings.feeAmount < 0) {
        found.push('The fee cannot be negative.');
    }

    const perEntry = draft.value.settings.feePerEntryOfSection;
    if (perEntry && !repeatableSections.value.some(section => section.id === perEntry)) {
        found.push(`The fee is charged per entry of "${perEntry}", which is not a repeating section.`);
    }

    Object.entries(paymentIssues.value).forEach(([key, message]) => {
        const step = /^settings\.fee\.tiers\.(\d+)\./.exec(key);

        if (step) {
            found.push(`Price step ${Number(step[1]) + 1}: ${message}`);
        } else if (key.startsWith('settings.whatsapp')) {
            found.push(`WhatsApp group link: ${message}`);
        } else {
            found.push(`Fee and payment: ${message}`);
        }
    });

    return found;
});

// ------------------------------------------------------------ fee and payment

/** Card payment or staff cash codes: the switches that put the fee under crossCheck's rules. */
const paymentOn = computed(() => draft.value.settings.paymentOnline || draft.value.settings.paymentStaffCodes);

/** Whole cents, with room for float noise (19.99 * 100 is 1998.9999999999998). */
const isWholeCents = (amount: number): boolean => Math.abs(Math.round(amount * 100) - amount * 100) < 1e-6;

/**
 * StoreFormRequest's settings rules and crossCheck()'s payment rules, keyed as the server
 * keys its refusals, so a problem shows beside its field before Save and a 422 lands on
 * the same spot. A courtesy: the server re-checks all of it.
 */
const paymentIssues = computed<Record<string, string>>(() => {
    const issues: Record<string, string> = {};
    const s = draft.value.settings;

    const link = s.whatsappUrl.trim();
    if (link && !FORM_WHATSAPP_URL_PATTERN.test(link)) {
        issues['settings.whatsappUrl'] = 'The WhatsApp group link must be a WhatsApp invite link starting https://chat.whatsapp.com/.';
    }

    s.feeTiers.forEach((tier, index) => {
        if (tier.amount === null) {
            issues[`settings.fee.tiers.${index}.amount`] = 'Every price step needs a price.';
        } else if (tier.amount < 0) {
            issues[`settings.fee.tiers.${index}.amount`] = 'A price cannot be negative.';
        }

        // A date input can only produce this shape; a stored unpadded date cannot be shown in one.
        if (tier.until && !/^\d{4}-\d{2}-\d{2}$/.test(tier.until)) {
            issues[`settings.fee.tiers.${index}.until`] = `"${tier.until}" is not a date. Choose the step's last day again.`;
        }
    });

    if (!paymentOn.value) return issues;

    if (!s.feePerEntryOfSection) {
        issues['settings.fee.perEntryOfSection'] = 'A form that takes payment must charge its fee per entry of a repeatable section (for example, per attendee).';
    } else {
        const section = repeatableSections.value.find(candidate => candidate.id === s.feePerEntryOfSection);

        if (section && (section.minEntries ?? 0) < 1) {
            issues['settings.fee.perEntryOfSection'] = `"${section.title || section.id}" must require at least one entry on a form that takes payment, or a registration with no entries would owe nothing.`;
        }
    }

    if (s.feeAmount === null && s.feeTiers.length === 0) {
        issues['settings.fee'] = 'A form that takes payment needs a price.';
    }

    const prices: [string, number][] = [];
    if (s.feeAmount !== null) prices.push(['settings.fee.amount', s.feeAmount]);
    s.feeTiers.forEach((tier, index) => {
        if (tier.amount !== null) prices.push([`settings.fee.tiers.${index}.amount`, tier.amount]);
    });

    prices.forEach(([key, amount]) => {
        if (issues[key]) return;

        if (!isWholeCents(amount)) {
            issues[key] = 'A price on a form that takes payment must be in whole cents (at most two decimal places).';
        } else if (Math.round(amount * 100) < MIN_CHARGE_MINOR) {
            issues[key] = 'Every price on a form that takes payment must be at least $0.50, the smallest amount a card can be charged.';
        }
    });

    if (s.paymentOnline && (s.feeCurrency || 'USD').toUpperCase() !== 'USD') {
        issues['settings.fee.currency'] = 'Card payment is available in US dollars (USD) only.';
    }

    return issues;
});

/** What to show beside a field: this screen's own check first, then the server's refusal. */
const fieldIssue = (key: string): string | null =>
    paymentIssues.value[key] ?? (serverFieldErrorsByKey.value[key]?.join(' ') || null);

/** An edited field's server refusal no longer describes it. */
const clearServerError = (key: string) => {
    if (!serverFieldErrorsByKey.value[key]) return;

    const remaining = { ...serverFieldErrorsByKey.value };
    delete remaining[key];
    serverFieldErrorsByKey.value = remaining;
};

/** Price-step refusals are keyed by position, which adding or removing a step shifts. */
const clearTierErrors = () => {
    const remaining = { ...serverFieldErrorsByKey.value };
    Object.keys(remaining).filter(key => key.startsWith('settings.fee.tiers.')).forEach(key => delete remaining[key]);
    serverFieldErrorsByKey.value = remaining;
};

const addTier = () => {
    if (draft.value.settings.feeTiers.length >= 10) return;

    draft.value.settings.feeTiers.push({ label: '', amount: null, until: '', extra: {} });
    clearTierErrors();
};

const removeTier = (index: number) => {
    draft.value.settings.feeTiers.splice(index, 1);
    clearTierErrors();
};

// ------------------------------------------------------------ Stripe connection
// Card payment goes to the organisation's own connected Stripe account, so the Payment
// card says whether it is connected. /connect/status is behind the CRM gate and
// `manage donations`, so a 403 means "this account cannot see it", not "not connected".

const connectStore = useConnectStore();
const router = useRouter();

const connectState = ref<'idle' | 'loading' | 'ready' | 'unfinished' | 'none' | 'forbidden' | 'failed'>('idle');

const donationsHref = computed<string | null>(() => {
    try {
        return router.resolve({ name: 'masjid.donationsDashboard' }).href;
    } catch (e) {
        return null;
    }
});

const loadConnectState = async () => {
    if (connectState.value !== 'idle') return;

    connectState.value = 'loading';

    try {
        await connectStore.fetchStatus();
        const status = connectStore.connectStatus;

        connectState.value = !status?.stripe_account_id ? 'none' : (status.charges_enabled ? 'ready' : 'unfinished');
    } catch (error) {
        connectState.value = isForbidden(error) ? 'forbidden' : 'failed';
    }
};

// Only asked once card payment is on (or loaded on): most forms never need it.
watch(() => draft.value.settings.paymentOnline, (online) => {
    if (online) loadConnectState();
}, { immediate: true });
</script>

<style scoped>
.section-card {
    border: 1px solid #dee2e6;
}

.section-card > .card-body {
    background-color: #f8f9fa;
}
</style>
