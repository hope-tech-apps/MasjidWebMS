<?php

namespace Tests\Feature;

use App\Models\AppMenuSetting;
use App\Support\AppMenu;
use App\Support\Canary\AppMenuKillSwitch;
use App\Support\Canary\DarkLaunchSwitch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * The switch tenancy:canary reads to decide an endpoint is dark ON PURPOSE.
 *
 * TenancyCanaryTest pins what the canary DOES with the answer. This file pins
 * the answer itself, and the two properties the canary's docblock promises
 * about how it is obtained:
 *
 *  - it is the SAME switch the endpoint obeys. A canary that skipped /menu on
 *    a different row than the one AppMenuController reads would be skipping a
 *    live endpoint.
 *  - it is READ-ONLY. AppMenu::killed() — the endpoint's reader — goes through
 *    Cache::remember, which upserts a row into `cache` on the database store,
 *    production's default. The canary is declared read-only, so it reads the
 *    row uncached. phpunit runs on the `array` store, where no row count can
 *    see a cache write, so the test that proves it switches store.
 */
class CanaryDarkLaunchSwitchTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
    }

    #[Test]
    public function the_canary_reads_the_kill_switch_the_endpoint_obeys(): void
    {
        // No row: live, to both readers.
        $this->assertNull(AppMenuKillSwitch::darkBecause());
        $this->assertFalse(AppMenu::killed());

        Artisan::call('app-menu:kill', ['--reason' => 'S1: the menu stays dark until S2b', '--by' => 'canary-test']);

        $this->assertNotNull(AppMenuKillSwitch::darkBecause());
        $this->assertTrue(AppMenu::killed());

        Artisan::call('app-menu:restore', ['--by' => 'canary-test']);

        $this->assertNull(AppMenuKillSwitch::darkBecause());
        $this->assertFalse(AppMenu::killed());

        // Unreadable — the table does not exist yet. The endpoint fails open to
        // LIVE; the switch THROWS, and the canary reads a throw as live and
        // probes. The two agree on the answer; only the canary says why.
        Schema::drop('app_menu_settings');
        Cache::forget(AppMenu::KILL_CACHE_KEY);

        $this->assertFalse(AppMenu::killed());

        $this->expectException(QueryException::class);

        AppMenuKillSwitch::darkBecause();
    }

    #[Test]
    public function reading_the_kill_switch_issues_one_select_and_writes_no_cache(): void
    {
        // Production's default; phpunit uses `array`, which no row count can see.
        config(['cache.default' => 'database']);

        AppMenuSetting::create([
            'menu_disabled' => true,
            'reason' => 'S1: the menu stays dark until S2b',
            'updated_by' => 'canary-test',
        ]);

        Cache::forget(AppMenu::KILL_CACHE_KEY);

        $rows = DB::table('cache')->count();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $evidence = AppMenuKillSwitch::darkBecause();

        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        // Who pulled the lever and why — this lands in the scheduled log line.
        $this->assertStringContainsString('S1: the menu stays dark until S2b', (string) $evidence);
        $this->assertStringContainsString('canary-test', (string) $evidence);

        $this->assertCount(1, $queries, 'the canary\'s read of the kill switch is not one query: '.json_encode($queries));
        $this->assertStringStartsWith('select', strtolower($queries[0]['query']));
        $this->assertStringContainsString('app_menu_settings', $queries[0]['query']);
        $this->assertSame($rows, DB::table('cache')->count(), 'reading the kill switch wrote a cache row');

        // The control, which proves this test CAN see a cache write: the
        // endpoint's reader writes one. This test fails if AppMenuKillSwitch is
        // ever changed to call killed().
        $this->assertTrue(AppMenu::killed());
        $this->assertSame($rows + 1, DB::table('cache')->count(),
            'killed() wrote no cache row, so this test cannot see the write it guards against');
    }

    #[Test]
    public function every_declared_dark_launch_names_a_real_public_get_and_a_real_switch(): void
    {
        // The canary treats a bad declaration as LIVE and probes, which is the
        // safe direction at run time. This is the same vetting at review time,
        // so a bad entry never reaches production to be discovered as a
        // NOT REACHED at 3am.
        $declared = config('canary.dark_launches');

        $this->assertIsArray($declared);
        $this->assertNotEmpty($declared);

        foreach ($declared as $uri => $entry) {
            $switch = $entry['switch'];

            $this->assertTrue(class_exists($switch), "{$uri}: switch {$switch} is not a class");
            $this->assertTrue(is_a($switch, DarkLaunchSwitch::class, true),
                "{$uri}: {$switch} does not implement DarkLaunchSwitch, and the canary calls nothing else");
            $this->assertTrue((new \ReflectionClass($switch))->isFinal(), "{$uri}: {$switch} is not final");
            $this->assertSame([], class_uses($switch), "{$uri}: {$switch} uses a trait");

            $this->assertNotSame('', trim((string) ($entry['reason'] ?? '')), "{$uri}: no reason");
            $this->assertNotSame('', trim((string) ($entry['clears_with'] ?? '')), "{$uri}: no clears_with");

            $answers = $entry['answers'] ?? null;

            $this->assertIsInt($answers, "{$uri}: answers is not an int");
            $this->assertGreaterThanOrEqual(400, $answers, "{$uri}: answers is not a 4xx");
            $this->assertLessThanOrEqual(499, $answers, "{$uri}: answers is not a 4xx");
            $this->assertNotSame(429, $answers, "{$uri}: 429 is a throttle, never a dark answer");

            foreach ((array) config('canary.core_prefixes') as $prefix) {
                $this->assertFalse($uri === $prefix || str_starts_with($uri, $prefix.'/'),
                    "{$uri}: on the graded surface ({$prefix}), where nothing may be declared dark");
            }

            $route = collect(Route::getRoutes()->getRoutes())->first(
                static fn ($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true)
            );

            $this->assertNotNull($route, "{$uri}: no GET route has this uri — a stale or mistyped declaration");
        }
    }
}
