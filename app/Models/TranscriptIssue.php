<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptIssue extends Model
{
    use BelongsToMasjid;
    protected $fillable = ['masjid_id', 'contact_id', 'serial', 'snapshot', 'pdf_path', 'pdf_sha256', 'issued_by_user_id', 'issued_at'];
    protected $hidden = ['pdf_path'];
    protected function casts(): array { return ['snapshot'=>'array','issued_at'=>'datetime']; }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['contact_id','updated_at']) !== [] || static::class !== TranscriptReceived::class) {
                throw new \LogicException('An issued transcript is immutable. Issue a new copy.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Academic transcript copies are retained.'));
    }
}
