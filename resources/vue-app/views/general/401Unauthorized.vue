<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-4 w-100 vh-100">
        <div class="fs-4 fw-bold text-center text-danger">
            Sorry,
            <br />
            <span class="fs-2">401 Unauthorized</span>
            <span v-if="authStore.user?.type === 'User'" class="fs-6">
                <hr />
                Please Call Your Service Provider for More Information
            </span>
            <hr />
        </div>
        <button type="button" @click.prevent="logout" class="btn btn-success">Return</button>
    </div>
</template>

<script setup lang="ts">
import { useAuthStore } from '@/stores/authStore';
import { useRouter } from 'vue-router';

// Routing
const router = useRouter()

// Stores
const authStore = useAuthStore()

// Functions
function logout() {

    if(authStore.user?.type === 'User') {
        authStore.removeAuth()
    } else {
        authStore.authenticate();
    }

    if(history.state && history.state?.back) {
        router.back();
    } else {
        router.push('/');
    }
    
}
</script>