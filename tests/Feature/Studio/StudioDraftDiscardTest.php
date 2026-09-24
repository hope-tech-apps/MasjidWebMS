<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * Discarding a draft, or only its logo, must reach the disk: a DB delete fires
 * no hook of its own, and bytes nobody points at are never cleaned up
 * (.claude/rules/private-uploads.md, "Deleting must reach the disk").
 */
class StudioDraftDiscardTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function discarding_a_draft_deletes_the_row_and_its_logo_bytes(): void
    {
        $id = $this->newDraft(['name' => 'Walk-away client'])['id'];
        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $this->assertCount(1, $this->storedLogos());

        $this->deleteJson(self::DRAFTS . "/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseMissing('studio_drafts', ['id' => $id]);
        $this->assertSame([], $this->storedLogos());
        $this->getJson(self::DRAFTS . "/{$id}/logo")->assertNotFound();
        $this->getJson(self::DRAFTS . "/{$id}")->assertNotFound();
    }

    #[Test]
    public function deleting_the_logo_clears_it_and_removes_the_file(): void
    {
        $id = $this->newDraft()['id'];
        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();

        $this->deleteJson(self::DRAFTS . "/{$id}/logo")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.logo', null);

        $draft = StudioDraft::findOrFail($id);
        $this->assertNull($draft->logo_path);
        $this->assertNull($draft->logo_sha256);
        $this->assertSame([], $this->storedLogos());
        $this->getJson(self::DRAFTS . "/{$id}/logo")->assertNotFound();

        // With nothing left to delete it still answers with the draft.
        $this->deleteJson(self::DRAFTS . "/{$id}/logo")->assertOk();
    }

    #[Test]
    public function a_draft_that_does_not_exist_is_a_404_on_every_endpoint(): void
    {
        $missing = 999999;

        // The endpoints exist and answer for a real draft; only the id is missing.
        $this->getJson(self::DRAFTS . '/' . $this->newDraft()['id'])->assertOk();

        $this->getJson(self::DRAFTS . "/{$missing}")->assertNotFound();
        $this->patchDraft($missing, 0, ['content' => ['about' => 'x']])->assertNotFound();
        $this->deleteJson(self::DRAFTS . "/{$missing}")->assertNotFound();
        $this->uploadLogo($missing, $this->realUpload('logo.png', $this->pngBytes()))->assertNotFound();
        $this->getJson(self::DRAFTS . "/{$missing}/logo")->assertNotFound();
        $this->deleteJson(self::DRAFTS . "/{$missing}/logo")->assertNotFound();

        $this->assertSame([], $this->storedLogos());
    }

    #[Test]
    public function a_provisioned_drafts_logo_cannot_be_replaced_or_removed(): void
    {
        $id = $this->newDraft()['id'];
        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $this->markProvisioned($id);

        $this->uploadLogo($id, $this->realUpload('other.png', $this->pngBytes(300, 300)))->assertStatus(409);
        $this->deleteJson(self::DRAFTS . "/{$id}/logo")->assertStatus(409);

        $this->assertCount(1, $this->storedLogos());
        $this->assertSame('logo.png', StudioDraft::findOrFail($id)->logo_original_name);
    }

    #[Test]
    public function every_file_in_a_drafts_directory_goes_with_its_logo_or_the_draft(): void
    {
        $disk = Storage::disk((string) config('studio.logo.disk'));
        $stray = fn (int $id) => $disk->put("studio-drafts/{$id}/" . str_repeat('b', 40) . '.png', $this->pngBytes());

        $kept = $this->newDraft()['id'];
        $this->uploadLogo($kept, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $keptPath = StudioDraft::findOrFail($kept)->logo_path;

        // Removing the logo takes the file the row names and any it does not.
        $id = $this->newDraft()['id'];
        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $stray($id);
        $this->deleteJson(self::DRAFTS . "/{$id}/logo")->assertOk();
        $this->assertSame([$keptPath], $this->storedLogos());

        // So does discarding the draft, which the purge also goes through.
        $id = $this->newDraft()['id'];
        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $stray($id);
        $this->deleteJson(self::DRAFTS . "/{$id}")->assertOk();
        $this->assertSame([$keptPath], $this->storedLogos(), 'only the other draft\'s logo is left');
    }

    #[Test]
    public function a_logo_removal_that_cannot_save_keeps_the_file_the_row_still_names(): void
    {
        $id = $this->newDraft()['id'];
        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $path = StudioDraft::findOrFail($id)->logo_path;

        // The row update fails, as a lost connection or a deadlock would.
        StudioDraft::updating(function () {
            throw new \RuntimeException('The database went away.');
        });

        $this->deleteJson(self::DRAFTS . "/{$id}/logo")->assertStatus(500);

        $this->assertSame($path, StudioDraft::findOrFail($id)->logo_path, 'the row still names the logo');
        $this->assertSame([$path], $this->storedLogos(), 'so its file is still there');
        $this->get(self::DRAFTS . "/{$id}/logo")->assertOk();
    }
}
