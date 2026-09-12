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
 * **An admin may not release one.** There is no admin endpoint and no service
 * caller outside the public unsubscribe controller, for the same reason
 * `SmsConsentService::grant()` refuses a suppressed number: a staff button that
 * re-enables mail to somebody who opted out is the button that turns a
 * compliance obligation into a complaint.
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

    /** The relay reported the address as permanently undeliverable. */
    public const REASON_BOUNCE = 'bounce';

    protected $fillable = [
        'masjid_id',
        'email_normalized',
        'reason',
        'broadcast_id',
        'suppressed_at',
        'released_at',
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
