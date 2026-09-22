<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website's announcements, on the home page (three) and on /announcements,
 * are newest first with `id` ascending breaking created_at ties: the services
 * fix (ServiceListOrderTest) applied to the other list on the same pages.
 *
 * Announcements tie too. On production at 00:30 UTC on 2026-09-22, org 1 had
 * 41–43 in one second and 44–49 in the next, and MEC had 56 and 57 in one
 * second. created_at desc, id asc was what both lists returned for every
 * organisation at 3, 6, 9, 10, 12 and 20 per page and every offset, so the
 * tie-break changes nothing anyone sees.
 *
 * ## Why the fixture builds an index
 *
 * With no index, SQLite returns ties in rowid order, so a plain fixture passes
 * with or without the tie-break. An index on (masjid_id, created_at) makes
 * SQLite walk it backwards and hand ties back highest id first, which MySQL is
 * equally free to do. Every test asserts that premise first, so these
 * assertions fail when the tie-break is missing.
 */
class AnnouncementListOrderTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    /** @var list<int> ids in the order both lists must show them */
    private array $expected;

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

        Schema::table('announcements', fn (Blueprint $t) => $t->index(['masjid_id', 'created_at'], 'announcements_tie_probe'));

        $this->masjid = Masjid::create([
            'name' => 'Order Masjid '.uniqid(),
            'email' => 'order-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);

        // Six in one second, then three in a later one.
        $older = array_map(fn () => $this->announcement('2026-07-23 21:37:25'), range(1, 6));
        $newer = array_map(fn () => $this->announcement('2026-08-07 14:58:54'), range(1, 3));
        $this->expected = [...$newer, ...$older];

        $untied = Announcement::where('masjid_id', $this->masjid->id)->latest()->limit(3)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
        $this->assertNotSame(array_slice($this->expected, 0, 3), $untied, 'Fixture premise: sorted by created_at alone, '
            .'the tied rows must come back in some other order, or this test cannot tell whether the tie-break is there.');
    }

    #[Test]
    public function the_home_three_are_newest_first_with_ties_in_id_order(): void
    {
        $this->assertSame(array_slice($this->expected, 0, 3), $this->ids('/api/v1/home', 'data.announcements'));
    }

    #[Test]
    public function the_announcements_page_is_newest_first_with_ties_in_id_order(): void
    {
        $this->assertSame($this->expected, $this->ids('/api/v1/announcements?per_page=9', 'data.items'));

        $paged = [];
        foreach ([1, 2, 3] as $page) {
            $paged = [...$paged, ...$this->ids("/api/v1/announcements?per_page=3&page={$page}", 'data.items')];
        }
        $this->assertSame($this->expected, $paged, 'per_page=3: no announcement repeated or skipped');
    }

    #[Test]
    public function an_older_announcement_does_not_change_the_first_page(): void
    {
        $inserted = $this->announcement('2026-07-23 21:37:24');

        $this->assertSame(array_slice($this->expected, 0, 3), $this->ids('/api/v1/home', 'data.announcements'));
        $this->assertSame($this->expected, $this->ids('/api/v1/announcements?per_page=9', 'data.items'));
        $this->assertSame([...$this->expected, $inserted], $this->ids('/api/v1/announcements?per_page=20', 'data.items'));
    }

    private function announcement(string $createdAt): int
    {
        $this->travelTo(Carbon::parse($createdAt));
        $announcement = Announcement::create([
            'masjid_id' => $this->masjid->id,
            'title' => "Notice {$createdAt} ".uniqid(),
            'details' => 'A notice',
            'text' => 'A notice',
            'start_date' => '2026-07-01',
        ]);
        $this->travelBack();

        return $announcement->id;
    }

    /** @return list<int> */
    private function ids(string $uri, string $key): array
    {
        $rows = $this->getJson($uri, ['masjid-id' => (string) $this->masjid->id])
            ->assertOk()
            ->json($key);

        return array_map('intval', array_column($rows, 'id'));
    }
}
