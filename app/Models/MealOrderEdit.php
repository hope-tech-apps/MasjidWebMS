<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a lunch order's lines after it was placed.
 *
 * The record of who changed an order and what it said before — written in the
 * same transaction as the change itself (App\Services\Lunch\MealOrderEditor), so
 * an edit cannot commit without one. It matters most for an order that was
 * already paid: the money that settled and the food now on the order can differ,
 * and this is where the difference comes from.
 *
 * WRITE-ONCE. There is no `updated_at` and nothing updates these rows; an audit
 * row that could be revised would not be one. `actor` is a plain string, like
 * every other status in this module.
 */
class MealOrderEdit extends Model
{
    use BelongsToMasjid;

    /** Changed by the customer, on the order link they hold. */
    public const ACTOR_CUSTOMER = 'customer';

    /** Changed by staff on the board (an admin; `user_id` says who). */
    public const ACTOR_STAFF = 'staff';

    public const ACTORS = [
        self::ACTOR_CUSTOMER,
        self::ACTOR_STAFF,
    ];

    /** Written once, never updated. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'masjid_id',
        'meal_order_id',
        'actor',
        'user_id',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MealOrder::class, 'meal_order_id');
    }

    /**
     * The staff login that made the change; null for a customer's own edit.
     * withTrashed for the reason MealOrder::enteredBy is: removing a volunteer
     * soft-deletes their login, and "who changed this order?" must still answer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * Record one edit. Call it inside the transaction that writes the change.
     *
     * `masjid_id` is stamped from the ORDER, not the bound tenant: the customer's
     * own edit runs UNBOUND (the public `/api/v1` idiom), where the BelongsToMasjid
     * hook has nothing to stamp. Under a bound admin request the hook overrides it
     * with the bound tenant, which is the same organisation the order belongs to.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    public static function record(MealOrder $order, string $actor, ?int $userId, array $before, array $after): self
    {
        if (! in_array($actor, self::ACTORS, true)) {
            throw new \InvalidArgumentException("Unknown actor: {$actor}");
        }

        $edit = new self([
            'meal_order_id' => $order->id,
            'actor' => $actor,
            'user_id' => $userId,
            'before' => $before,
            'after' => $after,
        ]);

        $edit->masjid_id = (int) $order->masjid_id;
        $edit->created_at = Carbon::now();
        $edit->save();

        return $edit;
    }
}
