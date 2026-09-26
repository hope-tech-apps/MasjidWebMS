<?php

namespace App\Services\Broadcast\Newsletter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The rich-text block of a newsletter: a deliberately tiny subset of HTML.
 *
 * ## Why an allowlist parser and not a cleaned-up copy of the input
 *
 * What an admin types arrives as HTML from a contenteditable box, and what they
 * paste arrives from Word, Google Docs and other people's emails. Any of it can
 * carry `<script>`, `<style>`, `on*` handlers, `javascript:` links, tracking
 * pixels and CSS that repaints the whole message. Removing the bad parts of that
 * input is a blocklist, and a blocklist loses to the first construct nobody
 * thought of. So the input is PARSED into a small model — paragraphs and lists
 * of text runs that may be bold, italic, underlined or a link — and the output
 * is written fresh from that model. Nothing from the input survives except text
 * and the one attribute this format has: a link's address, and only when it is
 * http, https or mailto. There is no path by which an input attribute, style or
 * element reaches the output, because the output is never a copy of the input.
 *
 * The same model yields the three things the newsletter needs: the canonical
 * HTML stored on the broadcast, the email HTML with inline styles (email clients
 * strip `<style>` blocks, so every element carries its own), and the
 * plain-text alternative. Rendering re-parses the stored HTML rather than
 * trusting it, so a row edited directly in the database is held to the same
 * rules as a request.
 *
 * Images are dropped: a newsletter image is an image BLOCK, uploaded to the
 * organisation's own media with alt text required. An `<img>` in pasted text
 * would be a hotlink to somebody else's server — a tracking pixel in every
 * congregant's inbox — with no alt text at all.
 */
final class RichText
{
    /** Raw input ceiling, in characters, before parsing. */
    public const MAX_LENGTH = 20000;

    /** Longest link address kept; anything longer is unwrapped to its text. */
    public const MAX_HREF_LENGTH = 2048;

    /** Elements removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'noscript', 'template', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base',
        'textarea', 'select', 'option', 'button', 'input', 'form', 'img', 'picture',
        'source', 'video', 'audio', 'canvas', 'map', 'area',
    ];

    /** Elements that start a new paragraph wherever they appear. */
    private const BLOCK_CONTAINERS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'section',
        'article', 'header', 'footer', 'main', 'aside', 'nav', 'center', 'address',
        'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'dl', 'dt', 'dd', 'li', 'hr',
    ];

    /** Inline wrappers kept, mapped to their one canonical spelling. */
    private const MARKS = ['strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em', 'u' => 'u'];

    /** Canonical HTML: what is stored on the broadcast. */
    public static function sanitize(?string $html): string
    {
        return self::emit(self::parse($html), []);
    }

    /**
     * Email HTML: the canonical structure with an inline style on every element.
     *
     * @param  array<string, string>  $styles  keyed p, ul, ol, li, a, strong, em, u
     * @param  array<string, string>  $classes same keys; the dark-mode hooks
     */
    public static function toEmailHtml(?string $html, array $styles, array $classes = []): string
    {
        return self::emit(self::parse($html), $styles, $classes);
    }

    /** The plain-text alternative: paragraphs, "- " bullets, "label (address)" links. */
    public static function toText(?string $html): string
    {
        $out = [];

        foreach (self::parse($html) as $block) {
            if ($block[0] === 'p') {
                $out[] = self::inlineText($block[1]);
                continue;
            }

            $lines = [];
            foreach ($block[1] as $i => $item) {
                $marker = $block[0] === 'ol' ? ($i + 1) . '. ' : '- ';
                $lines[] = $marker . str_replace("\n", "\n  ", self::inlineText($item));
            }
            $out[] = implode("\n", $lines);
        }

        return implode("\n\n", $out);
    }

    /** True when the text holds something a reader would see. */
    public static function hasVisibleText(?string $html): bool
    {
        return self::parse($html) !== [];
    }

    // ---------------------------------------------------------------- parse

    /**
     * The model: a list of blocks, each ['p', inlines] or ['ul'|'ol', list of inlines].
     * An inline is ['text', string] | ['br'] | ['strong'|'em'|'u', inlines] | ['a', href, inlines].
     *
     * @return list<array>
     */
    private static function parse(?string $html): array
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return [];
        }

        // Longer input is cut rather than refused here: validation refuses it at
        // the request, and this function also runs on render, where a stored
        // over-long value must degrade, not throw.
        $html = mb_substr($html, 0, self::MAX_LENGTH);

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration is how libxml's HTML parser is told the input is
        // UTF-8; without it, Arabic and curly quotes are read as Latin-1.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body) {
            return [];
        }

        $blocks = [];
        $open = [];
        self::walkBlocks($body, $blocks, $open);
        self::flush($blocks, $open);

        return $blocks;
    }

    /**
     * @param  list<array>  $blocks
     * @param  list<array>  $open   inlines of the paragraph being gathered
     */
    private static function walkBlocks(DOMNode $parent, array &$blocks, array &$open): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText) {
                $open[] = ['text', $node->data];
                continue;
            }

            if (! $node instanceof DOMElement) {
                continue; // comments, processing instructions
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            if ($tag === 'ul' || $tag === 'ol') {
                self::flush($blocks, $open);
                $items = self::listItems($node);
                if ($items !== []) {
                    $blocks[] = [$tag, $items];
                }
                continue;
            }

            if (in_array($tag, self::BLOCK_CONTAINERS, true)) {
                self::flush($blocks, $open);
                self::walkBlocks($node, $blocks, $open);
                self::flush($blocks, $open);
                continue;
            }

            // An inline element at block level (a <strong> or <a> directly in a
            // paragraph): the element itself is inline content, so it is handed
            // over whole — handing over only its children would drop the link
            // or the emphasis it exists to carry.
            array_push($open, ...self::inlines([$node], null, false));
        }
    }

    /** @return list<array> the items of one list, each a list of inlines */
    private static function listItems(DOMElement $list): array
    {
        $items = [];

        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            // Anything directly inside a list that is not an <li> (stray text,
            // a nested list pasted from Word) becomes an item of its own rather
            // than being lost.
            $inlines = $child instanceof DOMElement && strtolower($child->tagName) === 'li'
                ? self::inlines($child->childNodes, null, false)
                : self::inlines([$child], null, false);

            $inlines = self::trimInlines($inlines);
            if ($inlines !== []) {
                $items[] = $inlines;
            }
        }

        return $items;
    }

    /**
     * Inline content of a node list. `$wrap` is the tag of the element whose
     * children these are, when that element is itself inline.
     *
     * @param  iterable<DOMNode>  $nodes
     * @return list<array>
     */
    private static function inlines(iterable $nodes, ?string $wrap, bool $inLink): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMText) {
                $out[] = ['text', $node->data];
                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            if ($tag === 'br') {
                $out[] = ['br'];
                continue;
            }

            if (isset(self::MARKS[$tag])) {
                $children = self::inlines($node->childNodes, $tag, $inLink);
                if (self::hasText($children)) {
                    $out[] = [self::MARKS[$tag], $children];
                }
                continue;
            }

            if ($tag === 'a') {
                $href = $inLink ? null : self::safeHref($node->getAttribute('href'));
                $children = self::inlines($node->childNodes, $tag, $inLink || $href !== null);
                if ($href !== null && self::hasText($children)) {
                    $out[] = ['a', $href, $children];
                } else {
                    // A link we will not keep still says something; keep the words.
                    array_push($out, ...$children);
                }
                continue;
            }

            if ($tag === 'ul' || $tag === 'ol' || in_array($tag, self::BLOCK_CONTAINERS, true)) {
                // A block inside a list item or inside inline text: a line break
                // keeps its words apart, which is all a plain run can express.
                $out[] = ['br'];
                array_push($out, ...self::inlines($node->childNodes, null, $inLink));
                $out[] = ['br'];
                continue;
            }

            // span, font, abbr, and everything else: the element goes, the words stay.
            array_push($out, ...self::inlines($node->childNodes, $wrap, $inLink));
        }

        return $out;
    }

    /**
     * An address a reader may be sent to, or null.
     *
     * Only three schemes, and nothing relative: a relative link in an email
     * resolves against whatever the mail client decides, and `javascript:`,
     * `data:` and `vbscript:` are the classic ways a link becomes code. The DOM
     * has already decoded entities, so `&#106;avascript:` arrives here as the
     * word it spells.
     */
    public static function safeHref(?string $href): ?string
    {
        $href = trim((string) $href);
        // Control characters and whitespace are how a scheme is hidden from a
        // naive prefix check ("java\tscript:"); an address containing them is
        // not one anybody typed.
        if ($href === '' || mb_strlen($href) > self::MAX_HREF_LENGTH || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $href)) {
            return null;
        }

        if (preg_match('/^mailto:[^@\s]+@[^@\s]+$/i', $href) === 1) {
            return 'mailto:' . substr($href, 7);
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        $host = (string) parse_url($href, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $href;
    }

    /**
     * Close the paragraph being gathered, keeping it only if it has words.
     *
     * @param  list<array>  $blocks
     * @param  list<array>  $open
     */
    private static function flush(array &$blocks, array &$open): void
    {
        $inlines = self::trimInlines($open);
        $open = [];

        if ($inlines !== []) {
            $blocks[] = ['p', $inlines];
        }
    }

    /**
     * Collapse whitespace the way a browser would, then drop leading and
     * trailing breaks and spaces; empty means nothing a reader would see.
     *
     * @param  list<array>  $inlines
     * @return list<array>
     */
    private static function trimInlines(array $inlines): array
    {
        $inlines = self::collapse($inlines);

        while ($inlines !== [] && self::isBlank($inlines[0])) {
            array_shift($inlines);
        }
        while ($inlines !== [] && self::isBlank($inlines[count($inlines) - 1])) {
            array_pop($inlines);
        }

        if ($inlines === [] || ! self::hasText($inlines)) {
            return [];
        }

        // Whitespace at the very edges of the first and last text runs.
        self::trimEdge($inlines, true);
        self::trimEdge($inlines, false);

        // Runs of breaks (an empty line typed as <div><br></div>) become one pair.
        $out = [];
        $breaks = 0;
        foreach ($inlines as $inline) {
            if ($inline[0] === 'br') {
                if (++$breaks > 2) {
                    continue;
                }
            } else {
                $breaks = 0;
            }
            $out[] = $inline;
        }

        return $out;
    }

    /** @param list<array> $inlines */
    private static function collapse(array $inlines): array
    {
        $out = [];

        foreach ($inlines as $inline) {
            if ($inline[0] === 'text') {
                $text = (string) preg_replace('/[ \t\n\r\f]+/u', ' ', $inline[1]);
                // A space after a break is the source's indentation, not a word gap.
                if ($out !== [] && $out[count($out) - 1][0] === 'br') {
                    $text = ltrim($text, ' ');
                }
                if ($text === '') {
                    continue;
                }
                $out[] = ['text', $text];
                continue;
            }

            if ($inline[0] === 'a') {
                $out[] = ['a', $inline[1], self::collapse($inline[2])];
                continue;
            }

            if ($inline[0] === 'br') {
                // Drop the space that sat before a break.
                if ($out !== [] && $out[count($out) - 1][0] === 'text') {
                    $last = rtrim($out[count($out) - 1][1], ' ');
                    if ($last === '') {
                        array_pop($out);
                    } else {
                        $out[count($out) - 1][1] = $last;
                    }
                }
                $out[] = ['br'];
                continue;
            }

            $out[] = [$inline[0], self::collapse($inline[1])];
        }

        return $out;
    }

    private static function isBlank(array $inline): bool
    {
        return $inline[0] === 'br' || ($inline[0] === 'text' && trim($inline[1]) === '');
    }

    /** @param list<array> $inlines */
    private static function trimEdge(array &$inlines, bool $leading): void
    {
        $i = $leading ? 0 : count($inlines) - 1;

        if ($inlines[$i][0] === 'text') {
            $inlines[$i][1] = $leading ? ltrim($inlines[$i][1]) : rtrim($inlines[$i][1]);
        } elseif ($inlines[$i][0] !== 'br') {
            $children = $inlines[$i][0] === 'a' ? $inlines[$i][2] : $inlines[$i][1];
            if ($children !== []) {
                self::trimEdge($children, $leading);
            }
            if ($inlines[$i][0] === 'a') {
                $inlines[$i][2] = $children;
            } else {
                $inlines[$i][1] = $children;
            }
        }
    }

    /** @param list<array> $inlines */
    private static function hasText(array $inlines): bool
    {
        foreach ($inlines as $inline) {
            if ($inline[0] === 'text' && trim($inline[1]) !== '') {
                return true;
            }
            if ($inline[0] === 'a' && self::hasText($inline[2])) {
                return true;
            }
            if (in_array($inline[0], ['strong', 'em', 'u'], true) && self::hasText($inline[1])) {
                return true;
            }
        }

        return false;
    }

    // ----------------------------------------------------------------- emit

    /**
     * @param  list<array>  $blocks
     * @param  array<string, string>  $styles
     * @param  array<string, string>  $classes
     */
    private static function emit(array $blocks, array $styles, array $classes = []): string
    {
        $html = '';

        foreach ($blocks as $block) {
            if ($block[0] === 'p') {
                $html .= self::open('p', $styles, $classes) . self::emitInlines($block[1], $styles, $classes) . '</p>';
                continue;
            }

            $html .= self::open($block[0], $styles, $classes);
            foreach ($block[1] as $item) {
                $html .= self::open('li', $styles, $classes) . self::emitInlines($item, $styles, $classes) . '</li>';
            }
            $html .= '</' . $block[0] . '>';
        }

        return $html;
    }

    /**
     * @param  list<array>  $inlines
     * @param  array<string, string>  $styles
     * @param  array<string, string>  $classes
     */
    private static function emitInlines(array $inlines, array $styles, array $classes): string
    {
        $html = '';

        foreach ($inlines as $inline) {
            switch ($inline[0]) {
                case 'text':
                    $html .= self::escape($inline[1]);
                    break;
                case 'br':
                    $html .= '<br>';
                    break;
                case 'a':
                    $html .= '<a href="' . self::escape($inline[1]) . '"'
                        . self::attributes('a', $styles, $classes) . '>'
                        . self::emitInlines($inline[2], $styles, $classes) . '</a>';
                    break;
                default:
                    $html .= self::open($inline[0], $styles, $classes)
                        . self::emitInlines($inline[1], $styles, $classes)
                        . '</' . $inline[0] . '>';
            }
        }

        return $html;
    }

    /**
     * @param  array<string, string>  $styles
     * @param  array<string, string>  $classes
     */
    private static function open(string $tag, array $styles, array $classes): string
    {
        return '<' . $tag . self::attributes($tag, $styles, $classes) . '>';
    }

    /**
     * The only attributes this format ever writes: our own class and style.
     *
     * @param  array<string, string>  $styles
     * @param  array<string, string>  $classes
     */
    private static function attributes(string $tag, array $styles, array $classes): string
    {
        $attributes = '';

        if (isset($classes[$tag])) {
            $attributes .= ' class="' . self::escape($classes[$tag]) . '"';
        }
        if (isset($styles[$tag])) {
            $attributes .= ' style="' . self::escape($styles[$tag]) . '"';
        }

        return $attributes;
    }

    /** @param list<array> $inlines */
    private static function inlineText(array $inlines): string
    {
        $text = '';

        foreach ($inlines as $inline) {
            switch ($inline[0]) {
                case 'text':
                    $text .= $inline[1];
                    break;
                case 'br':
                    $text .= "\n";
                    break;
                case 'a':
                    $label = self::inlineText($inline[2]);
                    $address = str_starts_with($inline[1], 'mailto:') ? substr($inline[1], 7) : $inline[1];
                    $text .= trim($label) === $address ? $address : $label . ' (' . $address . ')';
                    break;
                default:
                    $text .= self::inlineText($inline[1]);
            }
        }

        return $text;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
