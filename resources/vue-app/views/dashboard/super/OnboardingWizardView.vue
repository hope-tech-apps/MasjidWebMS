<template>
    <div class="card border-0 py-4 px-3 w-100 onboarding-wizard">

        <!-- Header + Stepper -->
        <div class="card-header bg-white border-0 d-flex flex-column gap-3">
            <div class="card-title fs-4 fw-semibold">Onboard a New Masjid</div>
            <ol class="wizard-steps list-unstyled d-flex flex-wrap gap-2 m-0 p-0">
                <li v-for="(step, index) in steps" :key="step.key"
                    class="wizard-step d-flex align-items-center gap-2"
                    :class="{ active: index === currentStep, done: index < currentStep }">
                    <span class="wizard-step-index">{{ index + 1 }}</span>
                    <span class="wizard-step-label">{{ step.title }}</span>
                </li>
            </ol>
        </div>

        <div class="card-body w-100 d-flex flex-column gap-4">

            <!-- ===================== STEP 1: IDENTITY ===================== -->
            <section v-show="currentStep === 0" class="d-flex flex-column gap-4">
                <h5 class="section-heading">Identity &amp; Contact</h5>

                <div class="wizard-field">
                    <label>Masjid Admin <span class="text-muted">(optional)</span></label>
                    <select v-model="form.user_id" class="dashboard-input">
                        <option :value="''">No admin assigned yet</option>
                        <option v-for="admin in masjidAdmins" :key="admin.id" :value="admin.id">{{ admin.name }}</option>
                    </select>
                </div>

                <div class="wizard-field">
                    <label>Name <span class="req">*</span></label>
                    <input v-model="form.name" type="text" class="dashboard-input" placeholder="Masjid name" />
                </div>

                <div class="d-flex flex-column flex-md-row gap-4">
                    <div class="wizard-field w-100">
                        <label>Email <span class="req">*</span></label>
                        <input v-model="form.email" type="email" class="dashboard-input" placeholder="contact@masjid.org" />
                    </div>
                    <div class="wizard-field w-100">
                        <label>Phone <span class="req">*</span></label>
                        <input v-model="form.phone" type="text" class="dashboard-input" placeholder="+1 555 000 0000" />
                    </div>
                </div>

                <div class="wizard-field">
                    <label>Address <span class="req">*</span></label>
                    <input v-model="form.address" type="text" class="dashboard-input" placeholder="Street, City, State" />
                </div>

                <div class="d-flex flex-column flex-md-row gap-4">
                    <div class="wizard-field w-100">
                        <label>Country <span class="req">*</span></label>
                        <select v-model.number="form.country_id" class="dashboard-input">
                            <option :value="0">Select country</option>
                            <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="wizard-field w-100">
                        <label>City <span class="req">*</span></label>
                        <select v-model.number="form.city_id" class="dashboard-input" :disabled="!form.country_id">
                            <option :value="0">Select city</option>
                            <option v-for="c in cities" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex flex-column flex-md-row gap-4">
                    <div class="wizard-field w-100">
                        <label>Latitude <span class="req">*</span></label>
                        <input v-model.number="form.latitude" type="number" step="any" class="dashboard-input" placeholder="43.32" />
                    </div>
                    <div class="wizard-field w-100">
                        <label>Longitude <span class="req">*</span></label>
                        <input v-model.number="form.longitude" type="number" step="any" class="dashboard-input" placeholder="-79.79" />
                    </div>
                </div>

                <div class="wizard-field">
                    <label>Timezone <span class="req">*</span></label>
                    <select v-model="form.timezone" class="dashboard-input">
                        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                    </select>
                </div>

                <div class="wizard-subsection">
                    <span class="subsection-title">Donation link (optional)</span>
                    <div class="wizard-field">
                        <label>Donate URL</label>
                        <input v-model="form.donation_link" type="url" class="dashboard-input" placeholder="https://..." />
                    </div>
                    <div class="d-flex flex-column flex-md-row gap-4">
                        <div class="wizard-field w-100">
                            <label>Button title</label>
                            <input v-model="form.donation_title" type="text" class="dashboard-input" placeholder="Donation Link" />
                        </div>
                        <div class="wizard-field w-100">
                            <label>Button message</label>
                            <input v-model="form.donation_message" type="text" class="dashboard-input" placeholder="Donate Now" />
                        </div>
                    </div>
                </div>

                <div class="wizard-subsection">
                    <span class="subsection-title">Social links (optional)</span>
                    <div class="d-flex flex-column flex-md-row gap-4">
                        <div class="wizard-field w-100">
                            <label>Facebook URL</label>
                            <input v-model="form.facebook_url" type="text" class="dashboard-input" />
                        </div>
                        <div class="wizard-field w-100">
                            <label>YouTube URL</label>
                            <input v-model="form.youtube_url" type="text" class="dashboard-input" />
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-md-row gap-4">
                        <div class="wizard-field w-100">
                            <label>Instagram URL</label>
                            <input v-model="form.instagram_url" type="text" class="dashboard-input" />
                        </div>
                        <div class="wizard-field w-100">
                            <label>WhatsApp number</label>
                            <input v-model="form.whatsapp_number" type="text" class="dashboard-input" placeholder="+1 555 000 0000" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===================== STEP 2: PRAYER ===================== -->
            <section v-show="currentStep === 1" class="d-flex flex-column gap-4">
                <h5 class="section-heading">Prayer &amp; Iqama</h5>

                <div class="wizard-field">
                    <label>Calculation method <span class="req">*</span></label>
                    <select v-model="form.method" class="dashboard-input">
                        <option v-for="m in prayerOptions.methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>

                <div class="d-flex flex-column flex-md-row gap-4">
                    <div class="wizard-field w-100">
                        <label>Madhab (Asr) <span class="req">*</span></label>
                        <select v-model="form.madhab" class="dashboard-input">
                            <option v-for="m in prayerOptions.madhabs" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </div>
                    <div class="wizard-field w-100">
                        <label>High latitude rule <span class="req">*</span></label>
                        <select v-model="form.high_latitude_rule" class="dashboard-input">
                            <option v-for="r in prayerOptions.high_latitude_rules" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="wizard-subsection">
                    <span class="subsection-title">Iqama offsets — minutes after adhan</span>
                    <p class="text-muted small mb-2">
                        Fixed per-date iqama times can be configured later on the masjid's Iqama screen.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <div v-for="salah in salahKeys" :key="salah" class="wizard-field iqama-offset">
                            <label class="text-capitalize">{{ salah }}</label>
                            <input v-model.number="form.iqama[salah]" type="number" min="0" max="180" class="dashboard-input" />
                        </div>
                    </div>
                </div>

                <div class="wizard-field" style="max-width: 16rem;">
                    <label>Jumu'ah iqama time (optional)</label>
                    <input v-model="form.jumaa_iqama" type="time" class="dashboard-input" />
                </div>
            </section>

            <!-- ===================== STEP 3: BRAND ===================== -->
            <section v-show="currentStep === 2" class="d-flex flex-column gap-4">
                <h5 class="section-heading">Brand Colors</h5>
                <p class="text-muted small">
                    Pick the four base colors. Full design-token overrides live in the masjid's Brand Studio after onboarding.
                </p>

                <div class="d-flex flex-wrap gap-4">
                    <div v-for="c in colorFields" :key="c.key" class="wizard-field color-field">
                        <label>{{ c.label }}</label>
                        <div class="d-flex align-items-center gap-2">
                            <input v-model="form.brand[c.key]" type="color" class="color-swatch-input" />
                            <input v-model="form.brand[c.key]" type="text" class="dashboard-input" style="width: 8rem;" />
                        </div>
                    </div>
                </div>

                <div class="brand-preview" :style="{ backgroundColor: form.brand.background_color }">
                    <div class="preview-bar" :style="{ backgroundColor: form.brand.primary_color }">
                        <span :style="{ color: form.brand.background_color }">Header</span>
                    </div>
                    <div class="preview-body">
                        <span class="preview-chip" :style="{ backgroundColor: form.brand.secondary_color, color: '#fff' }">Secondary</span>
                        <span class="preview-chip" :style="{ backgroundColor: form.brand.accent_color, color: '#000' }">Accent</span>
                    </div>
                </div>
            </section>

            <!-- ===================== STEP 4: CONTENT ===================== -->
            <section v-show="currentStep === 3" class="d-flex flex-column gap-4">
                <h5 class="section-heading">Content &amp; Features</h5>

                <div class="wizard-field">
                    <label>About</label>
                    <textarea v-model="form.about" rows="3" class="dashboard-input" placeholder="About this masjid"></textarea>
                </div>
                <div class="d-flex flex-column flex-md-row gap-4">
                    <div class="wizard-field w-100">
                        <label>Mission</label>
                        <textarea v-model="form.mission" rows="3" class="dashboard-input"></textarea>
                    </div>
                    <div class="wizard-field w-100">
                        <label>Vision</label>
                        <textarea v-model="form.vision" rows="3" class="dashboard-input"></textarea>
                    </div>
                </div>

                <div class="wizard-subsection">
                    <span class="subsection-title">Mobile app features</span>
                    <p class="text-muted small mb-2">Toggle which features appear in this masjid's app.</p>
                    <div v-if="features.length" class="d-flex flex-wrap gap-3">
                        <label v-for="f in features" :key="f.key" class="feature-toggle d-flex align-items-center gap-2">
                            <input type="checkbox" :value="f.key" v-model="form.feature_keys" />
                            <span>{{ f.name }}</span>
                        </label>
                    </div>
                    <p v-else class="text-muted small">No feature catalog available.</p>
                </div>
            </section>

            <!-- ===================== STEP 5: APPS ===================== -->
            <section v-show="currentStep === 4" class="d-flex flex-column gap-4">
                <h5 class="section-heading">App Publishing</h5>
                <p class="text-muted small">
                    <strong>Managed</strong> (default) means we publish the app under Hope Tech / the organization's
                    developer accounts — this is the paid tier. <strong>Bring your own</strong> means the masjid
                    publishes under its own accounts and supplies credentials.
                </p>

                <!-- Platform selection -->
                <div class="wizard-subsection">
                    <span class="subsection-title">Platforms to provision</span>
                    <p class="text-muted small mb-2">Choose which platforms this masjid will publish to.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <label v-for="p in platformOptions" :key="p.slug" class="mode-pill"
                            :class="{
                                selected: form.platforms.includes(p.slug),
                                'pill-disabled': p.slug === 'tvos' && !form.platforms.includes('ios'),
                            }">
                            <input type="checkbox" :value="p.slug" v-model="form.platforms"
                                :disabled="p.slug === 'tvos' && !form.platforms.includes('ios')" />
                            {{ p.label }}
                        </label>
                    </div>
                    <p class="text-muted small mb-0">tvOS ships under the iOS Apple account.</p>
                </div>

                <!-- iOS -->
                <div v-if="form.platforms.includes('ios')" class="platform-card">
                    <div class="platform-head d-flex align-items-center justify-content-between">
                        <span class="platform-name">iOS — Apple App Store</span>
                        <div class="mode-toggle d-flex gap-2">
                            <label class="mode-pill" :class="{ selected: form.apps.ios.account_mode === 'managed' }">
                                <input type="radio" value="managed" v-model="form.apps.ios.account_mode" /> Managed
                            </label>
                            <label class="mode-pill" :class="{ selected: form.apps.ios.account_mode === 'byo' }">
                                <input type="radio" value="byo" v-model="form.apps.ios.account_mode" /> Bring your own
                            </label>
                        </div>
                    </div>
                    <div v-if="form.apps.ios.account_mode === 'byo'" class="platform-body d-flex flex-column gap-3">
                        <div class="wizard-field">
                            <label>App Store Connect API key (.p8 contents) <span class="req">*</span></label>
                            <textarea v-model="form.apps.ios.asc_key_p8" rows="4" class="dashboard-input secret-input"
                                placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea>
                        </div>
                        <div class="d-flex flex-column flex-md-row gap-4">
                            <div class="wizard-field w-100">
                                <label>Key ID <span class="req">*</span></label>
                                <input v-model="form.apps.ios.asc_key_id" type="text" class="dashboard-input" />
                            </div>
                            <div class="wizard-field w-100">
                                <label>Issuer ID <span class="req">*</span></label>
                                <input v-model="form.apps.ios.asc_issuer_id" type="text" class="dashboard-input" />
                            </div>
                        </div>
                        <p class="secret-note small">Stored encrypted. Never shown again after saving.</p>
                    </div>
                </div>

                <!-- Android -->
                <div v-if="form.platforms.includes('android')" class="platform-card">
                    <div class="platform-head d-flex align-items-center justify-content-between">
                        <span class="platform-name">Android — Google Play</span>
                        <div class="mode-toggle d-flex gap-2">
                            <label class="mode-pill" :class="{ selected: form.apps.android.account_mode === 'managed' }">
                                <input type="radio" value="managed" v-model="form.apps.android.account_mode" /> Managed
                            </label>
                            <label class="mode-pill" :class="{ selected: form.apps.android.account_mode === 'byo' }">
                                <input type="radio" value="byo" v-model="form.apps.android.account_mode" /> Bring your own
                            </label>
                        </div>
                    </div>
                    <div v-if="form.apps.android.account_mode === 'byo'" class="platform-body d-flex flex-column gap-3">
                        <div class="wizard-field">
                            <label>Play service-account JSON <span class="req">*</span></label>
                            <textarea v-model="form.apps.android.play_service_account_json" rows="5" class="dashboard-input secret-input"
                                placeholder='{ "type": "service_account", ... }'></textarea>
                        </div>
                        <p v-if="androidJsonInvalid" class="wizard-error small">That does not parse as valid JSON.</p>
                        <p class="secret-note small">Stored encrypted. Never shown again after saving.</p>
                    </div>
                </div>

                <!-- Web -->
                <div v-if="form.platforms.includes('web')" class="platform-card">
                    <div class="platform-head d-flex align-items-center justify-content-between">
                        <span class="platform-name">Web</span>
                        <div class="mode-toggle d-flex gap-2">
                            <label class="mode-pill" :class="{ selected: form.apps.web.account_mode === 'managed' }">
                                <input type="radio" value="managed" v-model="form.apps.web.account_mode" /> Managed
                            </label>
                            <label class="mode-pill" :class="{ selected: form.apps.web.account_mode === 'byo' }">
                                <input type="radio" value="byo" v-model="form.apps.web.account_mode" /> Bring your own
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ===================== STEP 6: REVIEW ===================== -->
            <section v-show="currentStep === 5" class="d-flex flex-column gap-4">
                <h5 class="section-heading">Review &amp; Provision</h5>

                <div v-if="createdMasjidId" class="success-panel">
                    <div class="fs-5 fw-semibold mb-1">Masjid created</div>
                    <p class="mb-2">New masjid id: <strong>#{{ createdMasjidId }}</strong></p>
                    <router-link :to="`/dashboard/super/masjids/${createdMasjidId}`" class="btn btn-success btn-sm">
                        Open masjid
                    </router-link>
                </div>

                <template v-else>
                    <div class="review-grid">
                        <div class="review-item"><span>Name</span><strong>{{ form.name || '—' }}</strong></div>
                        <div class="review-item"><span>Email</span><strong>{{ form.email || '—' }}</strong></div>
                        <div class="review-item"><span>Phone</span><strong>{{ form.phone || '—' }}</strong></div>
                        <div class="review-item"><span>Location</span><strong>{{ countryName }} / {{ cityName }}</strong></div>
                        <div class="review-item"><span>Timezone</span><strong>{{ form.timezone }}</strong></div>
                        <div class="review-item"><span>Method</span><strong>{{ form.method }}</strong></div>
                        <div class="review-item"><span>Madhab</span><strong>{{ form.madhab }}</strong></div>
                        <div class="review-item"><span>Features on</span><strong>{{ form.feature_keys.length }}</strong></div>
                        <div class="review-item">
                            <span>Platforms</span>
                            <span v-if="selectedPlatformLabels.length" class="d-flex flex-wrap gap-1">
                                <span v-for="label in selectedPlatformLabels" :key="label" class="mode-pill selected">{{ label }}</span>
                            </span>
                            <strong v-else>—</strong>
                        </div>
                        <div v-if="form.platforms.includes('ios')" class="review-item"><span>iOS</span><strong>{{ appModeLabel(form.apps.ios.account_mode) }}</strong></div>
                        <div v-if="form.platforms.includes('android')" class="review-item"><span>Android</span><strong>{{ appModeLabel(form.apps.android.account_mode) }}</strong></div>
                        <div v-if="form.platforms.includes('web')" class="review-item"><span>Web</span><strong>{{ appModeLabel(form.apps.web.account_mode) }}</strong></div>
                        <div class="review-item">
                            <span>Brand</span>
                            <span class="d-flex gap-1">
                                <span class="mini-swatch" :style="{ backgroundColor: form.brand.primary_color }"></span>
                                <span class="mini-swatch" :style="{ backgroundColor: form.brand.secondary_color }"></span>
                                <span class="mini-swatch" :style="{ backgroundColor: form.brand.accent_color }"></span>
                                <span class="mini-swatch" :style="{ backgroundColor: form.brand.background_color }"></span>
                            </span>
                        </div>
                    </div>
                    <p v-if="!allStepsValid" class="wizard-error">
                        Some required fields are missing. Review the highlighted steps before provisioning.
                    </p>
                </template>
            </section>

            <!-- Per-step validation messages -->
            <ul v-if="!createdMasjidId && currentStepErrors.length" class="wizard-error-list">
                <li v-for="(err, i) in currentStepErrors" :key="i" class="wizard-error">{{ err }}</li>
            </ul>
        </div>

        <!-- Footer nav -->
        <div class="card-footer bg-white border-0 d-flex align-items-center justify-content-between w-100">
            <button v-if="currentStep > 0 && !createdMasjidId" type="button" class="btn btn-outline-secondary"
                @click="prev">Back</button>
            <span v-else></span>

            <div class="d-flex gap-2">
                <button v-if="currentStep < steps.length - 1" type="button" class="btn btn-success"
                    :disabled="currentStepErrors.length > 0" @click="next">Next</button>

                <LoadingButton v-else-if="!createdMasjidId" type="button" :is-loading="isLoading"
                    classes="btn-success" @click.prevent="provision">
                    Provision Masjid
                </LoadingButton>

                <button v-else type="button" class="btn btn-success" @click="resetWizard">Onboard another</button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import ApiService from '@/core/services/ApiService';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { City, Country } from '@/core/types/data/Country';
import { MasjidAdmin } from '@/core/types/data/Admin';
import { useUsersStore } from '@/stores/super/usersStore';
import { AxiosError } from 'axios';
import { SweetAlertOptions } from 'sweetalert2';
import { computed, onBeforeMount, reactive, ref, watch } from 'vue';

type PrayerOption = { value: string; label: string };
type FeatureOption = { id: number; key: string; name: string };
type AccountMode = 'managed' | 'byo';

const usersStore = useUsersStore();

const steps = [
    { key: 'identity', title: 'Identity' },
    { key: 'prayer', title: 'Prayer' },
    { key: 'brand', title: 'Brand' },
    { key: 'content', title: 'Content' },
    { key: 'apps', title: 'Apps' },
    { key: 'review', title: 'Review' },
];

const salahKeys = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'] as const;

const colorFields = [
    { key: 'primary_color' as const, label: 'Primary' },
    { key: 'secondary_color' as const, label: 'Secondary' },
    { key: 'accent_color' as const, label: 'Accent' },
    { key: 'background_color' as const, label: 'Background' },
];

// Selectable publishing platforms. tvOS ships under the iOS Apple account, so it
// has no account-mode block of its own and depends on iOS being selected.
const platformOptions = [
    { slug: 'ios', label: 'iOS' },
    { slug: 'android', label: 'Android' },
    { slug: 'tvos', label: 'tvOS' },
    { slug: 'web', label: 'Web' },
];

const currentStep = ref(0);
const isLoading = ref(false);
const createdMasjidId = ref<number | null>(null);

// Fetched catalogs
const countries = ref<Country[]>([]);
const cities = ref<City[]>([]);
const masjidAdmins = ref<MasjidAdmin[]>([]);
const features = ref<FeatureOption[]>([]);
const prayerOptions = reactive<{ methods: PrayerOption[]; madhabs: PrayerOption[]; high_latitude_rules: PrayerOption[] }>({
    methods: [],
    madhabs: [],
    high_latitude_rules: [],
});

// Timezone list from the platform (IANA identifiers match PHP's `timezone` rule).
const timezones = ref<string[]>([]);

const form = reactive({
    user_id: '' as number | '',
    name: '',
    email: '',
    phone: '',
    address: '',
    country_id: 0,
    city_id: 0,
    latitude: null as number | null,
    longitude: null as number | null,
    timezone: '',
    donation_link: '',
    donation_title: '',
    donation_message: '',
    facebook_url: '',
    youtube_url: '',
    instagram_url: '',
    whatsapp_url: '',
    whatsapp_number: '',
    method: 'MoonsightingCommittee',
    madhab: 'Shafi',
    high_latitude_rule: 'MiddleOfTheNight',
    iqama_type: 'minutes_after_adhan',
    iqama: { fajr: 20, dhuhr: 10, asr: 10, maghrib: 5, isha: 10 } as Record<string, number>,
    jumaa_iqama: '',
    brand: {
        primary_color: '#01b151',
        secondary_color: '#1b1b2e',
        accent_color: '#ffba63',
        background_color: '#f3f8fb',
    } as Record<string, string>,
    about: '',
    mission: '',
    vision: '',
    feature_keys: [] as string[],
    // Platforms to provision. Defaults to the historical set (iOS/Android/Web) so
    // existing onboarding behavior is preserved. tvOS is opt-in and iOS-gated.
    platforms: ['ios', 'android', 'web'] as string[],
    apps: {
        ios: { account_mode: 'managed' as AccountMode, asc_key_p8: '', asc_key_id: '', asc_issuer_id: '' },
        android: { account_mode: 'managed' as AccountMode, play_service_account_json: '' },
        web: { account_mode: 'managed' as AccountMode },
    },
});

onBeforeMount(async () => {
    // Timezones from the browser when supported; fall back to the local zone.
    try {
        const supported = (Intl as unknown as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf;
        timezones.value = supported ? supported('timeZone') : [];
    } catch {
        timezones.value = [];
    }
    const localTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!timezones.value.length && localTz) timezones.value = [localTz];
    if (localTz && timezones.value.includes(localTz)) form.timezone = localTz;
    else if (timezones.value.length) form.timezone = timezones.value[0];

    await usersStore.fetchMasjidAdmins(masjidAdmins);

    await ApiService.get('/api/admin/onboarding/options')
        .then(res => {
            if (res.data?.status === 'success' && res.data?.data) {
                const d = res.data.data;
                features.value = d.features ?? [];
                prayerOptions.methods = d.prayer?.methods ?? [];
                prayerOptions.madhabs = d.prayer?.madhabs ?? [];
                prayerOptions.high_latitude_rules = d.prayer?.high_latitude_rules ?? [];
                countries.value = d.countries ?? [];
                // Default: enable every feature (admin can uncheck).
                form.feature_keys = features.value.map(f => f.key);
            }
        })
        .catch((e: Error) => console.log(e));
});

// Reload cities whenever the country changes.
watch(() => form.country_id, async (id) => {
    form.city_id = 0;
    cities.value = [];
    if (!id) return;
    await ApiService.get(`/api/admin/countries/${id}/cities`)
        .then(res => {
            if (res.data?.status === 'success' && res.data?.data) cities.value = res.data.data;
        })
        .catch((e: Error) => console.log(e));
});

// tvOS cannot ship without iOS (it publishes under the iOS Apple account), so
// drop it from the selection whenever iOS is deselected.
watch(() => form.platforms.includes('ios'), (iosSelected) => {
    if (!iosSelected) {
        const i = form.platforms.indexOf('tvos');
        if (i !== -1) form.platforms.splice(i, 1);
    }
});

// ---- Validation helpers ----
const emailValid = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
const hexValid = (v: string) => !v || /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(v);
const jsonValid = (v: string) => { try { JSON.parse(v); return true; } catch { return false; } };

const androidJsonInvalid = computed(() =>
    form.apps.android.account_mode === 'byo' &&
    form.apps.android.play_service_account_json.trim().length > 0 &&
    !jsonValid(form.apps.android.play_service_account_json)
);

function identityErrors(): string[] {
    const e: string[] = [];
    if (!form.name.trim()) e.push('Name is required.');
    if (!form.email.trim() || !emailValid(form.email)) e.push('A valid email is required.');
    if (!form.phone.trim()) e.push('Phone is required.');
    if (!form.address.trim()) e.push('Address is required.');
    if (!form.country_id) e.push('Country is required.');
    if (!form.city_id) e.push('City is required.');
    const lat = form.latitude as number | string | null;
    const lng = form.longitude as number | string | null;
    if (lat === null || lat === '' || isNaN(Number(lat)) || Number(lat) < -90 || Number(lat) > 90) e.push('Latitude must be between -90 and 90.');
    if (lng === null || lng === '' || isNaN(Number(lng)) || Number(lng) < -180 || Number(lng) > 180) e.push('Longitude must be between -180 and 180.');
    if (!form.timezone) e.push('Timezone is required.');
    if (form.donation_link && !/^https?:\/\//i.test(form.donation_link)) e.push('Donation link must be a valid URL.');
    return e;
}

function prayerErrors(): string[] {
    const e: string[] = [];
    if (!form.method) e.push('Calculation method is required.');
    if (!form.madhab) e.push('Madhab is required.');
    if (!form.high_latitude_rule) e.push('High latitude rule is required.');
    if (form.jumaa_iqama && !/^\d{2}:\d{2}$/.test(form.jumaa_iqama)) e.push('Jumu\'ah time must be HH:MM.');
    return e;
}

function brandErrors(): string[] {
    const e: string[] = [];
    for (const c of colorFields) {
        if (!hexValid(form.brand[c.key])) e.push(`${c.label} color must be a hex value.`);
    }
    return e;
}

function contentErrors(): string[] {
    const e: string[] = [];
    if (form.about.length > 5000) e.push('About is too long.');
    return e;
}

function platformsErrors(): string[] {
    const e: string[] = [];
    if (form.platforms.length === 0) e.push('Select at least one platform.');
    if (form.platforms.includes('tvos') && !form.platforms.includes('ios')) {
        e.push('tvOS requires iOS to be selected (it ships under the iOS Apple account).');
    }
    return e;
}

function appsErrors(): string[] {
    // Platform selection is validated as part of the Apps step.
    const e: string[] = [...platformsErrors()];
    // Only require account-mode credentials for platforms that are actually selected.
    if (form.platforms.includes('ios') && form.apps.ios.account_mode === 'byo') {
        if (!form.apps.ios.asc_key_p8.trim()) e.push('iOS: App Store Connect .p8 key is required for bring-your-own.');
        if (!form.apps.ios.asc_key_id.trim()) e.push('iOS: Key ID is required for bring-your-own.');
        if (!form.apps.ios.asc_issuer_id.trim()) e.push('iOS: Issuer ID is required for bring-your-own.');
    }
    if (form.platforms.includes('android') && form.apps.android.account_mode === 'byo') {
        if (!form.apps.android.play_service_account_json.trim()) e.push('Android: Play service-account JSON is required for bring-your-own.');
        else if (!jsonValid(form.apps.android.play_service_account_json)) e.push('Android: Play service-account JSON is not valid JSON.');
    }
    return e;
}

function errorsForStep(step: number): string[] {
    switch (step) {
        case 0: return identityErrors();
        case 1: return prayerErrors();
        case 2: return brandErrors();
        case 3: return contentErrors();
        case 4: return appsErrors();
        default: return [];
    }
}

const currentStepErrors = computed(() => errorsForStep(currentStep.value));
const allStepsValid = computed(() => [0, 1, 2, 3, 4].every(s => errorsForStep(s).length === 0));

const countryName = computed(() => countries.value.find(c => c.id === form.country_id)?.name ?? '—');
const cityName = computed(() => cities.value.find(c => c.id === form.city_id)?.name ?? '—');
const appModeLabel = (m: AccountMode) => (m === 'managed' ? 'Managed (paid)' : 'Bring your own');
const selectedPlatformLabels = computed(() =>
    platformOptions.filter(p => form.platforms.includes(p.slug)).map(p => p.label)
);

function next() {
    if (currentStepErrors.value.length === 0 && currentStep.value < steps.length - 1) currentStep.value++;
}
function prev() {
    if (currentStep.value > 0) currentStep.value--;
}

/**
 * Recursively append a plain object/array into FormData using bracket notation
 * (`apps[ios][account_mode]`). Passing a real FormData instance guarantees axios
 * sends multipart with a boundary regardless of the global default content-type,
 * and Laravel re-parses the bracketed keys into the nested arrays the
 * ProvisionMasjidRequest dot-rules validate. null / undefined / '' are skipped so
 * optional fields arrive as absent, not empty-string.
 */
function appendNested(fd: FormData, value: unknown, key: string) {
    if (value === null || value === undefined || value === '') return;
    if (Array.isArray(value)) {
        value.forEach((v, i) => appendNested(fd, v, `${key}[${i}]`));
    } else if (typeof value === 'object') {
        Object.entries(value as Record<string, unknown>).forEach(([k, v]) => appendNested(fd, v, `${key}[${k}]`));
    } else {
        fd.append(key, String(value));
    }
}

function resetWizard() {
    createdMasjidId.value = null;
    currentStep.value = 0;
}

async function provision() {
    if (!allStepsValid.value) {
        MSwal.fire({ title: 'Incomplete', text: 'Please complete all required fields.', icon: 'warning' });
        return;
    }

    const confirm = await QSwal.fire('Question', `Create and configure "${form.name}"?`, 'question');
    if (!confirm.isConfirmed) return;

    isLoading.value = true;

    // Build the payload, including only BYO credentials for platforms in BYO mode.
    const payload: Record<string, unknown> = {
        name: form.name,
        email: form.email,
        phone: form.phone,
        address: form.address,
        country_id: form.country_id,
        city_id: form.city_id,
        latitude: form.latitude,
        longitude: form.longitude,
        timezone: form.timezone,
        user_id: form.user_id || '',
        donation_link: form.donation_link,
        donation_title: form.donation_title,
        donation_message: form.donation_message,
        facebook_url: form.facebook_url,
        youtube_url: form.youtube_url,
        instagram_url: form.instagram_url,
        whatsapp_url: form.whatsapp_url,
        whatsapp_number: form.whatsapp_number,
        method: form.method,
        madhab: form.madhab,
        high_latitude_rule: form.high_latitude_rule,
        iqama_type: form.iqama_type,
        iqama: form.iqama,
        jumaa_iqama: form.jumaa_iqama,
        brand: form.brand,
        about: form.about,
        mission: form.mission,
        vision: form.vision,
        // Explicit-selection flag: survives the empty-array case (see controller).
        feature_keys_provided: '1',
        feature_keys: form.feature_keys,
        // Selected publishing platforms (backend validates platforms.*).
        platforms: form.platforms,
        apps: {
            ios: { account_mode: form.apps.ios.account_mode },
            android: { account_mode: form.apps.android.account_mode },
            web: { account_mode: form.apps.web.account_mode },
        },
    };

    const appsObj = payload.apps as Record<string, Record<string, unknown>>;
    if (form.apps.ios.account_mode === 'byo') {
        appsObj.ios.asc_key_p8 = form.apps.ios.asc_key_p8;
        appsObj.ios.asc_key_id = form.apps.ios.asc_key_id;
        appsObj.ios.asc_issuer_id = form.apps.ios.asc_issuer_id;
    }
    if (form.apps.android.account_mode === 'byo') {
        appsObj.android.play_service_account_json = form.apps.android.play_service_account_json;
    }

    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => appendNested(fd, v, k));

    const swalInstance: SweetAlertOptions = { title: 'Info', text: '', icon: 'info' };

    await ApiService.post('/api/admin/onboarding/provision', fd)
        .then(res => {
            if (res.data?.status === 'success') {
                createdMasjidId.value = res.data.data?.masjid_id ?? null;
                swalInstance.title = 'Success';
                swalInstance.text = 'Masjid provisioned successfully.';
                swalInstance.icon = 'success';
            } else {
                swalInstance.title = 'Sorry';
                swalInstance.text = getMessageFromObj(res);
                swalInstance.icon = 'warning';
            }
        })
        .catch((e: AxiosError<BackendResponseData>) => {
            swalInstance.title = e.response?.status === 422 ? 'Validation Error' : e.message;
            swalInstance.text = getMessageFromObj(e);
            swalInstance.icon = 'error';
        })
        .finally(() => {
            isLoading.value = false;
            MSwal.fire(swalInstance);
        });
}
</script>

<style scoped>
.onboarding-wizard .section-heading {
    font-weight: 600;
    margin: 0;
}

.wizard-steps .wizard-step {
    padding: .35rem .75rem;
    border-radius: 2rem;
    background: var(--input-border, #e6e6e6);
    color: #555;
    font-size: .9rem;
}

.wizard-steps .wizard-step .wizard-step-index {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.4rem;
    height: 1.4rem;
    border-radius: 50%;
    background: #fff;
    font-size: .8rem;
    font-weight: 600;
}

.wizard-steps .wizard-step.active {
    background: var(--cgreen, #01b151);
    color: #fff;
}

.wizard-steps .wizard-step.done {
    background: var(--cgreen-active, #018a40);
    color: #fff;
}

.wizard-field {
    display: flex;
    flex-direction: column;
    gap: .35rem;
}

.wizard-field label {
    font-size: .9rem;
    font-weight: 500;
}

.wizard-field .req {
    color: #d9534f;
}

.wizard-subsection {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.wizard-subsection .subsection-title {
    font-weight: 600;
    font-size: .95rem;
}

.iqama-offset {
    width: 6rem;
}

.color-field .color-swatch-input {
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
    border: 1px solid var(--input-border, #ccc);
    border-radius: .35rem;
    background: none;
}

.brand-preview {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    overflow: hidden;
    max-width: 24rem;
}

.brand-preview .preview-bar {
    padding: .75rem 1rem;
    font-weight: 600;
}

.brand-preview .preview-body {
    padding: 1rem;
    display: flex;
    gap: .5rem;
}

.preview-chip {
    padding: .25rem .75rem;
    border-radius: 1rem;
    font-size: .85rem;
}

.feature-toggle {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .4rem;
    padding: .4rem .7rem;
    cursor: pointer;
}

.platform-card {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.platform-card .platform-name {
    font-weight: 600;
}

.mode-pill {
    border: 1px solid var(--input-border, #ccc);
    border-radius: 2rem;
    padding: .25rem .75rem;
    font-size: .85rem;
    cursor: pointer;
}

.mode-pill.selected {
    background: var(--cgreen, #01b151);
    color: #fff;
    border-color: var(--cgreen, #01b151);
}

.mode-pill.pill-disabled {
    opacity: .5;
    cursor: not-allowed;
}

.secret-input {
    font-family: monospace;
    font-size: .8rem;
}

.secret-note {
    color: #888;
    margin: 0;
}

.review-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
    gap: .75rem;
}

.review-item {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--input-border, #eee);
    border-radius: .4rem;
    padding: .5rem .75rem;
}

.review-item span {
    font-size: .75rem;
    color: #888;
    text-transform: uppercase;
}

.mini-swatch {
    display: inline-block;
    width: 1.1rem;
    height: 1.1rem;
    border-radius: .2rem;
    border: 1px solid #ddd;
}

.success-panel {
    border: 1px solid var(--cgreen, #01b151);
    background: rgba(1, 177, 81, .08);
    border-radius: .5rem;
    padding: 1.25rem;
}

.wizard-error {
    color: #d9534f;
    font-size: .9rem;
    margin: 0;
}

.wizard-error-list {
    list-style: disc;
    margin: 0;
    padding-left: 1.25rem;
}
</style>
