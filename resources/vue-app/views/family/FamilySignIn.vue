<template>
    <div class="d-flex justify-content-center">
        <div class="card border-0 shadow-sm w-100" style="max-width: 460px;">
            <div class="card-body p-4">
                <h1 class="h4 mb-1">Parent sign in</h1>
                <p class="text-muted small mb-4">
                    No password needed — we email you a six-digit code each time.
                </p>

                <!-- Step 1: the address -->
                <template v-if="step === 'email'">
                    <label class="form-label small text-muted">Your email address</label>
                    <input v-model="email" type="email" class="form-control" placeholder="you@example.com"
                           autocomplete="email" @keyup.enter="requestCode">
                    <p class="form-text">
                        Use the address the school has on file for you.
                    </p>

                    <button class="btn btn-success w-100 mt-2" :disabled="!emailLooksValid || busy" @click="requestCode">
                        <span v-if="busy" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Email me a code</span>
                    </button>
                </template>

                <!-- Step 2: the code -->
                <template v-else>
                    <div class="alert alert-info small">
                        If <strong>{{ email }}</strong> is on file, a six-digit code is on its way.
                        It expires shortly, and can only be used once.
                    </div>

                    <label class="form-label small text-muted">Six-digit code</label>
                    <input v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           class="form-control form-control-lg text-center" style="letter-spacing:.4em"
                           placeholder="000000" @keyup.enter="verify">

                    <div v-if="error" class="alert alert-danger small mt-3 mb-0">{{ error }}</div>

                    <button class="btn btn-success w-100 mt-3" :disabled="code.length < 4 || busy" @click="verify">
                        <span v-if="busy" class="spinner-border spinner-border-sm"></span>
                        <span v-else>Sign in</span>
                    </button>

                    <button class="btn btn-link w-100 mt-2 text-decoration-none" :disabled="busy" @click="restart">
                        Use a different address
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { useFamilyStore } from '@/stores/familyStore';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();

const masjidId = computed(() => String(route.params.masjidId));
const step = ref<'email' | 'code'>('email');
const email = ref('');
const code = ref('');
const busy = ref(false);
const error = ref('');

const emailLooksValid = computed(() => /\S+@\S+\.\S+/.test(email.value.trim()));

onMounted(() => {
    if (familyStore.isSignedIn && familyStore.masjidId === masjidId.value) {
        router.replace(`/family/${masjidId.value}`);
    }
});

const requestCode = async () => {
    if (!emailLooksValid.value) return;
    busy.value = true;
    error.value = '';
    try {
        await familyStore.requestCode(masjidId.value, email.value.trim());
    } catch {
        // The endpoint always accepts; a transport failure must not become a
        // hint about whether the address exists.
    } finally {
        // Always advance. The API answers 202 for every well-formed address on
        // purpose — telling the parent "no such account" here would rebuild the
        // disclosure oracle the backend refuses to be.
        step.value = 'code';
        busy.value = false;
    }
};

const verify = async () => {
    busy.value = true;
    error.value = '';
    try {
        await familyStore.verifyCode(masjidId.value, email.value.trim(), code.value.trim());
        router.replace(`/family/${masjidId.value}`);
    } catch (e: any) {
        // Every way this can fail returns the same 410, so the message is the
        // same too.
        error.value = 'That code did not work. It may have expired or already been used — ask for a new one.';
    } finally {
        busy.value = false;
    }
};

const restart = () => {
    step.value = 'email';
    code.value = '';
    error.value = '';
};
</script>
