<?php

namespace App\Support;

use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a feed post's images to the private disk and records them.
 *
 * The one place in the app that turns an UploadedFile into a stored group image,
 * so that where a child's photograph lands, what it is called on disk, and what
 * is written about it have a single definition. It is the third implementation
 * of the arrangement in .claude/rules/private-uploads.md, after
 * FlyerCutoutController and App\Support\FormAttachments, and it matches them
 * deliberately.
 *
 * Two properties this class exists to guarantee:
 *
 *  - **The stored name is random and the directory is tenant-scoped.** The
 *    uploader's filename is data, kept in the database; it never reaches the
 *    filesystem, so it cannot collide, cannot traverse, and cannot be guessed.
 *  - **All or nothing.** If the third of four images fails to write, the first
 *    two are removed before the exception escapes, so the surrounding
 *    transaction rolls the post back without leaving bytes behind that nothing
 *    points at.
 */
class GroupPostAttachments
{
    /**
     * Store every uploaded image against a saved post.
     *
     * @param  array<int,UploadedFile>  $uploads
     * @return array<int,GroupPostAttachment>
     */
    public static function store(GroupPost $post, array $uploads): array
    {
        if ($uploads === []) {
            return [];
        }

        $diskName = (string) config('groups.media.disk', 'local');
        $disk = Storage::disk($diskName);

        // <root>/<masjid_id>/<group_id>/ — one organization's images are never
        // interleaved with another's on disk (private-uploads rule 3).
        $directory = trim((string) config('groups.media.directory', 'group-media'), '/')
            . '/' . $post->masjid_id
            . '/' . $post->group_id;

        $written = [];
        $records = [];

        try {
            foreach ($uploads as $file) {
                // The extension comes from the file's SNIFFED type, never from
                // the name the client sent — the uploaded name has no business
                // deciding what we write to disk.
                $storedName = Str::random(40) . '.' . ($file->extension() ?: 'bin');

                $path = $disk->putFileAs($directory, $file, $storedName);

                if ($path === false) {
                    throw new \RuntimeException('The uploaded image could not be saved.');
                }

                $written[] = $path;

                $records[] = GroupPostAttachment::create([
                    'group_post_id' => $post->id,
                    // Server-derived from the post, never from the payload.
                    'masjid_id' => $post->masjid_id,
                    'original_name' => self::safeOriginalName($file),
                    'mime_type' => Str::limit((string) $file->getMimeType(), 190, ''),
                    'size_bytes' => (int) $file->getSize(),
                    'disk' => $diskName,
                    'path' => $path,
                ]);
            }
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }

            throw $e;
        }

        return $records;
    }

    /**
     * The uploader's filename, reduced to something safe to store and to hand
     * back in a Content-Disposition header.
     *
     * Path separators and control characters are removed rather than escaped:
     * this value is shown to a reader and used as a DOWNLOAD name, and neither
     * use has any reason to carry a directory or a newline.
     */
    private static function safeOriginalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();

        // basename() first so "../../etc/passwd" becomes "passwd", then strip
        // the separators Windows uses, which basename() on Linux does not treat
        // as one.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'image.' . ($file->extension() ?: 'bin');
        }

        return Str::limit($name, 200, '');
    }
}
