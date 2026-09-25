<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactPortalInvite — one 7-day link that lands a parent inside the portal.
 *
 * The row is the RECORD of a credential, never the credential: `token_hash` is a
 * keyed digest (see the migration for the construction and why it is keyed), the
 * plaintext exists only inside `App\Services\Family\FamilyInviteService::mint()`
 * for as long as it takes to build a URL, and nothing in this class can
 * reproduce it. That holds for BOTH doors: the emailed one hands the URL to the
 * mailer and drops it, and the SuperAdmin-only "Copy link" (2026-09-25) returns
 * it in one response body — neither ever writes it anywhere this model can see.
 *
 * `BelongsToMasjid` because an invite is tenant data of the most sensitive kind:
 * it names a family address against an organisation and it opens a specific
 * child's file. .claude/rules/tenant-scoping.md admits no unscoped CRM model, and
 * requires a cross-tenant Feature test per model —
 * `tests/Feature/FamilyPortalInviteTest.php::another_tenants_contact_cannot_be_invited`.
 *
 * ## `$fillable` is deliberately narrow
 *
 * `consumed_at` and `invalidated_at` are the two columns the single-use and
 * one-live-link guarantees rest on, so they are not mass-assignable: they are
 * written by the service, explicitly, and by nothing that takes a request body.
 * The same call `ContactLoginCode` makes about `consumed_at` and `attempts`.
 */
class ContactPortalInvite extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'contact_id',
        'login_email',
        'token_hash',
        'expires_at',
        'issued_ip',
    ];

    /**
     * The digest never travels. Belt-and-braces — nothing in either realm
     * serializes this model (the admin panel's payload is built by hand in
     * `ContactFamilyLoginController`, and the family realm never sees the row at
     * all) — but a future `toArray()` must not be the thing that publishes it.
     */
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    // ------------------------------------------------------------- predicates

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** Could this row still be exchanged for a session, ignoring the contact? */
    public function isLive(): bool
    {
        return ! $this->isConsumed() && ! $this->isInvalidated() && ! $this->isExpired();
    }

    /**
     * ONE WORD for the office panel: what happened to the last link we sent?
     *
     * Derived here rather than reconstructed in TypeScript, for the reason
     * `FamilyAccessService::state()` records: a second copy of a rule in the SPA
     * is a copy that agrees today.
     */
    public function state(): string
    {
        if ($this->isConsumed()) {
            return 'accepted';
        }

        if ($this->isInvalidated()) {
            return 'superseded';
        }

        return $this->isExpired() ? 'expired' : 'pending';
    }

    // ----------------------------------------------------------------- scopes

    /**
     * Rows that have not yet been ended by anything.
     *
     * Deliberately NOT filtered on `expires_at` — an expired row is still a row
     * this contact's live-invite bookkeeping has to see, because invalidating it
     * is what keeps `invalidated_at` honest about WHY a link stopped working. The
     * expiry test is applied at redemption, where it belongs.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->whereNull('invalidated_at');
    }

    // ----------------------------------------------------------------- writes

    /**
     * End every link this contact is still holding, and say how many.
     *
     * A STATIC ON THE MODEL rather than a method on `FamilyInviteService`, for a
     * structural reason and not a stylistic one: `FamilyAccessService` has to
     * call this from `revoke()`, from a re-address and from
     * `releaseAddressFrom()`, while `FamilyInviteService` already depends on
     * `FamilyAccessService` for the eligibility rule. Putting it on the service
     * would make those two constructor-inject each other, which the container
     * resolves by recursing until it runs out of stack.
     *
     * Called from FOUR places, all of them acts that end or move a grant:
     *
     *  - `FamilyInviteService::issue()` — one live link per contact.
     *  - `FamilyAccessService::write()` when the address CHANGES — a link mailed
     *    to the old mailbox must die at that moment, not seven days later.
     *  - `FamilyAccessService::revoke()` — beside the token delete, and for the
     *    identical reason that method already gives for deleting tokens rather
     *    than trusting the middleware: a revoked guardian should not be left
     *    holding a working credential in an inbox for the rest of its life.
     *  - `FamilyAccessService::releaseAddressFrom()` — the address is now
     *    somebody else's.
     *
     * It is a BUILDER-level update, so no model events fire and nothing here
     * needs them: the row is bookkeeping about a credential, not an audit record
     * (`contact_login_events` is the audit record, and the act that caused this
     * writes its own row there).
     *
     * `withoutMasjidScope()` with the masjid re-applied by hand, matching
     * `FamilyAccessService::guardianEdges()`: a write that ends a credential must
     * not depend on a caller having bound the tenant context correctly, and must
     * still never reach another organisation's rows.
     */
    public static function invalidateOutstandingFor(Contact $contact): int
    {
        return static::withoutMasjidScope()
            ->where('masjid_id', $contact->masjid_id)
            ->where('contact_id', $contact->getKey())
            ->outstanding()
            ->update(['invalidated_at' => now()]);
    }
}
