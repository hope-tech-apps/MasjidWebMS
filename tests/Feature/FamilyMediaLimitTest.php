<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\GroupMembership;
use App\Support\GroupPostAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * PHOTOS HAVE THEIR OWN ALLOWANCE IN THE FAMILY REALM (owner's bug report 2026-10-16).
 *
 * A parent opened the portal on a class whose stories carried about twenty photos. The
 * page asked for every photo at once, those downloads used up the realm's 60 requests a
 * minute (throttle:family, per contact), and the parent's next requests (her classes,
 * her account, a message) came back 429: "We could not load your classes just now."
 * Photo and file downloads now count against throttle:family-media instead, so a
 * photo-heavy page can never lock a parent out of reading, and the realm limit still
 * holds for everything else.
 */
class FamilyMediaLimitTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSqliteInMemory();
        Storage::fake((string) config('groups.media.disk'));
        Bus::fake([SendGroupNotificationJob::class]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->buildStoryWorld();
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);
    }

    #[Test]
    public function seventy_photos_in_one_minute_leave_the_parent_free_to_read_her_classes(): void
    {
        $downloads = [];
        for ($p = 0; $p < 7; $p++) {
            $post = $this->makePost();
            for ($i = 0; $i < 10; $i++) {
                $photo = GroupPostAttachments::store($post, [UploadedFile::fake()->create("photo{$p}-{$i}.jpg", 5, 'image/jpeg')])[0];
                $downloads[] = $this->familyUrl("/posts/{$post->id}/attachments/{$photo->id}");
            }
        }

        $this->asParent($this->parentA);
        foreach ($downloads as $url) {
            $this->get($url)->assertOk();
        }

        // Before the fix the 61st request of the minute was refused, whatever it asked for.
        $this->getJson($this->familyUrl('/posts'))->assertOk();
        $this->getJson("/api/family/masjids/{$this->class->masjid_id}/groups")->assertOk();
    }

    #[Test]
    public function ordinary_reads_still_stop_at_sixty_a_minute(): void
    {
        $this->asParent($this->parentA);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson($this->familyUrl('/posts'))->assertOk();
        }

        $this->getJson($this->familyUrl('/posts'))->assertStatus(429);
    }

    #[Test]
    public function both_photo_doors_use_the_media_allowance_and_not_the_realm_one(): void
    {
        $base = "/api/family/masjids/{$this->class->masjid_id}/groups/{$this->class->id}";
        foreach (["{$base}/posts/1/attachments/1", "{$base}/threads/1/messages/1/attachments/1"] as $uri) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, 'GET'));
            $middleware = Route::gatherRouteMiddleware($route);

            $this->assertContains(\Illuminate\Routing\Middleware\ThrottleRequests::class . ':family-media', $middleware, $uri);
            $this->assertNotContains(\Illuminate\Routing\Middleware\ThrottleRequests::class . ':family', $middleware, $uri);
        }
    }
}
