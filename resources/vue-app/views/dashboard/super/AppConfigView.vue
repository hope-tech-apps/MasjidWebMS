<template>
    <div class="card border-0 py-4 px-3 w-100">
        <div class="card-header bg-white border-0">
            <div class="card-title fs-4 fw-semibold">App Version Control</div>
            <div class="text-muted small mt-1">
                Emergency lever for the mobile apps. Turn on <strong>Force Update</strong> and set the minimum
                build to wall off old installs on their next launch — no app release needed. Turn on
                <strong>Maintenance Mode</strong> to show a "we'll be right back" screen in both apps.
                Each masjid has its own per-platform config.
            </div>
        </div>

        <div class="card-body d-flex flex-column gap-4">
            <div class="w-100 w-md-50">
                <label class="form-label small fw-semibold">Select Masjid</label>
                <select v-model="selectedMasjidId" class="dashboard-input" :class="{ 'placeholder': !selectedMasjidId }">
                    <option disabled hidden :value="undefined">select masjid</option>
                    <option v-for="masjid in masjids" :key="masjid.id" :value="masjid.id" :label="masjid.name">
                        {{ masjid.name }}
                    </option>
                </select>
            </div>

            <template v-if="selectedMasjidId">
                <div v-for="platform in platforms" :key="platform.platform" class="border rounded p-3">
                    <h5 class="text-capitalize mb-3">{{ platform.platform }}</h5>

                    <div class="d-flex flex-column flex-md-row gap-3 mb-3">
                        <div class="w-100 w-md-50">
                            <label class="form-label small fw-semibold">Minimum Version</label>
                            <input v-model="platform.minimum_version" type="text" class="dashboard-input" placeholder="2.5.0" />
                        </div>
                        <div class="w-100 w-md-50">
                            <label class="form-label small fw-semibold">Minimum Build</label>
                            <input v-model.number="platform.minimum_build" type="number" min="0" class="dashboard-input" />
                        </div>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" :id="`force-${platform.platform}`" v-model="platform.force_update" />
                        <label class="form-check-label ms-2 fw-semibold text-danger" :for="`force-${platform.platform}`">
                            Force Update (blocks installs below minimum)
                        </label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Update Message</label>
                        <textarea v-model="platform.update_message" class="dashboard-input" rows="2"></textarea>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" :id="`maint-${platform.platform}`" v-model="platform.maintenance_mode" />
                        <label class="form-check-label ms-2 fw-semibold text-warning" :for="`maint-${platform.platform}`">
                            Maintenance Mode (blocks the whole app)
                        </label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Maintenance Message</label>
                        <textarea v-model="platform.maintenance_message" class="dashboard-input" rows="2"></textarea>
                    </div>

                    <div class="d-flex flex-column flex-md-row gap-3 mb-3">
                        <div class="w-100 w-md-50">
                            <label class="form-label small fw-semibold">Latest Version (soft prompt)</label>
                            <input v-model="platform.latest_version" type="text" class="dashboard-input" placeholder="2.6.0" />
                        </div>
                        <div class="w-100 w-md-50">
                            <label class="form-label small fw-semibold">Store URL</label>
                            <input v-model="platform.store_url" type="url" class="dashboard-input" />
                        </div>
                    </div>

                    <LoadingButton :is-loading="savingPlatform === platform.platform" classes="btn btn-success"
                        @click="onSave(platform)">
                        Save {{ platform.platform }}
                    </LoadingButton>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
import LoadingButton from '@/components/form/LoadingButton.vue'
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2'
import { useAppConfigStore, AppVersionSetting } from '@/stores/super/appConfigStore'
import { useMasjidsStore } from '@/stores/super/masjidsStore'
import { computed, onBeforeMount, ref, watch } from 'vue'

const store = useAppConfigStore()
const masjidsStore = useMasjidsStore()

const savingPlatform = ref<string | null>(null)
const selectedMasjidId = ref<number | undefined>(undefined)
const platforms = ref<AppVersionSetting[]>([])

const masjids = computed(() => masjidsStore.masjids)

/** A blank editable row so a masjid with no saved config can still be created. */
function defaultRow(platform: string): AppVersionSetting {
    return {
        id: 0,
        platform,
        minimum_version: '0.0.0',
        minimum_build: 0,
        force_update: false,
        update_message: '',
        latest_version: '',
        store_url: '',
        maintenance_mode: false,
        maintenance_message: '',
    }
}

/** Merge fetched rows over an ios/android scaffold so both are always editable. */
function buildPlatforms() {
    platforms.value = ['ios', 'android'].map((name) => {
        const existing = store.settings.find((s) => s.platform === name)
        return existing ? { ...existing } : defaultRow(name)
    })
}

onBeforeMount(async () => {
    await masjidsStore.fetchMasjidsList()
})

watch(selectedMasjidId, async (masjidId) => {
    if (!masjidId) return
    await store.fetchAll(masjidId)
    buildPlatforms()
})

async function onSave(platform: AppVersionSetting) {
    if (!selectedMasjidId.value) return

    // Confirm when arming a blocking control — this affects every user.
    if (platform.force_update || platform.maintenance_mode) {
        const r = await QSwal.fire(
            'Confirm',
            `This will ${platform.maintenance_mode ? 'put the app into maintenance mode' : 'force users below build ' + platform.minimum_build + ' to update'} for ${platform.platform}. Continue?`,
            'warning'
        )
        if (!r.isConfirmed) return
    }

    savingPlatform.value = platform.platform
    try {
        await store.save(selectedMasjidId.value, platform.platform, {
            minimum_version: platform.minimum_version,
            minimum_build: platform.minimum_build,
            force_update: platform.force_update,
            update_message: platform.update_message ?? '',
            latest_version: platform.latest_version ?? '',
            store_url: platform.store_url ?? '',
            maintenance_mode: platform.maintenance_mode,
            maintenance_message: platform.maintenance_message ?? '',
        })
        await MSwal.fire('Saved', `${platform.platform} config updated. Takes effect on next app launch.`, 'success')
    } catch (e) {
        await MSwal.fire('Error', 'Failed to save. Try again.', 'error')
    } finally {
        savingPlatform.value = null
    }
}
</script>
