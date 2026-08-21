<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the board on the masjid wall shows.
 *
 * ## The measured failure
 *
 * On 2026-08-21 a photograph of the lobby screen at Burlington read
 * "No announcements". Nothing was broken: `/signage` serves BROADCASTS whose
 * signage channel was selected and sent, and Burlington had never created one.
 * It had TEN live Announcements — the noun the admin dashboard puts in front of
 * staff — and the screen on their own wall ignored every one of them.
 *
 * That is a product defect rather than a crash, and it is invisible to a test
 * suite that only ever asks "does a sent signage broadcast appear".
 *
 * ## The rule this pins
 *
 * A broadcast sent to signage still wins, and a broadcast sent to OTHER channels
 * still never reaches the board — that gate is deliberate and is asserted below.
 * The fallback only decides what an otherwise EMPTY board shows, and the answer
 * is the masjid's own live announcements, which are already public on its
 * website and in its app.
 */
class SignageBoardTest extends TestCase
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
            'name' => 'Board Masjid '.uniqid(),
            'email' => 'board-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function board(): array
    {
        return $this->getJson("/api/mobile/masjids/{$this->masjid->id}/signage")
            ->assertStatus(200)
            ->json('data');
    }

    /**
     * `details`, `text` and `start_date` are all NOT NULL on this table, so an
     * announcement always carries a body and a start date. The controller's
     * null-window branches are belt-and-braces against a future nullable column
     * and are deliberately not exercised here, because the schema forbids them.
     */
    private function announcement(string $title, ?string $start = null, ?string $end = null): Announcement
    {
        return Announcement::create([
            'masjid_id' => $this->masjid->id,
            'title' => $title,
            'summary' => $title.' — short line for the board',
            'details' => $title.' — the longer body the app renders',
            'text' => $title.' — plain text body',
            'start_date' => $start ?? now()->subMonth()->toDateString(),
            'end_date' => $end,
        ]);
    }

    #[Test]
    public function a_masjid_with_live_announcements_and_no_broadcasts_does_not_show_an_empty_board(): void
    {
        // Burlington's exact shape on the day: announcements, no broadcasts.
        $this->announcement('Salawat Saturdays', now()->subWeek()->toDateString(), now()->addYear()->toDateString());
        $this->announcement('Summer Youth Soccer', now()->subMonths(2)->toDateString(), now()->addDays(10)->toDateString());

        $board = $this->board();

        $this->assertCount(2, $board, 'the board was empty while the masjid had live announcements');
        $this->assertEqualsCanonicalizing(
            ['Salawat Saturdays', 'Summer Youth Soccer'],
            array_column($board, 'title'),
        );
        // The short line, not the long body — the board has one line of room.
        $this->assertStringContainsString('short line for the board', $board[0]['body']);
    }

    #[Test]
    public function an_expired_announcement_stays_off_the_board(): void
    {
        $this->announcement('Ramadan Iftar 2025', '2025-03-01', '2025-03-30');
        $this->announcement('Open Now', now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $board = $this->board();

        $this->assertSame(['Open Now'], array_column($board, 'title'));
    }

    #[Test]
    public function an_announcement_with_no_image_still_reaches_the_board(): void
    {
        // The mobile announcements feed DROPS imageless rows, because a shipped
        // iOS build declares `image` non-optional. The board must not inherit
        // that: its `image_url` is nullable by contract, and on 2026-08-21 every
        // announcement in production was imageless.
        $this->announcement('No Picture Attached');

        $board = $this->board();

        $this->assertCount(1, $board);
        $this->assertNull($board[0]['image_url']);
    }

    #[Test]
    public function a_broadcast_sent_to_signage_still_wins_over_the_announcements(): void
    {
        $this->announcement('Fallback Notice');

        $broadcast = Broadcast::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Masjid Closed Tomorrow',
            'body' => 'The masjid will be closed for maintenance.',
            'status' => Broadcast::STATUS_SENT,
            'dispatched_at' => now(),
        ]);

        BroadcastDelivery::create([
            'masjid_id' => $this->masjid->id,
            'broadcast_id' => $broadcast->id,
            'channel' => BroadcastChannel::SIGNAGE->value,
            'status' => BroadcastDelivery::STATUS_SENT,
        ]);

        $board = $this->board();

        $this->assertSame(['Masjid Closed Tomorrow'], array_column($board, 'title'),
            'the announcement fallback overrode a real signage broadcast');
    }

    #[Test]
    public function a_broadcast_sent_only_to_push_never_reaches_the_board(): void
    {
        // The gate this fallback must not weaken: a notice deliberately sent to
        // push alone stays off the wall, and the board falls back to the
        // announcements rather than leaking it.
        $this->announcement('Fallback Notice');

        $broadcast = Broadcast::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Push Only — Not For The Wall',
            'body' => 'Sent to phones only.',
            'status' => Broadcast::STATUS_SENT,
            'dispatched_at' => now(),
        ]);

        BroadcastDelivery::create([
            'masjid_id' => $this->masjid->id,
            'broadcast_id' => $broadcast->id,
            'channel' => BroadcastChannel::PUSH->value,
            'status' => BroadcastDelivery::STATUS_SENT,
        ]);

        $board = $this->board();

        $this->assertSame(['Fallback Notice'], array_column($board, 'title'));
    }

    #[Test]
    public function the_board_never_shows_another_masjids_announcements(): void
    {
        $other = Masjid::create([
            'name' => 'Other Masjid '.uniqid(),
            'email' => 'other-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);

        Announcement::create([
            'masjid_id' => $other->id,
            'title' => 'Belongs To Someone Else',
            'summary' => 'x',
            'details' => 'x',
            'text' => 'x',
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $this->announcement('Ours');

        $this->assertSame(['Ours'], array_column($this->board(), 'title'));
    }
}
