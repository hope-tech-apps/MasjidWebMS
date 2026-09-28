<template>
    <StudioPanel title="Prayer" note="How the times are calculated, and the iqama times the client gave.">
        <div class="studio-field">
            <label for="studio-method">Calculation method</label>
            <select id="studio-method" v-model="prayer.method" class="dashboard-input">
                <option :value="null">Choose a method</option>
                <option v-for="method in prayerOptions.methods" :key="method.value" :value="method.value">{{ method.label }}</option>
            </select>
        </div>

        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-madhab">Madhab (Asr)</label>
                <select id="studio-madhab" v-model="prayer.madhab" class="dashboard-input">
                    <option :value="null">Choose a madhab</option>
                    <option v-for="madhab in prayerOptions.madhabs" :key="madhab.value" :value="madhab.value">{{ madhab.label }}</option>
                </select>
            </div>
            <div class="studio-field w-100">
                <label for="studio-high-latitude">High latitude rule</label>
                <select id="studio-high-latitude" v-model="prayer.high_latitude_rule" class="dashboard-input">
                    <option :value="null">Choose a rule</option>
                    <option v-for="rule in prayerOptions.high_latitude_rules" :key="rule.value" :value="rule.value">{{ rule.label }}</option>
                </select>
            </div>
        </div>

        <div class="form-check">
            <input id="studio-iqama-not-given" class="form-check-input" type="checkbox" :checked="prayer.iqama_given === false"
                @change="setIqamaGiven(($event.target as HTMLInputElement).checked)" />
            <label class="form-check-label" for="studio-iqama-not-given">Client has not given iqama times</label>
        </div>

        <fieldset :disabled="prayer.iqama_given === false" class="d-flex flex-column gap-3">
            <div class="studio-field">
                <span class="studio-label">Iqama</span>
                <div class="iqama-grid">
                    <div v-for="salah in SALAH_KEYS" :key="salah" class="iqama-prayer">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span class="fw-semibold text-capitalize">{{ salah }}</span>
                            <div class="d-flex gap-1" role="radiogroup" :aria-label="`How the client gives the ${salah} iqama`">
                                <label v-for="mode in IQAMA_MODES" :key="mode.fixed ? 'fixed' : 'minutes'" class="mode-pill"
                                    :class="{ selected: isFixed(salah) === mode.fixed, 'pill-disabled': prayer.iqama_given === false }">
                                    <input type="radio" :name="`studio-iqama-mode-${salah}`" :checked="isFixed(salah) === mode.fixed"
                                        @change="setFixedMode(salah, mode.fixed)" />
                                    {{ mode.label }}
                                </label>
                            </div>
                        </div>

                        <div v-if="isFixed(salah)" class="studio-field">
                            <label :for="`studio-iqama-fixed-${salah}`">Fixed time</label>
                            <input :id="`studio-iqama-fixed-${salah}`" :value="prayer.iqama_fixed?.[salah] ?? ''" type="time"
                                class="dashboard-input" @input="setFixedTime(salah, $event)" />
                        </div>

                        <div class="studio-field">
                            <label :for="`studio-iqama-${salah}`">
                                {{ isFixed(salah) ? 'Minutes after adhan once the fixed time ends' : 'Minutes after adhan' }}
                            </label>
                            <input :id="`studio-iqama-${salah}`" :value="prayer.iqama?.[salah] ?? ''" type="number" min="0" max="180"
                                step="1" class="dashboard-input" :placeholder="isFixed(salah) ? '0' : undefined"
                                @input="setOffset(salah, $event)" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="anyFixed" class="studio-field until">
                <label for="studio-iqama-fixed-until">Fixed times hold until <span class="req">*</span></label>
                <input id="studio-iqama-fixed-until" :value="prayer.iqama_fixed_until ?? ''" type="date" class="dashboard-input"
                    required :min="untilMin" :max="untilMax" aria-describedby="studio-iqama-fixed-until-hint" @input="setFixedUntil" />
                <p id="studio-iqama-fixed-until-hint" class="studio-hint">
                    After this date these prayers use minutes after adhan. Update them in Prayer settings before then.
                </p>
            </div>
        </fieldset>

        <div class="studio-field jumuah">
            <span class="studio-label" id="studio-jumuah-label">Jumu'ah khutbah times</span>
            <div class="d-flex flex-column gap-2" role="group" aria-labelledby="studio-jumuah-label">
                <div v-for="(time, index) in jumuahRows" :key="index" class="d-flex align-items-center gap-2">
                    <input :id="`studio-jumuah-${index}`" :value="time" type="time" class="dashboard-input"
                        :aria-label="jumuahRows.length > 1 ? `Khutbah time ${index + 1}` : `Khutbah time`"
                        @input="setJumuahTime(index, $event)" />
                    <button v-if="jumuahRows.length > 1" type="button" class="btn btn-sm btn-outline-secondary"
                        :aria-label="`Remove khutbah time ${index + 1}`" @click="removeJumuahTime(index)">
                        Remove
                    </button>
                </div>
            </div>
            <button v-if="jumuahRows.length < MAX_JUMUAH_TIMES" type="button" class="btn btn-sm btn-outline-secondary align-self-start"
                @click="addJumuahTime">
                Add a khutbah time
            </button>
            <p class="studio-hint">Earliest first. The TV board, the website and the apps show these as the Jumu'ah times.</p>
            <p v-if="jumuahDuplicate" class="studio-error">The Jumu'ah times must all be different.</p>
        </div>

        <div class="studio-field">
            <span class="studio-label">Today at these coordinates</span>
            <table v-if="today" class="table table-sm mb-0 prayer-table">
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th scope="col">Adhan</th>
                        <th scope="col">Iqama</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in today" :key="row.key">
                        <th scope="row" class="text-capitalize fw-normal">{{ row.key }}</th>
                        <td>{{ row.adhan }}</td>
                        <td>{{ row.iqama ?? '' }}</td>
                    </tr>
                    <tr v-for="(time, index) in jumuahTimes" :key="`jumuah-${index}`">
                        <th scope="row" class="fw-normal">{{ jumuahTimes.length > 1 ? `Jumu'ah ${index + 1}` : `Jumu'ah` }}</th>
                        <td>{{ time }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="studio-hint">
                The day's times appear here once the coordinates and timezone (Identity) and a method are set.
            </p>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's Prayer panel, shown for a masjid only (docs/manara-studio-w1.md S5).
 *
 * Nothing is pre-chosen. The wizard pre-filled a method and iqama offsets; a
 * Studio draft carries only what the client said, and "Client has not given
 * iqama times" (`iqama_given: false`) lets Step 3 provision with iqama hidden
 * instead of showing invented times. The day's table is computed from these
 * answers in the browser (core/studio/mockPrayerTimes.ts) so the operator can
 * check the offsets against real adhan times before the client sees them.
 *
 * Each prayer is given as minutes after adhan or as a fixed clock time
 * (`iqama_fixed`), and the two mix, as IqamaResolver resolves them. Any fixed
 * time needs the date it holds until (`iqama_fixed_until`), which the client
 * says; Studio never picks one. After it the prayer is adhan + its minutes,
 * so a fixed prayer keeps its minutes field, blank being 0. Jumu'ah is a list
 * of one to four khutbah times (`jumaa_times`), earliest first, stored as the
 * Jumu'ah athans; an older draft's lone `jumaa_iqama` is shown as its first
 * entry and replaced when the list is edited. The list sits outside the
 * "not given" tick's fieldset, since khutbah times are not iqama times, and
 * its rows are drawn in the table's Adhan column. The rules are the server's (ProvisionMasjidRequest) and Step 3 says
 * what they refuse before Provision (core/studio/provision.ts iqamaBlockers).
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { isClockTime, mockPrayerTimes, SALAH_KEYS, SalahKey } from '@/core/studio/mockPrayerTimes';
import { addDays, FIXED_IQAMA_MAX_DAYS, organisationToday } from '@/core/studio/provision';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, reactive, ref, watch } from 'vue';

/** The one iqama type Studio collects (app/Enums/IqamaType.php MINUTES_AFTER_ADHAN). */
const IQAMA_MINUTES_AFTER_ADHAN = 'minutes_after_adhan';

/** ProvisionMasjidRequest's `jumaa_times` max. */
const MAX_JUMUAH_TIMES = 4;

const IQAMA_MODES = [
    { fixed: false, label: 'Minutes' },
    { fixed: true, label: 'Fixed time' },
] as const;

const store = useStudioDraftStore();

const prayer = computed(() => store.answers.prayer);
const prayerOptions = computed(() => store.options?.prayer ?? { methods: [], madhabs: [], high_latitude_rules: [] });

const today = computed(() => mockPrayerTimes({
    latitude: store.answers.identity.latitude,
    longitude: store.answers.identity.longitude,
    timezone: store.answers.identity.timezone,
    ...prayer.value,
}));

/** Ticked is `false`; unticked says nothing either way, as before it was touched. */
function setIqamaGiven(notGiven: boolean) {
    prayer.value.iqama_given = notGiven ? false : null;
}

function setOffset(salah: SalahKey, event: Event) {
    const raw = (event.target as HTMLInputElement).value;
    const parsed = Math.round(Number(raw));
    const minutes = raw !== '' && Number.isFinite(parsed) ? parsed : null;
    prayer.value.iqama = { ...(prayer.value.iqama ?? {}), [salah]: minutes };
    if (minutes !== null) prayer.value.iqama_type = IQAMA_MINUTES_AFTER_ADHAN;
}

/**
 * "Fixed time" chosen before a time is typed. The draft holds only times, so
 * the choice lives here until one is; a draft opened fresh shows Fixed time
 * for exactly the prayers that hold one.
 */
const fixedChosen = reactive<Partial<Record<SalahKey, boolean>>>({});
watch(() => store.draft?.id, () => {
    for (const salah of SALAH_KEYS) delete fixedChosen[salah];
});

function isFixed(salah: SalahKey): boolean {
    return fixedChosen[salah] ?? isClockTime(prayer.value.iqama_fixed?.[salah]);
}

/** Back to minutes clears the fixed time, so nothing unseen is provisioned. */
function setFixedMode(salah: SalahKey, fixed: boolean) {
    fixedChosen[salah] = fixed;
    if (!fixed) {
        prayer.value.iqama_fixed = { ...(prayer.value.iqama_fixed ?? {}), [salah]: null };
    }
}

function setFixedTime(salah: SalahKey, event: Event) {
    const value = (event.target as HTMLInputElement).value;
    prayer.value.iqama_fixed = { ...(prayer.value.iqama_fixed ?? {}), [salah]: isClockTime(value) ? value : null };
}

const anyFixed = computed(() => SALAH_KEYS.some((salah) => isClockTime(prayer.value.iqama_fixed?.[salah])));

const untilMin = computed(() => organisationToday(store.answers.identity.timezone));
const untilMax = computed(() => addDays(untilMin.value, FIXED_IQAMA_MAX_DAYS));

function setFixedUntil(event: Event) {
    const value = (event.target as HTMLInputElement).value;
    prayer.value.iqama_fixed_until = value || null;
}

/** The draft's Jumu'ah times, earliest first: the list, or an older draft's single time. */
const jumuahTimes = computed<string[]>(() => {
    if (prayer.value.jumaa_times?.length) return prayer.value.jumaa_times.filter(isClockTime);
    return isClockTime(prayer.value.jumaa_iqama) ? [prayer.value.jumaa_iqama] : [];
});

/**
 * The inputs, in the order the operator is filling them in, blanks included.
 * The draft is written sorted (the first time is the Jumu'ah iqama), but the
 * inputs are never reordered under the cursor. They are rebuilt from the
 * draft only when it changes from elsewhere (another draft opened, a reload).
 */
const jumuahRows = ref<string[]>(['']);

const sortedTimes = (rows: string[]) => rows.filter(isClockTime).sort();

watch(() => jumuahTimes.value.join(','), (stored) => {
    if (sortedTimes(jumuahRows.value).join(',') !== stored) {
        jumuahRows.value = jumuahTimes.value.length ? [...jumuahTimes.value] : [''];
    }
}, { immediate: true });

const jumuahDuplicate = computed(() => new Set(jumuahTimes.value).size !== jumuahTimes.value.length);

/** Every edit writes the sorted list and retires the single-time key, so the draft holds one answer. */
function writeJumuahRows(rows: string[]) {
    jumuahRows.value = rows.length ? rows : [''];
    const times = sortedTimes(rows);
    prayer.value.jumaa_times = times.length ? times : null;
    prayer.value.jumaa_iqama = null;
}

function setJumuahTime(index: number, event: Event) {
    const value = (event.target as HTMLInputElement).value;
    const rows = [...jumuahRows.value];
    rows[index] = isClockTime(value) ? value : '';
    writeJumuahRows(rows);
}

function addJumuahTime() {
    if (jumuahRows.value.length >= MAX_JUMUAH_TIMES) return;
    writeJumuahRows([...jumuahRows.value, '']);
}

function removeJumuahTime(index: number) {
    writeJumuahRows(jumuahRows.value.filter((_, i) => i !== index));
}
</script>

<style scoped>
fieldset {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
}

.iqama-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
    gap: .75rem;
}

.iqama-prayer {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: .5rem .75rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    min-width: 0;
}

.until,
.jumuah {
    max-width: 16rem;
}

.prayer-table {
    max-width: 22rem;
    font-variant-numeric: tabular-nums;
}
</style>
