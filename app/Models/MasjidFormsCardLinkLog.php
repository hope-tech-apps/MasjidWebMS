<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One change to where an organisation's FORM card payments are charged
 * (masjids.forms_card_via_masjid_id, DECISIONS.md 2026-09-15).
 *
 * NOT `BelongsToMasjid`: a link joins TWO tenants (the child whose forms charge,
 * the holder whose Connect account is charged), so there is no single masjid to
 * scope it to, and the table carries no `masjid_id`. It is written by a
 * SuperAdmin endpoint, a holder's revoke and the Masjid force-delete hook, and
 * read by nobody over HTTP.
 *
 * APPEND-ONLY, enforced here with the same honest limit as TwoFactorResetEvent:
 * the hooks fire for model instances only, so a query-builder update or raw SQL
 * still gets through. Nothing in this application does either.
 */
class MasjidFormsCardLinkLog extends Model
{
    public const ACTION_LINK = 'link';
    public const ACTION_UNLINK = 'unlink';
    public const ACTION_REVOKE = 'revoke';

    public const ACTIONS = [self::ACTION_LINK, self::ACTION_UNLINK, self::ACTION_REVOKE];

    protected $table = 'masjid_forms_card_links_log';

    /** Rows carry a single `created_at`; nothing ever updates one. */
    public const UPDATED_AT = null;

    /**
     * Fillable because nothing here comes straight from a request body: the two
     * typed values arrive validated through SetFormsCardAccountRequest, and every
     * other column is derived from the locked rows and the authenticated actor.
     */
    protected $fillable = [
        'child_masjid_id',
        'holder_masjid_id',
        'action',
        'actor_user_id',
        'typed_name',
        'consent_reference',
        'holder_account_suffix',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MasjidFormsCardLinkLog $row) {
            if (! in_array($row->action, self::ACTIONS, true)) {
                throw new RuntimeException("Unknown forms card link action '{$row->action}'.");
            }
        });

        static::updating(function () {
            throw new RuntimeException('Forms card link log rows are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Forms card link log rows are append-only and cannot be deleted.');
        });
    }

    /**
     * Write one row. `$holderAccountId` is the holder's account at the time of
     * the act; only its last four characters are kept, and only for a real
     * `acct_` id.
     */
    public static function record(
        int $childId,
        int $holderId,
        string $action,
        ?int $actorUserId,
        ?string $holderAccountId,
        ?string $typedName = null,
        ?string $consentReference = null,
    ): self {
        return self::create([
            'child_masjid_id' => $childId,
            'holder_masjid_id' => $holderId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'typed_name' => $typedName,
            'consent_reference' => $consentReference,
            'holder_account_suffix' => self::accountSuffix($holderAccountId),
        ]);
    }

    public static function accountSuffix(?string $accountId): ?string
    {
        if (! is_string($accountId) || ! str_starts_with($accountId, 'acct_') || strlen($accountId) <= 9) {
            return null;
        }

        return substr($accountId, -4);
    }
}
