<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MasjidSmsSender — this organisation's own originating identity and its A2P
 * 10DLC registration state (T-009).
 *
 * There is NO shared fallback number in this feature, anywhere, on purpose. A
 * fleet that puts every tenant's traffic on one long code gets that number
 * filtered and then the whole provider account suspended — taking SMS away from
 * every organisation on the platform, including the ones that registered
 * properly. So the absence of an approved row here is not a gap to be papered
 * over with a default; it is the refusal, and
 * App\Services\Broadcast\Channels\SmsChannel states it in words an admin can act
 * on.
 *
 * ## `canSend()` is the whole contract
 *
 * Approved AND addressable. `pending` deliberately cannot send: "the paperwork
 * is submitted" is not carrier permission, and the gap between the two is
 * measured in days during which an eager admin would otherwise be putting
 * unregistered traffic on the network in the organisation's name.
 *
 * ## No secrets live here
 *
 * The provider account credentials are platform-level, env-driven and unset by
 * default (config/services.php, `twilio`) — the Stripe/OneSignal shape. This row
 * holds only the tenant's identity and the registration ids an operator can look
 * up in the provider console, none of which are secret. That is why, unlike
 * MasjidAppPublishing, nothing here is encrypted or $hidden.
 *
 * ## Tenant scoping
 *
 * BelongsToMasjid (.claude/rules/tenant-scoping.md): a new model, so it takes
 * the global scope and the server-derived creating hook rather than the
 * hand-filtering the pre-CRM config models still use. The inbound webhook runs
 * unbound and resolves the sender by its number across tenants, which is exactly
 * what the unbound scope permits; a bound admin request can only ever see its
 * own. Pinned by SmsTenantIsolationTest.
 */
class MasjidSmsSender extends Model
{
    use HasFactory, BelongsToMasjid;

    /** Nothing has been submitted to the carriers. The default. */
    public const STATUS_UNREGISTERED = 'unregistered';

    /** Brand/campaign submitted, no carrier verdict yet. Cannot send. */
    public const STATUS_PENDING = 'pending';

    /** Carrier-approved. The ONLY status that may send. */
    public const STATUS_APPROVED = 'approved';

    /** The carriers refused the brand or campaign. */
    public const STATUS_REJECTED = 'rejected';

    /** Previously approved, then suspended (usually a complaint rate). */
    public const STATUS_SUSPENDED = 'suspended';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_UNREGISTERED,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SUSPENDED,
    ];

    protected $fillable = [
        'masjid_id',
        'provider',
        'phone_number',
        'messaging_service_sid',
        'sender_label',
        'registration_status',
        'brand_registration_id',
        'campaign_registration_id',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function masjidRelation(): BelongsTo
    {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * May this tenant put traffic on the carrier network right now?
     *
     * Both halves are necessary: carrier approval WITHOUT an originating
     * identity has nothing to send from, and an originating identity WITHOUT
     * approval is unregistered traffic.
     */
    public function canSend(): bool
    {
        return $this->registration_status === self::STATUS_APPROVED
            && ($this->hasMessagingService() || filled($this->phone_number));
    }

    public function hasMessagingService(): bool
    {
        return filled($this->messaging_service_sid);
    }

    /**
     * Why this sender cannot send, phrased for the admin who sees it on a
     * delivery row. Null when it can.
     */
    public function refusalReason(): ?string
    {
        if ($this->canSend()) {
            return null;
        }

        if ($this->registration_status !== self::STATUS_APPROVED) {
            return 'This organization\'s text-message sender is "' . $this->registration_status
                . '", not approved. Carriers require A2P 10DLC brand and campaign registration for each '
                . 'organization before it may send, and messages sent before approval are blocked and '
                . 'counted against the platform. No message was sent.';
        }

        return 'This organization is approved to send text messages but has no phone number or messaging '
            . 'service on file to send from. No message was sent.';
    }
}
