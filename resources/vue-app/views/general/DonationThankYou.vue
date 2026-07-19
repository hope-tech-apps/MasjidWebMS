<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-3 w-100 vh-100 px-3 text-center">
        <div class="donation-badge donation-badge--success d-flex align-items-center justify-content-center rounded-circle">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>

        <h1 class="fs-2 fw-bold text-success mb-0">Jazāk Allāhu Khayran</h1>
        <p class="fs-5 fw-semibold mb-1">Your donation was received.</p>
        <p class="text-muted mb-2 donation-copy">
            May Allah accept it and reward you abundantly. A receipt has been issued for your records.
        </p>
        <p v-if="reference" class="small text-muted mb-3">Reference: <code>{{ reference }}</code></p>

        <button type="button" @click.prevent="done" class="btn btn-success px-4">Done</button>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();

// A donor lands here from Stripe Checkout with ?session_id=cs_...; show a short
// reference so they can quote it if they ever contact the masjid. No API call
// is needed — this page is a graceful confirmation fallback.
const reference = computed(() => {
    const raw = route.query.session_id;
    const value = Array.isArray(raw) ? raw[0] : raw;
    return value ? `${String(value).slice(0, 22)}…` : '';
});

function done() {
    router.push('/');
}
</script>

<style scoped>
.donation-badge {
    width: 88px;
    height: 88px;
}

.donation-badge svg {
    width: 46px;
    height: 46px;
}

.donation-badge--success {
    background: #d1e7dd;
    color: #0f5132;
}

.donation-copy {
    max-width: 30rem;
}
</style>
