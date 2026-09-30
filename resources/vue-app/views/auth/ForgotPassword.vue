<template>
    <AuthShell>
        <Form v-slot="{ errors }" @submit="submit()" :validation-schema="validationSchema" class="auth-card" novalidate>
            <div class="auth-card__head">
                <span class="auth-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <template v-if="sent">
                            <rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 7 8 6 8-6" />
                        </template>
                        <template v-else>
                            <circle cx="8" cy="15" r="4" /><path d="m11 12 8-8" /><path d="m16 7 3 3" />
                        </template>
                    </svg>
                </span>
                <h1 class="auth-title">{{ sent ? 'Check your email' : 'Reset your password' }}</h1>
                <p v-if="!sent" class="auth-sub">
                    Enter the email address for your account and we will send you a link to set a new password.
                </p>
            </div>

            <!-- Deliberately the same message whether or not the address exists:
                 the API does not disclose who has an account, and neither does this. -->
            <div v-if="sent" class="auth-alert auth-alert--ok" role="status">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" /><path d="m8.5 12 2.5 2.5 4.5-5" />
                </svg>
                <span>{{ message }}</span>
            </div>

            <div v-else class="auth-field">
                <label class="auth-label" for="forgot_email">Email</label>
                <div class="auth-control">
                    <svg class="auth-control__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                        stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 7 8 6 8-6" />
                    </svg>
                    <Field id="forgot_email" type="email" name="email" v-model="email" class="auth-input"
                        placeholder="you@yourorganization.org" autocomplete="username" inputmode="email"
                        autocapitalize="none" spellcheck="false" autofocus :validate-on-model-update="false"
                        :aria-invalid="errors.email ? 'true' : 'false'"
                        :aria-describedby="errors.email ? 'forgot_email_error' : undefined" />
                </div>
                <p v-if="errors.email" id="forgot_email_error" class="auth-error-text">{{ errors.email }}</p>
            </div>

            <div class="auth-actions">
                <button v-if="!sent" type="submit" class="auth-button" :disabled="loading" :aria-busy="loading">
                    <template v-if="!loading">
                        <span>Send reset link</span>
                        <svg class="auth-button__arrow" viewBox="0 0 24 24" width="18" height="18" fill="none"
                            stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M5 12h14" /><path d="m13 6 6 6-6 6" />
                        </svg>
                    </template>
                    <template v-else>
                        <span class="auth-spinner" aria-hidden="true"></span>
                        <span>Sending…</span>
                    </template>
                </button>
                <router-link to="/auth/sign-in" class="auth-link auth-link--center">Back to sign in</router-link>
            </div>
        </Form>
    </AuthShell>
</template>

<script setup lang="ts">
import AuthShell from '@/components/auth/AuthShell.vue';
import ApiService from '@/core/services/ApiService';
import { Form, Field } from 'vee-validate';
import { ref } from 'vue';
import { object, string } from 'yup';

const validationSchema = object().shape({
    email: string().email('Enter a valid email address.').required('Enter your email address.'),
});

const email = ref('');
const loading = ref(false);
const sent = ref(false);
const message = ref('');

const submit = async () => {
    loading.value = true;
    try {
        const res = await ApiService.post('/api/admin/forgot-password' as any, { email: email.value });
        message.value = res.data?.message || 'If that address belongs to an account, a reset link is on its way.';
    } catch (e: any) {
        // Even a failure must not reveal whether the address exists.
        message.value = 'If that address belongs to an account, a reset link is on its way.';
    } finally {
        sent.value = true;
        loading.value = false;
    }
};
</script>
