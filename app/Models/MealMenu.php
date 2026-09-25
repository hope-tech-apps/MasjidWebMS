<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A meal menu a masjid opens for ordering — one of two KINDS:
 *
 *  - `dated` (every menu before 2026-09-25, and still the default): the Jummah
 *    lunch. One service date, one cutoff, everyone collects after the prayer.
 *  - `catalogue`: a standing price list with no service date (MEC's Halal
 *    Kitchen; owner: "Pickup at MEC, 48h, office confirms"). The customer picks
 *    when to collect, at least `pickup_lead_hours` ahead, and the office confirms
 *    each order (KitchenOrdersController, KitchenOrderNotifier). A paid catalogue
 *    order is NOT confirmed by its payment (MealOrder::markPaid).
 *
 * Every reader that means "this Friday's lunch" filters with scopeDated(), so a
 * catalogue can never be served, announced or ordered as one. DECISIONS.md
 * 2026-09-25 records why the kitchen rides on these tables at all.
 *
 * Status and kind are plain strings backed by the constants below — never a DB
 * enum, so a new state never needs an ALTER on a live table (and SQLite, which
 * the test suite runs on, cannot ALTER a CHECK constraint at all).
 */
class MealMenu extends Model
{
    use HasFactory, BelongsToMasjid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';   // being prepared, not orderable
    public const STATUS_OPEN = 'open';     // accepting orders
    public const STATUS_CLOSED = 'closed'; // ordering has ended

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    public const KIND_DATED = 'dated';          // the Jummah lunch: a service date and a cutoff
    public const KIND_CATALOGUE = 'catalogue';  // a standing catalogue: pickup chosen by the customer

    public const KINDS = [
        self::KIND_DATED,
        self::KIND_CATALOGUE,
    ];

    /** The owner's lead time for MEC's kitchen, and the default for a new catalogue. */
    public const DEFAULT_PICKUP_LEAD_HOURS = 48;

    /**
     * How far ahead a catalogue pickup may be booked. Not a business rule — a
     * bound on what an unauthenticated form can put on the office's board, so a
     * typo of 2062 for 2026 is refused instead of sitting there for decades.
     */
    public const MAX_PICKUP_DAYS_AHEAD = 90;

    protected $fillable = [
        'masjid_id',
        'title',
        'title_ar',
        'service_date',
        'status',
        'ordering_opens_at',
        'ordering_closes_at',
        'pickup_instructions',
        'pickup_instructions_ar',
        'flyer_image_url',
        'notes',
        'allow_online_payment',
        'allow_pay_at_pickup',
        'collect_customer_email',
        'allow_donation',
        'allow_fee_coverage',
        'notify_service_id',
        'allow_sms_optin',
        'currency',
        'kind',
        'pickup_lead_hours',
        'notify_emails',
    ];

    protected $attributes = [
        'title' => 'Jummah Lunch',
        'kind' => self::KIND_DATED,
        'status' => self::STATUS_DRAFT,
        'allow_online_payment' => true,
        'allow_pay_at_pickup' => true,
        'currency' => 'usd',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'ordering_opens_at' => 'datetime',
            'ordering_closes_at' => 'datetime',
            'allow_online_payment' => 'boolean',
            'allow_pay_at_pickup' => 'boolean',
            'collect_customer_email' => 'boolean',
            'allow_donation' => 'boolean',
            'allow_fee_coverage' => 'boolean',
            'allow_sms_optin' => 'boolean',
            'opening_notified_at' => 'datetime',
            'pickup_lead_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MealMenu $menu): void {
            if (empty($menu->uuid)) {
                $menu->uuid = (string) Str::uuid();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealMenuItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MealOrder::class);
    }

    /**
     * May the public place an order against this menu right now? Open, and
     * either no cutoff or the cutoff is still in the future. An unknown status
     * fails closed.
     */
    public function isOpenForOrders(): bool
    {
        return $this->status === self::STATUS_OPEN
            && ($this->ordering_closes_at === null || $this->ordering_closes_at->isFuture());
    }

    /**
     * Resolve a menu by its public uuid within one masjid. Public order pages
     * run UNBOUND (the tenant global scope adds no filter there), so the
     * masjid_id is filtered explicitly — a foreign uuid is a miss, never a leak.
     */
    public static function findByUuidForMasjid(string $uuid, int $masjidId): ?self
    {
        return static::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('uuid', $uuid)
            ->first();
    }

    public function isCatalogue(): bool
    {
        return $this->kind === self::KIND_CATALOGUE;
    }

    /** Only the dated (Jummah-lunch) menus: what every "this Friday" reader means. */
    public function scopeDated(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('kind'), self::KIND_DATED);
    }

    /** Only the standing catalogues. */
    public function scopeCatalogue(Builder $query): Builder
    {
        return $query->where($query->getModel()->qualifyColumn('kind'), self::KIND_CATALOGUE);
    }

    /** The lead time a catalogue pickup must respect, in hours. */
    public function pickupLeadHours(): int
    {
        return max(0, (int) ($this->pickup_lead_hours ?? self::DEFAULT_PICKUP_LEAD_HOURS));
    }

    /**
     * The earliest pickup a customer may choose right now: the lead time from
     * this moment. The server decides it (the page only displays it), because the
     * customer's clock is not the office's.
     */
    public function earliestPickup(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->addHours($this->pickupLeadHours());
    }

    /** The latest pickup a customer may choose right now. */
    public function latestPickup(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->addDays(self::MAX_PICKUP_DAYS_AHEAD);
    }

    /** The menu a masjid is currently taking orders on, if any. */
    public static function scopeCurrentlyOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)
            ->where(function (Builder $q) {
                $q->whereNull('ordering_closes_at')
                    ->orWhere('ordering_closes_at', '>', Carbon::now());
            });
    }
}
