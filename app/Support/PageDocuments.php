<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * PDFs an office attaches to its web pages (a curriculum, a calendar, a schedule).
 *
 * A document is NOT part of a section. It is uploaded on its own (PageDocumentsController), stored at
 * once, and answered with its public address; the page tool then puts that address into a link field
 * a section already has (a Link Buttons button, a programme's link, a call-to-action button). So the
 * website needs no new section type and no renderer change: it already draws those links.
 *
 * What that costs, and is said to the office where it acts:
 *
 *  - The file is PUBLIC from the second the upload ends, before the section is saved or the page
 *    published. Media ids count upwards, so an address is hard to guess but is not a secret. This is
 *    for documents meant for the public; a private file belongs to the arrangement in
 *    `.claude/rules/private-uploads.md`.
 *  - The organisation owns the file (collection `page_documents` on the Masjid), because a new section
 *    has no row to own it until it is saved.
 *
 * THE RULE THE OFFICE IS TOLD: a document stays online while a saved section links to it. Clear or
 * replace its address and save, or delete the section from the library, and the file is deleted then
 * (forgetUnlinked). Deliberately KEPT: a document whose section was only taken off a page, or whose
 * page was deleted (the section is still in the library), and a document that was uploaded and never
 * saved. Nothing scheduled removes anything: the only way to remove a never-saved upload is a job that
 * deletes public files by inference, and this platform has an incident history with exactly that
 * (App\Console\Commands\MediaVerify exists because of it).
 */
final class PageDocuments
{
    /** The media collection, on the Masjid. Read through Masjid::pageDocuments(), never by name alone. */
    public const COLLECTION = 'page_documents';

    /** The longest stored name, before `.pdf`. Long enough to read, short enough for any file system. */
    private const SLUG_MAX = 80;

    /** The longest name kept for the office to read (media.name). */
    private const NAME_MAX = 120;

    /**
     * A page document's address as it appears inside page content, whatever host or scheme was
     * written in front of it: `/storage/{media id}/{stored name}`. Matching the PATH means a change of
     * host or of http to https can never make a live file look unlinked. The lookahead refuses a
     * longer name that merely starts the same way (`calendar.pdf.html`, `calendar.pdfx`), and still
     * takes an address followed by a full stop, a query or a fragment.
     */
    private const ADDRESS = '#/storage/(\d+)/([a-z0-9-]+\.pdf)(?![\w.-]*\w)#';

    /**
     * Store one PDF for this organisation and return its media row.
     *
     * The caller has already checked the bytes, the name and the size (StorePageDocumentRequest).
     * THE CLIENT'S FILE NAME NEVER REACHES THE DISK: the web server serves a file on the public disk by
     * its extension, on the origin where the admin screens keep their sign-in token, so the name
     * written is one this method makes and it always ends in `.pdf`. That also means no character of
     * the client's name, no dot segment and no blocked middle part (`report.php.pdf`, which the media
     * library refuses with an exception) can reach the file system.
     *
     * Every upload is a new row and a new address. Replacing a document never puts new bytes behind an
     * old address, so no browser or edge cache can show the old file under the new link.
     */
    public static function store(Masjid $masjid, UploadedFile $file): Media
    {
        $base = self::baseName($file->getClientOriginalName());

        $media = $masjid->addMedia($file)
            ->usingName(self::displayName($base))
            ->usingFileName(self::storedName($base))
            ->toMediaCollection(self::COLLECTION);

        // The address is written into page content and read by the public website, which is another
        // host: a root-relative address would be looked up THERE and miss the file. The public disk's
        // configured `url` is absolute in every environment that serves a site, so this is a broken
        // configuration, and the honest answer is a failure with nothing left behind, not a link that
        // looks saved and opens nothing.
        if (! preg_match('#^https?://#i', $media->getUrl())) {
            $media->delete();

            throw new RuntimeException('The public disk has no absolute address, so a page document cannot be linked.');
        }

        return $media;
    }

    /**
     * Delete the documents a section's save (or its deletion) just stopped linking.
     *
     * Called after the write, with the section's content as it was and as it now is (`[]` once the
     * section is deleted). This DELETES PUBLIC FILES, so every step narrows what it may touch:
     *
     *  1. Only an address that was in THIS section before the write and is in it no longer.
     *  2. Only a file that is this organisation's own page document, found through
     *     Masjid::pageDocuments() by media id AND stored name. Another organisation's document whose
     *     address was pasted here, a section image, a gallery photo: none of them can match.
     *  3. Not while any other section of this organisation still carries the address, on a page or
     *     only in the library, active or not. Looked for in the decoded content, because the stored
     *     JSON writes each `/` as `\/`.
     *
     * It never fails the save it follows: whatever goes wrong here, the office's edit is already
     * stored, and the worst outcome is a file left online, which is the state before this existed.
     * Every removal, and every failure, leaves a line at WARNING (production's log level drops
     * anything quieter), by ids alone.
     */
    public static function forgetUnlinked(Masjid $masjid, mixed $before, mixed $after, int $sectionId): void
    {
        $ids = ['masjid_id' => $masjid->id, 'section_id' => $sectionId];

        try {
            $afterStrings = self::strings($after);
            $unlinked = array_filter(
                self::addresses(self::strings($before)),
                fn (string $path) => ! self::mentions($afterStrings, $path),
                ARRAY_FILTER_USE_KEY
            );

            $documents = [];
            foreach ($unlinked as $path => [$mediaId, $storedName]) {
                $media = $masjid->pageDocuments()->whereKey($mediaId)->where('file_name', $storedName)->first();

                if ($media !== null) {
                    $documents[$path] = $media;
                }
            }

            if ($documents === []) {
                return;
            }

            // The raw column, decoded here: Section's `content` accessor would also look a page up
            // for every row, and nothing below needs it.
            $elsewhere = $masjid->sections()->whereKeyNot($sectionId)->toBase()->pluck('content');
            foreach ($elsewhere as $content) {
                $strings = self::strings($content);
                $documents = array_filter(
                    $documents,
                    fn (string $path) => ! self::mentions($strings, $path),
                    ARRAY_FILTER_USE_KEY
                );
            }

            foreach ($documents as $media) {
                // Through the model, so the row and the file go together. One at a time: a file that
                // cannot be removed must not keep the others online.
                try {
                    $media->delete();

                    Log::warning('Page document deleted: no saved section links it any more', $ids + ['media_id' => $media->id]);
                } catch (Throwable $e) {
                    Log::warning('Page document NOT deleted, and no saved section links it any more', $ids + [
                        'media_id' => $media->id,
                        'exception' => $e::class,
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Page documents were not checked after a section was saved or deleted', $ids + [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Every string anywhere in a section's content, which arrives as an array (the model's accessor),
     * as the stored JSON, or as nothing.
     *
     * @return list<string>
     */
    private static function strings(mixed $content): array
    {
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = json_last_error() === JSON_ERROR_NONE ? $decoded : [$content];
        }

        $strings = [];
        $walk = function (mixed $value) use (&$walk, &$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $inner) {
                    $walk($inner);
                }
            }
        };
        $walk($content);

        return $strings;
    }

    /**
     * The page-document addresses written in these strings, keyed by path.
     *
     * @param  list<string>  $strings
     * @return array<string, array{int, string}> path => [media id, stored name]
     */
    private static function addresses(array $strings): array
    {
        $found = [];

        foreach ($strings as $string) {
            if (preg_match_all(self::ADDRESS, $string, $matches, PREG_SET_ORDER)) {
                foreach ($matches as [$path, $mediaId, $storedName]) {
                    $found[$path] = [(int) $mediaId, $storedName];
                }
            }
        }

        return $found;
    }

    /**
     * Whether any of these strings still carries this path. Looser than addresses() on purpose: when
     * in doubt a file is kept.
     *
     * @param  list<string>  $strings
     */
    private static function mentions(array $strings, string $path): bool
    {
        foreach ($strings as $string) {
            if (str_contains($string, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name written to disk: an ASCII slug of the client's name, then `.pdf`. `Academic Calendar
     * 2026.pdf` becomes `academic-calendar-2026.pdf`; a name with nothing a slug can keep becomes
     * `document.pdf`.
     */
    public static function storedName(string $base): string
    {
        // Str::slug transliterates first; the second pass is what GUARANTEES the result, whatever
        // script the name was in and whatever the transliteration left.
        $slug = preg_replace('/[^a-z0-9-]+/', '', strtolower(Str::slug($base))) ?? '';
        $slug = trim(substr($slug, 0, self::SLUG_MAX), '-');

        return ($slug === '' ? 'document' : $slug) . '.pdf';
    }

    /** The name the office gave the file, without its ending, kept on the media row for people to read. */
    public static function displayName(string $base): string
    {
        $name = trim(preg_replace('/\p{C}+/u', '', $base) ?? '');

        return $name === '' ? 'Document' : mb_substr($name, 0, self::NAME_MAX);
    }

    /**
     * The client's name without its last ending, as valid UTF-8. Not pathinfo(): it follows the
     * process locale, and drops the leading characters of a name in another script when the locale is
     * not a UTF-8 one.
     */
    private static function baseName(string $clientName): string
    {
        $name = mb_scrub($clientName, 'UTF-8');

        return preg_replace('/\.[^.]*$/', '', $name) ?? $name;
    }
}
