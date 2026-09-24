<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * A provision that fails inside the transaction leaves the world as it found
 * it (docs/manara-studio-w1.md S8): no organisation, no media rows, no files on
 * the public disk (medialibrary saves a Media row BEFORE copying its file, and
 * a rollback does not remove files), the draft still a draft with its logo,
 * and no invite in anyone's inbox. So the same draft can simply be retried.
 */
class StudioProvisionRollbackTest extends TestCase
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
    public function a_throwing_step_leaves_no_masjid_no_media_no_files_no_mail_and_the_draft_intact_and_a_retry_succeeds(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $masjidsBefore = Masjid::count();

        // The last write of the transaction, after every Media row and file.
        $fail = true;
        StudioDraft::updating(function (StudioDraft $row) use (&$fail) {
            if ($fail && $row->status === StudioDraft::STATUS_PROVISIONED) {
                throw new \RuntimeException('the database went away');
            }
        });

        $this->provision($draft->id)->assertStatus(500)->assertJsonPath('status', 'error');

        $this->assertSame($masjidsBefore, Masjid::count(), 'no organisation');
        $this->assertSame(0, Media::count(), 'no media rows');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'no files left on the public disk');
        $this->assertSame([], $this->derivativeDirectories($draft->id), 'no temporary images');
        \Illuminate\Support\Facades\Mail::assertNothingOutgoing();

        $fresh = StudioDraft::findOrFail($draft->id);
        $this->assertSame(StudioDraft::STATUS_DRAFT, $fresh->status);
        $this->assertNull($fresh->provisioned_masjid_id);
        $this->assertTrue($fresh->logoExists(), 'the draft keeps its logo, so it can be retried');

        $fail = false;
        $data = $this->provision($draft->id)->assertCreated()->json('data');

        $this->assertSame($masjidsBefore + 1, Masjid::count());
        $this->assertNotNull(Masjid::findOrFail($data['masjid_id'])->logo);
        $this->assertSame(4, Media::count());
    }
}
