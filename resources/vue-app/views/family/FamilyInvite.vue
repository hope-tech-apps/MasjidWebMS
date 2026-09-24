<template>
    <div class="d-flex justify-content-center" :dir="dir" :lang="lang">
        <div class="card border-0 shadow-sm w-100" style="max-width: 460px;">
            <div class="card-body p-4 text-center">
                <div class="d-flex justify-content-end mb-2">
                    <FamilyLangPicker />
                </div>

                <template v-if="busy">
                    <div class="spinner-border text-success my-3" role="status"></div>
                    <p class="mb-0 text-muted">{{ t('invite_working') }}</p>
                </template>

                <template v-else>
                    <h1 class="h5 mb-2">{{ t('invite_failed_title') }}</h1>
                    <p class="text-muted small mb-4">{{ t('invite_failed') }}</p>
                    <router-link class="btn btn-success w-100" :to="`/family/${masjidId}/sign-in`">
                        {{ t('invite_go_signin') }}
                    </router-link>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * The landing for the office's "Send portal invite" link (2026-09-24).
 *
 * ## The parent arrives SIGNED IN, not at a password step
 *
 * The two options were a link that mints a session outright and a link that
 * lands on "choose a password". The staff invite does the second, because a
 * staff account IS a password and there is no other way for one to exist.
 *
 * A parent is the opposite case, and the codebase already says so: the family
 * realm's whole premise is that "200 families cannot be issued passwords and a
 * school office cannot run a reset desk" — the credential is the mailbox, and a
 * password is an optional convenience a parent may CHOOSE later, from inside the
 * portal (`FamilyPasswordService`, and the Family password endpoints, which
 * deliberately have no admin twin). Making a password the price of entry would
 * put the exact friction this feature exists to remove between the parent and
 * the thing they were invited to see, and it would do it at the one moment they
 * are most likely to give up. They arrive at their children; the password offer
 * is already there when they want it.
 *
 * ## Nothing about the session is special
 *
 * `redeemInvite` returns the same body `verify-code` returns and
 * `adoptSession()` stores it identically: the token is an ordinary family token
 * with `Contact::FAMILY_TOKEN_ABILITIES`, aged by the `family` guard's own
 * `config('family.session.expiration_minutes')`, and refused on its next request
 * the moment the office revokes access (`family.active`). An invite is a way in,
 * not a different kind of way in.
 *
 * ## The token comes out of the FRAGMENT and is erased immediately
 *
 * A fragment never reaches a server — not the request line, not `Referer`, not
 * any proxy in between. It does, however, sit in `location.href` and in the
 * browser's history entry, so it is scrubbed with `replaceState` BEFORE the
 * request is sent: a parent who leaves this tab open, or bookmarks it, or hands
 * the phone to their child, leaves no working credential in the URL bar. The
 * staff reset screen does the same thing for the same reason.
 */
import { useFamilyStore } from '@/stores/familyStore';
import { useFamilyLang } from '@/views/family/familyI18n';
import FamilyLangPicker from '@/views/family/FamilyLangPicker.vue';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();
const { lang, dir, t } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId));
const busy = ref(true);

/**
 * Read the token out of the hash and take it out of the URL in the same breath.
 *
 * `URLSearchParams` over the hash body, matching the `http_build_query` the
 * server builds the link with, so a token that ever needs a companion parameter
 * does not need a second parser.
 */
const takeTokenFromHash = (): string => {
    const raw = window.location.hash.startsWith('#') ? window.location.hash.slice(1) : window.location.hash;
    const token = new URLSearchParams(raw).get('token') ?? '';

    if (window.location.hash) {
        // replaceState, not a router push: the point is to leave no history
        // entry that still carries the credential.
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }

    return token;
};

onMounted(async () => {
    const token = takeTokenFromHash();

    if (!token) {
        busy.value = false;

        return;
    }

    try {
        await familyStore.redeemInvite(masjidId.value, token);
        router.replace(`/family/${masjidId.value}`);
    } catch {
        // Every way this fails is one 410 with one body, so there is one
        // message — and it is ours, in the parent's chosen language, rather than
        // the server's English sentence.
        busy.value = false;
    }
});
</script>
