<template>
    <div class="min-vh-100 d-flex align-items-center py-5" :style="cssVars"
         style="background: var(--org-bg);">
        <div class="container" style="max-width: 620px;">

            <!-- THE SCHOOL'S OWN MARK, not ours. A parent following a link from
                 their school's website must land somewhere that looks like their
                 school; the vendor appears once, in grey, at the very bottom. -->
            <div class="text-center mb-4">
                <div v-if="logo" class="d-inline-block bg-white rounded-4 shadow-sm p-3 mb-3">
                    <!-- object-fit, never a circle crop: the file is a
                         wordmark-and-mark lockup and a circle decapitates it. -->
                    <img :src="logo" :alt="orgName" style="height:72px;width:auto;object-fit:contain;">
                </div>

                <h1 class="h3 fw-semibold mb-1" style="color: var(--org-text);">
                    {{ orgName || 'Portal' }}
                </h1>
                <p class="mb-0" style="color: var(--org-muted);">
                    {{ loading ? 'Loading…' : 'Sign in to the school portal' }}
                </p>
            </div>

            <!-- TWO DOORS, LABELLED. Deliberately not one email box that works
                 out who you are: that would answer "is this address a parent at
                 this school?", which is a question about a roster of children.
                 Two buttons cannot be asked it. -->
            <div class="row g-3 row-cols-1 row-cols-md-2">
                <div class="col">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body d-flex flex-column p-4">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                                 style="width:44px;height:44px;background:var(--org-primary-soft);">
                                <i class="bi bi-people-fill" style="color:var(--org-primary);"></i>
                            </div>
                            <h2 class="h6 fw-semibold mb-1" style="color: var(--org-text);">Parents</h2>
                            <p class="small mb-4" style="color: var(--org-muted);">
                                See your child's class, marks, attendance and report cards, and
                                message their teacher.
                            </p>
                            <a class="btn btn-brand w-100 mt-auto" :href="parentHref">Parent sign in</a>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body d-flex flex-column p-4">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                                 style="width:44px;height:44px;background:var(--org-primary-soft);">
                                <i class="bi bi-mortarboard-fill" style="color:var(--org-primary);"></i>
                            </div>
                            <h2 class="h6 fw-semibold mb-1" style="color: var(--org-text);">Teachers &amp; staff</h2>
                            <p class="small mb-4" style="color: var(--org-muted);">
                                Take the register, plan lessons, mark work and write report cards.
                            </p>
                            <a class="btn btn-brand-outline w-100 mt-auto" href="/auth/sign-in">Staff sign in</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students have no login BY DESIGN — a child gets in when a parent
                 hands them the device from inside the family portal. Saying so
                 here stops a parent hunting for a third button that will never
                 exist, and stops a child being told to "ask for their password". -->
            <p class="text-center small mt-4 mb-0" style="color: var(--org-muted);">
                Students don't sign in on their own — a parent opens the portal and
                hands over the device.
            </p>

            <hr class="my-4" style="border-color: var(--org-border);">
            <p class="text-center mb-0" style="font-size:.75rem;opacity:.55;color:var(--org-text);">
                Powered by Manara
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * The school's own front door — one branded page, two labelled doors.
 *
 * Reached from the school's website (alrazischool.org/portal), so it is the
 * first thing a family sees and must not look like a vendor's admin tool. The
 * previous state of the world was a green page reading "Manara — Masjid
 * Management Portal", which is the wrong name and the wrong brand for a parent
 * who was told to visit their child's school.
 *
 * ---------------------------------------------------------------------------
 * NO NEW ENDPOINT, AND THAT IS THE POINT
 * ---------------------------------------------------------------------------
 *
 * The name, logo and palette come from GET /api/mobile/masjids/{id}, which is
 * already unauthenticated, already serves unlisted organisations (Al-Razi is
 * unlisted), and is already what FamilyLayout reads for the same purpose. A
 * purpose-built "branding" route was designed and then thrown away: it would
 * have been a brand-new public surface on a multi-tenant system, and every byte
 * it returned is a subset of what this one already publishes. The safest new
 * endpoint is the one you do not add.
 *
 * ---------------------------------------------------------------------------
 * THE COLOURS ARE THE SCHOOL'S, READ NOT HARDCODED
 * ---------------------------------------------------------------------------
 *
 * `theme.tokens.color` is resolved server-side by App\Support\DesignTokens, and
 * for Al-Razi it already holds #286C56 — their own primary-600, the same value
 * as on alrazischool.org. Nothing here is tenant-specific: the identical
 * component renders the next school's palette with no branch. Hardcoding a hex
 * is what homogenises; reading one is what does not.
 *
 * `onPrimary` is computed from WCAG relative luminance server-side, so a school
 * that picks a pale gold gets dark text automatically rather than white-on-cream.
 */
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import FamilyApiService from '@/core/services/FamilyApiService';

const route = useRoute();
const masjidId = computed(() => String(route.params.masjidId ?? ''));

const orgName = ref('');
const logo = ref<string | null>(null);
const tokens = ref<Record<string, string>>({});
const loading = ref(true);

/**
 * Neutral slate, deliberately NOT Manara green.
 *
 * If branding cannot be fetched, the honest failure is a plain, unbranded page —
 * not one wearing the vendor's colours, which would assert something about the
 * school that we failed to look up.
 */
const FALLBACK: Record<string, string> = {
    primary: '#475569',
    onPrimary: '#FFFFFF',
    background: '#F8FAFC',
    text: '#111827',
    textMuted: '#6B7280',
    border: '#E5E7EB',
};

const cssVars = computed(() => {
    const c = { ...FALLBACK, ...tokens.value };
    return {
        '--org-primary': c.primary,
        '--org-on-primary': c.onPrimary,
        '--org-bg': c.background,
        '--org-text': c.text,
        '--org-muted': c.textMuted,
        '--org-border': c.border,
        // Derived, so a palette change needs no second stored value and the
        // hover can never drift out of the school's hue.
        '--org-primary-hover': `color-mix(in srgb, ${c.primary} 88%, black)`,
        '--org-primary-soft': `color-mix(in srgb, ${c.primary} 12%, white)`,
    };
});

const parentHref = computed(() => `/family/${masjidId.value}/sign-in`);

onMounted(async () => {
    try {
        const res = await FamilyApiService.get(`/api/mobile/masjids/${masjidId.value}`);
        const d = res.data?.data ?? {};
        orgName.value = d.name ?? '';
        logo.value = d.logo?.original_url ?? d.logo?.url ?? null;
        tokens.value = d.theme?.tokens?.color ?? {};
    } catch {
        // Non-fatal, always. A branding lookup that fails must never be able to
        // break the doors themselves — the page still renders, unbranded, and
        // both buttons still work.
        orgName.value = '';
        logo.value = null;
        tokens.value = {};
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
/*
 * Bootstrap 5.3's own button variables, rather than overriding its selectors —
 * so hover, focus, active and disabled all stay consistent with every other
 * button in the app while taking the school's colour.
 */
.btn-brand {
    --bs-btn-bg: var(--org-primary);
    --bs-btn-border-color: var(--org-primary);
    --bs-btn-color: var(--org-on-primary);
    --bs-btn-hover-bg: var(--org-primary-hover);
    --bs-btn-hover-border-color: var(--org-primary-hover);
    --bs-btn-hover-color: var(--org-on-primary);
    --bs-btn-active-bg: var(--org-primary-hover);
    --bs-btn-active-border-color: var(--org-primary-hover);
    --bs-btn-active-color: var(--org-on-primary);
}

.btn-brand-outline {
    --bs-btn-color: var(--org-primary);
    --bs-btn-border-color: var(--org-primary);
    --bs-btn-hover-bg: var(--org-primary);
    --bs-btn-hover-color: var(--org-on-primary);
    --bs-btn-hover-border-color: var(--org-primary);
    --bs-btn-active-bg: var(--org-primary);
    --bs-btn-active-color: var(--org-on-primary);
}
</style>
