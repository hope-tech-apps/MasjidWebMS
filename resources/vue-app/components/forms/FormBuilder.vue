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
                                <label class="form-label" :for="`sectionMaxEntries${sectionIndex}`">Max entries</label>
                                <input
                                    :id="`sectionMaxEntries${sectionIndex}`"
                                    type="number"
                                    class="form-control"
                                    :class="{ 'is-invalid': countPricingNeedsMax(section) }"
                                    min="1"
                                    :value="section.maxEntries ?? ''"
                                    :aria-describedby="countPricingNeedsMax(section) ? `sectionMaxEntriesNeeded${sectionIndex}` : undefined"
                                    @input="section.maxEntries = toNumberOrNull(($event.target as HTMLInputElement).value)"
                                    placeholder="No limit"
                                />
                                <!-- Prices by number of entries count this section, and the server refuses
                                     an open-ended count (StoreFormRequest::countTierProblems()). -->
                                <div
                                    v-if="countPricingNeedsMax(section)"
                                    :id="`sectionMaxEntriesNeeded${sectionIndex}`"
                                    class="invalid-feedback d-block"
                                >
                                    Needed: the prices go by the number of entries here.
                                </div>
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
                        :options-sources="formsStore.optionsSources"
                        :in-repeatable="!!section.repeatable"
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
                        This form takes payment (see Payment below), so this is the price people pay. The
                        total is worked out when the form is submitted and then frozen, so a later price
                        change never restates what somebody already agreed to pay.
                    </p>

                    <!-- One way of pricing is saved: the server refuses a price by number of entries
                         together with an amount or date steps (buildPayload() sends only the chosen one). -->
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6 mb-1">How the price is worked out</legend>
                        <div v-for="mode in PRICING_MODES" :key="mode.value" class="form-check">
                            <input
                                :id="`formFeePricing_${mode.value}`"
                                class="form-check-input"
                                type="radio"
                                name="formFeePricing"
                                :value="mode.value"
                                :checked="draft.settings.feePricing === mode.value"
                                :aria-describedby="`formFeePricingHelp_${mode.value}`"
                                @change="setPricing(mode.value)"
                            />
                            <label class="form-check-label" :for="`formFeePricing_${mode.value}`">{{ mode.label }}</label>
                            <div :id="`formFeePricingHelp_${mode.value}`" class="form-text mt-0">{{ mode.help }}</div>
                        </div>
                    </fieldset>

                    <div v-if="fieldIssue('settings.fee')" class="alert alert-danger py-2 small" role="alert">
                        {{ fieldIssue('settings.fee') }}
                    </div>

                    <div v-if="draft.settings.feePricing !== 'none'" class="row">
                        <div v-if="draft.settings.feePricing !== 'count'" class="col-md-4 mb-3">
                            <label class="form-label" for="formFeeAmount">{{ feeAmountLabel }}</label>
                            <input
                                id="formFeeAmount"
                                type="number"
                                class="form-control"
                                :class="{ 'is-invalid': !!fieldIssue('settings.fee.amount') }"
                                min="0"
                                step="0.01"
                                :value="draft.settings.feeAmount ?? ''"
                                @input="draft.settings.feeAmount = toNumberOrNull(($event.target as HTMLInputElement).value); clearServerError('settings.fee.amount'); clearServerError('settings.fee')"
                                :placeholder="feeAmountOptional ? 'Optional' : 'Enter a price'"
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
                        <div v-if="draft.settings.feePricing !== 'flat'" class="col-md-5 mb-3">
                            <label class="form-label" for="formFeePerEntry">
                                {{ draft.settings.feePricing === 'count' ? 'Count the entries of' : 'Charged' }}
                            </label>
                            <select
                                id="formFeePerEntry"
                                class="form-select"
                                :class="{ 'is-invalid': !!fieldIssue('settings.fee.perEntryOfSection') }"
                                v-model="draft.settings.feePerEntryOfSection"
                                @change="clearServerError('settings.fee.perEntryOfSection')"
                            >
                                <option :value="null">
                                    {{ draft.settings.feePricing === 'dateSteps' ? 'Once per submission' : 'Choose a repeating section' }}
                                </option>
                                <option
                                    v-for="section in repeatableSections"
                                    :key="section.id"
                                    :value="section.id"
                                >
                                    {{ sectionChoiceLabel(section) }}
                                </option>
                            </select>
                            <div v-if="fieldIssue('settings.fee.perEntryOfSection')" class="invalid-feedback d-block">
                                {{ fieldIssue('settings.fee.perEntryOfSection') }}
                            </div>
                            <small v-if="!repeatableSections.length" class="form-text text-muted">
                                No section repeats yet. Switch on "Repeats?" for a section first, for example one
                                entry per child.
                            </small>
                        </div>
                    </div>

                    <!-- Price steps: an early-bird price, then a standard one. Carried through a
                         save even when nobody edits them, or saving would drop the price. -->
                    <div v-if="draft.settings.feePricing === 'dateSteps'" class="mb-2">
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

                    <!-- Price by number of entries (settings.fee.countTiers): the row for the number of
                         entries submitted is the WHOLE price, never multiplied. -->
                    <div v-if="draft.settings.feePricing === 'count'" class="mb-2">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                            <span class="form-label mb-0">Prices by number of entries</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                :disabled="draft.settings.feeCountTiers.length >= MAX_COUNT_TIERS"
                                @click="addCountTier"
                            >
                                <i class="bi bi-plus-circle"></i> Add a price
                            </button>
                        </div>
                        <p class="form-text mt-0">
                            Each price is the whole amount for that many entries, up to the next row. The first row
                            starts at 1, and the last row also covers any larger number. For example: 1 child $100,
                            2 children $170, 5 or more children $350.
                        </p>

                        <div v-if="fieldIssue('settings.fee.countTiers')" class="alert alert-danger py-2 small" role="alert">
                            {{ fieldIssue('settings.fee.countTiers') }}
                        </div>

                        <div
                            v-for="(tier, tierIndex) in draft.settings.feeCountTiers"
                            :key="tierIndex"
                            class="row g-2 align-items-start mb-2"
                            role="group"
                            :aria-label="`Price ${tierIndex + 1} by number of entries`"
                        >
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`formCountTierMin${tierIndex}`">From this many entries</label>
                                <input
                                    :id="`formCountTierMin${tierIndex}`"
                                    type="number"
                                    inputmode="numeric"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': !!fieldIssue(`settings.fee.countTiers.${tierIndex}.min`) }"
                                    min="1"
                                    step="1"
                                    :value="tier.min ?? ''"
                                    :aria-describedby="`formCountTierRange${tierIndex}`"
                                    @input="tier.min = toNumberOrNull(($event.target as HTMLInputElement).value); clearCountTierErrors()"
                                />
                                <div v-if="fieldIssue(`settings.fee.countTiers.${tierIndex}.min`)" class="invalid-feedback d-block">
                                    {{ fieldIssue(`settings.fee.countTiers.${tierIndex}.min`) }}
                                </div>
                                <div :id="`formCountTierRange${tierIndex}`" class="form-text mt-0">{{ countTierRange(tierIndex) }}</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`formCountTierAmount${tierIndex}`">Price</label>
                                <input
                                    :id="`formCountTierAmount${tierIndex}`"
                                    type="number"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': !!fieldIssue(`settings.fee.countTiers.${tierIndex}.amount`) }"
                                    min="0"
                                    step="0.01"
                                    :value="tier.amount ?? ''"
                                    @input="tier.amount = toNumberOrNull(($event.target as HTMLInputElement).value); clearCountTierErrors()"
                                />
                                <div v-if="fieldIssue(`settings.fee.countTiers.${tierIndex}.amount`)" class="invalid-feedback d-block">
                                    {{ fieldIssue(`settings.fee.countTiers.${tierIndex}.amount`) }}
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1" :for="`formCountTierLabel${tierIndex}`">Name</label>
                                <input
                                    :id="`formCountTierLabel${tierIndex}`"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': !!fieldIssue(`settings.fee.countTiers.${tierIndex}.label`) }"
                                    maxlength="60"
                                    v-model="tier.label"
                                    placeholder="e.g. 2 children"
                                    aria-describedby="formCountTierLabelHelp"
                                    @input="clearCountTierErrors()"
                                />
                                <div v-if="fieldIssue(`settings.fee.countTiers.${tierIndex}.label`)" class="invalid-feedback d-block">
                                    {{ fieldIssue(`settings.fee.countTiers.${tierIndex}.label`) }}
                                </div>
                                <!-- A warning, never a refusal: the server accepts a price with no name. -->
                                <div v-else-if="!tier.label.trim()" class="form-text text-warning-emphasis mt-0">
                                    No name yet. A name such as "2 children" tells people which price applies.
                                </div>
                            </div>
                            <div class="col-md-1">
                                <span class="form-label small mb-1 d-none d-md-block" aria-hidden="true">&nbsp;</span>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :aria-label="`Remove price ${tierIndex + 1}`"
                                    :disabled="draft.settings.feeCountTiers.length === 1"
                                    @click="removeCountTier(tierIndex)"
                                >
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <p id="formCountTierLabelHelp" class="form-text mt-0">
                            The name is what the receipt and the card payment page call the price.
                        </p>
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
                        Off by default. With any kind of payment on, the fee above must be charged per entry,
                        or by number of entries, of a section that needs at least one entry, and every price
                        must be at least $0.50.
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
                        organisation's Stripe account (or, where Manara has set it up, its parent organisation's),
                        so card payment works only once that account is ready. Card payment is in US dollars only.
                    </div>
                    <div v-if="fieldIssue('settings.payment.online')" class="invalid-feedback d-block mb-2">
                        {{ fieldIssue('settings.payment.online') }}
                    </div>

                    <!-- Whether a card payment would be taken right now, and through whom
                         (GET forms/card-account, the same answer the submit gets). -->
                    <div v-if="draft.settings.paymentOnline" class="mb-3" role="status">
                        <div v-if="cardAccountState === 'loading'" class="small text-muted">
                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                            Checking how this organisation takes card payments…
                        </div>
                        <template v-else-if="cardAccountState === 'loaded' && cardAccount">
                            <div v-if="cardAccount.state === 'own'" class="small text-success">
                                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                                This organisation's Stripe account is connected and can take card payments.
                            </div>
                            <div v-else-if="cardAccount.state === 'linked' && cardAccount.holder" class="small text-success">
                                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                                Card payments go through {{ cardAccount.holder.name }}'s Stripe account, and it can take them.
                                The money lands in that account, card statements show {{ cardAccount.holder.name }}, and refunds
                                are made in {{ cardAccount.holder.name }}'s Stripe dashboard.
                            </div>
                            <div v-else-if="cardAccount.holder || formsCardProblemIsLink(cardAccount.problem)" class="alert alert-warning py-2 small mb-0">
                                Card payments for this organisation go through {{ cardHolderName }}, but card payment
                                will be refused right now<template v-if="cardProblemText">, because {{ cardProblemText }}</template>.
                                Ask {{ cardHolderName }} or your Manara contact to fix it.
                            </div>
                            <div v-else class="alert alert-warning py-2 small mb-0">
                                Card payment will be refused<template v-if="cardProblemText">, because {{ cardProblemText }}</template>.
                                Connect or finish this organisation's Stripe account {{ givingOff ? 'under' : 'on the' }}
                                <a v-if="donationsHref" :href="donationsHref" target="_blank" rel="noopener">{{ connectPlace }} (opens in a new tab)</a><span v-else>{{ connectPlace }}</span>.
                            </div>
                        </template>
                        <div v-else-if="cardAccountState === 'failed'" class="small text-muted">
                            Could not check just now whether this organisation can take card payments. Card payment works
                            only once its Stripe account is ready.
                        </div>
                    </div>

                    <!-- Card fee: who pays the card processing fee on a card payment
                         (allowFeeCoverage / requireFeeCoverage). An office payer and a staff-code entry
                         never pay it. -->
                    <fieldset class="ms-md-4 mb-3" :disabled="!draft.settings.paymentOnline">
                        <legend class="form-label fs-6 mb-1">Card processing fee</legend>
                        <div v-for="choice in FEE_COVERAGE_CHOICES" :key="choice.value" class="form-check">
                            <input
                                :id="`formPaymentFee_${choice.value}`"
                                class="form-check-input"
                                type="radio"
                                name="formPaymentFeeCoverage"
                                :value="choice.value"
                                v-model="draft.settings.paymentFeeCoverage"
                                :aria-describedby="`formPaymentFeeHelp_${choice.value}`"
                                @change="clearServerError('settings.payment.allowFeeCoverage'); clearServerError('settings.payment.requireFeeCoverage')"
                            />
                            <label class="form-check-label" :for="`formPaymentFee_${choice.value}`">{{ choice.label }}</label>
                            <div :id="`formPaymentFeeHelp_${choice.value}`" class="form-text mt-0">{{ choice.help }}</div>
                        </div>
                        <div class="form-text">
                            Card payments only. People who pay the office, and entries made with a staff code, never
                            pay this fee.<template v-if="!draft.settings.paymentOnline"> Switch on card payment to choose.</template>
                        </div>
                    </fieldset>
                    <div v-if="fieldIssue('settings.payment.allowFeeCoverage')" class="invalid-feedback d-block ms-md-4 mb-2">
                        {{ fieldIssue('settings.payment.allowFeeCoverage') }}
                    </div>
                    <div v-if="fieldIssue('settings.payment.requireFeeCoverage')" class="invalid-feedback d-block ms-md-4 mb-2">
                        {{ fieldIssue('settings.payment.requireFeeCoverage') }}
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

                    <!-- Pay the office (officePayment / officeInstructions): chosen on the form instead of
                         the card. The registration is saved as owed, with no card fee, and marked paid on
                         Form Responses, which asks how the money came. -->
                    <div class="form-check form-switch">
                        <input
                            id="formPaymentOffice"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            v-model="draft.settings.paymentOfficePayment"
                            aria-describedby="formPaymentOfficeHelp"
                            @change="clearServerError('settings.payment.officePayment'); clearServerError('settings.fee')"
                        />
                        <label class="form-check-label" for="formPaymentOffice">Let people choose to pay the office</label>
                    </div>
                    <div id="formPaymentOfficeHelp" class="form-text mb-2">
                        People can choose to pay the office (by Zelle, Cash App, Venmo, check or cash) instead of by
                        card. Their registration is saved as owed and they are told how to pay. When the money
                        arrives, mark it paid on Form Responses and say how it came. People who pay the office
                        never pay the card processing fee.
                    </div>
                    <div v-if="fieldIssue('settings.payment.officePayment')" class="invalid-feedback d-block mb-2">
                        {{ fieldIssue('settings.payment.officePayment') }}
                    </div>

                    <div v-if="draft.settings.paymentOfficePayment" class="ms-md-4 mb-3">
                        <label class="form-label" for="formPaymentOfficeInstructions">How to pay the office</label>
                        <textarea
                            id="formPaymentOfficeInstructions"
                            class="form-control"
                            :class="{ 'is-invalid': !!fieldIssue('settings.payment.officeInstructions') }"
                            rows="3"
                            :maxlength="OFFICE_INSTRUCTIONS_MAX"
                            v-model="draft.settings.paymentOfficeInstructions"
                            placeholder="e.g. Zelle to office@example.org, or bring cash or a check to the office on Sunday between 10am and 1pm."
                            aria-describedby="formPaymentOfficeInstructionsHelp"
                            @input="clearServerError('settings.payment.officeInstructions')"
                        ></textarea>
                        <div v-if="fieldIssue('settings.payment.officeInstructions')" class="invalid-feedback d-block">
                            {{ fieldIssue('settings.payment.officeInstructions') }}
                        </div>
                        <div id="formPaymentOfficeInstructionsHelp" class="form-text">
                            Shown to people who choose to pay the office, and emailed to them with the amount they owe.
                            {{ draft.settings.paymentOfficeInstructions.length }} of {{ OFFICE_INSTRUCTIONS_MAX }} characters.
                        </div>
                        <div v-if="!draft.settings.paymentOfficeInstructions.trim()" class="small text-warning-emphasis mt-1">
                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                            Without instructions, people who choose the office are told only what they owe.
                        </div>
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
                                Switch on a kind of payment above to set the event date.
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
    FormFeeCountTier,
    FormFeeRule,
    FormFeeTier,
    FormPaymentSettings,
    FORM_IDENTIFIER_PATTERN,
    FORM_WHATSAPP_URL_PATTERN,
    deriveFormIdentifier,
    deriveFormSlug,
    selectionCountProblem,
    uniqueFormIdentifier
} from '@/core/types/data/masjid-related/Form';
import FormFieldEditor from '@/components/forms/FormFieldEditor.vue';
import FormStaffCodesModal from '@/components/forms/FormStaffCodesModal.vue';
import { useFormsStore } from '@/stores/masjid/formsStore';
import { useConnectStore } from '@/stores/masjid/connectStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { detailsScreenTitle, moduleIsOff } from '@/core/access/orgAccess';
import { FormsCardAccount, formsCardProblemIsLink, formsCardProblemText } from '@/core/types/data/masjid-related/StripeConnect';
import { serverFieldErrors, serverMessage } from '@/core/helpers/serverMessage';
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';

/**
 * The sign-up form builder: sections, questions, the identity map, the fee rule (one price,
 * per entry, date-stepped prices, or prices by number of entries), payment (card and who
 * pays its fee, staff cash codes, paying the office, the event day) and the WhatsApp group
 * link.
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

/** One price by number of entries as the builder edits it; `extra` as on DraftTier. */
type DraftCountTier = {
    min: number | null;
    amount: number | null;
    label: string;
    extra: Record<string, unknown>;
};

/**
 * How the fee's price is worked out. Exactly one is saved (buildPayload()): the server
 * refuses countTiers together with amount or tiers.
 */
type FeePricing = 'none' | 'flat' | 'perEntry' | 'dateSteps' | 'count';

/** Who pays the card processing fee on a card payment. */
type FeeCoverage = 'absorb' | 'optional' | 'required';

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
    feePricing: FeePricing;
    feeTiers: DraftTier[];
    feeCountTiers: DraftCountTier[];
    // settings.payment, flattened like identity and fee.
    paymentOnline: boolean;
    paymentStaffCodes: boolean;
    /** allowFeeCoverage ('optional') and requireFeeCoverage ('required') as one choice. */
    paymentFeeCoverage: FeeCoverage;
    paymentOfficePayment: boolean;
    /** '' for none. */
    paymentOfficeInstructions: string;
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
    /**
     * The keys the loaded payment block carried. The keys added for office payment and the
     * required card fee are written only once used or when already there, so a form that
     * never used them saves exactly as it did before they existed.
     */
    loadedPaymentKeys: string[];
};

/** The settings keys buildPayload() writes itself; every other key is sent back as loaded. */
const MANAGED_SETTINGS_KEYS = [
    'submitButtonLabel', 'successTitle', 'successBody', 'successNextSteps', 'notifyEmails',
    'confirmationEmail', 'paymentNote', 'intro', 'identity', 'fee', 'payment',
    'whatsappUrl', 'whatsappLabel'
] as const;
// `pricing` is Form::feeRule()'s computed marker, never part of what is saved.
const MANAGED_FEE_KEYS = ['amount', 'currency', 'perEntryOfSection', 'tiers', 'countTiers', 'pricing'] as const;
const MANAGED_PAYMENT_KEYS = [
    'online', 'staffCodes', 'allowFeeCoverage', 'requireFeeCoverage', 'officePayment', 'officeInstructions', 'eventDate'
] as const;

const IDENTITY_SLOTS = [
    { key: 'identityName', slot: 'name', label: 'Name question' },
    { key: 'identityEmail', slot: 'email', label: 'Email question' },
    { key: 'identityPhone', slot: 'phone', label: 'Phone question' }
] as const;

/** FormPayment::MIN_CHARGE_MINOR: Stripe's smallest card charge. */
const MIN_CHARGE_MINOR = 50;

const PRICING_MODES: { value: FeePricing; label: string; help: string }[] = [
    {
        // Chosen, never fallen into: an emptied price box does not make a form free (paymentIssues()).
        value: 'none',
        label: 'No price',
        help: 'The form is free. Nobody owes anything for submitting it.'
    },
    {
        value: 'flat',
        label: 'One price per submission',
        help: 'Everyone who submits pays the same amount, however many people they add.'
    },
    {
        value: 'perEntry',
        label: 'A price for each entry',
        help: 'The price is multiplied by the number of entries in a repeating section, for example per attendee.'
    },
    {
        value: 'dateSteps',
        label: 'Prices that change by date',
        help: 'For example an early-bird price, then a standard price. Charged once per submission or per entry.'
    },
    {
        value: 'count',
        label: 'Price by number of entries',
        help: 'One total for 1 entry, another for 2, and so on, for example a family price by number of children. It is not multiplied.'
    }
];

const FEE_COVERAGE_CHOICES: { value: FeeCoverage; label: string; help: string }[] = [
    {
        value: 'absorb',
        label: 'This organisation pays it',
        help: 'Card payers pay the price. The fee comes out of what this organisation receives.'
    },
    {
        value: 'optional',
        label: 'Card payers can choose to add it',
        help: 'Adds an optional checkbox so a card payer can add the card processing fee to their total.'
    },
    {
        value: 'required',
        label: 'Card payers always pay it',
        help: 'The card processing fee is added to every card payment and cannot be removed. Check that a card surcharge is allowed for this organisation first.'
    }
];

/** Rows of prices by number of entries, as many as date steps allow. */
const MAX_COUNT_TIERS = 10;

/** StoreFormRequest's limit on settings.payment.officeInstructions. */
const OFFICE_INSTRUCTIONS_MAX = 1000;

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
    feePricing: 'none',
    feeTiers: [],
    feeCountTiers: [],
    paymentOnline: false,
    paymentStaffCodes: false,
    paymentFeeCoverage: 'absorb',
    paymentOfficePayment: false,
    paymentOfficeInstructions: '',
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

const blankPreserved = (): Preserved => ({ settings: {}, fee: {}, payment: {}, hadPaymentBlock: false, loadedPaymentKeys: [] });

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

const toDraftCountTier = (tier: unknown): DraftCountTier => {
    const record = asRecord(tier);

    return {
        min: toAmount(record.min),
        amount: toAmount(record.amount),
        label: typeof record.label === 'string' ? record.label : '',
        extra: omit(record, ['min', 'amount', 'label'])
    };
};

// A missing number goes as null so the server names the row, as the problems list does.
const buildCountTier = (tier: DraftCountTier): FormFeeCountTier => ({
    ...tier.extra,
    min: tier.min as number,
    amount: tier.amount as number,
    label: tier.label.trim()
});

/**
 * Which pricing a stored fee uses. A fee with no amount, no date steps and no prices by
 * number of entries charges nothing (Form::feeRule() is null), so it reads as No price.
 */
const pricingOf = (fee: Record<string, any>): FeePricing => {
    if (Array.isArray(fee.countTiers) && fee.countTiers.length) return 'count';
    if (Array.isArray(fee.tiers) && fee.tiers.length) return 'dateSteps';
    if (toAmount(fee.amount) === null) return 'none';
    return typeof fee.perEntryOfSection === 'string' && fee.perEntryOfSection ? 'perEntry' : 'flat';
};

/**
 * settings.payment, or null to leave it out. Written for a form that already had one
 * (a festival form with both switches off after the day is still reconciled from its
 * payment columns), or once card payment, staff codes or paying the office is switched on. Nothing else
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
        allowFeeCoverage: s.paymentFeeCoverage === 'optional'
    };

    // Written once used, or when the loaded block already had them, so a form that never
    // used them (MEC's festival form) saves the block it had.
    const had = (key: string): boolean => preserved.value.loadedPaymentKeys.includes(key);
    // One choice, so never both on: the server refuses allowFeeCoverage with requireFeeCoverage.
    const requireFee = s.paymentFeeCoverage === 'required';
    if (requireFee || had('requireFeeCoverage')) payment.requireFeeCoverage = requireFee;
    if (s.paymentOfficePayment || had('officePayment')) payment.officePayment = s.paymentOfficePayment;

    const officeInstructions = s.paymentOfficeInstructions.trim();
    if (officeInstructions) payment.officeInstructions = officeInstructions;
    else if (had('officeInstructions')) payment.officeInstructions = null;

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
            hadPaymentBlock,
            loadedPaymentKeys: Object.keys(payment)
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
                feePricing: pricingOf(fee),
                feeTiers: (Array.isArray(fee.tiers) ? fee.tiers : []).map(toDraftTier),
                feeCountTiers: (Array.isArray(fee.countTiers) ? fee.countTiers : []).map(toDraftCountTier),
                paymentOnline: readFlag(payment.online),
                paymentStaffCodes: readFlag(payment.staffCodes),
                paymentFeeCoverage: readFlag(payment.requireFeeCoverage)
                    ? 'required'
                    : (readFlag(payment.allowFeeCoverage) ? 'optional' : 'absorb'),
                paymentOfficePayment: readFlag(payment.officePayment),
                paymentOfficeInstructions: typeof payment.officeInstructions === 'string' ? payment.officeInstructions : '',
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

    // Only the chosen pricing is sent: the server refuses countTiers together with an amount
    // or date steps, and this is what widens the old "amount or steps" gate so prices by
    // number of entries are not dropped (a form priced only by them would save as free).
    const pricing = draftSettings.feePricing;
    const currency = (draftSettings.feeCurrency || 'USD').toUpperCase();

    if (pricing === 'none') {
        // No price, chosen as such (an empty price box under another choice blocks the save).
    } else if (pricing === 'count') {
        const countTiers = draftSettings.feeCountTiers.map(buildCountTier);

        if (countTiers.length) {
            settings.fee = {
                ...preserved.value.fee,
                currency,
                perEntryOfSection: draftSettings.feePerEntryOfSection || null,
                countTiers
            };
        }
    } else {
        // A fee is an amount, price steps, or both: the festival form has steps and no amount.
        // With neither there is no fee, and the form is free.
        const tiers = pricing === 'dateSteps' ? draftSettings.feeTiers.map(buildTier) : [];

        if (draftSettings.feeAmount !== null || tiers.length) {
            const fee: FormFeeRule = {
                ...preserved.value.fee,
                currency,
                perEntryOfSection: pricing === 'flat' ? null : (draftSettings.feePerEntryOfSection || null)
            };

            if (draftSettings.feeAmount !== null) fee.amount = draftSettings.feeAmount;
            if (tiers.length) fee.tiers = tiers;

            settings.fee = fee;
        }
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
        if (field.optionsSource) {
            // A reference, never a copy: the server fills the choices in when it
            // serves the form, so no `options` key goes with it.
            clean.optionsSource = field.optionsSource;
        } else {
            clean.options = (field.options ?? []).map(option => {
                const cleanOption: FormFieldOption = {
                    value: option.value.trim(),
                    label: option.label.trim()
                };
                if (option.detail) cleanOption.detail = option.detail;
                return cleanOption;
            });
        }
    }

    if (field.type === 'checkboxGroup') {
        // Blank means no limit, so only a real number is sent; a minimum of 0 is no minimum.
        if (typeof field.minSelections === 'number' && field.minSelections > 0) clean.minSelections = field.minSelections;
        if (typeof field.maxSelections === 'number') clean.maxSelections = field.maxSelections;
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

    // Only a choice question can take its choices from a source.
    if (!CHOICE_FIELD_TYPES.includes(type)) {
        delete field.optionsSource;
    }

    // "How many can they pick" belongs to a checkboxGroup alone.
    if (type !== 'checkboxGroup') {
        delete field.minSelections;
        delete field.maxSelections;
    }

    // A choice question is refused without options, so open one empty row straight away —
    // unless its choices come from a source, which stores none.
    if (CHOICE_FIELD_TYPES.includes(type) && !field.optionsSource && !field.options?.length) {
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

            if (CHOICE_FIELD_TYPES.includes(field.type) && field.optionsSource) {
                // Mirrors ValidFormSchema: a sourced question stores no options and is
                // refused inside a section that repeats.
                if (section.repeatable) {
                    found.push(`${name} — "${question}": choices from the school calendar can't be used in a section that repeats.`);
                }
            } else if (CHOICE_FIELD_TYPES.includes(field.type)) {
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

            const countProblem = selectionCountProblem(field);
            if (countProblem) {
                found.push(`${name} — "${question}": ${countProblem}`);
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

    const pricing = draft.value.settings.feePricing;

    if (pricing !== 'count' && pricing !== 'none' && draft.value.settings.feeAmount !== null && draft.value.settings.feeAmount < 0) {
        found.push('The fee cannot be negative.');
    }

    const perEntry = pricing === 'flat' || pricing === 'none' ? null : draft.value.settings.feePerEntryOfSection;
    if (perEntry && !repeatableSections.value.some(section => section.id === perEntry)) {
        found.push(`The fee is charged per entry of "${perEntry}", which is not a repeating section.`);
    }

    Object.entries(paymentIssues.value).forEach(([key, message]) => {
        const step = /^settings\.fee\.tiers\.(\d+)\./.exec(key);
        const countRow = /^settings\.fee\.countTiers\.(\d+)\./.exec(key);

        if (countRow) {
            found.push(`Price ${Number(countRow[1]) + 1} by number of entries: ${message}`);
        } else if (step) {
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

/** Card payment, staff cash codes or paying the office: the switches that put the fee under crossCheck's rules. */
const paymentOn = computed(() =>
    draft.value.settings.paymentOnline || draft.value.settings.paymentStaffCodes || draft.value.settings.paymentOfficePayment
);

/** With date steps, the amount is only the price when no step applies. */
const feeAmountOptional = computed(() =>
    draft.value.settings.feePricing === 'dateSteps' && draft.value.settings.feeTiers.length > 0
);

const feeAmountLabel = computed(() => {
    if (feeAmountOptional.value) return 'Price when no step applies';
    return draft.value.settings.feePricing === 'perEntry' ? 'Price per entry' : 'Amount';
});

/**
 * The section that prices by number of entries count, when it has no maximum of 1 or more.
 * The server refuses that on every form, paying or not: the top price is open-ended, so one
 * registration could add entries without limit at that price.
 */
const countPricingNeedsMax = (section: FormSchemaSection): boolean =>
    draft.value.settings.feePricing === 'count' &&
    !!section.repeatable &&
    !!section.id &&
    section.id === draft.value.settings.feePerEntryOfSection &&
    !(Number(section.maxEntries) >= 1);

const sectionChoiceLabel = (section: FormSchemaSection): string => {
    const title = section.title || section.id;
    return draft.value.settings.feePricing === 'count' ? `"${title}"` : `Per entry of "${title}"`;
};

/** Whole cents, with room for float noise (19.99 * 100 is 1998.9999999999998). */
const isWholeCents = (amount: number): boolean => Math.abs(Math.round(amount * 100) - amount * 100) < 1e-6;

/**
 * StoreFormRequest's refusals for a price by number of entries, mirrored where the server
 * applies them.
 *
 * On EVERY form (countTierProblems() and the field rules): a counted section; an amount on
 * every row, never negative; a first row starting at 1; rows starting at ever more entries,
 * 1 to 1000; no row cheaper than the one above it (a typo guard).
 *
 * Only on a form that takes payment (`paying`; paymentProblems()): whole cents and at least
 * $0.50. The counted section's "at least one entry" is the paying-form rule in
 * paymentIssues(), as for every other pricing.
 *
 * A missing name is never refused (the server accepts one): the row shows a warning.
 */
const countTierIssues = (s: DraftSettings, paying: boolean): Record<string, string> => {
    const issues: Record<string, string> = {};

    const counted = repeatableSections.value.find(candidate => candidate.id === s.feePerEntryOfSection);

    if (!s.feePerEntryOfSection) {
        issues['settings.fee.perEntryOfSection'] = 'Choose the repeating section whose entries are counted.';
    } else if (counted && countPricingNeedsMax(counted)) {
        issues['settings.fee.perEntryOfSection'] = `“${counted.title || counted.id}” needs a maximum number of entries when prices go by number of entries. Set “Max entries” on that section, under Questions above.`;
    }

    if (!s.feeCountTiers.length) {
        // Zero rows would save as a free form: that has to be chosen as No price.
        issues['settings.fee.countTiers'] = 'Add at least one price, or choose No price.';
        return issues;
    }

    let previousMin: number | null = null;
    let previousAmount: number | null = null;

    s.feeCountTiers.forEach((tier, index) => {
        const key = `settings.fee.countTiers.${index}`;
        const min = tier.min;

        if (min === null || !Number.isInteger(min) || min < 1 || min > 1000) {
            issues[`${key}.min`] = 'Enter a whole number of entries, from 1 to 1000.';
        } else {
            if (index === 0 && min !== 1) {
                issues[`${key}.min`] = 'The first price must start at 1 entry.';
            } else if (previousMin !== null && min <= previousMin) {
                issues[`${key}.min`] = `Must be more than ${previousMin}, where the price above it starts.`;
            }

            previousMin = previousMin === null ? min : Math.max(previousMin, min);
        }

        const amount = tier.amount;

        if (amount === null) {
            issues[`${key}.amount`] = 'Every price needs an amount.';
        } else if (amount < 0) {
            issues[`${key}.amount`] = 'A price cannot be negative.';
        } else if (paying && !isWholeCents(amount)) {
            issues[`${key}.amount`] = 'A price on a form that takes payment must be in whole cents (at most two decimal places).';
        } else if (paying && Math.round(amount * 100) < MIN_CHARGE_MINOR) {
            issues[`${key}.amount`] = 'Every price on a form that takes payment must be at least $0.50, the smallest amount a card can be charged.';
        } else {
            if (previousAmount !== null && amount < previousAmount) {
                issues[`${key}.amount`] = 'This costs less than the price above it. Prices cannot go down as entries go up, so check for a typo.';
            }

            previousAmount = previousAmount === null ? amount : Math.max(previousAmount, amount);
        }
    });

    return issues;
};

/**
 * StoreFormRequest's settings rules and crossCheck()'s payment rules, keyed as the server
 * keys its refusals, so a problem shows beside its field before Save and a 422 lands on
 * the same spot. A courtesy: the server re-checks all of it.
 */
const paymentIssues = computed<Record<string, string>>(() => {
    const issues: Record<string, string> = {};
    const s = draft.value.settings;

    const pricing = s.feePricing;

    const link = s.whatsappUrl.trim();
    if (link && !FORM_WHATSAPP_URL_PATTERN.test(link)) {
        issues['settings.whatsappUrl'] = 'The WhatsApp group link must be a WhatsApp invite link starting https://chat.whatsapp.com/.';
    }

    if (s.paymentOfficeInstructions.trim().length > OFFICE_INSTRUCTIONS_MAX) {
        issues['settings.payment.officeInstructions'] = `Keep the instructions for paying the office to ${OFFICE_INSTRUCTIONS_MAX} characters or fewer.`;
    }

    // Only the chosen pricing is saved, so only its own values are checked.
    if (pricing === 'dateSteps') {
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
    }

    if (pricing === 'count') {
        Object.assign(issues, countTierIssues(s, paymentOn.value));
    }

    if (pricing === 'perEntry' && s.feeAmount !== null && !s.feePerEntryOfSection) {
        issues['settings.fee.perEntryOfSection'] = 'Choose the repeating section to charge per entry of.';
    }

    // A priced choice with no price would save the form as FREE without a word (switching away
    // from prices by number of entries leaves the box empty). Free has to be chosen: No price.
    if ((pricing === 'flat' || pricing === 'perEntry') && s.feeAmount === null) {
        issues['settings.fee.amount'] = 'Enter a price, or choose No price.';
    }

    if (pricing === 'dateSteps' && s.feeAmount === null && s.feeTiers.length === 0) {
        issues['settings.fee'] = 'Enter a price, or add a price step, or choose No price.';
    }

    if (!paymentOn.value) return issues;

    if (!issues['settings.fee.perEntryOfSection'] && pricing !== 'none') {
        if (pricing === 'flat') {
            issues['settings.fee.perEntryOfSection'] = 'A form that takes payment cannot charge one price per submission. Choose a pricing that counts the entries of a repeating section.';
        } else if (!s.feePerEntryOfSection) {
            issues['settings.fee.perEntryOfSection'] = 'A form that takes payment must charge its fee per entry of a repeatable section (for example, per attendee).';
        } else {
            const section = repeatableSections.value.find(candidate => candidate.id === s.feePerEntryOfSection);

            if (section && (section.minEntries ?? 0) < 1) {
                issues['settings.fee.perEntryOfSection'] = `"${section.title || section.id}" must require at least one entry on a form that takes payment, or a registration with no entries would owe nothing.`;
            }
        }
    }

    const hasPrice = pricing === 'none'
        ? false
        : (pricing === 'count'
            ? s.feeCountTiers.length > 0
            : s.feeAmount !== null || (pricing === 'dateSteps' && s.feeTiers.length > 0));

    if (!hasPrice && !issues['settings.fee.countTiers'] && !issues['settings.fee.amount'] && !issues['settings.fee']) {
        issues['settings.fee'] = pricing === 'none'
            ? 'A form that takes payment needs a price. Choose how the price is worked out above.'
            : 'A form that takes payment needs a price.';
    }

    // Prices by number of entries are checked in countTierIssues().
    const prices: [string, number][] = [];
    if (pricing !== 'count' && pricing !== 'none' && s.feeAmount !== null) prices.push(['settings.fee.amount', s.feeAmount]);
    if (pricing === 'dateSteps') {
        s.feeTiers.forEach((tier, index) => {
            if (tier.amount !== null) prices.push([`settings.fee.tiers.${index}.amount`, tier.amount]);
        });
    }

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

/** Refusals about the fee no longer describe it once its pricing changes. */
const clearFeeErrors = () => {
    const remaining = { ...serverFieldErrorsByKey.value };
    Object.keys(remaining).filter(key => key === 'settings.fee' || key.startsWith('settings.fee.')).forEach(key => delete remaining[key]);
    serverFieldErrorsByKey.value = remaining;
};

/** Rows are keyed by position and checked against each other, so any edit clears them all. */
const clearCountTierErrors = () => {
    const remaining = { ...serverFieldErrorsByKey.value };
    Object.keys(remaining).filter(key => key === 'settings.fee' || key.startsWith('settings.fee.countTiers')).forEach(key => delete remaining[key]);
    serverFieldErrorsByKey.value = remaining;
};

const setPricing = (pricing: FeePricing) => {
    const s = draft.value.settings;
    if (s.feePricing === pricing) return;

    s.feePricing = pricing;

    // With one repeating section there is only one section to count.
    if ((pricing === 'perEntry' || pricing === 'count') && !s.feePerEntryOfSection && repeatableSections.value.length === 1) {
        s.feePerEntryOfSection = repeatableSections.value[0].id;
    }

    if (pricing === 'count' && !s.feeCountTiers.length) {
        s.feeCountTiers.push({ min: 1, amount: null, label: '', extra: {} });
    }

    clearFeeErrors();
};

const addCountTier = () => {
    const tiers = draft.value.settings.feeCountTiers;
    if (tiers.length >= MAX_COUNT_TIERS) return;

    // The next row starts one entry after the last one, when that one has a usable number.
    const last = tiers[tiers.length - 1];
    const min = !last ? 1 : (last.min !== null && Number.isInteger(last.min) ? last.min + 1 : null);

    tiers.push({ min, amount: null, label: '', extra: {} });
    clearCountTierErrors();
};

const removeCountTier = (index: number) => {
    draft.value.settings.feeCountTiers.splice(index, 1);
    clearCountTierErrors();
};

/** "Applies to 2 entries", "Applies to 2 to 4 entries", "Applies to 5 or more entries"; '' until the numbers make sense. */
const countTierRange = (index: number): string => {
    const tiers = draft.value.settings.feeCountTiers;
    const usable = (value: number | null | undefined): value is number =>
        typeof value === 'number' && Number.isInteger(value) && value >= 1;

    const min = tiers[index]?.min;
    if (!usable(min)) return '';

    if (index === tiers.length - 1) return `Applies to ${min} or more entries`;

    const next = tiers[index + 1]?.min;
    if (!usable(next) || next <= min) return '';

    const upTo = next - 1;
    if (upTo === min) return `Applies to ${min} ${min === 1 ? 'entry' : 'entries'}`;

    return `Applies to ${min} to ${upTo} entries`;
};

// ------------------------------------------------------------ Card payment account
// Card payment goes to the organisation's own Stripe account, or through its parent's when
// a SuperAdmin has linked them (DECISIONS.md 2026-09-15). GET forms/card-account answers
// with the resolver the submit uses, sits in the forms route group (no `manage donations`
// needed, unlike /connect/status), and never carries an account id.

const connectStore = useConnectStore();
const router = useRouter();

const cardAccountState = ref<'idle' | 'loading' | 'loaded' | 'failed'>('idle');
const cardAccount = ref<FormsCardAccount | null>(null);
const cardProblemText = computed(() => formsCardProblemText(cardAccount.value?.problem));
const cardHolderName = computed(() => cardAccount.value?.holder?.name || 'another organisation');
const CARD_ACCOUNT_STATES = ['own', 'linked', 'unavailable'];

const masjidStore = useMasjidStore();

// Where Stripe Connect is. The Giving Dashboard holds it while Giving is on; switched
// off, that screen is hidden and Connect sits on the Details screen's Online payments
// tab, named by its sidebar title ("Masjid Details"), never "Settings".
const givingOff = computed<boolean>(() => moduleIsOff(masjidStore.masjid, 'giving'));
const connectPlace = computed<string>(() => givingOff.value
    ? `Online payments on its ${detailsScreenTitle(masjidStore.term)} screen`
    : 'Giving Dashboard');

const donationsHref = computed<string | null>(() => {
    try {
        return givingOff.value
            ? router.resolve({ name: 'masjid.details', hash: '#online-payments' }).href
            : router.resolve({ name: 'masjid.donationsDashboard' }).href;
    } catch (e) {
        return null;
    }
});

const loadCardAccount = async () => {
    if (cardAccountState.value !== 'idle') return;

    cardAccountState.value = 'loading';

    try {
        const account = await connectStore.fetchFormsCardAccount();
        // A state this screen does not know is not guessed at: it says it could not check.
        if (!CARD_ACCOUNT_STATES.includes(account.state)) throw new Error('Unknown card account state.');

        cardAccount.value = account;
        cardAccountState.value = 'loaded';
    } catch (error) {
        cardAccount.value = null;
        cardAccountState.value = 'failed';
    }
};

// Only asked once card payment is on (or loaded on): most forms never need it.
watch(() => draft.value.settings.paymentOnline, (online) => {
    if (online) loadCardAccount();
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
