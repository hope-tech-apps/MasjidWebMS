<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One executed change to a web address that takes something away: a release or
 * adoption of an imported row (W2 S6), a detach (S3) or a collapse into a
 * redirect (S5). Written by MasjidDomain::releaseImported(),
 * reclassifyImported() and recordChange(), in the change's own transaction.
 *
 * Append-only: updating or deleting a row through the model throws, as
 * ContactLoginEvent does. A builder-level update fires no model event, so the
 * guarantee is about every code path in this application, all of which go
 * through the model.
 *
 * Not tenant-scoped: like `masjid_domains` it is platform-level, and only the
 * operator's command writes it.
 */
class MasjidDomainChange extends Model
{
    public const ACTION_RELEASE = 'release';
    public const ACTION_ADOPT = 'adopt';

    /** A detach started (W2 S3): the row stops being served and Studio removes what it made. */
    public const ACTION_DETACH = 'detach';

    /** A serving host became a redirect to its sibling (W2 S5, `domains:collapse-alias`). */
    public const ACTION_COLLAPSE = 'collapse';

    public const UPDATED_AT = null;

    protected $fillable = [
        'masjid_domain_id',
        'host',
        'action',
        'before',
        'after',
        'operator',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** The width of `operator` (the migration's string(191)). */
    public const OPERATOR_MAX = 191;

    protected static function booted(): void
    {
        // Cut to the column here, once, for every writer: on MySQL an
        // oversized value would fail the insert AFTER the Cloudflare change it
        // records was made, losing the record of it.
        static::creating(function (MasjidDomainChange $change) {
            $change->operator = mb_substr((string) $change->operator, 0, self::OPERATOR_MAX);
        });

        static::updating(function () {
            throw new RuntimeException('Web address changes are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Web address changes are append-only and cannot be deleted.');
        });
    }
}
