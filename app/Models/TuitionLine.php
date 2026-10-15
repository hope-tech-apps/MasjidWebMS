<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Immutable financial entries; FamilyLedger is the only writer. Isolation: TuitionLedgerTest. */
class TuitionLine extends Model
{
    use BelongsToMasjid;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['amount_minor' => 'integer', 'effective_on' => 'date', 'due_on' => 'date', 'needs_review' => 'boolean', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Ledger entries are immutable. Void and re-enter.'));
        static::deleting(fn () => throw new \LogicException('Ledger entries are immutable. Void and re-enter.'));
    }
}
