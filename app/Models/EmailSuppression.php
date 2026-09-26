<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use App\Services\Broadcast\EmailSuppressionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EmailSuppression — an address that must not receive BROADCAST email, held
 * OUTSIDE the contact row so it cannot be defeated by deleting one (T-042c).
 *
 * The sibling of App\Models\SmsSuppression, and deliberately the same shape:
 * same absent foreign key, same released-never-deleted rule, same per-tenant
 * scope. A second vocabulary for the same obligation is how the two channels
 * drift apart.
 *
 * The whole point is the absent foreign key. `email_suppressions` references no
 * contact: it is keyed on the normalised address, the one identifier that
 * survives `ContactsController::merge`'s `forceDelete()`, a CSV re-import, and
 * somebody re-adding a person by hand next month. `contacts` is mortal; the
 * opt-out is not.
 *
 * ## What it suppresses, and what it must never suppress
 *
 * Broadcast email from ONE organisation. Not one message — a per-message opt-out
 * is not an opt-out, because the next snowstorm notice arrives anyway and the
 * organisation has failed the request. Not platform-wide — consent is per tenant.
 *
 * And **not transactional mail**. Tax receipts, annual statements, registration
 * confirmations, replies to a message the person sent, family sign-in codes and
 * account-access emails are things a person asked for by acting, and they are
 * unaffected here structurally rather than by convention: this model is read in
 * exactly one place, `BroadcastAudienceResolver::emailAudience()`. Adding a
 * `MessageSending` listener or a check inside a Mailable would swallow a donor's
 * receipt, and it is the single most damaging change that could be made to this
 * feature. `GroupUpdateNudgeMail` is deliberately out of scope too: it is
 * roster-bound, tied to one child's class, and a parent who wants it stopped
 * leaves the class.
 *
 * ## Rows are released, never deleted
 *
 * A subscriber who re-subscribes from a link sent to their own mailbox gets
 * `released_at` stamped and the row kept
 * (App\Services\Broadcast\EmailSuppressionService::release). Deleting it would
 * destroy the evidence that the unsubscribe was honoured — the exact record an
 * operator needs when a complaint arrives — and would let a later
 * re-unsubscribe write a second contradictory row instead of updating this one.
 *
 * **An admin may not release an OPT-OUT.** There is no admin endpoint and no
 * service caller outside the public unsubscribe controller that releases one,
 * for the same reason `SmsConsentService::grant()` refuses a suppressed number:
 * a staff button that re-enables mail to somebody who opted out is the button
 * that turns a compliance obligation into a complaint.
 *
 * The one row staff MAY lift is an import's `not_opted_in` precaution, which
 * records no request from anybody — only that the old platform never had
 * consent. The person it silences never receives a broadcast, so the
 * subscriber's own link can never reach them; without a staff path "never
 * opted in on Wix" would mean "can never opt in". Lifting it requires the
 * staff member's evidence of consent given in Manara and is written onto the
 * row (`release_source`, `release_evidence`, `released_by_user_id`;
 * EmailSuppressionService::liftPrecaution). Every other reason, `bounce`
 * included, is still released only by the subscriber.
 *
 * **The one deletion.** An import's undo deletes the rows that very run
 * INSERTED as precautions (`not_opted_in`, `bounce`), recorded row by row in
 * `import_links`, because undoing the run means the import never happened and a
 * released row would instead read as a decision somebody made. Opt-outs an
 * import wrote survive its undo unless the operator explicitly says the run
 * went into the wrong organisation (`--remove-opt-outs`). Nothing else deletes
 * a row (EmailSuppressionService::forgetWrittenByImport).
 *
 * ## Tenant scoping
 *
 * BelongsToMasjid, per .claude/rules/tenant-scoping.md, with a cross-tenant
 * suite in tests/Feature/EmailSuppressionTenantIsolationTest.php. Two
 * consequences worth stating:
 *
 *  - The public unsubscribe landing runs UNBOUND (routes/web.php, like the
 *    Stripe webhook), where the scope adds no constraint — so it resolves the
 *    tenant from the encrypted token in the link and then writes an explicit
 *    masjid_id. The creating hook only overrides masjid_id when a tenant IS
 *    bound, so nothing is silently rewritten.
 *  - During a send the tenant IS bound, so the audience resolver sees exactly
 *    this masjid's suppressions and never another's.
 */
class EmailSuppression extends Model
{
    use HasFactory, BelongsToMasjid;

    /** The person clicked the unsubscribe link in a broadcast email. */
    public const REASON_UNSUBSCRIBE_LINK = 'unsubscribe_link';

    /** An operator recorded a request made some other way (by phone, in person). */
    public const REASON_MANUAL = 'manual';

    /**
     * The relay reported the address as permanently undeliverable — or, for a
     * contact imported from another platform, that platform had.
     */
    public const REASON_BOUNCE = 'bounce';

    /**
     * The person unsubscribed on the platform this organisation's list was
     * imported from (App\Services\Imports\WixContactImport). A real opt-out,
     * made before Manara held the list; it is honoured exactly like one made
     * here and outlives the import (its undo keeps it).
     */
    public const REASON_IMPORTED_OPT_OUT = 'imported_opt_out';

    /** The person marked the organisation's email as spam on the platform it was imported from. */
    public const REASON_COMPLAINT = 'complaint';

    /**
     * Written IN ADVANCE by an import, for an address that never opted in on
     * the platform it came from (never subscribed, pending, or no longer
     * receiving mail there). Not a request from the person: it keeps the
     * import from opting anybody in. The owner's rule for the MEC migration
     * (DECISIONS.md 2026-09-25, "Everyone, most blocked").
     */
    public const REASON_NOT_OPTED_IN = 'not_opted_in';

    /**
     * The reasons an import writes as its OWN precaution rather than on the
     * person's request. Shown differently in the directory, and removed by the
     * undo of the run that inserted them.
     */
    public const PRECAUTION_REASONS = [self::REASON_NOT_OPTED_IN, self::REASON_BOUNCE];

    /** `release_source` when staff recorded consent given in Manara (see liftPrecaution). */
    public const RELEASE_STAFF_RECORDED_CONSENT = 'staff_recorded_consent';

    /**
     * A HOLD, not an opt-out: the order-history import created a contact for a
     * Wix buyer Manara had no consent record for, and suppressed the address so
     * no broadcast reaches somebody who never subscribed (DECISIONS.md
     * 2026-09-25, "Wix order history"; the owner's contact rule, "Everyone, most
     * blocked"). Nobody asked for it, so it is the one reason whose row may be
     * removed rather than released: `crm:import-wix-orders --undo` deletes the
     * hold it created when it deletes the contact it created, and only while the
     * row still carries this reason and no live contact holds the address.
     *
     * The Wix contact import is meant to run FIRST: then almost every buyer is
     * already a contact carrying their Wix consent, the order import links to
     * them, and this hold is written only for a buyer that import did not bring
     * over. Run the other way round, a buyer who is subscribed on Wix stays held
     * until someone decides otherwise, which errs towards not mailing. A later
     * consent decision may lift a hold of THIS reason; every other reason keeps
     * the release-only rule above.
     */
    public const REASON_ORDER_HISTORY_HOLD = 'order_history_import';

    protected $fillable = [
        'masjid_id',
        'email_normalized',
        'reason',
        'broadcast_id',
        'suppressed_at',
        'released_at',
        'release_source',
        'release_evidence',
        'released_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** Suppressions currently in force — a released row no longer blocks. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    /**
     * Normalise before matching: the key is the lower-cased, trimmed address or
     * it is nothing. An unnormalisable value matches the empty string, which no
     * row carries, rather than matching everything.
     */
    public function scopeForAddress(Builder $query, ?string $raw): Builder
    {
        return $query->where('email_normalized', EmailSuppressionService::normalize($raw) ?? '');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }
}
