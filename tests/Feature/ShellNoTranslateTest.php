<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The app shell tells Chrome not to machine-translate Manara (owner 2026-10-16:
 * "disable chrome auto translate", staff screens and the family portal alike).
 * Chrome's translation garbled children's names, nonsense words for labels and
 * money amounts on a parent's phone; people choose their language inside the app.
 */
class ShellNoTranslateTest extends TestCase
{
    #[Test]
    public function the_app_shell_asks_browsers_not_to_translate_it(): void
    {
        // The shell's @vite tags need a built manifest; this test is about the page's
        // own markup, so Vite is replaced by a stand-in that emits nothing.
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString('');
            }
        });

        $html = view('vue-app-index')->render();

        $this->assertMatchesRegularExpression('/<html[^>]*\stranslate="no"/', $html);
        $this->assertStringContainsString('<meta name="google" content="notranslate">', $html);
    }
}
