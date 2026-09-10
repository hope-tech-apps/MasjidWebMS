<?php

namespace Tests\Feature;

use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Support\LunchOrderExtras;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one rule both order doors — the public page and the staff board — price
 * the optional extra and the covered card fee by. Literal cents at a pinned
 * rate, so no expectation is computed by the code under test.
 */
class LunchOrderExtrasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.fee_percentage' => 0.029, 'services.stripe.fee_fixed' => 30]);
    }

    private function menu(bool $extra, bool $fee): MealMenu
    {
        return (new MealMenu())->forceFill(['allow_donation' => $extra, 'allow_fee_coverage' => $fee]);
    }

    /**
     * $8.00 of food with a $5.00 extra asked for.
     * [extra offered, fee offered, online, covered] => [extra charged, fee charged]
     */
    public static function switches(): array
    {
        return [
            'everything on' => [true, true, true, true, 500, 70],
            'fee not ticked' => [true, true, true, false, 500, 0],
            'pay at pickup is never surcharged' => [true, true, false, true, 500, 0],
            'fee not offered' => [true, false, true, true, 500, 0],
            'extra not offered: fee on the food alone' => [false, true, true, true, 0, 55],
            'neither offered' => [false, false, true, true, 0, 0],
            'extra not offered, pickup' => [false, true, false, true, 0, 0],
        ];
    }

    #[Test]
    #[DataProvider('switches')]
    public function it_follows_the_menu_the_payment_method_and_the_payers_answer(
        bool $extraOn, bool $feeOn, bool $online, bool $cover, int $donation, int $fee
    ): void {
        $this->assertSame(
            ['donation_minor' => $donation, 'fee_covered_minor' => $fee],
            LunchOrderExtras::compute($this->menu($extraOn, $feeOn), 800, 500, $cover, $online)
        );
    }

    #[Test]
    public function the_extra_is_clamped_to_the_ceiling_and_never_negative(): void
    {
        $menu = $this->menu(true, true);

        $this->assertSame(
            ['donation_minor' => MealOrder::MAX_DONATION_MINOR, 'fee_covered_minor' => 3041],
            LunchOrderExtras::compute($menu, 800, MealOrder::MAX_DONATION_MINOR + 1, true, true)
        );
        $this->assertSame(
            ['donation_minor' => 0, 'fee_covered_minor' => 55],
            LunchOrderExtras::compute($menu, 800, -100, true, true)
        );
    }
}
