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
 * A dated meal menu (the Jummah lunch) a masjid opens for ordering.
 *
 * Status is a plain string backed by the constants below — never a DB enum, so
 * a new state never needs an ALTER on a live table (and SQLite, which the test
 * suite runs on, cannot ALTER a CHECK constraint at all).
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
        'currency',
    ];

    protected $attributes = [
        'title' => 'Jummah Lunch',
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
