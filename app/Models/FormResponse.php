<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One submission to a Form.
 *
 * `data` is the validated submission keyed by field name; the repeatable section's
 * entries live under that section's id as an array of rows. The respondent_* columns
 * are copies of three values out of `data` so the admin list can search and sort
 * across the whole result set — see the create_form_responses_table migration.
 *
 * A `file` question stores the respondent's ORIGINAL FILENAME in `data` under the
 * field's name, so the admin table and the CSV export have something to show; the
 * bytes live on a private disk and are reached only through `attachments()`.
 *
 * These rows are PII (names, emails, phones, for camp forms minors' medical details,
 * and for a careers form an entire résumé). Treat them accordingly: they are excluded
 * from any payload that is not behind admin auth, and are never sent to a third-party
 * API unaggregated.
 */
class FormResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'masjid_id',
        'data',
        'respondent_name',
        'respondent_email',
        'respondent_phone',
        'entry_count',
        'amount_due',
        'status',
        'admin_notes',
        'device_id',
        'ip_address',
        'user_agent',
        'submitted_at',
    ];

    protected $casts = [
        'data' => 'array',
        'entry_count' => 'integer',
        'amount_due' => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    /**
     * Workflow states an admin can move a response through. Kept here rather than in a
     * DB enum so states can be added without a migration.
     */
    public const STATUSES = ['new', 'confirmed', 'waitlisted', 'cancelled'];

    /**
     * Keep forms.response_count honest without a COUNT(*) on every capacity check.
     *
     * Capacity is enforced against this counter, so it must move in the same
     * transaction as the row it counts. `increment`/`decrement` issue an atomic
     * UPDATE … SET response_count = response_count + 1, which is safe against two
     * simultaneous submissions in a way that read-modify-write would not be.
     */
    protected static function booted(): void
    {
        static::created(function (FormResponse $response) {
            Form::whereKey($response->form_id)->increment('response_count');
        });

        // BEFORE the row goes, not after: the FK on form_response_attachments
        // cascades, so by the time a `deleted` hook ran the attachment rows would
        // already be gone — silently, without firing a single model event — and
        // every résumé ever submitted would still be sitting on the volume. Deleting
        // them through the model here is what makes each one's `deleting` hook run
        // and remove its bytes from disk.
        static::deleting(function (FormResponse $response) {
            $response->attachments()->get()->each->delete();
        });

        static::deleted(function (FormResponse $response) {
            Form::whereKey($response->form_id)
                ->where('response_count', '>', 0)
                ->decrement('response_count');
        });
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Files uploaded with this submission. Empty for every form without a `file`
     * field, which is every form that existed before T-004.
     */
    public function attachments()
    {
        return $this->hasMany(FormResponseAttachment::class, 'form_response_id');
    }

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * Free-text search across the three denormalised identity columns.
     * Deliberately not a JSON search: JSON path predicates are not portable between
     * MySQL (production) and SQLite (tests).
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $like = '%' . $term . '%';
            $q->where('respondent_name', 'like', $like)
                ->orWhere('respondent_email', 'like', $like)
                ->orWhere('respondent_phone', 'like', $like);
        });
    }
}
