<?php

namespace Tests\Feature;

use App\Support\Guides\GuideValidator;
use App\Support\Guides\GuideValidationException;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuideAskReleaseTest extends TestCase
{
    private string $source;
    protected function setUp(): void
    {
        parent::setUp();
        $this->source = sys_get_temp_dir().'/ask-release-'.bin2hex(random_bytes(6));
        File::copyDirectory(base_path('tests/fixtures/guides-ask/d1-1234abcd'), $this->source);
    }
    protected function tearDown(): void { File::deleteDirectory($this->source); parent::tearDown(); }

    #[Test]
    public function optional_ask_and_old_release_are_both_valid(): void
    {
        $this->assertSame('admin/ask.txt', app(GuideValidator::class)->validate($this->source)['books']['admin']['ask']);
        $this->assertArrayNotHasKey('ask', app(GuideValidator::class)->validate(base_path('tests/fixtures/guides/d1-1234abcd'))['books']['admin']);
    }

    public static function invalid(): array
    {
        return array_map(fn ($rule) => [$rule], ['path', 'unlisted', 'missing', 'hash', 'bytes', 'no-hash', 'no-bytes', 'orphan-meta', 'bytes-type', 'size', 'utf8', 'nul', 'cr', 'del', 'c1', 'extra-task', 'missing-task', 'duplicate-task', 'extra-faq', 'duplicate-faq', 'fake-heading']);
    }

    #[Test, DataProvider('invalid')]
    public function invalid_ask_is_refused_without_content_in_error(string $rule): void
    {
        $file = $this->source.'/admin/ask.txt'; $text = file_get_contents($file);
        $m = json_decode(file_get_contents($this->source.'/manifest.json'), true); $meta = &$m['books']['admin'];
        switch ($rule) {
            case 'path': $meta['ask'] = 'school/ask.txt'; break;
            case 'unlisted': file_put_contents($this->source.'/admin/other.txt', 'private phrase'); break;
            case 'missing': unlink($file); break;
            case 'hash': $meta['ask_sha256'] = str_repeat('0', 64); break;
            case 'bytes': $meta['ask_bytes']++; break;
            case 'no-hash': unset($meta['ask_sha256']); break;
            case 'no-bytes': unset($meta['ask_bytes']); break;
            case 'orphan-meta': unset($meta['ask']); unlink($file); break;
            case 'bytes-type': $meta['ask_bytes'] = (string) $meta['ask_bytes']; break;
            case 'size': $text .= str_repeat('x', 262144); break;
            case 'utf8': $text .= "\xff"; break;
            case 'nul': $text .= "\0"; break;
            case 'cr': $text .= "\r"; break;
            case 'del': $text .= "\x7f"; break;
            case 'c1': $text .= "\u{0085}"; break;
            case 'extra-task': $text .= "\n### Task [invented]: Invented\nprivate phrase"; break;
            case 'missing-task': $text = str_replace('### Task [sprout]:', 'Task:', $text); break;
            case 'duplicate-task': $text .= "\n### Task [sprout]: Admin Sprout task\nprivate phrase"; break;
            case 'extra-faq': $text .= "\n### Common question [faq-invented]: Why?\nprivate phrase"; break;
            case 'duplicate-faq': $text .= "\n### Common question [faq-pebble]: Why?\nprivate phrase"; break;
            case 'fake-heading': $text .= "\n### Task invented\nprivate phrase"; break;
        }
        if (in_array($rule, ['size', 'utf8', 'nul', 'cr', 'del', 'c1', 'extra-task', 'missing-task', 'duplicate-task', 'extra-faq', 'duplicate-faq', 'fake-heading'])) {
            file_put_contents($file, $text); $meta['ask_bytes'] = strlen($text); $meta['ask_sha256'] = hash('sha256', $text);
        }
        file_put_contents($this->source.'/manifest.json', json_encode($m));
        try { app(GuideValidator::class)->validate($this->source); $this->fail('Accepted '.$rule); }
        catch (GuideValidationException $e) { $this->assertStringNotContainsString('private phrase', $e->getMessage()); }
    }

    #[Test]
    public function real_release_ask_text_generated_with_its_own_tool_passes(): void
    {
        $release = getenv('GUIDE_ASK_REAL_RELEASE');
        if (! $release) { $this->markTestSkipped('Set GUIDE_ASK_REAL_RELEASE to the external release; no real content enters the repo.'); }
        File::deleteDirectory($this->source); File::copyDirectory($release, $this->source);
        $m = json_decode(file_get_contents($this->source.'/manifest.json'), true);
        foreach (GuideValidator::BOOKS as $book) {
            $proc = proc_open(['node', '/Users/moneebsayed/Developer/manara-admin-guide/tools/ask-text.mjs', $this->source, $book], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $text = stream_get_contents($pipes[1]); $stats = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $stats);
            file_put_contents($this->source.'/'.$book.'/ask.txt', $text);
            $m['books'][$book]['ask'] = $book.'/ask.txt'; $m['books'][$book]['ask_bytes'] = strlen($text); $m['books'][$book]['ask_sha256'] = hash('sha256', $text);
        }
        file_put_contents($this->source.'/manifest.json', json_encode($m));
        $this->assertSame($m, app(GuideValidator::class)->validate($this->source));
    }
}
