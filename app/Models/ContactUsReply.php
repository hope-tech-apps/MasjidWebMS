<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reply staff sent back to somebody who wrote in through the contact form
 * (PLAN T-042d).
 *
 * ## This model deliberately does NOT use BelongsToMasjid
 *
 * `contact_us_replies` carries no `masjid_id`, and that is the whole reason it
 * is exempt rather than an oversight. Tenancy for this slice is hand-scoped
 * three joins deep — ContactUsMessage -> ContactUsAccount -> MobileAppUser ->
 * masjid_id — in ContactRequestsController and in both public intake
 * controllers. MobileAppUser is itself on TenantScopingCoverageTest's
 * HAND_SCOPED_LEGACY list for exactly that reason: the public mobile API never
 * runs the tenant middleware, so no tenant is ever bound on the write path.
 *
 * Giving this table a `masjid_id` would oblige the model to take the trait
 * (TenantScopingCoverageTest layer 4 fails any NEW model that carries the column
 * without it), and the resulting global scope would silently rewrite those
 * hand-written queries, including the unauthenticated ones. Scoping a reply
 * through its parent message keeps the existing guarantee exactly as it is:
 * a reply is reachable only via a message the caller could already read.
 * `ContactUsReplyTenantIsolationTest` pins that, and the FK cascade means a
 * deleted message takes its replies with it.
 *
 * ## Three timestamps, three different facts
 *
 * `created_at` says an admin composed this. `sending_at` says a request has
 * CLAIMED the right to send it and is at the relay now. `sent_at` says the
 * mailer took it. The gap between the second and the third is the whole reason
 * this table exists in the shape it does — see the two migration docblocks —
 * and collapsing any pair of them is how a member of the public gets the same
 * reply twice, or gets nothing while the office reads "answered".
 *
 * `isDelivered()` and `isBeingSent()` are the only questions callers should
 * ask, so nobody has to remember which column carries which truth.
 *
 * @property int $contact_us_message_id
 * @property string $body
 * @property string $sent_to
 * @property int|null $actor_user_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property string|null $idempotency_key
 * @property \Illuminate\Support\Carbon|null $sending_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class ContactUsReply extends Model
{
    /**
     * The unique index behind the double-send guard. Two simultaneous clicks
     * race the INSERT and the loser lands on this as a duplicate-key error,
     * which the reply path must answer as a replay, never a 500. MySQL's error
     * names this index; SQLite's names the two columns instead — the same
     * asymmetry FormResponse::CLIENT_KEY_UNIQUE_INDEX documents.
     */
    public const IDEMPOTENCY_UNIQUE_INDEX = 'contact_us_replies_msg_idem_unique';

    /**
     * The index decides which request owns the ROW. It cannot decide which
     * request owns the SEND — the loser of the race re-reads the winner's row
     * while the winner is still inside its SMTP round-trip. `sending_at` is
     * what settles that, and it is claimed by a conditional UPDATE before the
     * mailer is called. See ContactRequestsController::claimForSending().
     */

    protected $fillable = [
        'contact_us_message_id',
        'body',
        'sent_to',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'idempotency_key',
        'sending_at',
        'sent_at',
    ];

    protected $casts = [
        'sending_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /**
     * The double-send guard's key is the CLIENT's bookkeeping and has no use on
     * any screen — the admin inbox serialises whole reply rows on the listing
     * and the detail read, and this would ride along on both for no reason.
     * Same reading as FormResponse::$hidden for its replay digest.
     */
    protected $hidden = [
        'idempotency_key',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ContactUsMessage::class, 'contact_us_message_id');
    }

    /**
     * The staff member who wrote it, or null once that account is deleted.
     * Read `actor_name` for display — it survives the deletion, this does not.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Whether the mailer actually accepted this reply. See the class docblock. */
    public function isDelivered(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Whether some request currently holds the right to send this reply.
     *
     * A delivered reply is never "being sent": the claim is released the moment
     * the mailer answers, either way. A row that reads true here long after the
     * fact is a request that died mid-send, which is why the claim expires —
     * see ContactRequestsController::SEND_CLAIM_TTL_SECONDS.
     */
    public function isBeingSent(): bool
    {
        return $this->sending_at !== null && $this->sent_at === null;
    }
}
