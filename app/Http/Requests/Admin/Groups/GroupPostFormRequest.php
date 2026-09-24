<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Shared boundary rules for the group-feed write requests.
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy
 * {status:'failed'} 422 instead of a raw ValidationException, which this app's
 * JSON renderer would turn into a 500.
 *
 * Images ride in their OWN top-level bag rather than nested inside the post
 * fields, for the same reason form uploads do (see SubmitFormResponseRequest): a
 * multipart body cannot carry both a scalar and a file under one key.
 *
 * The type allowlist and the size ceiling come from config('groups.media') and
 * are matched with `mimetypes:` against the type SNIFFED FROM THE BYTES — never
 * `mimes:` on a client-supplied extension, never the Content-Type header. A
 * tenant must not be able to widen what this server accepts, so the ceiling is
 * never read from anything the tenant controls. See
 * .claude/rules/private-uploads.md.
 *
 * masjid_id is NOT accepted by either subclass and never will be — the
 * BelongsToMasjid creating hook stamps it from the bound tenant.
 */
abstract class GroupPostFormRequest extends BaseFormRequest
{
    /** The top-level bag images arrive in. */
    public const UPLOAD_KEY = 'images';

    /**
     * The top-level bag VIDEOS arrive in — a SECOND bag, not a wider first one.
     *
     * `config('groups.media.mime_types')` and `max_size_kb` are one shared
     * definition read by both surfaces, so adding `video/mp4` and raising the
     * ceiling to 100MB there would have made a 100MB *image* legal too. Video
     * therefore arrives under its own key, against its own allowlist, its own
     * ceiling and its own count. See config/groups.php ("video").
     */
    public const VIDEO_UPLOAD_KEY = 'videos';

    /**
     * Rules for the image bag, identical on create and on edit.
     *
     * @return array<string,mixed>
     */
    protected function imageRules(): array
    {
        $mimeTypes = (array) config('groups.media.mime_types', []);
        $maxKb = (int) config('groups.media.max_size_kb', 8192);
        $maxPerPost = (int) config('groups.media.max_per_post', 8);

        $each = ['file'];

        // An empty allowlist would make `mimetypes:` match nothing and reject
        // every upload — the safe direction, but an unhelpful one to debug — so
        // it is only applied when something is actually configured.
        if ($mimeTypes !== []) {
            $each[] = 'mimetypes:' . implode(',', $mimeTypes);
        }

        if ($maxKb > 0) {
            $each[] = 'max:' . $maxKb;
        }

        $bag = ['nullable', 'array'];

        if ($maxPerPost > 0) {
            $bag[] = 'max:' . $maxPerPost;
        }

        return [
            self::UPLOAD_KEY => $bag,
            // One level deep. A nested array (images[0][photo]) fails `file` and
            // is rejected rather than silently flattened.
            self::UPLOAD_KEY . '.*' => $each,
        ];
    }

    /**
     * Rules for the VIDEO bag — the same shape as imageRules(), read from the
     * video block, and deliberately not merged into it.
     *
     * @return array<string,mixed>
     */
    protected function videoRules(): array
    {
        $mimeTypes = (array) config('groups.media.video.mime_types', []);
        $maxKb = (int) config('groups.media.video.max_size_kb', 0);
        $maxPerPost = (int) config('groups.media.video.max_per_post', 0);

        $each = ['file'];

        // As above: an empty allowlist would reject everything — the safe
        // direction, an unhelpful one to debug — so it is only applied when
        // something is configured. An operator who blanks
        // GROUP_MEDIA_VIDEO_MIME_TYPES to switch video OFF should set
        // GROUP_MEDIA_VIDEO_MAX_PER_POST=0 instead, which refuses the bag
        // outright rather than accepting any file in it.
        if ($mimeTypes !== []) {
            $each[] = 'mimetypes:' . implode(',', $mimeTypes);
        }

        if ($maxKb > 0) {
            $each[] = 'max:' . $maxKb;
        }

        $bag = ['nullable', 'array'];

        // `max:0` is a real rule and the right one: a configured ceiling of
        // zero means "this surface takes no video", and an empty array still
        // passes, so a client that always sends the bag is unaffected.
        $bag[] = 'max:' . max(0, $maxPerPost);

        return [
            self::VIDEO_UPLOAD_KEY => $bag,
            self::VIDEO_UPLOAD_KEY . '.*' => $each,
        ];
    }

    /**
     * Both bags. What every subclass actually wants: a post or a message may
     * carry photos, a video, or both, and neither bag is required.
     *
     * @return array<string,mixed>
     */
    protected function mediaRules(): array
    {
        return array_merge($this->imageRules(), $this->videoRules());
    }

    /**
     * What the images are attached to, for the error text. Conversation
     * messages reuse these rules (StoreGroupMessageRequest,
     * StoreGroupThreadRequest) because a photo of a child is the same file
     * wherever it is sent; only the noun in the sentence differs.
     */
    protected function uploadNoun(): string
    {
        return 'post';
    }

    public function messages(): array
    {
        $maxKb = (int) config('groups.media.max_size_kb', 8192);
        $maxPerPost = (int) config('groups.media.max_per_post', 8);
        $videoMaxKb = (int) config('groups.media.video.max_size_kb', 0);
        $videoMaxPerPost = (int) config('groups.media.video.max_per_post', 0);
        $noun = $this->uploadNoun();

        return [
            self::UPLOAD_KEY . '.max' => 'A ' . $noun . ' may carry at most ' . $maxPerPost . ' images.',
            self::UPLOAD_KEY . '.*.file' => 'The upload could not be read as a file.',
            self::UPLOAD_KEY . '.*.mimetypes' => 'That file type is not accepted; a group ' . $noun . ' can carry images only.',
            self::UPLOAD_KEY . '.*.max' => 'That image is larger than the ' . $maxKb . 'KB limit.',

            // The video bag's own sentences. The size one is stated in MB
            // rather than the rule's KB: the ceiling is 102400KB, and a teacher
            // reading "larger than the 102400KB limit" has to do arithmetic
            // while holding a phone.
            self::VIDEO_UPLOAD_KEY . '.max' => $videoMaxPerPost > 0
                ? 'A ' . $noun . ' may carry at most ' . $videoMaxPerPost . ' video.'
                : 'A ' . $noun . ' cannot carry a video.',
            self::VIDEO_UPLOAD_KEY . '.*.file' => 'The upload could not be read as a file.',
            self::VIDEO_UPLOAD_KEY . '.*.mimetypes' =>
                'That video format is not accepted; send an MP4, a MOV or a WebM.',
            self::VIDEO_UPLOAD_KEY . '.*.max' =>
                'That video is larger than the ' . round($videoMaxKb / 1024) . 'MB limit.',
        ];
    }
}
