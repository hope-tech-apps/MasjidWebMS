<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SectionType;

/**
 * The per-field upload rule and the content rule for SectionType::VIDEO, shared by the
 * four requests that can write a section (Sections store/update, PageSections
 * store/update), beside ValidatesEmbedContent and for the same reason: the SPA is not
 * the only way in, so the rule lives where every writer passes through.
 *
 * Before the video type, every uploaded file got the image rule. It still does, with ONE
 * exception: the `video_url` field of a `video` section takes an MP4 (by its bytes AND
 * its `.mp4` name) of up to 25 MB. An
 * MP4 sent anywhere else (an image section's `image_url`, the video section's own
 * `poster_url`) is still refused, because those fields are drawn by `<img>` elements and
 * an MP4 in one draws nothing.
 *
 * Uses ValidatesEmbedContent::resolvedSectionType(), declared abstract below so the
 * dependency is stated rather than assumed: on an update `section_type` may be omitted,
 * and the section's stored type then decides, exactly as it does for an embed (and, like
 * it, only from this tenant's sections).
 */
trait ValidatesVideoSection
{
    /**
     * 25 MB in kilobytes, Laravel's unit for `max` on a file: Spatie's own ceiling
     * (config/media-library.php `max_file_size`), so a file this rule passes is one the
     * media library will store. The image rule has always used the same number.
     */
    private const SECTION_UPLOAD_MAX_KB = 25600;

    abstract private function resolvedSectionType(): ?SectionType;

    /**
     * One rule per uploaded file, keyed by its FormData name (`image_url`,
     * `slides_0_image_url`, `video_url`).
     *
     * @return array<string, string>
     */
    protected function sectionUploadRules(): array
    {
        $isVideo = $this->resolvedSectionType() === SectionType::VIDEO;
        $rules = [];

        foreach ($this->allFiles() as $fieldName => $_) {
            // Both halves of each rule are needed. `mimetypes` / `mimes` read the file's
            // BYTES (finfo), not its name or the browser's claim: a JPEG renamed clip.mp4
            // is still a JPEG. `extensions` pins the NAME, because the media library keeps
            // the client's file name (DefaultFileNamer) on the public disk, and the web
            // server picks the Content-Type from the extension: real MP4 or JPEG bytes
            // uploaded as `x.html` would be served as a page on this app's own origin.
            $rules[$fieldName] = $isVideo && $fieldName === 'video_url'
                ? 'nullable|file|mimetypes:video/mp4|extensions:mp4|max:' . self::SECTION_UPLOAD_MAX_KB
                : 'nullable|file|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:' . self::SECTION_UPLOAD_MAX_KB;
        }

        return $rules;
    }

    /**
     * Attach as a `content` closure rule. Silent for every other section type.
     *
     * Only the two enumerations are checked. A `layout` the renderer does not know would
     * be drawn as a player (the layout that never moves by itself), so this is about the
     * admin seeing a mistake, not about safety; `video_url` and `poster_url` are
     * scheme-checked by the renderer, as every image URL is.
     */
    protected function validateVideoContent(mixed $content, callable $fail): void
    {
        if ($this->resolvedSectionType() !== SectionType::VIDEO || ! is_array($content)) {
            return;
        }

        if (array_key_exists('layout', $content) && ! in_array($content['layout'], ['player', 'banner'], true)) {
            $fail('A video is shown as a player or as a banner.');
        }

        if (array_key_exists('max_width', $content) && ! in_array($content['max_width'], ['full', 'container', 'narrow'], true)) {
            $fail('A video player is full width, container width or narrow.');
        }
    }
}
