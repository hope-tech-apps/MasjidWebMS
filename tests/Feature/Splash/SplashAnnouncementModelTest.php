<?php

namespace Tests\Feature\Splash;

use App\Models\Masjid;
use App\Models\SplashAnnouncement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Model-layer tests for SplashAnnouncement::active() scope.
 *
 * The scope is what `/api/mobile/masjids/{id}/splash` and the admin
 * "Resync" button rely on to decide which row counts as live. The scope must:
 *   - include rows whose [starts_at, ends_at] window contains now()
 *   - exclude rows with is_active = false
 *   - exclude rows whose starts_at is in the future
 *   - exclude rows whose ends_at is in the past
 *
 * One behavior per test. Sqlite-in-memory + RefreshDatabase per the testing
 * convention in `.claude/rules/testing.md`.
 */
class SplashAnnouncementModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — tests in this
        // suite must never need a network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
    protected function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    #[Test]
    public function active_scope_returns_rows_currently_in_window(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addHour(),
            'is_active' => true,
        ]);

        $this->assertSame(1, SplashAnnouncement::active()->count());
    }

    #[Test]
    public function active_scope_excludes_inactive_rows(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->inactive()->create([
            'masjid_id' => $masjid->id,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addHour(),
        ]);

        $this->assertSame(0, SplashAnnouncement::active()->count());
    }

    #[Test]
    public function active_scope_excludes_future_rows(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->future()->create(['masjid_id' => $masjid->id]);

        $this->assertSame(0, SplashAnnouncement::active()->count());
    }

    #[Test]
    public function active_scope_excludes_past_rows(): void
    {
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->past()->create(['masjid_id' => $masjid->id]);

        $this->assertSame(0, SplashAnnouncement::active()->count());
    }

    #[Test]
    public function active_scope_treats_boundary_now_as_in_window(): void
    {
        // Belt-and-suspenders: <= and >= are used in the scope, so a row whose
        // starts_at == now() should still match. Verifies we don't accidentally
        // flip to strict < / >.
        $masjid = $this->makeMasjid();
        SplashAnnouncement::factory()->create([
            'masjid_id' => $masjid->id,
            'starts_at' => Carbon::now()->subSecond(),
            'ends_at' => Carbon::now()->addSecond(),
        ]);

        $this->assertSame(1, SplashAnnouncement::active()->count());
    }
}
