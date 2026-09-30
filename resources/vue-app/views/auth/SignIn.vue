<template>
    <AuthShell>
        <!--
            The ordinary sign-in form. Untouched for anybody who has not
            turned on two-step sign-in: same fields, same submit, same
            single round trip. The code screen below only ever appears
            AFTER the server has accepted this email and password and
            asked for a second factor.
        -->
        <Form v-if="!authStore.twoFactorRequired" v-slot="{ errors }" @submit="signIn()"
            :validation-schema="validationSchema" class="auth-card" novalidate>
            <div class="auth-card__head">
                <h1 class="auth-title">Welcome back</h1>
                <p class="auth-sub">Sign in to your Manara dashboard.</p>
            </div>

            <div v-if="signInMessage" class="auth-alert auth-alert--error" role="alert">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" /><path d="M12 7.5v5" /><path d="M12 16h.01" />
                </svg>
                <span>{{ signInMessage }}</span>
            </div>

            <div class="auth-fields">
                <div class="auth-field">
                    <label class="auth-label" for="sign_in_email">Email</label>
                    <div class="auth-control">
                        <svg class="auth-control__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                            stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 7 8 6 8-6" />
                        </svg>
                        <Field id="sign_in_email" type="email" name="email" v-model="signData.email"
                            class="auth-input" placeholder="you@yourorganization.org" autocomplete="username"
                            inputmode="email" autocapitalize="none" spellcheck="false" autofocus
                            :validate-on-model-update="false"
                            :aria-invalid="errors.email ? 'true' : 'false'"
                            :aria-describedby="errors.email ? 'sign_in_email_error' : undefined" />
                    </div>
                    <p v-if="errors.email" id="sign_in_email_error" class="auth-error-text">{{ errors.email }}</p>
                </div>

                <!--
                    "Forgot password?" is drawn beside the label but comes after
                    the field in the page, so Tab goes email, password, sign in,
                    and the link is still one Tab away.
                -->
                <div class="auth-field auth-field--split">
                    <label class="auth-label" for="sign_in_password">Password</label>
                    <div class="auth-control" :class="{ 'auth-control--invalid': errors.password }"
                        @keydown="noteCapsLock" @keyup="noteCapsLock">
                        <svg class="auth-control__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
                            stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <rect x="5" y="11" width="14" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
                        </svg>
                        <PasswordInput name="password" v-model="signData.password" input-class="auth-input"
                            input-id="sign_in_password" autocomplete="current-password" placeholder="Your password"
                            :invalid="!!errors.password"
                            :described-by="errors.password ? 'sign_in_password_error' : (capsLockOn ? 'sign_in_caps' : undefined)" />
                    </div>
                    <p v-if="errors.password" id="sign_in_password_error" class="auth-error-text">{{ errors.password }}</p>
                    <p v-else-if="capsLockOn" id="sign_in_caps" class="auth-hint auth-hint--warn" aria-live="polite">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m12 4 8 8h-4v5H8v-5H4z" /><path d="M8 20h8" />
                        </svg>
                        Caps Lock is on
                    </p>
                    <router-link to="/auth/forgot-password" class="auth-link auth-link--small auth-field__aside">
                        Forgot password?
                    </router-link>
                </div>
            </div>

            <div class="auth-actions">
                <button type="submit" class="auth-button" :disabled="submitLoading" :aria-busy="submitLoading">
                    <template v-if="!submitLoading">
                        <span>Sign in</span>
                        <svg class="auth-button__arrow" viewBox="0 0 24 24" width="18" height="18" fill="none"
                            stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M5 12h14" /><path d="m13 6 6 6-6 6" />
                        </svg>
                    </template>
                    <template v-else>
                        <span class="auth-spinner" aria-hidden="true"></span>
                        <span>Signing in…</span>
                    </template>
                </button>
            </div>

            <div class="auth-divider" aria-hidden="true"></div>

            <p class="auth-family">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" /><path d="M3 20a6 6 0 0 1 12 0" />
                    <path d="M16 5.3a3 3 0 0 1 0 5.4" /><path d="M18 14.5a6 6 0 0 1 3 5.5" />
                </svg>
                <span>Signing in as a parent or guardian? Use the sign-in link your school sent you.</span>
            </p>
        </Form>

        <!--
            The second-factor challenge.

            The email and password are re-posted with the code, because
            the challenge is STATELESS — there is no half-signed-in
            session on the server, deliberately, so there is no partial
            credential for anyone to steal. They are still in `signData`
            from the first submit; the user does not retype them.
        -->
        <form v-else @submit.prevent="submitCode()" class="auth-card" novalidate>
            <div class="auth-card__head">
                <span class="auth-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6z" /><path d="M9 12l2 2 4-4" />
                    </svg>
                </span>
                <h1 class="auth-title">Two-step verification</h1>
                <p v-if="!useRecoveryCode" class="auth-sub">
                    Open your authenticator app and enter the 6&#8209;digit code for Manara.
                </p>
                <!--
                    Both sentences here are load-bearing, and both were
                    missing while the behaviour they describe already
                    existed.

                    The lock: login checks the second-factor lockout
                    BEFORE it reads a recovery code, so five wrong
                    app codes take the printed sheet away for the same
                    fifteen minutes (pinned by
                    TwoFactorTest::the_second_factor_lock_binds_the_recovery_code_path_too).
                    Offering "use a recovery code instead" without
                    saying so sends somebody to a door we already know
                    is bolted.

                    The way back: somebody with neither their phone nor
                    their sheet cannot get in from this screen at all,
                    and used to be given no idea that a way back exists.
                    It does — a platform administrator can clear the
                    second factor (TwoFactorController::resetForUser) —
                    and this line is the only place the person who needs
                    that will ever be looking.
                -->
                <p v-else class="auth-sub">
                    Enter one of the recovery codes you saved when you turned two-step
                    sign-in on. Each code works once. If you have just had several codes
                    refused, recovery codes are paused for a few minutes too — wait, then
                    try again.
                </p>
            </div>

            <p v-if="useRecoveryCode" class="auth-note">
                Lost your phone <em>and</em> your recovery codes? Ask whoever administers
                Manara for your organisation to clear two-step sign-in on your account.
                They will need to confirm it is you, and you will be emailed when it is done.
            </p>

            <div class="auth-field">
                <label class="auth-label" for="two_factor_code">
                    {{ useRecoveryCode ? 'Recovery code' : '6-digit code' }}
                </label>
                <input v-if="!useRecoveryCode" id="two_factor_code" ref="codeInput"
                    v-model="twoFactorCode" class="auth-input auth-input--code" inputmode="numeric"
                    autocomplete="one-time-code" maxlength="6" placeholder="••••••"
                    :aria-invalid="authStore.twoFactorError ? 'true' : 'false'"
                    :aria-describedby="authStore.twoFactorError ? 'two_factor_error' : undefined" />
                <input v-else id="two_factor_code" ref="codeInput" v-model="recoveryCode"
                    class="auth-input auth-input--recovery" type="text" autocomplete="off" maxlength="32"
                    placeholder="ABCDE-FGHJK" autocapitalize="characters" spellcheck="false"
                    :aria-invalid="authStore.twoFactorError ? 'true' : 'false'"
                    :aria-describedby="authStore.twoFactorError ? 'two_factor_error' : undefined" />
            </div>

            <div v-if="authStore.twoFactorError" id="two_factor_error" class="auth-alert auth-alert--error"
                role="alert">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" /><path d="M12 7.5v5" /><path d="M12 16h.01" />
                </svg>
                <span>{{ authStore.twoFactorError }}</span>
            </div>

            <div class="auth-actions">
                <button type="submit" class="auth-button" :disabled="submitLoading" :aria-busy="submitLoading">
                    <template v-if="!submitLoading">
                        <span>Verify and sign in</span>
                        <svg class="auth-button__arrow" viewBox="0 0 24 24" width="18" height="18" fill="none"
                            stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M5 12h14" /><path d="m13 6 6 6-6 6" />
                        </svg>
                    </template>
                    <template v-else>
                        <span class="auth-spinner" aria-hidden="true"></span>
                        <span>Verifying…</span>
                    </template>
                </button>
                <a href="#" class="auth-link auth-link--center" @click.prevent="toggleRecoveryCode()">
                    {{ useRecoveryCode ? 'Use a code from your app instead' : 'Use a recovery code instead' }}
                </a>
                <a href="#" class="auth-link auth-link--quiet auth-link--center" @click.prevent="startOver()">
                    Sign in as someone else
                </a>
            </div>
        </form>

        <template #foot>
            New to Manara?
            <a class="auth-link" href="https://manara.hopetechapps.com/">See what it can do for you</a>
        </template>
    </AuthShell>
</template>

<script setup lang="ts">
import AuthShell from '@/components/auth/AuthShell.vue';
import PasswordInput from '@/components/form/PasswordInput.vue';
import { useAuthStore } from '@/stores/authStore';
import { LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';
import { signInSchoolId } from '@/core/helpers/teacherSchools';
import { useMasjidStore } from '@/stores/masjidStore';
import { useTenantSwitchStore } from '@/stores/tenantSwitchStore';
import { Form, Field } from 'vee-validate';
import { computed, nextTick, onBeforeMount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { object, string } from 'yup';

// Lifecycle hooks
onBeforeMount(async () => {
    // A challenge left over from a previous visit is stale: the password it
    // belonged to was never held anywhere, so there is nothing to answer it
    // with. Arriving at this screen always starts at the password form.
    authStore.cancelTwoFactorChallenge();

    if(authStore.isAuthenticated) {
        router.push('/');
    }
});

// Routing
const router = useRouter();

// Stores
const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const tenantSwitchStore = useTenantSwitchStore();

// Custom constants
const nexPath = ref<string|void>()
const validationSchema = object().shape({
    email: string().email('Enter a valid email address.').required('Enter your email address.'),
    password: string().required('Enter your password.')
});

const signData = ref({
    email: "",
    password: ""
});
const submitLoading = ref<boolean>(false);

/**
 * The second-factor answer, held only in this component and only until the
 * request that spends it. Two fields rather than one because the server takes
 * them as two: a 6-digit TOTP code and a printed recovery code are checked
 * against different things, and one field that guessed between them would make
 * "wrong code" ambiguous.
 */
const twoFactorCode = ref<string>('');
const recoveryCode = ref<string>('');
const useRecoveryCode = ref<boolean>(false);
const codeInput = ref<HTMLInputElement | null>(null);

/**
 * The server answers a wrong email or password with the bare phrase "invalid
 * credentials". Said plainly here, with the way out; any other refusal (too
 * many attempts, a disabled login) is shown as the server wrote it.
 */
const signInMessage = computed<string>(() => {
    const message = (authStore.signInError || '').trim();
    if (/^invalid credentials\.?$/i.test(message)) {
        return 'That email and password do not match. Check them and try again, or reset your password.';
    }
    return message ? message.charAt(0).toUpperCase() + message.slice(1) : '';
});

/** Set from the key events in the password field, so a locked Caps key is named before it costs an attempt. */
const capsLockOn = ref<boolean>(false);
function noteCapsLock(event: KeyboardEvent): void {
    if (typeof event.getModifierState === 'function') {
        capsLockOn.value = event.getModifierState('CapsLock');
    }
}

/**
 * The app code is digits only: a pasted "123 456" or "123-456" is cleaned to
 * its digits, and the sixth digit submits, as authenticator codes do elsewhere.
 * A recovery code is left exactly as typed, since the server checks its format.
 */
watch(twoFactorCode, (value) => {
    const digits = value.replace(/\D/g, '').slice(0, 6);
    if (digits !== value) {
        twoFactorCode.value = digits;
        return;
    }
    if (digits.length === 6 && !useRecoveryCode.value && !submitLoading.value) {
        submitCode();
    }
});

async function signIn () : Promise<void> {
    submitLoading.value = true;
    await authStore.login(signData.value.email, signData.value.password, twoFactorCode.value, recoveryCode.value)
        .finally(async () => {
            // Challenged: stay on this screen and put the cursor in the code
            // field. This guard is the ONLY change to the routing block below —
            // every branch in it, and the order they are tried in, is exactly
            // what it was, because that block decides which shell each staff
            // type lands in and none of that is a 2FA question.
            if (authStore.twoFactorRequired) {
                submitLoading.value = false;
                // A refused code is cleared so the next one can be typed
                // straight in; the refusal itself stays on screen.
                if (authStore.twoFactorError && twoFactorCode.value) {
                    twoFactorCode.value = '';
                }
                await nextTick();
                codeInput.value?.focus();
                return;
            }

            if (authStore.isAuthenticated) {
                // `landingMasjidId` is `authStore.user.masjid.id` for every admin
                // who can sign in today, so this branch is entered on exactly the
                // condition it always was. The membership fallback is S5's: once
                // the multi-membership gate opens, an admin can hold a grant in an
                // organisation they do not OWN, and `user.masjid` — a `hasOne` over
                // `masjids.user_id` — is null for them. Without the fallback they
                // would fall past every branch below and land on /auth/401 holding
                // a valid membership.
                const landingMasjidId = authStore.user?.masjid?.id ?? tenantSwitchStore.defaultSelection();

                if(authStore.user?.type === 'MasjidAdmin' && landingMasjidId) {
                    authStore.saveDashboardMasjidId(landingMasjidId);
                    await masjidStore.fetchMasjid()
                        .finally(async () => {
                            router.push("/masjid");
                        });
                }
                else if(authStore.user?.type === 'SuperAdmin') {
                    router.push("/auth/dashboards");
                }
                else if(authStore.user?.type === 'Teacher') {
                    // Mirror the MasjidAdmin prefetch: seed the masjid id the shell
                    // leans on. The teacher realm has no admin access, so we do NOT
                    // call the admin-scoped masjidStore.fetchMasjid(); the teacher
                    // shell reads its own /api/teacher/user for the school header.
                    //
                    // A teacher at several schools lands in the one this browser last
                    // used, when the server still grants it (an expired token sends
                    // them here without signing them out), else in their default.
                    let lastUsed: string | null = null;
                    try {
                        lastUsed = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
                    } catch { /* blocked storage: the default it is */ }

                    const teacherSchool = signInSchoolId(lastUsed, authStore.user.memberships, authStore.user.masjid?.id);
                    if (teacherSchool !== null) {
                        authStore.saveDashboardMasjidId(teacherSchool);
                    }
                    router.push("/teacher");
                }
                else if(authStore.user?.type === 'LunchStaff') {
                    // Same shape as Teacher: seed the masjid id their shell reads
                    // and do NOT call the admin-scoped masjidStore.fetchMasjid(),
                    // which this login has no access to. Their masjid rides on the
                    // login payload, attached from their membership.
                    if (authStore.user.masjid) {
                        authStore.saveDashboardMasjidId(authStore.user.masjid.id);
                    }
                    router.push("/lunch");
                } else {
                    router.push("/auth/401");
                }
            } else {
                router.push("/");
            }
            submitLoading.value = false;
        });
}

/**
 * Answer the challenge.
 *
 * Same call as the first submit — email, password and now a code — because the
 * server holds no partial session. Only one of the two code fields is ever sent:
 * `login()` appends a field only when it has a value, and the other one is
 * cleared when the user switches between them.
 */
async function submitCode(): Promise<void> {
    if (!twoFactorCode.value && !recoveryCode.value) return;
    if (submitLoading.value) return;

    await signIn();
}

/** Swap between the authenticator code and a printed recovery code. */
function toggleRecoveryCode(): void {
    useRecoveryCode.value = !useRecoveryCode.value;
    twoFactorCode.value = '';
    recoveryCode.value = '';
    authStore.twoFactorError = '';
    nextTick(() => codeInput.value?.focus());
}

/** Back to the password form — for a shared machine, or the wrong account. */
function startOver(): void {
    twoFactorCode.value = '';
    recoveryCode.value = '';
    useRecoveryCode.value = false;
    signData.value.password = '';
    authStore.cancelTwoFactorChallenge();
}

</script>
