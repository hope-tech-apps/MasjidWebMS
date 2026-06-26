<?php

namespace Tests\Feature\ContentUnification;

use App\Models\ContactReason;
use App\Models\DonationLink;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\Page;
use App\Models\Section;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1 content-unification — Web V1 page API.
 *
 * Asserts the four entity-bound section types render the dedicated model's
 * content (single source of truth) through GET /api/v1/pages/{slug}, that
 * editing the model changes the section output, and that the placement-level
 * `platforms` flag round-trips through the serializer.
 *
 * Ordering note: unlike the splash suite (which calls config() before
 * parent::setUp() and therefore depends on a pre-booted container), this suite
 * boots the app FIRST via parent::setUp(), THEN points the default connection
 * at sqlite :memory: and migrates. That makes each test self-contained and
 * runnable in isolation.
 */
class PageSectionContentUnificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Boot done — now force an isolated in-memory sqlite DB and migrate it.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Reconnect so the :memory: handle is the one migrations run against,
        // and run the full migration set against it.
        \DB::purge('sqlite');
        \DB::reconnect('sqlite');

        // The masjids migration hardcodes a MySQL collation (utf8mb4_bin) with
        // no driver guard, which sqlite doesn't know. Register a custom sqlite
        // collation of that name (binary comparison) so the schema is portable
        // in tests. Test-only shim — does not touch production migrations.
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

    /* ---------------------------------------------------------------- */

    private function makeMasjid(array $overrides = []): Masjid
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

    /**
     * Create a page with one section attached, returning [page, section].
     *
     * @return array{0:Page,1:Section}
     */
    private function makePageWithSection(
        Masjid $masjid,
        string $sectionType,
        array $content,
        ?array $platforms = null
    ): array {
        $page = Page::create([
            'masjid_id' => $masjid->id,
            'slug' => 'test-page-' . uniqid(),
            'title' => 'Test Page',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => $sectionType,
            'title' => 'Stored Title',
            'content' => $content,
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, [
            'order' => 1,
            'platforms' => $platforms,
        ]);

        return [$page, $section];
    }

    private function getPage(Masjid $masjid, string $slug): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('masjid-id', (string) $masjid->id)
            ->getJson("/api/v1/pages/{$slug}");
    }

    /* ---------------- about_us ---------------- */

    #[Test]
    public function about_us_section_renders_masjid_about_model_over_stored_content(): void
    {
        $masjid = $this->makeMasjid();

        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'Canonical about text from the model',
            'mission' => 'Canonical mission',
            'vision' => 'Canonical vision',
        ]);

        // Section carries its OWN (now ignored) stored copy.
        [$page] = $this->makePageWithSection($masjid, 'about_us', [
            'title' => 'About Us',
            'subtitle' => 'stored subtitle',
            'text' => 'stored about text (should be overridden)',
            'image_url' => null,
            'button_text' => 'Learn More',
        ]);

        $section = $this->getPage($masjid, $page->slug)
            ->assertStatus(200)
            ->json('data.sections.0');

        // Model wins for text + subtitle(mission); presentation fields kept.
        $this->assertSame('Canonical about text from the model', $section['content']['text']);
        $this->assertSame('Canonical mission', $section['content']['subtitle']);
        $this->assertSame('About Us', $section['content']['title']);
        $this->assertSame('Learn More', $section['content']['button_text']);
    }

    #[Test]
    public function editing_masjid_about_model_changes_about_us_section_output(): void
    {
        $masjid = $this->makeMasjid();

        $about = MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'First version',
            'mission' => '',
            'vision' => '',
        ]);

        [$page] = $this->makePageWithSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'stored',
            'button_text' => '',
        ]);

        $first = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.text');
        $this->assertSame('First version', $first);

        // Edit the MODEL — the single source — and re-read.
        $about->update(['about' => 'Second version']);

        $second = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.text');
        $this->assertSame('Second version', $second);
    }

    #[Test]
    public function about_us_keeps_stored_text_when_model_field_is_empty(): void
    {
        $masjid = $this->makeMasjid();
        // No MasjidAbout row at all -> stored content is the graceful fallback.

        [$page] = $this->makePageWithSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'fallback stored text',
            'button_text' => '',
        ]);

        $text = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.text');
        $this->assertSame('fallback stored text', $text);
    }

    /* ---------------- mission_vision ---------------- */

    #[Test]
    public function mission_vision_section_renders_model_mission_and_vision(): void
    {
        $masjid = $this->makeMasjid();

        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => '',
            'mission' => 'Model mission statement',
            'vision' => 'Model vision statement',
        ]);

        [$page] = $this->makePageWithSection($masjid, 'mission_vision', [
            'heading' => 'Our Mission & Vision',
            'layout' => 'side_by_side',
            'items' => [], // stored copy empty; model should drive it
        ]);

        $content = $this->getPage($masjid, $page->slug)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        // heading preserved (presentation), items rebuilt from the model.
        $this->assertSame('Our Mission & Vision', $content['heading']);
        $this->assertCount(2, $content['items']);
        $this->assertSame('Model mission statement', $content['items'][0]['content']);
        $this->assertSame('Model vision statement', $content['items'][1]['content']);
    }

    #[Test]
    public function editing_model_mission_changes_mission_vision_output(): void
    {
        $masjid = $this->makeMasjid();
        $about = MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => '',
            'mission' => 'Mission v1',
            'vision' => 'Vision v1',
        ]);

        [$page] = $this->makePageWithSection($masjid, 'mission_vision', [
            'heading' => 'H',
            'items' => [],
        ]);

        $before = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.items.0.content');
        $this->assertSame('Mission v1', $before);

        $about->update(['mission' => 'Mission v2']);

        $after = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.items.0.content');
        $this->assertSame('Mission v2', $after);
    }

    /* ---------------- donation ---------------- */

    #[Test]
    public function donation_section_renders_donation_link_model(): void
    {
        $masjid = $this->makeMasjid();

        DonationLink::create([
            'masjid_id' => $masjid->id,
            'title' => 'Support Our Masjid',
            'message' => 'Your donation keeps the doors open',
            'link' => 'https://give.example.org',
        ]);

        [$page] = $this->makePageWithSection($masjid, 'donation', [
            'title' => 'stored donate title',
            'subtitle' => 'stored donate subtitle',
            'image_url' => null,
            'button_text' => 'Donate Now',
        ]);

        $content = $this->getPage($masjid, $page->slug)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        $this->assertSame('Support Our Masjid', $content['title']);
        $this->assertSame('Your donation keeps the doors open', $content['subtitle']);
        $this->assertSame('https://give.example.org', $content['link']);
        // button_text is presentation-only and preserved.
        $this->assertSame('Donate Now', $content['button_text']);
    }

    #[Test]
    public function editing_donation_link_model_changes_donation_section_output(): void
    {
        $masjid = $this->makeMasjid();
        $donation = DonationLink::create([
            'masjid_id' => $masjid->id,
            'title' => 'Title A',
            'message' => 'Message A',
            'link' => 'https://give.example.org',
        ]);

        [$page] = $this->makePageWithSection($masjid, 'donation', [
            'title' => 'stored',
            'subtitle' => 'stored',
            'button_text' => 'Donate Now',
        ]);

        $first = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.title');
        $this->assertSame('Title A', $first);

        $donation->update(['title' => 'Title B']);

        $second = $this->getPage($masjid, $page->slug)->json('data.sections.0.content.title');
        $this->assertSame('Title B', $second);
    }

    /* ---------------- contact_form ---------------- */

    #[Test]
    public function contact_form_section_injects_masjid_contact_block_and_reasons(): void
    {
        $masjid = $this->makeMasjid([
            'phone' => '+15551234567',
            'email' => 'info@masjid.test',
            'address' => '123 Mosque Ave',
        ]);

        ContactReason::create(['masjid_id' => $masjid->id, 'name' => 'General Inquiry', 'is_active' => true, 'order' => 1]);
        ContactReason::create(['masjid_id' => $masjid->id, 'name' => 'Marriage Services', 'is_active' => true, 'order' => 2]);
        ContactReason::create(['masjid_id' => $masjid->id, 'name' => 'Hidden Reason', 'is_active' => false, 'order' => 3]);

        [$page] = $this->makePageWithSection($masjid, 'contact_form', [
            'title' => 'Contact Us',
            'subtitle' => 'Reach out',
            'button_text' => 'Send Message',
            'show_map' => true,
        ]);

        $content = $this->getPage($masjid, $page->slug)
            ->assertStatus(200)
            ->json('data.sections.0.content');

        // Presentation fields preserved.
        $this->assertSame('Contact Us', $content['title']);
        $this->assertTrue($content['show_map']);
        // Contact block injected from the masjid.
        $this->assertSame('+15551234567', $content['contact']['phone']);
        $this->assertSame('info@masjid.test', $content['contact']['email']);
        $this->assertSame('123 Mosque Ave', $content['contact']['address']);
        // Only active reasons, in order.
        $this->assertSame(['General Inquiry', 'Marriage Services'], $content['reasons']);
    }

    /* ---------------- platforms round-trip ---------------- */

    #[Test]
    public function platforms_round_trips_through_v1_serializer(): void
    {
        $masjid = $this->makeMasjid();

        [$page] = $this->makePageWithSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'x',
            'button_text' => '',
        ], ['web']);

        $platforms = $this->getPage($masjid, $page->slug)
            ->assertStatus(200)
            ->json('data.sections.0.platforms');

        $this->assertSame(['web'], $platforms);
    }

    #[Test]
    public function null_platforms_placement_defaults_to_both(): void
    {
        $masjid = $this->makeMasjid();

        // platforms left null on the placement -> serializer normalizes to both.
        [$page] = $this->makePageWithSection($masjid, 'about_us', [
            'title' => 'About Us',
            'text' => 'x',
            'button_text' => '',
        ], null);

        $platforms = $this->getPage($masjid, $page->slug)
            ->assertStatus(200)
            ->json('data.sections.0.platforms');

        $this->assertSame(['web', 'mobile'], $platforms);
    }

    #[Test]
    public function platforms_persists_on_the_pivot_when_attached(): void
    {
        $masjid = $this->makeMasjid();

        [$page, $section] = $this->makePageWithSection($masjid, 'donation', [
            'title' => 't', 'subtitle' => 's', 'button_text' => 'Donate Now',
        ], ['mobile']);

        // Read the raw pivot back to prove persistence.
        $fresh = $page->fresh()->sections()->withPivot('platforms')->first();
        $this->assertSame(['mobile'], $fresh->pivot->platforms);
    }
}
