<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A school can point its own hostname at this application and have it serve as
 * that school's portal.
 *
 * `/portal` deliberately carries no id — it is the address a school's OWN domain
 * serves, where the hostname identifies the organisation. Until a hostname could
 * be pointed straight here, the only thing that answered "which school?" was
 * `window.__PORTAL_MASJID__` injected by the Cloudflare Worker proxying
 * alrazischool.org/portal. A hostname aimed directly at this app has no proxy in
 * between, so the mapping has to live here.
 *
 * `withoutVite()` throughout: these assertions are about the Blade shell, and the
 * built manifest is not present in a test environment.
 */
class PortalHostTest extends TestCase
{
    private const HOST = 'portal.alrazischool.org';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['portal.hosts' => [self::HOST => 14]]);
    }

    #[Test]
    public function a_mapped_hostname_tells_the_page_which_school_it_is(): void
    {
        $this->get('https://' . self::HOST . '/portal')
            ->assertOk()
            ->assertSee('window.__PORTAL_MASJID__ = 14;', false);
    }

    /**
     * The value is cast to an int on the way out, so a mapping cannot become an
     * injection point even if the env var is edited carelessly.
     */
    #[Test]
    public function the_injected_value_is_always_a_bare_integer(): void
    {
        config(['portal.hosts' => [self::HOST => '14"; alert(1); //']]);

        $this->get('https://' . self::HOST . '/portal')
            ->assertOk()
            ->assertSee('window.__PORTAL_MASJID__ = 14;', false)
            ->assertDontSee('alert(1)', false);
    }

    /** An ordinary host is untouched and says nothing about any organisation. */
    #[Test]
    public function an_unmapped_hostname_claims_to_be_no_one(): void
    {
        $this->get('https://masjid.hopetechapps.com/portal')
            ->assertOk()
            ->assertDontSee('__PORTAL_MASJID__', false);
    }

    /**
     * Host headers are not case-sensitive and browsers do not normalise them for
     * us, so the lookup is done in lower case on both sides.
     */
    #[Test]
    public function the_hostname_match_ignores_case(): void
    {
        $this->get('https://Portal.AlRaziSchool.org/portal')
            ->assertOk()
            ->assertSee('window.__PORTAL_MASJID__ = 14;', false);
    }

    /**
     * A parent told "go to portal.alrazischool.org" types exactly that. Landing
     * them on a staff-shaped app root would be a dead end.
     */
    #[Test]
    public function the_root_of_a_mapped_hostname_is_the_portal(): void
    {
        $this->get('https://' . self::HOST)
            ->assertRedirect('/portal');
    }

    #[Test]
    public function the_root_of_an_ordinary_hostname_still_serves_the_app(): void
    {
        $this->get('https://masjid.hopetechapps.com')
            ->assertOk()
            ->assertDontSee('__PORTAL_MASJID__', false);
    }

    /**
     * The env format is a comma-separated list of host=id pairs. Anything that is
     * not one is dropped rather than half-parsed into a wrong organisation.
     */
    #[Test]
    public function malformed_env_entries_are_discarded_not_guessed_at(): void
    {
        $this->assertSame(
            ['good.example' => 7],
            $this->parseHosts('good.example=7, nonsense, =9, bad.example=abc, ,'),
        );
    }

    /** Re-evaluate config/portal.php with a given env value. */
    private function parseHosts(string $env): array
    {
        putenv('PORTAL_HOSTS=' . $env);
        $_ENV['PORTAL_HOSTS'] = $env;

        try {
            return (require base_path('config/portal.php'))['hosts'];
        } finally {
            putenv('PORTAL_HOSTS');
            unset($_ENV['PORTAL_HOSTS']);
        }
    }
}
