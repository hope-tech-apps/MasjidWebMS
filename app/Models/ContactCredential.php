<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * ContactCredential — one credential held by one Contact: a medical license, a
 * background check, a BLS card (T-023, Community vertical; the pilot is a free
 * clinic run on licensed volunteer providers).
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * fillable so system/super code can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 *
 * `identifier` (the license number) uses the `encrypted` cast — the same
 * arrangement as users.two_factor_secret — so a DB dump or a stray query log
 * never shows a provider's license number in the clear.
 *
 * Status is DERIVED, never stored: valid / expiring / expired is a function of
 * expires_at and today, so a stored value would silently go stale at midnight
 * with nothing responsible for refreshing it. Read it via the `status` accessor
 * (appended to JSON) or query it via the scopes below.
 *
 * The optional document (the scanned license) lives on the PRIVATE disk under a
 * random name per .claude/rules/private-uploads.md; this row is only a pointer.
 * There is deliberately NO public URL and no accessor that produces one — the
 * only way out is ContactCredentialsController::downloadDocument, which
 * re-resolves masjid -> contact -> credential before any bytes leave.
 */
class ContactCredential extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Common credential kinds a volunteer-run clinic tracks. PHP constants
     * rather than a DB enum, exactly like Group::KINDS: adding one must never
     * mean ALTER TABLE on a live table (.claude/rules/migrations.md). Validated
     * at the request boundary with Rule::in(...).
     *
     * KIND_OTHER is the escape hatch: anything not listed is stored as `other`
     * with the human name in `label`, so an unforeseen credential never
     * requires a deploy.
     */
    public const KIND_MEDICAL_LICENSE = 'medical_license';
    public const KIND_NURSING_LICENSE = 'nursing_license';
    public const KIND_BACKGROUND_CHECK = 'background_check';
    public const KIND_BLS_CERTIFICATION = 'bls_certification';
    public const KIND_ACLS_CERTIFICATION = 'acls_certification';
    public const KIND_LIABILITY_INSURANCE = 'liability_insurance';
    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_MEDICAL_LICENSE,
        self::KIND_NURSING_LICENSE,
        self::KIND_BACKGROUND_CHECK,
        self::KIND_BLS_CERTIFICATION,
        self::KIND_ACLS_CERTIFICATION,
        self::KIND_LIABILITY_INSURANCE,
        self::KIND_OTHER,
    ];

    /** Derived status values — see the accessor below. Never a column. */
    public const STATUS_VALID = 'valid';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_VALID,
        self::STATUS_EXPIRING,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'masjid_id',
        'contact_id',
        'kind',
        'label',
        'issuing_body',
        'identifier',
        'issued_at',
        'expires_at',
        'notes',
        'document_original_name',
        'document_mime_type',
        'document_size_bytes',
        'document_disk',
        'document_path',
    ];

    /**
     * The raw document columns never reach a JSON payload: the path and disk
     * are server internals (leaking them invites someone to try fetching the
     * file some other way), and the metadata the admin SPA needs is served as a
     * single `document` object built by the controller — the only place that
     * knows the download route. Same reasoning as
     * FormResponseAttachment::toAdminArray().
     */
    protected $hidden = [
        'document_original_name',
        'document_mime_type',
        'document_size_bytes',
        'document_disk',
        'document_path',
    ];

    /** Derived, so every serialization carries it without anyone storing it. */
    protected $appends = ['status'];

    protected function casts(): array
    {
        return [
            // A license number is sensitive PII — encrypted at rest, same as
            // users.two_factor_secret. Decrypts transparently for admins.
            'identifier' => 'encrypted',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'document_size_bytes' => 'integer',
        ];
    }

    /**
     * Delete the bytes with the row — in the MODEL layer, on `deleting`, so it
     * holds for every caller and runs before the row is gone (a failure to
     * remove the row must not leave us having already destroyed the only copy
     * of the scan). The DB cascade fires no model events, so it must never be
     * what removes these rows: see Contact::booted() for the force-delete path.
     */
    protected static function booted(): void
    {
        static::deleting(function (ContactCredential $credential) {
            if ($credential->hasDocument()) {
                $credential->documentStorage()->delete($credential->document_path);
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    // ------------------------------------------------------------- derived status

    /**
     * How many days before expiry counts as "expiring". Config-driven so a
     * tenant fleet can be tuned without a deploy; clamped to at least 1 so a
     * misconfigured 0/negative value cannot silently disable the warning state.
     */
    public static function expiringThresholdDays(): int
    {
        return max(1, (int) config('credentials.expiring_within_days', 30));
    }

    /**
     * valid / expiring / expired, computed at read time.
     *
     * A null expires_at means non-expiring, so: valid. A credential dated today
     * is still presentable today — it counts as expiring, not expired; expired
     * begins the day AFTER the expiry date.
     */
    public function getStatusAttribute(): string
    {
        if ($this->expires_at === null) {
            return self::STATUS_VALID;
        }

        $today = Carbon::today();

        if ($this->expires_at->lt($today)) {
            return self::STATUS_EXPIRED;
        }

        if ($this->expires_at->lte($today->copy()->addDays(self::expiringThresholdDays()))) {
            return self::STATUS_EXPIRING;
        }

        return self::STATUS_VALID;
    }

    /**
     * Credentials whose expiry falls within the next $days days (inclusive),
     * today included, already-expired excluded — the renewal chase list.
     *
     * Bounds are passed as Carbon instances so the query grammar formats them
     * to match how the `date` cast stored the column, which keeps the
     * comparison correct on BOTH MySQL (DATE column) and the SQLite test
     * driver (text comparison).
     */
    public function scopeExpiringWithin(Builder $query, ?int $days = null): Builder
    {
        $days = $days ?? self::expiringThresholdDays();
        $today = Carbon::today();

        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', $today)
            ->where('expires_at', '<=', $today->copy()->addDays($days));
    }

    /** Credentials whose expiry date has already passed. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::today());
    }

    // ----------------------------------------------------------- private document

    public function hasDocument(): bool
    {
        return (bool) $this->document_path;
    }

    /** The disk this particular scan was written to, which may predate a config change. */
    public function documentStorage()
    {
        return Storage::disk($this->document_disk);
    }

    public function documentExists(): bool
    {
        return $this->hasDocument() && $this->documentStorage()->exists($this->document_path);
    }

    /**
     * What the admin surface shows for the document, or null when there is
     * none. No URL here — the controller appends the download link, because
     * only it knows the route. Mirrors FormResponseAttachment::toAdminArray().
     *
     * @return array<string,mixed>|null
     */
    public function documentAdminArray(): ?array
    {
        if (! $this->hasDocument()) {
            return null;
        }

        return [
            'file_name' => $this->document_original_name,
            'mime_type' => $this->document_mime_type,
            'size_bytes' => $this->document_size_bytes,
        ];
    }
}
