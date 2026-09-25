<?php

namespace App\Services\Broadcast\Newsletter;

/**
 * The newsletter layout's schema: which blocks exist, what each may hold, and
 * the one canonical shape each is stored in.
 *
 * The seven types are the pieces MEC's Wix campaigns are built from — a title,
 * paragraphs, a flyer, a "Register" button, a rule between sections, two photos
 * side by side, and space — and nothing more. Every block is data, never markup:
 * a heading is a string, a button is a label and an address, and the one type
 * that carries HTML (text) is re-parsed through RichText, which can only write
 * the tags it knows.
 *
 * ## Images are referenced, never addressed
 *
 * An image block names an upload KEY, not a URL. The file arrives in the same
 * request (`block_images[<key>]`), is stored as a media row on the broadcast,
 * and the address in the email is built from that row on the application's own
 * disk. An admin therefore cannot point a newsletter at somebody else's server —
 * which would hand every open, with the reader's IP address, to whoever runs it
 * — and every image is one this organisation uploaded, with the alt text this
 * class requires.
 *
 * ## One validator, two callers
 *
 * `errors()` is used by the send request and by the preview endpoint, so the
 * preview cannot accept a layout the send would refuse. The only difference is
 * whether the uploads must be present: a preview renders before anything is
 * uploaded.
 */
final class NewsletterBlocks
{
    public const TYPES = ['heading', 'text', 'image', 'button', 'divider', 'image_row', 'spacer'];

    /** A long newsletter is ~25 blocks; this is a ceiling on accidents, not on style. */
    public const MAX_BLOCKS = 40;

    /**
     * Distinct images per newsletter. With MAX_IMAGE_KB this bounds one request
     * at 80 MB, inside production's 100M upload limit with room for the form.
     */
    public const MAX_IMAGES = 10;

    /** Per image, in kilobytes (Laravel's `max` unit for files). */
    public const MAX_IMAGE_KB = 8192;

    public const MAX_HEADING = 200;

    public const MAX_ALT = 300;

    public const MAX_BUTTON_LABEL = 80;

    public const MAX_URL = 2048;

    /** An upload key: short, lowercase, safe inside a form field name and a URL path. */
    public const IMAGE_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    public const ALIGNS = ['left', 'center'];

    /** Spacer heights in pixels. Named sizes rather than numbers so nobody sends 4000px of nothing. */
    public const SPACER_SIZES = ['small' => 12, 'medium' => 24, 'large' => 48];

    /**
     * Every problem with a proposed layout, keyed like Laravel's errors
     * (`blocks.3.alt`) and worded for the admin who has to fix it.
     *
     * @param  mixed  $blocks  the decoded `blocks` input
     * @param  list<string>|null  $uploadedKeys  keys that arrived as files; null skips the check (preview)
     * @return array<string, string>
     */
    public static function errors(mixed $blocks, ?array $uploadedKeys): array
    {
        if ($blocks === null || $blocks === []) {
            return [];
        }

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return ['blocks' => 'The newsletter layout could not be read. Reload the page and try again.'];
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            return ['blocks' => 'A newsletter can have at most ' . self::MAX_BLOCKS . ' blocks.'];
        }

        $errors = [];
        $keys = [];

        foreach ($blocks as $i => $block) {
            $n = $i + 1;
            $at = "blocks.{$i}";

            if (! is_array($block) || ! in_array($block['type'] ?? null, self::TYPES, true)) {
                $errors["{$at}.type"] = "Block {$n} is not a kind of block the newsletter knows.";
                continue;
            }

            switch ($block['type']) {
                case 'heading':
                    $text = self::string($block['text'] ?? null);
                    if ($text === null || trim($text) === '') {
                        $errors["{$at}.text"] = "Block {$n} (heading): write the heading.";
                    } elseif (mb_strlen(trim($text)) > self::MAX_HEADING) {
                        $errors["{$at}.text"] = "Block {$n} (heading): keep it under " . self::MAX_HEADING . ' characters.';
                    }
                    self::checkAlign($block, $at, $n, 'heading', $errors);
                    break;

                case 'text':
                    $html = self::string($block['html'] ?? null);
                    if ($html !== null && mb_strlen($html) > RichText::MAX_LENGTH) {
                        $errors["{$at}.html"] = "Block {$n} (text) is too long; split it into two text blocks.";
                    } elseif (! RichText::hasVisibleText($html)) {
                        $errors["{$at}.html"] = "Block {$n} (text) is empty.";
                    }
                    break;

                case 'image':
                    self::checkImage($block, $at, "Block {$n} (image)", $uploadedKeys, $errors, $keys);
                    break;

                case 'image_row':
                    $images = $block['images'] ?? null;
                    if (! is_array($images) || ! array_is_list($images) || count($images) !== 2) {
                        $errors["{$at}.images"] = "Block {$n} (two images): choose exactly two pictures.";
                        break;
                    }
                    foreach ($images as $j => $image) {
                        $side = $j === 0 ? 'left' : 'right';
                        self::checkImage(
                            is_array($image) ? $image : [],
                            "{$at}.images.{$j}",
                            "Block {$n} (two images, {$side})",
                            $uploadedKeys,
                            $errors,
                            $keys,
                        );
                    }
                    break;

                case 'button':
                    $label = self::string($block['label'] ?? null);
                    if ($label === null || trim($label) === '') {
                        $errors["{$at}.label"] = "Block {$n} (button): write what the button says.";
                    } elseif (mb_strlen(trim($label)) > self::MAX_BUTTON_LABEL) {
                        $errors["{$at}.label"] = "Block {$n} (button): keep the label under " . self::MAX_BUTTON_LABEL . ' characters.';
                    }
                    if (self::webUrl($block['url'] ?? null) === null) {
                        $errors["{$at}.url"] = "Block {$n} (button): enter the full web address it opens, starting https://.";
                    }
                    self::checkAlign($block, $at, $n, 'button', $errors);
                    break;

                case 'spacer':
                    $size = $block['size'] ?? 'medium';
                    if (! is_string($size) || ! array_key_exists($size, self::SPACER_SIZES)) {
                        $errors["{$at}.size"] = "Block {$n} (space): choose small, medium or large.";
                    }
                    break;

                case 'divider':
                    break;
            }
        }

        if (count(array_unique($keys)) > self::MAX_IMAGES) {
            $errors['blocks'] = 'A newsletter can carry at most ' . self::MAX_IMAGES . ' pictures.';
        }

        return $errors;
    }

    /**
     * The canonical stored shape of a layout that passed `errors()`: trimmed
     * strings, sanitised text, defaults filled, unknown keys gone.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $blocks): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $out[] = match ($block['type']) {
                'heading' => [
                    'type' => 'heading',
                    'text' => trim((string) $block['text']),
                    'align' => self::align($block),
                ],
                'text' => ['type' => 'text', 'html' => RichText::sanitize((string) $block['html'])],
                'image' => ['type' => 'image'] + self::image($block),
                'image_row' => [
                    'type' => 'image_row',
                    'images' => [self::image($block['images'][0]), self::image($block['images'][1])],
                ],
                'button' => [
                    'type' => 'button',
                    'label' => trim((string) $block['label']),
                    'url' => (string) self::webUrl($block['url']),
                    'align' => self::align($block),
                ],
                'divider' => ['type' => 'divider'],
                'spacer' => ['type' => 'spacer', 'size' => (string) ($block['size'] ?? 'medium')],
            };
        }

        return $out;
    }

    /**
     * Every image key a layout references, in first-use order.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function imageKeys(array $blocks): array
    {
        $keys = [];

        foreach ($blocks as $block) {
            $images = match ($block['type'] ?? null) {
                'image' => [$block],
                'image_row' => (array) ($block['images'] ?? []),
                default => [],
            };
            foreach ($images as $image) {
                if (is_array($image) && is_string($image['image'] ?? null)) {
                    $keys[] = $image['image'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The layout with each image key swapped for the absolute address it will be
     * served from. A key with no address (an upload that never landed) keeps a
     * null `src`, and the renderer leaves that picture out rather than sending a
     * broken image.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $urls  key => absolute URL
     * @return list<array<string, mixed>>
     */
    public static function withImageUrls(array $blocks, array $urls): array
    {
        $resolve = function (mixed $image) use ($urls): array {
            $image = is_array($image) ? $image : [];
            $key = is_string($image['image'] ?? null) ? $image['image'] : '';
            $image['src'] = $urls[$key] ?? null;

            return $image;
        };

        return array_map(function (mixed $block) use ($resolve) {
            if (! is_array($block)) {
                return $block;
            }
            if (($block['type'] ?? null) === 'image') {
                return $resolve($block);
            }
            if (($block['type'] ?? null) === 'image_row') {
                $block['images'] = array_map($resolve, (array) ($block['images'] ?? []));
            }

            return $block;
        }, array_values($blocks));
    }

    /**
     * An absolute http(s) address, or null.
     *
     * Laravel's `url` rule accepts dozens of schemes, `javascript:` among them,
     * which is why the newsletter does not use it for anything a reader clicks.
     */
    public static function webUrl(mixed $url): ?string
    {
        $url = self::string($url);
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || mb_strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $image
     * @param  array<string, string>  $errors
     * @param  list<string>  $keys
     * @param  list<string>|null  $uploadedKeys
     */
    private static function checkImage(array $image, string $at, string $label, ?array $uploadedKeys, array &$errors, array &$keys): void
    {
        $key = self::string($image['image'] ?? null);

        if ($key === null || preg_match(self::IMAGE_KEY_PATTERN, $key) !== 1) {
            $errors["{$at}.image"] = "{$label}: choose a picture.";
        } elseif ($uploadedKeys !== null && ! in_array($key, $uploadedKeys, true)) {
            $errors["{$at}.image"] = "{$label}: the picture did not upload. Choose it again.";
        } else {
            $keys[] = $key;
        }

        // Alt text is REQUIRED, not encouraged: a newsletter that is one flyer
        // image says nothing at all to a reader using a screen reader, or to
        // anyone whose mail client blocks images by default (Outlook does).
        $alt = self::string($image['alt'] ?? null);
        if ($alt === null || trim($alt) === '') {
            $errors["{$at}.alt"] = "{$label}: describe the picture for people who cannot see it.";
        } elseif (mb_strlen(trim($alt)) > self::MAX_ALT) {
            $errors["{$at}.alt"] = "{$label}: keep the description under " . self::MAX_ALT . ' characters.';
        }

        $link = $image['link'] ?? null;
        if ($link !== null && $link !== '' && self::webUrl($link) === null) {
            $errors["{$at}.link"] = "{$label}: the link must be a full web address, starting https://.";
        }
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $errors
     */
    private static function checkAlign(array $block, string $at, int $n, string $kind, array &$errors): void
    {
        $align = $block['align'] ?? null;

        if ($align !== null && ! in_array($align, self::ALIGNS, true)) {
            $errors["{$at}.align"] = "Block {$n} ({$kind}): align it left or centre.";
        }
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array{image: string, alt: string, link: ?string}
     */
    private static function image(array $image): array
    {
        return [
            'image' => (string) $image['image'],
            'alt' => trim((string) $image['alt']),
            'link' => self::webUrl($image['link'] ?? null),
        ];
    }

    /** @param array<string, mixed> $block */
    private static function align(array $block): string
    {
        return in_array($block['align'] ?? null, self::ALIGNS, true) ? $block['align'] : 'left';
    }

    /** A string, or null for anything else — an array where text belongs is refused, not cast. */
    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
