<template>
    <div class="d-flex flex-column align-items-center justify-content-center gap-5 w-100 min-vh-100 py-4">
        <div class="d-flex flex-column align-items-center justify-content-center gap-2">
            <img :src="'/manara-icon.svg'" alt="Manara" width="84" height="84" class="mb-1" />
            <div class="display-4 text-cgreen text-center fw-bold">
                Manara
            </div>
            <div class="fs-5 text-muted text-center">Masjid Management Portal</div>
        </div>

        <div class="container">
            <div class="d-flex flex-row flex-wrap align-items-center justify-content-center gap-4">
                <!--
                    The ordinary sign-in form. Untouched for anybody who has not
                    turned on two-step sign-in: same fields, same submit, same
                    single round trip. The code screen below only ever appears
                    AFTER the server has accepted this email and password and
                    asked for a second factor.
                -->
                <Form v-if="!authStore.twoFactorRequired" @submit="signIn()" :validation-schema="validationSchema" class="card border-0 shadow p-3 overflow-auto sign-in-form">
                    <div class="card-header border-0 bg-white text-center fs-1 fw-bold text-cdark">
                        <div class="card-title">Login</div>
                    </div>
                    <div
                        class="card-body d-flex flex-column align-items-start justify-content-start gap-4 w-100">
                        <ColumnInputContainer name="email" label="Your Email" :show_error="true">
                            <Field type="email" name="email" v-model="signData.email" class="input w-100" placeholder="example@example.com" />
                        </ColumnInputContainer>

                        <ColumnInputContainer name="password" label="Your Password" :show_error="true">
                            <PasswordInput name="password" v-model="signData.password" input-class="input w-100" />
                        </ColumnInputContainer>
                    </div>
                    <div class="card-footer bg-white border-0 d-flex flex-column gap-3">
                        <LoadingButton type="submit" classes="btn-success w-100" :is-loading="submitLoading">
                            Sign In
                        </LoadingButton>
                        <router-link to="/auth/forgot-password" class="text-center text-decoration-none">
                            Forgot your password?
                        </router-link>
                    </div>
                </Form>

                <!--
                    The second-factor challenge.

                    The email and password are re-posted with the code, because
                    the challenge is STATELESS — there is no half-signed-in
                    session on the server, deliberately, so there is no partial
                    credential for anyone to steal. They are still in `signData`
                    from the first submit; the user does not retype them.
                -->
                <form v-else @submit.prevent="submitCode()" class="card border-0 shadow p-3 overflow-auto sign-in-form">
                    <div class="card-header border-0 bg-white text-center fw-bold text-cdark">
                        <div class="card-title fs-2">Enter your code</div>
                    </div>
                    <div class="card-body d-flex flex-column align-items-start justify-content-start gap-3 w-100">
                        <p v-if="!useRecoveryCode" class="text-muted mb-0">
                            Open your authenticator app and enter the 6-digit code for Manara.
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
                        <p v-else class="text-muted mb-0">
                            Enter one of the recovery codes you saved when you turned two-step
                            sign-in on. Each code works once. If you have just had several codes
                            refused, recovery codes are paused for a few minutes too — wait, then
                            try again.
                        </p>
                        <p v-if="useRecoveryCode" class="small text-muted mb-0">
                            Lost your phone <em>and</em> your recovery codes? Ask whoever administers
                            Manara for your organisation to clear two-step sign-in on your account.
                            They will need to confirm it is you, and you will be emailed when it is done.
                        </p>

                        <div class="w-100">
                            <label class="form-label" for="two_factor_code">
                                {{ useRecoveryCode ? 'Recovery code' : '6-digit code' }}
                            </label>
                            <input v-if="!useRecoveryCode" id="two_factor_code" ref="codeInput"
                                v-model="twoFactorCode" class="input w-100 font-monospace" inputmode="numeric"
                                autocomplete="one-time-code" maxlength="6" placeholder="123456" />
                            <input v-else id="two_factor_code" ref="codeInput" v-model="recoveryCode"
                                class="input w-100 font-monospace" type="text" autocomplete="off" maxlength="32"
                                placeholder="ABCDE-FGHJK" />
                        </div>

                        <div v-if="authStore.twoFactorError" class="alert alert-danger w-100 mb-0" role="alert">
                            {{ authStore.twoFactorError }}
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 d-flex flex-column gap-3">
                        <LoadingButton type="submit" classes="btn-success w-100" :is-loading="submitLoading">
                            Sign In
                        </LoadingButton>
                        <a href="#" class="text-center text-decoration-none" @click.prevent="toggleRecoveryCode()">
                            {{ useRecoveryCode ? 'Use a code from your app instead' : 'Use a recovery code instead' }}
                        </a>
                        <a href="#" class="text-center text-decoration-none text-muted" @click.prevent="startOver()">
                            Sign in as someone else
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import ColumnInputContainer from '@/components/form/ColumnInputContainer.vue';
import PasswordInput from '@/components/form/PasswordInput.vue';
import LoadingButton from '@/components/form/LoadingButton.vue';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { Form, Field } from 'vee-validate';
import { nextTick, onBeforeMount, ref } from 'vue';
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

// Custom constants
const nexPath = ref<string|void>()
const validationSchema = object().shape({
    email: string().email().required(),
    password: string().required()
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
                await nextTick();
                codeInput.value?.focus();
                return;
            }

            if (authStore.isAuthenticated) {
                if(authStore.user?.type === 'MasjidAdmin' && authStore.user.masjid) {
                    authStore.saveDashboardMasjidId(authStore.user.masjid.id);
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
                    if (authStore.user.masjid) {
                        authStore.saveDashboardMasjidId(authStore.user.masjid.id);
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

<style scoped>
.sign-in-form {
    width: 22rem;
}
</style>