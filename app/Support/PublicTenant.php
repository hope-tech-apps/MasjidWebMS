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
}
