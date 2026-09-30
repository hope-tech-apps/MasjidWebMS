<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/member_portal.php, evaluated against the environment an operator could give it
 * (DECISIONS.md 2026-09-30). The switch and the allowlist both FAIL CLOSED, exactly as
 * config/cart.php's do: a typo may leave the portal off, or off for everyone, and can never
 * switch it on for an organisation nobody named.
 */
class MemberPortalConfigTest extends TestCase
{
    private const KEYS = [
        'MEMBER_PORTAL_ENABLED',
        'MEMBER_PORTAL_MASJID_IDS',
    ];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }

        parent::tearDown();
    }

    #[Test]
    public function unset_the_portal_is_off_for_everyone(): void
    {
        $config = $this->evaluate([]);

        $this->assertFalse($config['enabled'], 'off unless the owner turns it on');
        $this->assertSame([], $config['masjid_ids'], 'an empty list means every organisation, once on');
    }

    #[Test]
    public function only_a_clear_yes_switches_it_on(): void
    {
        foreach (['true', '1', 'on', 'yes'] as $yes) {
            $this->assertTrue($this->evaluate(['MEMBER_PORTAL_ENABLED' => $yes])['enabled'], "MEMBER_PORTAL_ENABLED={$yes}");
        }

        foreach (['false', '0', 'off', 'no', 'nope', 'enabled', ''] as $no) {
            $this->assertFalse($this->evaluate(['MEMBER_PORTAL_ENABLED' => $no])['enabled'], "MEMBER_PORTAL_ENABLED={$no}");
        }
    }

    #[Test]
    public function the_allowlist_is_a_comma_list_of_organisation_ids(): void
    {
        $this->assertSame([7], $this->evaluate(['MEMBER_PORTAL_MASJID_IDS' => '7'])['masjid_ids']);
        $this->assertSame([7, 12], $this->evaluate(['MEMBER_PORTAL_MASJID_IDS' => ' 7 , 12 ,7'])['masjid_ids']);
        $this->assertSame([7], $this->evaluate(['MEMBER_PORTAL_MASJID_IDS' => '007'])['masjid_ids']);
    }

    #[Test]
    public function a_malformed_allowlist_admits_nobody_rather_than_everybody(): void
    {
        // `true`, `false` and `null` are the literals env() turns into a bool or null.
        foreach (['7;12', 'all', '*', '7,x', '7,,12', '0', '-3', '7.5', '7 12', 'true', 'false', 'null'] as $bad) {
            $this->assertSame([0], $this->evaluate(['MEMBER_PORTAL_MASJID_IDS' => $bad])['masjid_ids'], "MEMBER_PORTAL_MASJID_IDS={$bad}");
        }
    }

    /**
     * config/member_portal.php evaluated afresh under $env, as config:cache would read it.
     *
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    private function evaluate(array $env): array
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, $env[$key] ?? null);
        }

        return require config_path('member_portal.php');
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
