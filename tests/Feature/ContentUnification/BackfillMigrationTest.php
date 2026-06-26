<?php

namespace Tests\Feature\ContentUnification;

use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\Page;
use App\Models\Section;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1 — non-destructive backfill migration behavior.
 *
 * Exercises the migration class
 * database/migrations/2026_06_25_000001_backfill_section_content_into_dedicated_models.php
 * directly (instantiate + up()) against an in-memory DB, asserting:
 *   - empty model gets seeded FROM the section (backfill)
 *   - non-empty model is KEPT; section copy is ignored (no overwrite)
 *   - both-non-empty-and-different is a CONFLICT (model kept, reported)
 */
class BackfillMigrationTest extends TestCase
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

        \DB::purge('sqlite');
        \DB::reconnect('sqlite');

        $pdo = \DB::connection('sqlite')->getPdo();
        if (method_exists($pdo, 'sqliteCreateCollation')) {
            $pdo->sqliteCreateCollation('utf8mb4_bin', static fn($a, $b) => strcmp($a, $b));
        }

        $this->artisan('migrate', ['--database' => 'sqlite'])->run();
    }

    protected function tearDown(): void
    {
        \DB::purge('sqlite');
        parent::tearDown();
    }

    private function migrationPath(): string
    {
        return base_path('database/migrations/2026_06_25_000001_backfill_section_content_into_dedicated_models.php');
    }

    private function runBackfill(): void
    {
        $migration = require $this->migrationPath();
        // Swallow the printed report so test output stays clean.
        ob_start();
        $migration->up();
        ob_end_clean();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeSection(Masjid $masjid, string $type, array $content): Section
    {
        return Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => $type,
            'content' => $content,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function backfills_about_into_empty_model(): void
    {
        $masjid = $this->makeMasjid();
        $this->makeSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'Section about copy',
        ]);

        $this->assertNull(MasjidAbout::where('masjid_id', $masjid->id)->first());

        $this->runBackfill();

        $about = MasjidAbout::where('masjid_id', $masjid->id)->first();
        $this->assertNotNull($about);
        $this->assertSame('Section about copy', $about->about);
    }

    #[Test]
    public function keeps_existing_model_about_and_ignores_section_copy(): void
    {
        $masjid = $this->makeMasjid();
        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'Existing model about',
            'mission' => '',
            'vision' => '',
        ]);

        $this->makeSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'Section about copy (should be ignored)',
        ]);

        $this->runBackfill();

        $about = MasjidAbout::where('masjid_id', $masjid->id)->first();
        // Model kept — section copy did NOT overwrite it.
        $this->assertSame('Existing model about', $about->about);
    }

    #[Test]
    public function backfills_donation_link_into_empty_model(): void
    {
        $masjid = $this->makeMasjid();
        $this->makeSection($masjid, 'donation', [
            'title' => 'Donate Title',
            'subtitle' => 'Donate Message',
            'link' => 'https://give.example.org',
            'button_text' => 'Donate Now',
        ]);

        $this->runBackfill();

        $donation = DonationLink::where('masjid_id', $masjid->id)->first();
        $this->assertNotNull($donation);
        $this->assertSame('Donate Title', $donation->title);
        $this->assertSame('Donate Message', $donation->message);
        $this->assertSame('https://give.example.org', $donation->link);
    }

    #[Test]
    public function backfills_mission_and_vision_from_items(): void
    {
        $masjid = $this->makeMasjid();
        $this->makeSection($masjid, 'mission_vision', [
            'heading' => 'Our Mission & Vision',
            'items' => [
                ['type' => 'mission', 'title' => 'Our Mission', 'content' => 'Mission copy'],
                ['type' => 'vision', 'title' => 'Our Vision', 'content' => 'Vision copy'],
            ],
        ]);

        $this->runBackfill();

        $about = MasjidAbout::where('masjid_id', $masjid->id)->first();
        $this->assertNotNull($about);
        $this->assertSame('Mission copy', $about->mission);
        $this->assertSame('Vision copy', $about->vision);
    }

    #[Test]
    public function conflict_keeps_model_when_both_differ(): void
    {
        $masjid = $this->makeMasjid();
        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'MODEL about wins',
            'mission' => '',
            'vision' => '',
        ]);
        $this->makeSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'SECTION about differs',
        ]);

        $this->runBackfill();

        $about = MasjidAbout::where('masjid_id', $masjid->id)->first();
        // Conflict policy: keep the model, never silently overwrite.
        $this->assertSame('MODEL about wins', $about->about);
    }
}
