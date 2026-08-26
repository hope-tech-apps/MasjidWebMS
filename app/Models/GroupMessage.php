<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupMessage — one message inside a group thread (PLAN T-005c).
 *
 * Body text only; the feed owns media and thread attachments are deferred
 * entirely. WHO may write one is "whoever may READ the thread, holding the
 * roster permission" — decided by App\Support\GroupAudience plus the
 * `permission:manage contacts` route gate, never here.
 *
 * THE AUTHOR IS A STAFF USER OR A GUARDIAN CONTACT — never both, sometimes
 * neither (see booted()).
 *
 * This docblock used to say the author is a `User` because "a Contact cannot
 * authenticate anywhere in this application, so attributing a message to one
 * would record a claim the server never verified". That reason was true and is
 * now obsolete: T-015c gave a Contact its own guard, its own token and its own
 * realm, so a parent's message IS a verified claim — about a principal the
 * server authenticated itself. What must never happen is a message attributed
 * to a contact the request did not authenticate AS, which is why
 * `author_contact_id` is written from the token and never from the payload.
 *
 * `authorLabel()` and `authorIsParent()` are the ONE place either principal is
 * turned into something a client can render. Both serializers (staff and
 * family) go through them, so a parent's reply cannot show a name in one
 * surface and a blank in the other.
 *
 * No SoftDeletes: deletion follows the THREAD. Hiding a conversation is the
 * thread's soft delete; destroying it is the retention purge; there is no
 * per-message eraser to quietly rewrite what was said to a parent.
 *
 * Tenant-scoped by a denormalised masjid_id (BelongsToMasjid), so message
 * queries scope without joining through threads.
 */
class GroupMessage extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Writing a message bumps the thread's updated_at, so "recently active
     * first" in the thread list is one ORDER BY on a real column instead of a
     * MAX() join per page.
     */
    protected $touches = ['thread'];

    protected $fillable = [
        'masjid_id',
        'group_thread_id',
        'author_user_id',
        'author_contact_id',
        'body',
    ];

    /**
     * NEVER TWO AUTHORS. Enforced here rather than as a database CHECK because
     * the suite runs on SQLite and production on MySQL, and a rule that exists
     * on only one of them is a rule that gets discovered in production.
     *
     * Deliberately "at most one", not "exactly one". A message with NO author is
     * a real and necessary state: `author_user_id` is nulled when a staff
     * account is deleted and `author_contact_id` when a contact is, because what
     * was said to a family must survive a directory edit with its attribution
     * softened rather than the message vanishing. Requiring an author would make
     * deleting a staff member fail on every message they ever wrote.
     */
    protected static function booted(): void
    {
        static::saving(function (self $message): void {
            if ($message->author_user_id !== null && $message->author_contact_id !== null) {
                throw new \LogicException(
                    'A group message cannot be written by a staff user and a parent at once.'
                );
            }
        });
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(GroupThread::class, 'group_thread_id');
    }

    /** The admin account that wrote it; null once that account is deleted. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** The guardian who wrote it; null once that contact is deleted. */
    public function authorContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'author_contact_id');
    }

    /** True when a parent wrote this, so a client can side the message. */
    public function authorIsParent(): bool
    {
        return $this->author_contact_id !== null;
    }

    /**
     * A NAME, never an id — `users.id` is an internal staff identifier and a
     * parent has nothing to do with it, and the reverse holds just as firmly.
     *
     * Null when the account or contact behind a message has since been deleted.
     * The message stays; only its attribution softens.
     */
    public function authorLabel(): ?string
    {
        if ($this->authorIsParent()) {
            $contact = $this->authorContact;

            if ($contact === null) {
                return null;
            }

            return trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: null;
        }

        return $this->author?->name;
    }
}
