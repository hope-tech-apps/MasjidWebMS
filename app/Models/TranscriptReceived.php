<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;

/** Tenant isolation: TranscriptsTest. */
class TranscriptReceived extends Model
{
    use BelongsToMasjid;
    protected $table = 'transcript_received';
    protected $fillable = ['masjid_id', 'contact_id', 'source_request_id', 'origin_school', 'origin_serial', 'received_at', 'accepted_by_user_id', 'snapshot', 'pdf_path', 'pdf_sha256'];
    protected $hidden = ['pdf_path'];
    protected function casts(): array { return ['snapshot'=>'array','received_at'=>'datetime']; }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['contact_id','updated_at']) !== [] || ($record->isDirty('contact_id') && $record->getRawOriginal('contact_id')!==null)) {
                throw new \LogicException('An issued transcript is immutable. Issue a new copy.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Academic transcript copies are retained.'));
    }
}
