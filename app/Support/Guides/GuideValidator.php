<?php

namespace App\Support\Guides;

use DOMDocument;
use DOMElement;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** Validates the complete private release before it can become readable. */
class GuideValidator
{
    // Over twice the largest reference text (~128k English characters), bounded in bytes.
    public const ASK_MAX_BYTES = 262144;
    public const BOOKS = ['admin', 'school', 'teacher', 'lunch'];
    public const VERSION = '/^d[0-9]+-[0-9a-f]{8}$/D';

    public static function safePath(mixed $path): bool
    {
        return is_string($path) && preg_match('~^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$~D', $path)
            && ! str_contains($path, '..') && ! str_contains($path, '//');
    }

    private function identifier(mixed $value): bool
    {
        // Task/chapter identifiers are data, never filesystem paths or CSS selectors.
        return is_string($value) && trim($value) !== '' && ! preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    public function validate(string $root): array
    {
        if (is_link($root)) $this->fail('symlink', 'release');
        if (! is_dir($root)) $this->fail('missing', 'release');
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $name = substr($entry->getPathname(), strlen($root) + 1);
            if ($entry->isLink()) $this->fail('symlink', $name);
            if (! self::safePath($name)) $this->fail('path', $name);
            if ($entry->isDir()) continue;
            if (! $entry->isFile()) $this->fail('file-type', $name);
            if ($name !== 'manifest.json' && ! in_array(pathinfo($name, PATHINFO_EXTENSION), ['html', 'css', 'jpg', 'png', 'webp', 'txt'], true)) $this->fail('extension', $name);
            $actual[] = $name;
        }
        if (! in_array('manifest.json', $actual, true)) $this->fail('missing', 'manifest.json');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true);
        if (! is_array($manifest) || ! is_string($manifest['version'] ?? null) || ! preg_match(self::VERSION, $manifest['version'])) $this->fail('manifest-version', 'manifest.json');
        foreach (['draft', 'follows', 'built_at'] as $key) {
            if (! array_key_exists($key, $manifest) || (! is_string($manifest[$key]) && ! is_int($manifest[$key]))) $this->fail('manifest-'.$key, 'manifest.json');
        }
        if (! is_array($manifest['books'] ?? null)) $this->fail('manifest-books', 'manifest.json');
        $keys = array_keys($manifest['books']);
        sort($keys);
        $books = self::BOOKS;
        sort($books);
        if ($keys !== $books) $this->fail('manifest-books', 'manifest.json');
        $expected = ['manifest.json'];
        foreach (self::BOOKS as $book) {
            $meta = $manifest['books'][$book];
            if (! is_array($meta) || ! is_string($meta['title'] ?? null) || trim($meta['title']) === '') $this->fail('manifest-title', 'manifest.json');
            foreach (['page' => 'page.html', 'style' => 'page.css'] as $key => $fixed) {
                if (! self::safePath($meta[$key] ?? null)) $this->fail('path', 'manifest.json');
                $relative = $book.'/'.$fixed;
                // The manifest names each file from the release root.
                if ($meta[$key] !== $relative) $this->fail('manifest-'.$key, 'manifest.json');
                $expected[] = $relative;
                $check = ['sha256' => $meta[$key.'_sha256'] ?? null];
                if (array_key_exists($key.'_bytes', $meta)) {
                    if (! is_int($meta[$key.'_bytes']) || $meta[$key.'_bytes'] <= 0) $this->fail('manifest-bytes', $relative);
                    $check['bytes'] = $meta[$key.'_bytes'];
                }
                $this->checkFile($root, $relative, $check);
            }
            if (! is_array($meta['files'] ?? null)) $this->fail('manifest-files', 'manifest.json');
            foreach ($meta['files'] as $path => $file) {
                if (! self::safePath($path) || ! preg_match('~^shots/[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+\.(jpg|png|webp)$~D', $path)) $this->fail('path', 'manifest.json');
                $relative = $book.'/'.$path;
                $expected[] = $relative;
                if (! is_array($file) || ! is_int($file['bytes'] ?? null) || $file['bytes'] <= 0) $this->fail('manifest-bytes', $relative);
                $this->checkFile($root, $relative, $file);
                $image = @getimagesize($root.'/'.$relative);
                $mime = match (pathinfo($path, PATHINFO_EXTENSION)) { 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' };
                if (! $image || ($image['mime'] ?? '') !== $mime) $this->fail('image-type', $relative);
                // Header sniffing alone accepts a truncated/corrupt image.
                $decoded = @imagecreatefromstring(file_get_contents($root.'/'.$relative));
                if ($decoded === false) $this->fail('image-decode', $relative);
                imagedestroy($decoded);
            }
            $this->html(file_get_contents($root.'/'.$book.'/page.html'), $book, $meta);
            $this->css(file_get_contents($root.'/'.$book.'/page.css'), $book.'/page.css');
            if (array_key_exists('ask', $meta)) {
                $relative = $book.'/ask.txt';
                if ($meta['ask'] !== $relative) $this->fail('manifest-ask', 'manifest.json');
                if (! is_int($meta['ask_bytes'] ?? null) || $meta['ask_bytes'] <= 0 || $meta['ask_bytes'] > self::ASK_MAX_BYTES) $this->fail('ask-bytes', $relative);
                $expected[] = $relative;
                $this->checkFile($root, $relative, ['bytes' => $meta['ask_bytes'], 'sha256' => $meta['ask_sha256'] ?? null]);
                $this->ask(file_get_contents($root.'/'.$relative), file_get_contents($root.'/'.$book.'/page.html'), $meta, $relative);
            } elseif (array_key_exists('ask_sha256', $meta) || array_key_exists('ask_bytes', $meta)) {
                $this->fail('manifest-ask', 'manifest.json');
            }
        }
        foreach (array_diff($actual, $expected) as $path) $this->fail('unlisted', $path);
        return $manifest;
    }

    private function ask(string $text, string $html, array $meta, string $file): void
    {
        if (! mb_check_encoding($text, 'UTF-8') || preg_match('/(?![\n\t])\p{Cc}/u', $text)) $this->fail('ask-text', $file);
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        $faqs = [];
        foreach ($dom->getElementsByTagName('details') as $el) {
            if ($el->hasAttribute('data-faq')) $faqs[] = $el->getAttribute('id');
        }
        $tasks = $questions = [];
        foreach (explode("\n", $text) as $line) {
            if (! str_starts_with($line, '###')) continue;
            if (! preg_match('/^### (Task|Common question) \[(.+)\]: (.+)$/uD', $line, $match)) $this->fail('ask-heading', $file);
            if ($match[1] === 'Task') {
                if (in_array($match[2], $tasks, true) || ! in_array($match[2], array_column($meta['tasks'], 'id'), true)) $this->fail('ask-task', $file);
                $tasks[] = $match[2];
            } else {
                if (in_array($match[2], $questions, true) || ! in_array($match[2], $faqs, true)) $this->fail('ask-faq', $file);
                $questions[] = $match[2];
            }
        }
        if (array_diff(array_column($meta['tasks'], 'id'), $tasks)) $this->fail('ask-task-missing', $file);
    }

    private function checkFile(string $root, string $file, array $meta): void
    {
        if (! is_file($root.'/'.$file)) $this->fail('missing', $file);
        if (isset($meta['bytes']) && filesize($root.'/'.$file) !== $meta['bytes']) $this->fail('bytes', $file);
        if (! is_string($meta['sha256'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $meta['sha256']) || ! hash_equals($meta['sha256'], hash_file('sha256', $root.'/'.$file))) $this->fail('sha256', $file);
    }

    private function html(string $html, string $book, array $meta): void
    {
        $file = $book.'/page.html';
        if (! mb_check_encoding($html, 'UTF-8') || preg_match('~<\s*(script|style|iframe|object|embed|link|base|form|meta)\b~i', $html)) $this->fail('html-forbidden-tag', $file);
        if (preg_match('/javascript\s*:/i', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) $this->fail('html-javascript', $file);
        if (preg_match('~<\s*(html|head|body|title)\b~i', $html)) $this->fail('html-forbidden-tag', $file);
        if (str_contains($html, '<?') || str_contains($html, '<!')) $this->fail('html-declaration', $file);
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $roots = [];
        foreach ($body->childNodes as $node) {
            if ($node instanceof DOMElement) $roots[] = $node;
            elseif (trim($node->textContent) !== '') $this->fail('html-root', $file);
        }
        if (count($roots) !== 1 || $roots[0]->tagName !== 'div' || $roots[0]->getAttribute('data-book') !== $book || ! in_array('mg', preg_split('/\s+/', $roots[0]->getAttribute('class')), true)) $this->fail('html-root', $file);
        $tags = ['div', 'span', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'strong', 'b', 'em', 'i', 'small', 'mark', 'code', 'pre', 'kbd', 'samp', 'blockquote', 'figure', 'figcaption', 'details', 'summary', 'a', 'img', 'sup', 'sub', 'time', 'abbr'];
        $attrs = ['class', 'id', 'title', 'lang', 'dir', 'role', 'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden', 'data-book', 'data-task', 'data-chapter', 'data-faq', 'data-guide', 'data-guide-hint', 'data-words', 'data-src', 'width', 'height', 'alt', 'href', 'rel', 'target', 'colspan', 'rowspan', 'scope', 'open', 'start', 'datetime'];
        $tasks = $chapters = $questions = $references = [];
        foreach ($roots[0]->getElementsByTagName('*') as $el) {
            if (! in_array($el->tagName, $tags, true)) $this->fail('html-forbidden-tag', $file);
            foreach ($el->attributes as $attr) {
                if (str_starts_with(strtolower($attr->name), 'on')) $this->fail('html-event-attribute', $file);
                if (! in_array($attr->name, $attrs, true)) $this->fail('html-attribute', $file);
                if (in_array($attr->name, ['aria-labelledby', 'aria-describedby'], true)) $references[] = $attr->value;
                if (preg_match('/javascript\s*:/i', $attr->value)) $this->fail('html-javascript', $file);
                if ($attr->name === 'href' && ($el->tagName !== 'a' || ! preg_match('~^https://[^\s]+$~D', $attr->value) || ! filter_var($attr->value, FILTER_VALIDATE_URL))) $this->fail('html-href', $file);
                if (in_array($attr->name, ['data-src', 'data-guide', 'data-guide-hint'], true) && $el->tagName !== match ($attr->name) { 'data-src' => 'img', 'data-guide' => 'a', default => 'span' }) $this->fail('html-markup', $file);
            }
            if ($el->hasAttribute('href')) {
                $rel = preg_split('/\s+/', strtolower($el->getAttribute('rel')));
                if (! in_array('noopener', $rel, true) || ! in_array('noreferrer', $rel, true)) $this->fail('html-rel', $file);
            }
            if ($el->tagName === 'img') {
                if (! isset($meta['files'][$el->getAttribute('data-src')])) $this->fail('html-picture-manifest', $file);
                if (! $el->hasAttribute('alt') || ! preg_match('/^[1-9][0-9]*$/D', $el->getAttribute('width')) || ! preg_match('/^[1-9][0-9]*$/D', $el->getAttribute('height'))) $this->fail('html-picture-dimensions', $file);
            }
            foreach (['data-guide', 'data-guide-hint'] as $key) {
                if (! $el->hasAttribute($key)) continue;
                if (! in_array($el->getAttribute($key), self::BOOKS, true) || $el->hasAttribute('href')) $this->fail('html-guide-link', $file);
                // A link names one task, one common question, or (with neither) the whole guide.
                if ($el->hasAttribute('data-task') && $el->hasAttribute('data-faq')) $this->fail('html-guide-link', $file);
                foreach (['data-task', 'data-faq'] as $target) {
                    if ($el->hasAttribute($target) && ! $this->identifier($el->getAttribute($target))) $this->fail('html-guide-link', $file);
                }
            }
            if ($el->hasAttribute('data-faq') && $el->tagName !== 'details' && ! $el->hasAttribute('data-guide') && ! $el->hasAttribute('data-guide-hint')) $this->fail('html-faq', $file);
            if ($el->hasAttribute('id')) {
                $id = $el->getAttribute('id');
                if ($el->tagName !== 'details' || ! $el->hasAttribute('data-faq') || ! preg_match('/^faq-[a-z0-9-]+$/D', $id) || isset($questions[$id])) $this->fail('html-id', $file);
                $questions[$id] = true;
            }
            if ($el->hasAttribute('data-words') && ! (($el->tagName === 'section' && $el->hasAttribute('data-task')) || ($el->tagName === 'details' && $el->hasAttribute('data-faq')))) $this->fail('html-markup', $file);
            foreach (['data-chapter', 'data-task'] as $key) {
                // Cross-guide links use data-task as their destination, not a section.
                if (! $el->hasAttribute($key) || ($key === 'data-task' && ($el->hasAttribute('data-guide') || $el->hasAttribute('data-guide-hint')))) continue;
                $id = $el->getAttribute($key);
                if ($el->tagName !== 'section' || ! $this->identifier($id)) $this->fail('html-section', $file);
                if ($key === 'data-task') {
                    if (isset($tasks[$id])) $this->fail('html-duplicate-task', $file);
                    $parent = $el->parentNode;
                    while ($parent instanceof DOMElement && ! $parent->hasAttribute('data-chapter')) $parent = $parent->parentNode;
                    $tasks[$id] = $parent instanceof DOMElement ? $parent->getAttribute('data-chapter') : null;
                } else {
                    if (isset($chapters[$id])) $this->fail('html-duplicate-chapter', $file);
                    $chapters[$id] = true;
                }
            }
        }
        if (! is_array($meta['tasks'] ?? null) || ! array_is_list($meta['tasks'])) $this->fail('manifest-tasks', 'manifest.json');
        $listed = [];
        foreach ($meta['tasks'] as $task) {
            if (! is_array($task) || ! is_string($task['id'] ?? null) || isset($listed[$task['id']]) || ! is_string($task['title'] ?? null) || trim($task['title']) === '' || ! is_string($task['section'] ?? null) || trim($task['section']) === '' || ! array_key_exists($task['id'], $tasks) || $tasks[$task['id']] === null) $this->fail('html-task-manifest', $file);
            $listed[$task['id']] = true;
        }
        if (count($listed) !== count($tasks) || array_diff_key($listed, $tasks) !== []) $this->fail('html-task-manifest', $file);
        // The root's attributes also pass the allowlist (it is not included above).
        foreach ($roots[0]->attributes as $attr) {
            if (! in_array($attr->name, ['class', 'data-book', 'lang', 'dir', 'aria-label', 'role'], true)) $this->fail('html-root-attribute', $file);
        }
        foreach ($references as $reference) {
            $ids = preg_split('/\s+/', trim($reference));
            foreach ($ids as $id) if (! isset($questions[$id])) $this->fail('html-aria-reference', $file);
        }
    }

    private function css(string $css, string $file): void
    {
        if (! mb_check_encoding($css, 'UTF-8') || preg_match('~@import|url\s*\(|image(?:-set)?\s*\(|expression\s*\(|</style|\\\\|[\x00-\x08\x0b\x0c\x0e-\x1f]~i', $css)) $this->fail('css-forbidden', $file);
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        if (preg_match('~@import|url\s*\(|image(?:-set)?\s*\(|expression\s*\(~i', $css)) $this->fail('css-forbidden', $file);
        if (str_contains($css, '/*')) $this->fail('css-syntax', $file);
        $this->cssBlocks($css, $file);
    }

    /** Only the responsive width wrapper used by the release; no other at-rules. */
    private function cssBlocks(string $css, string $file): void
    {
        while (trim($css) !== '') {
            $open = strpos($css, '{');
            if ($open === false) $this->fail('css-syntax', $file);
            $selector = trim(substr($css, 0, $open));
            $depth = 1;
            $quote = null;
            for ($i = $open + 1, $length = strlen($css); $i < $length && $depth > 0; $i++) {
                $c = $css[$i];
                if ($quote !== null) { if ($c === $quote) $quote = null; continue; }
                if ($c === '"' || $c === "'") { $quote = $c; continue; }
                if ($c === '{') $depth++;
                if ($c === '}') $depth--;
            }
            if ($depth !== 0 || $quote !== null) $this->fail('css-syntax', $file);
            $body = substr($css, $open + 1, $i - $open - 2);
            $css = substr($css, $i);
            if (str_starts_with($selector, '@')) {
                if (! preg_match('/^@media\s+\(\s*(?:min|max)-width\s*:\s*[0-9]+(?:\.[0-9]+)?px\s*\)$/iD', $selector)) $this->fail('css-at-rule', $file);
                $this->cssBlocks($body, $file);
            } else {
                $this->scopedSelectors($this->cssTokens($selector, [' ', ',', '>'], $file, true), $file);
                $this->declarations($body, $file);
            }
        }
    }

    /** One lexer handles delimiters and scope: quoted punctuation is never grouping. */
    private function cssTokens(string $text, array $delimiters, string $file, bool $selector = false): array
    {
        $tokens = $stack = [];
        $quote = null;
        $start = 0;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $c = $text[$i];
            if ($quote !== null) {
                if ($selector && str_contains('(){},', $c)) $this->fail('css-selector', $file);
                if ($c === $quote) $quote = null;
                continue;
            }
            if ($c === '"' || $c === "'") {
                if ($selector && end($stack) !== ']') $this->fail('css-selector', $file);
                $quote = $c;
                continue;
            }
            if ($c === '(' || $c === '[') { $stack[] = $c === '(' ? ')' : ']'; continue; }
            if ($c === ')' || $c === ']') {
                if (array_pop($stack) !== $c) $this->fail('css-syntax', $file);
                continue;
            }
            $delimiter = ctype_space($c) ? ' ' : $c;
            if ($stack === [] && in_array($delimiter, $delimiters, true)) {
                $token = trim(substr($text, $start, $i - $start));
                if ($token !== '') $tokens[] = $token;
                $tokens[] = $delimiter;
                $start = $i + 1;
            }
        }
        if ($quote !== null || $stack !== []) $this->fail('css-syntax', $file);
        $token = trim(substr($text, $start));
        if ($token !== '') $tokens[] = $token;
        return $tokens;
    }

    private function scopedSelectors(array $tokens, string $file): void
    {
        $first = true;
        $compound = false;
        foreach ($tokens as $token) {
            if ($token === ' ') continue;
            if ($token === ',' || $token === '>') {
                if (! $compound) $this->fail('css-syntax', $file);
                if ($token === ',') $first = true;
                $compound = false;
                continue;
            }
            if ($first && ! preg_match('/^\.mg(?=$|[.\[:])/', $token)) $this->fail('css-scope', $file);
            $this->selectorCompound($token, $file);
            $first = false;
            $compound = true;
        }
        if (! $compound) $this->fail('css-syntax', $file);
    }

    /** Classes, types, attributes and simple pseudos only; no selector functions or ids. */
    private function selectorCompound(string $token, string $file): void
    {
        if (preg_match('/^(?:[a-zA-Z][a-zA-Z0-9-]*|\*)/', $token, $match)) {
            if (in_array(strtolower($match[0]), ['html', 'body'], true)) $this->fail('css-scope', $file);
            $token = substr($token, strlen($match[0]));
        }
        while ($token !== '') {
            if (preg_match('/^\.[a-zA-Z_][a-zA-Z0-9_-]*/', $token, $match)
                || preg_match('/^\[[a-zA-Z_][a-zA-Z0-9_-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[a-zA-Z0-9_-]+))?\]/', $token, $match)
                || preg_match('/^:(?:focus-visible|focus-within|hover|focus|active|first-child|last-child|only-child|empty|checked|disabled|enabled)(?![a-zA-Z0-9_-])/', $token, $match)) {
                $token = substr($token, strlen($match[0]));
            } elseif (in_array($token, ['::before', '::after'], true)) {
                return;
            } else $this->fail('css-selector', $file);
        }
    }

    private function declarations(string $body, string $file): void
    {
        if (str_contains($body, '{') || str_contains($body, '}') || str_contains($body, '@')) $this->fail('css-syntax', $file);
        foreach ($this->cssTokens($body, [';'], $file) as $declaration) {
            if ($declaration === ';') continue;
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2 || ! preg_match('/^(?:--[a-zA-Z_][a-zA-Z0-9_-]*|-?[a-zA-Z][a-zA-Z0-9-]*)$/D', trim($parts[0])) || trim($parts[1]) === '') $this->fail('css-declaration', $file);
            // The real release uses no positioning. Refuse it entirely, including var indirection.
            if (strtolower(trim($parts[0])) === 'position') $this->fail('css-position', $file);
            $value = $parts[1];
            $quote = null;
            for ($i = 0, $length = strlen($value); $i < $length; $i++) {
                $c = $value[$i];
                if ($c === '(') {
                    if ($quote !== null || ! preg_match('/([a-zA-Z_-][a-zA-Z0-9_-]*)$/', substr($value, 0, $i), $function) || ! in_array(strtolower($function[1]), ['var', 'counter'], true)) $this->fail('css-function', $file);
                }
                if ($quote !== null) { if ($c === $quote) $quote = null; }
                elseif ($c === '"' || $c === "'") $quote = $c;
            }
        }
    }

    private function fail(string $rule, string $file): never
    {
        throw new GuideValidationException($rule, $file);
    }
}
