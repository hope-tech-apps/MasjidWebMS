<?php

namespace App\Services\Broadcast\Newsletter;

/**
 * Turns a newsletter layout into the rows of an email and into plain text.
 *
 * ## Email-client-safe, which is a narrower thing than browser-safe
 *
 * Mail clients are old rendering engines with their own rules, so every choice
 * here is the conservative one:
 *
 *  - LAYOUT IS TABLES. Outlook for Windows renders with Word, which ignores
 *    flexbox, grid, floats and most `max-width`; nested presentation tables are
 *    the one layout every client honours.
 *  - STYLES ARE INLINE. Gmail strips `<style>` in many contexts, so each element
 *    carries its own. The `nl-*` classes are only HOOKS for the frame's
 *    dark-mode and narrow-screen rules; the email reads correctly without them.
 *  - DARK MODE by explicit colours on every cell. Clients that invert colours
 *    (Outlook.com, Gmail's apps) invert what is declared; clients that honour
 *    `prefers-color-scheme` (Apple Mail) get the frame's dark palette through
 *    the classes. A cell with no declared background is where inversion goes
 *    wrong, so there are none.
 *  - IMAGES are absolute URLs on the application's own disk, sized with the
 *    `width` attribute for Outlook and `max-width:100%` for everyone else, with
 *    `alt` always present. A picture with no resolvable address is left out
 *    rather than sent broken.
 *
 * Everything written here is escaped here. The renderer does not trust the
 * layout it is given — it may be a stored row that was edited by hand — so an
 * unknown block type is skipped, a missing field skips its block, rich text is
 * re-parsed through RichText, and every address goes through the same checks
 * the request applied.
 */
final class NewsletterRenderer
{
    /** The content column inside the 600px card's 24px padding. */
    public const CONTENT_WIDTH = 552;

    /** Each column of a two-image row, with a 16px gutter between. */
    public const COLUMN_WIDTH = 268;

    public const INK = '#1f2933';

    public const ACCENT = '#1f7a41';

    public const RULE = '#d9dee3';

    public const CARD = '#ffffff';

    private const FONT = '-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif';

    /**
     * The table rows for a layout, to sit inside the frame's card table.
     *
     * @param  list<array<string, mixed>>  $blocks  image blocks carry a resolved `src`
     */
    public function html(array $blocks): string
    {
        $rows = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $row = match ($block['type'] ?? null) {
                'heading' => $this->heading($block),
                'text' => $this->textRow($block),
                'image' => $this->image($block),
                'image_row' => $this->imageRow($block),
                'button' => $this->button($block),
                'divider' => $this->divider(),
                'spacer' => $this->spacer($block),
                default => null,
            };

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return implode("\n", $rows);
    }

    /**
     * The same layout as plain text, for the text/plain alternative part.
     *
     * Screen readers, plain-text mail clients and spam filters read this part,
     * so it carries everything the HTML says: headings as their own lines,
     * pictures as their descriptions, buttons and linked pictures with their
     * addresses written out.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function text(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $part = match ($block['type'] ?? null) {
                'heading' => $this->plain($block['text'] ?? null) !== null
                    ? mb_strtoupper(trim($block['text']))
                    : null,
                'text' => ($t = RichText::toText($this->plain($block['html'] ?? null))) !== '' ? $t : null,
                'image' => $this->imageText($block),
                'image_row' => $this->imageRowText($block),
                'button' => $this->buttonText($block),
                'divider' => str_repeat('-', 40),
                default => null,
            };

            if ($part !== null && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n\n", $parts);
    }

    // -------------------------------------------------------------- blocks

    private function heading(array $block): ?string
    {
        $text = $this->plain($block['text'] ?? null);
        if ($text === null || trim($text) === '') {
            return null;
        }

        $align = $this->align($block);

        return $this->row(
            '<h2 class="nl-text" style="margin:0; font-family:' . self::FONT . '; font-size:22px; line-height:1.3; font-weight:700; color:' . self::INK . '; text-align:' . $align . ';">'
            . $this->e(trim($text)) . '</h2>',
            '12px 24px 12px',
            $align,
        );
    }

    private function textRow(array $block): ?string
    {
        $html = RichText::toEmailHtml($this->plain($block['html'] ?? null), [
            'p' => 'margin:0 0 16px; font-family:' . self::FONT . '; font-size:16px; line-height:1.6; color:' . self::INK . ';',
            'ul' => 'margin:0 0 16px; padding:0 0 0 24px; color:' . self::INK . ';',
            'ol' => 'margin:0 0 16px; padding:0 0 0 24px; color:' . self::INK . ';',
            'li' => 'margin:0 0 6px; font-family:' . self::FONT . '; font-size:16px; line-height:1.6; color:' . self::INK . ';',
            'a' => 'color:' . self::ACCENT . '; text-decoration:underline;',
            'strong' => 'font-weight:700;',
            'em' => 'font-style:italic;',
        ], [
            'p' => 'nl-text',
            'ul' => 'nl-text',
            'ol' => 'nl-text',
            'li' => 'nl-text',
            'a' => 'nl-link',
        ]);

        return $html === '' ? null : $this->row($html, '4px 24px 0');
    }

    private function image(array $block): ?string
    {
        $img = $this->img($block, self::CONTENT_WIDTH);

        return $img === null ? null : $this->row($img, '4px 24px 16px');
    }

    private function imageRow(array $block): ?string
    {
        $images = array_values(array_filter(
            array_map(fn ($image) => is_array($image) ? $this->img($image, self::COLUMN_WIDTH) : null, (array) ($block['images'] ?? [])),
        ));

        if ($images === []) {
            return null;
        }

        // One picture of the pair did not resolve: show the one that did at full
        // width rather than half an empty row.
        if (count($images) === 1) {
            $only = array_values(array_filter((array) $block['images'], fn ($i) => is_array($i) && $this->src($i) !== null))[0];

            return $this->image($only);
        }

        // Two columns side by side; `nl-col` stacks them on a narrow screen
        // (the frame's media query), and the first column's bottom padding is
        // the gap between them once stacked.
        return $this->row(
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '<tr>'
            . '<td class="nl-card nl-col nl-col-first" width="' . self::COLUMN_WIDTH . '" valign="top" style="width:' . self::COLUMN_WIDTH . 'px; vertical-align:top; background-color:' . self::CARD . ';">' . $images[0] . '</td>'
            . '<td class="nl-card nl-gap" width="16" style="width:16px; background-color:' . self::CARD . '; font-size:0; line-height:0;">&nbsp;</td>'
            . '<td class="nl-card nl-col" width="' . self::COLUMN_WIDTH . '" valign="top" style="width:' . self::COLUMN_WIDTH . 'px; vertical-align:top; background-color:' . self::CARD . ';">' . $images[1] . '</td>'
            . '</tr>'
            . '</table>',
            '4px 24px 16px',
        );
    }

    private function button(array $block): ?string
    {
        $label = $this->plain($block['label'] ?? null);
        $url = NewsletterBlocks::webUrl($block['url'] ?? null);

        if ($label === null || trim($label) === '' || $url === null) {
            return null;
        }

        $align = $this->align($block);

        // The "bulletproof" button: the colour is on the table cell (with the
        // legacy bgcolor attribute for Outlook), so it survives a client that
        // drops the link's padding or background, and the whole padded area is
        // the link.
        return $this->row(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . $align . '" style="border-collapse:separate;' . ($align === 'center' ? ' margin:0 auto;' : '') . '">'
            . '<tr><td bgcolor="' . self::ACCENT . '" style="background-color:' . self::ACCENT . '; border-radius:8px;">'
            . '<a href="' . $this->e($url) . '" target="_blank" style="display:inline-block; padding:12px 24px; font-family:' . self::FONT . '; font-size:16px; font-weight:600; line-height:1.2; color:#ffffff; text-decoration:none; border-radius:8px;">'
            . $this->e(trim($label))
            . '</a></td></tr></table>',
            '4px 24px 20px',
            $align,
        );
    }

    private function divider(): string
    {
        return $this->row(
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '<tr><td class="nl-card nl-rule" height="1" style="height:1px; border-top:1px solid ' . self::RULE . '; background-color:' . self::CARD . '; font-size:0; line-height:0;">&nbsp;</td></tr>'
            . '</table>',
            '12px 24px 12px',
        );
    }

    private function spacer(array $block): string
    {
        $height = NewsletterBlocks::SPACER_SIZES[$block['size'] ?? 'medium'] ?? NewsletterBlocks::SPACER_SIZES['medium'];

        return '<tr><td class="nl-card" height="' . $height . '" style="height:' . $height . 'px; background-color:' . self::CARD . '; font-size:0; line-height:0;">&nbsp;</td></tr>';
    }

    // ------------------------------------------------------------- pieces

    /** One card row with the card's background declared, so nothing inverts unevenly. */
    private function row(string $inner, string $padding, string $align = 'left'): string
    {
        return '<tr><td class="nl-card nl-pad" align="' . $align . '" style="padding:' . $padding . '; background-color:' . self::CARD . '; text-align:' . $align . ';">'
            . $inner
            . '</td></tr>';
    }

    /** An <img>, linked when the block has a link; null when it cannot be shown. */
    private function img(array $image, int $width): ?string
    {
        $src = $this->src($image);
        $alt = $this->plain($image['alt'] ?? null);

        // Alt text is required at the request. A stored picture without it is
        // left out rather than sent as an unlabelled image.
        if ($src === null || $alt === null || trim($alt) === '') {
            return null;
        }

        $img = '<img src="' . $this->e($src) . '" alt="' . $this->e(trim($alt)) . '" width="' . $width . '"'
            . ' style="display:block; width:100%; max-width:' . $width . 'px; height:auto; border:0; outline:none; text-decoration:none;">';

        $link = NewsletterBlocks::webUrl($image['link'] ?? null);

        return $link === null
            ? $img
            : '<a href="' . $this->e($link) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
    }

    /** The picture's absolute http(s) address, or null. Relative or odd-scheme sources are never sent. */
    private function src(array $image): ?string
    {
        return NewsletterBlocks::webUrl($image['src'] ?? null);
    }

    private function imageText(array $block): ?string
    {
        if ($this->src($block) === null) {
            return null;
        }

        $alt = $this->plain($block['alt'] ?? null);
        if ($alt === null || trim($alt) === '') {
            return null;
        }

        $link = NewsletterBlocks::webUrl($block['link'] ?? null);

        return '[' . trim($alt) . ']' . ($link !== null ? ' (' . $link . ')' : '');
    }

    private function imageRowText(array $block): ?string
    {
        $lines = array_filter(array_map(
            fn ($image) => is_array($image) ? $this->imageText($image) : null,
            (array) ($block['images'] ?? []),
        ));

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function buttonText(array $block): ?string
    {
        $label = $this->plain($block['label'] ?? null);
        $url = NewsletterBlocks::webUrl($block['url'] ?? null);

        return $label === null || trim($label) === '' || $url === null ? null : trim($label) . ': ' . $url;
    }

    private function align(array $block): string
    {
        return in_array($block['align'] ?? null, NewsletterBlocks::ALIGNS, true) ? $block['align'] : 'left';
    }

    private function plain(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
