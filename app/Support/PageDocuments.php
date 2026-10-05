<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
