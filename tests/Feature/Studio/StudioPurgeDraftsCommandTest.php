<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * `studio:purge-drafts`: abandoned drafts hold a prospective client's contact
 * details and an unannounced logo on a disk no backup covers, so they are
 * deleted, bytes and all, once nobody has touched them for the window.
 */
class StudioPurgeDraftsCommandTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    private function ageDraft(int $id, int $days): void
    {
        DB::table('studio_drafts')->where('id', $id)->update(['updated_at' => now()->subDays($days)]);
    }

    #[Test]
    public function it_deletes_drafts_untouched_past_the_window_with_their_logos(): void
    {
        $stale = $this->newDraft(['name' => 'Stale'])['id'];
        $this->uploadLogo($stale, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $this->ageDraft($stale, 91);

        $this->artisan('studio:purge-drafts')
            ->expectsOutputToContain('Purged 1 Studio draft(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('studio_drafts', ['id' => $stale]);
        $this->assertSame([], $this->storedLogos(), 'the logo went with the row');
    }

    #[Test]
    public function it_keeps_recent_drafts_and_every_provisioned_one(): void
    {
        $recent = $this->newDraft(['name' => 'Recent'])['id'];
        $this->ageDraft($recent, 89);

        $live = $this->newDraft(['name' => 'Live'])['id'];
        $this->uploadLogo($live, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $this->markProvisioned($live);
        $this->ageDraft($live, 400);

        $this->artisan('studio:purge-drafts')->expectsOutputToContain('Purged 0 Studio draft(s)')->assertSuccessful();

        $this->assertDatabaseHas('studio_drafts', ['id' => $recent]);
        $this->assertDatabaseHas('studio_drafts', ['id' => $live]);
        $this->assertCount(1, $this->storedLogos());

        // The window is config, and --days narrows it.
        $this->artisan('studio:purge-drafts', ['--days' => 30])->assertSuccessful();
        $this->assertDatabaseMissing('studio_drafts', ['id' => $recent]);
        $this->assertDatabaseHas('studio_drafts', ['id' => $live]);
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $stale = $this->newDraft()['id'];
        $this->uploadLogo($stale, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $this->ageDraft($stale, 120);

        $this->artisan('studio:purge-drafts', ['--dry-run' => true])
            ->expectsOutputToContain('Would purge 1 Studio draft(s)')
            ->assertSuccessful();

        $this->assertDatabaseHas('studio_drafts', ['id' => $stale]);
        $this->assertCount(1, $this->storedLogos());
    }

    #[Test]
    public function it_runs_daily_clear_of_the_reaper_and_canary_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'studio:purge-drafts'));

        $this->assertCount(1, $events, 'scheduled exactly once');
        $event = $events->first();

        [$minute, $hour, $dom, $month, $dow] = explode(' ', $event->expression);
        $this->assertSame(['*', '*', '*'], [$dom, $month, $dow], 'daily');
        $this->assertMatchesRegularExpression('/^\d+$/', $hour);
        $this->assertNotContains((int) $minute, [0, 15, 30, 45, 47], 'the minute the reaper or the canary owns');
        $this->assertTrue($event->withoutOverlapping);

        $this->assertSame(0, StudioDraft::count());
    }

    #[Test]
    public function a_negative_window_is_held_to_one_day_and_spares_a_draft_being_edited(): void
    {
        $this->freezeTime();
        $editing = $this->newDraft(['name' => 'Being edited'])['id'];

        // --days=0 falls through to the config default; a sign error does not.
        $this->artisan('studio:purge-drafts', ['--days' => -5])
            ->expectsOutputToContain('Purged 0 Studio draft(s) untouched since ' . now()->subDay()->toDateTimeString())
            ->assertSuccessful();

        $this->assertDatabaseHas('studio_drafts', ['id' => $editing]);
    }
}
