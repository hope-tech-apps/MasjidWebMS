<?php

namespace Tests\Feature\Studio;

use App\Mail\AccountAccessMail;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\Page;
use App\Models\StudioDraft;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\Studio\LogoDerivatives;
use App\Support\Studio\LogoFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * A draft becomes at most one organisation (docs/manara-studio-w1.md S8, D7).
 * Two clicks, two tabs, and a retry after a response that never arrived all
 * meet the draft's row lock; the loser gets a 409 naming the organisation that
 * exists, never a second one.
 */
class StudioProvisionConflictTest extends TestCase
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
    public function a_second_provision_of_the_same_draft_is_a_409_naming_the_first_masjid(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $masjidsBefore = Masjid::count();

        $first = $this->provision($draft->id)->assertCreated()->json('data');

        $this->provision($draft->id)
            ->assertStatus(409)
            ->assertExactJson(['status' => 'conflict', 'data' => ['draft_id' => $draft->id, 'provisioned_masjid_id' => $first['masjid_id']]]);

        $this->assertSame($masjidsBefore + 1, Masjid::count(), 'exactly one organisation');
        $this->assertSame(1, MasjidDomain::where('masjid_id', $first['masjid_id'])->count(), 'one set of domain rows');
        $this->assertSame(1, MasjidDomain::count());
        $this->assertSame(count($first['starter_site']['created']), Page::where('masjid_id', $first['masjid_id'])->count(), 'one set of pages');
        $this->assertSame(Page::count(), Page::where('masjid_id', $first['masjid_id'])->count());
        Mail::assertSent(AccountAccessMail::class, 1);
    }

    #[Test]
    public function a_draft_provisioned_after_the_checks_is_refused_under_the_lock(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $other = $this->org();

        // Runs after validation and the brand gate, before the transaction
        // takes the lock: the moment a twin request can win the race.
        $this->app->instance(LogoDerivatives::class, new class($other->id) extends LogoDerivatives {
            public function __construct(private int $winner) {}

            public function generate(StudioDraft $draft, string $backgroundColor): LogoFiles
            {
                StudioDraft::whereKey($draft->id)->update([
                    'status' => StudioDraft::STATUS_PROVISIONED,
                    'provisioned_masjid_id' => $this->winner,
                    'provisioned_at' => now(),
                ]);

                return parent::generate($draft, $backgroundColor);
            }
        });

        $masjidsBefore = Masjid::count();

        $this->provision($draft->id)
            ->assertStatus(409)
            ->assertExactJson(['status' => 'conflict', 'data' => ['draft_id' => $draft->id, 'provisioned_masjid_id' => $other->id]]);

        $this->assertSame($masjidsBefore, Masjid::count(), 'no new organisation');
        $this->assertSame(0, MasjidDomain::count());
        $this->assertSame([], $this->derivativeDirectories($draft->id), 'the images made for it are deleted');
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function an_after_commit_failure_returns_201_leaves_the_draft_provisioned_and_a_retry_is_a_409(): void
    {
        $draft = $this->draftWith($this->studioAnswers());

        $this->app->instance(AccountAccessService::class, new class extends AccountAccessService {
            public function invite(User $user, ?string $orgName = null): bool
            {
                throw new \RuntimeException('SMTP is down');
            }
        });

        $data = $this->provision($draft->id)->assertCreated()->json('data');

        $this->assertSame(0, $data['after_commit']['invites_sent']);
        $this->assertSame(1, $data['after_commit']['invites_failed']);
        $this->assertCount(1, $data['after_commit']['warnings']);
        $this->assertStringContainsString('was not sent', $data['after_commit']['warnings'][0]);

        $fresh = StudioDraft::findOrFail($draft->id);
        $this->assertSame(StudioDraft::STATUS_PROVISIONED, $fresh->status);
        $this->assertSame($data['masjid_id'], $fresh->provisioned_masjid_id);

        $this->provision($draft->id)->assertStatus(409)->assertJsonPath('data.provisioned_masjid_id', $data['masjid_id']);
        $this->assertSame(1, Masjid::where('id', $data['masjid_id'])->count());
    }
}
