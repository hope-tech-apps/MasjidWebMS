<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\MealMenuItem;
use App\Services\Cart\Sources\FormLineSource;
use App\Support\FormSchema;
use App\Support\MasjidTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puts ONE line into a basket for the public add endpoint (brief 5, section 1): validates
 * it as the line's own door does, prices it through the basket's own pricer, and refuses
 * what would come back `gone`.
 *
 * ## What each line is validated against
 *
 *   - form: exactly what FormSubmissionsController::store does with the answers. Unused
 *     price answers are dropped, the stored schema validates them (FormSchema::validator),
 *     and only what the schema declares is kept (FormSchema::only): the line's payload is the
 *     validated answers, never the request. A form that asks for a FILE is refused here, in
 *     one sentence: a basket carries answers only, and the upload path (a multipart bag, a
 *     private disk, the response's own transaction) is not one it has.
 *   - meal: the dish must be this organisation's, and a standing catalogue's line carries the
 *     pickup the customer chose, read in the organisation's timezone as the kitchen door reads
 *     it and stored as an absolute instant (CartPricer and settlement read it without one). A
 *     dated (Friday-lunch) menu has one service date and takes no pickup.
 *   - donation: the fund must be this organisation's; the amount is the giver's, in minor units,
 *     in the range the donation door accepts. `zakat` rides on the line only when the giver
 *     answered, because checkout freezes it and settlement hands it to ZakatDesignation, which
 *     stays the one place zakat is decided. Nothing recurring: a basket is one Stripe payment.
 *
 * ## Priced before it is kept
 *
 * The line is inserted inside a transaction, the WHOLE basket is priced by CartPricer (so the
 * payee rule, the currency rule and every source's gate run on it exactly as they will at
 * checkout), and a line that comes back `gone` rolls the insert back and is refused with its
 * reason. What the basket shows for the line is what the source says it costs NOW: a form's
 * unit price and place count come from FormLineSource, a dish's price from the dish.
 *
 * ## The replay guard
 *
 * `client_line_key` is minted once per "add" press by the page, and a retry sends it again.
 * The same key in the same basket returns the line it made (nothing is validated, priced or
 * written again: a retry after the form has since closed still gets its line); the same key
 * with a different request is a 409. The request is compared by a keyed digest
 * (`client_line_hash`), taken before validation, so a retry is recognised as itself whatever
 * has changed since. The basket row is locked for the check and the insert, so two taps at
 * once cannot both pass it; the unique (cart_id, client_line_key) index is the backstop.
 *
 * Runs UNBOUND (the public routes bind no tenant): every read filters `masjid_id` by hand and
 * every create stamps it.
 */
final class CartLineAdder
{
    /** What a basket that was already paid for answers to a change. */
    public const CLOSED = 'This basket has already been paid for.';

    public const RECURRING = 'Monthly giving cannot go in a basket. Please give it on its own page.';

    private const KEY_REUSED = 'That request was already used for a different item. Please reload your basket and try again.';

    public function __construct(
        private readonly CartPricer $pricer = new CartPricer,
        private readonly FormLineSource $forms = new FormLineSource,
    ) {}

    /**
     * @param  array<string,mixed>  $body  the request, its shape already validated:
     *                                     `type` (form|meal|donation), the type's own fields and an
     *                                     optional `client_line_key`
     * @return CartItem the line that was added, or the one this key already made
     *
     * @throws CartLineRefused
     */
    public function add(Cart $cart, array $body): CartItem
    {
        $type = (string) ($body['type'] ?? '');
        $key = is_string($body['client_line_key'] ?? null) ? $body['client_line_key'] : null;
        $hash = $key === null ? null : $this->requestHash($type, $body);

        if ($key !== null) {
            $existing = $this->lineWithKey($cart, $key);

            if ($existing !== null) {
                return $this->replay($existing, (string) $hash);
            }
        }

        $spec = match ($type) {
            'form' => $this->formLine($cart, $body),
            'meal' => $this->mealLine($cart, $body),
            'donation' => $this->donationLine($cart, $body),
            default => throw CartLineRefused::fields(['type' => ['Choose what to add.']]),
        };

        return DB::transaction(function () use ($cart, $spec, $key, $hash): CartItem {
            // The basket row is the lock every add, remove, acknowledge and checkout of it
            // queues behind (checkout takes the same one).
            $locked = Cart::withoutMasjidScope()
                ->where('masjid_id', $cart->masjid_id)
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw CartLineRefused::refused(self::CLOSED);
            }

            // Again, now that no other add can be between the check and the insert.
            if ($key !== null) {
                $existing = $this->lineWithKey($locked, $key);

                if ($existing !== null) {
                    return $this->replay($existing, (string) $hash);
                }
            }

            $max = max(1, (int) config('cart.max_lines', 25));
            $held = CartItem::withoutMasjidScope()
                ->where('cart_id', $locked->id)
                ->where('masjid_id', $locked->masjid_id)
                ->count();

            if ($held >= $max) {
                throw CartLineRefused::refused("A basket holds at most {$max} items. Please remove one to add another.");
            }

            $item = CartItem::withoutMasjidScope()->create([
                'cart_id' => $locked->id,
                'masjid_id' => $locked->masjid_id,
                'buyable_type' => $spec['buyable_type'],
                'buyable_id' => $spec['buyable_id'],
                'recorded_as' => $spec['recorded_as'],
                'label' => mb_substr($spec['label'], 0, 255),
                'quantity' => $spec['quantity'],
                'unit_amount_shown_minor' => $spec['unit'],
                'currency' => strtolower((string) config('services.stripe.currency', 'usd')),
                'payload' => $spec['payload'],
                'client_line_key' => $key,
                'client_line_hash' => $hash,
            ]);

            $priced = $this->pricer->price($locked);

            if ($priced->refusal !== null) {
                throw CartLineRefused::refused($priced->refusal);
            }

            foreach ($priced->lines as ['item' => $line, 'outcome' => $outcome]) {
                if ((int) $line->id === (int) $item->id && ! $outcome->isPayable()) {
                    throw CartLineRefused::refused((string) ($outcome->reason ?? 'This cannot be added to a basket right now.'));
                }
            }

            return $item;
        });
    }

    // ------------------------------------------------------------------ the three lines

    /**
     * @param  array<string,mixed>  $body
     * @return array{buyable_type: string, buyable_id: int, recorded_as: string, label: string, quantity: int, unit: int, payload: array<string,mixed>}
     */
    private function formLine(Cart $cart, array $body): array
    {
        // Hand-filtered: Form carries no tenant scope, and this runs unbound. A missing form,
        // another organisation's and a deleted one are the same answer.
        $form = Form::query()->where('masjid_id', $cart->masjid_id)->find((int) $body['form_id']);

        if ($form === null) {
            throw CartLineRefused::fields(['form_id' => ['This form is not available.']]);
        }

        $submitted = $body['answers'] ?? null;

        if (! is_array($submitted)) {
            throw CartLineRefused::fields(['answers' => ['No form data was submitted.']]);
        }

        $schema = FormSchema::for($form);

        if ($schema->fileFields() !== []) {
            throw CartLineRefused::refused(FormLineSource::FILE_FORM);
        }

        // The door's own order: unused price answers dropped BEFORE they are validated, so a
        // level is never refused over a question it does not ask.
        $submitted = $form->withoutUnusedPriceAnswers($submitted);
        $validator = $schema->validator($submitted);

        if ($validator->fails()) {
            throw CartLineRefused::fields($validator->errors()->toArray());
        }

        $clean = $schema->only($submitted);

        // What the form charges for these answers NOW, and for how many places. Shown 0, so the
        // outcome is `repriced` unless the form is priced at nothing; only `gone` matters here.
        $outcome = $this->forms->reprice($form, $clean, 0, now());

        if ($outcome->status === 'gone') {
            throw CartLineRefused::refused((string) $outcome->reason);
        }

        return [
            'buyable_type' => CartItem::TYPE_FORM,
            'buyable_id' => (int) $form->id,
            'recorded_as' => CartItem::RECORDED_AS_REGISTRATION,
            'label' => $outcome->label,
            'quantity' => $outcome->quantity,
            'unit' => $outcome->unitAmountMinor,
            'payload' => $clean,
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{buyable_type: string, buyable_id: int, recorded_as: string, label: string, quantity: int, unit: int, payload: array<string,mixed>}
     */
    private function mealLine(Cart $cart, array $body): array
    {
        $dish = MealMenuItem::withoutMasjidScope()
            ->where('masjid_id', $cart->masjid_id)
            ->find((int) $body['item_id']);

        if ($dish === null) {
            throw CartLineRefused::fields(['item_id' => ['This item is not available.']]);
        }

        $payload = [];
        $menu = $dish->menu;

        // Only a standing catalogue lets the customer choose when to collect (the kitchen door
        // requires it); a dated menu has one service date and nothing to pick.
        if ($menu !== null && $menu->isCatalogue()) {
            $pickup = $this->pickupInstant($body['pickup_at'] ?? null, (int) $cart->masjid_id);

            if ($pickup === null) {
                throw CartLineRefused::fields(['pickup_at' => ['Please choose a pickup date and time.']]);
            }

            $payload['pickup_at'] = $pickup;
        }

        return [
            'buyable_type' => CartItem::TYPE_MEAL,
            'buyable_id' => (int) $dish->id,
            'recorded_as' => CartItem::RECORDED_AS_ORDER_ONLY,
            'label' => (string) $dish->name,
            'quantity' => (int) $body['quantity'],
            // What the dish costs now, so the basket shows a price the source agrees with.
            'unit' => (int) $dish->price_minor,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{buyable_type: string, buyable_id: int, recorded_as: string, label: string, quantity: int, unit: int, payload: array<string,mixed>}
     */
    private function donationLine(Cart $cart, array $body): array
    {
        $fund = Fund::withoutMasjidScope()
            ->where('masjid_id', $cart->masjid_id)
            ->find((int) $body['fund_id']);

        if ($fund === null) {
            throw CartLineRefused::fields(['fund_id' => ['This fund is not available.']]);
        }

        // Present only when the giver answered (`false` is an answer): an absent zakat must stay
        // absent so the fund's type supplies the default (ZakatDesignation), as at the door.
        $payload = is_bool($body['zakat'] ?? null) ? ['zakat' => $body['zakat']] : [];

        return [
            'buyable_type' => CartItem::TYPE_DONATION,
            'buyable_id' => (int) $fund->id,
            'recorded_as' => CartItem::RECORDED_AS_DONATION,
            'label' => (string) $fund->name,
            'quantity' => 1,
            'unit' => (int) $body['amount_minor'],
            'payload' => $payload,
        ];
    }

    /**
     * The pickup as an absolute instant, or null when it is missing or unreadable. Read in the
     * organisation's own timezone (a datetime-local value carries none) exactly as the kitchen
     * door reads it, then stored with its offset, because CartPricer and settlement parse it
     * without a zone of their own.
     */
    private function pickupInstant(mixed $raw, int $masjidId): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, MasjidTime::zoneFor($masjidId))->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    // --------------------------------------------------------------------- the replay guard

    private function lineWithKey(Cart $cart, string $key): ?CartItem
    {
        return CartItem::withoutMasjidScope()
            ->where('cart_id', $cart->id)
            ->where('masjid_id', $cart->masjid_id)
            ->where('client_line_key', $key)
            ->first();
    }

    /** The line this key made, when the request is the same one; a conflict when it is not. */
    private function replay(CartItem $existing, string $hash): CartItem
    {
        if ($existing->client_line_hash !== null && hash_equals((string) $existing->client_line_hash, $hash)) {
            return $existing;
        }

        throw CartLineRefused::conflict(self::KEY_REUSED);
    }

    /**
     * A keyed digest of WHAT WAS ASKED FOR: the type and the fields that decide the line, ids
     * and numbers normalised (the same press is the same however its numbers were encoded),
     * answers as sent. Independent of key order. FormResponse::payloadHash is the house's replay
     * digest (HMAC-SHA256 on APP_KEY over a key-sorted canonical form), reused rather than
     * written a second time.
     *
     * @param  array<string,mixed>  $body
     */
    private function requestHash(string $type, array $body): string
    {
        $asked = match ($type) {
            'form' => [
                'form_id' => (int) ($body['form_id'] ?? 0),
                'answers' => $body['answers'] ?? null,
            ],
            'meal' => [
                'item_id' => (int) ($body['item_id'] ?? 0),
                'quantity' => (int) ($body['quantity'] ?? 0),
                'pickup_at' => $body['pickup_at'] ?? null,
            ],
            'donation' => [
                'fund_id' => (int) ($body['fund_id'] ?? 0),
                'amount_minor' => (int) ($body['amount_minor'] ?? 0),
                'zakat' => is_bool($body['zakat'] ?? null) ? $body['zakat'] : null,
            ],
            default => [],
        };

        return FormResponse::payloadHash(['type' => $type] + $asked);
    }
}
