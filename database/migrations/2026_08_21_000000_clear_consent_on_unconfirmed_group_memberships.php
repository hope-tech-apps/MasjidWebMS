<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ===========================================================================
 * ERASE CONSENT FROM EVERY ROSTER ROW NOBODY HAS STOOD BEHIND.
 * ===========================================================================
 *
 * `GroupMembership::unconfirm()` now clears `consent_granted_at` and
 * `consent_scope` when a merge re-opens a row. That fixes every row written from
 * this deploy onwards and reaches NOTHING already on disk — and the rows already
 * on disk are the ones the defect produced. Any tenant that has ever run a
 * contact merge over a consented guardian edge is carrying them; a school
 * provisioned fresh is not.
 *
 * `GroupMembership::hasConsent()` now also refuses an unconfirmed row at every
 * READ. That is necessary and it is NOT sufficient, which is the whole argument
 * for this migration:
 *
 * ---------------------------------------------------------------------------
 * THE READ GATE DOES NOT CLOSE THE MEASURED PATH. ONE CONFIRM CLICK DOES.
 * ---------------------------------------------------------------------------
 *
 * Measured against the fixed read gate, on a row left in the pre-fix state:
 *
 *     GET  …/consent -> 200 {"scope":null}          <- the gate holds
 *     CONFIRM        -> {"confirmed":1,"skipped":0} <- an ordinary roster act
 *     edge after     -> {"provenance":"confirmed","consent_scope":"media"}
 *     GET  …/consent -> 200 {"scope":"media","covers_media":true}
 *     family feed    -> 200 [{"title":"Class photograph", …}]
 *
 * The gate suppresses the row until somebody confirms the CLAIM — a decision
 * about a RELATIONSHIP, made on a roster screen that says nothing about
 * photographs — and the stale `media` grant then re-arms itself with no second
 * decision. That is byte-for-byte the failure `GroupConsentController::update()`
 * exists to prevent ("the moment the claim is confirmed, the class feed and the
 * photograph bytes open in the same instant as the records, with no second
 * decision in between"). Only removing the bytes closes it.
 *
 * ---------------------------------------------------------------------------
 * THE POLICY, AND WHY IT IS THE DESTRUCTIVE ONE
 * ---------------------------------------------------------------------------
 *
 * Clear both columns on every `group_memberships` row whose `provenance` is not
 * exactly `confirmed`. Three deliberate choices:
 *
 *  1. ERASE RATHER THAN KEEP-AND-SUPPRESS. What is being erased is a permission
 *     to publish photographs of a named child to a named adult, recorded against
 *     a pairing this organisation has not stood behind — and, in the case that
 *     produced most of these rows, recorded about a DIFFERENT child before a
 *     merge moved the row. Re-asking a parent for consent costs one conversation
 *     the office was going to have anyway when it confirms the claim. Inheriting
 *     it costs a photograph of a child reaching somebody nobody vouched for.
 *     There is no symmetric risk here to weigh: the failure modes are "ask
 *     again" against "disclose to a stranger".
 *
 *  2. EVERY UNCONFIRMED ROW, NOT ONLY THE ONES A MERGE TOUCHED. There is no
 *     column recording that a merge re-pointed a row — `unconfirm()` clears
 *     `confirmed_by_user_id`, so the rows this is aimed at are indistinguishable
 *     from a claim that arrived that way. Guessing which is which would be the
 *     round's own failure shape: a sentence about one surface believed about
 *     all of them. The wider sweep is also correct on its own terms, because
 *     an unconfirmed row carrying consent is a state NO writer in this
 *     application may produce, from any path — `update()` 422s on it — so every
 *     such row is corrupt however it got there.
 *
 *  3. NOT LIMITED TO `role = guardian`, though only a guardian row can carry
 *     meaningful consent. A participant row with the columns set is data that
 *     should not exist, and leaving it for a future `role` change to activate is
 *     the same bet this migration is refusing.
 *
 * `provenance IS NULL` is included for the reason `scopePendingClaims()` spells
 * out: SQL evaluates `provenance != 'confirmed'` as UNKNOWN for a NULL and drops
 * the row, so a NULL would read as pending to every human and be invisible to
 * the one statement meant to clean it. MEASURED: it is unreachable today — the
 * column is NOT NULL, and an attempt to write one throws
 * (`UnconfirmedConsentTest::a_null_provenance_is_unreachable_today…` pins that
 * premise, so the day the constraint is relaxed the clause stops being
 * decoration and the test says so).
 *
 * NO DRIVER GUARD IS NEEDED (.claude/rules/migrations.md): this is Blueprint-free
 * but also raw-SQL-free — a query-builder UPDATE, which Laravel emits correctly
 * for MySQL and for the suite's SQLite alike.
 *
 * WRITTEN THROUGH THE QUERY BUILDER, NOT THE MODEL, on purpose. `GroupMembership`
 * is `BelongsToMasjid`, so a model query would be scoped to a bound tenant that
 * a migration does not have — silently cleaning one organisation, or none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('group_memberships')
            ->where(function ($q) {
                $q->where('provenance', '!=', 'confirmed')
                    ->orWhereNull('provenance');
            })
            ->where(function ($q) {
                $q->whereNotNull('consent_granted_at')
                    ->orWhereNotNull('consent_scope');
            })
            ->update([
                'consent_granted_at' => null,
                'consent_scope' => null,
            ]);
    }

    /**
     * Deliberately a no-op, and this is not laziness.
     *
     * The rows' previous values are gone; a `down()` cannot invent them. The
     * only thing it could do is nothing, and saying so here is better than a
     * plausible-looking method that restores something else. Rolling back the
     * deploy restores the CODE, and the read gate in `hasConsent()` then keeps
     * the erased rows granting exactly what they granted before — nothing.
     */
    public function down(): void
    {
        // Irreversible by design — see the docblock.
    }
};
