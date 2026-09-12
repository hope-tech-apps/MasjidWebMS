<template>
    <div class="d-flex justify-content-center" :dir="dir" :lang="lang">
        <div class="card border-0 shadow-sm w-100" style="max-width: 460px;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h1 class="h4 mb-1">{{ t('signin_title') }}</h1>
                        <p class="text-muted small mb-0">{{ t('signin_sub') }}</p>
                    </div>
                    <!-- Same place as on every other screen in this realm. It
                         matters most here: this is the first page a parent who
                         does not read English ever sees. -->
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                            :title="t('switch_lang_title')" @click="toggle">
                        {{ switchLabel }}
                    </button>
                </div>

                <!-- Step 1: the address -->
                <template v-if="step === 'email'">
                    <label class="form-label small text-muted">{{ t('signin_email_label') }}</label>
                    <!-- An address is a left-to-right run whatever the page is
                         set to: an Arabic-aligned "you@example.com" puts the
                         domain where the parent looks for the mailbox name. The
                         placeholder stays in Latin for the same reason — it is
                         the shape of an address, not a sentence to translate. -->
                    <input v-model="email" type="email" class="form-control ltr-field" placeholder="you@example.com"
                           dir="ltr" autocomplete="email" @keyup.enter="requestCode">
                    <p class="form-text">{{ t('signin_email_hint') }}</p>

                    <!-- The password box is ALWAYS offered, never revealed
                         conditionally. Showing it only to parents who have one
                         would make this page answer "does this address have a
                         password here?" — which is a question about a specific
                         family at a specific school, and exactly what the
                         backend's single 410 exists to refuse. -->
                    <template v-if="usePassword">
                        <label class="form-label small text-muted mt-3">{{ t('signin_password_label') }}</label>
                        <input v-model="password" type="password" class="form-control ltr-field" dir="ltr"
                               autocomplete="current-password" @keyup.enter="signInWithPassword">

                        <div v-if="error" class="alert alert-danger small mt-3 mb-0">{{ t(error) }}</div>

                        <button class="btn btn-success w-100 mt-3"
                                :disabled="!emailLooksValid || !password || busy" @click="signInWithPassword">
                            <span v-if="busy" class="spinner-border spinner-border-sm"></span>
                            <span v-else>{{ t('signin_submit') }}</span>
                        </button>
                        <button class="btn btn-link w-100 mt-1 text-decoration-none" :disabled="busy"
                                @click="usePassword = false; error = ''">
                            {{ t('signin_code_instead') }}
                        </button>
                    </template>

                    <template v-else>
                        <button class="btn btn-success w-100 mt-2" :disabled="!emailLooksValid || busy" @click="requestCode">
                            <span v-if="busy" class="spinner-border spinner-border-sm"></span>
                            <span v-else>{{ t('signin_email_code') }}</span>
                        </button>
                        <button class="btn btn-link w-100 mt-1 text-decoration-none" :disabled="busy"
                                @click="usePassword = true; error = ''">
                            {{ t('signin_have_password') }}
                        </button>
                    </template>
                </template>

                <!-- Step 2: the code -->
                <template v-else>
                    <!-- <bdi> around the address, not just dir="ltr": it isolates
                         the whole run so the Arabic comma that follows it in the
                         sentence cannot be reordered into the middle of the
                         domain, which is what bidi resolution does to an
                         unfenced Latin span inside RTL text. -->
                    <div class="alert alert-info small">
                        {{ t('signin_sent_before') }} <bdi dir="ltr"><strong>{{ email }}</strong></bdi>
                        {{ t('signin_sent_after') }}
                    </div>

                    <label class="form-label small text-muted">{{ t('signin_code_label') }}</label>
                    <input v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           class="form-control form-control-lg text-center" style="letter-spacing:.4em"
                           dir="ltr" placeholder="000000" @keyup.enter="verify">

                    <div v-if="error" class="alert alert-danger small mt-3 mb-0">{{ t(error) }}</div>

                    <button class="btn btn-success w-100 mt-3" :disabled="code.length < 4 || busy" @click="verify">
                        <span v-if="busy" class="spinner-border spinner-border-sm"></span>
                        <span v-else>{{ t('signin_submit') }}</span>
                    </button>

                    <button class="btn btn-link w-100 mt-2 text-decoration-none" :disabled="busy" @click="restart">
                        {{ t('signin_other_address') }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { useFamilyStore } from '@/stores/familyStore';
import { useFamilyLang } from '@/views/family/familyI18n';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();
const { lang, dir, toggle, t, switchLabel } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId));
const step = ref<'email' | 'code'>('email');
const email = ref('');
const code = ref('');
const password = ref('');
const usePassword = ref(false);
const busy = ref(false);

// A KEY, not a sentence. Every failure on this page is one of ours — the API
// answers with a bare 410 and no wording of its own — so the slot never has to
// carry server text, and holding the key means a parent who toggles the
// language after a failed attempt sees the reason in the language they just
// asked for.
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
        error.value = 'signin_code_failed';
    } finally {
        busy.value = false;
    }
};

/**
 * The password door.
 *
 * Every failure — unknown address, revoked login, no password set, wrong
 * password — comes back as the same 410, so there is one message here too. A
 * kinder, more specific error would be the disclosure the whole realm is built
 * to avoid: "no password set for that address" tells a stranger that the
 * address IS on file at this school.
 */
const signInWithPassword = async () => {
    if (!emailLooksValid.value || !password.value) return;
    busy.value = true;
    error.value = '';
    try {
        await familyStore.signInWithPassword(masjidId.value, email.value.trim(), password.value);
        router.replace(`/family/${masjidId.value}`);
    } catch {
        error.value = 'signin_password_failed';
    } finally {
        busy.value = false;
    }
};

const restart = () => {
    step.value = 'email';
    code.value = '';
    password.value = '';
    error.value = '';
};
</script>

<style scoped>
/* Credentials are Latin runs. `dir="ltr"` on the field sets the typing
   direction; `text-align: start` then resolves against the FIELD's direction
   rather than the page's, so the text and the caret sit at the left of the box
   even when everything around it is Arabic. Written logically on purpose — a
   hardcoded `left` here would be a second rule to remember if this realm ever
   gains a language that is neither. */
.ltr-field {
    text-align: start;
}
</style>
