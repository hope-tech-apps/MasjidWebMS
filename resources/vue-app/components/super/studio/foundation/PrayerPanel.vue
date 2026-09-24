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
                <span class="studio-label">Iqama, minutes after adhan</span>
                <div class="d-flex flex-wrap gap-3">
                    <div v-for="salah in SALAH_KEYS" :key="salah" class="studio-field iqama-offset">
                        <label :for="`studio-iqama-${salah}`" class="text-capitalize">{{ salah }}</label>
                        <input :id="`studio-iqama-${salah}`" :value="prayer.iqama?.[salah] ?? ''" type="number" min="0" max="180"
                            step="1" class="dashboard-input" @input="setOffset(salah, $event)" />
                    </div>
                </div>
            </div>

            <div class="studio-field jumuah">
                <label for="studio-jumuah">Jumu'ah iqama</label>
                <input id="studio-jumuah" v-model="prayer.jumaa_iqama" type="time" class="dashboard-input" />
            </div>
        </fieldset>

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
                    <tr v-if="prayer.jumaa_iqama && prayer.iqama_given !== false">
                        <th scope="row" class="fw-normal">Jumu'ah</th>
                        <td></td>
                        <td>{{ prayer.jumaa_iqama }}</td>
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
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { mockPrayerTimes, SALAH_KEYS, SalahKey } from '@/core/studio/mockPrayerTimes';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed } from 'vue';

/** The one iqama type Studio collects (app/Enums/IqamaType.php MINUTES_AFTER_ADHAN). */
const IQAMA_MINUTES_AFTER_ADHAN = 'minutes_after_adhan';

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
</script>

<style scoped>
fieldset {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
}

.iqama-offset {
    width: 6rem;
}

.jumuah {
    max-width: 16rem;
}

.prayer-table {
    max-width: 22rem;
    font-variant-numeric: tabular-nums;
}
</style>
