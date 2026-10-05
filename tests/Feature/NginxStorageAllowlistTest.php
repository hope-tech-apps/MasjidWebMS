<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The web server's second lock on /storage, kept in step with the application's first.
 *
 * Every file under /storage is answered from the same origin as the admin screen. The application
 * refuses an upload whose name is not what it claims (`extensions:`, UploadFileNameCoverageTest).
 * The web server is the second lock, for the name nobody thought of: `deploy/nginx/manara-storage.conf`
 * answers a file under /storage inline only when its ending is on a short list of kinds that cannot
 * be a page, and answers everything else as a download.
 *
 * That list is on the SERVERS, so the application cannot know it. This test reads the repository's
 * copy and holds the two together from this side: a kind of file an upload rule lets in must be on
 * the list, or be named below as one that is meant to download or is never under /storage. Without
 * it, a new upload type works in every test here and then downloads instead of opening on a server.
 *
 * What it cannot see: whether the servers carry this file. `deploy/README.md` says how to check.
 */
class NginxStorageAllowlistTest extends TestCase
{
    private const SNIPPET = 'deploy/nginx/manara-storage.conf';

    /**
     * Endings an upload rule lets in that the web server is NOT asked to open inline: a file kept on
     * a private disk and handed out by a signed-in download, or one that is meant to download.
     * ending => why. Empty on 2026-10-05: every pinned ending is a picture, a PDF or an MP4.
     *
     * @var array<string, string>
     */
    private const NOT_OPENED_INLINE = [];

    /** Endings a browser would run or render as a page. None of them may ever be on the list. */
    private const PAGE_LIKE = ['html', 'htm', 'shtml', 'xhtml', 'xht', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'css', 'php', 'phtml', 'phar', 'json', 'txt'];

    #[Test]
    public function every_kind_an_upload_rule_lets_in_is_one_the_web_server_opens_inline(): void
    {
        $inline = $this->inline();
        $missing = [];

        foreach ($this->pinnedEndings() as $ending => $where) {
            if (! in_array($ending, $inline, true) && ! array_key_exists($ending, self::NOT_OPENED_INLINE)) {
                $missing[] = "{$ending} ({$where})";
            }
        }

        $this->assertSame([], $missing, 'An upload rule lets in a kind of file that ' . self::SNIPPET
            . ' does not open inline, so on the servers it would download instead of showing. Add the ending to the list'
            . ' in that file (and on BOTH servers, deploy/README.md), or name it in NOT_OPENED_INLINE with the reason.');
    }

    #[Test]
    public function every_exemption_still_names_a_kind_some_rule_lets_in(): void
    {
        $pinned = $this->pinnedEndings();

        foreach (self::NOT_OPENED_INLINE as $ending => $why) {
            $this->assertArrayHasKey($ending, $pinned, "NOT_OPENED_INLINE names '{$ending}', which no upload rule lets in any more: delete the entry.");
            $this->assertNotSame('', trim($why), "NOT_OPENED_INLINE['{$ending}'] has no reason.");
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function nothing_page_like_is_on_the_list(): void
    {
        $this->assertSame([], array_values(array_intersect($this->inline(), self::PAGE_LIKE)));
    }

    #[Test]
    public function everything_not_on_the_list_is_a_download_and_nothing_under_storage_is_run(): void
    {
        $conf = $this->conf();

        // The block is a prefix location that stops the server's regex locations (the PHP one among
        // them) from applying under /storage.
        $this->assertMatchesRegularExpression('~^location \^\~ /storage/ \{$~m', $conf);
        $this->assertStringNotContainsString('fastcgi', $this->withoutComments($conf));
        // By default: no type from the name, and a download.
        $this->assertMatchesRegularExpression('~^    types \{ \}$~m', $conf);
        $this->assertMatchesRegularExpression('~^    default_type application/octet-stream;$~m', $conf);
        $this->assertMatchesRegularExpression('~^    add_header Content-Disposition "attachment";$~m', $conf);
        // A dot file is refused, as the server block refuses it everywhere else.
        $this->assertMatchesRegularExpression('~^    location \~ /\\\\\. \{ deny all; \}$~m', $conf);
        // An SVG is still an image, and cannot script when it is opened by itself.
        $this->assertMatchesRegularExpression('~location \~\* \\\\\.svgz\?\$ \{[^}]*Content-Security-Policy "sandbox;~s', $conf);
    }

    /**
     * The endings answered inline: the list of the media location, plus svg and svgz.
     *
     * @return list<string>
     */
    private function inline(): array
    {
        $this->assertSame(1, preg_match('~^    location \~\* \\\\\.\(\?:([a-z0-9?|]+)\)\$ \{$~m', $this->conf(), $match), 'the media location of ' . self::SNIPPET . ' is not written as this test reads it');

        $endings = [];
        foreach (explode('|', $match[1]) as $alternative) {
            // `jpe?g` is jpg and jpeg; nothing else in the list uses a pattern.
            if (preg_match('~^(\w*)(\w)\?(\w*)$~', $alternative, $optional)) {
                $endings[] = $optional[1] . $optional[3];
                $endings[] = $optional[1] . $optional[2] . $optional[3];
            } else {
                $this->assertMatchesRegularExpression('~^[a-z0-9]+$~', $alternative, "'{$alternative}' in the list is a pattern this test does not read");
                $endings[] = $alternative;
            }
        }

        return [...$endings, 'svg', 'svgz'];
    }

    /**
     * Every ending an `extensions:` rule under app/ lets in, with one place it is written.
     *
     * @return array<string, string> ending => file
     */
    private function pinnedEndings(): array
    {
        $found = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match_all('~extensions:([a-z0-9,]+)~i', $file->getContents(), $matches)) {
                foreach ($matches[1] as $list) {
                    foreach (array_filter(explode(',', strtolower($list))) as $ending) {
                        $found[$ending] ??= 'app/' . $file->getRelativePathname();
                    }
                }
            }
        }

        $this->assertNotEmpty($found, 'no extensions: rule was found under app/, so this test is reading nothing');
        ksort($found);

        return $found;
    }

    private function conf(): string
    {
        return (string) file_get_contents(base_path(self::SNIPPET));
    }

    private function withoutComments(string $conf): string
    {
        return (string) preg_replace('~^\s*#.*$~m', '', $conf);
    }
}
