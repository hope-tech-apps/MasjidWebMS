<?php

namespace Tests\Feature\Studio;

use App\Jobs\AttachMasjidDomain;
use App\Models\MasjidDomain;
use App\Models\StudioDraft;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * The web host rows a Studio provision writes, and the attach that follows the
 * commit (docs/manara-studio-w1.md S8, S7, R24). The row commits or vanishes
 * with the organisation; nothing is sent to Cloudflare for a row that rolled
 * back; and without the token the response says so, having sent nothing.
 */
class ProvisionAttachesDomainTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function exactly_one_managed_row_is_written_for_the_slug(): void
    {
        $answers = $this->studioAnswers();
        $data = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data');

        $row = MasjidDomain::sole();
        $this->assertSame($data['masjid_id'], (int) $row->masjid_id);
        $this->assertSame($answers['identity']['slug'] . '.manara.hopetechapps.com', $row->host);
        $this->assertSame(MasjidDomain::KIND_MANAGED_SUBDOMAIN, $row->kind);
        $this->assertSame('hopetechapps.com', $row->zone_apex);
        $this->assertSame(MasjidDomain::STATUS_PENDING, $row->status);
        $this->assertSame(MasjidDomain::SOURCE_STUDIO, $row->source);
        $this->assertSame([$row->toAdminArray()], $data['domains']);

        // The client's own domain is a second row beside it, and is what the site answers on.
        $custom = $this->studioAnswers(sections: ['domain' => ['custom' => ['host' => 'WWW.Client-Masjid.example', 'zone_apex' => 'client-masjid.example']]]);
        $second = $this->provision($this->draftWith($custom)->id)->assertCreated()->json('data');
        $this->assertSame(['managed_subdomain', 'custom'], MasjidDomain::where('masjid_id', $second['masjid_id'])->orderBy('id')->pluck('kind')->all());
        $this->assertSame('www.client-masjid.example', $second['web']['host']);
    }

    /**
     * The draft always sends its slug, and the request accepts one without
     * web. An app-only organisation must still get no public host: no row,
     * and nothing asked of Cloudflare.
     */
    #[Test]
    public function a_slug_without_the_web_platform_writes_no_host_and_starts_no_attach(): void
    {
        $answers = $this->studioAnswers(sections: ['platforms' => ['platforms' => ['ios', 'android']]]);
        $this->assertNotEmpty($answers['identity']['slug'], 'the premise: Foundation chose a subdomain');

        $data = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data');

        $this->assertSame(0, MasjidDomain::count());
        $this->assertNull($data['web']);
        $this->assertSame([], $data['domains']);
        Queue::assertNotPushed(AttachMasjidDomain::class);
    }

    #[Test]
    public function rollback_leaves_no_row_and_no_job(): void
    {
        StudioDraft::updating(function (StudioDraft $row) {
            if ($row->status === StudioDraft::STATUS_PROVISIONED) {
                throw new \RuntimeException('fail after the rows were written');
            }
        });

        $this->provision($this->draftWith($this->studioAnswers())->id)->assertStatus(500);

        $this->assertSame(0, MasjidDomain::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_job_is_dispatched_after_commit(): void
    {
        // The real queue connection (sync in tests), not setUp's fake, with the
        // attacher replaced: what matters is when it runs, and that the row is
        // committed by then.
        $this->app->forgetInstance('queue');
        Queue::swap($this->app->make('queue'));

        $baseline = DB::transactionLevel();
        $seen = [];
        $this->mock(DomainAttacher::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('advance')->andReturnUsing(function (MasjidDomain $domain) use (&$seen) {
                $seen[] = [DB::transactionLevel(), MasjidDomain::whereKey($domain->id)->exists(), $domain->masjid()->exists()];

                return $domain;
            });
        });

        $data = $this->provision($this->draftWith($this->studioAnswers())->id)->assertCreated()->json('data');

        $this->assertSame([[$baseline, true, true]], $seen, 'one attach, run once the organisation and its row were committed');
        $this->assertSame([], $data['after_commit']['warnings']);
    }

    #[Test]
    public function with_the_token_blank_the_web_status_is_pending_waiting_on_the_token_with_no_http_call(): void
    {
        Http::fake();

        $data = $this->provision($this->draftWith($this->studioAnswers())->id)->assertCreated()->json('data');

        $this->assertSame('pending', $data['web']['status']);
        $this->assertSame('token', $data['web']['waiting_on']);
        $this->assertNull($data['web']['live_url'], 'nothing is live until a probe has seen it');
        $this->assertNotEmpty($data['web']['manual_steps']);
        Queue::assertPushed(AttachMasjidDomain::class, 1);
        Http::assertNothingSent();
    }
}
