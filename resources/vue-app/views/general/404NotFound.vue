<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-4 w-100 vh-100">
        <div class="fs-4 fw-bold text-center text-danger">
            Sorry,
            <br />
            <span class="fs-2">404 Not Found</span>
        </div>
        <button type="button" @click.prevent="goOut" class="btn btn-success">Return</button>
    </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';

// Routing
const router = useRouter()

// Functions
function goOut() {
    router.back();
}
</script>