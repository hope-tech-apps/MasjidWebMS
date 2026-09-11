<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/forms.php's payment keys (DECISIONS.md 2026-09-11), evaluated against
 * the environment an operator could give them.
 *
 * The one that matters is `payment_return_origins`, the allowlist a Stripe
 * return URL is built on. It must FAIL CLOSED: unset is an empty list, and a
 * wildcard, a path or anything else that is not a bare origin is dropped rather
 * than trusted downstream — the reason it is not `cors.allowed_origins`, which
 * defaults to ['*'].
 */
class FormPaymentConfigTest extends TestCase
{
    private const KEYS = [
        'FORMS_PAYMENT_RETURN_ORIGINS',
        'FORMS_SUBMIT_PER_HOUR',
        'FORMS_CODE_PER_HOUR',
        'FORMS_STAFF_TOKEN_TTL_MINUTES',
    ];

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }

        parent::tearDown();
    }

    #[Test]
    public function unset_the_return_allowlist_is_empty_and_the_limits_are_the_briefs(): void
    {
        $config = $this->evaluate([]);

        $this->assertSame([], $config['payment_return_origins'], 'unset must fail closed, never fall back');
        $this->assertSame(8, $config['submit_per_hour'], 'the limit the public submit has always had');
        $this->assertSame(60, $config['code_per_hour']);
        $this->assertSame(720, $config['staff_token_ttl_minutes']);
    }

    #[Test]
    public function only_bare_origins_reach_the_return_allowlist(): void
    {
        $config = $this->evaluate([
            'FORMS_PAYMENT_RETURN_ORIGINS' => ' https://mec.hopetechapps.com/ ,*, HTTPS://MEC-Web.pages.dev,'
                . 'https://evil.example/path,https://*.pages.dev,javascript:alert(1),http://localhost:3000,,'
                . 'https://mec.hopetechapps.com',
        ]);

        $this->assertSame([
            'https://mec.hopetechapps.com',
            'https://mec-web.pages.dev',
            'http://localhost:3000',
        ], $config['payment_return_origins']);
    }

    #[Test]
    public function a_typo_cannot_close_a_limit_outright(): void
    {
        $config = $this->evaluate([
            'FORMS_SUBMIT_PER_HOUR' => '0',
            'FORMS_CODE_PER_HOUR' => 'sixty',
            'FORMS_STAFF_TOKEN_TTL_MINUTES' => '-5',
        ]);

        $this->assertSame(1, $config['submit_per_hour']);
        $this->assertSame(1, $config['code_per_hour']);
        $this->assertSame(1, $config['staff_token_ttl_minutes']);

        // …while a real value is honoured: festival week can raise the submit limit.
        $this->assertSame(40, $this->evaluate(['FORMS_SUBMIT_PER_HOUR' => '40'])['submit_per_hour']);
    }

    /**
     * config/forms.php evaluated afresh under $env, as config:cache would read it.
     *
     * @param  array<string,string>  $env
     * @return array<string,mixed>
     */
    private function evaluate(array $env): array
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, $env[$key] ?? null);
        }

        return require config_path('forms.php');
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
