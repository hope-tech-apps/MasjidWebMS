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

    public function messages(): array
    {
        $maxKb = (int) config('groups.media.max_size_kb', 8192);
        $maxPerPost = (int) config('groups.media.max_per_post', 8);

        return [
            self::UPLOAD_KEY . '.max' => 'A post may carry at most ' . $maxPerPost . ' images.',
            self::UPLOAD_KEY . '.*.file' => 'The upload could not be read as a file.',
            self::UPLOAD_KEY . '.*.mimetypes' => 'That file type is not accepted; group posts carry images only.',
            self::UPLOAD_KEY . '.*.max' => 'That image is larger than the ' . $maxKb . 'KB limit.',
        ];
    }
}
