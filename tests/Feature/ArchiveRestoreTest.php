<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Masjid;
use App\Models\Service;
use App\Models\User;
use App\Services\Broadcast\Channels\AnnouncementChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArchiveRestoreTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $org;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        $this->org = $this->organisation();
        $this->admin = User::factory()->create([
            'name' => 'Test Admin', 'email' => 'admin@example.test',
            'type' => 'MasjidAdmin', 'phone' => '+15555550101',
        ]);
        $this->org->update(['user_id' => $this->admin->id]);
        Sanctum::actingAs($this->admin);
    }

    public static function areas(): array
    {
        return [['services', Service::class], ['announcements', Announcement::class]];
    }

    private function organisation(): Masjid
    {
        return Masjid::create([
            'name' => 'Archive Test Organisation '.uniqid(),
            'email' => 'archive-'.uniqid().'@example.test',
            'phone' => '+1555'.random_int(1000000, 9999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);
    }

    private function row(string $model, ?Masjid $org = null): Service|Announcement
    {
        $fields = [
            'masjid_id' => ($org ?? $this->org)->id,
            'title' => 'Test item', 'summary' => 'Summary', 'text' => 'Text',
        ];
        $fields += $model === Service::class ? ['description' => 'Description'] : [
            'details' => 'Details', 'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(), 'link' => 'https://example.test/notice',
        ];

        return $model::create($fields);
    }

    private function url(string $area, string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->org->id}/{$area}{$suffix}";
    }

    #[Test, DataProvider('areas')]
    public function archived_list_contains_only_our_archived_rows_and_archive_time(string $area, string $model): void
    {
        $archived = $this->row($model);
        $this->deleteJson($this->url($area, "/{$archived->id}/trash"))->assertOk();
        $this->row($model);
        $foreign = $this->row($model, $this->organisation());
        $foreign->delete();

        $rows = $this->getJson($this->url($area, '/archived'))->assertOk()->json('data.data');
        $this->assertSame([$archived->id], array_column($rows, 'id'));
        $this->assertNotEmpty($rows[0]['deleted_at']);
        $this->assertSame(1, $this->getJson($this->url($area))->assertOk()->json('data.total'));
    }

    #[Test, DataProvider('areas')]
    public function restore_keeps_the_row_dates_media_and_original_list_position(string $area, string $model): void
    {
        $row = $this->row($model);
        $row->forceFill(['created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-02 12:00:00'])->save();
        $media = $row->addMediaFromString('test media')->usingFileName('test.txt')->toMediaCollection($area);
        if ($area === 'services') {
            $icon = $row->addMediaFromString('test icon')->usingFileName('icon.txt')->toMediaCollection('servicesIcons');
        }
        $this->row($model);
        $before = $row->fresh()->getAttributes();
        $beforePublic = $this->getJson("/api/v1/{$area}", ['masjid-id' => (string) $this->org->id])->assertOk()->json('data.items');
        $this->deleteJson($this->url($area, "/{$row->id}/trash"))->assertOk();
        // Archive's existing timestamp update is kept; Restore changes only deleted_at.
        $archived = $row->fresh()->getAttributes();

        $this->post($this->url($area, "/{$row->id}/restore"), [], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $row->id)->assertJsonPath('data.deleted_at', null);

        $after = $row->fresh()->getAttributes();
        $this->assertSame(array_replace($archived, ['deleted_at' => null]), $after);
        $this->assertSame($before['created_at'], $after['created_at']);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'model_id' => $row->id]);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        if (isset($icon)) {
            $this->assertDatabaseHas('media', ['id' => $icon->id]);
            Storage::disk('public')->assertExists($icon->getPathRelativeToRoot());
        }
        $this->assertSame($beforePublic, $this->getJson("/api/v1/{$area}", ['masjid-id' => (string) $this->org->id])->assertOk()->json('data.items'));
        $this->assertSame(0, $this->getJson($this->url($area, '/archived'))->assertOk()->json('data.total'));
    }

    #[Test, DataProvider('areas')]
    public function restoring_twice_is_one_restore_and_no_send(string $area, string $model): void
    {
        $row = $this->row($model);
        $row->delete();
        Queue::fake();
        Event::fake(["eloquent.restored: {$model}"]);
        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertOk();
        $after = $row->fresh()->getAttributes();
        $this->travel(1)->hours();
        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertOk();
        $this->assertSame($after, $row->fresh()->getAttributes());
        Event::assertDispatchedTimes("eloquent.restored: {$model}", 1);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('broadcasts', 0);
        $this->assertDatabaseCount('broadcast_deliveries', 0);
    }

    #[Test, DataProvider('areas')]
    public function another_organisations_row_gets_the_same_answer_as_archive(string $area, string $model): void
    {
        $other = $this->organisation();
        $row = $this->row($model, $other);
        $this->deleteJson($this->url($area, "/{$row->id}/trash"))->assertNotFound();
        $row->delete();
        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertNotFound();
        $this->postJson("/api/admin/masjids/{$other->id}/{$area}/{$row->id}/restore")->assertForbidden();
        $this->getJson("/api/admin/masjids/{$other->id}/{$area}/archived")->assertForbidden();
        $this->assertTrue($row->fresh()->trashed());
    }

    #[Test, DataProvider('areas')]
    public function a_switched_off_capability_refuses_archive_restore_and_archived_list(string $area, string $model): void
    {
        $row = $this->row($model);
        $this->org->forceFill(['capability_overrides' => [$area => false]])->save();
        $this->deleteJson($this->url($area, "/{$row->id}/trash"))->assertForbidden();
        $row->delete();
        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertForbidden();
        $this->getJson($this->url($area, '/archived'))->assertForbidden();
        if ($area === 'services') {
            $this->getJson($this->url($area))->assertOk();
        }
        $this->assertTrue($row->fresh()->trashed());
    }

    #[Test, DataProvider('areas')]
    public function member_and_unauthenticated_callers_cannot_restore_or_read_archived_items(string $area, string $model): void
    {
        $row = $this->row($model);
        $row->delete();
        Sanctum::actingAs(User::factory()->create([
            'name' => 'Test Member', 'email' => 'member@example.test',
            'type' => 'User', 'phone' => '+15555550102',
        ]));
        $archiveStatus = $this->deleteJson($this->url($area, "/{$row->id}/trash"))->status();
        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertStatus($archiveStatus);
        $this->getJson($this->url($area, '/archived'))->assertStatus($archiveStatus);
        $this->app['auth']->forgetGuards();
        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertUnauthorized();
        $this->getJson($this->url($area, '/archived'))->assertUnauthorized();
    }

    #[Test, DataProvider('areas')]
    public function public_and_cached_mobile_payloads_drop_archive_and_regain_restore(string $area, string $model): void
    {
        $row = $this->row($model);
        $mobile = "/api/mobile/masjids/{$this->org->id}/{$area}";
        $headers = ['masjid-id' => (string) $this->org->id];
        $this->getJson($mobile)->assertOk()->assertJsonPath('data.0.id', $row->id);
        $this->deleteJson($this->url($area, "/{$row->id}/trash"))->assertOk();
        $this->getJson($mobile)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/{$area}", $headers)->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson("/api/v1/{$area}/{$row->id}", $headers)->assertNotFound();
        $this->getJson('/api/v1/home', $headers)->assertOk()->assertJsonCount(0, "data.{$area}");

        $this->postJson($this->url($area, "/{$row->id}/restore"))->assertOk();
        $this->getJson($mobile)->assertOk()->assertJsonPath('data.0.id', $row->id);
        $this->getJson("/api/v1/{$area}", $headers)->assertOk()->assertJsonPath('data.items.0.id', $row->id);
        $this->getJson("/api/v1/{$area}/{$row->id}", $headers)->assertOk()->assertJsonPath('data.id', $row->id);
        $this->getJson('/api/v1/home', $headers)->assertOk()->assertJsonPath("data.{$area}.0.id", $row->id);
        // Archive remains usable after Restore.
        $this->deleteJson($this->url($area, "/{$row->id}/trash"))->assertOk();
        $this->assertTrue($row->fresh()->trashed());
    }

    #[Test]
    public function expired_announcement_returns_without_becoming_current(): void
    {
        $row = $this->row(Announcement::class);
        $row->update(['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->subDay()->toDateString()]);
        $row->delete();
        $this->postJson($this->url('announcements', "/{$row->id}/restore"))->assertOk();
        $headers = ['masjid-id' => (string) $this->org->id];
        $this->getJson('/api/v1/announcements?filter_active=1', $headers)->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/announcements', $headers)->assertOk()->assertJsonPath('data.items.0.id', $row->id);
        $this->getJson($this->url('announcements'))->assertOk()->assertJsonPath('data.data.0.end_date', $row->end_date);
        $this->getJson("/api/mobile/masjids/{$this->org->id}/signage")->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function restore_clears_the_cached_signage_fallback(): void
    {
        $row = $this->row(Announcement::class);
        $row->delete();
        $signage = "/api/mobile/masjids/{$this->org->id}/signage";
        $this->getJson($signage)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->url('announcements', "/{$row->id}/restore"))->assertOk();
        $this->getJson($signage)->assertOk()->assertJsonPath('data.0.id', $row->id);
    }

    #[Test]
    public function archiving_and_restoring_a_broadcasts_announcement_leave_history_and_signage_intact(): void
    {
        $broadcast = Broadcast::create([
            'masjid_id' => $this->org->id, 'created_by_user_id' => $this->admin->id,
            'title' => 'Broadcast item', 'body' => 'Broadcast body', 'audience' => 'all_devices',
            'status' => 'sent', 'dispatched_at' => now(),
            'starts_on' => now()->subDay(), 'ends_on' => now()->addWeek(),
        ]);
        $result = (new AnnouncementChannel)->deliver($broadcast, $this->org);
        $announcementId = $result->referenceId;
        BroadcastDelivery::create([
            'masjid_id' => $this->org->id, 'broadcast_id' => $broadcast->id,
            'channel' => 'announcement', 'status' => 'sent', 'reference_id' => $announcementId,
        ]);
        BroadcastDelivery::create([
            'masjid_id' => $this->org->id, 'broadcast_id' => $broadcast->id,
            'channel' => 'signage', 'status' => 'sent',
        ]);
        $before = $broadcast->fresh()->getAttributes();
        $deliveries = $broadcast->deliveries()->get()->toArray();
        Queue::fake();
        $this->deleteJson($this->url('announcements', "/{$announcementId}/trash"))->assertOk();
        $this->getJson("/api/mobile/masjids/{$this->org->id}/signage")->assertOk()->assertJsonPath('data.0.body', 'Broadcast body');
        $this->postJson($this->url('announcements', "/{$announcementId}/restore"))->assertOk();
        $this->assertSame($before, $broadcast->fresh()->getAttributes());
        $this->assertSame($deliveries, $broadcast->deliveries()->get()->toArray());
        $this->assertDatabaseCount('announcements', 1);
        $this->getJson("/api/mobile/masjids/{$this->org->id}/signage")->assertOk()->assertJsonPath('data.0.body', 'Broadcast body');
        Queue::assertNothingPushed();
    }
}
