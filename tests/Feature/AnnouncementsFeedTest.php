<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mobile + tvOS announcements feed.
 *
 * ## The measured failure
 *
 * `index()` partitioned the list and DISCARDED every announcement without an
 * image, to protect an iOS build whose `image` was a non-optional `Gallery`.
 * Its comment said this "should never fire — the admin form requires an image".
 *
 * On 2026-08-21 it was firing for every announcement on the platform. The
 * `media` table is empty, so all of Burlington's live announcements and all of
 * MEC's were withheld: the phone tab was blank, and the lobby board read
 * "No announcements" while ten were current. It took a photograph of the screen
 * on the masjid wall to find, because the endpoint returned `200 []` and the
 * only trace was a `warning` nobody was reading.
 *
 * ## What the clients actually do
 *
 * `MasjidKit/Models/Announcement.swift` now reads `public let image: Gallery?`,
 * and the tvOS carousel keeps a slide with EITHER an image or text:
 *
 *     items.filter { $0.image?.originalUrl != nil || $0.carouselText != nil }
 *
 * so a text-only notice has always been renderable. The stub this file pins is
 * for the older non-optional build still in the field: `Gallery` requires only
 * `id`, so the stub decodes there too, and its null URLs stop any client
 * drawing a picture that does not exist.
 */
class AnnouncementsFeedTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        $this->masjid = Masjid::create([
            'name' => 'Feed Masjid '.uniqid(),
            'email' => 'feed-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);
    }

    private function announce(string $title): Announcement
    {
        return Announcement::create([
            'masjid_id' => $this->masjid->id,
            'title' => $title,
            'summary' => $title.' — the line a board renders',
            'details' => $title.' — the long body',
            'text' => $title,
            'start_date' => now()->subWeek()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function feed(): array
    {
        return $this->getJson("/api/mobile/masjids/{$this->masjid->id}/announcements")
            ->assertStatus(200)
            ->json('data');
    }

    #[Test]
    public function an_announcement_without_an_image_is_still_served(): void
    {
        $this->announce('Masjid Cook Out');
        $this->announce('Qur\'an Class');

        $feed = $this->feed();

        $this->assertCount(2, $feed, 'imageless announcements were dropped from the feed');
        $this->assertEqualsCanonicalizing(
            ['Masjid Cook Out', "Qur'an Class"],
            array_column($feed, 'title'),
        );
    }

    #[Test]
    public function the_image_field_is_present_and_decodable_even_when_there_is_no_image(): void
    {
        // The image envelope is present with an `id` (an older build decodes it
        // into a non-optional object) AND a NON-NULL url. The URLs used to be
        // null, which was itself a crash: the current Flutter carousel
        // force-unwraps image.originalUrl!, so a null there blanks the whole
        // Announcements tab (the featuresIcons class, one collection over). A
        // served placeholder is safe for both — the old build draws it, the new
        // build's `!` never fires. See App\Support\MobileMedia.
        $this->announce('No Picture');

        $row = $this->feed()[0];

        $this->assertArrayHasKey('image', $row);
        $this->assertIsArray($row['image']);
        $this->assertSame(0, $row['image']['id']);
        $this->assertNotNull($row['image']['original_url'], 'a null original_url here crashes the carousel that force-unwraps it');
        $this->assertNotNull($row['image']['preview_url']);
        $this->assertStringContainsString('mobile-assets/placeholder', $row['image']['original_url']);
    }

    #[Test]
    public function the_body_the_board_renders_survives(): void
    {
        // tvOS builds its slide text from `summary`, falling back to `details`.
        // A feed that returns rows without them renders blank cards.
        $this->announce('Cook Out & Bazaar');

        $row = $this->feed()[0];

        $this->assertStringContainsString('the line a board renders', $row['summary']);
        $this->assertNotEmpty($row['details']);
    }

    #[Test]
    public function the_feed_is_still_scoped_to_one_masjid(): void
    {
        $other = Masjid::create([
            'name' => 'Other '.uniqid(),
            'email' => 'other-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);

        Announcement::create([
            'masjid_id' => $other->id,
            'title' => 'Not Ours', 'summary' => 'x', 'details' => 'x', 'text' => 'x',
            'start_date' => now()->subWeek()->toDateString(),
        ]);

        $this->announce('Ours');

        $this->assertSame(['Ours'], array_column($this->feed(), 'title'));
    }
}
