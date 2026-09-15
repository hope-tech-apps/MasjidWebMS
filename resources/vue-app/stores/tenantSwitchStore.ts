import { defineStore } from "pinia";
import { computed, ref } from "vue";
import router from "@/router/router";
import { useAuthStore } from "@/stores/authStore";
import { useMasjidStore } from "@/stores/masjidStore";
import { resetTenantScopedStores } from "@/stores/plugins/tenantStoreReset";
import {
    bumpTenantEpoch,
    forgetServerTenant,
    serverTenantId,
} from "@/core/tenancy/tenantRequests";
import { grantedMemberships, Membership, membershipLabel } from "@/core/types/data/Membership";

/**
 * The organisation switcher — S5 of docs/multi-tenant-admin-design.md.
 *
 * ------------------------------------------------------------------------------
 * It is dark for everybody in production today, and that is the requirement
 * ------------------------------------------------------------------------------
 *
 * Everything here hangs off `memberships[]`, which `/api/admin/user` serves as
 * the grants `App\Support\TenantResolver` would bind — and that resolver is
 * gated by `config/tenancy.php`'s `multi_membership`, which ships FALSE. With
 * the gate shut every admin who can exist holds exactly one grant, so
 * `canSwitch` is false, no switcher chrome renders, no stored id is ever
 * rejected and no notice can appear. A SuperAdmin holds no memberships at all
 * (S2's backfill gave them none on purpose) and keeps the masjid picker they
 * have always had. A backend that predates S4 sends no `memberships` key and
 * every consumer here reads that as an empty array.
 *
 * ------------------------------------------------------------------------------
 * What a switch has to do, in this order, or it is a leak
 * ------------------------------------------------------------------------------
 *
 * 1. Open a new request epoch. Responses already on the wire carry the previous
 *    organisation's rows and are dropped rather than delivered.
 * 2. Empty every tenant-scoped store. This is the step the design calls the
 *    single most important part: a store still holding A's donors is a screen
 *    headed B showing A's donors, with no error anywhere.
 * 3. Forget the echoed tenant, so the chrome claims nothing until the server
 *    says what it bound.
 * 4. Only then move the selection, load the new organisation and remount the
 *    screen.
 *
 * If step 2 cannot be completed the switch does not continue in this tab: it
 * reloads the page, which cannot leak. A switch that half-happened is worse than
 * one that took a second longer.
 */
export const useTenantSwitchStore = defineStore('tenantSwitchStore', () => {

    // Stores
    const authStore = useAuthStore();
    const masjidStore = useMasjidStore();

    /** A switch is in progress; the switcher is disabled and the mismatch notice is held. */
    const switching = ref<boolean>(false);

    /** The last refusal, in the user's words. Empty when there is nothing to say. */
    const switchError = ref<string>('');

    /**
     * Bumped after every completed switch, and used as the key of the dashboard's
     * `<RouterView>`.
     *
     * Emptying the stores is not enough on its own: a screen keeps its OWN state
     * too — the contact open in a modal, the rows a table copied into a local
     * `ref` — and a switch that leaves the route unchanged (switching while
     * already on `/masjid`) re-renders none of it. Changing the key remounts the
     * screen, so every component starts again in the new organisation and
     * re-fetches. It never changes until a switch completes, so an app where
     * nobody switches renders exactly as it did before this existed.
     */
    const viewGeneration = ref<number>(0);

    /**
     * The organisations the SERVER granted. Never assembled locally: a switcher
     * entry the resolver would refuse is a 403 with a spinner in front of it.
     */
    const memberships = computed<Membership[]>(
        () => grantedMemberships(authStore.user?.memberships)
    );

    /** Whether there is anything to switch BETWEEN. One membership renders no chrome at all. */
    const canSwitch = computed<boolean>(() => memberships.value.length > 1);

    /**
     * An organisation administrator the server described and granted nothing.
     *
     * Three conditions, and each one is load-bearing:
     *
     * - `MasjidAdmin` — a SuperAdmin legitimately holds NO membership (S2's
     *   backfill gave them none on purpose) and owns no masjid, so without this
     *   the notice would greet them on every organisation they open.
     * - `memberships` is an ARRAY — an absent key means an older backend that
     *   says nothing about grants, not a user who has none, and reading the two
     *   the same way would strand today's admins behind a notice.
     * - no owned masjid — the pre-pivot authority. While the gate is shut that
     *   is what admits them, and it must still count.
     */
    const hasNoOrganisation = computed<boolean>(() =>
        authStore.user?.type === 'MasjidAdmin'
        && Array.isArray(authStore.user?.memberships)
        && memberships.value.length === 0
        && !authStore.user?.masjid
    );

    /** What this tab has SELECTED. A claim, not a fact — the server decides. */
    const selectedMasjidId = computed<number | null>(() => {
        const selected = Number(authStore.dashboardMasjidId);

        return Number.isInteger(selected) && selected > 0 ? selected : null;
    });

    /** What the server last said it BOUND. The fact. */
    const boundMasjidId = computed<number | null>(() => serverTenantId.value);

    /** The row the switcher marks as current: what the server bound, falling back to the selection. */
    const currentMasjidId = computed<number | null>(() => boundMasjidId.value ?? selectedMasjidId.value);

    /**
     * Several organisations, none of them default, and nothing chosen yet.
     *
     * The other half of "must not be stranded on a blank dashboard": the screens
     * have no masjid to load and the reason is a choice only the user can make.
     * Reachable only once the multi-membership gate opens.
     */
    const mustChooseOrganisation = computed<boolean>(
        () => canSwitch.value && selectedMasjidId.value === null
    );

    /**
     * The organisation name for the chrome — the SERVER's answer, not the store's.
     *
     * For a principal the server sent no memberships for (a SuperAdmin, a
     * teacher, any backend without S4) this is the exact expression the header
     * rendered before S5: `masjidStore.masjid?.name`. Those principals cannot
     * switch, so there is no second organisation for the name to be wrong about,
     * and leaving their chrome untouched is what keeps this slice invisible.
     */
    const chromeOrgName = computed<string>(() => {
        const storeName = masjidStore.masjid?.name ?? '';

        if (!memberships.value.length) return storeName;

        const bound = boundMasjidId.value;
        if (bound === null || masjidStore.masjid?.id === bound) return storeName;

        const membership = memberships.value.find(entry => entry.masjid_id === bound);

        return membership ? membershipLabel(membership) : `Organisation #${bound}`;
    });

    /**
     * The server bound one organisation while this tab believes it is in another.
     *
     * Null in every ordinary case, including mid-switch, where the two disagree
     * for a moment by design. When it is not null the user is TOLD (the chrome
     * already shows the server's answer) rather than left reading a name that
     * does not match the rows underneath it.
     */
    const mismatch = computed<{ server: number; selected: number } | null>(() => {
        if (!memberships.value.length || switching.value) return null;

        const server = boundMasjidId.value;
        const selected = selectedMasjidId.value;

        if (server === null || selected === null || server === selected) return null;

        return { server, selected };
    });

    /**
     * The organisation to land in when nothing has been chosen — the default
     * membership, or the only one there is.
     *
     * Null when there are several grants and none is marked default: nothing in
     * the SPA may choose between organisations on the user's behalf. The
     * switcher asks, because a guess here is a request scoped to an organisation
     * nobody picked. (The database allows at most one default per user, so this
     * is a real fallback and not a coin toss.)
     */
    function defaultSelection(): number | null {
        const granted = memberships.value;

        const fallback = granted.find(entry => entry.is_default)
            ?? (granted.length === 1 ? granted[0] : undefined);

        return fallback ? fallback.masjid_id : null;
    }

    /** The name of an organisation this user administers, for a message about it. */
    function nameFor(masjidId: number | null): string {
        if (masjidId === null) return 'no organisation';

        const membership = memberships.value.find(entry => entry.masjid_id === masjidId);

        return membership ? membershipLabel(membership) : `Organisation #${masjidId}`;
    }

    /**
     * Re-validate the `localStorage` selection against the grants the server just
     * returned, on boot (main.ts).
     *
     * A stored id survives everything — a revoked membership, a masjid that was
     * trashed, an admin who was moved to another organisation — and the tab that
     * reloads with it would spend the session 403ing against a header that reads
     * like it is working. So the stored value is only honoured when the server
     * still grants it, and otherwise it is dropped for the default membership.
     *
     * Returns false when there is nothing to validate against (no `memberships`
     * key: a SuperAdmin, or a backend without S4), in which case the caller runs
     * the pre-S5 line and boot is byte-identical to what it was.
     */
    function rehydrateSelection(storedMasjidId: string | null): boolean {
        const granted = memberships.value;
        if (!granted.length) return false;

        const stored = Number(storedMasjidId);

        if (Number.isInteger(stored) && granted.some(entry => entry.masjid_id === stored)) {
            authStore.saveDashboardMasjidId(stored);
            return true;
        }

        if (storedMasjidId) {
            console.warn(`[tenant] dropped the stored organisation ${storedMasjidId}: it is not one this account administers.`);
        }

        authStore.forgetDashboardMasjidId();

        const fallback = defaultSelection();
        if (fallback !== null) {
            authStore.saveDashboardMasjidId(fallback);
        }

        return true;
    }

    /**
     * Switch to one of this account's organisations.
     *
     * Returns true only when the server has actually served the new organisation.
     * Every other path leaves the tab in a state the user can see and understand.
     */
    async function switchTo(masjidId: number): Promise<boolean> {
        if (switching.value) return false;

        const target = memberships.value.find(entry => entry.masjid_id === masjidId);
        if (!target) {
            // Fail closed. The list of grants is the server's; anything not on it
            // would be refused by the resolver anyway, and offering it here would
            // only turn a 403 into a mystery.
            switchError.value = 'That organisation is not one this account administers.';
            return false;
        }

        const previousId = selectedMasjidId.value;
        if (previousId === masjidId && boundMasjidId.value === masjidId) return true;

        switching.value = true;
        switchError.value = '';

        try {
            bumpTenantEpoch();
            const emptied = resetTenantScopedStores();
            console.debug(`[tenant] switching to masjid ${masjidId}: emptied ${emptied.length} stores`);
        } catch (error) {
            console.error('[tenant] could not empty the stores for the switch: ', error);

            // The previous organisation's rows may still be in memory and this
            // tab can no longer prove otherwise. Take the selection with us and
            // start the app again from nothing.
            authStore.saveDashboardMasjidId(masjidId);
            window.location.assign('/masjid');

            return false;
        }

        // The chrome names nothing until the server answers. Better a blank
        // header for one request than the previous organisation's name over the
        // new one's data.
        forgetServerTenant();
        authStore.saveDashboardMasjidId(masjidId);

        try {
            await masjidStore.fetchMasjid(masjidId);

            if (masjidStore.masjid?.id !== masjidId) {
                await revertTo(previousId, target);

                return false;
            }

            // Leave whatever screen was open: it belonged to the other
            // organisation, and a record id from there resolves to somebody
            // else's row or to a 404.
            await router.push('/masjid');

            // Last, and only on success: every screen remounts into the new
            // organisation. Moving it earlier would remount them while the
            // masjid payload was still loading.
            viewGeneration.value += 1;

            return true;
        } finally {
            // In a finally because the switcher is disabled while this is true.
            // A throw anywhere above — a navigation guard, a router error — would
            // otherwise leave the control dead for the rest of the page's life.
            switching.value = false;
        }
    }

    /**
     * The switch was refused (the resolver said 403, or the organisation is gone).
     * Put the tab back where it was and say so.
     */
    async function revertTo(previousId: number | null, attempted: Membership): Promise<void> {
        switchError.value = `Manara could not open ${membershipLabel(attempted)}. You are still in ${nameFor(previousId)}.`;

        if (previousId === null) {
            authStore.forgetDashboardMasjidId();
            return;
        }

        authStore.saveDashboardMasjidId(previousId);
        await masjidStore.fetchMasjid(previousId);

        if (masjidStore.masjid?.id !== previousId) {
            // Neither organisation will load. Say that plainly instead of
            // leaving a dashboard that looks empty rather than broken.
            switchError.value = `Manara could not open ${membershipLabel(attempted)}, and could not return you to ${nameFor(previousId)} either. Reload the page to try again.`;

            return;
        }

        // The stores were emptied before the refusal, so the screen the user is
        // still looking at is now blank and has no reason to fetch again.
        // Remount it: they never left this organisation, and it has to look like
        // they never left.
        viewGeneration.value += 1;
    }

    return {
        switching, switchError, viewGeneration,
        memberships, canSwitch, hasNoOrganisation, mustChooseOrganisation,
        selectedMasjidId, boundMasjidId, currentMasjidId, chromeOrgName, mismatch,
        nameFor, defaultSelection, rehydrateSelection, switchTo,
    };
});
