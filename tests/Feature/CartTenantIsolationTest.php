<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A basket, and each line in it, belong to ONE organisation.
 *
 * Cart and CartItem use BelongsToMasjid, and MySQL has no row-level security, so
 * the bound tenant is the only boundary between one organisation's shoppers and
 * another's — a basket carries attendee names, and a guest's hashed access token.
 * TenantScopingCoverageTest requires this proof per model; it named Cart, and this
 * covers CartItem too rather than relying on the detector having counted it: a
 * scope nobody tested is a scope nobody proved (two such holes were found live on
 * 2026-08-11).
 *
 * Mirrors TenantIsolationTest: seed UNBOUND (so the explicit masjid_id is honoured
 * — the creating hook only overrides when a tenant is bound), then bind A and
 * assert every way of reaching B's row is refused.
 */
class CartTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'America/New_York',
        ]);
    }

    /** Seeded while UNBOUND, so the explicit masjid_id is kept. */
    private function basketIn(Masjid $org): Cart
    {
        return Cart::create(['masjid_id' => $org->id, 'token_hash' => hash('sha256', uniqid('', true))]);
    }

    private function lineIn(Cart $cart): CartItem
    {
        return CartItem::create([
            'cart_id' => $cart->id,
            'masjid_id' => $cart->masjid_id,
            'buyable_type' => CartItem::TYPE_DONATION,
            'buyable_id' => 1,
            'recorded_as' => CartItem::RECORDED_AS_DONATION,
            'label' => 'Zakat-ul-Fitr',
            'quantity' => 1,
            'unit_amount_shown_minor' => 5000,
            'currency' => 'usd',
            'payload' => ['note' => 'names can live here'],
        ]);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_cart(): void
    {
        $theirs = $this->basketIn($this->masjidB);
        $bId = $theirs->id;

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Cart::find($bId), 'another organisation\'s basket must not be found');
        $this->assertSame(0, Cart::where('id', $bId)->count());
        $this->assertSame(0, Cart::where('id', $bId)->update(['status' => 'hijacked']));
        $this->assertSame(0, Cart::where('id', $bId)->delete());

        // Unbound again, the row is untouched: the refusals above changed nothing.
        $this->tenant->forgetTenant();
        $this->assertSame(Cart::STATUS_OPEN, Cart::find($bId)->status);
    }

    #[Test]
    public function creating_a_cart_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->masjidA->id);

        // A client tries to plant a basket in organisation B.
        $cart = Cart::create(['masjid_id' => $this->masjidB->id, 'token_hash' => hash('sha256', 'x')]);

        $this->assertSame($this->masjidA->id, (int) $cart->masjid_id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_cart_lines(): void
    {
        $theirLine = $this->lineIn($this->basketIn($this->masjidB));
        $bId = $theirLine->id;

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(CartItem::find($bId), 'another organisation\'s basket line must not be found');
        $this->assertSame(0, CartItem::where('id', $bId)->count());
        $this->assertSame(0, CartItem::where('id', $bId)->update(['unit_amount_shown_minor' => 1]));
        $this->assertSame(0, CartItem::where('id', $bId)->delete());

        $this->tenant->forgetTenant();
        $this->assertSame(5000, CartItem::find($bId)->unit_amount_shown_minor);
    }

    #[Test]
    public function creating_a_cart_line_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $mine = $this->basketIn($this->masjidA);

        $this->tenant->set($this->masjidA->id);

        $line = CartItem::create([
            'cart_id' => $mine->id,
            'masjid_id' => $this->masjidB->id,   // planted
            'buyable_type' => CartItem::TYPE_DONATION,
            'buyable_id' => 1,
            'recorded_as' => CartItem::RECORDED_AS_DONATION,
            'label' => 'Gift',
            'quantity' => 1,
            'unit_amount_shown_minor' => 100,
            'currency' => 'usd',
        ]);

        $this->assertSame($this->masjidA->id, (int) $line->masjid_id);
    }

    private function orderIn(Masjid $org): Order
    {
        return Order::create([
            'masjid_id' => $org->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'total_minor' => 5000,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
            'idempotency_key' => 'cart_order_' . Str::uuid(),
        ]);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_order(): void
    {
        // An order is a sale — the organisation's record, not the shopper's — and
        // it names the account the money went to.
        $bId = $this->orderIn($this->masjidB)->id;

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(Order::find($bId), 'another organisation\'s order must not be found');
        $this->assertSame(0, Order::where('id', $bId)->count());
        $this->assertSame(0, Order::where('id', $bId)->update(['status' => Order::STATUS_PAID]));
        $this->assertSame(0, Order::where('id', $bId)->delete());

        $this->tenant->forgetTenant();
        $this->assertSame(Order::STATUS_PENDING, Order::find($bId)->status, 'the refusals changed nothing');
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_order_lines(): void
    {
        $order = $this->orderIn($this->masjidB);
        $bId = OrderItem::create([
            'order_id' => $order->id, 'masjid_id' => $this->masjidB->id,
            'buyable_type' => CartItem::TYPE_DONATION, 'buyable_id' => 1,
            'recorded_as' => CartItem::RECORDED_AS_DONATION, 'label' => 'Zakat-ul-Fitr',
            'quantity' => 1, 'unit_amount_minor' => 5000, 'total_minor' => 5000, 'currency' => 'usd',
        ])->id;

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(OrderItem::find($bId));
        $this->assertSame(0, OrderItem::where('id', $bId)->update(['total_minor' => 1]));
        $this->assertSame(0, OrderItem::where('id', $bId)->delete());
    }

    #[Test]
    public function creating_an_order_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->masjidA->id);

        $order = $this->orderIn($this->masjidB);   // tries to plant it in B

        $this->assertSame($this->masjidA->id, (int) $order->masjid_id);
    }

    #[Test]
    public function an_order_never_serialises_its_idempotency_key_or_pinned_account(): void
    {
        $order = $this->orderIn($this->masjidA);

        $this->assertArrayNotHasKey('idempotency_key', $order->toArray(), 'the key would let a caller replay a page');
        $this->assertArrayNotHasKey('charge_account_id', $order->toArray(), 'the account id belongs to the organisation');
    }

    #[Test]
    public function the_guest_token_hash_is_never_serialised(): void
    {
        // Whoever holds the token can read and edit the basket, so it must never
        // leave the server in a JSON response, even a hashed one.
        $cart = $this->basketIn($this->masjidA);

        $this->assertArrayNotHasKey('token_hash', $cart->toArray());
        $this->assertStringNotContainsString($cart->token_hash, $cart->toJson());
    }
}
