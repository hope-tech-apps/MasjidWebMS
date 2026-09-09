<template>
    <PageDataContainer title="Broadcasts" :paginationOptions="paginationOptions"
        :buttonProps="{ title: 'Compose', type: 'button', class: 'btn btn-success' }"
        @headerButtonClick="() => router.push('/masjid/broadcasts/compose')"
        @pageChange="pageChange">
        <div class="container w-100">

            <div v-if="broadcasts.length === 0" class="text-center text-muted py-5">
                Nothing sent yet. Compose one to reach the announcements feed, phones, the lobby screen,
                email and text — all from a single message.
            </div>

            <div v-for="b in broadcasts" :key="b.id" class="card border-0 shadow-sm mb-3">
                <div class="card-body">

                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div class="flex-grow-1">
                            <h5 class="card-title mb-1">{{ b.title }}</h5>
                            <div class="text-muted small">{{ audienceLabel(b) }} · {{ sentWhen(b) }}</div>
                        </div>
                        <span :class="['badge', statusBadge(b.status).klass]">{{ statusBadge(b.status).label }}</span>
                    </div>

                    <p class="card-text text-muted small mt-2 mb-3">{{ truncate(b.body) }}</p>

                    <!-- Per-channel outcome. This is the part that matters: the fan-out is not
                         transactional, so one line per channel is the only honest summary. -->
                    <div class="d-flex flex-wrap gap-2">
                        <span v-for="d in (b.deliveries ?? [])" :key="d.id"
                            :class="['badge', 'fw-normal', deliveryBadge(d.status).klass]"
                            :title="d.note ?? d.error ?? ''">
                            {{ channelLabel(d.channel) }}: {{ deliveryBadge(d.status).label }}
                            <template v-if="d.target_count !== null"> ({{ d.target_count }})</template>
                        </span>
                    </div>

                    <div v-if="failureNotes(b).length" class="mt-2">
                        <div v-for="(n, i) in failureNotes(b)" :key="i" class="text-danger small">{{ n }}</div>
                    </div>

                </div>
            </div>

        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
import PageDataContainer from '@/components/PageDataContainer.vue'
import { Broadcast, BroadcastChannel, BroadcastDeliveryStatus, BroadcastStatus } from '@/core/types/data/masjid-related/Broadcast'
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination'
import { useBroadcastsStore } from '@/stores/masjid/broadcastsStore'
import { computed, onBeforeMount, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const store = useBroadcastsStore()

const broadcasts = computed(() => store.broadcastsPaginated?.data ?? [])

const paginationOptions = ref<PaginationOptions>({
    itemsTotal: 0,
    currentPage: 0,
    perPage: 15,
})

onBeforeMount(async () => {
    await loadPage(1)
})

async function loadPage(page: number) {
    await store.fetchBroadcastsPaginated(page)
    paginationOptions.value.itemsTotal = store.broadcastsPaginated?.total ?? 0
    paginationOptions.value.currentPage = store.broadcastsPaginated?.current_page ?? 0
    paginationOptions.value.perPage = store.broadcastsPaginated?.per_page ?? 15
}

const pageChange = async (data: PageChangeData) => {
    await loadPage(data.toPage)
}

const CHANNEL_LABELS: Record<BroadcastChannel, string> = {
    announcement: 'Feed',
    push: 'Push',
    signage: 'Screen',
    email: 'Email',
    sms: 'Text',
}

function channelLabel(c: BroadcastChannel): string {
    return CHANNEL_LABELS[c] ?? c
}

/**
 * `partial` is deliberately not styled as an error. Some channels went and some
 * did not; the message really did reach people, and telling an admin it failed
 * invites them to send it a second time.
 */
function statusBadge(s: BroadcastStatus): { label: string; klass: string } {
    switch (s) {
        case 'sent': return { label: 'Sent', klass: 'bg-success' }
        case 'partial': return { label: 'Partly sent', klass: 'bg-warning text-dark' }
        case 'scheduled': return { label: 'Scheduled', klass: 'bg-info text-dark' }
        case 'failed': return { label: 'Failed', klass: 'bg-danger' }
        default: return { label: 'Pending', klass: 'bg-secondary' }
    }
}

/** `skipped` is a fact worth showing — nobody to send to is not a failure. */
function deliveryBadge(s: BroadcastDeliveryStatus): { label: string; klass: string } {
    switch (s) {
        case 'sent': return { label: 'sent', klass: 'bg-success-subtle text-success' }
        case 'failed': return { label: 'failed', klass: 'bg-danger-subtle text-danger' }
        case 'skipped': return { label: 'nobody to send to', klass: 'bg-light text-muted' }
        default: return { label: 'pending', klass: 'bg-secondary-subtle text-secondary' }
    }
}

function audienceLabel(b: Broadcast): string {
    if (b.audience === 'service') return 'People interested in one service'
    if (b.audience === 'contacts') {
        const n = b.audience_contact_ids?.length ?? 0
        return `${n} selected contact${n === 1 ? '' : 's'}`
    }
    return 'Everyone'
}

function sentWhen(b: Broadcast): string {
    const iso = b.scheduled_at ?? b.created_at
    const when = new Date(iso).toLocaleString()
    return b.status === 'scheduled' ? `scheduled for ${when}` : when
}

function failureNotes(b: Broadcast): string[] {
    return (b.deliveries ?? [])
        .filter(d => d.status === 'failed' && d.error)
        .map(d => `${channelLabel(d.channel)}: ${d.error}`)
}

function truncate(s: string, n = 180): string {
    return s.length > n ? `${s.slice(0, n)}…` : s
}
</script>
