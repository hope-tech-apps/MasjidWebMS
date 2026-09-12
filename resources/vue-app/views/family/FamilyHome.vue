<template>
    <div :dir="dir" :lang="lang">
        <!-- `flex-wrap`: the translate button carries two languages at once and
             is wide, so on a phone the pair drops below the greeting rather
             than crushing it to one word per line. -->
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div>
                <h1 class="h4 mb-1">
                    {{ firstName ? t('home_greeting_named', firstName) : t('home_greeting') }}
                </h1>
                <p class="text-muted small mb-0">{{ t('home_sub') }}</p>
            </div>
            <!-- The toggle sits at the end of the header row on every screen in
                 this realm, so a parent who finds it once knows where it is —
                 and the translate button sits beside it here for the same
                 reason it does on the class screen. -->
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <!-- Only the class DESCRIPTIONS are translatable on this screen,
                     so the button appears only when a class actually carries
                     one. Most of what is here is names — of classes and of
                     children — which are left alone: a translated class name is
                     not a name the office would recognise if a parent rang up
                     and read it out. -->
                <button v-if="translationAvailable && translatableItems.length" type="button"
                        class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1"
                        :disabled="translating" :aria-busy="translating" @click="onTranslate">
                    <span v-if="translating" class="spinner-border spinner-border-sm"></span>
                    <i v-else class="bi bi-translate"></i>
                    {{ translationShowing ? tBoth('tr_show_original') : tBoth('tr_translate') }}
                </button>

                <button type="button" class="btn btn-sm btn-outline-secondary"
                        :title="t('switch_lang_title')" @click="toggle">
                    {{ switchLabel }}
                </button>
            </div>
        </div>

        <!-- Honest about what came back, exactly as on the class screen. -->
        <div v-if="translationError" class="alert alert-warning py-2 small">
            {{ tMessageBoth(translationError) }}
        </div>
        <div v-else-if="translationShowing && translationIncomplete" class="alert alert-warning py-2 small">
            {{ tBoth('tr_incomplete') }}
        </div>
        <p v-if="translationShowing" class="text-muted small d-flex align-items-baseline gap-2">
            <i class="bi bi-translate"></i><span>{{ tBoth('tr_machine') }}</span>
        </p>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-success"></span>
        </div>

        <div v-else-if="error" class="alert alert-danger">{{ tMessage(error) }}</div>

        <div v-else-if="!groups.length" class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <p class="mb-1 fw-semibold">{{ t('home_empty_title') }}</p>
                <p class="text-muted small mb-0">{{ t('home_empty_body') }}</p>
            </div>
        </div>

        <div v-else class="d-flex flex-column gap-3">
            <router-link v-for="group in groups" :key="group.id"
                         :to="`/family/${masjidId}/classes/${group.id}`"
                         class="card border-0 shadow-sm text-decoration-none text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <!-- dir="auto" on the school's own words, as on the
                                 class screen: a class named in one language
                                 inside a portal set to the other is a run in the
                                 opposite direction, and only the browser can
                                 resolve that per string. The words are
                                 untouched; only their direction is. -->
                            <h2 class="h6 mb-1" dir="auto">{{ group.name }}</h2>
                            <p v-if="group.description" class="text-muted small mb-2" dir="auto">{{ txGroupDescription(group) }}</p>

                            <div class="d-flex flex-wrap gap-3">
                                <span v-for="child in group.children" :key="child.membership_id"
                                      class="d-inline-flex align-items-center gap-2">
                                    <PersonAvatar
                                        :avatar="child.contact?.avatar"
                                        :first-name="child.contact?.first_name"
                                        :last-name="child.contact?.last_name"
                                        :size="34" />
                                    <span class="small" dir="auto">{{ childName(child) }}</span>
                                </span>
                            </div>
                        </div>
                        <!-- A chevron is a direction, not decoration: it points at
                             the screen this card opens, which is off the right of
                             the page in English and off the left in Arabic. -->
                        <i class="text-muted" :class="chevronIcon"></i>
                    </div>

                    <!-- Stated, not inferred. A parent who has not consented must not be
                         shown an empty class story and left to think the teacher posts nothing. -->
                    <div v-if="!group.may_receive_feed" class="alert alert-warning small mt-3 mb-0 py-2">
                        {{ t('home_no_consent') }}
                    </div>
                </div>
            </router-link>
        </div>

        <!-- SIGNING IN NEXT TIME.
             Offered here rather than buried in a settings screen the portal does
             not have, and placed BELOW the children because that is what a
             parent came for. Codes never go away: this is a convenience laid on
             top of the mailbox, not a replacement for it, so a parent who
             forgets the password is never locked out. -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h6 mb-1">{{ t('signin_panel_title') }}</h2>
                <p class="text-muted small mb-3">
                    {{ hasPassword ? t('signin_panel_has_pw') : t('signin_panel_no_pw') }}
                </p>

                <template v-if="pwOpen">
                    <div class="row g-2">
                        <div class="col-12 col-sm">
                            <label class="form-label small text-muted mb-1">{{ t('pw_new') }}</label>
                            <input v-model="pw" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password">
                        </div>
                        <div class="col-12 col-sm">
                            <label class="form-label small text-muted mb-1">{{ t('pw_again') }}</label>
                            <input v-model="pw2" type="password" class="form-control form-control-sm"
                                   autocomplete="new-password" @keyup.enter="savePassword">
                        </div>
                    </div>
                    <p class="form-text mb-2">{{ t('pw_hint') }}</p>

                    <div v-if="pwError" class="alert alert-danger small py-2 mb-2">{{ tMessage(pwError) }}</div>
                    <div v-if="pwSaved" class="alert alert-success small py-2 mb-2">{{ tMessage(pwSaved) }}</div>

                    <button class="btn btn-sm btn-success" :disabled="pwBusy || !pw || !pw2" @click="savePassword">
                        {{ pwBusy ? t('pw_saving') : (hasPassword ? t('pw_change') : t('pw_set')) }}
                    </button>
                    <button class="btn btn-sm btn-link text-decoration-none" :disabled="pwBusy"
                            @click="closePw">{{ t('cancel') }}</button>
                </template>

                <template v-else>
                    <button class="btn btn-sm btn-outline-success" @click="pwOpen = true">
                        {{ hasPassword ? t('pw_change_mine') : t('pw_set_a') }}
                    </button>
                    <button v-if="hasPassword" class="btn btn-sm btn-link text-danger text-decoration-none"
                            :disabled="pwBusy" @click="removePassword">
                        {{ t('pw_remove') }}
                    </button>
                    <div v-if="pwSaved" class="alert alert-success small py-2 mt-2 mb-0">{{ tMessage(pwSaved) }}</div>
                    <div v-if="pwError" class="alert alert-danger small py-2 mt-2 mb-0">{{ tMessage(pwError) }}</div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import FamilyApiService, { rowsOf } from '@/core/services/FamilyApiService';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import { useFamilyStore } from '@/stores/familyStore';
import { useFamilyLang } from '@/views/family/familyI18n';
import type { FamilyMessage } from '@/views/family/familyI18n';
import { useContentTranslation } from '@/views/family/useContentTranslation';
import type { TranslatableItem } from '@/views/family/useContentTranslation';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();
const { lang, isAr, dir, toggle, t, tMessage, tBoth, tMessageBoth, switchLabel } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId));
const firstName = computed(() => familyStore.contact?.first_name ?? '');

const chevronIcon = computed(() => (isAr.value ? 'bi bi-chevron-left' : 'bi bi-chevron-right'));

const groups = ref<any[]>([]);
const loading = ref(true);
const error = ref<FamilyMessage | null>(null);

// ---------- signing in next time ----------
const hasPassword = ref(false);
const pwOpen = ref(false);
const pw = ref('');
const pw2 = ref('');
const pwBusy = ref(false);
const pwError = ref<FamilyMessage | null>(null);
const pwSaved = ref<FamilyMessage | null>(null);

const closePw = () => { pwOpen.value = false; pw.value = ''; pw2.value = ''; pwError.value = null; };

const loadMe = async () => {
    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${masjidId.value}/me`);
        hasPassword.value = !!res.data?.data?.has_password;
    } catch {
        // Non-fatal: the panel just offers "Set a password", which is harmless
        // to show to someone who already has one — it changes it.
    }
};

const savePassword = async () => {
    pwError.value = null;
    pwSaved.value = null;
    if (pw.value !== pw2.value) { pwError.value = { key: 'pw_mismatch' }; return; }

    pwBusy.value = true;
    try {
        await familyStore.setPassword(masjidId.value, pw.value, pw2.value);
        hasPassword.value = true;
        closePw();
        pwSaved.value = { key: 'pw_saved' };
    } catch (e: any) {
        // The server's own wording — it explains the 12-character minimum and
        // the breached-password refusal better than a generic message could.
        // It arrives in English whatever this portal is set to; translating a
        // validator's sentence in the client would mean guessing which of them
        // came back, and guessing wrong would tell a parent the wrong reason.
        const served = e?.response?.data?.data?.password?.[0];
        pwError.value = served ? { text: served } : { key: 'pw_save_failed' };
    } finally {
        pwBusy.value = false;
    }
};

const removePassword = async () => {
    pwBusy.value = true;
    pwError.value = null;
    pwSaved.value = null;
    try {
        await familyStore.clearPassword(masjidId.value);
        hasPassword.value = false;
        pwSaved.value = { key: 'pw_removed' };
    } catch {
        pwError.value = { key: 'pw_remove_failed' };
    } finally {
        pwBusy.value = false;
    }
};

const childName = (child: any) =>
    [child?.contact?.first_name ?? child?.first_name, child?.contact?.last_name ?? child?.last_name]
        .filter(Boolean).join(' ') || t('student');

// ---------- translating what the school wrote ----------
//
// The only staff-written prose on this screen is a class description, so this
// is the small version of what FamilyClass.vue does — same composable, same
// key shape, same refusal to fire on its own. The shared key shape is what
// makes it worth having here at all: a description translated from this screen
// is already in the server's cache when the parent taps into the class, so the
// second reading of it costs nothing.

/** `group:<id>:description`, identical to the key the class screen emits. */
const descriptionKey = (group: any) => `group:${group.id}:description`;

const {
    loading: translating,
    error: translationError,
    incomplete: translationIncomplete,
    showOriginal,
    showing: translationShowing,
    available: translationAvailable,
    setAvailable: setTranslationAvailable,
    translate,
    tx,
} = useContentTranslation(masjidId, {
    // The same judgement onMounted makes below: an ended session is a
    // navigation, not a translation error to report on a page being replaced.
    onAuthFailure: (e: any) => {
        if (!familyStore.handleAuthFailure(e?.response?.status)) return false;

        router.replace(`/family/${masjidId.value}/sign-in`);

        return true;
    },
});

const translatableItems = computed<TranslatableItem[]>(() =>
    groups.value
        .filter((group: any) => typeof group.description === 'string' && group.description.trim() !== '')
        .map((group: any) => ({ key: descriptionKey(group), text: group.description })),
);

// No watcher here, unlike the class screen. The classes arrive in one request
// and never grow afterwards, and the button does not exist until they have
// landed — so there is no "content that appeared after the tap" case for one to
// catch, and a watcher written for symmetry would be a watcher that can never
// fire.
const onTranslate = async () => {
    if (translationShowing.value) {
        showOriginal.value = true;
        return;
    }

    showOriginal.value = false;
    await translate(translatableItems.value);
};

const txGroupDescription = (group: any) => tx(descriptionKey(group), group.description);

onMounted(async () => {
    try {
        const res = await FamilyApiService.get(`/api/family/masjids/${masjidId.value}/groups`);
        groups.value = rowsOf(res.data?.data);

        // See FamilyClass.vue: no key on the box, no button on the screen.
        setTranslationAvailable(res.data?.meta?.translation_available);
    } catch (e: any) {
        if (familyStore.handleAuthFailure(e?.response?.status)) {
            router.replace(`/family/${masjidId.value}/sign-in`);
            return;
        }
        error.value = { key: 'home_load_error' };
    } finally {
        loading.value = false;
    }

    // After the classes, never before: the panel is secondary and must not
    // delay the thing the parent opened the portal for.
    loadMe();
});
</script>
