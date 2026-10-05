<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     *
     * The id has NO LEADING ZERO. `/storage/03/calendar.pdf` has no file behind it and is not an
     * address anybody is given, but read as a number it is document 3: it would find that document's
     * row while the "still linked" test looked for the string `/storage/03/...`, which no section
     * holding the real address contains. So it is not a page-document address at all, and removing
     * one can never start a deletion.
     */
    private const ADDRESS = '#/storage/([1-9]\d*)/([a-z0-9-]+\.pdf)(?![\w.-]*\w)#';

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

        // NO ROW WITHOUT A FILE. The media library saves the row and then copies the file, and it
        // takes its row back only for the failure it expects (a write the disk refuses). A copy that
        // THROWS, as when the file's directory cannot be made on a full or unwritable disk, leaves the
        // row behind, pointing at nothing, and each retry leaves one more. Inside a transaction the
        // row goes with the exception.
        $media = $masjid->getConnection()->transaction(fn () => $masjid->addMedia($file)
            ->usingName(self::displayName($base))
            ->usingFileName(self::storedName($base))
            ->toMediaCollection(self::COLLECTION));

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
     *  1. Only an address that was in THIS section before the write and is in it no longer. Read
     *     from the content as it was written: an address that was only ever there percent-encoded,
     *     inside another link, keeps its file (step 3) and never starts a deletion.
     *  2. Only a file that is this organisation's own page document, found through
     *     Masjid::pageDocuments() by media id AND stored name. Another organisation's document whose
     *     address was pasted here, a section image, a gallery photo: none of them can match.
     *  3. Not while this section, or any other section of this organisation (on a page or only in
     *     the library, active or not), still LINKS the file. "Links" is read widely, because a wrong
     *     answer here deletes a file a page still uses: the address as it was written, the
     *     document's own path (`/storage/{id}/{stored name}`), either of them percent-encoded
     *     inside another address, and any of those in a spelling a browser resolves to the same
     *     file (spellings()). Looked for in the decoded content, because the stored JSON writes
     *     each `/` as `\/`.
     *  4. Not on a save that looks OUT OF DATE (linksADocumentThatIsGone()): then nothing is deleted.
     *
     * It never fails the save it follows: whatever goes wrong here, the office's edit is already
     * stored, and the worst outcome is a file left online, which is the state before this existed.
     * Every removal, and every failure, leaves a line at WARNING (production's log level drops
     * anything quieter), by ids alone. A file is only ever called deleted once the disk says it is
     * gone, and only called kept once the disk says it is still there (remove()).
     */
    public static function forgetUnlinked(Masjid $masjid, mixed $before, mixed $after, int $sectionId): void
    {
        $ids = ['masjid_id' => $masjid->id, 'section_id' => $sectionId];

        try {
            $beforeStrings = self::strings($before);
            $afterStrings = self::strings($after);
            $afterSpellings = self::spellings($afterStrings);

            $unlinked = array_filter(
                self::addresses($beforeStrings),
                fn (string $path) => ! self::mentions($afterSpellings, $path),
                ARRAY_FILTER_USE_KEY
            );

            $documents = [];
            foreach ($unlinked as $path => [$mediaId, $storedName]) {
                $media = self::document($masjid, $mediaId, $storedName);

                if ($media !== null && ! self::linked($afterSpellings, $path, $media)) {
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
                $spellings = self::spellings(self::strings($content));
                $documents = array_filter(
                    $documents,
                    fn (Media $media, string $path) => ! self::linked($spellings, $path, $media),
                    ARRAY_FILTER_USE_BOTH
                );
            }

            if ($documents === []) {
                return;
            }

            if (self::linksADocumentThatIsGone($beforeStrings, $afterStrings)) {
                Log::warning(
                    'Page documents NOT removed: this save links a page document of ours that has been deleted, as a save from an out-of-date editor does',
                    $ids + ['media_ids' => array_values(array_map(fn (Media $media) => $media->id, $documents))]
                );

                return;
            }

            foreach ($documents as $media) {
                self::remove($media, $ids);
            }
        } catch (Throwable $e) {
            Log::warning('Page documents were not checked after a section was saved or deleted', $ids + [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * forgetUnlinked() for a section that was just SAVED, compared with what is stored now.
     *
     * A save writes the section's row and then does more (the placement, the images), in no
     * transaction. When a later step fails the office is answered 500, but the content is written:
     * the address is already gone from it, and the next save's "before" no longer carries it, so a
     * cleanup that ran only on success would leave that document online for good. Both controllers
     * therefore call this in a `finally` that starts once the content write has succeeded. It reads
     * the row itself rather than trust a model a failed step left half-updated, and like
     * forgetUnlinked() it cannot throw: in a `finally`, an exception from here would replace the one
     * the office has to be told about.
     */
    public static function forgetUnlinkedBySave(Masjid $masjid, mixed $before, int $sectionId): void
    {
        try {
            $stored = $masjid->sections()->whereKey($sectionId)->toBase()->first(['content']);

            if ($stored === null) {
                // Nothing to compare with. Not "the section links nothing now": that reading
                // would delete every document it linked.
                throw new RuntimeException('The saved section is not there to be read.');
            }
        } catch (Throwable $e) {
            Log::warning('Page documents were not checked after a section was saved or deleted', [
                'masjid_id' => $masjid->id,
                'section_id' => $sectionId,
                'exception' => $e::class,
            ]);

            return;
        }

        self::forgetUnlinked($masjid, $before, $stored->content, $sectionId);
    }

    /**
     * Delete one document, and say what happened to its FILE.
     *
     * The media library deletes the row first and removes the file afterwards, and a disk that
     * will not let a file go raises nothing: the public disk does not throw, and the library reports
     * what would. So "the delete returned" is not "the file is gone". The disk is asked. A file it
     * still holds is still public, with no row left for a later save to find it by, and the line
     * must say so: it is the only record there will be. A disk that cannot be asked at all gets a
     * line of its own, which claims neither: the file could not be checked and may still be online.
     *
     * One document at a time, each in its own try: a file that cannot be removed must not keep the
     * others online.
     *
     * @param  array{masjid_id: int, section_id: int}  $ids
     */
    private static function remove(Media $media, array $ids): void
    {
        $ids += ['media_id' => $media->id];

        try {
            // Read before the delete: the row cannot be asked afterwards.
            $disk = $media->disk;
            $file = $media->getPathRelativeToRoot();

            // Through the model, so the row and the file go together.
            $media->delete();
        } catch (Throwable $e) {
            Log::warning('Page document NOT deleted, and no saved section links it any more', $ids + [
                'exception' => $e::class,
            ]);

            return;
        }

        $held = self::onDisk($disk, $file);

        if ($held === null) {
            // Not "kept" and not "deleted": the disk was not able to say, so neither is known.
            Log::warning('Page document could NOT be checked: no saved section links it and its record is gone, but the disk could not be asked whether the file is still there, so it may still be online', $ids);

            return;
        }

        if ($held) {
            Log::warning('Page document NOT removed: no saved section links it and its record is gone, but the disk kept the file, which is still online', $ids);

            return;
        }

        Log::warning('Page document deleted: no saved section links it any more', $ids);
    }

    /**
     * Whether the disk still holds this file, or null when the disk could not be asked. The three
     * answers are three different lines: a file is called deleted only on a "no", and called kept
     * only on a "yes".
     */
    private static function onDisk(string $disk, string $file): ?bool
    {
        try {
            return Storage::disk($disk)->exists($file);
        } catch (Throwable) {
            return null;
        }
    }

    /** This organisation's own page document with this id AND this stored name, or nothing. */
    private static function document(Masjid $masjid, int $mediaId, string $storedName): ?Media
    {
        return $masjid->pageDocuments()->whereKey($mediaId)->where('file_name', $storedName)->first();
    }

    /**
     * Whether a save looks as if it came from an OUT-OF-DATE editor: its content brings in an
     * address the section did not have before, which THIS APPLICATION gave out, for a document
     * that has since been DELETED.
     *
     * That is what a second tab does. Two tabs have one section open, linking document X. One
     * replaces X with Y and saves, and X is deleted, as it should be. The other, still showing X,
     * saves: X's address comes back, Y's goes, and without this Y would be deleted too, leaving the
     * page linking a file that is gone and the office with neither. An editor is only ever handed
     * the address of a document that exists, so a new address of ours with nothing behind it is the
     * mark of an old copy. On such a save nothing is deleted. The link the old copy put back is
     * still dead (the save itself is not refused), but the current document is not lost with it.
     *
     * NARROWED to what an old copy can really hold, because keeping is not free: a document kept
     * here has left the section's content, so no later save has it in its "before", and it stays
     * public for good while the page tool said "taken offline when you save". The first rule was
     * "any address of this shape that is not one of this organisation's documents", which also
     * fired for an ordinary replace by another organisation's document, by the organisation's own
     * PDF in another collection, and by another site's address. So both of these must hold:
     *
     *  1. the address is one of OURS as it is written (writtenAsOurs()), and
     *  2. NO media row on the disk page documents live on (the public disk) has that id and that
     *     file name: the document it named is gone. A row of another organisation, or in another
     *     collection, is a file that exists, and putting its address in is an ordinary edit.
     *
     * ONLY THE PUBLIC DISK IS ASKED, because the answer shows: the administrator sees their own
     * document deleted or kept. That a public file answers at `/storage/{id}/{name}` is something
     * any visitor finds out by asking for it. Asked of every disk, the same save told an
     * administrator whether ANOTHER ORGANISATION'S PRIVATE file has a given number and name. A row
     * on a private disk is therefore not looked at: no file of that address is online, which is
     * all an old copy's dead link means.
     *
     * Anything else is an ordinary replace, and what it drops is cleaned up as usual. Read from the
     * content as written and percent-decoded once (decoded()), not in the wider spellings of
     * "still linked": an old copy holds the address as it was given, and reading `$before` more
     * widely would let a deletion through that this check stops today.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private static function linksADocumentThatIsGone(array $before, array $after): bool
    {
        $had = self::addresses(self::decoded($before));
        $ours = null;

        foreach (self::decoded($after) as $string) {
            if (! preg_match_all(self::ADDRESS, $string, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as [[$path, $at], [$mediaId], [$storedName]]) {
                if (isset($had[$path])) {
                    continue;
                }

                $ours ??= self::ourHosts();

                if (! self::writtenAsOurs(self::front($string, $at), $ours)) {
                    continue;
                }

                $online = Media::query()->whereKey((int) $mediaId)->where('file_name', $storedName)
                    ->where('disk', self::disk())->exists();

                if (! $online) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether an address of page-document shape is written as one of this application's, judged by
     * what stands in front of its path (`/storage/{id}/{name}.pdf`). One of:
     *
     *  - the public disk's own address, which is what every upload is answered with (by its host;
     *    http and https are one, since erring here only ever keeps a file);
     *  - the host this request came in on (the deployment answers to more than one, and the web
     *    server serves the same file on each);
     *  - no host at all (a bare path).
     *
     * An address on any other host is another site's, whatever number and name it carries. Only ever
     * compared, never stored (`.claude/rules/generated-urls.md` is about what is kept).
     *
     * @param  string  $front  what stands straight in front of the path (front())
     * @param  list<string>  $ours  ourHosts()
     */
    private static function writtenAsOurs(string $front, array $ours): bool
    {
        // The address's own `//host` and anything between that and the path, when the text in front
        // ends with one. A host belonging to a longer address the path is only a parameter of
        // (`https://viewer.example/view?url=/storage/3/x.pdf`) is not this address's host.
        if (! preg_match('~//([^/?\#\s"\'<>=&\\\\]+)((?:/[^?\#\s"\'<>=&]*)?)$~', $front, $match)) {
            return true;
        }

        $written = strtolower($match[1]) . rtrim($match[2], '/');

        return in_array($written, $ours, true);
    }

    /**
     * How far in front of a path its own `scheme://host` is looked for. A host name is at most 253
     * characters; with a scheme, a port and whatever path a deployment serves `/storage` under, an
     * address of ours is a few hundred at the very most. This is several times that.
     */
    private const FRONT_MAX = 2048;

    /**
     * The characters an address's own host and path cannot hold (writtenAsOurs()), so the nearest
     * of them ends what stands in front of a path: white space, and `? # " ' < > = &`.
     */
    private const ENDS_A_FRONT = " \t\n\v\f\r?#\"'<>=&";

    /**
     * What stands straight in front of the path that starts at `$at`: the text back to the nearest
     * character an address's host and path cannot hold. Only this can be the address's own host.
     *
     * NOT the whole text in front. That was read once for every address a save brought in, so a
     * text of many addresses cost the SQUARE of their number: 5,000 of them (250 KB) held the save
     * for fifteen seconds, after the content was written. Each address now costs what stands in
     * front of it, to a limit. Past the limit this THROWS, and the caller logs that the documents
     * were not checked and deletes nothing: an address that deep in unbroken text cannot be judged
     * cheaply, and calling it "not ours" unread would let a deletion through.
     */
    private static function front(string $string, int $at): string
    {
        // One line feed straight in front of the path is read past, as the pattern this replaced
        // read past it (its `$` matches before a final one), and as a browser drops it.
        if ($at > 0 && $string[$at - 1] === "\n") {
            $at--;
        }

        $window = substr($string, max(0, $at - self::FRONT_MAX - 1), min($at, self::FRONT_MAX + 1));
        $length = strcspn(strrev($window), self::ENDS_A_FRONT);

        if ($length > self::FRONT_MAX) {
            throw new RuntimeException('An address stands after more unbroken text than is read for its host.');
        }

        return $length === 0 ? '' : substr($window, -$length);
    }

    /**
     * The hosts (with whatever path stands before `/storage`) a page document of ours is served
     * under: the public disk's, and the one this request came in on, with and without its port.
     *
     * @return list<string>
     */
    private static function ourHosts(): array
    {
        $hosts = [];

        try {
            // `https://host/storage` as configured; what stands in front of `/storage` is the host.
            $disk = rtrim(Storage::disk(self::disk())->url(''), '/');
            if (preg_match('~^https?://(.+)/storage$~i', $disk, $match)) {
                $hosts[] = strtolower($match[1]);
            }
        } catch (Throwable) {
            // A disk with no address of its own gives out none.
        }

        try {
            $request = request();
            $hosts[] = strtolower($request->getHost());
            $hosts[] = strtolower($request->getHttpHost());
        } catch (Throwable) {
            // A Host the framework will not read names no host of ours.
        }

        return array_values(array_unique($hosts));
    }

    /** The disk page documents live on: the media library's own, which is the public one. */
    private static function disk(): string
    {
        return (string) config('media-library.disk_name');
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
     * Every way these strings are read when the question is "does this STILL LINK the file?": as
     * written, percent-decoded (decoded()), and each of those with the spellings a browser resolves
     * made plain (resolved()).
     *
     * For the KEEPING side only. A wrong "yes" here keeps a file online; a wrong "no" deletes one a
     * page still uses. What STARTS a deletion is read from the content as it was written
     * (addresses() of the strings themselves), and nothing here widens that.
     *
     * @param  list<string>  $strings
     * @return list<string>
     */
    private static function spellings(array $strings): array
    {
        $spellings = self::decoded($strings);

        foreach ($spellings as $string) {
            $resolved = self::resolved($string);

            if ($resolved !== $string) {
                $spellings[] = $resolved;
            }
        }

        return $spellings;
    }

    /**
     * These strings, and each of them percent-decoded where that reads differently.
     *
     * A link to a document viewer carries the document's address inside its own, encoded
     * (`https://viewer.example/view?url=https%3A%2F%2F...%2Fstorage%2F3%2Fcalendar.pdf`). That is a
     * link to the file all the same, and the page that carries it goes dead if the file is deleted.
     * Decoded once: an address encoded twice over is not found.
     *
     * @param  list<string>  $strings
     * @return list<string>
     */
    private static function decoded(array $strings): array
    {
        $spellings = $strings;

        foreach ($strings as $string) {
            $decoded = rawurldecode($string);

            if ($decoded !== $string) {
                $spellings[] = $decoded;
            }
        }

        return $spellings;
    }

    /**
     * A string with the spellings a browser resolves before it asks for a file made plain, so that
     * a link written any of these ways is seen to carry `/storage/{id}/{name}.pdf`:
     *
     *  - a tab or a line break inside the address, which a browser drops wherever it stands;
     *  - backslashes written for slashes (`https:\\host\storage\3\x.pdf`), which a browser reads as
     *    slashes;
     *  - a doubled slash (`/storage//3/x.pdf`), and JSON-escaped slashes copied out of a raw API
     *    answer (`https:\/\/host\/storage\/3\/x.pdf`), which a browser reads as doubled ones. Both
     *    are working links only where the web server merges slashes (its default; not seen on a
     *    server, ASSUMPTIONS.md PD-26);
     *  - `.` and `..` segments (`/storage/./3/x.pdf`, `/storage/old/../3/x.pdf`,
     *    `/storage/a/b/../../3/x.pdf`).
     *
     * Done to the whole text, which may be a paragraph: it is only ever SEARCHED for a path, never
     * shown or stored, and anything odd it does to prose can only keep a file. (The two slashes
     * after a scheme's colon become one with the rest; nothing reads the result as an address.)
     *
     * IN ONE PASS, whatever the text holds. This runs over every string of a section on every save,
     * and over every other section of the organisation when a save drops a document, and a section's
     * text has no size limit. It used to take one `..` step per pass over the whole text, so its cost
     * grew with the SQUARE of the number of steps: a text of 40,000 of them (195 KB) held each of
     * those saves for eleven seconds, and a larger one reached the request's time limit after the
     * content was written and before the cleanup ended. The text is cut at its slashes once and its
     * segments are walked once, a `..` stepping back over the segment before it.
     *
     * A `..` steps back over ANY segment, as a browser's does (one holding a space, an apostrophe, a
     * quote or an angle bracket included), except one that holds `?` or `#`: there a browser's path
     * has ended, and what follows is not resolved.
     *
     * STILL NOT SEEN, and this is all of it (ASSUMPTIONS.md PD-17). A save that drops the plain
     * address deletes the file while a link written one of these ways still reaches it:
     *
     *  - any character of the address written as an HTML character reference (`&#x2F;`, `&#47;`,
     *    `&sol;`, `&#46;`), which a browser decodes where the link stands in HTML;
     *  - the address percent-encoded twice or more (a link only through something that decodes it
     *    twice);
     *  - the path or `.PDF` in another letter case (the same file only where the file system
     *    ignores case, which these servers' does not);
     *  - a link that does not hold the path at all: a redirect, a short link, an address relative
     *    to another.
     */
    private static function resolved(string $string): string
    {
        // A tab or a line break is dropped wherever it stands, and a backslash is a slash.
        $segments = explode('/', str_replace(["\t", "\r", "\n", '\\'], ['', '', '', '/'], $string));
        // What stands before the first slash is no segment of a path, and nothing steps back over it.
        $front = array_shift($segments);
        $path = [];

        foreach ($segments as $segment) {
            // An empty segment is a doubled slash (or a JSON-escaped one, `\/`, read as two); `.` is
            // where it stands.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $last = end($path);

                if ($last === false) {
                    // Nothing to step back over: a browser stays at the root.
                    continue;
                }

                if ($last !== '..' && strpbrk($last, '?#') === false) {
                    array_pop($path);

                    continue;
                }
            }

            $path[] = $segment;
        }

        return $path === [] ? $front : $front . '/' . implode('/', $path);
    }

    /**
     * Whether these strings still link this document: by the address as it was written, or by the
     * document's own path. The two are the same string today, because ADDRESS takes an id in one
     * spelling only. The document's own path is asked as well so that this test, the last thing
     * between a save and a deleted file, does not rest on that pattern alone.
     *
     * @param  list<string>  $spellings
     */
    private static function linked(array $spellings, string $path, Media $media): bool
    {
        return self::mentions($spellings, $path)
            || self::mentions($spellings, "/storage/{$media->id}/{$media->file_name}");
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
