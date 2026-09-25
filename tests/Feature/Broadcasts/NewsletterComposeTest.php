<?php

namespace Tests\Feature\Broadcasts;

use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Composing a newsletter: what the composer accepts, what it stores, where the
 * pictures go, whose they are, and what the live preview shows.
 *
 * Every request here is multipart with the layout as a JSON string, because
 * that is what the SPA sends (FormData cannot nest) — a test posting JSON
 * would pass on an encoding no browser uses (.claude/rules/shipping.md).
 */
class NewsletterComposeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

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
        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->masjid, $this->admin] = $this->makeOrg('Masjid An-Nur');
    }

    // ------------------------------------------------------------- storage

    #[Test]
    public function the_layout_column_holds_json_of_any_length(): void
    {
        // SQLite would round-trip a 200 KB layout through a VARCHAR(255) happily;
        // MySQL strict mode would refuse every write. So the TYPE is pinned.
        $this->assertContains(Schema::getColumnType('broadcasts', 'blocks'), ['json', 'text', 'longtext']);
    }

    #[Test]
    public function a_newsletter_is_stored_in_order_with_its_pictures_on_the_broadcast(): void
    {
        Sanctum::actingAs($this->admin);
        $this->contactIn($this->masjid);

        $this->send($this->masjid, [
            ['type' => 'heading', 'text' => '  Fall Festival  ', 'align' => 'center', 'extra' => 'dropped'],
            ['type' => 'image', 'image' => 'flyer', 'alt' => ' The flyer ', 'link' => ''],
            ['type' => 'text', 'html' => '<p>Come <b>early</b>.</p>'],
            ['type' => 'image_row', 'images' => [
                ['image' => 'left', 'alt' => 'Left photo'],
                ['image' => 'right', 'alt' => 'Right photo', 'link' => 'https://example.test/r'],
            ]],
            ['type' => 'divider'],
            ['type' => 'spacer'],
            ['type' => 'button', 'label' => 'Tickets', 'url' => 'https://example.test/t'],
        ], [
            'flyer' => UploadedFile::fake()->image('flyer.png', 1200, 900),
            'left' => UploadedFile::fake()->image('left.jpg', 600, 400),
            'right' => UploadedFile::fake()->image('right.jpg', 600, 400),
            'unused' => UploadedFile::fake()->image('stray.jpg', 10, 10),
        ])->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->sole();

        $this->assertSame([
            ['type' => 'heading', 'text' => 'Fall Festival', 'align' => 'center'],
            ['type' => 'image', 'image' => 'flyer', 'alt' => 'The flyer', 'link' => null],
            ['type' => 'text', 'html' => '<p>Come <strong>early</strong>.</p>'],
            ['type' => 'image_row', 'images' => [
                ['image' => 'left', 'alt' => 'Left photo', 'link' => null],
                ['image' => 'right', 'alt' => 'Right photo', 'link' => 'https://example.test/r'],
            ]],
            ['type' => 'divider'],
            ['type' => 'spacer', 'size' => 'medium'],
            ['type' => 'button', 'label' => 'Tickets', 'url' => 'https://example.test/t', 'align' => 'left'],
        ], $broadcast->blocks);

        // One media row per referenced picture, tagged with its key; the stray
        // upload no block names was not kept.
        $keys = $broadcast->getMedia(Broadcast::BLOCK_MEDIA_COLLECTION)
            ->map(fn ($m) => $m->getCustomProperty(Broadcast::BLOCK_KEY_PROPERTY))->sort()->values()->all();
        $this->assertSame(['flyer', 'left', 'right'], $keys);
        $this->assertCount(0, $broadcast->getMedia(Broadcast::MEDIA_COLLECTION), 'A newsletter picture is not the composer image.');

        // The queued mail carries the layout with each picture's own address.
        $urls = $broadcast->blockImageUrls();
        Mail::assertQueued(BroadcastMail::class, function (BroadcastMail $mail) use ($urls) {
            return $mail->blocks[1]['src'] === $urls['flyer']
                && $mail->blocks[3]['images'][0]['src'] === $urls['left']
                && $mail->blocks[3]['images'][1]['src'] === $urls['right'];
        });
    }

    #[Test]
    public function a_broadcast_without_blocks_is_stored_exactly_as_before(): void
    {
        Sanctum::actingAs($this->admin);
        $this->contactIn($this->masjid);

        $response = $this->call('POST', "/api/admin/masjids/{$this->masjid->id}/broadcasts", [
            'title' => 'Snow closure',
            'body' => 'Cancelled today.',
            'channels' => ['email'],
            'audience' => 'everyone',
        ], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(202);

        $this->assertArrayNotHasKey('blocks', $response->json('data'), 'The create payload gained a key.');
        $broadcast = Broadcast::withoutMasjidScope()->sole();
        $this->assertNull($broadcast->blocks);
        $this->assertNull($broadcast->newsletterBlocks());
        Mail::assertQueued(BroadcastMail::class, fn (BroadcastMail $mail) => $mail->blocks === null);
    }

    #[Test]
    public function a_newsletter_picture_never_becomes_the_push_or_top_email_picture(): void
    {
        // The case the separate collection exists for: no composer image, so a
        // newsletter picture in a shared collection would be "first" and become
        // the push's big picture (imagePath) and the email's top picture
        // (imageUrl), shown twice. With a composer image the feed and push take
        // that one, which is the pre-existing behaviour.
        Sanctum::actingAs($this->admin);
        $this->contactIn($this->masjid);

        $this->send($this->masjid, [
            ['type' => 'image', 'image' => 'inside', 'alt' => 'Inside the newsletter'],
        ], ['inside' => UploadedFile::fake()->image('inside.png', 800, 600)])->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->sole();
        $this->assertNull($broadcast->imageUrl());
        $this->assertNull($broadcast->imagePath());
        $this->assertStringEndsWith('/inside.png', $broadcast->newsletterBlocks()[0]['src']);
        Mail::assertQueued(BroadcastMail::class, fn (BroadcastMail $mail) => $mail->imageUrl === null);
    }

    // ---------------------------------------------------------- validation

    #[Test]
    public function a_layout_without_the_email_channel_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->send($this->masjid, [['type' => 'heading', 'text' => 'Hi']], [], ['channels' => ['signage']])
            ->assertStatus(422)
            ->assertJsonPath('data.blocks.0', 'The newsletter layout is sent by email only. Tick the Email channel, or remove the blocks.');

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function every_picture_needs_a_description_and_an_upload(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->send($this->masjid, [
            ['type' => 'image', 'image' => 'a', 'alt' => '   '],
            ['type' => 'image_row', 'images' => [
                ['image' => 'b', 'alt' => 'Fine'],
                ['image' => 'never-sent', 'alt' => 'Missing file'],
            ]],
        ], [
            'a' => UploadedFile::fake()->image('a.png'),
            'b' => UploadedFile::fake()->image('b.png'),
        ])->assertStatus(422);

        $this->assertSame([
            'blocks.0.alt' => ['Block 1 (image): describe the picture for people who cannot see it.'],
            'blocks.1.images.1.image' => ['Block 2 (two images, right): the picture did not upload. Choose it again.'],
        ], $response->json('data'));
    }

    #[Test]
    public function an_unreadable_layout_is_refused_by_name(): void
    {
        Sanctum::actingAs($this->admin);

        $this->call('POST', "/api/admin/masjids/{$this->masjid->id}/broadcasts", [
            'title' => 'T', 'body' => 'B', 'channels' => ['email'], 'audience' => 'everyone',
            'blocks' => '{not json',
        ], [], [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('data.blocks.0', 'The newsletter layout could not be read. Reload the page and try again.');
    }

    // ------------------------------------------------------ tenant scoping

    #[Test]
    public function an_admin_cannot_compose_or_preview_a_newsletter_for_another_organisation(): void
    {
        [$other] = $this->makeOrg('Other Masjid');
        Sanctum::actingAs($this->admin);

        $this->send($other, [['type' => 'heading', 'text' => 'Hi']])->assertStatus(403);
        $this->preview($other, [['type' => 'heading', 'text' => 'Hi']])->assertStatus(403);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_broadcast_only_ever_resolves_its_own_pictures(): void
    {
        // Both organisations name their picture "flyer". Each newsletter must
        // resolve the key to ITS OWN media row: keys are labels inside one
        // broadcast, never a way to reach another organisation's file.
        [$other, $otherAdmin] = $this->makeOrg('Other Masjid');
        $this->contactIn($this->masjid);
        $this->contactIn($other);

        Sanctum::actingAs($this->admin);
        $this->send($this->masjid, [['type' => 'image', 'image' => 'flyer', 'alt' => 'Ours']],
            ['flyer' => UploadedFile::fake()->image('ours.png')])->assertStatus(202);

        Sanctum::actingAs($otherAdmin);
        app(TenantContext::class)->forgetTenant();
        $this->send($other, [['type' => 'image', 'image' => 'flyer', 'alt' => 'Theirs']],
            ['flyer' => UploadedFile::fake()->image('theirs.png')])->assertStatus(202);
        app(TenantContext::class)->forgetTenant();

        $ours = Broadcast::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->sole();
        $theirs = Broadcast::withoutMasjidScope()->where('masjid_id', $other->id)->sole();

        $this->assertStringEndsWith('/ours.png', $ours->newsletterBlocks()[0]['src']);
        $this->assertStringEndsWith('/theirs.png', $theirs->newsletterBlocks()[0]['src']);

        // And the other organisation's admin cannot read our broadcast at all.
        $this->getJson("/api/admin/masjids/{$other->id}/broadcasts/{$ours->id}")->assertStatus(404);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/broadcasts/{$ours->id}")->assertStatus(403);
    }

    // ------------------------------------------------------------- preview

    #[Test]
    public function the_preview_is_the_email_that_would_be_sent_with_local_pictures_marked(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->preview($this->masjid, [
            ['type' => 'heading', 'text' => 'Fall Festival'],
            ['type' => 'image', 'image' => 'flyer-1', 'alt' => 'The flyer'],
        ], ['title' => 'October', 'body' => 'Hello all.'])->assertOk();

        $html = $response->json('data.html');
        $this->assertStringContainsString('>Fall Festival</h2>', $html);
        $this->assertStringContainsString('src="https://preview.invalid/newsletter-image/flyer-1"', $html);
        $this->assertStringContainsString('Masjid An-Nur', $html, 'The preview names the caller\'s own organisation.');
        $this->assertStringContainsString('FALL FESTIVAL', $response->json('data.text'));

        // Nothing is stored or sent.
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
        Mail::assertNothingQueued();
    }

    #[Test]
    public function with_no_blocks_the_preview_shows_the_original_email(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->preview($this->masjid, [], ['title' => 'Snow closure', 'body' => 'Cancelled today.'])->assertOk();

        $this->assertSame((new BroadcastMail(
            orgName: 'Masjid An-Nur',
            title: 'Snow closure',
            body: 'Cancelled today.',
            unsubscribeUrl: 'https://preview.invalid/unsubscribe',
        ))->render(), $response->json('data.html'));
        $this->assertNull($response->json('data.text'));
    }

    #[Test]
    public function the_preview_reports_what_the_send_would_refuse(): void
    {
        Sanctum::actingAs($this->admin);

        $this->preview($this->masjid, [['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)']])
            ->assertStatus(422)
            ->assertJsonPath('data', ['blocks.0.url' => ['Block 1 (button): enter the full web address it opens, starting https://.']]);
    }

    #[Test]
    public function the_preview_needs_a_signed_in_admin(): void
    {
        $this->preview($this->masjid, [['type' => 'heading', 'text' => 'Hi']])->assertStatus(401);
    }

    // -------------------------------------------------------------- helpers

    /** @return array{0: Masjid, 1: User} */
    private function makeOrg(string $name): array
    {
        $masjid = Masjid::create([
            'name' => $name,
            'email' => 'office-' . uniqid() . '@org.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        $masjid->user_id = $admin->id;
        $masjid->save();

        return [$masjid, $admin];
    }

    private function contactIn(Masjid $masjid): void
    {
        Contact::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'first_name' => 'Jane',
            'last_name' => 'Example',
            'email' => 'jane-' . $masjid->id . '@example.test',
        ]);
    }

    /** POST the composer as the SPA does: multipart, the layout as a JSON string. */
    private function send(Masjid $masjid, array $blocks, array $images = [], array $overrides = [], ?UploadedFile $composerImage = null)
    {
        $files = [];
        foreach ($images as $key => $file) {
            $files['block_images'][$key] = $file;
        }
        if ($composerImage) {
            $files['image'] = $composerImage;
        }

        return $this->call('POST', "/api/admin/masjids/{$masjid->id}/broadcasts", array_merge([
            'title' => 'October newsletter',
            'body' => 'Here is what is happening.',
            'channels' => ['email'],
            'audience' => 'everyone',
            'blocks' => json_encode($blocks),
        ], $overrides), [], $files, ['HTTP_ACCEPT' => 'application/json']);
    }

    private function preview(Masjid $masjid, array $blocks, array $fields = [])
    {
        return $this->call('POST', "/api/admin/masjids/{$masjid->id}/broadcasts/preview", array_merge([
            'blocks' => json_encode($blocks),
        ], $fields), [], [], ['HTTP_ACCEPT' => 'application/json']);
    }
}
