<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The per-address sign-in allowance is one allowance per MAILBOX, not one per
 * way of spelling it.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). `familyLoginKey()`
 * bucketed on `strtolower(trim(raw))`. A limiter runs BEFORE the FormRequest
 * normalises the address, so every UTS46-equivalent spelling of one mailbox (a
 * soft hyphen, a zero-width space, fullwidth letters, math letters, U+3002 for
 * the dot) got a bucket of its own: the reviewer got a 200 on a variant after
 * the plain address had hit 429. A stranger who knows a parent's address could
 * therefore have sent them an unbounded stream of sign-in codes, or ground a
 * password, by varying the spelling.
 *
 * Each case spends the whole per-address allowance on the plain address, sees
 * the next plain request refused, and then asserts that a variant is refused too.
 * Without the fix the variant has a fresh bucket and is answered, not 429.
 */
class SignInThrottleKeyTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    /**
     * Spellings of `gmail.com` that UTS46 maps to it, and that the door therefore
     * treats as the same domain.
     *
     * @return array<string, array{0: string}>
     */
    public static function spellingsOfTheSameDomain(): array
    {
        return [
            'a soft hyphen inside the domain' => ["gma\u{00AD}il.com"],
            'a zero-width space inside the domain' => ["gma\u{200B}il.com"],
            'fullwidth letters' => ["\u{FF47}\u{FF4D}\u{FF41}\u{FF49}\u{FF4C}.com"],
            'a mathematical letter' => ["\u{1D420}mail.com"],
            'the ideographic full stop for the dot' => ["gmail\u{3002}com"],
            'capitals (one bucket before the fix too: the control)' => ['GMAIL.COM'],
        ];
    }

    /**
     * Each door with its own per-address ceiling: the config key that sets it,
     * and what it takes to spend one attempt.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function doors(): array
    {
        return [
            'the family door: request a code' => ['/api/family/masjids/%d/auth/request-code', 'family.login.requests_per_hour_per_address', []],
            'the family door: verify a code' => ['/api/family/masjids/%d/auth/verify-code', 'family.login.verifications_per_hour_per_address', ['code' => '000000']],
            'the family door: password' => ['/api/family/masjids/%d/auth/password', 'family.login.verifications_per_hour_per_address', ['password' => 'not-the-password-at-all']],
            'the app door: request a code' => ['/api/mobile/masjids/%d/auth/request-code', 'member.signup.requests_per_hour_per_address', []],
            'the app door: verify a code' => ['/api/mobile/masjids/%d/auth/verify-code', 'member.signup.verifications_per_hour_per_address', ['code' => '000000']],
            'the app door: password' => ['/api/mobile/masjids/%d/auth/password', 'member.signup.verifications_per_hour_per_address', ['password' => 'not-the-password-at-all']],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string}>
     */
    public static function everyDoorAndSpelling(): array
    {
        $cases = [];

        foreach (self::doors() as $doorName => [$path, $limitKey, $extra]) {
            foreach (self::spellingsOfTheSameDomain() as $spellingName => [$domain]) {
                $cases["{$doorName}, {$spellingName}"] = [$path, $limitKey, $extra, $domain];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[Test]
    #[DataProvider('everyDoorAndSpelling')]
    public function a_variant_spelling_of_a_throttled_address_is_throttled_too(string $path, string $limitKey, array $extra, string $domain): void
    {
        $limit = (int) config($limitKey);
        $this->assertGreaterThan(0, $limit);

        $plain = 'person@gmail.com';
        $variant = 'person@' . $domain;

        for ($i = 0; $i < $limit; $i++) {
            $this->assertNotSame(429, $this->submit($path, $plain, $extra)->getStatusCode(), 'The plain address was throttled inside its own allowance.');
        }

        $this->submit($path, $plain, $extra)->assertStatus(429);

        $this->submit($path, $variant, $extra)->assertStatus(
            429,
            'A different spelling of the same mailbox got a fresh allowance.',
        );
    }

    #[Test]
    public function a_different_address_still_has_an_allowance_of_its_own(): void
    {
        // The other half of the property: folding spellings together must not fold
        // different mailboxes together, or one stranger could lock a family out.
        $path = '/api/family/masjids/%d/auth/request-code';
        $limit = (int) config('family.login.requests_per_hour_per_address');

        for ($i = 0; $i <= $limit; $i++) {
            $this->submit($path, 'person@gmail.com');
        }

        $this->submit($path, 'person@gmail.com')->assertStatus(429);
        $this->submit($path, 'someone.else@gmail.com')->assertStatus(202);
    }

    #[Test]
    public function an_accented_domain_is_a_different_address_and_keeps_its_own_allowance(): void
    {
        // `gmaíl.com` is not `gmail.com`: the door converts it to punycode, so it
        // names another mailbox and is throttled as one. (The collation, not the
        // limiter, is why sign-in re-checks addresses exactly.)
        $path = '/api/family/masjids/%d/auth/request-code';
        $limit = (int) config('family.login.requests_per_hour_per_address');

        for ($i = 0; $i <= $limit; $i++) {
            $this->submit($path, 'person@gmail.com');
        }

        $this->submit($path, 'person@gmail.com')->assertStatus(429);
        $this->assertNotSame(429, $this->submit($path, "person@gma\u{00ED}l.com")->getStatusCode());
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function submit(string $path, string $email, array $extra = []): \Illuminate\Testing\TestResponse
    {
        // A real request clears the guard cache and the tenant; so must a test
        // between requests.
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->postJson(sprintf($path, $this->masjid->id), array_merge(['email' => $email], $extra));
    }
}
