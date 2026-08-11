<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A file uploaded with a form submission — a résumé, a scanned document.
 *
 * The row is a pointer; the bytes live on a private disk. Nothing outside this
 * model should build the path or touch the disk, so that "where is it and who may
 * read it" has exactly one answer.
 *
 * These are PII of the sharpest kind (a résumé carries an address and a work
 * history), which is why there is no public URL for one and no accessor that
 * produces one. The ONLY way out is FormResponsesController::downloadAttachment,
 * behind auth:sanctum + admin + tenant.
 */
class FormResponseAttachment extends Model
{
    protected $fillable = [
        'form_response_id',
        'masjid_id',
        'field',
        'original_name',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    /**
     * Delete the bytes with the row.
     *
     * On `deleting` rather than `deleted` so that a failure to remove the row does
     * not leave us having already destroyed the only copy of the file.
     */
    protected static function booted(): void
    {
        static::deleting(function (FormResponseAttachment $attachment) {
            $attachment->storage()->delete($attachment->path);
        });
    }

    public function response()
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /** The disk this particular file was written to, which may predate a config change. */
    public function storage()
    {
        return Storage::disk($this->disk);
    }

    public function exists(): bool
    {
        return (bool) $this->path && $this->storage()->exists($this->path);
    }

    /**
     * What the admin surface shows for one attachment. No URL is included here —
     * the controller builds the download link, because only it knows the route.
     *
     * @return array<string,mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'file_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
