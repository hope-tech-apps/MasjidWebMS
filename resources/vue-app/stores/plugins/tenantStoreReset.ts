import { getActivePinia, PiniaPluginContext, Store } from "pinia";

/**
 * `$reset()` for setup stores, and the sweep that empties every one of them when
 * the admin switches organisation — S5 of docs/multi-tenant-admin-design.md.
 *
 * ------------------------------------------------------------------------------
 * Why the plugin has to exist
 * ------------------------------------------------------------------------------
 *
 * Every store in this app is written in the setup style
 * (`defineStore('x', () => { … })`), and pinia gives setup stores NO working
 * `$reset()`. The design's instruction ("$reset() every stores/masjid/* store")
 * is therefore not something one can simply do; the method has to be built
 * first, out of a snapshot of each store's state taken at the moment pinia
 * creates it, which is the only moment that state is known to be empty.
 *
 * **And pinia's own `$reset` must never be trusted for a setup store, in either
 * direction.** Read `createSetupStore` in pinia/dist/pinia.mjs: for a setup
 * store `$reset` throws in a development build and is `noop` in a PRODUCTION
 * one. A plugin that called the built-in and treated "it did not throw" as
 * success would therefore work in every `npm run dev` session and quietly empty
 * nothing at all on masjid.hopetechapps.com — a switch that reports success
 * while organisation A's donors stay in the store. So the override below is
 * unconditional: the snapshot is the only implementation, for setup and options
 * stores alike.
 *
 * ------------------------------------------------------------------------------
 * The sweep denies by default
 * ------------------------------------------------------------------------------
 *
 * The design names `stores/masjid/*`, and that directory is where the ~30
 * tenant-scoped stores live today. This sweep resets every LIVE store EXCEPT a
 * short, named list, which is a superset of that directory and stays correct
 * when someone puts the thirty-first tenant-scoped store somewhere else —
 * `dashboardSearchStore` is already such a store, sitting one directory up and
 * holding search hits from the organisation being left. A list of what to wipe
 * would have to be remembered; a list of what to KEEP is three entries long and
 * anything forgotten is merely re-fetched.
 *
 * Only stores pinia has actually instantiated are touched. A store nobody has
 * used holds nothing, and instantiating ~30 of them to prove it would run their
 * setup functions for no reason.
 */

/** Every store pinia has built in this page life, by id. */
const liveStores = new Map<string, Store>();

/**
 * The stores a switch must NOT empty, each for a reason that is about the human
 * rather than the organisation:
 *
 * - `authStore` — the session itself. Emptying it signs the user out mid-switch.
 * - `twoFactorStore` — enrollment state for this ACCOUNT (`/api/admin/2fa/*`,
 *   no masjid segment). Wiping it would abandon a half-finished enrollment
 *   because the user changed organisation.
 * - `dashboardAsideStore` — the sidebar's menu constant, chosen by the router's
 *   `beforeEach`. Resetting it while staying on the same route (a switch does
 *   not always navigate) would blank the sidebar until the next click.
 */
const SESSION_STORES = new Set<string>([
    'authStore',
    'twoFactorStore',
    'dashboardAsideStore',
    // The store PERFORMING the switch cannot be part of what the switch empties.
    // `resetTenantScopedStores()` is called from the middle of
    // `tenantSwitchStore.switchTo`, so sweeping it resets `switching` and
    // `viewGeneration` half-way through — the spinner stops, the remount key goes
    // backwards, and the steps after it run against state that has been rewound.
    'tenantSwitchStore',
]);

/**
 * Pinia plugin: give every store a working `$reset()` and remember it exists.
 *
 * Registered in stores/index.ts before any store is created. Adding an unused
 * method changes nothing about how the app runs today — until a switch calls it,
 * this file is inert.
 */
export function tenantStoreReset({ store }: PiniaPluginContext): void {
    const initialState = snapshot(store.$state);

    store.$reset = () => {
        // The function form of $patch, not the object form: the object form
        // MERGES, so a list that is empty in the initial state would keep the
        // rows it grew — which for a tenant-scoped store is the leak itself.
        // The snapshot is copied again on every reset, so two switches cannot
        // share (and mutate) one object.
        store.$patch((state: Record<string, unknown>) => {
            Object.assign(state, snapshot(initialState));
        });
    };

    liveStores.set(store.$id, store);
}

/**
 * Empty every live store that is not part of the session, and return the ids
 * that were emptied so the caller can log what it proved.
 *
 * Throws if any store refuses to reset. That is deliberate: the switch cannot
 * continue in a tab where it cannot show that the previous organisation's rows
 * are gone, and the caller turns the throw into a full page load, which cannot
 * leak.
 */
export function resetTenantScopedStores(): string[] {
    assertEveryStoreCanBeReset();

    const emptied: string[] = [];

    liveStores.forEach((store, id) => {
        if (SESSION_STORES.has(id)) return;

        store.$reset();
        emptied.push(id);
    });

    return emptied;
}

/**
 * Cross-check our registry against pinia's own, and refuse the sweep if pinia
 * knows a store we do not.
 *
 * `pinia.use()` called before `app.use(pinia)` QUEUES the plugin — it only
 * becomes active when the pinia is installed on the app. Any store created in
 * between would therefore be built without a snapshot and without a working
 * `$reset`, and would sail through this sweep untouched while the sweep reported
 * success. That is a store keeping the previous organisation's rows across a
 * switch, reported as a clean switch, which is the exact failure this whole
 * slice exists to prevent.
 *
 * So the gap is made LOUD: the throw reaches tenantSwitchStore.switchTo(), which
 * abandons the in-tab switch and reloads the page instead. A reload cannot leak.
 *
 * `_s` is pinia's internal map of instantiated stores. It is read defensively —
 * if a future pinia stops exposing it, the check is skipped rather than made
 * into a false alarm, and the sweep still empties everything it does know.
 */
function assertEveryStoreCanBeReset(): void {
    const active = getActivePinia() as unknown as { _s?: Map<string, unknown> } | undefined;
    const instantiated = active?._s;

    if (!(instantiated instanceof Map)) return;

    const unreachable: string[] = [];

    instantiated.forEach((_store, id) => {
        if (SESSION_STORES.has(id) || liveStores.has(id)) return;

        unreachable.push(id);
    });

    if (unreachable.length) {
        throw new Error(
            `[tenant] these stores were created without the reset plugin and cannot be emptied: ${unreachable.join(', ')}`
        );
    }
}

/**
 * A deep copy that cannot throw.
 *
 * `structuredClone` would be shorter and would reject the first store that ever
 * puts a function, a class instance or a DOM node in its state — at runtime, at
 * store-creation time, for every user. Anything this does not know how to copy
 * is carried by reference instead, which is the right answer for an initial
 * snapshot: the value was never a per-organisation row to begin with.
 */
function snapshot<T>(value: T): T {
    if (Array.isArray(value)) {
        return value.map(item => snapshot(item)) as unknown as T;
    }

    if (value instanceof Date) {
        return new Date(value.getTime()) as unknown as T;
    }

    if (value !== null && typeof value === 'object') {
        const proto = Object.getPrototypeOf(value);
        if (proto === Object.prototype || proto === null) {
            const copy: Record<string, unknown> = {};
            for (const key of Object.keys(value as Record<string, unknown>)) {
                copy[key] = snapshot((value as Record<string, unknown>)[key]);
            }
            return copy as unknown as T;
        }
    }

    return value;
}
