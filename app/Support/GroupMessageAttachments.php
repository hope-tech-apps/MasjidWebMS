<?php

namespace App\Support;

use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a conversation message's photos to the private disk and records them.
 *
 * The same arrangement as GroupPostAttachments, on the same disk and under the
 * same allowlist (`config('groups.media')`), because a photo of a child is the
 * same thing whether it arrives in the class story or in a conversation:
 *
 *  - the stored name is random and the directory is tenant-scoped
 *    (`<root>/<masjid_id>/<group_id>/threads/<thread_id>/`), so the uploader's
 *    filename never reaches the filesystem;
 *  - all or nothing — if a later photo fails to write, the earlier ones are
 *    removed before the exception escapes, so the surrounding transaction rolls
 *    the message back with no bytes left behind that nothing points at.
 */
class GroupMessageAttachments
{
    /**
     * @param  array<int,UploadedFile>  $uploads
     * @return array<int,GroupMessageAttachment>
     */
    public static function store(GroupMessage $message, array $uploads): array
    {
        if ($uploads === []) {
            return [];
        }

        $thread = $message->thread()->withTrashed()->firstOrFail();

        $diskName = (string) config('groups.media.disk', 'local');
        $disk = Storage::disk($diskName);

        $directory = trim((string) config('groups.media.directory', 'group-media'), '/')
            . '/' . $message->masjid_id
            . '/' . $thread->group_id
            . '/threads/' . $thread->id;

        $written = [];
        $records = [];

        try {
            foreach ($uploads as $file) {
                // The extension comes from the SNIFFED type, never the client's name.
                $storedName = Str::random(40) . '.' . ($file->extension() ?: 'bin');

                $path = $disk->putFileAs($directory, $file, $storedName);

                if ($path === false) {
                    throw new \RuntimeException('The uploaded photo could not be saved.');
                }

                $written[] = $path;

                $mimeType = Str::limit((string) $file->getMimeType(), 190, '');

                $records[] = GroupMessageAttachment::create([
                    'group_message_id' => $message->id,
                    // Server-derived from the message, never from the payload.
                    'masjid_id' => $message->masjid_id,
                    'original_name' => GroupPostAttachments::safeOriginalName($file),
                    'mime_type' => $mimeType,
                    'size_bytes' => (int) $file->getSize(),
                    'disk' => $diskName,
                    'path' => $path,
                    // The same shorter window video carries on the story side —
                    // one definition, in GroupMedia, because a video of a child
                    // is the same thing in a conversation as in a class story.
                    'retained_until' => GroupMedia::retainedUntilFor($mimeType),
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
}
