<template>
    <div class="d-flex flex-column gap-4">

        <!-- ================================================================
             THE PROVENANCE BANNER.

             Rendered above everything, on every state, and never collapsed
             behind a toggle. It is the answer to "where did this threshold come
             from and when was it true" — the question a nisab figure is
             worthless without.
             ================================================================ -->
        <div class="card border-0 py-4 px-3 w-100">
            <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
                <div class="card-title fs-4 fw-semibold">Zakat calculator</div>
            </div>

            <div class="card-body">
                <div v-if="loading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading…</span>
                    </div>
                </div>

                <div v-else :class="['alert d-flex align-items-start gap-3 mb-0', banner.cssClass]">
                    <i :class="['bi fs-3 lh-1', banner.icon]"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ banner.heading }}</div>
                        <p class="mb-2">{{ banner.body }}</p>

                        <!-- The banner above describes the PUBLISHED basis only.
                             A payer may choose the other one, so a problem with
                             the metal nobody publishes is still a problem
                             somebody is answered with — and it is invisible in
                             every figure on this page unless it is said here. -->
                        <p v-if="otherMetalWarning" class="mb-2">{{ otherMetalWarning }}</p>

                        <!-- The price, its date and its origin. Always all three,
                             whenever a price exists at all. -->
                        <dl v-if="nisab && nisab.price_per_gram_minor !== null" class="row mb-0 small">
                            <dt class="col-sm-4 col-lg-3 fw-normal text-muted">Price used</dt>
                            <dd class="col-sm-8 col-lg-9 mb-1">
                                {{ money(nisab.price_per_gram_minor) }} per gram of {{ nisab.basis }}
                            </dd>

                            <dt class="col-sm-4 col-lg-3 fw-normal text-muted">Recorded on</dt>
                            <dd class="col-sm-8 col-lg-9 mb-1">
                                <span v-if="nisab.price_quoted_on">{{ longDate(nisab.price_quoted_on) }}</span>
                                <span v-else class="text-danger">no date stored</span>
                            </dd>

                            <dt class="col-sm-4 col-lg-3 fw-normal text-muted">Taken from</dt>
                            <dd class="col-sm-8 col-lg-9 mb-1">
                                <span v-if="nisab.price_quoted_from">{{ nisab.price_quoted_from }}</span>
                                <span v-else class="text-muted">not recorded</span>
                            </dd>

                            <dt class="col-sm-4 col-lg-3 fw-normal text-muted">Set by</dt>
                            <dd class="col-sm-8 col-lg-9 mb-1">{{ sourceLabel }}</dd>

                            <dt class="col-sm-4 col-lg-3 fw-normal text-muted">Threshold</dt>
                            <dd class="col-sm-8 col-lg-9 mb-0">
                                <span v-if="nisab.threshold_minor !== null">
                                    {{ money(nisab.threshold_minor) }}
                                    <span class="text-muted">
                                        ({{ nisab.grams }} g &times; {{ money(nisab.price_per_gram_minor) }})
                                    </span>
                                </span>
                                <span v-else>—</span>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             THE PRICE THE OFFICE SETS.
             ================================================================ -->
        <div class="card border-0 py-4 px-3 w-100">
            <div class="card-header bg-white border-0">
                <div class="card-title fs-5 fw-semibold mb-0">Nisab price</div>
            </div>

            <div class="card-body d-flex flex-column gap-4">
                <p class="text-muted mb-0">
                    Nisab is a weight of gold or silver, so it only becomes an amount of money once
                    somebody supplies a metal price. This platform does not fetch one: the price
                    below is whatever your office records here, and the calculator shows that date
                    to every reader. Review it monthly — the calculator stops saying whether anyone
                    meets the threshold once the price is more than
                    {{ nisab?.price_freshness_days ?? 30 }} days old.
                </p>
                <p class="text-muted mb-0">
                    Which basis to publish, and whether the threshold below is the right one for
                    your community, are not questions this software answers. Confirm them with a
                    scholar your organisation trusts.
                </p>

                <!-- ------------------------------------------------------------
                     ONE BLOCK PER METAL, and the date lives INSIDE the block.

                     The layout is the guarantee. A single date field serving both
                     prices was a field the office could satisfy by re-dating the
                     metal it had just looked at, leaving the other price wearing a
                     date it was never read on. Here each price sits with the day it
                     was read and the source it was read from, so there is nowhere
                     to put a date that belongs to the other number.
                     ------------------------------------------------------------ -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="zakatBasis" class="form-label">Basis you publish</label>
                        <select id="zakatBasis" class="dashboard-input form-select" v-model="form.nisab_basis">
                            <option :value="null">Use the platform default ({{ defaultBasisLabel }})</option>
                            <option v-for="b in NISAB_BASES" :key="b" :value="b" class="text-capitalize">{{ b }}</option>
                        </select>
                        <div class="form-text">
                            A payer may still choose the other basis for their own calculation, so
                            keep both prices current if you can.
                        </div>
                    </div>
                </div>

                <div v-for="metal in METAL_FIELDS" :key="metal.key" class="border rounded p-3">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="fw-semibold">{{ metal.label }}, per gram</span>
                        <span v-if="metal.key === publishedBasisLabel" class="badge text-bg-light">
                            the basis you publish
                        </span>
                        <span :class="['badge', metalStatus(metal.key).cssClass]">
                            {{ metalStatus(metal.key).label }}
                        </span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label :for="`zakatPrice-${metal.key}`" class="form-label">Price</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <!-- min="0.01", not min="0": a metal price of zero is
                                     not a cheap market, it is a mistake. A typed 0 is
                                     still POSTED so the server refuses it out loud
                                     rather than being mapped to null here, which
                                     would silently delete the published price. -->
                                <input :id="`zakatPrice-${metal.key}`" type="number" step="0.01" min="0.01"
                                    class="form-control" placeholder="0.00"
                                    v-model="form[metal.key].price_dollars"
                                    @input="touchQuoteDate(metal.key)" />
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label :for="`zakatQuotedOn-${metal.key}`" class="form-label">
                                Date you read it <span class="text-danger">*</span>
                            </label>
                            <input :id="`zakatQuotedOn-${metal.key}`" type="date" class="form-control"
                                :max="today" v-model="form[metal.key].quoted_on" />
                            <div class="form-text">
                                Set to today whenever you change this price. Change it by hand if you
                                are entering an earlier close.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label :for="`zakatQuotedFrom-${metal.key}`" class="form-label">Where you read it</label>
                            <input :id="`zakatQuotedFrom-${metal.key}`" type="text" class="form-control"
                                maxlength="255" placeholder="e.g. metals exchange spot, USD per gram"
                                v-model="form[metal.key].quoted_from" />
                            <div class="form-text">
                                Shown to readers beside the figure so they can check the same source.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- The screen will not publish a figure it cannot date. These are
                     the same refusals SaveZakatSettingRequest makes; they are
                     repeated here so the office is stopped before the round trip,
                     not so the server can stop checking. -->
                <div v-if="priceProblems.length" class="alert alert-warning mb-0">
                    <div class="fw-semibold">This cannot be saved yet.</div>
                    <ul class="mb-0 ps-3">
                        <li v-for="problem in priceProblems" :key="problem">{{ problem }}</li>
                    </ul>
                </div>

                <div v-if="saveError" class="alert alert-danger mb-0">{{ saveError }}</div>
            </div>

            <div class="card-footer bg-white border-0 d-flex align-items-center justify-content-between gap-2">
                <button type="button" class="btn btn-outline-danger"
                    :disabled="saving || !hasStoredPrice" @click="clearPrice">
                    <i class="bi bi-x-circle me-1"></i>Remove the price
                </button>
                <button type="button" class="btn btn-success"
                    :disabled="saving || priceProblems.length > 0" @click="save">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    Save price
                </button>
            </div>
        </div>

        <!-- ================================================================
             THE CALCULATOR ITSELF.

             Driven by the PUBLIC endpoint, with the same `masjid-id` header a
             donor's browser sends, so what the office sees here is what a donor
             gets — not a second implementation of the arithmetic that is free to
             disagree with the real one.
             ================================================================ -->
        <div class="card border-0 py-4 px-3 w-100">
            <div class="card-header bg-white border-0">
                <div class="card-title fs-5 fw-semibold mb-0">Work out a zakat figure</div>
            </div>

            <div class="card-body d-flex flex-column gap-4">
                <p class="text-muted mb-0">
                    Enter amounts you have determined yourself. Nothing here is valued for you, and
                    nothing you type is stored or sent anywhere but back to this page.
                </p>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">Assets</h6>
                        <div v-for="field in ZAKAT_ASSET_FIELDS" :key="field.key" class="mb-3">
                            <label :for="`zk-${field.key}`" class="form-label mb-1">{{ field.label }}</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input :id="`zk-${field.key}`" type="number" step="0.01" min="0"
                                    class="form-control" placeholder="0.00" v-model="amounts[field.key]" />
                            </div>
                            <div class="form-text">{{ field.hint }}</div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <h6 class="fw-semibold">Liabilities you are deducting</h6>
                        <div v-for="field in ZAKAT_LIABILITY_FIELDS" :key="field.key" class="mb-3">
                            <label :for="`zk-${field.key}`" class="form-label mb-1">{{ field.label }}</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input :id="`zk-${field.key}`" type="number" step="0.01" min="0"
                                    class="form-control" placeholder="0.00" v-model="amounts[field.key]" />
                            </div>
                            <div class="form-text">{{ field.hint }}</div>
                        </div>

                        <div class="mb-3">
                            <label for="zk-basis" class="form-label mb-1">Basis for this calculation</label>
                            <select id="zk-basis" class="form-select" v-model="calcBasis">
                                <option :value="null">
                                    Whatever this organisation publishes ({{ publishedBasisLabel }})
                                </option>
                                <option v-for="b in NISAB_BASES" :key="b" :value="b" class="text-capitalize">
                                    {{ b }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div v-if="calcError" class="alert alert-danger mb-0">{{ calcError }}</div>

                <!-- ---------------------------------------------------------
                     THE RESULT. Three states, and they are not interchangeable.
                     --------------------------------------------------------- -->
                <div v-if="result" class="border rounded p-4 d-flex flex-column gap-3">

                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small">Net zakatable wealth</div>
                            <div class="fs-4 fw-semibold">
                                {{ money(result.net_zakatable_wealth_minor) }}
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="text-muted small">
                                One fortieth of it ({{ result.rate.percent }}%)
                            </div>
                            <div class="fs-4 fw-semibold">{{ money(result.zakat_at_rate_minor) }}</div>
                        </div>
                        <div class="col-sm-12 col-lg-4">
                            <div class="text-muted small">Compared with the threshold</div>
                            <div class="fs-4 fw-semibold">
                                <span v-if="result.nisab.threshold_minor !== null">
                                    {{ money(result.nisab.threshold_minor) }}
                                </span>
                                <span v-else class="text-warning">unknown</span>
                            </div>
                        </div>
                    </div>

                    <!-- The verdict. `null` is its own outcome and must never be
                         rendered as a zero or as a blank. -->
                    <div v-if="result.meets_nisab_state === 'due'" class="alert alert-success mb-0">
                        <div class="fw-semibold">
                            Zakat on these figures: {{ money(result.zakat_due_minor) }}
                        </div>
                        <div class="small">
                            The declared wealth reaches the threshold shown above.
                        </div>
                    </div>

                    <div v-else-if="result.meets_nisab_state === 'below'" class="alert alert-secondary mb-0">
                        <div class="fw-semibold">
                            The declared wealth is below the threshold shown above, so nothing is due
                            on these figures.
                        </div>
                    </div>

                    <div v-else class="alert alert-warning mb-0">
                        <div class="fw-semibold">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            We cannot tell you whether zakat is due.
                        </div>
                        <div class="small">{{ unknownReason }}</div>
                    </div>

                    <!-- The price behind the verdict, restated here so a reader
                         who scrolled past the banner still sees it. -->
                    <div class="small text-muted">
                        <span v-if="result.nisab.price_per_gram_minor !== null">
                            Threshold based on {{ result.nisab.grams }} g of {{ result.nisab.basis }} at
                            {{ money(result.nisab.price_per_gram_minor) }} per gram,
                            <template v-if="result.nisab.price_quoted_on">
                                recorded {{ longDate(result.nisab.price_quoted_on) }}
                            </template>
                            <template v-else>with no recorded date</template>,
                            {{ sourceLabelFor(result.nisab.price_source) }}<template
                                v-if="result.nisab.price_quoted_from">, taken from
                                {{ result.nisab.price_quoted_from }}</template>.
                        </span>
                        <span v-else>
                            No metal price is available, so no threshold could be computed.
                        </span>
                    </div>

                    <!-- The assumptions, in full, on the page. Not behind a
                         "details" toggle: the reader seeing what produced their
                         number is the whole point of the payload carrying it. -->
                    <div>
                        <h6 class="fw-semibold">What this calculation assumed</h6>
                        <ul class="mb-0 ps-3">
                            <li v-for="a in result.assumptions" :key="a.key" class="mb-2 small">
                                {{ a.statement }}
                            </li>
                        </ul>
                    </div>

                    <p class="small fst-italic mb-0">{{ result.disclaimer }}</p>
                </div>
            </div>

            <div class="card-footer bg-white border-0 d-flex align-items-center justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" :disabled="calculating" @click="resetAmounts">
                    Clear
                </button>
                <button type="button" class="btn btn-primary" :disabled="calculating" @click="calculate">
                    <span v-if="calculating" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    Calculate
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * The zakat calculator screen (T-043c).
 *
 * The engine and its public endpoints shipped months before this page: correct,
 * tested, and answering "threshold unknown" for every tenant on earth, because
 * there was nowhere for a metal price to come from. This screen is both halves
 * of the fix — the place the office records a price, and the place it can see
 * exactly what a donor will be told once it has.
 *
 * ## The one rule this file exists to keep
 *
 * A nisab figure is only worth as much as the price behind it, so the price, its
 * DATE and its ORIGIN are shown every single time the figure is — in the banner
 * at the top, and again under the result. When the price is missing, or older
 * than the review window, the verdict is not softened or hidden: the server
 * returns `meets_nisab: null` and this page says, in words, that it cannot tell.
 * It never renders that state as "$0" or as "no zakat due". Somebody may pay an
 * obligation against what this screen says, and an out-of-date nisab is worse
 * than no nisab at all.
 *
 * ## Two clients on one screen, deliberately
 *
 * The price form talks to the ADMIN API through ApiService (bearer token, tenant
 * from the URL). The calculator talks to the PUBLIC `/api/v1/zakat/*` endpoints
 * through a tokenless axios instance carrying the `masjid-id` header — the same
 * request a donor's browser makes, the publicLunchStore shape. That is on
 * purpose: an admin-only mirror of the calculation would be a second
 * implementation free to drift, and the office would be checking something no
 * donor ever sees.
 *
 * ## Religious content
 *
 * There is none authored here. Every sentence about fiqh on this page comes back
 * from the server in `assumptions[]` and `disclaimer` and is rendered verbatim.
 * The copy this file does own is operational (what to type, what the software
 * will and will not conclude) plus one caveat pointing the organisation at its
 * own scholar.
 */
import { computed, onBeforeMount, reactive, ref } from 'vue';
import axios, { AxiosInstance } from 'axios';
import ApiService from '@/core/services/ApiService';
import { useMasjidStore } from '@/stores/masjidStore';
import {
    NISAB_BASES,
    Nisab,
    NisabBasis,
    NisabPriceSource,
    ZAKAT_ASSET_FIELDS,
    ZAKAT_LIABILITY_FIELDS,
    ZakatCalculation,
    ZakatSetting,
} from '@/core/types/data/masjid-related/Zakat';

/**
 * The two metal blocks, in the order the form shows them. Silver first because
 * it is the deployment default and the threshold most organisations publish.
 */
const METAL_FIELDS: { key: NisabBasis; label: string }[] = [
    { key: 'silver', label: 'Silver' },
    { key: 'gold', label: 'Gold' },
];

const masjidStore = useMasjidStore();

// ------------------------------------------------------------------ state

const loading = ref(true);
const saving = ref(false);
const calculating = ref(false);
const saveError = ref<string | null>(null);
const calcError = ref<string | null>(null);

const setting = ref<ZakatSetting | null>(null);
const nisab = ref<Nisab | null>(null);

/**
 * The server's resolution of BOTH metals, keyed by basis.
 *
 * The office edits two prices with two dates, and either can be stale while the
 * other is current — `nisab` alone only ever describes the published basis. The
 * screen does not work the second answer out: whether a quote has outlived its
 * review window is decided in ZakatCalculator::freshnessOf and nowhere else, and
 * a copy of that judgment here would be free to tell the office a price is fine
 * while the donor endpoint refuses to use it.
 */
const metals = ref<Partial<Record<NisabBasis, Nisab>>>({});

/**
 * Why the settings could not be read, kept apart from `saveError` and from the
 * confirmed "no price is recorded" state.
 *
 * Those are three different facts and only one of them is about the tenant's
 * price. A failed GET that fell through to the no-price banner would have this
 * screen assert in red that the organisation publishes no threshold — a
 * confident diagnosis of a public endpoint it never managed to ask.
 */
const loadError = ref<string | null>(null);

const today = new Date().toISOString().slice(0, 10);

/**
 * One price, one date, one citation — PER METAL, and grouped so they cannot be
 * edited apart.
 *
 * The price holds DOLLARS as typed and converts to integer minor units only at
 * the boundary: keeping cents in the input would make the office do the
 * multiplication by hand on a figure that ends up inside an obligation.
 *
 * The grouping is not tidiness. A flat `price_quoted_on` shared by both prices
 * is a field whose meaning depends on which price was edited last, and the form
 * cannot tell the office which one that was. Nested, the gold date is only ever
 * reachable from the gold block.
 */
type MetalForm = {
    price_dollars: string;
    quoted_on: string | null;
    quoted_from: string | null;
};

function emptyMetalForm(): MetalForm {
    return { price_dollars: '', quoted_on: null, quoted_from: null };
}

const form = reactive<{ nisab_basis: NisabBasis | null } & Record<NisabBasis, MetalForm>>({
    nisab_basis: null,
    gold: emptyMetalForm(),
    silver: emptyMetalForm(),
});

/**
 * What `applyResponse` last loaded, per metal — the yardstick `touchQuoteDate`
 * measures a keystroke against.
 *
 * Without it "the price changed" is unanswerable on this side, and the date can
 * only be defaulted when the box is EMPTY. That was the bug: after the first
 * save the box is never empty, so the default never fired again and every
 * re-quote went out wearing the previous quote's date.
 */
const loaded = reactive<Record<NisabBasis, MetalForm>>({
    gold: emptyMetalForm(),
    silver: emptyMetalForm(),
});

const amounts = reactive<Record<string, string>>({});
const calcBasis = ref<NisabBasis | null>(null);

/**
 * The calculation, plus the verdict collapsed into one of three named states so
 * the template cannot accidentally treat `null` as falsy and render the "below
 * the threshold" branch for it.
 */
const result = ref<(ZakatCalculation & { meets_nisab_state: 'due' | 'below' | 'unknown' }) | null>(null);

// ------------------------------------------------------------- public client

/**
 * Tokenless axios with the `masjid-id` header — the /api/v1 public-tenant idiom.
 * Must not go through ApiService, whose bearer token rides on axios's global
 * defaults: this is the donor's request, and it is meant to be.
 */
function publicClient(masjidId: number | string): AxiosInstance {
    return axios.create({
        baseURL: import.meta.env.VITE_APP_URL ?? '',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'masjid-id': String(masjidId),
        },
    });
}

// --------------------------------------------------------------- formatting

/**
 * The currency the server reported, not one assumed here. It comes from the
 * deployment's Stripe currency and travels at the top of every zakat payload;
 * a hardcoded 'USD' would mislabel every figure on a non-USD deployment.
 */
const currency = ref('USD');

/** Integer minor units to a display string. Never the reverse — see toMinor(). */
function money(minor: number | null): string {
    if (minor === null) {
        return '—';
    }
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.value,
    }).format(minor / 100);
}

/**
 * Dollars as typed to integer minor units, for the WEALTH boxes.
 *
 * `Math.round` on the scaled value rather than a float multiply left as-is:
 * 19.99 * 100 is 1998.9999999999998, and a truncation there would quietly lose
 * a cent from a figure that then gets divided by 40.
 *
 * A zero or unparseable box is null here, which is right for a declaration: an
 * empty "cash" line and a typed 0 both mean "I am not declaring any", and the
 * key is simply left out of the request. That reasoning does NOT carry over to
 * a metal price — see priceToMinor below, which is why the two exist separately.
 */
function toMinor(dollars: string | null | undefined): number | null {
    if (dollars === null || dollars === undefined || String(dollars).trim() === '') {
        return null;
    }
    const value = Number(dollars);
    if (!Number.isFinite(value) || value <= 0) {
        return null;
    }
    return Math.round(value * 100);
}

/**
 * The same conversion for a METAL PRICE, and deliberately not the same rule
 * about zero.
 *
 * `toMinor` above maps 0 and unparseable input to null, which is right for a
 * wealth box: leaving "cash" at 0 means you did not declare any, and there is
 * nothing for the server to refuse. On a metal price null means something else
 * entirely — DELETE THE ORGANISATION'S PUBLISHED PRICE — so mapping a typed 0
 * onto it turns a fumbled keystroke into a threshold vanishing from a public
 * page, with no error anywhere, because the server's `min:1` rule (written
 * because "a metal price of zero is not a cheap market, it is a mistake") never
 * sees the zero at all.
 *
 * So: only a genuinely EMPTY box is null. A typed 0 is posted as 0 and comes
 * back as a 422 the office can read. Non-numeric text is blocked before this by
 * `priceProblems`; if that guard is ever removed it degrades to 0 — a loud
 * refusal — rather than to null, because NaN would JSON-encode as null and we
 * would be back to the silent deletion.
 */
function priceToMinor(dollars: string | null | undefined): number | null {
    const text = String(dollars ?? '').trim();
    if (text === '') {
        return null;
    }
    const value = Number(text);
    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

function fromMinor(minor: number | null | undefined): string {
    return minor === null || minor === undefined ? '' : (minor / 100).toFixed(2);
}

function longDate(iso: string): string {
    const parsed = new Date(`${iso}T00:00:00`);
    return Number.isNaN(parsed.getTime())
        ? iso
        : parsed.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
}

function sourceLabelFor(source: NisabPriceSource | null): string {
    switch (source) {
        case 'organization':
            return 'recorded by this organisation';
        case 'request':
            return 'supplied with the request';
        case 'config':
            return 'configured for this installation, with no date';
        default:
            return 'no price available';
    }
}

const sourceLabel = computed(() => sourceLabelFor(nisab.value?.price_source ?? null));

/**
 * The basis the SETTINGS resolved to, captured at load and not re-read from
 * `nisab` afterwards.
 *
 * `nisab` is re-pointed at the calculation's own resolution when the office runs
 * a figure, and that one may be on the basis the office picked in the calculator
 * rather than the one it publishes. Reading "the basis you publish" off it would
 * make the label under the basis selector change because somebody ran a
 * what-if.
 */
const settingsBasis = ref<NisabBasis>('silver');

const defaultBasisLabel = computed(() => settingsBasis.value);

const publishedBasisLabel = computed<NisabBasis>(
    () => setting.value?.nisab_basis ?? settingsBasis.value,
);

const hasStoredPrice = computed(() =>
    setting.value !== null
    && (setting.value.gold_price_per_gram_minor !== null
        || setting.value.silver_price_per_gram_minor !== null));

/**
 * The badge beside one metal's block: the SERVER's verdict on that metal's own
 * quote, never a comparison worked out here.
 *
 * `price_source` matters as much as the freshness. A metal the office never
 * priced can still resolve to a deployment `.env` figure, and labelling that
 * "current" would credit this organisation with a number it did not type and
 * cannot vouch for.
 */
function metalStatus(metal: NisabBasis): { label: string; cssClass: string } {
    const resolved = metals.value[metal];

    if (!resolved || resolved.price_per_gram_minor === null) {
        return { label: 'no price recorded', cssClass: 'text-bg-secondary' };
    }

    if (resolved.price_source !== 'organization') {
        return { label: 'using the installation default, undated', cssClass: 'text-bg-warning' };
    }

    const on = resolved.price_quoted_on ? longDate(resolved.price_quoted_on) : 'an unknown date';

    if (resolved.price_freshness === 'current') {
        return { label: `read ${on} — current`, cssClass: 'text-bg-success' };
    }

    return { label: `read ${on} — out of date`, cssClass: 'text-bg-warning' };
}

/**
 * Every reason this form may not be published, in the office's words.
 *
 * The middle rule is the one that matters: a price with no date cannot be
 * checked for staleness ever again, so the screen refuses to send one rather
 * than relying on the office noticing the 422. The others keep a mistyped box
 * from being posted as something the server would read as an instruction.
 */
const priceProblems = computed<string[]>(() => {
    const problems: string[] = [];

    METAL_FIELDS.forEach(({ key, label }) => {
        const metal = label.toLowerCase();
        const typed = form[key].price_dollars.trim();
        const quotedOn = form[key].quoted_on;

        if (typed === '') {
            return;
        }

        if (!Number.isFinite(Number(typed))) {
            problems.push(`The ${metal} price must be a number of dollars per gram.`);
            return;
        }

        if (!quotedOn) {
            problems.push(
                `Enter the date you read the ${metal} price. A price with no date cannot be `
                + 'checked for staleness, and an out-of-date threshold can tell someone they owe '
                + 'no zakat when they do.',
            );
            return;
        }

        if (quotedOn > today) {
            problems.push(`The date the ${metal} price was read cannot be in the future.`);
        }
    });

    return problems;
});

// ------------------------------------------------------------------ banner

/**
 * The one place the page decides how loudly to speak about the price.
 *
 * FIVE states, and only one of them is quiet. `undated` is grouped with `stale`
 * rather than with `current`: a price whose age cannot be established is not a
 * price anyone should publish a threshold from.
 *
 * The first state is the one that is easy to leave out. "We could not read the
 * settings" and "there is no price recorded" look identical from here — both
 * leave `nisab` null — but only the second is a fact about the organisation.
 * Collapsing them makes this page announce in red that a tenant publishes no
 * threshold on the strength of a request that never returned, which is a
 * confident false statement about a public endpoint nobody asked.
 */
const banner = computed(() => {
    const days = nisab.value?.price_freshness_days ?? 30;

    if (loadError.value !== null) {
        return {
            cssClass: 'alert-secondary',
            icon: 'bi-wifi-off',
            heading: 'The nisab settings could not be read.',
            body: `${loadError.value} Nothing on this page is a statement about what your `
                + 'calculator is currently telling visitors — it could not be asked. Reload to '
                + 'try again.',
        };
    }

    if (!nisab.value || nisab.value.price_per_gram_minor === null) {
        return {
            cssClass: 'alert-danger',
            icon: 'bi-exclamation-octagon',
            heading: 'No metal price is set, so the calculator cannot state a threshold.',
            body: 'Until a price is recorded below, this organisation\'s calculator tells every '
                + 'visitor that it cannot say whether zakat is due. That is deliberate — it will '
                + 'not guess — but it means the tool is not answering the question people came '
                + 'with.',
        };
    }

    if (nisab.value.price_freshness === 'stale') {
        return {
            cssClass: 'alert-warning',
            icon: 'bi-clock-history',
            heading: `This price is out of date — more than ${days} days old.`,
            body: 'The threshold below is still shown, with its date, because it is a record of '
                + 'what was set. But the calculator has stopped saying whether anyone meets it: an '
                + 'out-of-date threshold can tell a payer they owe nothing when they do. Record a '
                + 'current price to switch it back on.',
        };
    }

    if (nisab.value.price_freshness === 'undated') {
        return {
            cssClass: 'alert-warning',
            icon: 'bi-question-circle',
            heading: 'This price carries no date.',
            body: 'It comes from this installation\'s configuration rather than from your office, '
                + 'so nobody can tell how current it is. Record a dated price below to replace it.',
        };
    }

    return {
        cssClass: 'alert-success',
        icon: 'bi-check-circle',
        heading: 'The calculator is answering with your organisation\'s price.',
        body: `Review it at least every ${days} days. After that the calculator stops drawing `
            + 'conclusions from it until it is refreshed.',
    };
});

/**
 * The metal the banner did NOT describe, when that metal is in trouble.
 *
 * Everything else on this page is about the published basis, because that is
 * what a donor who says nothing is answered on. But the payer may pick the other
 * basis — the calculator offers it precisely because the choice is disputed and
 * is not ours to make — so a stale gold price is a live answer being withheld
 * from real people while a green banner about silver sits above it. Nothing
 * else on the screen would say so.
 */
const otherMetalWarning = computed<string | null>(() => {
    if (loadError.value !== null) {
        return null;
    }

    // The basis the banner just described — the published one normally, or the
    // one a what-if calculation resolved to, since the banner follows that.
    const described = nisab.value?.basis ?? publishedBasisLabel.value;
    const other = METAL_FIELDS.find((m) => m.key !== described);
    const resolved = other ? metals.value[other.key] : undefined;

    if (!other || !resolved || resolved.price_source !== 'organization') {
        return null;
    }

    if (resolved.price_freshness === 'current') {
        return null;
    }

    return `Your ${other.label.toLowerCase()} price is out of date, so a payer who chooses the `
        + `${other.label.toLowerCase()} basis is told this calculator cannot say whether zakat is `
        + 'due. Re-read that price below, or remove it.';
});

/** Why the verdict came back unknown, in the words that match the actual cause. */
const unknownReason = computed(() => {
    const n = result.value?.nisab;
    if (!n || n.price_per_gram_minor === null) {
        return 'This organisation has not recorded a current metal price, so there is no threshold '
            + 'to compare these figures against. No conclusion is offered — not even a zero.';
    }
    if (n.price_freshness === 'stale' || n.price_freshness === 'undated') {
        return 'The metal price behind the threshold is out of date, so no conclusion is drawn from '
            + 'it. The arithmetic above is still yours to use; the threshold comparison is not.';
    }
    return 'The threshold could not be evaluated for these figures.';
});

// ------------------------------------------------------------------ loading

onBeforeMount(async () => {
    resetAmounts();
    await load();
});

async function load(): Promise<void> {
    const masjidId = masjidStore.masjid?.id;
    if (!masjidId) {
        // Not "there is no price" — the tenant is not known yet, so no question
        // about a price has been asked. Saying so is the whole point of this
        // branch having its own state.
        loadError.value = 'This page has no organisation selected yet.';
        loading.value = false;
        return;
    }

    loading.value = true;
    loadError.value = null;
    try {
        const res = await ApiService.get(`/api/admin/masjids/${masjidId}/zakat-settings`);
        applyResponse(res.data?.data);
    } catch (e: any) {
        loadError.value = messageFrom(e, 'The nisab settings could not be loaded.');
    } finally {
        loading.value = false;
    }
}

function applyResponse(data: any): void {
    setting.value = (data?.setting ?? null) as ZakatSetting | null;
    nisab.value = (data?.nisab?.nisab ?? null) as Nisab | null;
    metals.value = (data?.metals ?? {}) as Partial<Record<NisabBasis, Nisab>>;
    settingsBasis.value = (nisab.value?.basis ?? 'silver') as NisabBasis;
    loadError.value = null;
    if (data?.nisab?.currency) {
        currency.value = String(data.nisab.currency);
    }

    form.nisab_basis = setting.value?.nisab_basis ?? null;

    // Each metal's three fields are loaded together, and the same triple is
    // copied into `loaded` as the yardstick for "has this price changed".
    METAL_FIELDS.forEach(({ key }) => {
        const row = setting.value as any;
        const stored: MetalForm = {
            price_dollars: fromMinor(row?.[`${key}_price_per_gram_minor`]),
            quoted_on: row?.[`${key}_price_quoted_on`]
                ? String(row[`${key}_price_quoted_on`]).slice(0, 10)
                : null,
            quoted_from: row?.[`${key}_price_quoted_from`] ?? null,
        };

        form[key].price_dollars = stored.price_dollars;
        form[key].quoted_on = stored.quoted_on;
        form[key].quoted_from = stored.quoted_from;

        loaded[key] = { ...stored };
    });
}

// -------------------------------------------------------------------- saving

/**
 * Keep one metal's date attached to one metal's price, on every keystroke.
 *
 * Editing a price with the OLD date still in the box is the quiet way to ship a
 * stale figure that looks fresh. The previous version of this function guarded
 * on the date being EMPTY, which meant it did its job exactly once: after the
 * first save the loaded date is always in the box, so every re-quote afterwards
 * went out stamped with the previous quote's date — a September price published
 * as read in August, ageing out of its review window nineteen days early and
 * telling every reader a provenance that was not true.
 *
 * So the test is whether the PRICE differs from what was loaded, not whether the
 * date is blank:
 *
 *   changed  — this is a new reading, so it was read today.
 *   emptied  — there is no price left for a date to describe, so the date goes
 *              too (the server strips the pair as well; this keeps the form from
 *              showing an orphan in the meantime).
 *   restored — typed back to the stored figure, so the stored date comes back
 *              with it. An undone keystroke must not leave the date rewritten.
 *
 * It is a default, not a lock. An office entering yesterday's close changes the
 * date by hand afterwards and nothing here touches it again until the price
 * itself changes.
 */
function touchQuoteDate(metal: NisabBasis): void {
    const typed = form[metal].price_dollars.trim();

    if (typed === '') {
        form[metal].quoted_on = null;
        return;
    }

    if (typed === loaded[metal].price_dollars.trim()) {
        form[metal].quoted_on = loaded[metal].quoted_on;
        return;
    }

    form[metal].quoted_on = today;
}

async function save(): Promise<void> {
    const masjidId = masjidStore.masjid?.id;
    if (!masjidId) {
        // Never a silent return. A button that does nothing at all reads as a
        // save that worked, and the next thing the office does is close the tab.
        saveError.value = 'No organisation is selected, so there is nowhere to save this price.';
        return;
    }

    // The last line of the refusal: even if the button were re-enabled, a figure
    // whose price this screen cannot date does not leave it.
    if (priceProblems.value.length > 0) {
        saveError.value = priceProblems.value[0];
        return;
    }

    saveError.value = null;
    saving.value = true;

    // All seven keys travel every time, with explicit nulls: the server preserves
    // an absent key, so clearing a price has to be a null and not an omission.
    // Each price goes out beside ITS date and ITS citation.
    const payload = {
        nisab_basis: form.nisab_basis,
        gold_price_per_gram_minor: priceToMinor(form.gold.price_dollars),
        gold_price_quoted_on: form.gold.quoted_on || null,
        gold_price_quoted_from: form.gold.quoted_from || null,
        silver_price_per_gram_minor: priceToMinor(form.silver.price_dollars),
        silver_price_quoted_on: form.silver.quoted_on || null,
        silver_price_quoted_from: form.silver.quoted_from || null,
    };

    try {
        const res = await ApiService.post(
            `/api/admin/masjids/${masjidId}/zakat-settings`,
            payload,
        );
        applyResponse(res.data?.data);
        // The calculator's last answer was produced under the OLD price, so it
        // is no longer true. Dropping it is safer than leaving a figure on
        // screen that no longer matches the threshold beside it.
        result.value = null;
    } catch (e: any) {
        saveError.value = messageFrom(e, 'The price could not be saved.');
    } finally {
        saving.value = false;
    }
}

/**
 * Removing a price is a real edit: better no threshold than a wrong one.
 *
 * Both metals go, and each one's date and citation go with it — an orphan date
 * left behind is a claim about a figure that is no longer there, and it is one
 * keystroke away from becoming a claim about the next one.
 */
async function clearPrice(): Promise<void> {
    METAL_FIELDS.forEach(({ key }) => {
        form[key].price_dollars = '';
        form[key].quoted_on = null;
        form[key].quoted_from = null;
    });
    await save();
}

// ---------------------------------------------------------------- calculating

function resetAmounts(): void {
    [...ZAKAT_ASSET_FIELDS, ...ZAKAT_LIABILITY_FIELDS].forEach((field) => {
        amounts[field.key] = '';
    });
    result.value = null;
    calcError.value = null;
}

async function calculate(): Promise<void> {
    const masjidId = masjidStore.masjid?.id;
    if (!masjidId) {
        calcError.value = 'No organisation is selected, so there is nothing to calculate against.';
        return;
    }

    calcError.value = null;
    calculating.value = true;

    const body: Record<string, number | string> = {};
    [...ZAKAT_ASSET_FIELDS, ...ZAKAT_LIABILITY_FIELDS].forEach((field) => {
        const minor = toMinor(amounts[field.key]);
        if (minor !== null) {
            body[field.key] = minor;
        }
    });
    if (calcBasis.value) {
        body.basis = calcBasis.value;
    }

    try {
        const res = await publicClient(masjidId).post('/api/v1/zakat/calculate', body);
        const data = res.data?.data as ZakatCalculation;

        // The three-state collapse. `meets_nisab === null` is its own outcome
        // and must not fall through to the "below the threshold" branch.
        const state = data.nisab.meets_nisab === true
            ? 'due'
            : (data.nisab.meets_nisab === false ? 'below' : 'unknown');

        result.value = { ...data, meets_nisab_state: state };
        currency.value = data.currency ?? currency.value;

        // The banner reads from the same resolution the calculation used, so it
        // can never describe a different price from the one behind the answer.
        nisab.value = data.nisab;
    } catch (e: any) {
        calcError.value = messageFrom(e, 'The calculation could not be completed.');
        result.value = null;
    } finally {
        calculating.value = false;
    }
}

// ------------------------------------------------------------------- errors

/**
 * The SERVER's message first, then the thrown one. A 422 arrives as the legacy
 * {status:'failed', data:{field:[…]}} bag, and the field message is the one
 * worth showing — "Enter the date you read this price", not axios's "Request
 * failed with status code 422".
 */
function messageFrom(e: any, fallback: string): string {
    const data = e?.response?.data;
    if (typeof data?.data === 'string') {
        return data.data;
    }
    if (data?.data && typeof data.data === 'object') {
        const first = (Object.values(data.data).flat() as any[])[0];
        if (first) {
            return String(first);
        }
    }
    if (data?.message) {
        return String(data.message);
    }
    return fallback;
}
</script>
