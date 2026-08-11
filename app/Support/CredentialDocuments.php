<?php

namespace App\Support;

use App\Models\ContactCredential;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a credential's scanned document to the private disk and points the
 * credential row at it.
 *
 * Third implementation of the private-upload arrangement (after
 * FlyerCutoutController and FormAttachments) and deliberately shaped like
 * FormAttachments so the properties are the same by inspection:
 *
 *  - **The stored name is random and the directory is tenant-scoped**
 *    (`<root>/<masjid_id>/<contact_id>/…`). The admin's filename is data, kept
 *    in the database; it never reaches the filesystem, so it cannot collide,
 *    cannot traverse, and cannot be guessed.
 *  - **A replacement never strands the row.** The old bytes are deleted only
 *    AFTER the row durably points at the new ones; if the save fails, the new
 *    bytes are removed and the row keeps pointing at the old document.
 */
class CredentialDocuments
{
    /**
     * Store (or replace) the single document on a saved credential.
     *
     * The credential must already exist — masjid_id and contact_id come off the
     * row, never from the request, so the path is server-derived end to end.
     */
    public static function attach(ContactCredential $credential, UploadedFile $file): void
    {
        $diskName = (string) config('credentials.document.disk', 'local');
        $disk = Storage::disk($diskName);

        $directory = trim((string) config('credentials.document.directory', 'credential-documents'), '/')
            . '/' . $credential->masjid_id
            . '/' . $credential->contact_id;

        // The extension is derived from the file's sniffed type, never from the
        // name the client sent — the uploaded name has no business deciding
        // what we write to disk. Same reasoning as FormAttachments::store.
        $storedName = Str::random(40) . '.' . ($file->extension() ?: 'bin');

        $path = $disk->putFileAs($directory, $file, $storedName);

        if ($path === false) {
            throw new \RuntimeException('The uploaded document could not be saved.');
        }

        // Remembered BEFORE the row is updated, so the old bytes can be removed
        // once — and only once — the row no longer references them.
        $previousDisk = $credential->document_disk;
        $previousPath = $credential->document_path;

        try {
            $credential->forceFill([
                'document_original_name' => self::safeOriginalName($file),
                'document_mime_type' => Str::limit((string) $file->getMimeType(), 190, ''),
                'document_size_bytes' => (int) $file->getSize(),
                'document_disk' => $diskName,
                'document_path' => $path,
            ])->save();
        } catch (\Throwable $e) {
            // The row still points at the previous document (or at nothing), so
            // the new bytes are the orphans-to-be — remove them and let the
            // caller's transaction handle the rest.
            $disk->delete($path);

            throw $e;
        }

        if ($previousPath !== null && $previousPath !== $path) {
            Storage::disk($previousDisk)->delete($previousPath);
        }
    }

    /**
     * The admin's filename, reduced to something safe to store and to hand back
     * in a Content-Disposition header. Path separators and control characters
     * are removed rather than escaped: this value is shown to an admin and used
     * as a DOWNLOAD name, and neither use has any reason to carry a directory
     * or a newline. Same reduction as FormAttachments::safeOriginalName.
     */
    private static function safeOriginalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();

        // basename() first so "../../etc/passwd" becomes "passwd", then strip
        // the separators Windows uses, which basename() on Linux does not.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document.' . ($file->extension() ?: 'bin');
        }

        return Str::limit($name, 200, '');
    }
}
