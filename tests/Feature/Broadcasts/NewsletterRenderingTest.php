<?php

namespace Tests\Feature\Broadcasts;

use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Services\Broadcast\Newsletter\NewsletterRenderer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * How a newsletter broadcast looks in an inbox.
 *
 * The HTML and the plain-text part are SNAPSHOTS: one layout using every block
 * type is rendered through BroadcastMail and compared, byte for byte, with
 * tests/fixtures/newsletter/. A change to the email's markup must therefore be
 * a deliberate change to a reviewed file, not a side effect. To regenerate
 * after an intended change, run this file with UPDATE_SNAPSHOTS=1, then read
 * the diff of the fixtures before committing them.
 *
 * Beyond the snapshot, the rules that make the markup safe for mail clients
 * are asserted structurally, so they hold for layouts the snapshot does not
 * contain: tables not divs, a declared background on every row (dark-mode
 * inversion), every picture absolute with alt text and a width.
 */
class NewsletterRenderingTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = 'tests/fixtures/newsletter';

    private const READER = 'reader@family.test';

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

        config(['mail.default' => 'array', 'queue.default' => 'sync']);

        Storage::fake('public');
        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function a_newsletter_renders_to_its_pinned_html(): void
    {
        $this->assertMatchesSnapshot('newsletter.html', $this->mail()->render());
    }

    #[Test]
    public function its_plain_text_alternative_renders_to_its_pinned_text(): void
    {
        $this->assertMatchesSnapshot('newsletter.txt', (string) $this->mail()->textAlternative());
    }

    #[Test]
    public function the_markup_is_built_for_mail_clients(): void
    {
        $html = $this->mail()->render();

        // Dark mode is declared, and there is a palette for clients that honour it.
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);

        $rows = (new NewsletterRenderer())->html($this->layout());

        // Layout by tables only: Outlook for Windows ignores the rest.
        $this->assertDoesNotMatchRegularExpression('/<div\b/i', $rows);
        $this->assertDoesNotMatchRegularExpression('/display:\s*(flex|grid)|float:/i', $rows);

        // Every cell declares its own background, so an inverting client
        // inverts one known colour instead of guessing at a transparent cell.
        preg_match_all('#<td\b([^>]*)>#', $rows, $cells);
        $this->assertGreaterThan(10, count($cells[1]));
        foreach ($cells[1] as $attributes) {
            $this->assertStringContainsString('background-color:', $attributes, 'A block cell with no declared background.');
        }

        // Every picture: absolute https, a description, a width for Outlook.
        preg_match_all('#<img\b[^>]*>#', $rows, $images);
        $this->assertCount(3, $images[0]);
        foreach ($images[0] as $img) {
            $this->assertMatchesRegularExpression('#\ssrc="https://#', $img);
            $this->assertMatchesRegularExpression('#\salt="[^"]+"#', $img);
            $this->assertMatchesRegularExpression('#\swidth="\d+"#', $img);
        }

        // All styling is inline: the renderer writes no <style> of its own.
        $this->assertStringNotContainsString('<style', $rows);
    }

    #[Test]
    public function a_picture_whose_address_is_not_absolute_is_left_out_not_sent_broken(): void
    {
        $blocks = [
            ['type' => 'image', 'image' => 'a', 'alt' => 'Relative', 'src' => '/storage/1/a.png'],
            ['type' => 'image', 'image' => 'b', 'alt' => 'Missing', 'src' => null],
            ['type' => 'image', 'image' => 'c', 'alt' => 'Script', 'src' => 'javascript:alert(1)'],
            // A stored row without a description: the request requires one, so
            // the renderer leaves an unlabelled picture out rather than send it.
            ['type' => 'image', 'image' => 'e', 'alt' => '   ', 'src' => 'https://cdn.example.test/e.png'],
            ['type' => 'image', 'image' => 'f', 'src' => 'https://cdn.example.test/f.png'],
            ['type' => 'image', 'image' => 'd', 'alt' => 'Kept', 'src' => 'https://cdn.example.test/d.png'],
        ];
        $rows = (new NewsletterRenderer())->html($blocks);

        $this->assertSame(1, substr_count($rows, '<img'));
        $this->assertStringContainsString('alt="Kept"', $rows);
        $this->assertStringNotContainsString('e.png', $rows);
        $this->assertStringNotContainsString('f.png', $rows);
        $this->assertSame('[Kept]', (new NewsletterRenderer())->text($blocks));
    }

    #[Test]
    public function the_more_details_link_is_left_out_of_a_newsletter_unless_it_is_a_web_address(): void
    {
        // Laravel's url rule passes ms-settings:// and ~300 other schemes; the
        // newsletter prints only what NewsletterBlocks::webUrl accepts.
        $mail = new BroadcastMail(
            orgName: 'Masjid An-Nur',
            title: 'October newsletter',
            body: 'Hello.',
            link: 'ms-settings://privacy',
            blocks: [['type' => 'heading', 'text' => 'Fall Festival']],
        );

        $this->assertStringNotContainsString('ms-settings', $mail->render());
        $this->assertStringNotContainsString('More details', $mail->render());
        $this->assertStringNotContainsString('More details', (string) $mail->textAlternative());
    }

    #[Test]
    public function a_newsletter_goes_out_with_its_own_pictures_a_text_part_and_the_unsubscribe_link(): void
    {
        $masjid = Masjid::create([
            'name' => 'Masjid An-Nur',
            'email' => 'office@org.test',
            'phone' => '+15550002222',
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        Contact::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Example',
            'email' => self::READER,
        ]);

        $broadcast = Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'title' => 'October newsletter',
            'body' => 'Here is what is happening this month.',
            'blocks' => [
                ['type' => 'heading', 'text' => 'Fall Festival', 'align' => 'center'],
                ['type' => 'image', 'image' => 'flyer', 'alt' => 'Festival flyer', 'link' => null],
            ],
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_PENDING,
        ]);
        $broadcast->addMedia(UploadedFile::fake()->image('flyer.png', 1200, 800))
            ->withCustomProperties([Broadcast::BLOCK_KEY_PROPERTY => 'flyer'])
            ->toMediaCollection(Broadcast::BLOCK_MEDIA_COLLECTION);
        $broadcast->refresh();

        $tenant = app(TenantContext::class);
        $tenant->set($masjid->id);
        try {
            app(EmailChannel::class)->deliver($broadcast, $masjid);
        } finally {
            $tenant->forgetTenant();
        }

        $email = $this->theOneMessageTo(self::READER);
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        // The address is absolute even though the faked disk serves a bare
        // path: a relative src resolves against nothing in a mail client, so
        // the model pins it to APP_URL (production's disk is absolute already).
        $path = $broadcast->getFirstMedia(Broadcast::BLOCK_MEDIA_COLLECTION)->getUrl();
        $url = $broadcast->blockImageUrls()['flyer'];
        $this->assertMatchesRegularExpression('#^https?://[^/]+/#', $url);
        $this->assertStringEndsWith(ltrim(parse_url($path, PHP_URL_PATH), '/'), $url);
        $this->assertStringContainsString('src="' . e($url) . '"', $html);
        $this->assertStringContainsString('alt="Festival flyer"', $html);
        $this->assertStringContainsString('>Fall Festival</h2>', $html);

        // The text part says what the HTML says.
        $this->assertStringContainsString('FALL FESTIVAL', $text);
        $this->assertStringContainsString('[Festival flyer]', $text);
        $this->assertStringContainsString('Assalamu alaikum Amina,', $text);

        // Unsubscribe is untouched: the footer link in both parts, and the
        // RFC 8058 one-click headers.
        $this->assertMatchesRegularExpression('#<a href="[^"]+/unsubscribe/[^"]+"[^>]*>Unsubscribe from Masjid An-Nur\'s emails</a>#', $html);
        $this->assertMatchesRegularExpression('#Unsubscribe from Masjid An-Nur\'s emails: https?://\S+/unsubscribe/\S+#', $text);
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        $this->assertMatchesRegularExpression('#^<https?://\S+>$#', (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
    }

    #[Test]
    public function a_queued_newsletter_comes_back_off_the_queue_intact(): void
    {
        $mail = $this->mail();
        $fromQueue = unserialize(serialize($mail));

        $this->assertSame($mail->render(), $fromQueue->render());
        $this->assertSame($mail->textAlternative(), $fromQueue->textAlternative());
    }

    #[Test]
    public function a_mail_queued_before_the_layout_existed_still_renders_the_original_email(): void
    {
        $mail = new BroadcastMail(orgName: 'Masjid An-Nur', title: 'Snow closure', body: 'Stay home.');

        // What the previous class put on the queue: no `blocks` entry at all.
        // (Laravel already leaves a property at its default out of the payload;
        // any entry is stripped here so the case does not depend on that.)
        $payload = serialize($mail);
        $payload = preg_replace_callback(
            '/^O:(\d+):"App\\\\Mail\\\\BroadcastMail":(\d+):\{(.*)\}$/s',
            function ($m) {
                $body = preg_replace('/s:6:"blocks";(N;|a:\d+:\{.*?\})/s', '', $m[3], -1, $removed);

                return 'O:' . $m[1] . ':"App\\Mail\\BroadcastMail":' . ((int) $m[2] - $removed) . ':{' . $body . '}';
            },
            $payload,
        );
        $this->assertStringNotContainsString('"blocks"', $payload, 'The premise: the payload has no layout entry.');

        $old = unserialize($payload);

        $this->assertInstanceOf(BroadcastMail::class, $old);
        $this->assertNull($old->blocks);
        $this->assertSame($mail->render(), $old->render());
        $this->assertNull($old->textAlternative());
    }

    // -------------------------------------------------------------- helpers

    private function mail(): BroadcastMail
    {
        return new BroadcastMail(
            orgName: 'Masjid An-Nur',
            title: 'October newsletter',
            body: "Here is what is happening this month.\nRead to the end for volunteer spots.",
            link: 'https://example.test/october',
            imageUrl: null,
            recipientName: 'Amina',
            orgEmail: 'office@org.test',
            unsubscribeUrl: 'https://app.example.test/unsubscribe/pinned-token',
            unsubscribeOneClickUrl: 'https://app.example.test/unsubscribe/pinned-token/one-click',
            blocks: $this->layout(),
        );
    }

    /** Every block type, image addresses already resolved as EmailChannel resolves them. */
    private function layout(): array
    {
        return [
            ['type' => 'heading', 'text' => 'Fall Festival 2026 & more', 'align' => 'center'],
            ['type' => 'text', 'html' => '<p>Join us on <strong>Saturday</strong> for food, games and <em>community</em>.</p>'
                . '<ul><li>Bouncy castle</li><li>Food trucks</li></ul>'
                . '<p>Questions? <a href="mailto:office@example.test">Email the office</a> or see <a href="https://example.test/festival">the festival page</a>.</p>'],
            ['type' => 'image', 'image' => 'flyer', 'alt' => 'Festival flyer: October 17, 11am to 5pm', 'link' => 'https://example.test/festival',
                'src' => 'https://cdn.example.test/storage/1/flyer.png'],
            ['type' => 'button', 'label' => 'Get tickets', 'url' => 'https://example.test/tickets?utm=news&x=2', 'align' => 'center'],
            ['type' => 'divider'],
            ['type' => 'image_row', 'images' => [
                ['image' => 'left', 'alt' => 'Children at the bouncy castle', 'link' => null, 'src' => 'https://cdn.example.test/storage/2/left.jpg'],
                ['image' => 'right', 'alt' => 'The food court', 'link' => 'https://example.test/food', 'src' => 'https://cdn.example.test/storage/3/right.jpg'],
            ]],
            ['type' => 'spacer', 'size' => 'large'],
            ['type' => 'heading', 'text' => 'Volunteer', 'align' => 'left'],
            ['type' => 'text', 'html' => '<ol><li>Set-up crew</li><li>Parking</li></ol>'],
            ['type' => 'button', 'label' => 'Sign up', 'url' => 'https://example.test/volunteer', 'align' => 'left'],
        ];
    }

    private function assertMatchesSnapshot(string $name, string $actual): void
    {
        $path = base_path(self::FIXTURES . '/' . $name);

        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $actual);
            $this->markTestSkipped("Snapshot {$name} rewritten; review its diff before committing.");
        }

        $this->assertFileExists($path, "No snapshot {$name}; run with UPDATE_SNAPSHOTS=1 and review it.");
        $this->assertSame(file_get_contents($path), $actual, "The newsletter {$name} changed. If intended, regenerate and review the diff.");
    }

    private function theOneMessageTo(string $address): Email
    {
        $matches = [];

        foreach (app('mail.manager')->mailer('array')->getSymfonyTransport()->messages() as $sent) {
            $email = $sent->getOriginalMessage();
            $to = array_map(fn ($a) => strtolower($a->getAddress()), $email->getTo());

            if (in_array(strtolower($address), $to, true)) {
                $matches[] = $email;
            }
        }

        $this->assertCount(1, $matches, "Expected exactly one message to {$address}.");

        return $matches[0];
    }
}
