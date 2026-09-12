<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message somebody sent through the contact form, from the website
 * (Api\V1\ContactUsController) or the mobile app (Mobile\ContactUsController).
 *
 * ## Triage is a LABEL, not a state machine
 *
 * `answered_at` may be set and cleared in any order and any number of times, on
 * purpose — the same reasoning .claude/rules/appointments.md records for
 * AppointmentRequest::STATUSES. Somebody answers by phone and ticks the box; a
 * week later the sender writes back on the same thread and staff untick it.
 * Nothing here may grow a transition guard.
 *
 * The three answered columns move together and only ever through
 * `markAnswered()` / `markUnanswered()`, so "answered but by nobody" and
 * "answered by someone at no particular time" are not reachable states.
 *
 * ## Tenancy is hand-scoped, and deliberately so
 *
 * This model has no `masjid_id` and does NOT use BelongsToMasjid. The tenant is
 * resolved through `contacter.mobileAppUser.masjid_id` by every query that
 * touches it. MobileAppUser sits on TenantScopingCoverageTest's
 * HAND_SCOPED_LEGACY list because the public mobile API never runs the tenant
 * middleware — there is no bound tenant on the write path for a global scope to
 * read. See .claude/rules/tenant-scoping.md and the T-042d migrations.
 */
class ContactUsMessage extends Model
{
    protected $fillable = [
        'contact_us_account_id',
        'contact_us_reason_id',
        'message',
        'answered_at',
        'answered_by_user_id',
        'answered_by_name',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
    ];

    public function contacter(): BelongsTo
    {
        return $this->belongsTo(ContactUsAccount::class, 'contact_us_account_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ContactUsReason::class, 'contact_us_reason_id');
    }

    /**
     * Every reply staff have sent on this thread, oldest first.
     *
     * Ordered in the relation rather than at each call site so the admin screen
     * and any later reader agree on what "the first reply" means. Includes
     * replies whose `sent_at` is null — a reply that was recorded but never
     * delivered is precisely the thing the next person to open this message
     * needs to see.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ContactUsReply::class)->oldest('id');
    }

    /**
     * The staff member recorded as having answered, or null once that account
     * is deleted. Read `answered_by_name` for display — it is the snapshot that
     * survives.
     */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    /**
     * Record that this message has been dealt with, by whom, and when.
     *
     * The name is snapshotted here rather than read back through the FK so the
     * answer survives the staff member being deleted — the audit argument
     * .claude/rules/auth-permissions.md makes for contact_login_events. `$at`
     * is a parameter because the reply path stamps the moment the MAILER
     * accepted the reply, which is not necessarily the moment this is called.
     */
    public function markAnswered(?User $actor, ?\DateTimeInterface $at = null): void
    {
        $this->forceFill([
            'answered_at' => $at ?? now(),
            'answered_by_user_id' => $actor?->id,
            'answered_by_name' => $actor?->name,
        ])->save();
    }

    /**
     * Put the message back in the queue. Replies are NOT removed: the history of
     * what was said is a fact, and only the triage label is being changed.
     */
    public function markUnanswered(): void
    {
        $this->forceFill([
            'answered_at' => null,
            'answered_by_user_id' => null,
            'answered_by_name' => null,
        ])->save();
    }
}
