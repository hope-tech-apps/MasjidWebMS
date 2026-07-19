<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-3 w-100 vh-100 px-3 text-center">
        <div class="donation-badge donation-badge--muted d-flex align-items-center justify-content-center rounded-circle">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>

        <h1 class="fs-2 fw-bold mb-0">Donation cancelled</h1>
        <p class="text-muted mb-3 donation-copy">
            No payment was taken. You can try again whenever you're ready — may Allah make it easy for you.
        </p>

        <button type="button" @click.prevent="goBack" class="btn btn-outline-secondary px-4">Return</button>
    </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';

const router = useRouter();

function goBack() {
    router.push('/');
}
</script>

<style scoped>
.donation-badge {
    width: 88px;
    height: 88px;
}

.donation-badge svg {
    width: 42px;
    height: 42px;
}

.donation-badge--muted {
    background: #f1f3f5;
    color: #6c757d;
}

.donation-copy {
    max-width: 30rem;
}
</style>
