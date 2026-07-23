<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A single app-provisioning job — one platform (ios|android) of one "Generate
 * apps" request. See the provisioning_jobs migration for the column contract.
 *
 * SECURITY: `callback_token` is a per-job SECRET. It is `$hidden` (never
 * serialized by toArray()/toJson(), so no read endpoint can leak it) and is only
 * ever compared in constant time against the `Authorization: Bearer` header on
 * the runner's callback (see ProvisioningCallbackController). The plaintext is
 * available in-process right after create() so the controller can put it in the
 * outbound dispatch payload; it is never returned to a browser.
 *
 * `job_id` and `callback_token` are auto-assigned on create if not supplied.
 */
class ProvisioningJob extends Model
{
    // Portal-internal lifecycle states.
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DISPATCHED = 'dispatched';

    // Statuses the self-hosted runner may report via the callback contract.
    public const STATUS_SCAFFOLDING = 'scaffolding';
    public const STATUS_BUILDING = 'building';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_BUILT = 'built';
    public const STATUS_FAILED = 'failed';

    /**
     * The exact set of statuses the callback endpoint accepts from the runner.
     * `dispatched` is deliberately excluded — it is set only by the portal on a
     * successful repository_dispatch, never reported by the workflow.
     */
    public const CALLBACK_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SCAFFOLDING,
        self::STATUS_BUILDING,
        self::STATUS_UPLOADED,
        self::STATUS_BUILT,
        self::STATUS_FAILED,
    ];

    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';

    protected $fillable = [
        'job_id',
        'masjid_id',
        'platform',
        'status',
        'detail',
        'artifact_url',
        'callback_token',
        'github_repo',
    ];

    /**
     * Never serialize the per-job callback secret. Any accidental ->toJson() /
     * ->toArray() (e.g. a model returned straight from the index endpoint) drops
     * it entirely, so it can never cross the API boundary.
     */
    protected $hidden = [
        'callback_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProvisioningJob $job): void {
            if (empty($job->job_id)) {
                $job->job_id = (string) Str::uuid();
            }
            if (empty($job->callback_token)) {
                // Random per-job bearer the runner echoes back to authenticate
                // its callback. 40 chars of Str::random (mirrors the security
                // note in the task + the encrypted-secret pattern elsewhere).
                $job->callback_token = Str::random(40);
            }
        });
    }

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }
}
