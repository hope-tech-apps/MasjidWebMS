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
 * ## The organisation is a COLUMN now, and still hand-scoped
 *
 * `masjid_id` names the organisation the message was sent TO. It used to be
 * derived — contacter -> mobileAppUser -> masjid_id — and that derivation
 * became wrong the day the apps gained an organisation switcher: a member
 * standing in a listed child writes to the child while their device stays
 * registered with the parent, so the message was emailed to one organisation
 * and listed under another. See the
 * add_masjid_id_to_contact_us_messages_table migration.
 *
 * The model still does NOT use BelongsToMasjid, and it is on
 * TenantScopingCoverageTest's HAND_SCOPED_LEGACY roster for the reason the
 * previous migration gave: the two intake controllers are UNAUTHENTICATED and
 * never bind a tenant, so a global scope would add no constraint on the write
 * path and the `creating` hook would stamp nothing. Nothing about the boundary
 * would improve; the scoping would merely look automatic while remaining hand
 * written. `ContactRequestsController::ownedBy()` is still the only reader, and
 * `ContactUsReplyTenantIsolationTest` is still what proves it.
 *
 * See .claude/rules/tenant-scoping.md.
 */
class ContactUsMessage extends Model
{
    protected $fillable = [
        'masjid_id',
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

    /**
     * Last-resort fill for the organisation, from the sender's device.
     *
     * The two intake controllers set `masjid_id` explicitly, from the
     * organisation they resolved and emailed — that is the contract and it wins
     * here, because this hook only fires when the column is still unset.
     *
     * The hook exists for every OTHER writer: a console command, a seeder, a
     * test fixture. A message with no organisation is not a validation error
     * anywhere; it is a row that appears in no inbox, i.e. a message that
     * arrived and silently vanished. Deriving the old way is strictly better
     * than leaving it null, and it is what the row would have shown before this
     * column existed.
     *
     * Deliberately NOT a fallback for a switched sender: the derivation is the
     * thing the switcher broke. Only an explicit write is right there.
     */
    protected static function booted(): void
    {
        static::creating(function (ContactUsMessage $message): void {
            if ($message->masjid_id !== null) {
                return;
            }

            $message->masjid_id = ContactUsAccount::with('mobileAppUser')
                ->find($message->contact_us_account_id)
                ?->mobileAppUser
                ?->masjid_id;
        });
    }

    /** The organisation this message was sent to. */
    public function masjid(): BelongsTo
    {
        return $this->belongsTo(Masjid::class);
    }

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
