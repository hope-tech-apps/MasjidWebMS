<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/cart.php, evaluated against the environment an operator could give it (brief 5,
 * section 0). The two that matter are the master switch and the allowlist, and both must
 * FAIL CLOSED: a typo may leave the basket off, or off for everyone, and can never switch
 * it on for an organisation nobody named.
 */
class CartConfigTest extends TestCase
{
    private const KEYS = [
        'CART_ENABLED',
        'CART_MASJID_IDS',
        'CART_TTL_DAYS',
        'CART_MAX_LINES',
        'CART_CREATE_PER_HOUR',
        'CART_WRITE_PER_HOUR',
        'CART_PRUNE_EXPIRED_ORDER_DAYS',
        'CART_PRUNE_PENDING_WITH_PAYMENT_DAYS',
    ];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }

        parent::tearDown();
    }

    #[Test]
    public function unset_the_cart_is_off_for_everyone_and_the_limits_are_the_briefs(): void
    {
        $config = $this->evaluate([]);

        $this->assertFalse($config['enabled'], 'off unless the owner turns it on');
        $this->assertSame([], $config['masjid_ids'], 'an empty list means every organisation, once on');
        $this->assertSame(7, $config['ttl_days']);
        $this->assertSame(25, $config['max_lines']);
        $this->assertSame(200, $config['throttle']['create_per_hour']);
        $this->assertSame(120, $config['throttle']['write_per_hour']);
        $this->assertSame(600, $config['throttle']['read_per_hour']);
        $this->assertSame(20, $config['throttle']['checkout_per_hour']);
        $this->assertSame(30, $config['throttle']['order_status_per_hour']);
        $this->assertSame(300, $config['throttle']['order_status_guard_per_minute']);
        $this->assertSame(1, $config['prune']['grace_days']);
    }

    #[Test]
    public function only_a_clear_yes_switches_it_on(): void
    {
        foreach (['true', '1', 'on', 'yes'] as $yes) {
            $this->assertTrue($this->evaluate(['CART_ENABLED' => $yes])['enabled'], "CART_ENABLED={$yes}");
        }

        foreach (['false', '0', 'off', 'no', 'nope', 'enabled', ''] as $no) {
            $this->assertFalse($this->evaluate(['CART_ENABLED' => $no])['enabled'], "CART_ENABLED={$no}");
        }
    }

    #[Test]
    public function the_allowlist_is_a_comma_list_of_organisation_ids(): void
    {
        $this->assertSame([7], $this->evaluate(['CART_MASJID_IDS' => '7'])['masjid_ids']);
        $this->assertSame([7, 12], $this->evaluate(['CART_MASJID_IDS' => ' 7 , 12 ,7'])['masjid_ids']);
        $this->assertSame([7], $this->evaluate(['CART_MASJID_IDS' => '007'])['masjid_ids']);
    }

    #[Test]
    public function a_malformed_allowlist_admits_nobody_rather_than_everybody(): void
    {
        // `true`, `false` and `null` are the literals env() turns into a bool or null.
        foreach (['7;12', 'all', '*', '7,x', '7,,12', '0', '-3', '7.5', '7 12', 'true', 'false', 'null'] as $bad) {
            $this->assertSame([0], $this->evaluate(['CART_MASJID_IDS' => $bad])['masjid_ids'], "CART_MASJID_IDS={$bad}");
        }
    }

    #[Test]
    public function a_typo_cannot_close_a_limit_outright(): void
    {
        $config = $this->evaluate([
            'CART_TTL_DAYS' => '0',
            'CART_MAX_LINES' => 'many',
            'CART_CREATE_PER_HOUR' => '-5',
            'CART_WRITE_PER_HOUR' => '0',
        ]);

        $this->assertSame(1, $config['ttl_days']);
        $this->assertSame(1, $config['max_lines']);
        $this->assertSame(1, $config['throttle']['create_per_hour']);
        $this->assertSame(1, $config['throttle']['write_per_hour']);

        $this->assertSame(40, $this->evaluate(['CART_MAX_LINES' => '40'])['max_lines']);
    }

    #[Test]
    public function an_expired_order_is_kept_a_week_and_a_typo_cannot_shorten_it_below_a_day(): void
    {
        $this->assertSame(7, $this->evaluate([])['prune']['expired_order_days'], 'a week, as the brief says');
        $this->assertSame(14, $this->evaluate(['CART_PRUNE_EXPIRED_ORDER_DAYS' => '14'])['prune']['expired_order_days']);

        foreach (['0', '-3', 'soon'] as $bad) {
            $this->assertSame(1, $this->evaluate(['CART_PRUNE_EXPIRED_ORDER_DAYS' => $bad])['prune']['expired_order_days'], "CART_PRUNE_EXPIRED_ORDER_DAYS={$bad}");
        }
    }

    #[Test]
    public function a_pending_order_with_a_payment_is_kept_thirty_days_and_a_typo_cannot_shorten_it_below_a_week(): void
    {
        $this->assertSame(30, $this->evaluate([])['prune']['pending_with_payment_days'], 'a month, before a payment nobody recorded is written off');
        $this->assertSame(45, $this->evaluate(['CART_PRUNE_PENDING_WITH_PAYMENT_DAYS' => '45'])['prune']['pending_with_payment_days']);

        foreach (['0', '-3', '1', 'soon'] as $bad) {
            $this->assertSame(7, $this->evaluate(['CART_PRUNE_PENDING_WITH_PAYMENT_DAYS' => $bad])['prune']['pending_with_payment_days'], "CART_PRUNE_PENDING_WITH_PAYMENT_DAYS={$bad}");
        }
    }

    /**
     * config/cart.php evaluated afresh under $env, as config:cache would read it.
     *
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    private function evaluate(array $env): array
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, $env[$key] ?? null);
        }

        return require config_path('cart.php');
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}
