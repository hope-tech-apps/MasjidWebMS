<?php

/*
|--------------------------------------------------------------------------
| The shop's stock path, through the real statements
|--------------------------------------------------------------------------
|
| SQLite compiles `lockForUpdate()` to nothing and orders datetimes as strings, so the ordinary
| suite (tests/Feature/Shop/ShopStockTest.php, ShopSettlementTest.php) cannot show that the
| locking selects, the held-quantity sum (a join with a grouped SUM and a bound datetime), the
| grace comparison and the settlement's lock are accepted and correct on MySQL. This runs the
| whole flow once on the engine: two baskets for the last unit, the second refused while the first
| holds it and again once it has paid, the grace window, and an oversold payment recorded and
| flagged. One connection, so it proves the statements and the arithmetic, not an interleaving:
| two real connections racing for a row lock are exercised by the deploy's staging walk, not here.
| Runs only in CI's migrations-mysql job, as `pest --group=mysql`.
*/

use App\Models\Order;
use App\Models\ProductSale;
use App\Services\Cart\CartCheckoutRefused;
use App\Services\Cart\CartSettlementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\Feature\Shop\BuildsShop;

uses(BuildsBaskets::class, BuildsShop::class, SignsCartWebhooks::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('sells the last unit once: the second basket is refused while the first holds it and after it has paid', function () {
    $this->armWebhooks();

    $org = $this->shopOrg();
    $variant = $this->sizeOf($org, ['stock' => 1]);
    $one = $this->cart($org);
    $two = $this->cart($org);
    $this->addVariant($one, $variant, 1);
    $this->addVariant($two, $variant, 1);

    $held = $this->placeOrder($one);
    expect($held->status)->toBe(Order::STATUS_PENDING);

    $refused = fn () => $this->checkoutService()->checkout($two, 'https://mec.manara.hopetechapps.com/basket', 'two@example.org');

    expect($refused)->toThrow(CartCheckoutRefused::class);
    expect(Order::withoutMasjidScope()->count())->toBe(1);

    app(CartSettlementService::class)->settle((int) $held->id, 'pi_mysql_1', (int) $held->total_minor, 'usd');

    expect((int) $variant->fresh()->sold_count)->toBe(1)
        ->and(ProductSale::withoutMasjidScope()->count())->toBe(1)
        ->and($refused)->toThrow(CartCheckoutRefused::class);
});

it('lets a lapsed order go only after the grace, and records an oversold late payment flagged', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');
    $this->armWebhooks();

    $org = $this->shopOrg();
    $variant = $this->sizeOf($org, ['stock' => 1]);
    $one = $this->cart($org);
    $two = $this->cart($org);
    $this->addVariant($one, $variant, 1);
    $this->addVariant($two, $variant, 1);

    $late = $this->placeOrder($one);   // page lapses 12:31, held until 12:46

    Carbon::setTestNow('2026-10-01 12:45:00');
    expect(fn () => $this->checkoutService()->checkout($two, 'https://mec.manara.hopetechapps.com/basket', 'two@example.org'))
        ->toThrow(CartCheckoutRefused::class);

    Carbon::setTestNow('2026-10-01 12:47:00');
    $timely = $this->checkoutService()->checkout($two, 'https://mec.manara.hopetechapps.com/basket', 'two@example.org')['order'];
    app(CartSettlementService::class)->settle((int) $timely->id, 'pi_mysql_timely', (int) $timely->total_minor, 'usd');

    $channel = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('monitors')->andReturn($channel);

    app(CartSettlementService::class)->settle((int) $late->id, 'pi_mysql_late', (int) $late->total_minor, 'usd');

    expect((int) $variant->fresh()->sold_count)->toBe(2)
        ->and((bool) ProductSale::withoutMasjidScope()->where('order_id', $late->id)->sole()->oversold)->toBeTrue()
        ->and((bool) ProductSale::withoutMasjidScope()->where('order_id', $timely->id)->sole()->oversold)->toBeFalse();

    $channel->shouldHaveReceived('error')->once();
});
