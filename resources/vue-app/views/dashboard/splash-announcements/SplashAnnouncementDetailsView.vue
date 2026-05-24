<template>
    <div class="card border-0 py-4 px-3 w-100" v-if="splash">
        <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between">
            <div>
                <h4 class="mb-1">{{ splash.title }}</h4>
                <div class="text-muted small">
                    {{ new Date(splash.starts_at).toLocaleString() }} → {{ new Date(splash.ends_at).toLocaleString() }}
                </div>
            </div>
            <button class="btn btn-outline-primary"
                @click="router.push(`/masjid/splash-announcements/${splash.id}/edit`)">Edit</button>
        </div>
        <div class="card-body">
            <img v-if="splash.image?.original_url" :src="splash.image.original_url" :alt="splash.title"
                class="mb-3 rounded" style="max-width: 100%; max-height: 320px; object-fit: contain;">
            <!-- Body goes through SafeHtml (dompurify) — admin-authored content. -->
            <SafeHtml :html="splash.body ?? ''" tag="div" class="mb-3" />
            <a v-if="splash.cta_url && splash.cta_label" :href="splash.cta_url" target="_blank"
                rel="noopener noreferrer" class="btn btn-primary">
                {{ splash.cta_label }}
            </a>
            <div class="mt-4 small text-muted">
                <div><strong>Priority:</strong> {{ splash.priority }}</div>
                <div><strong>Active:</strong> {{ splash.is_active ? 'Yes' : 'No' }}</div>
                <div v-if="splash.onesignal_iam_id"><strong>OneSignal IAM:</strong> {{ splash.onesignal_iam_id }}</div>
            </div>
        </div>
    </div>
    <div v-else class="text-center py-5 text-muted">Loading...</div>
</template>

<script setup lang="ts">
import SafeHtml from '@/components/common/SafeHtml.vue'
import { SplashAnnouncement } from '@/core/types/data/masjid-related/SplashAnnouncement'
import { useSplashAnnouncementsStore } from '@/stores/masjid/splashAnnouncementsStore'
import { onBeforeMount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const store = useSplashAnnouncementsStore()
const splash = ref<SplashAnnouncement>()

onBeforeMount(async () => {
    const id = route.params.splash_id as string
    await store.fetchSplash(id, splash)
})
</script>
