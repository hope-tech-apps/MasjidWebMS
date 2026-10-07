<?php

namespace Tests\Feature;

use App\Jobs\ProcessFlyerCutout;
use App\Services\Flyer\ImageCutout;
use App\Models\Flyer;
use App\Models\FlyerTemplate;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlyerDraftsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $org;
    private Masjid $other;
    private User $admin;
    private FlyerTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->forgetTenant();
        $this->org = $this->org();
        $this->other = $this->org();
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550000001']);
        MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        $this->org->forceFill(['capability_overrides' => ['flyer_studio' => true]])->save();
        $this->template = FlyerTemplate::factory()->system()->create([
            'key' => 'test-design', 'schema' => ['slots' => [['name' => 'title', 'type' => 'text', 'maxLength' => 100]]],
        ]);
        Sanctum::actingAs($this->admin);
        Storage::fake('local');
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Sample organisation ' . uniqid(), 'email' => uniqid() . '@example.invalid',
            'phone' => '+1' . random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Test St', 'latitude' => 0, 'longitude' => 0,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->org->id}/flyers{$suffix}";
    }

    private function draft(array $attributes = []): Flyer
    {
        return Flyer::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $this->org->id, 'flyer_template_id' => $this->template->id,
            'title' => 'Saved draft', 'content' => ['title' => 'Saved wording'],
            'palette' => ['ink' => '#123456', 'grad' => ['#abcdef']], 'created_by' => $this->admin->id,
        ], $attributes));
    }

    #[Test]
    public function list_is_paginated_drafts_only_for_this_org_with_creator(): void
    {
        $draft = $this->draft();
        $this->draft(['masjid_id' => $this->other->id]);
        $this->draft(['status' => 'rendered']);
        $this->getJson($this->url('?status=draft&per_page=1'))->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $draft->id)
            ->assertJsonPath('data.data.0.creator.name', $this->admin->name)
            ->assertJsonPath('data.data.0.template_name', $this->template->name);
    }

    #[Test]
    public function open_and_form_encoded_resave_keep_the_same_row_snapshot_and_images(): void
    {
        $draft = $this->draft(['source_image_path' => 'flyers/source/saved.png']);
        Storage::disk('local')->put($draft->source_image_path, 'stored image');
        $body = $this->getJson($this->url("/{$draft->id}"))->assertOk()->json('data');
        $this->assertSame($draft->palette, $body['palette']);
        $this->assertSame($draft->content, $body['content']);
        $this->assertNotNull($body['images']['source']);
        $this->put($this->url("/{$draft->id}"), [
            'title' => 'Edited draft', 'content' => json_encode(['title' => 'Edited wording']), 'palette' => json_encode($body['palette']),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $draft->id);
        $this->assertSame(1, Flyer::withoutMasjidScope()->count());
        $this->assertSame($body['palette'], $draft->fresh()->palette);
        $this->assertSame('flyers/source/saved.png', $draft->fresh()->source_image_path);
    }

    #[Test]
    public function removed_slot_survives_resave_but_cannot_be_invented_or_changed(): void
    {
        $draft = $this->draft(['content' => ['title' => 'Old', 'removed' => 'Historical wording']]);
        $this->putJson($this->url("/{$draft->id}"), ['content' => ['title' => 'New', 'removed' => 'Historical wording']])
            ->assertOk()->assertJsonPath('data.content.removed', 'Historical wording');
        $this->putJson($this->url("/{$draft->id}"), ['content' => ['removed' => 'Changed']])->assertStatus(422);
        $this->putJson($this->url("/{$draft->id}"), ['content' => ['invented' => 'New']])->assertStatus(422);
    }

    #[Test]
    public function an_inactive_template_can_be_opened_and_resaved(): void
    {
        $draft = $this->draft();
        $this->template->update(['is_active' => false]);
        $this->getJson($this->url("/{$draft->id}"))->assertOk()->assertJsonPath('data.template.is_active', false);
        $this->putJson($this->url("/{$draft->id}"), ['content' => $draft->content])->assertOk();
    }

    #[Test]
    public function unavailable_template_relation_still_returns_saved_data(): void
    {
        $private = FlyerTemplate::factory()->create(['masjid_id' => $this->other->id]);
        $draft = $this->draft(['flyer_template_id' => $private->id]);
        $this->getJson($this->url("/{$draft->id}"))->assertOk()
            ->assertJsonPath('data.template', null)->assertJsonPath('data.content.title', 'Saved wording');
    }

    #[Test]
    public function delete_removes_unshared_images_and_keeps_images_used_by_finished_flyers(): void
    {
        $draft = $this->draft(['source_image_path' => 'flyers/source/shared.png', 'cutout_path' => 'flyers/cutouts/only.png']);
        $this->draft(['masjid_id' => $this->other->id, 'status' => 'rendered', 'rendered_path' => 'flyers/source/shared.png']);
        Storage::disk('local')->put('flyers/source/shared.png', 'shared');
        Storage::disk('local')->put('flyers/cutouts/only.png', 'only');
        $this->deleteJson($this->url("/{$draft->id}"))->assertOk();
        $this->assertNull(Flyer::withoutMasjidScope()->find($draft->id));
        Storage::disk('local')->assertExists('flyers/source/shared.png');
        Storage::disk('local')->assertMissing('flyers/cutouts/only.png');
    }

    #[Test]
    public function delete_refuses_a_flyer_finished_since_it_was_listed(): void
    {
        $draft = $this->draft(['rendered_path' => 'flyers/rendered/finished.png']);
        Storage::disk('local')->put($draft->rendered_path, 'finished');
        $this->getJson($this->url('?status=draft'))->assertOk()->assertJsonPath('data.total', 1);
        $draft->update(['status' => 'rendered']);
        $this->deleteJson($this->url("/{$draft->id}"))->assertStatus(409)
            ->assertJsonPath('message', 'This flyer is no longer a draft and cannot be deleted.');
        $this->assertNotNull($draft->fresh());
        Storage::disk('local')->assertExists($draft->rendered_path);
    }

    /** Inject a write after an unlocked read, as another request could do. */
    private function changeAfterUnlockedRead(Flyer $draft, array $changes): void
    {
        $level = DB::transactionLevel();
        $changed = false;
        Flyer::retrieved(function (Flyer $read) use ($draft, $changes, $level, &$changed): void {
            if (! $changed && $read->id === $draft->id && DB::transactionLevel() === $level) {
                $changed = true;
                Flyer::withoutMasjidScope()->whereKey($draft->id)->update($changes);
            }
        });
    }

    #[Test]
    public function delete_checks_status_on_the_locked_fresh_row(): void
    {
        $draft = $this->draft();
        $this->changeAfterUnlockedRead($draft, ['status' => 'rendered']);
        $this->deleteJson($this->url("/{$draft->id}"))->assertStatus(409);
        $this->assertSame('rendered', $draft->fresh()->status);
    }

    #[Test]
    public function delete_captures_the_latest_photo_under_its_transaction(): void
    {
        $draft = $this->draft(['source_image_path' => 'flyers/sources/old.png']);
        Storage::disk('local')->put('flyers/sources/new.png', 'new');
        $this->changeAfterUnlockedRead($draft, ['source_image_path' => 'flyers/sources/new.png']);
        $level = DB::transactionLevel();
        Flyer::deleting(function (Flyer $row) use ($level): void {
            $this->assertGreaterThan($level, DB::transactionLevel());
        });
        $this->deleteJson($this->url("/{$draft->id}"))->assertOk();
        Storage::disk('local')->assertMissing('flyers/sources/new.png');
    }

    #[Test]
    public function photo_replace_and_clear_capture_the_latest_paths_under_a_transaction(): void
    {
        foreach (['replace', 'clear'] as $action) {
            $draft = $this->draft(['source_image_path' => 'flyers/sources/old.png']);
            $new = "flyers/sources/new-{$draft->id}.png";
            Storage::disk('local')->put($new, 'new');
            $this->changeAfterUnlockedRead($draft, ['source_image_path' => $new]);
            $level = DB::transactionLevel();
            Flyer::updating(function (Flyer $row) use ($draft, $level): void {
                if ($row->id === $draft->id) $this->assertGreaterThan($level, DB::transactionLevel());
            });
            if ($action === 'replace') {
                $this->post($this->url("/{$draft->id}/photo"), [
                    'image' => UploadedFile::fake()->image('photo.png'), 'remove_background' => '0',
                ], ['Accept' => 'application/json'])->assertOk();
                Storage::disk('local')->assertExists($draft->fresh()->source_image_path);
            } else {
                $this->deleteJson($this->url("/{$draft->id}/photo"))->assertOk();
                $this->assertNull($draft->fresh()->source_image_path);
            }
            Storage::disk('local')->assertMissing($new);
        }
    }

    #[Test]
    public function a_cutout_finishing_after_delete_or_replacement_cannot_leave_its_output(): void
    {
        foreach (['delete', 'replace'] as $action) {
            $draft = $this->draft(['source_image_path' => "flyers/sources/{$action}.png", 'cutout_status' => 'queued']);
            Storage::disk('local')->put($draft->source_image_path, 'source');
            $destination = null;
            $cutout = \Mockery::mock(ImageCutout::class);
            $cutout->shouldReceive('run')->once()->andReturnUsing(function ($source, $output) use ($draft, $action, &$destination): array {
                $destination = substr($output, strlen(Storage::disk('local')->path('')));
                if ($action === 'delete') {
                    $this->deleteJson($this->url("/{$draft->id}"))->assertOk();
                } else {
                    $this->post($this->url("/{$draft->id}/photo"), [
                        'image' => UploadedFile::fake()->image('replacement.png'), 'remove_background' => '0',
                    ], ['Accept' => 'application/json'])->assertOk();
                }
                Storage::disk('local')->put($destination, 'cutout');
                return ['ok' => true, 'reason' => null, 'meta' => []];
            });
            (new ProcessFlyerCutout($draft->id, 'local'))->handle($cutout);
            Storage::disk('local')->assertMissing($destination);
            $stored = $draft->fresh();
            if ($action === 'delete') $this->assertNull($stored);
            else $this->assertNull($stored->cutout_path);
        }
    }

    #[Test]
    public function failed_file_deletion_warns_without_exposing_paths(): void
    {
        $draft = $this->draft(['source_image_path' => 'flyers/sources/private-person-name.png']);
        $disk = \Mockery::mock();
        $disk->shouldReceive('delete')->with($draft->source_image_path)->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        Log::shouldReceive('warning')->once()->with('Flyer file cleanup failed', [
            'flyer_id' => $draft->id, 'path_count' => 1,
        ]);
        $this->deleteJson($this->url("/{$draft->id}"))->assertOk();
        $this->assertNull($draft->fresh());
    }

    #[Test]
    public function another_orgs_draft_is_404_on_read_write_and_delete(): void
    {
        $draft = $this->draft(['masjid_id' => $this->other->id]);
        $this->getJson($this->url("/{$draft->id}"))->assertNotFound();
        $this->putJson($this->url("/{$draft->id}"), ['title' => 'Changed'])->assertNotFound();
        $this->deleteJson($this->url("/{$draft->id}"))->assertNotFound();
    }

    #[Test]
    public function capability_is_required_for_list_open_save_and_delete(): void
    {
        $draft = $this->draft();
        $this->org->forceFill(['capability_overrides' => ['flyer_studio' => false]])->save();
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url("/{$draft->id}"))->assertForbidden();
        $this->putJson($this->url("/{$draft->id}"), ['title' => 'Changed'])->assertForbidden();
        $this->deleteJson($this->url("/{$draft->id}"))->assertForbidden();
    }
}
