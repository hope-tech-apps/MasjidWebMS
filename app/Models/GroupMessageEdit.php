<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GroupMessageEdit — the wording a sent message had BEFORE its author changed it
 * (W7, 2026-10-01).
 *
 * GroupMessage promises there is no per-message eraser to quietly rewrite what
 * was said to a parent. Editing a message keeps that promise by leaving a row
 * here for every real change: `previous_body` is the text that stopped being
 * current at `created_at`, and `editor_user_id` is the staff account that
 * replaced it. The message's own `body` is always the newest version, so the
 * earlier ones plus the current body are the whole history.
 *
 * APPEND-ONLY. An update or a delete THROUGH THE MODEL throws. The database
 * cannot enforce it (no portable guard across MySQL and the SQLite suite), so
 * this is the guard, and the only things that remove a row are the DB cascades
 * off the message (its thread's purge, a deleted group or organisation): an
 * erased message must not survive here. GroupMessage's own deleting hook
 * removes them with a query-builder delete, which fires no model events.
 *
 * Only the office reads these, through the admin realm's `.../edits` route,
 * after the same thread read gate as the message. They are in no staff or
 * family message payload, which carry `edited_at` only.
 *
 * Tenant-scoped by a denormalised masjid_id (BelongsToMasjid).
 */
class GroupMessageEdit extends Model
{
    use BelongsToMasjid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'masjid_id',
        'group_message_id',
        'editor_user_id',
        'previous_body',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('A message edit is a record of what was said and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new \LogicException('A message edit goes only with its message, never on its own.');
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(GroupMessage::class, 'group_message_id');
    }

    /** The staff account that made the edit; null once that account is deleted. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_user_id');
    }
}
