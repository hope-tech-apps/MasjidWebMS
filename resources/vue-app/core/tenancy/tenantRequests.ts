import { readonly, ref } from "vue";
import { AxiosResponse, InternalAxiosRequestConfig } from "axios";

/**
 * The request epoch and the server's tenant echo — S5 of
 * docs/multi-tenant-admin-design.md.
 *
 * ------------------------------------------------------------------------------
 * Why an epoch exists at all
 * ------------------------------------------------------------------------------
 *
 * Switching organisation is not a navigation, it is a change of WHO the rest of
 * this tab is about. Requests issued a moment before the switch are already on
 * the wire; they come back carrying organisation A's donors, congregants and
 * children's records, and every one of them lands in a store that a screen now
 * labelled B is rendering. Nothing about that response looks wrong — it is a 200
 * with real rows — so there is no error anywhere to notice.
 *
 * So every request is STAMPED with the epoch that was current when it left, and
 * a response stamped with anything else is dropped instead of delivered. The
 * abort below cancels what can still be cancelled; the stamp catches what was
 * already in the browser's hands when the user clicked.
 *
 * ------------------------------------------------------------------------------
 * Dropped means DROPPED, not rejected
 * ------------------------------------------------------------------------------
 *
 * A stale response resolves to a promise that never settles, so neither the
 * `.then()` that would have written organisation A's rows nor the `.catch()`
 * that would have popped "Sorry, …" over organisation B's dashboard ever runs.
 * Rejecting instead would turn one switch into a burst of error modals naming
 * failures that did not happen, and the `.finally()` handlers would run in the
 * wrong organisation. The loading flags those `.finally()`s exist to clear are
 * restored by the store reset that the switch performs anyway
 * (stores/plugins/tenantStoreReset.ts), so nothing is left spinning.
 *
 * The drop is logged. A silently discarded response is exactly the shape of bug
 * this file exists to prevent, so it must never be invisible in a console.
 *
 * ------------------------------------------------------------------------------
 * The echo: the chrome renders the SERVER's tenant
 * ------------------------------------------------------------------------------
 *
 * The organisation name in the header used to be whatever the SPA last decided
 * to fetch. It is now whatever the server says it BOUND — because those are two
 * different facts, and the only dangerous one is the case where they differ: a
 * screen headed "Al-Razi School" showing rows the resolver scoped to a masjid.
 * When they disagree the server wins and the user is told (TenantMismatchNotice),
 * rather than being shown a quietly wrong organisation name.
 *
 * Absence is not disagreement. An admin route that is deliberately not about one
 * masjid (`/api/admin/user`, `/api/admin/masjids`) binds nothing and echoes
 * nothing, and a backend that predates S4 echoes nothing at all — in both cases
 * the last known bound tenant stands and the chrome falls back to exactly what
 * it rendered before this file existed.
 */

/** Where the stamp is parked on the axios config. Non-enumerable-ish by convention: nothing serialises a config. */
const EPOCH_KEY = '__tenantEpoch';

/**
 * Response headers carrying the tenant the server bound, canonical first.
 *
 * **`x-tenant-id` is the CONTRACT**, and it is written down on both sides:
 * `App\Http\Middleware\EchoResolvedTenant::TENANT_HEADER` names this constant in
 * its docblock and says the two change together or not at all. Renaming either
 * half alone does not fail loudly — this file simply finds nothing, the chrome
 * falls back to the store, and the SPA goes back to painting the organisation it
 * BELIEVES it is in over whatever rows arrived.
 *
 * The other two spellings are read because this SPA ships independently of the
 * backend serving it, and a differently-spelled deploy should light the chrome
 * up rather than silently fall back.
 *
 * The server sends the literal `unbound` for a route that is deliberately not
 * about one masjid. That is not a number, so it is read as "no echo" and the
 * last bound tenant stands — which is what the middleware documents and expects.
 *
 * This is readable only because the admin SPA is served SAME-ORIGIN by Laravel
 * (`config/cors.php`: `exposed_headers` is empty and `supports_credentials` is
 * false, so a cross-origin admin deploy could not read this header — nor, with
 * `withCredentials: true`, any admin response at all).
 */
const TENANT_HEADERS = ['x-tenant-id', 'x-resolved-masjid-id', 'x-masjid-id'];

let epoch = 0;

/**
 * Cancels what is still cancellable at the moment of a switch. Swapped for a
 * fresh controller BEFORE it is aborted, so a request issued by the switch
 * itself can never be caught by its own abort.
 */
let inFlight = new AbortController();

const serverTenant = ref<number | null>(null);

/** The tenant the server said it bound on the most recent bound response. */
export const serverTenantId = readonly(serverTenant);

/** The epoch a request would be stamped with right now. */
export function tenantEpoch(): number {
    return epoch;
}

/**
 * Open a new epoch: everything already on the wire stops counting.
 *
 * Called by the switch (and by sign-out, where the next person to use this tab
 * must not inherit the last one's in-flight rows). Returns the new epoch so the
 * caller can tell whether anything moved underneath it while it awaited.
 */
export function bumpTenantEpoch(): number {
    epoch += 1;

    const superseded = inFlight;
    inFlight = new AbortController();
    superseded.abort();

    return epoch;
}

/** The signal every request of the CURRENT epoch rides on. */
export function tenantAbortSignal(): AbortSignal {
    return inFlight.signal;
}

/**
 * Stamp a request with the epoch it belongs to, and put it on that epoch's abort
 * signal. Installed as a request interceptor in ApiService.init().
 *
 * A caller that brought its own `signal` keeps it — overriding it would take a
 * cancellation away from code that asked for one.
 */
export function stampTenantEpoch(config: InternalAxiosRequestConfig): InternalAxiosRequestConfig {
    (config as any)[EPOCH_KEY] = epoch;

    if (!config.signal) {
        config.signal = inFlight.signal;
    }

    return config;
}

/**
 * Whether this settled request belongs to an organisation the user has left.
 *
 * An UNSTAMPED config is never treated as stale: every request through this
 * axios instance passes the interceptor above, so an unstamped one came from
 * somewhere that does not participate in switching, and dropping it would break
 * a caller for a reason that does not apply to it.
 */
export function isFromSupersededEpoch(config: unknown): boolean {
    const stamped = (config as any)?.[EPOCH_KEY];

    return typeof stamped === 'number' && stamped !== epoch;
}

/**
 * The drop. A promise that never settles — see the file header for why this is
 * not a rejection.
 */
export function dropSupersededResponse(what: string): Promise<never> {
    console.debug(`[tenant] dropped ${what} issued before the organisation switch`);

    return new Promise<never>(() => { /* deliberately never settles */ });
}

/**
 * Record the tenant this response was served under, if it carries one.
 *
 * MUST be called only for responses of the current epoch. A stale response
 * echoes organisation A, and recording it would put A's name back into the
 * chrome moments after the user switched to B — the precise lie this whole file
 * is built to prevent.
 */
export function recordServerTenant(response: AxiosResponse): void {
    const echoed = echoedTenantId(response);

    if (echoed !== null) {
        serverTenant.value = echoed;
    }
}

/** Forget the bound tenant — on sign-out, and at the start of a switch. */
export function forgetServerTenant(): void {
    serverTenant.value = null;
}

function echoedTenantId(response: AxiosResponse): number | null {
    const headers: any = response?.headers;

    for (const name of TENANT_HEADERS) {
        // Axios normalises header names to lower case, but a plain object of
        // headers (a mocked response, a non-XHR adapter) may not be normalised
        // at all — so both spellings are read.
        const raw = typeof headers?.get === 'function'
            ? headers.get(name)
            : (headers?.[name] ?? headers?.[name.toUpperCase()]);

        const parsed = asMasjidId(raw);
        if (parsed !== null) return parsed;
    }

    // Body fallback, for an API surface that echoes in the envelope instead of
    // in a header. `meta` rather than `data`, because `data` is the payload the
    // screen renders and a tenant key inside it would collide with a resource
    // that legitimately has one.
    const meta: any = (response?.data as any)?.meta;

    return asMasjidId(meta?.tenant?.id ?? meta?.tenant ?? meta?.masjid_id);
}

/**
 * A tenant id or nothing. Anything unparsable is nothing: a malformed echo must
 * leave the chrome showing the last thing it knew, never blank it and never
 * raise a mismatch against a number that was never sent.
 */
function asMasjidId(raw: unknown): number | null {
    if (raw === null || raw === undefined || raw === '') return null;

    const value = Number(raw);

    return Number.isInteger(value) && value > 0 ? value : null;
}
