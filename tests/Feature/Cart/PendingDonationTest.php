<?php

namespace Tests\Feature\Cart;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Stripe\DonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DonationService::createPendingDonation — the row build the universal cart
 * writes AFTER the shopper has paid (DECISIONS 2026-09-26).
 *
 * It is the same code createDonationCheckout has always run before opening Stripe,
 * moved out so the cart can write the row without a Session of its own. What these
 * pin: it opens no Stripe call, it re-checks no gate (money already taken must
 * still be recorded), the door's row and Session parameters are unchanged, and
 * zakat is still decided only by ZakatDesignation::resolve.
 *
 * The public door's own behaviour stays pinned by the untouched DonationFlowTest.
 */
class PendingDonationTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBaskets;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.fee_percentage' => 0.029,
            'services.stripe.fee_fixed' => 30,
            'services.stripe.platform_fee_percentage' => 0,
            'services.stripe.currency' => 'usd',
        ]);
    }

    /** A service whose every outward Stripe call is captured, never made. */
    private function service(?array &$captured = null): DonationService
    {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('createCheckoutSession')
            ->andReturnUsing(function (array $params, string $account, string $key) use (&$captured) {
                $captured = ['params' => $params, 'account' => $account, 'key' => $key];

                return ['id' => 'cs_test_door', 'url' => 'https://checkout.stripe.test/door', 'payment_intent' => 'pi_test_door'];
            });

        return $service;
    }

    private function typedFund(Masjid $org, string $type, bool $active = true): Fund
    {
        return Fund::create(['masjid_id' => $org->id, 'name' => ucfirst($type) . ' Fund', 'type' => $type, 'is_active' => $active]);
    }

    #[Test]
    public function it_writes_the_pending_card_gift_row_and_opens_no_stripe_session(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);

        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldNotReceive('createCheckoutSession');

        $donation = $service->createPendingDonation($org, $fund, 5000, false);

        $this->assertInstanceOf(Donation::class, $donation);
        $row = Donation::withoutMasjidScope()->findOrFail($donation->id);
        $this->assertSame($org->id, $row->masjid_id);
        $this->assertSame($fund->id, $row->fund_id);
        $this->assertNull($row->contact_id);
        $this->assertSame('one_time', $row->type);
        $this->assertSame('pending', $row->status);
        $this->assertSame(5000, $row->intended_amount);
        $this->assertSame(5000, $row->charged_amount);
        $this->assertSame('usd', $row->currency);
        $this->assertFalse($row->donor_covers_fees);
        $this->assertFalse($row->is_zakat);
        $this->assertNull($row->zakat_source);
        $this->assertNull($row->application_fee_amount, 'a zero platform fee is stored null, never 0');
        $this->assertStringStartsWith('checkout_', $row->idempotency_key);
        $this->assertNull($row->stripe_checkout_session_id);
        $this->assertNull($row->stripe_payment_intent_id);
        $this->assertSame('stripe', $row->source, 'a card gift keeps the DB default source, it is not an offline row');
        $this->assertNull($row->payment_method);
    }

    #[Test]
    public function donor_covers_fees_grosses_up_the_charged_amount(): void
    {
        $org = $this->org();

        $donation = $this->service()->createPendingDonation($org, $this->fund($org), 10000, true);

        $this->assertSame(10000, $donation->intended_amount);
        $this->assertSame(DonationService::grossUp(10000), $donation->charged_amount);
        $this->assertSame(10330, $donation->charged_amount);
        $this->assertTrue($donation->donor_covers_fees);
    }

    #[Test]
    public function the_platform_fee_default_is_the_doors_and_is_stored_only_when_positive(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        config(['services.stripe.platform_fee_percentage' => 0.02]);

        $donation = $this->service()->createPendingDonation($org, $fund, 5000, false);

        $this->assertSame(DonationService::applicationFee(5000), $donation->application_fee_amount);
        $this->assertSame(100, $donation->application_fee_amount);
    }

    #[Test]
    public function a_cart_can_pass_its_own_application_fee_and_idempotency_key(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        config(['services.stripe.platform_fee_percentage' => 0.02]);

        $own = $this->service()->createPendingDonation($org, $fund, 5000, false, [
            'application_fee_amount' => 37,
            'idempotency_key' => 'cart_order_1_line_2',
        ]);
        $none = $this->service()->createPendingDonation($org, $fund, 5000, false, [
            'application_fee_amount' => 0,
        ]);

        $this->assertSame(37, $own->application_fee_amount);
        $this->assertSame('cart_order_1_line_2', $own->idempotency_key);
        $this->assertNull($none->application_fee_amount, 'an explicit 0 overrides the config default and is stored null');
        $this->assertStringStartsWith('checkout_', $none->idempotency_key);
    }

    #[Test]
    public function every_call_mints_its_own_default_idempotency_key(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $service = $this->service();

        $a = $service->createPendingDonation($org, $fund, 5000, false);
        $b = $service->createPendingDonation($org, $fund, 5000, false);

        $this->assertNotSame($a->idempotency_key, $b->idempotency_key);
        $this->assertNotSame($a->uuid, $b->uuid);
    }

    #[Test]
    public function the_cart_can_attach_the_payer_it_already_knows(): void
    {
        $org = $this->org();

        // A REAL contact: donations.contact_id is a foreign key, so an invented id (the
        // first version passed 4242) is refused by the database before anything is tested.
        $payer = \App\Models\Contact::factory()->create(['masjid_id' => $org->id]);
        $donation = $this->service()->createPendingDonation($org, $this->fund($org), 5000, false, ['contact_id' => $payer->id]);

        $this->assertSame($payer->id, $donation->contact_id);
    }

    #[Test]
    public function zakat_is_decided_only_by_the_designation_rule(): void
    {
        $org = $this->org();
        $zakatFund = $this->typedFund($org, 'zakat');
        $general = $this->typedFund($org, 'general');
        $service = $this->service();

        $inferred = $service->createPendingDonation($org, $zakatFund, 5000, false);
        $this->assertTrue($inferred->is_zakat);
        $this->assertSame('fund_default', $inferred->zakat_source);

        $declaredNo = $service->createPendingDonation($org, $zakatFund, 5000, false, ['zakat' => false]);
        $this->assertFalse($declaredNo->is_zakat);
        $this->assertNull($declaredNo->zakat_source);

        $declaredYes = $service->createPendingDonation($org, $general, 5000, false, ['zakat' => true]);
        $this->assertTrue($declaredYes->is_zakat);
        $this->assertSame('donor', $declaredYes->zakat_source);
    }

    #[Test]
    public function a_caller_cannot_set_is_zakat_or_its_source_around_the_rule(): void
    {
        $org = $this->org();
        $general = $this->typedFund($org, 'general');
        $zakatFund = $this->typedFund($org, 'zakat');
        $service = $this->service();

        $forced = $service->createPendingDonation($org, $general, 5000, false, [
            'is_zakat' => true,
            'zakat_source' => 'admin',
        ]);
        $this->assertFalse($forced->is_zakat, 'is_zakat is not an option; the fund says general');
        $this->assertNull($forced->zakat_source);

        $suppressed = $service->createPendingDonation($org, $zakatFund, 5000, false, [
            'is_zakat' => false,
            'zakat_source' => null,
        ]);
        $this->assertTrue($suppressed->is_zakat, 'the fund default still applies');
        $this->assertSame('fund_default', $suppressed->zakat_source);
    }

    #[Test]
    public function the_write_re_checks_no_gate_because_the_money_has_already_moved(): void
    {
        // A fund deactivated and an org whose Stripe account can no longer take
        // charges, between the shopper paying and the row being written.
        $org = $this->org(['stripe_charges_enabled' => false]);
        $fund = $this->fund($org, active: false);

        $donation = $this->service()->createPendingDonation($org, $fund, 5000, false);

        $this->assertSame('pending', Donation::withoutMasjidScope()->findOrFail($donation->id)->status);
    }

    #[Test]
    public function the_door_writes_the_same_row_as_createPendingDonation_and_sends_its_values_to_stripe(): void
    {
        config(['services.stripe.platform_fee_percentage' => 0.02]);
        $org = $this->org();
        $fund = $this->typedFund($org, 'zakat');
        $captured = null;
        $service = $this->service($captured);

        $payer = \App\Models\Contact::factory()->create(['masjid_id' => $org->id]);   // a real one: contact_id is a foreign key
        $door = $service->createDonationCheckout($org, $fund, 10000, true, ['contact_id' => $payer->id, 'zakat' => null]);
        $direct = $service->createPendingDonation($org, $fund, 10000, true, ['contact_id' => $payer->id, 'zakat' => null]);

        $volatile = ['id', 'uuid', 'idempotency_key', 'created_at', 'updated_at',
            'stripe_checkout_session_id', 'stripe_payment_intent_id'];
        $strip = fn (Donation $d) => array_diff_key(
            Donation::withoutMasjidScope()->findOrFail($d->id)->getAttributes(),
            array_flip($volatile)
        );
        $this->assertEquals($strip($direct), $strip($door['donation']));

        $donation = $door['donation'];
        $params = $captured['params'];
        $this->assertSame($donation->idempotency_key, $captured['key']);
        $this->assertSame($org->stripe_account_id, $captured['account']);
        $this->assertSame(10330, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(200, $params['payment_intent_data']['application_fee_amount']);
        $this->assertSame([
            'donation_uuid' => $donation->uuid,
            'masjid_id' => (string) $org->id,
            'fund_id' => (string) $fund->id,
            'zakat' => 'true',
            'zakat_source' => 'fund_default',
        ], $params['payment_intent_data']['metadata']);
        $this->assertSame($donation->uuid, $params['client_reference_id']);
        $this->assertSame('cs_test_door', $donation->fresh()->stripe_checkout_session_id);
        $this->assertSame('pi_test_door', $donation->fresh()->stripe_payment_intent_id);
        $this->assertSame('https://checkout.stripe.test/door', $door['checkout_url']);
    }

    #[Test]
    public function a_door_gift_with_no_platform_fee_and_no_zakat_sends_neither_to_stripe(): void
    {
        $org = $this->org();
        $captured = null;
        $service = $this->service($captured);

        $door = $service->createDonationCheckout($org, $this->typedFund($org, 'general'), 5000, false);

        $piData = $captured['params']['payment_intent_data'];
        $this->assertArrayNotHasKey('application_fee_amount', $piData);
        $this->assertArrayNotHasKey('zakat', $piData['metadata']);
        $this->assertArrayNotHasKey('zakat_source', $piData['metadata']);
        $this->assertNull($door['donation']->application_fee_amount);
    }
}
