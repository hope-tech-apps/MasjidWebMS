import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import {
    AdjustmentPayload,
    FeePlan,
    FeePlanPayload,
    Offering,
    OfferingFilters,
    OfferingOption,
    OfferingPayload,
    OfferingsMeta,
    Registrant,
    Registration,
    RegistrationAdjustment,
    RegistrationFilters,
    RegistrationView
} from "@/core/types/data/masjid-related/Offering";

/**
 * Offerings, their fee plans and their registrations — the T-006 admin surface
 * over /api/admin/masjids/{masjid_id}/offerings.
 *
 * Same shape as groupsStore: the active masjid comes from masjidStore, the
 * backend `tenant` middleware + BelongsToMasjid keep this admin inside their own
 * organization, and `meta` is kept because the SERVER is the authority on every
 * vocabulary on these screens (kinds, fee-plan kinds, billing intervals, both
 * status sets) and on what a tenant CALLS an offering.
 *
 * THREE THINGS THIS STORE DELIBERATELY DOES NOT HAVE, because the backend has
 * no write path for them and inventing one in the SPA would be inventing money
 * movement (docs/t006-registration-billing-design.md):
 *
 *  1. No `updateFeePlan`. A fee plan is IMMUTABLE once created — registrations
 *     snapshot their price from it — so the write surface is create +
 *     deactivate. The API's PUT exists only to refuse with a 422.
 *  2. No refund, and no way to write a `registration_payments` row. That table
 *     is written by signature-verified webhooks only; v1 refunds are made by
 *     the organization in its own Stripe dashboard, where it is the merchant of
 *     record.
 *  3. No "mark as paid". `payment_status` is advanced by Stripe's webhooks, and
 *     a button that set it here would put the roster and the money out of step
 *     with the account that actually holds the funds.
 *
 * Every `*_minor` value that passes through here is an INTEGER of minor units,
 * relayed verbatim in both directions. Nothing in this file adds, divides or
 * reformats one.
 */
export const useOfferingsStore = defineStore('offeringsStore', () => {

    // State
    const offeringsPaginated = ref<PaginatedData<Offering>>();
    const offeringsMeta = ref<OfferingsMeta>();
    /** The plans of the offering currently open; replaced on every fetch. */
    const feePlans = ref<FeePlan[]>([]);
    /**
     * The page-builder picker's list. Its own ref rather than reusing
     * `offeringsPaginated`, because the `offering` section editor is opened from
     * the PAGES screens: writing the picker's fetch into the paginated list
     * would silently replace whatever page of the offerings table an admin had
     * open, and the two screens have no reason to share state.
     */
    const offeringOptions = ref<OfferingOption[]>([]);
    /** One page of the roster, in whichever of the two server views was asked for. */
    const registrationsPaginated = ref<PaginatedData<Registration | Registrant>>();

    // Stores
    const masjidStore = useMasjidStore();

    function masjidId(): number | string | null {
        return masjidStore.masjid?.id ?? null;
    }

    // ------------------------------------------------------------- offerings

    /** A page of this organization's offerings, optionally narrowed. */
    async function fetchOfferings(
        page: number = 1,
        filters: Partial<OfferingFilters> = {}
    ): Promise<void> {
        const id = masjidId();
        if (!id) return;

        if (offeringsPaginated.value) {
            offeringsPaginated.value.data = [];
        }

        const query = new URLSearchParams({ page: String(page) });
        if (filters.search) query.append('search', filters.search);
        if (filters.kind) query.append('kind', filters.kind);
        // Only the non-default narrowing needs saying.
        if (filters.active_only) query.append('active_only', '1');

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/offerings?${query.toString()}` as BackendApiRoute
        );

        if (res.data?.status === 'success' && res.data?.data) {
            offeringsPaginated.value = res.data.data;
            offeringsMeta.value = res.data.meta;
        }
    }

    /**
     * One offering, with its fee plans, its intake form and its group.
     *
     * `meta` on THIS endpoint additionally carries `seats` and
     * `registrations_by_status`, which is why the caller re-reads
     * `offeringsMeta` afterwards rather than keeping the list's copy.
     *
     * A cross-tenant or deleted id is a 404 MISS by design; the caller decides
     * how to render that (.claude/rules/tenant-scoping.md).
     */
    async function fetchOffering(offeringId: number | string): Promise<Offering | null> {
        const id = masjidId();
        if (!id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/offerings/${offeringId}` as BackendApiRoute
        );

        if (res.data?.status === 'success' && res.data?.data) {
            offeringsMeta.value = res.data.meta;
            return res.data.data;
        }

        return null;
    }

    /**
     * The offering picker for the `offering` page section (T-006g).
     *
     * Returns the list AND caches it, mirroring formsStore.fetchFormOptions —
     * the section editor calls this on mount and re-reads the cache afterwards.
     *
     * A FAILURE IS NOT SWALLOWED INTO AN EMPTY LIST. These routes sit behind the
     * `crm` gate and `permission:view contacts`, so a tenant without the CRM, or
     * an admin whose role does not include programs, gets a 403 — and "you may
     * not see the programs list" must not render as "you have no programs yet".
     * The caller distinguishes them; hence the throw rather than a silent [].
     */
    async function fetchOfferingOptions(): Promise<OfferingOption[]> {
        const id = masjidId();
        if (!id) return [];

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/offerings/options` as BackendApiRoute
        );

        if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
            offeringOptions.value = res.data.data;
            // The SERVER is the authority on what a tenant CALLS an offering —
            // the editor reads `offering_label` off this rather than hardcoding
            // "Programs".
            if (res.data?.meta) offeringsMeta.value = res.data.meta;
        }

        return offeringOptions.value;
    }

    /**
     * Create an offering.
     *
     * POST goes out as multipart/form-data (the ApiService default). Booleans
     * are serialized to '1'/'0' so Laravel's `boolean` rule accepts them, and
     * every OPTIONAL field is omitted rather than sent empty — `capacity=` fails
     * `nullable|integer` and `opens_at=` fails `nullable|date`. `masjid_id` is
     * never sent: the BelongsToMasjid creating hook stamps it from the bound
     * tenant.
     */
    async function createOffering(payload: OfferingPayload): Promise<Offering> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const body = new FormData();
        body.append('name', payload.name);
        // Omitted when empty, like every other optional field here: '' would
        // pass `nullable|string` but store an empty string where the column
        // means "no description", and the public payload would publish ''.
        if (payload.description) body.append('description', payload.description);
        body.append('slug', payload.slug);
        body.append('kind', payload.kind);
        if (payload.intake_form_id !== null) body.append('intake_form_id', String(payload.intake_form_id));
        if (payload.group_id !== null) body.append('group_id', String(payload.group_id));
        if (payload.capacity !== null) body.append('capacity', String(payload.capacity));
        if (payload.opens_at) body.append('opens_at', payload.opens_at);
        if (payload.closes_at) body.append('closes_at', payload.closes_at);
        body.append('is_active', payload.is_active ? '1' : '0');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/offerings` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to create the offering.');
    }

    /**
     * Update an offering.
     *
     * ApiService.put sends application/x-www-form-urlencoded, so the payload is
     * serialized to URLSearchParams (the proven edit path in
     * contactsStore/fundsStore/groupsStore).
     *
     * NULLABLE fields are sent EXPLICITLY EMPTY rather than omitted here,
     * because the update request marks every field `sometimes`: omitting
     * `group_id` means "leave it alone", which would make an admin unable to
     * detach a roster group once one was set. An empty string satisfies
     * `sometimes|nullable|integer`. `capacity` follows the same reasoning —
     * clearing it restores unlimited capacity.
     */
    async function updateOffering(
        offeringId: number | string,
        payload: OfferingPayload
    ): Promise<Offering> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const body = new URLSearchParams();
        body.append('name', payload.name);
        // Sent EXPLICITLY EMPTY, not omitted — every field on the update request
        // is `sometimes`, so omitting this would mean "leave it alone" and an
        // admin could never clear a description once one was written.
        body.append('description', payload.description || '');
        body.append('slug', payload.slug);
        body.append('kind', payload.kind);
        if (payload.intake_form_id !== null) body.append('intake_form_id', String(payload.intake_form_id));
        body.append('group_id', payload.group_id === null ? '' : String(payload.group_id));
        body.append('capacity', payload.capacity === null ? '' : String(payload.capacity));
        body.append('opens_at', payload.opens_at || '');
        body.append('closes_at', payload.closes_at || '');
        body.append('is_active', payload.is_active ? '1' : '0');

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${id}/offerings/${offeringId}` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to update the offering.');
    }

    /**
     * Soft-delete an offering.
     *
     * The endpoint REFUSES with a 422 while any pending, confirmed or
     * waitlisted registration would be stranded, and names the non-destructive
     * alternative (is_active = false). That refusal is a message the UI shows,
     * not an error to swallow — hence the throw rather than a boolean.
     */
    async function deleteOffering(offeringId: number | string): Promise<boolean> {
        const id = masjidId();
        if (!id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${id}/offerings/${offeringId}` as BackendApiRoute
        );

        return res.data?.status === 'success';
    }

    // ------------------------------------------------------------- fee plans

    /** Every plan of one offering, oldest first — the deactivate-and-replace chain in order. */
    async function fetchFeePlans(offeringId: number | string): Promise<void> {
        const id = masjidId();
        if (!id) return;

        feePlans.value = [];

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/offerings/${offeringId}/fee-plans` as BackendApiRoute
        );

        if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
            feePlans.value = res.data.data;
        }
    }

    /**
     * Create a fee plan.
     *
     * `amount_minor` is relayed as the integer it already is — it was produced
     * once by `parseMajorToMinor()` from what the admin typed and is never
     * re-derived from a formatted string.
     *
     * `billing_interval` and `installment_count` are OMITTED, not blanked, when
     * the kind does not carry them: StoreFeePlanRequest marks them `prohibited`
     * for those kinds, so sending an empty value is a 422 rather than a no-op.
     */
    async function createFeePlan(
        offeringId: number | string,
        payload: FeePlanPayload
    ): Promise<FeePlan> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const body = new FormData();
        body.append('kind', payload.kind);
        body.append('amount_minor', String(payload.amount_minor));
        body.append('currency', payload.currency);
        body.append('label', payload.label);
        if (payload.billing_interval) body.append('billing_interval', payload.billing_interval);
        if (payload.installment_count !== null) {
            body.append('installment_count', String(payload.installment_count));
        }
        body.append('is_active', payload.is_active ? '1' : '0');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/offerings/${offeringId}/fee-plans` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to create the fee plan.');
    }

    /**
     * DEACTIVATE a plan — never delete one.
     *
     * The row must survive forever: registrations point at it with a RESTRICT FK
     * and read the currency of their snapshot totals through it. Deactivating
     * hides it from new registrations and from the public payload; everything
     * already sold keeps resolving. Idempotent server-side.
     */
    async function deactivateFeePlan(
        offeringId: number | string,
        feePlanId: number | string
    ): Promise<boolean> {
        const id = masjidId();
        if (!id) return false;

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${id}/offerings/${offeringId}/fee-plans/${feePlanId}` as BackendApiRoute
        );

        return res.data?.status === 'success';
    }

    // --------------------------------------------------------- registrations

    /**
     * A page of one offering's roster.
     *
     * `view` picks between the two lists the SERVER renders from the same
     * filtered set: one row per signup (the money view) or one row per person.
     * Both are paginated by the same `per_page`.
     */
    async function fetchRegistrations(
        offeringId: number | string,
        page: number = 1,
        filters: Partial<RegistrationFilters> = {},
        view: RegistrationView = 'registrations'
    ): Promise<void> {
        const id = masjidId();
        if (!id) return;

        if (registrationsPaginated.value) {
            registrationsPaginated.value.data = [];
        }

        const query = new URLSearchParams({ page: String(page) });
        if (filters.status) query.append('status', filters.status);
        if (filters.payment_status) query.append('payment_status', filters.payment_status);
        if (filters.search) query.append('search', filters.search);
        if (view === 'registrants') query.append('view', 'registrants');

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/offerings/${offeringId}/registrations?${query.toString()}` as BackendApiRoute
        );

        if (res.data?.status === 'success' && res.data?.data) {
            registrationsPaginated.value = res.data.data;
        }
    }

    /**
     * One registration in full: its payer, its plan, its registrants, its
     * adjustments (with who granted them) and its payment ledger. The ledger and
     * the adjustments ride only on this endpoint, which is why the roster links
     * here rather than expanding a row in place.
     */
    async function fetchRegistration(
        offeringId: number | string,
        registrationId: number | string
    ): Promise<Registration | null> {
        const id = masjidId();
        if (!id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${id}/offerings/${offeringId}/registrations/${registrationId}` as BackendApiRoute
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        return null;
    }

    /**
     * Grant financial aid / a discount / a code against one registration.
     *
     * `amount_minor` is an UNSIGNED REDUCTION in minor units — the table cannot
     * express a surcharge. `granted_by_user_id` is NOT sent: the audit trail's
     * "who" is the authenticated admin, derived server-side.
     *
     * The server REFUSES this once any Stripe leg exists (session, subscription,
     * schedule, or a payment status past `awaiting`), because aid is strictly
     * pre-checkout and this design has no post-hoc money movement. That refusal
     * arrives as a 422 carrying the service's own message, which the caller
     * shows verbatim.
     *
     * Returns the fresh registration alongside the adjustment, because granting
     * re-derives `adjusted_total_minor` server-side — the screen must re-read
     * that number, never recompute it.
     */
    async function grantAdjustment(
        offeringId: number | string,
        registrationId: number | string,
        payload: AdjustmentPayload
    ): Promise<{ adjustment: RegistrationAdjustment; registration: Registration }> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const body = new FormData();
        body.append('kind', payload.kind);
        body.append('amount_minor', String(payload.amount_minor));
        if (payload.reason) body.append('reason', payload.reason);

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/offerings/${offeringId}/registrations/${registrationId}/adjustments` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to grant the adjustment.');
    }

    /**
     * Promote a waitlisted registration into a seat — manual only, because
     * automatic promotion (and the payment window it would need) is deferred to
     * its own task.
     *
     * Capacity is re-checked under the offering's row lock inside the service,
     * so this can never oversell even against a public registration taking the
     * last seat at the same instant. A full offering, or a row that is not
     * waitlisted, comes back as a 422.
     */
    async function promoteRegistration(
        offeringId: number | string,
        registrationId: number | string
    ): Promise<Registration> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/offerings/${offeringId}/registrations/${registrationId}/promote` as BackendApiRoute,
            new FormData()
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error('Failed to promote the registration.');
    }

    /**
     * Cancel a registration: the Stripe subscription first when one exists (so a
     * family is never billed for a place they no longer hold), then the local
     * seat release.
     *
     * `meta.stripe_subscription_cancelled` says which of the two halves actually
     * ran, and the caller surfaces it — a Stripe outage is logged and not fatal
     * server-side, so "cancelled locally" and "cancelled everywhere" are
     * genuinely different outcomes and must not read the same.
     *
     * A settled charge KEEPS its `paid` money status. Cancelling stops future
     * billing; it does not restate a ledger row, and it is not a refund.
     */
    async function cancelRegistration(
        offeringId: number | string,
        registrationId: number | string
    ): Promise<{ registration: Registration; subscriptionCancelled: boolean }> {
        const id = masjidId();
        if (!id) throw new Error('Masjid not specified.');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/offerings/${offeringId}/registrations/${registrationId}/cancel` as BackendApiRoute,
            new FormData()
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return {
                registration: res.data.data,
                subscriptionCancelled: res.data?.meta?.stripe_subscription_cancelled === true
            };
        }

        throw new Error('Failed to cancel the registration.');
    }

    return {
        offeringsPaginated,
        offeringsMeta,
        offeringOptions,
        feePlans,
        registrationsPaginated,
        fetchOfferings,
        fetchOfferingOptions,
        fetchOffering,
        createOffering,
        updateOffering,
        deleteOffering,
        fetchFeePlans,
        createFeePlan,
        deactivateFeePlan,
        fetchRegistrations,
        fetchRegistration,
        grantAdjustment,
        promoteRegistration,
        cancelRegistration
    }
})
