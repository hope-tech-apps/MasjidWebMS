<?php

namespace App\Services\Member;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Models\MealOrder;
use App\Models\Order;
use App\Models\OrderItem;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHICH PURCHASES ARE A SIGNED-IN MEMBER'S — the linking rule, in one place.
 *
 * Nothing here decides what a member is SHOWN of a purchase; that is
 * MemberPurchaseProjector. This class only answers "is this row theirs", and it
 * answers it the same way for the list (a union) and for the detail (one row), because
 * both are built from the same four per-source queries below.
 *
 * ---------------------------------------------------------------------------
 * THE RULE (owner's call, slice 6 brief)
 * ---------------------------------------------------------------------------
 * A member sees a purchase when it was confirmed to THEIR VERIFIED ADDRESS. The
 * confirmation e-mail already told whoever holds that address, so the portal reveals
 * nothing new; and somebody who typed another person's address into a basket cannot
 * see the order unless they own that inbox.
 *
 * "Their verified address" is `contacts.login_email` while `verified_at` is set: an
 * e-mail code proved the mailbox (MemberSignupService), and FamilyAccessService clears
 * `verified_at` whenever the address moves. Without one, EVERY list is empty, the ones
 * keyed on `contact_id` included: the address is what proves the person, and a
 * verified session is the only door these routes have (`member.active`), so this is the
 * belt to that braces rather than a case anyone should meet.
 *
 * Within the caller's own organisation only, on every source:
 *   - cart `orders`: PAID, and `contact_id` is the caller OR the typed `buyer_email`
 *     is the address;
 *   - `historical_orders` (Wix): `contact_id` is the caller. The importer's link key;
 *     the table holds no e-mail;
 *   - door purchases, so this year's festival tickets show up: a paid money leg on a
 *     `form_responses` row whose `respondent_email` is the address, and a PAID
 *     `meal_orders` row whose `customer_email` is the address or whose `contact_id` is
 *     the caller;
 *   - a form response or meal order that an `order_items` row records is LEFT OUT: the
 *     cart order already lists it, and listing both would count one purchase twice;
 *   - donations: `contact_id` is the caller and the gift succeeded.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY QUERY NAMES THE ORGANISATION ITSELF
 * ---------------------------------------------------------------------------
 * The tenant scope is bound by `family.tenant` from the token's contact, and it is
 * the boundary. But `FormResponse` never had the trait, the union below is built on
 * the base query builder, and an unbound scope is "no filter" (.claude/rules/
 * tenant-scoping.md). One `masjid_id = ?` per query costs nothing, and it means a scope
 * that is ever lost turns into an empty page, not another organisation's purchases.
 *
 * ---------------------------------------------------------------------------
 * ADDRESSING A ROW
 * ---------------------------------------------------------------------------
 * The handle a member holds is the order's uuid for a cart order, and the row's own
 * number for the other three. Their uuids are deliberately NOT handed out: a form
 * response's is the bearer handle of its public payment page, and a meal order's is
 * the capability to edit it (routes/api_v1.php, `lunch-orders/{uuid}`). Ownership is
 * asked on every lookup, and a miss, a foreign row, a junk handle and an unknown
 * source all come back as null: the controller turns each into the same 404.
 */
class MemberPurchases
{
    public const SOURCE_MANARA = 'manara';
    public const SOURCE_WIX = 'wix';
    public const SOURCE_FORM = 'form';
    public const SOURCE_MEAL = 'meal';

    public const SOURCES = [
        self::SOURCE_MANARA,
        self::SOURCE_WIX,
        self::SOURCE_FORM,
        self::SOURCE_MEAL,
    ];

    /** A cart order's uuid, as Str::uuid() writes it. */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** A row number: positive, no leading zero, 18 digits at most so it fits a signed bigint. */
    private const ROW_NUMBER = '/^[1-9][0-9]{0,17}$/';

    /**
     * The address this member has proved, lower-cased and trimmed, or null when there is
     * none to speak for them.
     *
     * `memberAccessIsActive()` is the member realm's own liveness predicate (verified,
     * not revoked, not deleted), so a contact the realm would refuse at the door has no
     * address here either.
     */
    public function verifiedAddress(Contact $contact): ?string
    {
        if (! $contact->memberAccessIsActive()) {
            return null;
        }

        $address = mb_strtolower(trim((string) $contact->login_email));

        return $address === '' ? null : $address;
    }

    // ------------------------------------------------------------- per source

    /** Cart orders that were paid and were confirmed to the caller's address, or are the caller's. */
    public function cartOrders(Contact $contact): Builder
    {
        $query = Order::query()
            ->where('orders.masjid_id', $contact->masjid_id)
            ->where('orders.status', Order::STATUS_PAID);

        $address = $this->verifiedAddress($contact);

        if ($address === null) {
            return $this->none($query);
        }

        // One group, so the OR cannot escape the organisation and status above.
        return $query->where(function (Builder $q) use ($contact, $address) {
            $q->where('orders.contact_id', $contact->id)
                ->orWhereRaw('LOWER(TRIM(orders.buyer_email)) = ?', [$address]);
        });
    }

    /**
     * Imported Wix orders linked to the caller. Canceled and declined ones are included on
     * purpose: they are part of the history, and the projection says what they are.
     */
    public function historicalOrders(Contact $contact): Builder
    {
        $query = HistoricalOrder::query()
            ->where('historical_orders.masjid_id', $contact->masjid_id)
            ->where('historical_orders.contact_id', $contact->id);

        return $this->verifiedAddress($contact) === null ? $this->none($query) : $query;
    }

    /** Paid form responses (a money leg) confirmed to the caller's address and not owned by a cart order. */
    public function formPurchases(Contact $contact): Builder
    {
        $query = FormResponse::query()
            ->where('form_responses.masjid_id', $contact->masjid_id)
            ->whereNotNull('form_responses.payment_method')
            ->where('form_responses.payment_status', FormResponse::PAYMENT_PAID);

        $address = $this->verifiedAddress($contact);

        if ($address === null) {
            return $this->none($query);
        }

        return $this->notOwnedByACart(
            $query->whereRaw('LOWER(TRIM(form_responses.respondent_email)) = ?', [$address]),
            'form_responses',
            OrderItem::RECORD_FORM_RESPONSE
        );
    }

    /** Paid meal orders confirmed to the caller's address, or the caller's, not owned by a cart order. */
    public function mealPurchases(Contact $contact): Builder
    {
        $query = MealOrder::query()
            ->where('meal_orders.masjid_id', $contact->masjid_id)
            ->where('meal_orders.payment_status', MealOrder::PAYMENT_PAID);

        $address = $this->verifiedAddress($contact);

        if ($address === null) {
            return $this->none($query);
        }

        return $this->notOwnedByACart(
            $query->where(function (Builder $q) use ($contact, $address) {
                $q->where('meal_orders.contact_id', $contact->id)
                    ->orWhereRaw('LOWER(TRIM(meal_orders.customer_email)) = ?', [$address]);
            }),
            'meal_orders',
            OrderItem::RECORD_MEAL_ORDER
        );
    }

    /** The caller's succeeded gifts: Stripe, offline and imported Wix history alike. */
    public function gifts(Contact $contact): Builder
    {
        $query = Donation::query()
            ->where('donations.masjid_id', $contact->masjid_id)
            ->where('donations.contact_id', $contact->id)
            ->where('donations.status', 'succeeded');

        return $this->verifiedAddress($contact) === null ? $this->none($query) : $query;
    }

    /** The query for one source, or null for a source this portal does not have. */
    public function owned(Contact $contact, string $source): ?Builder
    {
        return match ($source) {
            self::SOURCE_MANARA => $this->cartOrders($contact),
            self::SOURCE_WIX => $this->historicalOrders($contact),
            self::SOURCE_FORM => $this->formPurchases($contact),
            self::SOURCE_MEAL => $this->mealPurchases($contact),
            default => null,
        };
    }

    // ------------------------------------------------------------------- reads

    /**
     * One page of the caller's orders across every source, newest first.
     *
     * Each item is a bare `{portal_source, portal_id, portal_at}` row and nothing else:
     * the union carries only the numeric key so it can never mix the collations of a
     * uuid and a number in one column (MySQL refuses that in a UNION; SQLite, which the
     * suite runs on, does not, so a test could not have told us). What the member is shown
     * is read afterwards, per source, by `load()`.
     *
     * The order is total (time, then source, then key) because `paginate()` is
     * LIMIT/OFFSET and MySQL orders equal keys arbitrarily per execution: without the
     * tie-breakers a row could be on two pages or on none.
     */
    public function orderPage(Contact $contact, int $perPage): LengthAwarePaginator
    {
        $union = null;

        foreach ($this->branches($contact) as $source => [$query, $at, $key]) {
            $branch = $query->toBase()->select([
                DB::raw("'{$source}' as portal_source"),
                DB::raw("{$key} as portal_id"),
                DB::raw("{$at} as portal_at"),
            ]);

            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        return DB::query()
            ->fromSub($union, 'member_orders')
            ->orderByDesc('portal_at')
            ->orderBy('portal_source')
            ->orderByDesc('portal_id')
            ->paginate($perPage);
    }

    /**
     * Read the rows a page named, each through its own ownership query again, so a row can
     * be shown only if it is still the caller's at the moment it is read.
     *
     * @param  iterable<object>  $rows  the items of `orderPage()`
     * @return array<string, Collection<int, Model>>  models by source, keyed by row id
     */
    public function load(Contact $contact, iterable $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys[(string) $row->portal_source][] = (int) $row->portal_id;
        }

        $models = [];

        foreach ($keys as $source => $ids) {
            $query = $this->owned($contact, $source);

            if ($query === null) {
                continue;
            }

            $models[$source] = $this->withDetail($query, $source)
                ->whereIn($query->getModel()->getQualifiedKeyName(), $ids)
                ->get()
                ->keyBy(fn (Model $model) => (int) $model->getKey());
        }

        return $models;
    }

    /**
     * One of the caller's orders by the handle the list gave, or null.
     *
     * Null covers every way of not having it: an unknown source, a handle that is not the
     * right shape for its source, a row that does not exist, and a row that is someone
     * else's. The caller cannot tell them apart, which is the point.
     */
    public function find(Contact $contact, string $source, string $handle): ?Model
    {
        $query = $this->owned($contact, $source);

        if ($query === null) {
            return null;
        }

        $query = $this->withDetail($query, $source);

        if ($source === self::SOURCE_MANARA) {
            return preg_match(self::UUID, $handle) === 1
                ? $query->where('orders.uuid', strtolower($handle))->first()
                : null;
        }

        return preg_match(self::ROW_NUMBER, $handle) === 1
            ? $query->whereKey((int) $handle)->first()
            : null;
    }

    /**
     * One of the caller's gifts by its uuid, or null. A donation's uuid was minted as the
     * opaque external handle (Donation::booted) and unlocks nothing on any public route.
     */
    public function findGift(Contact $contact, string $uuid): ?Donation
    {
        if (preg_match(self::UUID, $uuid) !== 1) {
            return null;
        }

        return $this->gifts($contact)
            ->with(['fund', 'receipt'])
            ->where('donations.uuid', strtolower($uuid))
            ->first();
    }

    /**
     * The calendar the caller's organisation keeps, for turning an instant into the day a
     * member would write down. `masjids.timezone` defaults to 'UTC' for "never set", and
     * a name PHP does not know is no better than none.
     */
    public function timezoneFor(Contact $contact): string
    {
        $name = trim((string) Masjid::withoutGlobalScopes()
            ->whereKey($contact->masjid_id)
            ->value('timezone'));

        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }

    // ------------------------------------------------------------------- guts

    /**
     * The four sources as union branches: the ownership query, the SQL for the moment the
     * purchase happened, and the SQL for the row's key.
     *
     * The moment is when the money moved where the row records that, else when the row was
     * made. It is only used to order the list; the date a member reads is the projector's.
     *
     * @return array<string, array{0: Builder, 1: string, 2: string}>
     */
    private function branches(Contact $contact): array
    {
        return [
            self::SOURCE_MANARA => [
                $this->cartOrders($contact),
                'COALESCE(orders.paid_at, orders.created_at)',
                'orders.id',
            ],
            self::SOURCE_WIX => [
                $this->historicalOrders($contact),
                'historical_orders.ordered_at',
                'historical_orders.id',
            ],
            self::SOURCE_FORM => [
                $this->formPurchases($contact),
                'COALESCE(form_responses.paid_at, form_responses.submitted_at)',
                'form_responses.id',
            ],
            self::SOURCE_MEAL => [
                $this->mealPurchases($contact),
                'COALESCE(meal_orders.paid_at, meal_orders.placed_at, meal_orders.created_at)',
                'meal_orders.id',
            ],
        ];
    }

    /** What a projection reads off each source, loaded in the same round trip. */
    private function withDetail(Builder $query, string $source): Builder
    {
        return match ($source) {
            self::SOURCE_MANARA, self::SOURCE_MEAL => $query->with(['items' => fn ($items) => $items->orderBy('id')]),
            // A form that was deleted since keeps its name for the purchase that paid for it.
            self::SOURCE_FORM => $query->with(['form' => fn ($form) => $form->withTrashed()]),
            default => $query,
        };
    }

    /**
     * Leave out a row that an order line records (`order_items.record_type/record_id`):
     * the cart order lists it, and it must not be listed twice.
     *
     * The subquery names the organisation too. Row ids are global, so a collision cannot
     * happen today, but the exclusion is about ONE organisation's orders and should say so.
     */
    private function notOwnedByACart(Builder $query, string $table, string $recordType): Builder
    {
        return $query->whereNotExists(function ($cart) use ($table, $recordType) {
            $cart->select(DB::raw('1'))
                ->from('order_items')
                ->where('order_items.record_type', $recordType)
                ->whereColumn('order_items.record_id', "{$table}.id")
                ->whereColumn('order_items.masjid_id', "{$table}.masjid_id");
        });
    }

    /** A query that matches nothing, still carrying its model so callers can chain on it. */
    private function none(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}
