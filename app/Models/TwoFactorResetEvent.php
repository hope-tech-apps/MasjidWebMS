<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One act of a platform operator clearing somebody ELSE'S second factor.
 *
 * This is the written-down half of the only door in the 2FA design that is not
 * opened by the account's own owner. See the migration for why the door exists
 * (a confirmed enrolment could otherwise brick an account forever) and why
 * "written down" is a precondition of it rather than a courtesy.
 *
 * NOT `BelongsToMasjid`, and that is deliberate rather than an oversight: the
 * subject is a `users` row, which is platform data with no `masjid_id` — a
 * SuperAdmin is bound to no tenant, and the stranded admin may belong to
 * several. .claude/rules/tenant-scoping.md governs CRM models; a record of a
 * platform-level act on a platform-level table is not one, and giving it a
 * tenant would mean inventing an organisation for an act that had none.
 *
 * APPEND-ONLY, enforced here rather than promised in a comment — same shape as
 * ContactLoginEvent, and with the same honest limits: the hooks below fire for
 * model instances only, so `TwoFactorResetEvent::where(...)->update([...])` and
 * raw SQL still get through. Nothing in this application does either. What is
 * NOT enforced at the database level, also deliberately: an erasure request has
 * to be able to scrub `user_email` / `performed_by_label`, and a trigger that
 * made this table immutable would make the users table undeletable with it —
 * we have shipped that bug once already.
 */
class TwoFactorResetEvent extends Model
{
    /** A signed-in SuperAdmin who passed their own second factor to do it. */
    public const CHANNEL_DASHBOARD = 'dashboard';

    /**
     * The platform operator, on the server, via `two-factor:reset`.
     *
     * Kept because the dashboard door cannot answer one case: the LAST
     * SuperAdmin, stranded. The endpoint refuses self-service by design, so
     * with nobody else holding the role there is no browser that can help. The
     * console command demands `--by="A Human Name"` for exactly this row.
     */
    public const CHANNEL_CONSOLE = 'console';

    /** Rows carry a single `created_at`; nothing ever updates one. */
    public const UPDATED_AT = null;

    /**
     * Everything is fillable because nothing here comes from a request body.
     * `reason` is the one operator-typed value, and it arrives validated
     * through ResetStrandedTwoFactorRequest; every other column is derived
     * from the authenticated actor and the subject row.
     */
    protected $fillable = [
        'user_id',
        'user_email',
        'performed_by_user_id',
        'performed_by_label',
        'channel',
        'reason',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Two-factor reset events are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Two-factor reset events are append-only and cannot be deleted.');
        });
    }

    /** The account whose second factor was cleared, while it still exists. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The operator who cleared it, while their account still exists. */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
