<template>
    <StudioPanel title="Identity">
        <div class="studio-field">
            <span class="studio-label">Organisation type <span class="req">*</span></span>
            <p v-if="store.optionsError" class="studio-error">{{ store.optionsError }}</p>
            <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="Organisation type">
                <label v-for="vertical in verticals" :key="vertical.org_type" class="mode-pill"
                    :class="{ selected: identity.org_type === vertical.org_type }">
                    <input type="radio" name="studio-org-type" :value="vertical.org_type" v-model="identity.org_type" />
                    {{ vertical.label }}
                </label>
            </div>
            <p class="studio-hint">
                The type sets the words the admin panel uses, the features offered at Features and the layouts
                offered at Layout.
            </p>

            <div v-if="selectedVertical" class="vertical-effects">
                <div>
                    <span class="effect-title">What the admin panel will call things</span>
                    <dl v-if="terminologyRows.length" class="terminology-list mb-0">
                        <div v-for="row in terminologyRows" :key="row.key" class="terminology-row">
                            <dt>{{ row.key }}</dt>
                            <dd>{{ row.value }}</dd>
                        </div>
                    </dl>
                    <p v-else class="studio-hint">No terminology pack for this type.</p>
                </div>
                <div>
                    <span class="effect-title">Prayer settings</span>
                    <p class="studio-hint mb-0">
                        {{ asksPrayer(store.answers) ? 'Asked for, in the Prayer panel.' : `Not asked for a ${selectedVertical.label.toLowerCase()}.` }}
                    </p>
                </div>
            </div>
        </div>

        <div class="studio-field">
            <label for="studio-name">Name <span class="req">*</span></label>
            <input id="studio-name" v-model="identity.name" type="text" maxlength="255" class="dashboard-input" />
        </div>

        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-email">Email</label>
                <input id="studio-email" v-model="identity.email" type="email" maxlength="255" class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-phone">Phone</label>
                <input id="studio-phone" v-model="identity.phone" type="tel" maxlength="40" class="dashboard-input" />
            </div>
        </div>

        <div class="studio-field">
            <label for="studio-address">Address</label>
            <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
                <input id="studio-address" v-model="identity.address" type="text" maxlength="1000"
                    class="dashboard-input flex-grow-1" />
                <button type="button" class="btn btn-outline-success text-nowrap"
                    :disabled="geocoding || !(identity.address ?? '').trim()" @click="geocode">
                    {{ geocoding ? 'Finding…' : 'Find coordinates' }}
                </button>
            </div>
            <p v-if="geocodeMessage" :class="geocodeFailed ? 'studio-error' : 'studio-hint'">{{ geocodeMessage }}</p>
        </div>

        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-country">Country</label>
                <select id="studio-country" v-model.number="identity.country_id" class="dashboard-input" @change="onCountryChange">
                    <option :value="null">Select country</option>
                    <option v-for="country in store.options?.countries ?? []" :key="country.id" :value="country.id">
                        {{ country.name }}
                    </option>
                </select>
            </div>
            <div class="studio-field w-100">
                <label for="studio-city">City</label>
                <select id="studio-city" v-model.number="identity.city_id" class="dashboard-input" :disabled="!identity.country_id">
                    <option :value="null">Select city</option>
                    <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                </select>
            </div>
        </div>

        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-latitude">Latitude</label>
                <input id="studio-latitude" v-model.number="identity.latitude" type="number" step="any" min="-90" max="90"
                    class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-longitude">Longitude</label>
                <input id="studio-longitude" v-model.number="identity.longitude" type="number" step="any" min="-180" max="180"
                    class="dashboard-input" />
            </div>
            <div class="studio-field w-100">
                <label for="studio-timezone">Timezone</label>
                <select id="studio-timezone" v-model="identity.timezone" class="dashboard-input">
                    <option :value="null">Select timezone</option>
                    <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
            </div>
        </div>

        <div class="studio-subsection">
            <span class="effect-title">Organisation admin</span>
            <div class="studio-field">
                <label for="studio-admin-existing">An existing admin account</label>
                <select id="studio-admin-existing" v-model.number="identity.user_id" class="dashboard-input" @change="onExistingAdmin">
                    <option :value="null">None</option>
                    <option v-for="admin in admins" :key="admin.id" :value="admin.id">{{ admin.name }} ({{ admin.email }})</option>
                </select>
            </div>
            <p class="studio-hint">Or a new admin, invited when the organisation is created:</p>
            <div class="d-flex flex-column flex-md-row gap-3">
                <div class="studio-field w-100">
                    <label for="studio-admin-name">Name</label>
                    <input id="studio-admin-name" :value="identity.admin?.name ?? ''" type="text" maxlength="255"
                        class="dashboard-input" :disabled="!!identity.user_id" @input="setAdmin('name', $event)" />
                </div>
                <div class="studio-field w-100">
                    <label for="studio-admin-email">Email</label>
                    <input id="studio-admin-email" :value="identity.admin?.email ?? ''" type="email" maxlength="255"
                        class="dashboard-input" :disabled="!!identity.user_id" @input="setAdmin('email', $event)" />
                </div>
                <div class="studio-field w-100">
                    <label for="studio-admin-phone">Phone</label>
                    <input id="studio-admin-phone" :value="identity.admin?.phone ?? ''" type="tel" maxlength="40"
                        class="dashboard-input" :disabled="!!identity.user_id" @input="setAdmin('phone', $event)" />
                </div>
            </div>
        </div>

        <div class="studio-field">
            <label for="studio-slug">Website address</label>
            <input id="studio-slug" v-model.trim="identity.slug" type="text" maxlength="63" autocapitalize="off"
                spellcheck="false" class="dashboard-input" />
            <p v-if="slugCheck.state === 'checking'" class="studio-hint">Checking…</p>
            <p v-else-if="slugCheck.state === 'available'" class="studio-hint text-success">
                {{ slugCheck.host }} is free.
            </p>
            <p v-else-if="slugCheck.state === 'taken'" class="studio-error">
                {{ slugCheck.host }} already belongs to organisation #{{ slugCheck.takenBy }}.
            </p>
            <p v-else-if="slugCheck.state === 'invalid'" class="studio-error">{{ slugCheck.message }}</p>
            <p v-else class="studio-hint">Letters, digits and hyphens. It becomes the organisation's Manara address.</p>
        </div>

        <div class="studio-field">
            <label for="studio-description">Description <span class="text-muted fw-normal">(client's words, published)</span></label>
            <textarea id="studio-description" v-model="identity.description" rows="2" maxlength="300" class="dashboard-input"></textarea>
            <p class="studio-hint">{{ (identity.description ?? '').length }} / 300</p>
        </div>

        <div class="studio-field">
            <label for="studio-vibe">Vibe <span class="text-muted fw-normal">(internal, never published)</span></label>
            <textarea id="studio-vibe" v-model="identity.vibe" rows="3" maxlength="2000" class="dashboard-input"></textarea>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's Identity panel: the organisation type and what it changes, who
 * the organisation is, its admin, and its Manara address (docs/manara-studio-w1.md S5).
 *
 * Everything binds straight into the store's `identity` section; the store's
 * autosave sends the section whole. The type's label and terminology come from
 * /onboarding/options (config/verticals.php), never from a copy here.
 *
 * The address is checked live, as it is typed, through /studio/domains/check
 * (S3): the check normalises and validates the label exactly as provisioning
 * will, so the answer here is the answer Step 3 will get.
 */
import { getMessageFromObj } from '@/assets/ts/swalMethods';
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import ApiService from '@/core/services/ApiService';
import { asksPrayer } from '@/core/studio/foundationGate';
import { BackendResponseData } from '@/core/types/config/AxiosCustom';
import { MasjidAdmin } from '@/core/types/data/Admin';
import { StudioDomainCheck, StudioSlugCheck as SlugCheckState } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { useUsersStore } from '@/stores/super/usersStore';
import { AxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const emit = defineEmits<{ (event: 'slug-check', check: SlugCheckState): void }>();

/** Quiet time after the last keystroke before the address is checked. */
const SLUG_CHECK_DEBOUNCE_MS = 500;

const store = useStudioDraftStore();
const usersStore = useUsersStore();

const identity = computed(() => store.answers.identity);
const verticals = computed(() => store.options?.verticals ?? []);
const selectedVertical = computed(() => verticals.value.find((v) => v.org_type === identity.value.org_type) ?? null);

/** The pack as rows, keys humanised the way the wizard and PHP's Masjid::term() do. */
const terminologyRows = computed(() =>
    Object.entries(selectedVertical.value?.terminology ?? {}).map(([key, value]) => ({
        key: key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()),
        value: String(value),
    }))
);

// ---- Place ----
const cities = ref<{ id: number; name: string }[]>([]);
const admins = ref<MasjidAdmin[]>([]);

/** IANA names, as PHP's `timezone` rule accepts; a stored one the browser lacks is still offered. */
const timezones = computed(() => {
    let zones: string[] = [];
    try {
        const supported = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf;
        zones = supported ? supported('timeZone') : [];
    } catch {
        zones = [];
    }
    const current = identity.value.timezone;
    return current && !zones.includes(current) ? [current, ...zones] : zones;
});

async function loadCities(countryId: number | null | undefined) {
    cities.value = countryId ? await store.fetchCities(countryId) : [];
}

/** Only an operator's change clears the city; loading a draft never does. */
function onCountryChange() {
    identity.value.city_id = null;
    void loadCities(identity.value.country_id);
}

function onExistingAdmin() {
    if (identity.value.user_id) identity.value.admin = null;
}

function setAdmin(key: 'name' | 'email' | 'phone', event: Event) {
    const value = (event.target as HTMLInputElement).value;
    identity.value.admin = { ...(identity.value.admin ?? {}), [key]: value };
}

// ---- Geocode (the wizard's "Find coordinates", same endpoint) ----
const geocoding = ref(false);
const geocodeMessage = ref('');
const geocodeFailed = ref(false);

async function geocode() {
    const address = (identity.value.address ?? '').trim();
    if (!address) return;
    geocoding.value = true;
    geocodeMessage.value = '';
    geocodeFailed.value = false;

    await ApiService.post('/api/admin/onboarding/intake/geocode', { address })
        .then((res) => {
            const data = res.data?.data;
            if (res.data?.status === 'success' && data) {
                identity.value.latitude = Number(data.latitude);
                identity.value.longitude = Number(data.longitude);
                geocodeMessage.value = data.formatted_address ? `Matched: ${data.formatted_address}` : 'Coordinates filled from the address.';
            } else {
                geocodeFailed.value = true;
                geocodeMessage.value = res.data?.message || 'No coordinates found for that address. Enter them by hand.';
            }
        })
        .catch((error: AxiosError<BackendResponseData>) => {
            geocodeFailed.value = true;
            geocodeMessage.value = getMessageFromObj(error) || 'Finding coordinates failed. Enter them by hand.';
        })
        .finally(() => { geocoding.value = false; });
}

// ---- Live address check ----
const slugCheck = reactive<SlugCheckState>({ state: 'none', host: null, takenBy: null, message: null });
let slugTimer: ReturnType<typeof setTimeout> | null = null;
let slugSeq = 0;

function publish() {
    emit('slug-check', { ...slugCheck });
}

async function checkSlug(label: string) {
    const seq = ++slugSeq;
    slugCheck.state = 'checking';
    publish();

    const outcome = await store.checkDomain({ kind: 'managed_subdomain', label });
    if (seq !== slugSeq) return;

    if (outcome.ok) {
        const data: StudioDomainCheck = outcome.data;
        slugCheck.host = data.host;
        slugCheck.takenBy = data.taken_by_masjid_id;
        slugCheck.state = data.available ? 'available' : 'taken';
        slugCheck.message = null;
    } else {
        slugCheck.host = null;
        slugCheck.takenBy = null;
        slugCheck.state = 'invalid';
        slugCheck.message = outcome.message;
    }
    publish();
}

watch(() => identity.value.slug, (slug) => {
    if (slugTimer) clearTimeout(slugTimer);
    const label = (slug ?? '').trim();
    if (!label) {
        slugSeq++;
        Object.assign(slugCheck, { state: 'none', host: null, takenBy: null, message: null });
        publish();
        return;
    }
    slugTimer = setTimeout(() => { void checkSlug(label); }, SLUG_CHECK_DEBOUNCE_MS);
}, { immediate: true });

onMounted(async () => {
    await Promise.all([
        loadCities(identity.value.country_id),
        usersStore.fetchMasjidAdmins(admins),
    ]);
});

onBeforeUnmount(() => {
    if (slugTimer) clearTimeout(slugTimer);
    slugSeq++;
});
</script>

<style scoped>
.vertical-effects {
    display: grid;
    gap: 1rem;
    grid-template-columns: 1fr;
    border: 1px solid var(--input-border, #eee);
    border-radius: .5rem;
    padding: .75rem;
}

@media (min-width: 768px) {
    .vertical-effects {
        grid-template-columns: 3fr 2fr;
    }
}

.effect-title {
    font-weight: 600;
    font-size: .9rem;
}

.terminology-list {
    display: flex;
    flex-direction: column;
    gap: .2rem;
    margin-top: .25rem;
}

.terminology-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    font-size: .85rem;
}

.terminology-row dt {
    color: #777;
    font-weight: 500;
}

.terminology-row dd {
    margin: 0;
    font-weight: 600;
}

.studio-subsection {
    border-top: 1px solid var(--input-border, #eee);
    padding-top: 1rem;
    display: flex;
    flex-direction: column;
    gap: .75rem;
}
</style>
