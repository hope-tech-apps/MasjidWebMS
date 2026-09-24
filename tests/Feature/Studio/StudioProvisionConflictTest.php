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
use App\Support\Studio\StudioDraftChanged;
use App\Support\Studio\StudioProvisioning;
use App\Support\Studio\StudioProvisionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * A draft becomes at most one organisation (docs/manara-studio-w1.md S8, D7).
 * Two clicks, two tabs, and a retry after a response that never arrived all
 * meet the draft's row lock; the loser gets a 409 naming the organisation that
 * exists, never a second one. A draft that changed after it was read (an
 * autosave, a new logo) or was discarded meanwhile is refused with nothing
 * written, so the draft stays the record of exactly what was made.
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

    /**
     * The twin commits after this request's pre-check but before its
     * validation: this draft's email and name now belong to the twin's
     * organisation, so the rules refuse it. The answer is still "this draft
     * is organisation #N", never "fix your answers".
     */
    #[Test]
    public function a_twin_that_commits_while_this_one_validates_is_a_409_not_a_422(): void
    {
        $draft = $this->draftWith($this->studioAnswers());

        $this->app->bind(StudioProvisioning::class, fn () => new class extends StudioProvisioning {
            public function provision(StudioDraft $draft, array $secrets = [], ?int $lockVersion = null): StudioProvisionResult
            {
                // The twin, on its own fresh read; `$draft` is this request's, read before it.
                parent::provision(StudioDraft::findOrFail($draft->id), $secrets);

                return parent::provision($draft, $secrets, $lockVersion);
            }
        });

        $this->provision($draft->id)->assertStatus(409)
            ->assertExactJson(['status' => 'conflict', 'data' => ['draft_id' => $draft->id, 'provisioned_masjid_id' => StudioDraft::findOrFail($draft->id)->provisioned_masjid_id]]);

        $this->assertSame(1, Masjid::count(), 'the twin\'s, and no other');
        $this->assertSame(Masjid::sole()->id, StudioDraft::findOrFail($draft->id)->provisioned_masjid_id);
    }

    #[Test]
    public function a_draft_discarded_before_the_lock_is_a_404_and_nothing_is_created(): void
    {
        $draft = $this->draftWith($this->studioAnswers());

        $this->app->instance(LogoDerivatives::class, new class extends LogoDerivatives {
            public function generate(StudioDraft $draft, string $backgroundColor): LogoFiles
            {
                $files = parent::generate($draft, $backgroundColor);
                StudioDraft::findOrFail($draft->id)->delete();

                return $files;
            }
        });

        $this->provision($draft->id)->assertNotFound();

        $this->assertSame(0, Masjid::count());
        $this->assertSame([], $this->derivativeDirectories($draft->id), 'the images made for it are deleted');
        Mail::assertNothingOutgoing();
    }

    /**
     * An autosave (another tab's, or this tab's own still in flight) commits
     * while the provision validates and makes images. Provisioning the answers
     * read before it would invite the old address and leave a provisioned
     * draft holding answers the organisation was not made from.
     */
    #[Test]
    public function an_autosave_that_commits_before_the_lock_is_refused_and_nothing_is_created(): void
    {
        $draft = $this->draftWith($this->studioAnswers());

        $this->app->instance(LogoDerivatives::class, new class extends LogoDerivatives {
            public function generate(StudioDraft $draft, string $backgroundColor): LogoFiles
            {
                $saved = StudioDraft::findOrFail($draft->id);
                $answers = $saved->answers;
                $answers['identity']['admin']['email'] = 'someone-else@example.test';
                // As StudioDraftsController::update writes it: the new answers and the next version.
                StudioDraft::whereKey($draft->id)->where('lock_version', $saved->lock_version)
                    ->update(['answers' => json_encode($answers), 'lock_version' => $saved->lock_version + 1]);

                return parent::generate($draft, $backgroundColor);
            }
        });

        $this->provision($draft->id)->assertStatus(409)->assertExactJson([
            'status' => 'conflict',
            'message' => StudioDraftChanged::MESSAGE,
            'data' => ['draft_id' => $draft->id, 'provisioned_masjid_id' => null],
        ]);

        $fresh = StudioDraft::findOrFail($draft->id);
        $this->assertSame(StudioDraft::STATUS_DRAFT, $fresh->status, 'still a draft, to review and provision again');
        $this->assertSame('someone-else@example.test', $fresh->section('identity')['admin']['email']);
        $this->assertSame(0, Masjid::count());
        $this->assertSame([], $this->derivativeDirectories($draft->id));
        $this->assertTrue($fresh->logoExists(), 'the draft keeps its logo for the retry');
        Mail::assertNothingOutgoing();
    }

    /**
     * A logo upload does not move lock_version, so it is compared on its own.
     * Missed, the organisation got the old logo and the after-commit clean-up
     * deleted the new one's bytes from under the draft.
     */
    #[Test]
    public function a_logo_replaced_before_the_lock_is_refused_and_the_new_logo_is_kept(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $newPath = "studio-drafts/{$draft->id}/" . str_repeat('c', 40) . '.png';

        $this->app->instance(LogoDerivatives::class, new class($newPath, $this->pngBytes(300, 300)) extends LogoDerivatives {
            public function __construct(private string $newPath, private string $bytes) {}

            public function generate(StudioDraft $draft, string $backgroundColor): LogoFiles
            {
                $files = parent::generate($draft, $backgroundColor);
                Storage::disk((string) config('studio.logo.disk'))->put($this->newPath, $this->bytes);
                StudioDraft::whereKey($draft->id)->update(['logo_path' => $this->newPath, 'logo_sha256' => hash('sha256', $this->bytes), 'logo_width' => 300, 'logo_height' => 300]);

                return $files;
            }
        });

        $this->provision($draft->id)->assertStatus(409)->assertJsonPath('data.provisioned_masjid_id', null);

        $fresh = StudioDraft::findOrFail($draft->id);
        $this->assertSame(StudioDraft::STATUS_DRAFT, $fresh->status);
        $this->assertSame($newPath, $fresh->logo_path);
        $this->assertTrue($fresh->logoExists(), 'the new logo\'s bytes are still there');
        $this->assertSame(0, Masjid::count());
    }

    /**
     * Step 3 sends the lock_version it reviewed. A save from another tab after
     * the review, even one long finished, is refused: the operator reviewed
     * other answers than the server holds.
     */
    #[Test]
    public function a_draft_saved_since_the_version_step_3_reviewed_is_refused(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        DB::table('studio_drafts')->where('id', $draft->id)->increment('lock_version');

        $this->provision($draft->id, ['lock_version' => $draft->lock_version])->assertStatus(409)
            ->assertJsonPath('message', StudioDraftChanged::MESSAGE)
            ->assertJsonPath('data.provisioned_masjid_id', null);
        $this->assertSame(0, Masjid::count());

        $this->provision($draft->id, ['lock_version' => $draft->lock_version + 1])->assertCreated();
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
