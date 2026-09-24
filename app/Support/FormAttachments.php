<?php

namespace App\Support;

use App\Models\FormResponse;
use App\Models\FormResponseAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a submission's uploads to the private disk and records them.
 *
 * The one place in the app that turns an UploadedFile into a stored attachment, so
 * that where a résumé lands, what it is called on disk, and what is written about
 * it have a single definition.
 *
 * Two properties this class exists to guarantee:
 *
 *  - **The stored name is random and the directory is tenant-scoped.** The
 *    respondent's filename is data, kept in the database; it never reaches the
 *    filesystem, so it cannot collide, cannot traverse, and cannot be guessed.
 *  - **All or nothing.** If the second of two files fails to write, the first is
 *    removed before the exception escapes, so the surrounding transaction rolls
 *    the response back without leaving bytes behind that nothing points at.
 */
class FormAttachments
{
    /**
     * Store every upload against a saved response.
     *
     * @param  array<string,UploadedFile>  $uploads  keyed by schema field name
     * @return array<string,string>  field name => the respondent's original filename
     */
    public static function store(FormResponse $response, array $uploads): array
    {
        if ($uploads === []) {
            return [];
        }

        $diskName = (string) config('forms.attachments.disk', 'local');
        $disk = Storage::disk($diskName);

        $directory = trim((string) config('forms.attachments.directory', 'form-attachments'), '/')
            . '/' . $response->masjid_id
            . '/' . $response->form_id;

        $written = [];
        $names = [];

        try {
            foreach ($uploads as $field => $file) {
                // The extension is derived from the file's sniffed type, never from
                // the name the client sent — the uploaded name has no business
                // deciding what we write to disk. Same reasoning as
                // FlyerCutoutController::store.
                $storedName = Str::random(40) . '.' . ($file->extension() ?: 'bin');

                $path = $disk->putFileAs($directory, $file, $storedName);

                if ($path === false) {
                    throw new \RuntimeException('The uploaded file could not be saved.');
                }

                $written[] = $path;

                $original = self::safeOriginalName($file);

                FormResponseAttachment::create([
                    'form_response_id' => $response->id,
                    // Server-derived from the response, never from the payload.
                    'masjid_id' => $response->masjid_id,
                    'field' => $field,
                    'original_name' => $original,
                    'mime_type' => Str::limit((string) $file->getMimeType(), 190, ''),
                    'size_bytes' => (int) $file->getSize(),
                    'disk' => $diskName,
                    'path' => $path,
                ]);

                $names[$field] = $original;
            }
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }

            throw $e;
        }

        return $names;
    }

    /**
     * Store ONE file that is already on this server's disk — a document the
     * school-website import has just downloaded — against a saved response.
     *
     * The upload path has a request boundary that settles what may be stored
     * (SubmitFormResponseRequest, from config('forms.attachments')). A file that
     * arrives by import has no such boundary, so the same two checks are made here
     * and made the same way: the type SNIFFED FROM THE BYTES against
     * `mime_types`, and the size against `max_size_kb`
     * (.claude/rules/private-uploads.md). Nothing the sender claims — the name,
     * the extension, a Content-Type — is consulted.
     *
     * A file that fails either check is NOT stored and null is returned; the
     * caller says so in the log. Otherwise it goes through store(), so the
     * directory, the random name, the attachment row and the rollback are the
     * upload path's own.
     *
     * The file at $absolutePath is read, never moved or deleted: it belongs to the
     * caller, who removes it.
     *
     * @return string|null  the stored original filename, or null when refused
     */
    public static function storeFromPath(FormResponse $response, string $field, string $absolutePath, string $originalName): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        // test: true — this file did not arrive through a PHP upload, so
        // is_uploaded_file() would call it invalid. That flag only waives that one
        // check; the type and size are checked below.
        $file = new UploadedFile($absolutePath, $originalName, null, null, true);

        $size = (int) $file->getSize();
        $maxBytes = max(0, (int) config('forms.attachments.max_size_kb', 8192)) * 1024;

        if ($size <= 0 || $size > $maxBytes) {
            return null;
        }

        $allowed = (array) config('forms.attachments.mime_types', []);

        if (! in_array((string) $file->getMimeType(), $allowed, true)) {
            return null;
        }

        return self::store($response, [$field => $file])[$field] ?? null;
    }

    /**
     * The respondent's filename, reduced to something safe to store and to hand
     * back in a Content-Disposition header.
     *
     * Path separators and control characters are removed rather than escaped: this
     * value is shown to an admin and used as a DOWNLOAD name, and neither use has
     * any reason to carry a directory or a newline.
     */
    private static function safeOriginalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();

        // basename() first so "../../etc/passwd" becomes "passwd", then strip the
        // separators Windows uses, which basename() on Linux does not treat as one.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment.' . ($file->extension() ?: 'bin');
        }

        return Str::limit($name, 200, '');
    }
}
