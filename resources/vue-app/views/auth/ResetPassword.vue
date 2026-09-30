<template>
    <AuthShell>
        <Form v-slot="{ errors }" @submit="submit()" :validation-schema="validationSchema" class="auth-card" novalidate>
            <div class="auth-card__head">
                <span class="auth-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <template v-if="done">
                            <circle cx="12" cy="12" r="9" /><path d="m8.5 12 2.5 2.5 4.5-5" />
                        </template>
                        <template v-else>
                            <rect x="5" y="11" width="14" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                        </template>
                    </svg>
                </span>
                <h1 class="auth-title">{{ done ? 'All set' : 'Choose a password' }}</h1>
                <p v-if="!done && hasLink" class="auth-sub">
                    Setting the password for <strong>{{ email }}</strong>.
                    Nobody else knows it — not even us.
                </p>
            </div>

            <div v-if="done" class="auth-alert auth-alert--ok" role="status">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" /><path d="m8.5 12 2.5 2.5 4.5-5" />
                </svg>
                <span>Your password is set. You can sign in with it now.</span>
            </div>

            <div v-else-if="!hasLink" class="auth-alert auth-alert--warn" role="alert">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 4 2.5 20h19z" /><path d="M12 10v4" /><path d="M12 17h.01" />
                </svg>
                <span>
                    This link is incomplete. Open the link from your email exactly as it was sent,
                    or ask for a new one.
                </span>
            </div>

            <template v-else>
                <div v-if="error" class="auth-alert auth-alert--error" role="alert">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" /><path d="M12 7.5v5" /><path d="M12 16h.01" />
                    </svg>
                    <span>{{ error }}</span>
                </div>

                <div class="auth-fields">
                    <div class="auth-field">
                        <label class="auth-label" for="reset_password">New password</label>
                        <div class="auth-control" :class="{ 'auth-control--invalid': errors.password }">
                            <svg class="auth-control__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                                stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <rect x="5" y="11" width="14" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                            </svg>
                            <PasswordInput name="password" v-model="password" input-class="auth-input"
                                input-id="reset_password" autocomplete="new-password" placeholder="New password"
                                :invalid="!!errors.password"
                                :described-by="errors.password ? 'reset_password_error' : 'reset_password_rule'" />
                        </div>
                        <p v-if="errors.password" id="reset_password_error" class="auth-error-text">{{ errors.password }}</p>
                        <p v-else id="reset_password_rule" class="auth-hint">
                            At least 10 characters, with letters and numbers.
                        </p>
                    </div>

                    <div class="auth-field">
                        <label class="auth-label" for="reset_password_confirmation">Confirm password</label>
                        <div class="auth-control" :class="{ 'auth-control--invalid': errors.password_confirmation }">
                            <svg class="auth-control__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                                stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <rect x="5" y="11" width="14" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                            </svg>
                            <PasswordInput name="password_confirmation" v-model="passwordConfirmation"
                                input-class="auth-input" input-id="reset_password_confirmation"
                                autocomplete="new-password" placeholder="Type it again"
                                :invalid="!!errors.password_confirmation"
                                :described-by="errors.password_confirmation ? 'reset_confirm_error' : undefined" />
                        </div>
                        <p v-if="errors.password_confirmation" id="reset_confirm_error" class="auth-error-text">
                            {{ errors.password_confirmation }}
                        </p>
                    </div>
                </div>
            </template>

            <div class="auth-actions">
                <button v-if="hasLink && !done" type="submit" class="auth-button" :disabled="loading"
                    :aria-busy="loading">
                    <template v-if="!loading">
                        <span>Set password</span>
                        <svg class="auth-button__arrow" viewBox="0 0 24 24" width="18" height="18" fill="none"
                            stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M5 12h14" /><path d="m13 6 6 6-6 6" />
                        </svg>
                    </template>
                    <template v-else>
                        <span class="auth-spinner" aria-hidden="true"></span>
                        <span>Saving…</span>
                    </template>
                </button>
                <router-link to="/auth/sign-in"
                    :class="done ? 'auth-button auth-button--link' : 'auth-link auth-link--center'">
                    {{ done ? 'Go to sign in' : 'Back to sign in' }}
                </router-link>
            </div>
        </Form>
    </AuthShell>
</template>

<script setup lang="ts">
import AuthShell from '@/components/auth/AuthShell.vue';
import PasswordInput from '@/components/form/PasswordInput.vue';
import ApiService from '@/core/services/ApiService';
import { Form } from 'vee-validate';
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { object, ref as yupRef, string } from 'yup';

const route = useRoute();

/**
 * Read the credential from the URL FRAGMENT, then remove it from the address bar.
 *
 * AccountAccessService puts the token and email after a `#` precisely so they
 * never reach a server: a fragment is not in the request line (so nginx cannot
 * log it — it was being logged), not in `Referer` (so the next site cannot read
 * it), and not visible to any proxy or CDN in between.
 *
 * The QUERY fallback is deliberate and TEMPORARY. Links already sitting in
 * inboxes were minted with `?token=…`, and breaking them would lock staff out
 * of an invite they were just sent. Those tokens expire in 60 minutes
 * (config/auth.php), so this branch stops mattering an hour after deploy and
 * can be deleted on the next pass.
 */
const fromHash = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));

const token = ref(String(fromHash.get('token') ?? route.query.token ?? ''));
const email = ref(String(fromHash.get('email') ?? route.query.email ?? ''));
// Which kind of link: 'invite' for a new account's first password (7 days, its
// own token table), absent for Forgot password. Sent back so the server looks in
// the right table; a link without it is a reset, as every link before this was.
const kind = ref(String(fromHash.get('kind') ?? route.query.kind ?? ''));
const hasLink = computed(() => token.value !== '' && email.value !== '');

// Scrub it. The values are captured above, so the address bar does not need to
// keep holding a working credential where a screenshot, a shoulder, or a
// copied URL would pick it up. replaceState rather than a router push: this
// must not add a history entry, and the component must not re-resolve.
if (window.location.hash) {
    window.history.replaceState(null, '', window.location.pathname);
}

const password = ref('');
const passwordConfirmation = ref('');
const loading = ref(false);
const done = ref(false);
const error = ref('');

const validationSchema = object().shape({
    password: string().min(10, 'Use at least 10 characters.').required('Choose a password.'),
    password_confirmation: string().oneOf([yupRef('password')], 'Passwords must match').required('Type the password again.'),
});

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        await ApiService.post('/api/admin/reset-password' as any, {
            token: token.value,
            email: email.value,
            ...(kind.value === 'invite' ? { kind: 'invite' } : {}),
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        done.value = true;
    } catch (e: any) {
        const data = e?.response?.data;
        error.value = data?.message
            || data?.data?.password?.[0]
            || 'That link could not be used. Use "Forgot password" on the sign-in page to get a new one.';
    } finally {
        loading.value = false;
    }
};
</script>
