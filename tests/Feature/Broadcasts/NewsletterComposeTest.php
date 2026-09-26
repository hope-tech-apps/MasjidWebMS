<?php

namespace Tests\Feature\Broadcasts;

use App\Http\Requests\Admin\Broadcasts\StoreBroadcastRequest;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;
use App\Services\Broadcast\Newsletter\NewsletterPicture;
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
        $inside = $broadcast->getFirstMedia(Broadcast::BLOCK_MEDIA_COLLECTION);
        $this->assertStringContainsString('/' . $inside->id . '/', $broadcast->newsletterBlocks()[0]['src']);
        Mail::assertQueued(BroadcastMail::class, fn (BroadcastMail $mail) => $mail->imageUrl === null);
    }

    #[Test]
    public function newsletter_pictures_are_published_without_their_metadata_or_their_file_name(): void
    {
        // A phone photo carries where it was taken. The stored file is public
        // and its address is in every recipient's email, so the GPS tag and the
        // personal file name must not survive the upload.
        Sanctum::actingAs($this->admin);
        $this->contactIn($this->masjid);

        $jpeg = $this->jpegWithGpsTag(800, 600);
        $premise = tempnam(sys_get_temp_dir(), 'gps');
        file_put_contents($premise, $jpeg);
        $this->assertSame('N', (@exif_read_data($premise) ?: [])['GPSLatitudeRef'] ?? null, 'The premise: the upload carries a GPS tag.');
        unlink($premise);

        $this->send($this->masjid, [['type' => 'image', 'image' => 'castle', 'alt' => 'The bouncy castle']], [
            'castle' => UploadedFile::fake()->createWithContent('aisha-at-the-bouncy-castle.jpg', $jpeg),
        ])->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->sole();
        $media = $broadcast->getFirstMedia(Broadcast::BLOCK_MEDIA_COLLECTION);
        $stored = (string) file_get_contents($media->getPath());

        $this->assertStringNotContainsString("Exif\x00\x00", $stored, 'The served file still has an EXIF block.');
        $this->assertArrayNotHasKey('GPSLatitudeRef', @exif_read_data($media->getPath()) ?: []);
        $this->assertSame(800, getimagesize($media->getPath())[0], 'Still the picture, at its own size.');

        foreach ([$media->file_name, $media->name, $broadcast->newsletterBlocks()[0]['src']] as $public) {
            $this->assertStringNotContainsString('aisha', $public);
        }
    }

    #[Test]
    public function newsletter_pictures_are_emailed_at_most_twice_the_column_width(): void
    {
        // The email shows a picture 552px wide at most; an 8 MB camera original
        // in ten thousand inboxes is weight nobody sees.
        Sanctum::actingAs($this->admin);
        $this->contactIn($this->masjid);

        $this->send($this->masjid, [
            ['type' => 'image', 'image' => 'wide', 'alt' => 'A wide photo'],
            ['type' => 'image', 'image' => 'small', 'alt' => 'A small photo'],
        ], [
            'wide' => UploadedFile::fake()->image('wide.jpg', 3000, 1500),
            'small' => UploadedFile::fake()->image('small.png', 600, 400),
        ])->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->sole();
        $media = $broadcast->getMedia(Broadcast::BLOCK_MEDIA_COLLECTION)
            ->keyBy(fn ($m) => $m->getCustomProperty(Broadcast::BLOCK_KEY_PROPERTY));

        $this->assertSame([NewsletterPicture::MAX_WIDTH, 552], array_slice(getimagesize($media['wide']->getPath()), 0, 2));
        $this->assertSame([600, 400], array_slice(getimagesize($media['small']->getPath()), 0, 2), 'A small picture is not enlarged.');
        $this->assertSame(1104, NewsletterPicture::MAX_WIDTH, 'Twice the 552px column.');

        // The email points at the stored copy, not at anything larger.
        Mail::assertQueued(BroadcastMail::class, function (BroadcastMail $mail) use ($media) {
            return str_contains($mail->blocks[0]['src'], '/' . $media['wide']->id . '/' . $media['wide']->file_name)
                && str_contains($mail->render(), 'src="' . e($mail->blocks[0]['src']) . '"');
        });
    }

    #[Test]
    public function a_picture_too_large_to_decode_is_refused_before_anything_is_stored(): void
    {
        // 7000 x 7000 declared in a few bytes: decoding it would need ~200 MB.
        Sanctum::actingAs($this->admin);

        $ihdr = pack('NNCCCCC', 7000, 7000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

        $this->send($this->masjid, [['type' => 'image', 'image' => 'huge', 'alt' => 'A huge picture']], [
            'huge' => UploadedFile::fake()->createWithContent('huge.png', $png),
        ])->assertStatus(422)->assertJsonPath('data', ['block_images.huge' => [
            'A picture is 49 megapixels, which is too large to send. Save a smaller copy (6000 × 6000 pixels at most) and choose it again.',
        ]]);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_newsletter_whose_more_details_link_is_not_a_web_address_is_refused(): void
    {
        // Laravel's url rule passes file://; the newsletter would print it.
        Sanctum::actingAs($this->admin);
        $this->contactIn($this->masjid);

        $this->send($this->masjid, [['type' => 'heading', 'text' => 'Hi']], [], ['link' => 'file://fileserver.test/share'])
            ->assertStatus(422)
            ->assertJsonPath('data', ['link' => [StoreBroadcastRequest::NEWSLETTER_LINK_ERROR]]);
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());

        // The legacy email's rule is unchanged.
        $this->call('POST', "/api/admin/masjids/{$this->masjid->id}/broadcasts", [
            'title' => 'Snow closure', 'body' => 'Cancelled.', 'channels' => ['email'], 'audience' => 'everyone',
            'link' => 'file://fileserver.test/share',
        ], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(202);
    }

    #[Test]
    public function a_newsletter_too_large_for_gmail_is_refused_and_the_preview_warns_first(): void
    {
        // Gmail clips an email over ~102 KB, and the unsubscribe footer is the
        // last thing in it.
        Sanctum::actingAs($this->admin);
        $paragraph = ['type' => 'text', 'html' => '<p>' . str_repeat('word ', 3800) . '</p>'];

        $tooBig = array_fill(0, 6, $paragraph);
        $refused = $this->send($this->masjid, $tooBig)->assertStatus(422);
        $this->assertStringContainsString('Gmail cuts off emails over about 100 KB', $refused->json('data.blocks.0'));
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());

        $preview = $this->preview($this->masjid, $tooBig)->assertOk();
        $this->assertGreaterThan(NewsletterBlocks::MAX_EMAIL_BYTES, $preview->json('data.size'));
        $this->assertSame(NewsletterBlocks::emailSizeError($preview->json('data.size')), $preview->json('data.errors')['blocks.size']);
        $this->assertSame(strlen($preview->json('data.html')), $preview->json('data.size'));

        $nearly = $this->preview($this->masjid, array_fill(0, 4, $paragraph))->assertOk();
        $this->assertGreaterThan(NewsletterBlocks::WARN_EMAIL_BYTES, $nearly->json('data.size'));
        $this->assertLessThanOrEqual(NewsletterBlocks::MAX_EMAIL_BYTES, $nearly->json('data.size'));
        $this->assertSame([], (array) $nearly->json('data.errors'));
        $this->assertSame([NewsletterBlocks::emailSizeWarning($nearly->json('data.size'))], $nearly->json('data.warnings'));

        $this->preview($this->masjid, [$paragraph])->assertOk()->assertJsonPath('data.warnings', []);
    }

    #[Test]
    public function the_layout_column_cannot_be_rolled_back_while_a_newsletter_is_stored(): void
    {
        // A scheduled newsletter would otherwise go out as the plain email with
        // its blocks silently gone, and every sent one would lose its record.
        Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Scheduled',
            'body' => 'Body.',
            'blocks' => [['type' => 'heading', 'text' => 'Later']],
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_SCHEDULED,
        ]);

        $migration = require database_path('migrations/2026_09_25_140000_add_blocks_to_broadcasts_table.php');

        try {
            $migration->down();
            $this->fail('down() dropped a column that holds a newsletter.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 broadcast(s) carry a newsletter layout', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('broadcasts', 'blocks'));
        $this->assertSame([['type' => 'heading', 'text' => 'Later']], Broadcast::withoutMasjidScope()->sole()->blocks);
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

        $oursMedia = $ours->getFirstMedia(Broadcast::BLOCK_MEDIA_COLLECTION);
        $theirsMedia = $theirs->getFirstMedia(Broadcast::BLOCK_MEDIA_COLLECTION);
        $this->assertNotSame($oursMedia->id, $theirsMedia->id);
        $this->assertStringContainsString('/' . $oursMedia->id . '/', $ours->newsletterBlocks()[0]['src']);
        $this->assertStringContainsString('/' . $theirsMedia->id . '/', $theirs->newsletterBlocks()[0]['src']);

        // And the other organisation's admin cannot read our broadcast at all.
        $this->getJson("/api/admin/masjids/{$other->id}/broadcasts/{$ours->id}")->assertStatus(404);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/broadcasts/{$ours->id}")->assertStatus(403);
    }

    // ------------------------------------------------------------- preview

    #[Test]
    public function the_preview_is_the_email_that_would_be_sent_with_local_pictures_marked(): void
    {
        // Previewed by the SECOND organisation, so the one with the lower id is
        // not the answer by accident: the org comes from the route.
        [$other, $otherAdmin] = $this->makeOrg('Other Masjid');
        Sanctum::actingAs($otherAdmin);

        $response = $this->preview($other, [
            ['type' => 'heading', 'text' => 'Fall Festival'],
            ['type' => 'image', 'image' => 'flyer-1', 'alt' => 'The flyer'],
        ], ['title' => 'October', 'body' => 'Hello all.'])->assertOk();

        $html = $response->json('data.html');
        $this->assertStringContainsString('>Fall Festival</h2>', $html);
        $this->assertStringContainsString('src="https://preview.invalid/newsletter-image/flyer-1"', $html);
        $this->assertStringContainsString('Other Masjid', $html, 'The preview names the caller\'s own organisation.');
        $this->assertStringNotContainsString('Masjid An-Nur', $html);
        $this->assertSame([], (array) $response->json('data.errors'));
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
    public function the_preview_lists_what_the_send_would_refuse_without_rendering_it(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->preview($this->masjid, [['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)']])
            ->assertOk()
            ->assertJsonPath('data.errors', ['blocks.0.url' => 'Block 1 (button): enter the full web address it opens, starting https://.']);

        $this->assertStringNotContainsString('javascript:', $response->json('data.html'));
        $this->assertStringNotContainsString('>Go</a>', $response->json('data.html'));
    }

    #[Test]
    public function an_unfinished_layout_still_previews_its_finished_blocks(): void
    {
        // Every new block starts empty. The preview keeps showing the layout as
        // it stands, and lists what the send would still refuse.
        Sanctum::actingAs($this->admin);

        $response = $this->preview($this->masjid, [
            ['type' => 'heading', 'text' => 'Fall Festival', 'align' => 'center'],
            ['type' => 'image', 'image' => 'img-1', 'alt' => '', 'link' => ''],
            ['type' => 'text', 'html' => ''],
            ['type' => 'button', 'label' => '', 'url' => '', 'align' => 'center'],
            ['type' => 'image_row', 'images' => [['image' => 'img-2', 'alt' => 'Left'], ['image' => 'img-3', 'alt' => '']]],
            ['type' => 'button', 'label' => 'Tickets', 'url' => 'https://example.test/t', 'align' => 'center'],
        ], ['link' => 'https://example.test/more'])->assertOk();

        $html = $response->json('data.html');
        $this->assertStringContainsString('>Fall Festival</h2>', $html);
        $this->assertStringContainsString('>Tickets</a>', $html);
        $this->assertStringContainsString('>More details</a>', $html);
        $this->assertStringNotContainsString('newsletter-image/img-', $html, 'An incomplete picture is not previewed.');

        $this->assertSame([
            'blocks.1.alt', 'blocks.2.html', 'blocks.3.label', 'blocks.3.url', 'blocks.4.images.1.alt',
        ], array_keys($response->json('data.errors')));
        $this->assertSame('Block 2 (image): describe the picture for people who cannot see it.', $response->json('data.errors')['blocks.1.alt']);

        // An unfinished link is listed too, and left out of the email.
        $unfinished = $this->preview($this->masjid, [['type' => 'heading', 'text' => 'Hi']], ['link' => 'ms-settings://privacy'])->assertOk();
        $this->assertSame(['link' => StoreBroadcastRequest::NEWSLETTER_LINK_ERROR], $unfinished->json('data.errors'));
        $this->assertStringNotContainsString('ms-settings', $unfinished->json('data.html'));
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

    /**
     * A JPEG carrying an EXIF block with a GPS directory (version 2.2, latitude
     * reference "N"), built byte by byte so the fixture holds no real location.
     */
    private function jpegWithGpsTag(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // Big-endian TIFF: IFD0 (at 8) has one entry, GPSInfo, pointing at the
        // GPS IFD (at 26), which holds GPSVersionID and GPSLatitudeRef.
        $tiff = 'MM' . pack('n', 42) . pack('N', 8)
            . pack('n', 1) . pack('nnNN', 0x8825, 4, 1, 26) . pack('N', 0)
            . pack('n', 2)
            . pack('nnN', 0x0000, 1, 4) . "\x02\x02\x00\x00"
            . pack('nnN', 0x0001, 2, 2) . "N\x00\x00\x00"
            . pack('N', 0);
        $app1 = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    private function preview(Masjid $masjid, array $blocks, array $fields = [])
    {
        return $this->call('POST', "/api/admin/masjids/{$masjid->id}/broadcasts/preview", array_merge([
            'blocks' => json_encode($blocks),
        ], $fields), [], [], ['HTTP_ACCEPT' => 'application/json']);
    }
}
