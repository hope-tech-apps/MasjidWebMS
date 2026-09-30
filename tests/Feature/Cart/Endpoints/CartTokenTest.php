<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\TestCase;

/**
 * A basket's handle and the replay guard's index (brief 5, sections 1 and 4).
 *
 * The token is a bearer secret: whoever holds it can read and edit the basket, so the
 * database keeps only a KEYED digest of it (the FamilyInviteService construction), and the
 * lookup is by that digest AND the organisation the request names. The migration used to
 * say "SHA-256"; these tests pin what the code does.
 */
class CartTokenTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;

    private const TOKEN = 'a3f5c0de9b1e4a7c8d2f6b0e1c3a5d7f9e2b4c6d8a0f1e3b5c7d9a2f4e6b8c01';

    private function basketWithToken(int $masjidId, string $token = self::TOKEN, mixed $expiresAt = '+7 days'): Cart
    {
        return Cart::withoutMasjidScope()->create([
            'masjid_id' => $masjidId,
            'token_hash' => Cart::hashToken($token),
            'expires_at' => $expiresAt === null ? null : now()->modify($expiresAt),
        ]);
    }

    #[Test]
    public function the_digest_is_hmac_sha256_on_the_application_key_never_a_bare_hash(): void
    {
        $digest = Cart::hashToken(self::TOKEN);

        $this->assertSame(hash_hmac('sha256', self::TOKEN, (string) config('app.key')), $digest);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $digest);
        $this->assertNotSame(hash('sha256', self::TOKEN), $digest, 'not the bare SHA-256 the migration comment used to claim');

        // Another key, another digest: a leaked table cannot be checked against guessed tokens.
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->assertNotSame($digest, Cart::hashToken(self::TOKEN));
    }

    #[Test]
    public function a_basket_is_found_by_its_token_within_the_organisation_only(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $cart = $this->basketWithToken($orgA->id);

        $this->assertSame($cart->id, Cart::findLiveByToken(self::TOKEN, $orgA->id)?->id);

        // Another organisation's header never opens it.
        $this->assertNull(Cart::findLiveByToken(self::TOKEN, $orgB->id));
        $this->assertNull(Cart::findLiveByToken(self::TOKEN, 0));
        $this->assertNull(Cart::findLiveByToken(self::TOKEN, -1));
    }

    #[Test]
    public function a_wrong_expired_or_malformed_token_finds_nothing(): void
    {
        $org = $this->org();
        $this->basketWithToken($org->id);

        $this->assertNull(Cart::findLiveByToken(str_repeat('0', 64), $org->id), 'a well-formed token nobody holds');
        $this->assertNull(Cart::findLiveByToken(strtoupper(self::TOKEN), $org->id), 'tokens are lower-case hex');
        $this->assertNull(Cart::findLiveByToken(substr(self::TOKEN, 0, 63), $org->id));
        $this->assertNull(Cart::findLiveByToken(self::TOKEN . "\n", $org->id), 'a trailing newline is not a token');
        $this->assertNull(Cart::findLiveByToken('', $org->id));

        $expired = 'b' . substr(self::TOKEN, 1);
        $this->basketWithToken($org->id, $expired, '-1 second');
        $this->assertNull(Cart::findLiveByToken($expired, $org->id), 'an expired basket is gone to its holder');
    }

    #[Test]
    public function a_basket_with_no_expiry_never_expires(): void
    {
        $org = $this->org();
        $this->basketWithToken($org->id, self::TOKEN, null);

        $this->assertNotNull(Cart::findLiveByToken(self::TOKEN, $org->id));
    }

    #[Test]
    public function the_limiters_digest_is_the_stored_one_and_only_for_a_live_basket(): void
    {
        $org = $this->org();
        $this->basketWithToken($org->id);

        $this->assertSame(Cart::hashToken(self::TOKEN), Cart::liveTokenHash(self::TOKEN, $org->id));
        $this->assertNull(Cart::liveTokenHash(str_repeat('0', 64), $org->id));
        $this->assertNull(Cart::liveTokenHash(null, $org->id));
        $this->assertNull(Cart::liveTokenHash(['array'], $org->id));
        $this->assertNull(Cart::liveTokenHash(self::TOKEN, $org->id + 1));
    }

    #[Test]
    public function the_digest_is_never_serialised(): void
    {
        $cart = $this->basketWithToken($this->org()->id);

        $this->assertArrayNotHasKey('token_hash', $cart->toArray());
        $this->assertStringNotContainsString(self::TOKEN, $cart->toJson());
    }

    // ------------------------------------------------------- client_line_key index

    #[Test]
    public function the_client_line_key_is_unique_within_a_basket_and_only_there(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $one = $this->cart($org);
        $two = $this->cart($org);

        $line = fn (Cart $cart, ?string $key) => CartItem::withoutMasjidScope()->create([
            'cart_id' => $cart->id,
            'masjid_id' => $cart->masjid_id,
            'buyable_type' => CartItem::TYPE_DONATION,
            'buyable_id' => $fund->id,
            'recorded_as' => CartItem::RECORDED_AS_DONATION,
            'label' => 'Gift',
            'quantity' => 1,
            'unit_amount_shown_minor' => 5000,
            'currency' => 'usd',
            'payload' => [],
            'client_line_key' => $key,
        ]);

        $line($one, 'press-0001');

        // Lines added without a key are unlimited: unique indexes admit many NULLs.
        $line($one, null);
        $line($one, null);

        // The same key in another basket is another press.
        $line($two, 'press-0001');

        $this->assertSame(4, CartItem::withoutMasjidScope()->count(), 'premise: all four were accepted');

        // The same press twice in ONE basket is what the index refuses.
        $this->expectException(UniqueConstraintViolationException::class);
        $line($one, 'press-0001');
    }

    #[Test]
    public function the_columns_are_there(): void
    {
        $this->assertTrue(Schema::hasColumns('cart_items', ['client_line_key', 'client_line_hash']));
    }
}
