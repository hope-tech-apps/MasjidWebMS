<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One executed change to an imported web address (Manara Studio W2, S6),
 * written by MasjidDomain::releaseImported() and reclassifyImported() inside
 * the change's own transaction.
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

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Web address changes are append-only and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Web address changes are append-only and cannot be deleted.');
        });
    }
}
