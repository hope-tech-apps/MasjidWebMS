<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a class's resource file to the private disk and records it.
 *
 * The one place that turns an UploadedFile into a stored class resource, so
 * that where it lands, what it is called on disk, and what is written about it
 * have a single definition. Another implementation of the arrangement in
 * .claude/rules/private-uploads.md, matching App\Support\GroupPostAttachments
 * deliberately and almost line for line — the differences are only that this
 * writes ONE file rather than a set, and that it carries a visibility.
 *
 * The two properties that matter are the same two:
 *  - **The stored name is random and the directory is tenant-scoped.** The
 *    uploader's filename is data, kept in the database; it never reaches the
 *    filesystem, so it cannot collide, cannot traverse, and cannot be guessed.
 *  - **All or nothing.** If the row cannot be written, the bytes are removed
 *    before the exception escapes, so nothing is left on disk that nothing
 *    points at.
 */
class GroupResourceFiles
{
    public static function store(Group $group, UploadedFile $file, array $attributes): GroupResource
    {
        $diskName = (string) config('groups.resources.disk', 'local');
        $disk = Storage::disk($diskName);

        // <root>/<masjid_id>/<group_id>/ — one organization's files are never
        // interleaved with another's on disk (private-uploads rule 3).
        $directory = trim((string) config('groups.resources.directory', 'group-resources'), '/')
            . '/' . $group->masjid_id
            . '/' . $group->id;

        // The extension comes from the file's SNIFFED type, never from the name
        // the client sent — the uploaded name has no business deciding what we
        // write to disk.
        $storedName = Str::random(40) . '.' . ($file->extension() ?: 'bin');

        $path = $disk->putFileAs($directory, $file, $storedName);

        if ($path === false) {
            throw new \RuntimeException('The uploaded file could not be saved.');
        }

        try {
            return GroupResource::create($attributes + [
                // Server-derived from the group, never from the payload.
                'masjid_id' => $group->masjid_id,
                'group_id' => $group->id,
                'original_name' => self::safeOriginalName($file),
                'mime_type' => Str::limit((string) $file->getMimeType(), 190, ''),
                'size_bytes' => (int) $file->getSize(),
                'disk' => $diskName,
                'path' => $path,
            ]);
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /**
     * The uploader's filename, reduced to something safe to store and to hand
     * back in a Content-Disposition header.
     *
     * Path separators and control characters are removed rather than escaped:
     * this value is shown to a reader and used as a DOWNLOAD name, and neither
     * use has any reason to carry a directory or a newline. Copied verbatim from
     * GroupPostAttachments so the two cannot drift.
     */
    private static function safeOriginalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();

        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file.' . ($file->extension() ?: 'bin');
        }

        return Str::limit($name, 200, '');
    }
}
