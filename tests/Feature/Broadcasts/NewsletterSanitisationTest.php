<?php

namespace Tests\Feature\Broadcasts;

use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\Newsletter\NewsletterBlocks;
use App\Services\Broadcast\Newsletter\NewsletterRenderer;
use App\Services\Broadcast\Newsletter\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing an admin types or pastes into a newsletter can become script, style
 * or a link somewhere dangerous in 2,800 congregants' inboxes.
 *
 * The admin is not assumed malicious; the paste buffer is. A block of text
 * copied out of another email arrives with its `<style>`, its tracking pixel
 * and its `onclick`s, and a compromised admin account can type anything. So
 * each hostile input here is pushed through the path it would really take — the
 * multipart request, the stored row, the queued mail, the renderer — and the
 * HTML that would reach an inbox is searched for what must not be there.
 */
class NewsletterSanitisationTest extends TestCase
{
    use RefreshDatabase;

    /** Strings that must appear nowhere in a rendered newsletter. */
    private const FORBIDDEN = [
        '<script', '</script', 'alert(', '<style', 'onclick', 'onerror', 'onmouseover', 'javascript:',
        'vbscript:', 'data:text', '<iframe', '<svg', '<object', '<embed', '<form', 'tracker.test',
        'expression(', 'position:fixed', 'color:red', 'srcdoc',
    ];

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

        Storage::fake('public');
        Mail::fake();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Masjid An-Nur',
            'email' => 'office@org.test',
            'phone' => '+15550003333',
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550004444']);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    /** @return array<string, array{0: string, 1: string, 2?: string}> hostile rich text, the words that must survive it, and the text part when it differs */
    public static function hostileText(): array
    {
        return [
            'script element' => ['<p>Hello<script>alert(1)</script> there</p>', 'Hello there'],
            'style element' => ['<style>body{position:fixed}</style><p>Eid prayer</p>', 'Eid prayer'],
            'event handlers and inline style' => ['<p onclick="alert(1)" style="color:red">Iftar <b onmouseover="alert(1)">tonight</b></p>', 'Iftar tonight'],
            'javascript link' => ['<p><a href="javascript:alert(1)">Click</a> here</p>', 'Click here'],
            'entity-encoded javascript link' => ['<p><a href="&#106;avascript:alert(1)">Click</a></p>', 'Click'],
            'scheme hidden by whitespace' => ["<p><a href=\"java\tscript:alert(1)\">Click</a></p>", 'Click'],
            'vbscript and data links' => ['<p><a href="vbscript:msgbox(1)">A</a> <a href="data:text/html,<script>alert(1)</script>">B</a></p>', 'A B'],
            'tracking pixel' => ['<p>Hi<img src="https://tracker.test/p.gif" onerror="alert(1)"></p>', 'Hi'],
            'iframe, svg, object, form' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe><svg onload="alert(1)"></svg><object data="x"></object><form><input></form><p>Kept</p>', 'Kept'],
            'css expression' => ['<p style="width:expression(alert(1))">Old IE</p>', 'Old IE'],
            'unclosed script' => ['<p>Before</p><script>alert(1)', 'Before'],
            // A kept link: the text part writes its address out after the words.
            'script inside a link' => ['<p><a href="https://ok.test"><script>alert(1)</script>Safe link</a></p>', 'Safe link', 'Safe link (https://ok.test)'],
            // Markup spelled out in entities is WORDS: decoded by the parser, so
            // it must be escaped again on the way out or it becomes a tag.
            'entity-encoded markup' => ['<p>&lt;b&gt;bold?&lt;/b&gt; 1 &lt; 2 &amp; 3</p>', '<b>bold?</b> 1 < 2 & 3'],
        ];
    }

    #[Test]
    #[DataProvider('hostileText')]
    public function rich_text_keeps_the_words_and_nothing_executable(string $input, string $words, ?string $text = null): void
    {
        foreach ([
            'stored' => RichText::sanitize($input),
            'emailed' => (new NewsletterRenderer())->html([['type' => 'text', 'html' => $input]]),
        ] as $where => $html) {
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $html, "{$where}: {$needle}");
            }
            $this->assertSame($words, $this->visible($html), "{$where}: the words a reader should see");
        }

        $this->assertSame($text ?? $words, trim((string) preg_replace('/\s+/', ' ', RichText::toText($input))), 'the text part');
    }

    #[Test]
    public function text_that_spells_out_markup_reaches_the_reader_as_text(): void
    {
        $input = '<p>&lt;script&gt;alert(1)&lt;/script&gt; 1 &lt; 2 &amp; 3</p>';

        foreach ([
            'stored' => RichText::sanitize($input),
            'emailed' => (new NewsletterRenderer())->html([['type' => 'text', 'html' => $input]]),
        ] as $where => $html) {
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; 1 &lt; 2 &amp; 3</p>', $html, $where);
            $this->assertStringNotContainsString('<script', $html, $where);
        }
    }

    #[Test]
    public function only_web_and_mail_links_survive_and_keep_their_address(): void
    {
        $html = RichText::sanitize(
            '<p><a href="https://example.test/a?x=1&y=2" target="_top" rel="opener" class="btn">Web</a> '
            . '<a href="mailto:office@example.test">Mail</a> <a href="/relative">Rel</a> <a href="#top">Hash</a> '
            . '<a href="ftp://example.test/f">Ftp</a> <a href="https:/relative">NoHost</a> <a href="http:foo">NoHost2</a></p>'
        );

        // The last two have a web scheme and no host: a mail client resolves
        // them against nothing, so they are words, not links.
        $this->assertSame(
            '<p><a href="https://example.test/a?x=1&amp;y=2">Web</a> <a href="mailto:office@example.test">Mail</a> Rel Hash Ftp NoHost NoHost2</p>',
            $html,
        );
    }

    #[Test]
    public function what_a_contenteditable_box_produces_becomes_clean_paragraphs(): void
    {
        // Chrome's contenteditable: first line bare, later lines in <div>s, an
        // empty line as <div><br></div>, formatting as <b>/<i>, spans from paste.
        $this->assertSame(
            '<p>First line</p><p>Second <strong>bold</strong> <em>and italic</em></p><p>After a gap</p><ul><li>One</li><li>Two</li></ul>',
            RichText::sanitize('First line<div>Second <b>bold</b> <span style="font-size:40px"><i>and italic</i></span></div><div><br></div><div>After a gap</div><ul><li>One</li><li><span>Two</span></li></ul>'),
        );

        $this->assertSame("First line\n\nSecond bold and italic\n\nAfter a gap\n\n- One\n- Two", RichText::toText(
            'First line<div>Second <b>bold</b> <span><i>and italic</i></span></div><div><br></div><div>After a gap</div><ul><li>One</li><li>Two</li></ul>'
        ));
    }

    #[Test]
    public function arabic_and_curly_quotes_survive_the_parser(): void
    {
        $this->assertSame('<p>السلام عليكم — “welcome”</p>', RichText::sanitize('<p>السلام عليكم — “welcome”</p>'));
    }

    #[Test]
    public function headings_labels_and_descriptions_are_printed_as_text_never_markup(): void
    {
        $rows = (new NewsletterRenderer())->html([
            ['type' => 'heading', 'text' => '<script>alert(1)</script>Eid', 'align' => 'center" onclick="alert(1)'],
            ['type' => 'button', 'label' => 'Broken <i>address</i>', 'url' => 'https://example.test/"><script>'],
            ['type' => 'button', 'label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['type' => 'button', 'label' => 'NoHostButton', 'url' => 'https:/relative'],
            ['type' => 'button', 'label' => '<img src=x onerror=alert(1)>Go', 'url' => 'https://example.test/go'],
            ['type' => 'image', 'image' => 'a', 'alt' => '"><script>alert(1)</script>', 'src' => 'https://cdn.example.test/a.png', 'link' => 'javascript:alert(1)'],
        ]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Eid</h2>', $rows);
        $this->assertStringContainsString('text-align:left', $rows, 'An unknown alignment falls back to left.');
        $this->assertStringNotContainsString('<script', $rows);
        $this->assertStringNotContainsString('onclick', $rows);
        $this->assertStringNotContainsString('javascript:', $rows);
        $this->assertStringNotContainsString('<img src=x', $rows);
        // The button whose address contains a quote and a tag is not a web
        // address at all, nor is one with a web scheme and no host.
        $this->assertStringNotContainsString('Broken', $rows);
        $this->assertStringNotContainsString('NoHostButton', $rows);
        $this->assertNull(NewsletterBlocks::webUrl('https:/relative'));
        $this->assertNull(NewsletterBlocks::webUrl('http:foo'));
        // A valid button's label is printed as text.
        $this->assertStringContainsString('>&lt;img src=x onerror=alert(1)&gt;Go</a>', $rows);
        $this->assertStringContainsString('alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $rows);
        // A picture keeps its place; its dangerous link is simply not a link.
        $this->assertSame(1, substr_count($rows, '<img '));
        $this->assertStringNotContainsString('<a href="javascript', $rows);
    }

    #[Test]
    public function the_request_refuses_links_that_are_not_web_addresses(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->send([
            ['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)'],
            ['type' => 'image', 'image' => 'pic', 'alt' => 'A picture', 'link' => 'data:text/html,hi'],
        ], ['pic' => UploadedFile::fake()->image('pic.png')])->assertStatus(422);

        $this->assertSame(
            'Block 1 (button): enter the full web address it opens, starting https://.',
            $response->json('data')['blocks.0.url'][0] ?? null,
        );
        $this->assertSame(
            'Block 2 (image): the link must be a full web address, starting https://.',
            $response->json('data')['blocks.1.link'][0] ?? null,
        );
        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_svg_upload_is_refused_as_a_newsletter_picture(): void
    {
        Sanctum::actingAs($this->admin);

        $svg = UploadedFile::fake()->createWithContent('pic.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');

        $this->send([['type' => 'image', 'image' => 'pic', 'alt' => 'A picture']], ['pic' => $svg])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function hostile_text_sent_through_the_composer_is_stored_clean_and_mailed_clean(): void
    {
        Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Example',
            'email' => 'reader@family.test',
        ]);
        Sanctum::actingAs($this->admin);

        $this->send([
            ['type' => 'heading', 'text' => '<style>*{display:none}</style>Notice'],
            ['type' => 'text', 'html' => '<p onclick="alert(1)">Read <a href="javascript:alert(1)">this</a><script>alert(1)</script></p>'],
            // Double-encoded: the reader must see the literal "&lt;script&gt;".
            ['type' => 'text', 'html' => '<p>&amp;lt;script&amp;gt; 1 &lt; 2 &amp; 3</p>'],
        ])->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->sole();
        $this->assertSame('<p>Read this</p>', $broadcast->blocks[1]['html'], 'The stored text is the sanitised text.');
        $this->assertSame('<p>&amp;lt;script&amp;gt; 1 &lt; 2 &amp; 3</p>', $broadcast->blocks[2]['html']);

        Mail::assertQueued(BroadcastMail::class, function (BroadcastMail $mail) {
            $html = $this->bodyOf($mail->render());
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $html, $needle);
            }
            $this->assertStringContainsString('&lt;style&gt;*{display:none}&lt;/style&gt;Notice', $html);
            $this->assertStringContainsString('&amp;lt;script&amp;gt; 1 &lt; 2 &amp; 3</p>', $html);

            return true;
        });
    }

    #[Test]
    public function a_stored_row_edited_outside_the_app_is_still_rendered_clean(): void
    {
        // The request is not the only way into the column: a console session,
        // a bad import or a restored backup can write it directly.
        $broadcast = Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Hand-edited',
            'body' => 'Body.',
            'blocks' => [
                ['type' => 'text', 'html' => '<p>Hi<script>alert(1)</script><img src="https://tracker.test/p.gif"></p>'],
                ['type' => 'button', 'label' => 'X', 'url' => 'javascript:alert(1)'],
                // A `src` typed into the row is not an upload: only the
                // broadcast's own media can address a picture.
                ['type' => 'image', 'image' => 'nope', 'alt' => 'Not uploaded', 'src' => 'https://tracker.test/p.gif'],
                ['type' => 'raw_html', 'html' => '<script>alert(1)</script>'],
            ],
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_PENDING,
        ]);

        $html = $this->bodyOf((new BroadcastMail(orgName: 'Masjid An-Nur', title: 'Hand-edited', body: 'Body.', blocks: $broadcast->newsletterBlocks()))->render());

        foreach (self::FORBIDDEN as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $html, $needle);
        }
        $this->assertStringContainsString('>Hi</p>', $html);
        $this->assertStringNotContainsString('Not uploaded', $html, 'A picture with no stored file is left out.');

        $this->assertNull(NewsletterBlocks::withImageUrls([['type' => 'image', 'image' => 'k', 'src' => 'https://x.test/a.png']], [])[0]['src']);
    }

    #[Test]
    public function a_text_block_longer_than_the_parser_reads_is_refused_not_cut(): void
    {
        // RichText::parse stops at MAX_LENGTH characters, so a longer block
        // would lose its end without a word; the request says so instead.
        $this->assertSame(
            ['blocks.0.html' => 'Block 1 (text) is too long; split it into two text blocks.'],
            NewsletterBlocks::errors([['type' => 'text', 'html' => '<p>' . str_repeat('a', RichText::MAX_LENGTH) . '</p>']], null),
        );
    }

    #[Test]
    public function a_layout_holds_at_most_forty_blocks(): void
    {
        $this->assertSame([], NewsletterBlocks::errors(array_fill(0, 40, ['type' => 'divider']), null));
        $this->assertSame(
            ['blocks' => 'A newsletter can have at most 40 blocks.'],
            NewsletterBlocks::errors(array_fill(0, 41, ['type' => 'divider']), null),
        );
    }

    #[Test]
    public function the_block_schema_refuses_type_confusion(): void
    {
        $errors = NewsletterBlocks::errors([
            ['type' => 'heading', 'text' => ['<script>']],
            ['type' => 'text', 'html' => ['nested' => 'array']],
            ['type' => 'button', 'label' => 'Go', 'url' => ['https://example.test']],
            ['type' => 'image_row', 'images' => [['image' => 'a', 'alt' => 'x']]],
            ['type' => 'spacer', 'size' => '4000px'],
            'not a block',
        ], null);

        $this->assertSame(
            ['blocks.0.text', 'blocks.1.html', 'blocks.2.url', 'blocks.3.images', 'blocks.4.size', 'blocks.5.type'],
            array_keys($errors),
        );
        $this->assertArrayHasKey('blocks', NewsletterBlocks::errors(['type' => 'heading', 'text' => 'Not a list'], null));
    }

    // -------------------------------------------------------------- helpers

    /** POST the composer the way the SPA does: multipart, the layout as a JSON string. */
    private function send(array $blocks, array $images = [])
    {
        $files = [];
        foreach ($images as $key => $file) {
            $files['block_images'][$key] = $file;
        }

        return $this->call(
            'POST',
            "/api/admin/masjids/{$this->masjid->id}/broadcasts",
            [
                'title' => 'October newsletter',
                'body' => 'Here is what is happening.',
                'channels' => ['email'],
                'audience' => 'everyone',
                'blocks' => json_encode($blocks),
            ],
            [],
            $files,
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /**
     * The mail without its <head>: the frame's own dark-mode <style> lives
     * there, and is ours, so the search for injected markup starts at <body>.
     */
    private function bodyOf(string $html): string
    {
        $this->assertSame(1, substr_count($html, '<style'), 'The frame carries exactly one style block of its own.');

        return (string) preg_replace('#<head\b.*?</head>#is', '', $html);
    }

    /** What a reader sees: tags stripped, entities decoded, whitespace collapsed. */
    private function visible(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
