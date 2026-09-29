<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Staff sign-in, forgot-password and reset-password are throttled per mailbox
 * AND per IP, and no spelling of an address changes either.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). The `login` limiter
 * keyed on `strtolower(email)|ip` at 5 a minute, with no IP-only limit.
 * `users.email` is utf8mb4_unicode_ci, so an accented spelling of an address is
 * the SAME user to the database and a different key to the limiter: every
 * spelling got five more password guesses. And a UTS46-equivalent spelling (a
 * soft hyphen, fullwidth letters) got a fresh bucket for the same reason the
 * family door did (SignInThrottleKeyTest).
 *
 * Two fixes, and a test for each: the per-address bucket is keyed on the
 * normalised address, and an IP-only ceiling caps what one host can try however
 * it spells the address. `throttle:login` is on all three routes, so it is one
 * allowance for the surface.
 */
class StaffLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * The three routes that carry `throttle:login`.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function doors(): array
    {
        return [
            'login' => ['/api/admin/login', ['password' => 'not-the-password-at-all']],
            'forgot password' => ['/api/admin/forgot-password', []],
            'reset password' => ['/api/admin/reset-password', [
                'token' => 'not-a-real-token',
                'password' => 'Correct-horse-battery-9',
                'password_confirmation' => 'Correct-horse-battery-9',
            ]],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function everyDoorAndSpelling(): array
    {
        $spellings = [
            'a soft hyphen inside the domain' => "exam\u{00AD}ple.test",
            'a zero-width space inside the domain' => "exam\u{200B}ple.test",
            'fullwidth letters in the domain' => "\u{FF45}xample.test",
            'the ideographic full stop for the dot' => "example\u{3002}test",
        ];

        $cases = [];

        foreach (self::doors() as $door => [$path, $extra]) {
            foreach ($spellings as $name => $domain) {
                $cases["{$door}, {$name}"] = [$path, $extra, $domain];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[Test]
    #[DataProvider('everyDoorAndSpelling')]
    public function a_variant_spelling_of_a_throttled_staff_address_is_throttled_too(string $path, array $extra, string $domain): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->submit($path, 'admin@example.test', $extra)->getStatusCode(), 'The plain address was throttled inside its allowance.');
        }

        $this->submit($path, 'admin@example.test', $extra)->assertStatus(429);

        // Without the normalised key this spelling has a bucket of its own.
        $this->submit($path, 'admin@' . $domain, $extra)->assertStatus(
            429,
            'A different spelling of the same address got a fresh allowance of password guesses.',
        );
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[Test]
    #[DataProvider('doors')]
    public function one_ip_cannot_multiply_its_guesses_by_spelling_the_address_differently(string $path, array $extra): void
    {
        config(['auth.admin_throttle.per_ip_per_minute' => 8]);

        // Accented spellings of one address: the same user to a unicode_ci
        // column, and a bucket each to a per-address limiter, so before the
        // IP-only ceiling every one of these was a fresh set of five guesses.
        $spellings = [
            "admín@example.test", "admìn@example.test", "admîn@example.test", "admïn@example.test",
            "ádmin@example.test", "àdmin@example.test", "âdmin@example.test", "ädmin@example.test",
        ];

        foreach ($spellings as $address) {
            $this->assertNotSame(429, $this->submit($path, $address, $extra)->getStatusCode(), "{$address} was throttled inside the IP allowance.");
        }

        // The ninth, from the same IP: a brand-new spelling, and the plain address.
        $this->submit($path, "admin\u{00F1}@example.test", $extra)->assertStatus(429);
        $this->submit($path, 'admin@example.test', $extra)->assertStatus(429);
    }

    #[Test]
    public function the_ip_ceiling_is_shared_across_the_three_routes(): void
    {
        config(['auth.admin_throttle.per_ip_per_minute' => 6]);

        foreach (self::doors() as [$path, $extra]) {
            for ($i = 0; $i < 2; $i++) {
                $this->assertNotSame(429, $this->submit($path, "shared-{$i}-" . uniqid() . '@example.test', $extra)->getStatusCode());
            }
        }

        // Six requests across the three routes spent the allowance of all of them.
        $this->submit('/api/admin/forgot-password', 'anyone@example.test')->assertStatus(429);
        $this->submit('/api/admin/login', 'anyone@example.test', ['password' => 'x'])->assertStatus(429);
    }

    #[Test]
    public function an_office_behind_one_address_is_not_throttled_at_the_documented_number(): void
    {
        // Thirty people on one NAT sign in inside a minute and half of them
        // mistype once: sixty requests, none of them refused. This pins the
        // default (DECISIONS.md 2026-09-29, follow-ups): 60 a minute, 600 an hour.
        $this->assertSame(60, (int) config('auth.admin_throttle.per_ip_per_minute'));
        $this->assertSame(600, (int) config('auth.admin_throttle.per_ip_per_hour'));

        for ($person = 0; $person < 30; $person++) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->assertNotSame(
                    429,
                    $this->submit('/api/admin/login', "staff{$person}@office.test", ['password' => 'not-the-password-at-all'])->getStatusCode(),
                    "Staff member {$person}, attempt {$attempt}, was throttled.",
                );
            }
        }

        $this->submit('/api/admin/login', 'staff31@office.test', ['password' => 'x'])->assertStatus(429);
    }

    #[Test]
    public function the_hourly_ceiling_holds_when_the_minute_ceiling_is_not_reached(): void
    {
        config(['auth.admin_throttle.per_ip_per_hour' => 4]);

        for ($i = 0; $i < 4; $i++) {
            $this->assertNotSame(429, $this->submit('/api/admin/forgot-password', "slow{$i}@example.test")->getStatusCode());
            $this->travel(2)->minutes();
        }

        $this->submit('/api/admin/forgot-password', 'slow-again@example.test')->assertStatus(429);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function submit(string $path, string $email, array $extra = []): TestResponse
    {
        return $this->postJson($path, array_merge(['email' => $email], $extra));
    }
}
