<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\Environment;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * T-040 W1 — the three things that must be true when this application runs as
 * something other than production, and the matching things that must NOT change
 * when it runs as production.
 *
 * Every "staging" case here is paired with its "production" case on purpose.
 * The whole slice is a promise that production is untouched, and a one-sided
 * test cannot keep it: a middleware that stamped `X-Robots-Tag` unconditionally
 * would pass every staging assertion in this file while quietly de-indexing the
 * live site.
 *
 * No database: nothing under test reads one, and `/api/*` 404s and `/robots.txt`
 * are answered without touching it. Sqlite-in-memory is still pinned in setUp so
 * an ambient `.env` on a CI box can never point a stray connection at a real
 * database.
 */
class StagingSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * Re-run only the provider's HTTPS decision against the CURRENT config.
     *
     * The provider booted long before a test could set `app.force_https`, so the
     * decision has to be replayed. Reflection rather than a public method
     * because the seam is genuinely private — nothing in the application should
     * be able to re-force the scheme mid-request — and calling boot() instead
     * would re-register an event listener and re-observe the User model.
     */
    private function replayForceHttpsDecision(): void
    {
        $method = new ReflectionMethod(AppServiceProvider::class, 'forceHttpsWhenConfigured');
        $method->setAccessible(true);
        $method->invoke(new AppServiceProvider($this->app));
    }

    // ---------------------------------------------------------------- HTTPS --

    #[Test]
    public function generated_urls_stay_http_when_force_https_is_off(): void
    {
        URL::forceScheme(null);
        // Pin the root so the assertion cannot pass by accident on a CI box
        // whose ambient APP_URL already starts with https://.
        URL::forceRootUrl('http://staging.example.test');

        config(['app.force_https' => false]);
        $this->replayForceHttpsDecision();

        $this->assertSame('http://staging.example.test/connect/1/return', url('/connect/1/return'));
    }

    #[Test]
    public function generated_urls_become_https_when_force_https_is_on(): void
    {
        URL::forceScheme(null);
        URL::forceRootUrl('http://staging.example.test');

        config(['app.force_https' => true]);
        $this->replayForceHttpsDecision();

        // This is the value that ends up in a Stripe return_url and in an
        // emailed password-reset link, neither of which may be http://.
        $this->assertSame('https://staging.example.test/connect/1/return', url('/connect/1/return'));
    }

    #[Test]
    public function force_https_is_keyed_off_config_not_the_environment_name(): void
    {
        // The defect this slice fixes: APP_ENV=staging behind TLS used to emit
        // http:// everywhere, because the provider asked for the environment
        // NAME instead of asking whether this deployment is behind TLS.
        config(['app.env' => 'staging', 'app.force_https' => true]);

        URL::forceScheme(null);
        URL::forceRootUrl('http://staging.example.test');
        $this->replayForceHttpsDecision();

        $this->assertSame('https://staging.example.test/x', url('/x'));
    }

    #[Test]
    public function the_force_https_default_is_on_for_production_and_off_for_staging(): void
    {
        // Prod behaviour is "unchanged by construction" only if the DEFAULT
        // reproduces the old `APP_ENV === 'production'` test, so evaluate the
        // real config file against a real environment rather than trusting the
        // claim. FORCE_HTTPS must be absent for the default to be exercised.
        $this->assertTrue($this->forceHttpsDefaultFor('production'));
        $this->assertFalse($this->forceHttpsDefaultFor('staging'));
        $this->assertFalse($this->forceHttpsDefaultFor('local'));
    }

    /**
     * Evaluate `config/app.php`'s `force_https` default with APP_ENV set to the
     * given value and FORCE_HTTPS unset.
     *
     * All three of $_ENV, $_SERVER and putenv are written because Laravel's Env
     * repository reads the superglobals BEFORE getenv(), so setting only one of
     * them would leave the phpunit.xml value (`testing`) winning and the test
     * would silently assert nothing. Everything is restored afterwards.
     */
    private function forceHttpsDefaultFor(string $appEnv): bool
    {
        $saved = [];
        foreach (['APP_ENV', 'FORCE_HTTPS'] as $key) {
            $saved[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }

        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $appEnv;
        putenv("APP_ENV={$appEnv}");
        unset($_ENV['FORCE_HTTPS'], $_SERVER['FORCE_HTTPS']);
        putenv('FORCE_HTTPS');

        try {
            $config = require base_path('config/app.php');

            return (bool) $config['force_https'];
        } finally {
            foreach ($saved as $key => $values) {
                if ($values['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $values['env'];
                }

                if ($values['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $values['server'];
                }

                $values['getenv'] === false ? putenv($key) : putenv("{$key}={$values['getenv']}");
            }
        }
    }

    // ------------------------------------------------------------- noindex --

    #[Test]
    public function responses_carry_noindex_when_the_environment_is_staging(): void
    {
        config(['app.env' => 'staging']);

        // A route that exists in every environment and needs neither the Vite
        // manifest nor the database: the JSON 404 renderer. Global middleware
        // wraps it, which is the point — the header must be on EVERY response,
        // API included, not only on rendered pages.
        $this->getJson('/api/no-such-route-exists')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function responses_carry_no_robots_header_in_production(): void
    {
        config(['app.env' => 'production']);

        $this->getJson('/api/no-such-route-exists')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    // ----------------------------------------------------------- robots.txt --

    #[Test]
    public function robots_txt_disallows_everything_on_staging(): void
    {
        config(['app.env' => 'staging']);

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame("User-agent: *\nDisallow: /\n", $response->getContent());
    }

    #[Test]
    public function robots_txt_is_not_answered_by_the_application_in_production(): void
    {
        config(['app.env' => 'production']);

        // Production keeps its STATIC public/robots.txt, which nginx serves
        // before PHP is reached. The application must not offer a second,
        // contradictory answer, so the route stands aside with a 404.
        $this->get('/robots.txt')->assertNotFound();
    }

    #[Test]
    public function robots_txt_is_not_swallowed_by_the_spa_catch_all(): void
    {
        // The `/{any}` SPA route matches everything that is not /api, so a
        // robots route declared after it would render the Vue shell as
        // text/html and no crawler directive would ever be served. Order is the
        // whole mechanism; this is what notices if someone moves it.
        config(['app.env' => 'staging']);

        $this->get('/robots.txt')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    // ------------------------------------------------------ Environment API --

    #[Test]
    public function environment_helper_labels_non_production_and_stays_silent_in_production(): void
    {
        config(['app.env' => 'production']);
        $this->assertTrue(Environment::isProduction());
        $this->assertSame('', Environment::label());

        config(['app.env' => 'staging']);
        $this->assertFalse(Environment::isProduction());
        $this->assertSame('STAGING', Environment::label());
        $this->assertSame('staging', Environment::name());
    }

    #[Test]
    public function environment_helper_treats_a_missing_env_as_production(): void
    {
        // Fail SAFE, not fail loud: a box that lost APP_ENV must not stamp a
        // stray ribbon and a noindex header across the live site.
        config(['app.env' => null]);

        $this->assertTrue(Environment::isProduction());
        $this->assertSame('', Environment::label());
    }

    #[Test]
    public function the_spa_shell_advertises_the_environment_to_the_browser(): void
    {
        // The ribbon can only render what the document tells it. Asserted on
        // the Blade source rather than a rendered response because rendering
        // the shell needs a Vite manifest that a source-only CI copy does not
        // have (see tests/CLAUDE.md on ExampleTest).
        $blade = file_get_contents(resource_path('views/vue-app-index.blade.php'));

        $this->assertStringContainsString('window.__APP_ENV__', $blade);
        $this->assertStringContainsString('Environment::name()', $blade);
    }
}
