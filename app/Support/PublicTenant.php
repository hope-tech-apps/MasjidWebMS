<?php

namespace App\Support;

use App\Models\Masjid;

/**
 * "Is the organisation named by this request still a live organisation?" — the
 * one question every unauthenticated `/api/v1` endpoint has to ask before it
 * reads or writes a single row for it.
 *
 * ## Why this exists as its own thing
 *
 * On these routes the tenant arrives in the `masjid-id` HEADER and the tenant
 * middleware never runs, so `BelongsToMasjid`'s global scope adds no filter at
 * all (.claude/rules/tenant-scoping.md). Every endpoint therefore hand-filters
 * `masjid_id` — and hand-filtering answers "does this row belong to masjid 7?"
 * while leaving "does masjid 7 still exist?" unasked. `masjids` SOFT-deletes,
 * so an offboarded organisation's id keeps matching its own rows forever.
 *
 * Measured on this branch before the fix: create an offering under masjid A,
 * `$masjidA->delete()`, then
 *
 *     GET  /api/v1/offerings/p13            masjid-id: A -> 200
 *     POST /api/v1/offerings/p13/quote      masjid-id: A -> 200
 *     POST /api/v1/offerings/p13/register   masjid-id: A -> 200  {"status":"confirmed"}
 *
 * — a real registration row, its answers stored as a form_response, and the
 * offering's seat counter incremented, all for an organisation that has been
 * offboarded. With a PRICED plan the money leg then fails closed one layer
 * down, because RegistrationCheckoutService resolves the org with
 * `Masjid::find()` and that excludes soft-deleted rows: no Checkout Session
 * opens, and a family is left holding a phantom pending seat instead. That one
 * guard, deep in the money layer, was the only thing between an offboarded
 * organisation and a charge — every layer above it behaved as though the
 * organisation were live (PublicTenantLifecycleTest pins both halves).
 *
 * `ZakatCalculatorController`, `ContactUsController`, `AppointmentRequestsController`
 * and `PhotoGalleryController` already asked the question, each with its own
 * inline copy of the predicate. This is that predicate named once, so the
 * NEXT public endpoint has something to find and `PublicTenantLifecycleTest`
 * has one thing to point at.
 *
 * ## The predicate is deliberately over-specified
 *
 * `Masjid::query()` already excludes trashed rows through the SoftDeletes
 * global scope, so the `whereNull('deleted_at')` below is redundant TODAY. It
 * is written out anyway because the whole bug class here is a filter that is
 * present by implication and absent in fact: if someone ever reaches for
 * `withTrashed()`, drops the trait, or copies this query somewhere the scope is
 * not applied, the intent must survive the copy. A redundant WHERE costs
 * nothing; an implicit one cost two production leaks this month.
 *
 * ## What this is NOT
 *
 * Not an authorization check, and not the directory-listing gate. An UNLISTED
 * masjid (`listed_at` null) is a live organisation that simply does not appear
 * in the mobile picker — its pages, forms and registrations must keep working
 * (Masjid::scopeListed). This asks only whether the organisation still exists.
 */
final class PublicTenant
{
    /**
     * True when this id names an organisation that has not been offboarded.
     *
     * A non-positive id is always false: `0`, `''` and a missing header all
     * int-cast to 0, and the falsy-bypass shape is exactly how the 2026-08-11
     * `SearchableTrait` leak served 14 rows across two tenants. Callers still
     * distinguish "no organisation named" (400) from "that organisation is not
     * available" (404) themselves — that is a response contract, not a fact
     * about the database.
     */
    public static function exists(int $masjidId): bool
    {
        if ($masjidId <= 0) {
            return false;
        }

        return Masjid::query()
            ->whereKey($masjidId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * True when this id names a live organisation whose CRM IS SWITCHED ON —
     * the question every public REGISTRATION endpoint has to ask, on top of
     * `exists()`.
     *
     * ## Why registration asks a second question
     *
     * `masjids.crm_enabled` gates the CRM route group in routes/admin.php
     * (`App\Http\Middleware\EnsureCrmEnabled`), and the offering / fee-plan /
     * registration admin surfaces are INSIDE that group. Until 2026-08-12 no
     * `/api/v1` endpoint consulted the flag, so with `crm_enabled = false`, as
     * the organisation's own admin:
     *
     *     PUBLIC  GET  /api/v1/offerings/{slug}         200, registration_state "open"
     *     PUBLIC  POST /api/v1/offerings/{slug}/quote   200, amount_due_minor 15000
     *     PUBLIC  POST /api/v1/offerings/{slug}/register
     *                                                   200, live checkout_url on the
     *                                                   org's connected account
     *                                                   -> 1 Contact + 1 Registration written
     *     ADMIN   GET  /api/admin/masjids/{id}/offerings              403
     *     ADMIN   GET  .../offerings/{id}/registrations               403
     *
     * — money taken and rows written for an organisation whose staff cannot see
     * any of it.
     *
     * ## Why the gate belongs on the PUBLIC side, not off the admin side
     *
     * The tempting "fix" is to drop `crm` from the offerings routes so the org
     * can at least see what is being sold for it. That is the wrong direction,
     * and measurably so: `AdminDashboard\RegistrationsController@index`
     * eager-loads `contact` and `registrants.contact` and `show` adds
     * `formResponse` — the roster IS the member directory, with names, emails,
     * phone numbers and intake answers. Ungating it would turn the roster
     * screen into an unguarded window onto the contacts the `crm` gate exists to
     * close, which is worse than the defect it fixed.
     *
     * Registration is not adjacent to the CRM, it is INSIDE it: `register()`
     * find-or-creates `Contact` rows in the member directory, `confirm()`
     * materialises `group_memberships`, and the charge runs on the connected
     * account whose onboarding endpoints are themselves behind this same gate.
     * `.claude/rules/auth-permissions.md` states it plainly — "the whole CRM
     * (member directory + money path) is OFF by default" — so an organisation
     * whose CRM has never been switched on has no registration feature to
     * expose, and one whose CRM has been switched OFF has had it withdrawn.
     *
     * ## Why not the `crm` middleware itself
     *
     * `EnsureCrmEnabled` reads `TenantContext` / the `{masjid_id}` route param
     * and aborts 403 "The CRM is not enabled for this masjid." Neither half
     * fits here: `/api/v1` never runs the tenant middleware and takes its
     * organisation from a HEADER, and a 403 naming an internal feature flag
     * tells an anonymous caller something about the organisation's account
     * standing that is none of their business. So the public surface answers
     * with the SAME 404 a missing offering gets — a caller cannot distinguish
     * "no such offering", "another org's offering", "switched off",
     * "offboarded" and "CRM not enabled", which is the same
     * no-probing contract the rest of this class holds.
     *
     * Deliberately NOT applied to the other public endpoints. A masjid without
     * a CRM still has prayer times, announcements, pages, a contact form and a
     * zakat calculator; this is the registration surface's question alone.
     */
    public static function crmEnabled(int $masjidId): bool
    {
        if ($masjidId <= 0) {
            return false;
        }

        return Masjid::query()
            ->whereKey($masjidId)
            ->whereNull('deleted_at')
            ->where('crm_enabled', true)
            ->exists();
    }
}
