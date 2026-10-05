<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The META-TEST for upload file names, in the shape of `TenantScopingCoverageTest` and
 * `StagingScrubCoverageTest`.
 *
 * ## Why this file exists
 *
 * The media library keeps an uploaded file on the PUBLIC disk under the name the client
 * gave it, and the web server serves public/storage straight from disk, choosing the
 * Content-Type from the extension. A rule that checks what a file IS (`image`, `mimes`,
 * `mimetypes`) and not what it is CALLED therefore lets image bytes named `x.html` be
 * stored as `<media id>/x.html` and answered as a page on the application's own origin.
 *
 * The rule against that was written down on 2026-09-25 (`extensions:` beside `mimes:`) and
 * applied to the section uploads and the shop's pictures. On 2026-10-05 fifteen more
 * uploads were found without it, thirteen of them only because somebody went looking. A
 * rule a person has to remember is documentation, not a control. This file is a control
 * for the ordinary ways of writing an upload rule. It is NOT a proof that no upload door
 * is open: read "What this test cannot see" before trusting a green run.
 *
 * ## What it enforces
 *
 *  1. **An upload rule the scan can read names the file names it takes.** Every PHP file
 *     under `app/` is read (tokens, so comments are not rules). Each rule the scan takes
 *     for one that admits a file ("What the scan reads", below) must have `extensions:`
 *     BESIDE it. A rule added tomorrow in one of those shapes is in scope tomorrow, with
 *     no edit to this file. A rule written another way is not seen at all.
 *  2. **Or it says why it need not** (`NAME_NEVER_PUBLIC`): the file is stored under a
 *     name this application chooses, on a private disk behind a signed-in download, or
 *     not stored at all. Each entry carries its reason AND facts that can turn false (the
 *     disk a config key names, a line the storing code must still contain), so an
 *     exemption cannot outlive the thing that made it safe.
 *  3. **The scanner itself is tested**, by tables of source snippets and not by this
 *     prose: what it must report (`rulesTheScanMustReport`), what it must accept as
 *     pinned (`rulesTheScanMustAccept`), what is not a rule (`textThatIsNotARule`) and
 *     what it is known to miss (`whatTheScanCannotSee`). It also has a control
 *     (`the_scan_still_sees_the_rules_it_was_written_for`): a guard that has gone blind
 *     passes for ever. `uploadRules()` is public and static, so a snippet can be put to
 *     the scanner from anywhere.
 *
 * ## What the scan reads
 *
 *  - A string with `mimes:`, `mimetypes:`, `dimensions:` or `image:` in it, or with
 *    `image` or `file` as one of several `|` segments, wherever under `app/` it is
 *    written: a FormRequest, a trait, a controller, a constant, the literal half of a
 *    concatenation.
 *  - The ONE WORD `image` or `file`, but only where rules are plainly being written,
 *    because anywhere else it is an ordinary word. That is: as an element of a list that
 *    also holds a presence word (`required`, `nullable`, ...) or a rule with a colon; or,
 *    in one of the PLACES RULES ARE WRITTEN, as an element of any list, the whole of a
 *    field's rule, the whole of one branch of a ternary there, or the whole of an
 *    assignment. Those places are: a method with `rules` in its name, an array assigned
 *    to a variable with `rules` in its name, and the arguments of `validate(`,
 *    `validateWithBag(`, `validator(` or `Validator::make(`.
 *  - The framework's own fluent rules: `Rule::file()`, `Rule::imageFile()`,
 *    `Rule::dimensions()`, `File::types()`, `File::image()`, `File::default()`,
 *    `File::defaults()` (the same on `ImageFile`), `new File`, `new ImageFile` and
 *    `new Dimensions`, under whatever name the file's `use` lines give those classes.
 *  - A rule's NAME as Laravel reads it: without regard to capitals, `_`, `-` or spaces.
 *    `Image|Mimes:png` and `mime_types:video/mp4` are run as `image|mimes:png` and
 *    `mimetypes:video/mp4` (`a_rule_name_is_read_the_way_laravel_reads_it` shows both
 *    halves of that).
 *
 * ## "Beside it" is deliberately narrow
 *
 * `extensions:` counts in three places and nowhere else:
 *
 *  - in the SAME string literal as the rule, with its list written out in that literal
 *    (`'image|extensions:' . $kinds` is reported: this test cannot read `$kinds`);
 *  - as a WHOLE element of the SAME list array (`['image', 'extensions:png']`). A literal
 *    in one branch of a ternary, in a concatenation or among another call's arguments
 *    (`Rule::when(...)`) is not an element of the list and does not count;
 *  - for a fluent rule, `->extensions([...])` chained straight onto it with the list
 *    written out, or a whole element of the same list as above.
 *
 * A rule assembled from variables across statements is reported even when the assembled
 * result would be fine, because this test cannot see the assembled result. Write such a
 * field's rule whole (SaveMasjidAboutRequest does), or give the reason below.
 *
 * ## What this test cannot see
 *
 * Green means: every upload rule the scan can READ is pinned or exempt. Each shape below
 * passes this test with a door open. `whatTheScanCannotSee` holds one of each, shows that
 * the scan says nothing against it and, where the shape is a rule, puts real PNG bytes
 * named `x.html` to Laravel's validator under it and sees them accepted.
 *
 *  - A rule with no file word in it at all (`'photo' => 'nullable|max:25600'`).
 *  - The one word `image` or `file` anywhere but the places listed above: returned by a
 *    helper method with no `rules` in its name, held in a constant or a property, as one
 *    arm of a `match`, handed to another call
 *    (`$validator->sometimes('photo', 'image', ...)`), or in the arguments of a
 *    validator made another way (`app('validator')->make(...)`).
 *  - A rule that is not written as one piece of text: joined from two literals
 *    (`'required|' . 'image'`), read from `config()`, made by `sprintf()`, or a pinned
 *    string lengthened in a later statement.
 *  - The array form of a rule (`['mimes', 'jpg', 'png']`).
 *  - A custom rule object or a closure, and so any class that extends the framework's.
 *  - A pinned rule the request switches off (`exclude_if:...` in front of it), where the
 *    controller reads the file from the request and not from what was validated.
 *  - A rule outside `app/` (`routes/`, `config/`, a package): only `app/` is read
 *    (`the_scan_reads_app_and_nothing_else`).
 *  - An upload that is read with NO rule at all (`$request->file('x')` and nothing
 *    validating `x`).
 *  - A pinned upload whose STORED name comes from somewhere else
 *    (`->usingFileName($request->input('name'))`).
 *  - Whether a pinned list is a sensible one (`extensions:jpg,html` counts as pinned).
 *
 * So this test does not stand in for the door tests. A new upload to the public disk
 * still needs its own row in PublicUploadFileNameDoorsTest, which sends real bytes
 * through the real route and proves, for that door, that page-like names are refused and
 * that each kind of file an office may upload there is accepted. And it still needs a
 * reader: nothing here finds an upload that nobody wrote a rule for.
 *
 * ## If this test fails
 *
 * Read the failure: it names the file, the line and the rule. Add `extensions:` naming,
 * in lower case, what a real file of that field can be called (the rule lower-cases the
 * client's extension and not its own list), and a sentence that tells the person what the
 * name must end in. **Do not add an exemption to make it pass** unless the client's file
 * name truly cannot reach a public address, and then say how you know.
 */
final class UploadFileNameCoverageTest extends TestCase
{
    /**
     * ---------------------------------------------------------------------
     * EXEMPTIONS: rules that accept an upload and deliberately carry no
     * `extensions:`, because the client's file name never reaches a public address.
     * ---------------------------------------------------------------------
     *
     * Keyed by file, then by "<function>() <field or variable the rule is built in>", as
     * the failure message prints it. `facts` are checked by
     * `every_exemption_still_holds_for_the_reason_it_gives`:
     *
     *   ['disk', <config key>]         the disk that key names is not the public one;
     *   ['has', <file>, <text>]        the storing code still contains that text;
     *   ['lacks', <file>, <text>]      the storing code still does not.
     *
     * Read at 9e026457 (the audit of 2026-10-05) and again when this file was written.
     */
    private const NAME_NEVER_PUBLIC = [
        'app/Http/Requests/Admin/Assistant/AssistantChatRequest.php' => [
            'rules() image' => [
                'what' => 'A picture dropped into the assistant chat, which a tool may attach to an announcement.',
                'why' => 'Stored under a name the server chooses: the controller hands the tool the path of PHP\'s own temporary upload, and the media library names the stored file after that path (phpXXXXXX, no extension). The client\'s file name is never read.',
                'facts' => [
                    ['has', 'app/Http/Controllers/AdminDashboard/AssistantController.php', "'path' => \$file->getRealPath(),"],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/AssistantController.php', 'getClientOriginal'],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/AssistantController.php', 'addMediaFromRequest'],
                    ['lacks', 'app/Services/Assistant/ToolRegistry.php', 'getClientOriginal'],
                    ['lacks', 'app/Services/Assistant/ToolRegistry.php', 'usingFileName'],
                ],
            ],
        ],
        'app/Http/Requests/Admin/Broadcasts/StoreBroadcastRequest.php' => [
            'rules() block_images.*' => [
                'what' => 'The pictures inside a newsletter\'s blocks.',
                'why' => 'Stored under a name the server chooses: each picture is re-encoded and kept as <uuid>.<the extension its bytes say>, so nothing of the client\'s file name reaches the disk.',
                'facts' => [
                    ['has', 'app/Services/Broadcast/Newsletter/NewsletterPicture.php', "\$name = Str::uuid()->toString() . '.' . \$extension;"],
                    ['has', 'app/Services/Broadcast/BroadcastComposer.php', "->usingFileName(\$picture['name'])"],
                ],
            ],
        ],
        'app/Http/Requests/Admin/Studio/StoreStudioDraftLogoRequest.php' => [
            'rules() logo' => [
                'what' => 'The logo on a Manara Studio draft.',
                'why' => 'Kept on a private disk under a random name with an extension taken from the bytes, and served only through the SuperAdmin draft route. When the draft is provisioned the files reach the public disk under names the code fixes (logo.png, favicon.png, ...).',
                'facts' => [
                    ['disk', 'studio.logo.disk'],
                    ['has', 'app/Http/Controllers/AdminDashboard/StudioDraftsController.php', "Str::random(40) . '.' . (self::LOGO_EXTENSIONS[\$mime] ?? \$file->guessExtension())"],
                    ['has', 'app/Support/Studio/ApplyDraftLogo.php', '->usingFileName($fileName)'],
                ],
            ],
        ],
        'app/Http/Controllers/AdminDashboard/FlyerCutoutController.php' => [
            'validateUpload() image' => [
                'what' => 'The photo for a Flyer Studio flyer.',
                'why' => 'Kept on the private disk under <flyer id>-<random>.<the extension its bytes say>, and read back only through the signed-in flyer image route.',
                'facts' => [
                    ['has', 'app/Http/Controllers/AdminDashboard/FlyerCutoutController.php', "private const IMAGE_DISK = 'local';"],
                    ['has', 'app/Http/Controllers/AdminDashboard/FlyerCutoutController.php', "\$name = \$flyer->id . '-' . Str::random(20) . '.' . (\$file->extension() ?: 'jpg');"],
                    ['has', 'app/Http/Controllers/AdminDashboard/FlyerCutoutController.php', 'Storage::disk(self::IMAGE_DISK)'],
                ],
            ],
        ],
        'app/Http/Requests/Api/V1/Forms/SubmitFormResponseRequest.php' => [
            'rules() $each' => [
                'what' => 'Files attached to a public form (the only upload reachable without a staff login).',
                'why' => 'Kept on a private disk under a random name with an extension taken from the bytes, and sent back only as a signed-in admin download. The client\'s name is kept as text in the database.',
                'facts' => [
                    ['disk', 'forms.attachments.disk'],
                    ['has', 'app/Support/FormAttachments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                ],
            ],
        ],
        'app/Support/FormSchema.php' => [
            'rulesForField() $rules' => [
                'what' => 'A form\'s own file question: presence only, for the same upload as the entry above.',
                'why' => 'The same file as SubmitFormResponseRequest\'s bag: private disk, random name. What may be uploaded is settled there, not in a form\'s schema.',
                'facts' => [
                    ['disk', 'forms.attachments.disk'],
                    ['has', 'app/Support/FormAttachments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                ],
            ],
        ],
        'app/Http/Requests/Admin/Groups/GroupPostFormRequest.php' => [
            'imageRules() $each' => [
                'what' => 'Photos on a class feed post or a class conversation message.',
                'why' => 'Kept on a private disk under a random name with an extension taken from the bytes, and read back only through signed-in downloads that check the viewer.',
                'facts' => [
                    ['disk', 'groups.media.disk'],
                    ['has', 'app/Support/GroupPostAttachments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                    ['has', 'app/Support/GroupMessageAttachments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                ],
            ],
            'videoRules() $each' => [
                'what' => 'Videos on a class feed post or a class conversation message.',
                'why' => 'The same store as the photos: private disk, random name, extension from the bytes, signed-in playback only.',
                'facts' => [
                    ['disk', 'groups.media.disk'],
                    ['has', 'app/Support/GroupPostAttachments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                    ['has', 'app/Support/GroupMessageAttachments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                ],
            ],
        ],
        'app/Http/Requests/Teacher/StoreGroupResourceRequest.php' => [
            'rules() $file' => [
                'what' => 'A handout a teacher shares with a class.',
                'why' => 'Kept on a private disk under a random name with an extension taken from the bytes, and sent back only as a signed-in download.',
                'facts' => [
                    ['disk', 'groups.resources.disk'],
                    ['has', 'app/Support/GroupResourceFiles.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                ],
            ],
        ],
        'app/Http/Requests/Admin/Credentials/ContactCredentialFormRequest.php' => [
            'documentRules() return' => [
                'what' => 'A scanned credential or licence on a contact.',
                'why' => 'Kept on a private disk under a random name with an extension taken from the bytes, and sent back only as a signed-in admin download.',
                'facts' => [
                    ['disk', 'credentials.document.disk'],
                    ['has', 'app/Support/CredentialDocuments.php', "\$storedName = Str::random(40) . '.' . (\$file->extension() ?: 'bin');"],
                ],
            ],
        ],
        'app/Http/Requests/Admin/Groups/PreviewRosterImportRequest.php' => [
            'rules() file' => [
                'what' => 'The roster spreadsheet, at the preview step.',
                'why' => 'Not stored anywhere: PHP\'s temporary file is parsed in place and discarded.',
                'facts' => [
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'store('],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'storeAs('],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'putFile'],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'addMedia'],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', '->move('],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'Storage::'],
                ],
            ],
        ],
        'app/Http/Requests/Admin/Groups/CommitRosterImportRequest.php' => [
            'rules() file' => [
                'what' => 'The roster spreadsheet, at the import step.',
                'why' => 'Not stored anywhere: PHP\'s temporary file is parsed in place and discarded.',
                'facts' => [
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'store('],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'storeAs('],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'putFile'],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'addMedia'],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', '->move('],
                    ['lacks', 'app/Http/Controllers/AdminDashboard/RosterImportController.php', 'Storage::'],
                ],
            ],
        ],
    ];

    /**
     * Files in which the scan MUST find a pinned upload rule: a FormRequest's rule string,
     * a rule built in a trait, a rule list, and an inline `validate()` in a controller. If
     * the scan ever stops seeing these, it has gone blind and the main test proves nothing.
     */
    private const CONTROL = [
        'app/Http/Requests/Admin/Gallery/StoreGalleryImageRequest.php',
        'app/Http/Requests/Concerns/ValidatesVideoSection.php',
        'app/Http/Requests/Admin/Shop/UploadProductImagesRequest.php',
        'app/Http/Controllers/AdminDashboard/MealMenusController.php',
    ];

    /** A rule segment with one of these names and something after its colon admits a file. */
    private const FILE_RULES_WITH_PARAMETERS = ['mimes', 'mimetypes', 'dimensions', 'image'];

    /** A rule segment that IS one of these admits a file. */
    private const FILE_RULE_WORDS = ['image', 'file'];

    /** Beside a bare `image` or `file` in a list, any of these says the list is a rule. */
    private const PRESENCE_WORDS = ['required', 'nullable', 'sometimes', 'bail', 'present', 'filled'];

    /**
     * The framework's own rule classes that admit a file, by full name in lower case (PHP
     * does not tell `File` from `file`), and what this file calls each.
     */
    private const RULE_CLASSES = [
        'illuminate\validation\rule' => 'Rule',
        'illuminate\validation\rules\file' => 'File',
        'illuminate\validation\rules\imagefile' => 'ImageFile',
        'illuminate\validation\rules\dimensions' => 'Dimensions',
    ];

    /** The static calls on those classes that hand back a rule admitting a file. */
    private const RULE_FACTORIES = [
        'Rule' => ['file', 'imagefile', 'dimensions'],
        'File' => ['types', 'image', 'default', 'defaults'],
        'ImageFile' => ['types', 'image', 'default', 'defaults'],
    ];

    /** A dimensions rule has no `->extensions()` of its own; only its list can pin the name. */
    private const NO_EXTENSIONS_OF_ITS_OWN = ['Rule::dimensions', 'Dimensions'];

    /** `image`, as a constant holds it (the row "the one word, held in a constant"). */
    private const ONE_WORD = 'image';

    /** The smallest file finfo reads as a PDF. */
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    // =====================================================================
    // 1. Every upload rule the scan can read pins the name, or says why not.
    // =====================================================================

    #[Test]
    public function every_upload_rule_the_scan_can_read_pins_the_file_name_or_says_why_it_need_not(): void
    {
        $open = [];

        foreach ($this->appSources() as $path => $source) {
            foreach (self::uploadRules($source) as $rule) {
                if ($rule['pinned'] || isset(self::NAME_NEVER_PUBLIC[$path][$rule['id']])) {
                    continue;
                }

                $open[] = "{$path}:{$rule['line']}  {$rule['id']}  says `{$rule['says']}`";
            }
        }

        sort($open);

        $this->assertSame([], $open, sprintf(
            "%d rule(s) accept an uploaded file and say nothing about its NAME.\n\n"
            . "The media library keeps the client's file name on the public disk and the web server\n"
            . "serves it by its extension, so image bytes uploaded as `x.html` would be a page on this\n"
            . "application's own origin. Put `extensions:` beside the rule (in the same string, or as an\n"
            . "element of the same list), naming in LOWER CASE what a real file of that field can be called,\n"
            . "and give the field a sentence that says what the name must end in. Then add the door to\n"
            . "PublicUploadFileNameDoorsTest, which proves it with real bytes: this test only reads rules.\n\n"
            . "If the client's file name truly cannot reach a public address (the file is stored under a\n"
            . "name this application chooses, or on a private disk behind a signed-in download), add it to\n"
            . "NAME_NEVER_PUBLIC in this file, with the reason and with facts that would turn false if the\n"
            . "reason did.\n\n  - %s\n",
            count($open),
            implode("\n  - ", $open),
        ));
    }

    // =====================================================================
    // 2. An exemption is a claim, and the claim is checked.
    // =====================================================================

    #[Test]
    public function every_exemption_still_holds_for_the_reason_it_gives(): void
    {
        $problems = [];

        foreach (self::NAME_NEVER_PUBLIC as $path => $entries) {
            $source = is_file(base_path($path)) ? (string) file_get_contents(base_path($path)) : null;
            $unpinned = $source === null ? [] : array_column(
                array_filter(self::uploadRules($source), fn (array $rule) => ! $rule['pinned']),
                'id',
            );

            foreach ($entries as $id => $entry) {
                $name = "{$path}  {$id}";

                if ($source === null) {
                    $problems[] = "{$name}: exempted here but the file is gone. Delete the entry.";

                    continue;
                }

                if (! in_array($id, $unpinned, true)) {
                    $problems[] = "{$name}: exempted here, but the scan finds no unpinned upload rule there. "
                        . 'It was pinned, renamed or removed: delete the entry, or re-key it to what the scan now prints.';
                }

                if (trim($entry['what'] ?? '') === '' || trim($entry['why'] ?? '') === '') {
                    $problems[] = "{$name}: every exemption says WHAT the upload is and WHY its name cannot reach a public address.";
                }

                if (($entry['facts'] ?? []) === []) {
                    $problems[] = "{$name}: every exemption carries at least one fact that can turn false.";
                }

                foreach ($entry['facts'] ?? [] as $fact) {
                    if (($problem = $this->factProblem($fact)) !== null) {
                        $problems[] = "{$name}: {$problem} The stated reason is no longer shown to be true. "
                            . 'Re-read the storing code and re-decide; do not re-word.';
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Stale or unproven exemptions:\n  - " . implode("\n  - ", $problems));
    }

    // =====================================================================
    // 3. The scanner is itself tested, and has a control.
    // =====================================================================

    #[Test]
    public function the_scan_still_sees_the_rules_it_was_written_for(): void
    {
        foreach (self::CONTROL as $path) {
            $this->assertFileExists(base_path($path), "{$path} is a control for this scan and is gone. Name another file of the same shape.");

            $pinned = array_filter(
                self::uploadRules((string) file_get_contents(base_path($path))),
                fn (array $rule) => $rule['pinned'],
            );

            $this->assertNotEmpty($pinned, "The scan no longer finds a pinned upload rule in {$path}. "
                . 'Either the pin was removed (the main test says so too) or the scan has gone blind, '
                . 'in which case every other assertion in this file passes without looking.');
        }

        $this->assertArrayHasKey(self::CONTROL[0], $this->appSources(), 'The scan no longer reads app/ at all.');
    }

    #[Test]
    public function the_scan_reads_app_and_nothing_else(): void
    {
        $outside = array_filter(array_keys($this->appSources()), fn (string $path) => ! str_starts_with($path, 'app/'));

        $this->assertSame([], array_values($outside), 'The scan now reads files outside app/. Good: take "a rule outside app/" '
            . 'off the list under "What this test cannot see", here and in .claude/rules/section-types.md.');
    }

    /**
     * Upload rules with NO name pinned beside them, and what the scan must report for each
     * (the ids, in the order met). The rows marked "the review" are the shapes the second
     * review of 2026-10-05 walked through a real route with the door open while this test
     * stayed green.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function rulesTheScanMustReport(): array
    {
        $alias = 'use Illuminate\Validation\Rules\File as ImageRule;';

        return [
            // Rule strings and rule lists.
            'a rule string' => [self::inRules("'photo' => 'required|image|mimes:jpeg,png|max:5120',"), ['rules() photo']],
            'a bare image rule with a presence word' => [self::inRules("'photo' => 'nullable|image',"), ['rules() photo']],
            'a rule that is the one word' => [self::inRules("'photo' => 'image',"), ['rules() photo']],
            'the one word as a whole branch' => [self::inRules("'photo' => \$new ? 'image' : 'nullable',"), ['rules() photo']],
            'a rule list' => [self::inRules("'photo' => ['required', 'file', 'max:2048'],"), ['rules() photo']],
            'a rule list by sniffed type' => [self::inRules("'doc' => ['required', 'file', 'mimetypes:' . implode(',', \$types)],"), ['rules() doc']],
            'a rule built across statements' => [
                self::inRules("]; \$each = ['file']; \$each[] = 'mimetypes:' . implode(',', \$types); return ['docs.*' => \$each,"),
                ['rules() $each'],
            ],
            'a dimensions rule alone' => [self::inRules("'photo' => 'dimensions:min_width=96',"), ['rules() photo']],
            'one branch of two' => [
                self::inRules("'clip' => \$video ? 'nullable|file|mimetypes:video/mp4|extensions:mp4' : 'nullable|file|mimes:jpeg,png|max:1',"),
                ['rules() clip'],
            ],
            'a pin on the neighbouring field' => [
                self::inRules("'a' => 'image|mimes:png|extensions:png', 'b' => 'image|mimes:png',"),
                ['rules() b'],
            ],
            'the literal half of a concatenation' => [self::inRules("'photo' => \$presence . '|image|max:10',"), ['rules() photo']],
            'a rule held in a constant' => ["<?php class R { private const PHOTO = 'nullable|image|max:10'; }", ['() (expression)']],
            'an inline validation in a controller' => [
                "<?php class C { public function upload(\$request) { \$request->validate(['flyer' => 'file']); "
                    . "Validator::make(\$request->all(), ['doc' => ['file']]); } }",
                ['upload() flyer', 'upload() doc'],
            ],
            'rules held in a variable before they are validated' => [
                "<?php class C { public function store(\$request) { \$rules = ['photo' => 'image']; \$request->validate(\$rules); } }",
                ['store() photo'],
            ],
            'a rule added to such a variable' => [
                "<?php class C { public function store(\$request) { \$photoRules = []; \$photoRules['photo'] = 'file'; \$request->validate(\$photoRules); } }",
                ['store() $photoRules'],
            ],

            // A rule's name, as Laravel reads it.
            'capitals (the review)' => [self::inRules("'photo' => 'Image|Mimes:jpeg,png,jpg,gif,webp|max:25600',"), ['rules() photo']],
            'the one word in capitals' => [self::inRules("'photo' => 'IMAGE',"), ['rules() photo']],
            'a rule list in capitals' => [self::inRules("'photo' => ['Required', 'File'],"), ['rules() photo']],
            'a rule name with an underscore in it' => [self::inRules("'clip' => 'required|mime_types:video/mp4',"), ['rules() clip']],

            // A pin that is not a whole element of the list, or not a whole list.
            'the pin in one branch of a ternary (the review)' => [
                self::inRules("'photo' => ['bail', 'image', 'mimes:jpeg,png', \$this->boolean('strict') ? 'extensions:jpeg,jpg,png' : 'max:25600'],"),
                ['rules() photo'],
            ],
            'the pin behind a null check' => [self::inRules("'photo' => ['file', \$pin ?? 'extensions:png'],"), ['rules() photo']],
            'the pin among another call\'s arguments' => [self::inRules("'photo' => ['image', Rule::when(\$strict, 'extensions:png')],"), ['rules() photo']],
            'the pin with a variable list (the review)' => [self::inRules("'photo' => 'image|extensions:' . \$kinds,"), ['rules() photo']],
            'the pin with more of its list joined on' => [self::inRules("'photo' => 'image|extensions:png,' . \$more,"), ['rules() photo']],
            'the pin with an interpolated list' => [self::inRules("'photo' => \"image|extensions:{\$kinds}\","), ['rules() photo']],
            'a list whose pin has a variable list' => [self::inRules("'photo' => ['image', 'extensions:' . implode(',', \$kinds)],"), ['rules() photo']],
            'the pin joined on as a second literal' => [self::inRules("'photo' => 'required|image|' . 'extensions:png',"), ['rules() photo']],

            // The framework's fluent rules.
            'the fluent rule' => [self::inRules("'doc' => [Rule::file()->max(2048)],"), ['rules() doc']],
            'the fluent image rule' => [self::inRules("'photo' => File::image()->max(2048),"), ['rules() photo']],
            'the fluent rule under an alias (the review)' => [self::inRules("'photo' => ImageRule::image()->max(25600),", $alias), ['rules() photo']],
            'Rule under an alias' => [self::inRules("'doc' => R::file(),", 'use Illuminate\Validation\Rule as R;'), ['rules() doc']],
            'aliases from a grouped import' => [
                self::inRules("'doc' => Upload::types(['pdf']), 'photo' => Picture::image(),", 'use Illuminate\Validation\Rules\{File as Upload, ImageFile as Picture};'),
                ['rules() doc', 'rules() photo'],
            ],
            'the fluent rule by its full name' => [self::inRules("'photo' => \\Illuminate\\Validation\\Rules\\File::image(),"), ['rules() photo']],
            'the instantiated rule (the review)' => [self::inRules("'photo' => new ImageFile,"), ['rules() photo']],
            'the instantiated rule in brackets' => [self::inRules("'photo' => (new ImageFile)->max(10),"), ['rules() photo']],
            'the instantiated file rule in a list' => [self::inRules("'doc' => ['required', new File()],"), ['rules() doc']],
            'the instantiated rule under an alias' => [
                self::inRules("'photo' => new Picture,", 'use Illuminate\Validation\Rules\ImageFile as Picture;'),
                ['rules() photo'],
            ],
            'Rule::dimensions() (the review)' => [self::inRules("'photo' => Rule::dimensions()->maxWidth(9000),"), ['rules() photo']],
            'the instantiated dimensions rule' => [self::inRules("'photo' => new Dimensions(['min_width' => 96]),"), ['rules() photo']],
            'the default file rule (the review)' => [self::inRules("'doc' => File::default(),"), ['rules() doc']],
            'the default file rule by its other name' => [self::inRules("'doc' => [File::defaults()->max(10)],"), ['rules() doc']],
            'a fluent pin in one branch of two' => [
                self::inRules("'doc' => [\$loose ? Rule::file()->max(1) : Rule::file()->extensions(['pdf'])],"),
                ['rules() doc'],
            ],
            'a fluent pin with a variable list' => [self::inRules("'doc' => Rule::file()->extensions(\$kinds),"), ['rules() doc']],
            'a fluent pin applied only sometimes' => [
                self::inRules("'doc' => Rule::file()->when(\$strict, fn (\$rule) => \$rule->extensions(['pdf'])),"),
                ['rules() doc'],
            ],
        ];
    }

    #[Test]
    #[DataProvider('rulesTheScanMustReport')]
    public function the_scan_reports_an_upload_rule_with_no_name_pinned_beside_it(string $source, array $expected): void
    {
        $scan = self::uploadRules($source);
        $reported = array_values(array_unique(array_column(array_filter($scan, fn (array $rule) => ! $rule['pinned']), 'id')));

        $this->assertSame($expected, $reported, 'The scan read this source as ' . json_encode($scan));
    }

    /**
     * Upload rules with the name pinned BESIDE them, and the ids the scan must find (so
     * that "nothing reported" cannot mean "nothing seen").
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function rulesTheScanMustAccept(): array
    {
        return [
            'a pinned rule string' => [self::inRules("'photo' => 'bail|required|image|mimes:jpeg,png|extensions:jpeg,jpg,png|max:5120',"), ['rules() photo']],
            'a pinned rule list' => [self::inRules("'photo' => ['required', 'file', 'mimes:jpeg,png', 'extensions:jpeg,jpg,png'],"), ['rules() photo']],
            'a pinned bare image rule' => [self::inRules("'photo' => 'bail|nullable|image|extensions:jpeg,jpg,png,gif,bmp,webp',"), ['rules() photo']],
            'a rule written whole after a variable' => [self::inRules("'photo' => \$presence . '|image|mimes:png|extensions:png|max:1',"), ['rules() photo']],
            'a rule written whole after an interpolated variable' => [self::inRules("'photo' => \"{\$presence}|image|mimes:png|extensions:png\","), ['rules() photo']],
            'a pinned rule with its size joined on' => [
                self::inRules("'clip' => 'nullable|file|mimetypes:video/mp4|extensions:mp4|max:' . self::MAX_KB,"),
                ['rules() clip'],
            ],
            'two branches, each pinned' => [
                self::inRules("'clip' => \$video ? 'nullable|file|mimetypes:video/mp4|extensions:mp4' : 'nullable|file|mimes:jpeg,png|extensions:jpeg,jpg,png',"),
                ['rules() clip'],
            ],
            'capitals, pinned in capitals' => [self::inRules("'photo' => 'Image|Mimes:png|Extensions:png',"), ['rules() photo']],
            'a list pinned by a whole element, whatever else is in it' => [
                self::inRules("'photo' => ['bail', \$new ? 'required' : 'nullable', 'image', 'extensions:png', 'max:' . self::MAX_KB],"),
                ['rules() photo'],
            ],
            'the pinned fluent rule' => [self::inRules("'doc' => [Rule::file()->extensions(['pdf'])->max(2048)],"), ['rules() doc']],
            'the fluent rule pinned to one kind' => [self::inRules("'doc' => Rule::file()->max(2048)->extensions('pdf'),"), ['rules() doc']],
            'the fluent rule under an alias, pinned' => [
                self::inRules("'photo' => ImageRule::image()->extensions(['png'])->max(10),", 'use Illuminate\Validation\Rules\File as ImageRule;'),
                ['rules() photo'],
            ],
            'the instantiated rule, pinned' => [self::inRules("'photo' => (new ImageFile)->extensions(['png'])->max(10),"), ['rules() photo']],
            'the default file rule, pinned' => [self::inRules("'doc' => File::default()->extensions(['pdf']),"), ['rules() doc']],
            'a dimensions rule in a pinned list' => [
                self::inRules("'photo' => ['image', Rule::dimensions()->maxWidth(9000), 'extensions:png'],"),
                ['rules() photo'],
            ],
            // Setting the default is not a rule on a field; the rule it is given is read as any other.
            'the default file rule being set, pinned' => [
                "<?php class P { public function boot(): void { File::defaults(fn () => File::types(['pdf'])->extensions(['pdf'])); } }",
                ['boot() (expression)'],
            ],
        ];
    }

    #[Test]
    #[DataProvider('rulesTheScanMustAccept')]
    public function the_scan_accepts_an_upload_rule_with_the_name_pinned_beside_it(string $source, array $expected): void
    {
        $scan = self::uploadRules($source);

        $this->assertSame([], array_values(array_filter($scan, fn (array $rule) => ! $rule['pinned'])), 'The scan reported a pinned rule.');
        $this->assertSame($expected, array_values(array_unique(array_column($scan, 'id'))), 'The scan did not see the rule at all: ' . json_encode($scan));
    }

    /**
     * Text with the same words in it that is not an upload rule.
     *
     * @return array<string, array{string}>
     */
    public static function textThatIsNotARule(): array
    {
        return [
            'a comment' => [self::inRules("// 'photo' => 'required|image|mimes:jpeg,png',\n 'title' => 'required|string',")],
            'other rules' => [self::inRules("'title' => 'required|string|max:255', 'starts' => ['required', 'date'],")],
            'a field called image' => [self::inRules("'image' => 'required|string',")],
            'a file read from the request' => [
                self::inRules("'title' => \$this->file('image') ? 'required' : 'nullable', 'x' => \$this->hasFile('file') ? 'string' : 'integer',"),
            ],
            'a word a field is compared with' => [self::inRules("'alt' => \$this->input('kind') === 'image' ? 'required' : 'nullable',")],
            'the sentences a refusal reads' => [
                "<?php class R { public function messages(): array { return ['photo.image' => 'Upload an image.', 'photo.extensions' => 'Rename the file.']; } }",
            ],
            // Outside anything that builds rules, the words are just words.
            'ordinary vocabulary' => [<<<'PHP'
                <?php
                class Blocks
                {
                    public const TYPES = ['heading', 'text', 'image', 'file'];
                    private const MAP = ['image' => 'image'];

                    public function describe($block, $request)
                    {
                        $kind = $block['type'] === 'image' ? 'image' : 'file';
                        $rows = $this->model->with('image', 'file')->get();

                        return ['type' => 'image', 'file' => $request->file('image'), 'kinds' => ['image', 'file']];
                    }
                }
                PHP],
            // The `use` lines say which File a file means.
            'another class called File' => [
                self::inRules("'path' => new File(\$path), 'kind' => File::types(),", 'use Illuminate\Http\File;'),
            ],
            'the File facade' => [
                '<?php use Illuminate\Support\Facades\File; class C { public function sweep($dir) { File::delete(File::files($dir)); return File::image($dir); } }',
            ],
            'the default file rule being set' => [
                '<?php class P { public function boot(): void { File::defaults(fn () => $this->uploads()); } }',
            ],
        ];
    }

    #[Test]
    #[DataProvider('textThatIsNotARule')]
    public function the_scan_does_not_take_other_text_for_an_upload_rule(string $source): void
    {
        $this->assertSame([], self::uploadRules($source), 'The scanner took something else for an upload rule.');
    }

    /**
     * What passes this test WITH A DOOR OPEN: one row for each line under "What this test
     * cannot see" that can be written as a snippet. The closure, where there is one, puts
     * the shape to Laravel's validator with real PNG bytes named `x.html` and answers
     * whether they were let through.
     *
     * @return array<string, array{string, (Closure(UploadedFile): bool)|null}>
     */
    public static function whatTheScanCannotSee(): array
    {
        $under = fn (mixed $rule, array $beside = []): Closure => static fn (UploadedFile $page): bool => Validator::make(['photo' => $page] + $beside, ['photo' => $rule])->passes();

        return [
            'a rule with no file word in it at all' => [self::inRules("'photo' => 'nullable|max:25600',"), $under('nullable|max:25600')],

            'the one word, from a helper not named for rules' => [
                "<?php class R { public function rules(): array { return [...\$this->photoField()]; } private function photoField(): array { return ['photo' => 'image']; } }",
                $under('image'),
            ],
            'the one word, held in a constant' => [
                "<?php class R { private const PHOTO = 'image'; public function rules(): array { return ['photo' => self::PHOTO]; } }",
                $under(self::ONE_WORD),
            ],
            'the one word, held in a property' => ["<?php class C { protected \$rules = ['photo' => 'image']; }", $under('image')],
            'the one word, as one arm of a match' => [
                self::inRules("'photo' => match (true) { \$new => 'file', default => 'image' },"),
                $under(match (true) {
                    default => 'image',
                }),
            ],
            'the one word, handed to another call' => [
                "<?php class R { public function withValidator(\$validator): void { \$validator->sometimes('photo', 'image', fn () => true); } }",
                static function (UploadedFile $page): bool {
                    $validator = Validator::make(['photo' => $page], []);
                    $validator->sometimes('photo', 'image', fn () => true);

                    return $validator->passes();
                },
            ],
            'the one word, in a validator made another way' => [
                "<?php class C { public function store(\$request) { return app('validator')->make(\$request->all(), ['photo' => 'image'])->validate(); } }",
                static fn (UploadedFile $page): bool => app('validator')->make(['photo' => $page], ['photo' => 'image'])->passes(),
            ],

            'a rule joined from two literals' => [self::inRules("'photo' => 'required|' . 'image',"), $under('required|' . 'image')],
            'a rule read from config()' => [
                self::inRules("'photo' => config('uploads.photo'),"),
                static function (UploadedFile $page): bool {
                    config(['uploads.photo' => 'required|image|max:25600']);

                    return Validator::make(['photo' => $page], ['photo' => config('uploads.photo')])->passes();
                },
            ],
            'a rule made by sprintf()' => [self::inRules("'photo' => sprintf('required|%s', 'image'),"), $under(sprintf('required|%s', 'image'))],
            'a pinned string lengthened in a later statement' => [
                self::inRules("]; \$photo = 'image|extensions:png'; \$photo .= ',html'; return ['photo' => \$photo,"),
                $under('image|extensions:png' . ',html'),
            ],

            'the array form of a rule' => [self::inRules("'photo' => [['mimes', 'jpg', 'png']],"), $under([['mimes', 'jpg', 'png']])],

            'a custom rule object' => [
                self::inRules("'photo' => ['required', new RealPicture],"),
                $under(['required', new class implements ValidationRule
                {
                    public function validate(string $attribute, mixed $value, Closure $fail): void
                    {
                        if (! str_starts_with((string) $value->getMimeType(), 'image/')) {
                            $fail('The :attribute must be a picture.');
                        }
                    }
                }]),
            ],
            'a closure' => [
                self::inRules("'photo' => ['required', function (\$attribute, \$value, \$fail) { if (! \$value->isValid()) { \$fail('No.'); } }],"),
                $under(['required', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value->isValid()) {
                        $fail('No.');
                    }
                }]),
            ],

            'a pinned rule the request switches off' => [
                self::inRules("'photo' => 'exclude_if:keep,1|image|extensions:png',"),
                $under('exclude_if:keep,1|image|extensions:png', ['keep' => 1]),
            ],

            'an upload read with no rule at all' => [
                "<?php class C { public function store(\$request, \$model) { \$model->addMediaFromRequest('photo')->toMediaCollection('photos'); } }",
                static fn (UploadedFile $page): bool => Validator::make(['photo' => $page], [])->passes(),
            ],
            'a pinned upload whose stored name comes from another input' => [
                "<?php class C { public function store(\$request, \$model) { \$request->validate(['photo' => 'bail|required|image|mimes:png|extensions:png']); "
                    . "\$model->addMediaFromRequest('photo')->usingFileName(\$request->input('name'))->toMediaCollection('photos'); } }",
                // Nothing to put to the validator: the rule is sound and the name is not the file's.
                null,
            ],
            'a pinned list that names a page' => [
                self::inRules("'photo' => 'bail|image|extensions:jpg,html|max:25600',"),
                $under('bail|image|extensions:jpg,html|max:25600'),
            ],
        ];
    }

    #[Test]
    #[DataProvider('whatTheScanCannotSee')]
    public function the_scan_says_nothing_against_a_shape_this_file_lists_as_unseen(string $source, ?Closure $letsAPageNameThrough): void
    {
        $reported = array_values(array_filter(self::uploadRules($source), fn (array $rule) => ! $rule['pinned']));

        $this->assertSame([], $reported, 'The scan now REPORTS this shape. Good: move the row to rulesTheScanMustReport and take the '
            . 'shape off the list under "What this test cannot see", here, in .claude/rules/section-types.md and in DECISIONS.md.');

        if ($letsAPageNameThrough === null) {
            return;
        }

        $this->assertFalse(
            Validator::make(['photo' => $this->pngNamed('x.html')], ['photo' => 'bail|image|mimes:png|extensions:png'])->passes(),
            'PREMISE: a rule with the name pinned refuses PNG bytes named x.html.',
        );
        $this->assertTrue($letsAPageNameThrough($this->pngNamed('x.html')), 'This shape refuses PNG bytes named x.html after all. '
            . 'It is not an open door: take it off the list under "What this test cannot see".');
    }

    #[Test]
    public function a_rule_name_is_read_the_way_laravel_reads_it(): void
    {
        // Laravel makes a method name of a rule's name with Str::studly() (which drops `_`,
        // `-` and spaces) and PHP finds a method without regard to case, so each of these is
        // RUN as the rule on its right. Shown by a value that rule refuses: a PDF, or for
        // `file` a plain string.
        $written = [
            'Image' => 'image',
            'IMAGE' => 'image',
            'i_mage' => 'image',
            'File' => 'file',
            'Mimes:png' => 'mimes:png',
            'MIMES:png' => 'mimes:png',
            'mime_types:image/png' => 'mimetypes:image/png',
            'Mime-Types:image/png' => 'mimetypes:image/png',
            'Dimensions:min_width=1' => 'dimensions:min_width=1',
        ];

        foreach ($written as $as => $rule) {
            $refused = $rule === 'file' ? 'not a file' : $this->upload('notice.pdf', self::PDF);

            $this->assertTrue(Validator::make(['photo' => $refused], ['photo' => $rule])->fails(), "PREMISE: `{$rule}` refuses this value.");
            $this->assertTrue(Validator::make(['photo' => $refused], ['photo' => $as])->fails(), "Laravel did not run `{$as}` as `{$rule}`.");

            $this->assertSame(
                array_column(self::uploadRules(self::inRules("'photo' => 'required|{$rule}',")), 'pinned', 'id'),
                array_column(self::uploadRules(self::inRules("'photo' => 'required|{$as}',")), 'pinned', 'id'),
                "The scan does not read `{$as}` as it reads `{$rule}`.",
            );
            $this->assertSame(['rules() photo' => false], array_column(self::uploadRules(self::inRules("'photo' => 'required|{$as}',")), 'pinned', 'id'));
        }

        // The same for the pin: Laravel holds the name to `Extensions:png`, so the scan counts it.
        foreach (['Extensions:png', 'EXTENSIONS:png'] as $pin) {
            $this->assertTrue(Validator::make(['photo' => $this->pngNamed('x.html')], ['photo' => "image|{$pin}"])->fails(), "Laravel did not run `{$pin}`.");
            $this->assertTrue(Validator::make(['photo' => $this->pngNamed('x.png')], ['photo' => "image|{$pin}"])->passes());
            $this->assertSame(['rules() photo' => true], array_column(self::uploadRules(self::inRules("'photo' => 'image|{$pin}',")), 'pinned', 'id'));
        }

        // An empty list is not a pin: it lets through a name with no extension at all.
        $this->assertTrue(Validator::make(['photo' => $this->pngNamed('x')], ['photo' => 'image|extensions:'])->passes());
        $this->assertSame(['rules() photo' => false], array_column(self::uploadRules(self::inRules("'photo' => 'image|extensions:',")), 'pinned', 'id'));
    }

    /** A FormRequest whose rules() returns `[ $body ]`, under those `use` lines. */
    private static function inRules(string $body, string $imports = ''): string
    {
        return "<?php {$imports} class R { public function rules(): array { return [ {$body} ]; } }";
    }

    /** Real PNG bytes (GD) under the name a client gave them. */
    private function pngNamed(string $name): UploadedFile
    {
        ob_start();
        imagepng(imagecreatetruecolor(8, 8));

        return $this->upload($name, (string) ob_get_clean());
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    // =====================================================================
    // The scan.
    // =====================================================================

    /**
     * Every PHP file under app/, keyed by its path from the project root.
     *
     * @return array<string, string>
     */
    private function appSources(): array
    {
        $sources = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = 'app/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(app_path()))), '/');
                $sources[$path] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($sources);

        return $sources;
    }

    /**
     * Every rule in a PHP source that the scan can read as one that admits an uploaded
     * file. Public and static so that any test can put a snippet to it.
     *
     * `id` is "<function>() <label>", the label being the field the rule is keyed by, or
     * the variable it is being built in, or `return`. `pinned` is whether `extensions:`
     * sits beside it, as the class docblock defines "beside".
     *
     * @return list<array{id: string, line: int, says: string, pinned: bool}>
     */
    public static function uploadRules(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            fn (PhpToken $token) => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML]),
        ));

        $position = 0;
        $found = [];
        $context = ['function' => '', 'validating' => false, 'array' => false, 'label' => '', 'imports' => self::imports($tokens)];
        self::read(self::nest($tokens, $position, ''), $context, $found);

        return $found;
    }

    /**
     * What the `use` lines above a file's first class call each class they import:
     * `[the name used in the file => the full name]`, both in lower case.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

        for ($i = 0; $i < count($tokens); $i++) {
            // Past the first declaration, `use` is a trait or a closure's, not an import.
            if ($tokens[$i]->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM, T_FUNCTION, T_FN])) {
                break;
            }

            if (! $tokens[$i]->is([T_USE])) {
                continue;
            }

            $prefix = '';
            for ($i++; $i < count($tokens) && self::mark($tokens[$i]) !== ';'; $i++) {
                $token = $tokens[$i];
                $next = $tokens[$i + 1] ?? null;

                if ($token->is([T_FUNCTION, T_CONST])) {
                    // `use function strlen;`: not a class. Step over its name.
                    $i++;
                } elseif (self::mark($token) === '}') {
                    $prefix = '';
                } elseif ($token->is($names) && $next?->is([T_NS_SEPARATOR])) {
                    // `use Some\Space\{A, B as C};`
                    $prefix = ltrim($token->text, '\\') . '\\';
                } elseif ($token->is($names)) {
                    $full = $prefix . ltrim($token->text, '\\');
                    $local = substr((string) strrchr('\\' . $full, '\\'), 1);

                    if ($next?->is([T_AS]) && ($tokens[$i + 2] ?? null)?->is([T_STRING])) {
                        $local = $tokens[$i + 2]->text;
                        $i += 2;
                    }

                    $imports[strtolower($local)] = strtolower($full);
                }
            }
        }

        return $imports;
    }

    /**
     * Which of the framework's file rule classes a name in the source means, if any.
     *
     * @param  array<string, string>  $imports
     */
    private static function ruleClass(string $name, array $imports): ?string
    {
        $name = strtolower($name);

        if ($name[0] === '\\') {
            return self::RULE_CLASSES[substr($name, 1)] ?? null;
        }

        [$first, $rest] = array_pad(explode('\\', $name, 2), 2, null);

        if (isset($imports[$first])) {
            return self::RULE_CLASSES[$imports[$first] . ($rest === null ? '' : '\\' . $rest)] ?? null;
        }

        // Not imported, so it is a class of the file's own namespace, which this scan does
        // not know. Go by the last part of the name: better a rule reported than one missed.
        $short = substr((string) strrchr('\\' . $name, '\\'), 1);

        foreach (self::RULE_CLASSES as $full => $class) {
            if (str_ends_with($full, '\\' . $short)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Tokens as a tree: everything between a bracket and its partner becomes one node.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{open: string, children: list<PhpToken|array<string, mixed>>}
     */
    private static function nest(array $tokens, int &$position, string $open): array
    {
        $node = ['open' => $open, 'children' => []];

        while ($position < count($tokens)) {
            $token = $tokens[$position++];
            $mark = self::mark($token);

            if ($token->is([T_ATTRIBUTE])) {
                $node['children'][] = self::nest($tokens, $position, '#[');
            } elseif (in_array($mark, ['[', '(', '{'], true)) {
                $node['children'][] = self::nest($tokens, $position, $mark);
            } elseif ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $node['children'][] = self::nest($tokens, $position, '{');
            } elseif (in_array($mark, [']', ')', '}'], true)) {
                return $node;
            } else {
                $node['children'][] = $token;
            }
        }

        return $node;
    }

    /**
     * @param  array{open: string, children: list<PhpToken|array<string, mixed>>}  $node
     * @param  array{function: string, validating: bool, array: bool, label: string, imports: array<string, string>}  $context
     * @param  list<array{id: string, line: int, says: string, pinned: bool}>  $found
     */
    private static function read(array $node, array $context, array &$found): void
    {
        $children = $node['children'];
        $roles = $context['array'] ? self::roles($children) : [];
        $validating = $context['validating'] || stripos($context['function'], 'rules') !== false;

        // What the unkeyed elements of this list say together. `extensions:` pins the list
        // only as a WHOLE element of it: a literal in one branch of a ternary or in a
        // concatenation shares its element with something this scan cannot weigh.
        $list = [];
        $listPinned = false;
        foreach ($roles as $index => $role) {
            if ($role['is'] === 'list' && $children[$index] instanceof PhpToken && ($text = self::literal($children[$index])) !== null) {
                $list[] = $text;
                $listPinned = $listPinned || ($role['alone'] && self::pinsTheName($children, $index));
            }
        }
        $listIsRules = $validating || self::anySegment($list, fn (array $segment) => $segment['parameters'] === null
            ? in_array($segment['name'], self::PRESENCE_WORDS, true)
            : preg_match('/^[a-z]+$/', $segment['name']) === 1);

        $pendingFunction = null;

        foreach ($children as $index => $child) {
            $role = $roles[$index] ?? ['is' => 'free', 'key' => '', 'alone' => false];
            $label = match ($role['is']) {
                'keyed' => $role['key'],
                'list' => $context['label'],
                default => self::statementTarget($children, $index),
            };
            $id = $context['function'] . '() ' . $label;
            $inPinnedList = $role['is'] === 'list' && $listPinned;

            if (is_array($child)) {
                // `(new ImageFile)->extensions([...])`: the rule is inside the brackets and
                // what pins it is chained on outside them.
                if (($fluent = self::bracketedNewRule($children, $index, $context['imports'])) !== null) {
                    $found[] = ['id' => $id, 'line' => $fluent['line'], 'says' => $fluent['says'], 'pinned' => $fluent['pinned'] || $inPinnedList];

                    continue;
                }

                $inner = $context;
                $inner['array'] = self::isArrayLiteral($children, $index);
                $inner['label'] = $inner['array'] ? $label : $context['label'];
                $inner['validating'] = $validating
                    || ($child['open'] === '(' && self::isValidationCall($children, $index))
                    || ($inner['array'] && $role['is'] === 'free' && self::isRulesVariable($label));

                if ($child['open'] === '{' && $pendingFunction !== null) {
                    $inner['function'] = $pendingFunction;
                    $inner['validating'] = $context['validating'];
                    $pendingFunction = null;
                }

                self::read($child, $inner, $found);

                continue;
            }

            if ($child->is([T_FUNCTION]) && ($children[$index + 1] ?? null) instanceof PhpToken && $children[$index + 1]->is([T_STRING])) {
                $pendingFunction = $children[$index + 1]->text;
            } elseif (self::mark($child) === ';') {
                $pendingFunction = null;
            }

            if ($role['is'] === 'key') {
                continue;
            }

            // Rule::file(), File::image(), new ImageFile and their like.
            if (($fluent = self::fluentFileRule($children, $index, $context['imports'])) !== null) {
                $found[] = ['id' => $id, 'line' => $child->line, 'says' => $fluent['says'], 'pinned' => $fluent['pinned'] || $inPinnedList];

                continue;
            }

            if (($text = self::literal($child)) === null) {
                continue;
            }

            $segments = self::segments($text);
            $withParameters = self::anySegment([$text], fn (array $segment) => $segment['parameters'] !== null
                && in_array($segment['name'], self::FILE_RULES_WITH_PARAMETERS, true));
            $worded = self::anySegment([$text], fn (array $segment) => $segment['parameters'] === null
                && in_array($segment['name'], self::FILE_RULE_WORDS, true));

            // `mimes:...` anywhere, or `image` / `file` as one segment of several, is a rule
            // wherever it is written. The bare word alone is a rule only where rules are
            // built: among the elements of a rule list, or as the whole of a field's rule
            // (or of one branch of it) inside a rules method or a validation call.
            $isRule = $withParameters || ($worded && count($segments) > 1) || ($worded && match ($role['is']) {
                'list' => $listIsRules,
                'keyed' => $validating && ($role['alone'] || self::isWholeBranch($children, $index)),
                default => ($validating || self::isRulesVariable($label)) && self::isWholeAssignment($children, $index),
            });

            if ($isRule) {
                $found[] = [
                    'id' => $id,
                    'line' => $child->line,
                    'says' => $text,
                    'pinned' => self::pinsTheName($children, $index) || $inPinnedList,
                ];
            }
        }
    }

    /**
     * For the children of an array literal: is each one part of a key, part of a keyed
     * value, or an unkeyed element of the list? `alone` says the token is the whole of its
     * element (or of its value).
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     * @return array<int, array{is: string, key: string, alone: bool}>
     */
    private static function roles(array $children): array
    {
        $roles = [];
        $item = [];

        $close = function () use (&$roles, &$item, $children): void {
            $arrow = null;
            foreach ($item as $index) {
                if ($children[$index] instanceof PhpToken && $children[$index]->is([T_DOUBLE_ARROW])) {
                    $arrow = $index;
                    break;
                }
            }

            if ($arrow === null) {
                foreach ($item as $index) {
                    $roles[$index] = ['is' => 'list', 'key' => '', 'alone' => count($item) === 1];
                }
            } else {
                $key = '';
                foreach ($item as $index) {
                    if ($index < $arrow) {
                        $key .= $children[$index] instanceof PhpToken ? (self::literal($children[$index]) ?? $children[$index]->text) : '…';
                    }
                }

                $values = array_filter($item, fn (int $index) => $index > $arrow);
                foreach ($item as $index) {
                    $roles[$index] = $index <= $arrow
                        ? ['is' => 'key', 'key' => $key, 'alone' => false]
                        : ['is' => 'keyed', 'key' => $key, 'alone' => count($values) === 1];
                }
            }

            $item = [];
        };

        foreach ($children as $index => $child) {
            if ($child instanceof PhpToken && self::mark($child) === ',') {
                $close();
                $roles[$index] = ['is' => 'key', 'key' => '', 'alone' => false];

                continue;
            }

            $item[] = $index;
        }
        $close();

        return $roles;
    }

    /**
     * `[` opens an array literal unless what stands before it can be indexed.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function isArrayLiteral(array $children, int $index): bool
    {
        $node = $children[$index];
        $before = $children[$index - 1] ?? null;

        if ($node['open'] === '(') {
            return $before instanceof PhpToken && $before->is([T_ARRAY]);
        }

        if ($node['open'] !== '[') {
            return false;
        }

        if (is_array($before)) {
            return $before['open'] === '{';
        }

        return ! ($before instanceof PhpToken && $before->is([T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, T_STATIC]));
    }

    /**
     * `validate(`, `validateWithBag(`, `validator(`, `Validator::make(`.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function isValidationCall(array $children, int $index): bool
    {
        $name = $children[$index - 1] ?? null;
        if (! $name instanceof PhpToken || ! $name->is([T_STRING])) {
            return false;
        }

        if (in_array(strtolower($name->text), ['validate', 'validatewithbag', 'validator'], true)) {
            return true;
        }

        $scope = $children[$index - 3] ?? null;

        return strtolower($name->text) === 'make' && $scope instanceof PhpToken && $scope->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && str_ends_with(strtolower($scope->text), 'validator');
    }

    /** `$rules`, `$photoRules`: what a statement assigns to is a variable named for rules. */
    private static function isRulesVariable(string $label): bool
    {
        return str_starts_with($label, '$') && stripos($label, 'rules') !== false;
    }

    /**
     * `$rules[] = 'file';` : the literal is all there is to the right of the `=`.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function isWholeAssignment(array $children, int $index): bool
    {
        $before = $children[$index - 1] ?? null;
        $after = $children[$index + 1] ?? null;

        return $before instanceof PhpToken && self::mark($before) === '='
            && $after instanceof PhpToken && self::mark($after) === ';';
    }

    /**
     * `'photo' => $new ? 'image' : 'nullable'` : the literal is all there is to one branch
     * of a ternary. A word a field is compared with (`$kind === 'image' ? ... : ...`) is not.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function isWholeBranch(array $children, int $index): bool
    {
        $before = $children[$index - 1] ?? null;
        $after = $children[$index + 1] ?? null;

        return $before instanceof PhpToken && in_array(self::mark($before), ['?', ':'], true)
            && ($after === null || ($after instanceof PhpToken && in_array(self::mark($after), [':', ','], true)));
    }

    /**
     * What the statement around this child assigns to: `$each`, `return`, or nothing known.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function statementTarget(array $children, int $index): string
    {
        $start = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            $child = $children[$i];
            if (is_array($child) ? $child['open'] === '{' : self::mark($child) === ';') {
                $start = $i + 1;
                break;
            }
        }

        // `case 'file': $rules[] = 'file';` : the statement starts after the case's colon.
        if (($children[$start] ?? null) instanceof PhpToken && $children[$start]->is([T_CASE, T_DEFAULT])) {
            for ($i = $start; $i < $index; $i++) {
                if ($children[$i] instanceof PhpToken && self::mark($children[$i]) === ':') {
                    $start = $i + 1;
                    break;
                }
            }
        }

        $first = $children[$start] ?? null;

        return match (true) {
            $first instanceof PhpToken && $first->is([T_VARIABLE]) => $first->text,
            $first instanceof PhpToken && $first->is([T_RETURN]) => 'return',
            default => '(expression)',
        };
    }

    /**
     * One of the framework's fluent file rules starting at this token: a static call
     * (`Rule::file()`, `File::image()`, `Rule::dimensions()`) or `new File`.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     * @param  array<string, string>  $imports
     * @return array{says: string, pinned: bool}|null
     */
    private static function fluentFileRule(array $children, int $index, array $imports): ?array
    {
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $first = $children[$index];
        $second = $children[$index + 1] ?? null;

        if ($first->is([T_NEW]) && $second instanceof PhpToken && $second->is($names)) {
            $class = self::ruleClass($second->text, $imports);

            if (! in_array($class, ['File', 'ImageFile', 'Dimensions'], true)) {
                return null;
            }

            return [
                'says' => "new {$second->text}",
                'pinned' => ! in_array($class, self::NO_EXTENSIONS_OF_ITS_OWN, true) && self::chainPinsTheName($children, $index + 2),
            ];
        }

        $method = $children[$index + 2] ?? null;
        $arguments = $children[$index + 3] ?? null;

        // The method is read by its text: after `::` the tokenizer hands back `default` as
        // the keyword, not as a name.
        if (! $first->is($names) || ! $second instanceof PhpToken || ! $second->is([T_DOUBLE_COLON])
            || ! $method instanceof PhpToken || ! is_array($arguments) || $arguments['open'] !== '(') {
            return null;
        }

        $class = self::ruleClass($first->text, $imports);
        $call = strtolower($method->text);

        if ($class === null || ! in_array($call, self::RULE_FACTORIES[$class] ?? [], true)) {
            return null;
        }

        // `File::defaults($callback)` SETS the default and hands nothing back; the rule in
        // the callback is read where it is written. `File::defaults()` hands the default back.
        if ($call === 'defaults' && $arguments['children'] !== []) {
            return null;
        }

        return [
            'says' => "{$first->text}::{$method->text}()",
            'pinned' => ! in_array("{$class}::{$call}", self::NO_EXTENSIONS_OF_ITS_OWN, true) && self::chainPinsTheName($children, $index + 3),
        ];
    }

    /**
     * `(new ImageFile)` or `(new File())` standing as a value: the same rule, written in
     * brackets so that calls can be chained onto it.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     * @param  array<string, string>  $imports
     * @return array{says: string, pinned: bool, line: int}|null
     */
    private static function bracketedNewRule(array $children, int $index, array $imports): ?array
    {
        $node = $children[$index];
        $before = $children[$index - 1] ?? null;
        $first = $node['children'][0] ?? null;

        // Brackets after a name or after other brackets are a call's arguments, not a value.
        if ($node['open'] !== '(' || ! $first instanceof PhpToken || ! $first->is([T_NEW]) || is_array($before)
            || ($before instanceof PhpToken && $before->is([T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC, T_ARRAY]))) {
            return null;
        }

        if (($fluent = self::fluentFileRule($node['children'], 0, $imports)) === null) {
            return null;
        }

        $chains = ! in_array(substr($fluent['says'], 4), self::NO_EXTENSIONS_OF_ITS_OWN, true);

        return [
            'says' => $fluent['says'],
            'pinned' => $fluent['pinned'] || ($chains && self::chainPinsTheName($children, $index + 1)),
            'line' => $first->line,
        ];
    }

    /**
     * Is `->extensions(...)` chained straight on from here, with its list written out?
     * The chain is calls and nothing else: it ends at the first `,`, `;`, `?` or `:`, so a
     * pin in the other branch of a ternary is not this rule's.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function chainPinsTheName(array $children, int $from): bool
    {
        $i = $from;

        while (true) {
            $child = $children[$i] ?? null;

            if (is_array($child) && $child['open'] === '(') {
                $i++;

                continue;
            }

            if (! $child instanceof PhpToken || ! $child->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                return false;
            }

            $name = $children[$i + 1] ?? null;
            $arguments = $children[$i + 2] ?? null;

            if ($name instanceof PhpToken && strtolower($name->text) === 'extensions'
                && is_array($arguments) && $arguments['open'] === '(' && self::isWrittenOutList($arguments)) {
                return true;
            }

            $i += 2;
        }
    }

    /**
     * `('pdf')` or `(['jpg', 'png'])`: string literals and nothing else.
     *
     * @param  array{open: string, children: list<PhpToken|array<string, mixed>>}  $arguments
     */
    private static function isWrittenOutList(array $arguments): bool
    {
        $inside = $arguments['children'];

        if (count($inside) === 1 && is_array($inside[0]) && $inside[0]['open'] === '[') {
            $inside = $inside[0]['children'];
        }

        $strings = 0;
        foreach ($inside as $child) {
            if (! $child instanceof PhpToken) {
                return false;
            }

            if ($child->is([T_CONSTANT_ENCAPSED_STRING])) {
                $strings++;
            } elseif (self::mark($child) !== ',') {
                return false;
            }
        }

        return $strings > 0;
    }

    /**
     * Does this literal hold `extensions:` with its list written out in it?
     *
     * The first and the last segment of a literal can be continued by whatever the literal
     * is joined to (`'image|extensions:png,' . $more`), so `extensions:` counts there only
     * when nothing is joined on that side. An empty list is no pin.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function pinsTheName(array $children, int $index): bool
    {
        $segments = self::segments((string) self::literal($children[$index]));
        $last = count($segments) - 1;

        foreach ($segments as $position => $segment) {
            if ($segment['name'] !== 'extensions' || trim((string) $segment['parameters']) === '') {
                continue;
            }

            if (($position === 0 && self::isJoined($children, $index, -1)) || ($position === $last && self::isJoined($children, $index, 1))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Is something joined onto this literal on that side (`-1` before it, `1` after it): a
     * `.`, or, for a plain stretch of an interpolated string, anything but the quote that
     * ends the string?
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private static function isJoined(array $children, int $index, int $side): bool
    {
        $beside = $children[$index + $side] ?? null;

        if ($children[$index]->is([T_ENCAPSED_AND_WHITESPACE])) {
            if (! $beside instanceof PhpToken || ! ($beside->text === '"' || $beside->is([T_START_HEREDOC, T_END_HEREDOC]))) {
                return true;
            }

            $beside = $children[$index + 2 * $side] ?? null;
        }

        return $beside instanceof PhpToken && (self::mark($beside) === '.' || $beside->is([T_CONCAT_EQUAL]));
    }

    /** The text of a string literal (or of a plain stretch inside an interpolated one). */
    private static function literal(PhpToken $token): ?string
    {
        if ($token->is([T_ENCAPSED_AND_WHITESPACE])) {
            return $token->text;
        }

        if (! $token->is([T_CONSTANT_ENCAPSED_STRING])) {
            return null;
        }

        $body = substr($token->text, 1, -1);

        return $token->text[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }

    /** A token that is one punctuation character, as that character; anything else is ''. */
    private static function mark(PhpToken $token): string
    {
        return $token->id < 256 ? $token->text : '';
    }

    /**
     * A rule string as Laravel takes it apart: `|` between rules, `:` between a rule's name
     * and its parameters. The name is folded the way Laravel folds it before it looks for
     * the method to run (Str::studly() drops `_`, `-` and spaces, and PHP matches a method
     * name without regard to case), so `Mime_Types:` is `mimetypes`.
     *
     * @return list<array{name: string, parameters: string|null}>
     */
    private static function segments(string $rule): array
    {
        return array_map(function (string $segment): array {
            [$name, $parameters] = array_pad(explode(':', $segment, 2), 2, null);

            return ['name' => strtolower(str_replace(['_', '-', ' '], '', trim($name))), 'parameters' => $parameters];
        }, explode('|', $rule));
    }

    /**
     * @param  list<string>  $rules
     * @param  callable(array{name: string, parameters: string|null}): bool  $test
     */
    private static function anySegment(array $rules, callable $test): bool
    {
        foreach ($rules as $rule) {
            foreach (self::segments($rule) as $segment) {
                if ($test($segment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Why a fact no longer holds, or null when it does.
     *
     * @param  array{0: string, 1: string, 2?: string}  $fact
     */
    private function factProblem(array $fact): ?string
    {
        if ($fact[0] === 'disk') {
            $disk = (string) config($fact[1]);
            $root = (string) config("filesystems.disks.{$disk}.root");
            $publicRoot = (string) config('filesystems.disks.public.root');

            return match (true) {
                $disk === '' => "config('{$fact[1]}') names no disk.",
                $disk === 'public' => "config('{$fact[1]}') is the PUBLIC disk.",
                $root === '' => "config('{$fact[1]}') names the disk `{$disk}`, which config/filesystems.php does not define.",
                $root === $publicRoot || str_starts_with($root, $publicRoot . DIRECTORY_SEPARATOR) => "the disk `{$disk}` (config('{$fact[1]}')) keeps its files under the public disk's root.",
                config("filesystems.disks.{$disk}.visibility") === 'public' => "the disk `{$disk}` (config('{$fact[1]}')) is public by visibility.",
                default => null,
            };
        }

        $path = base_path($fact[1]);
        if (! is_file($path)) {
            return "{$fact[1]} is gone.";
        }

        $contains = str_contains((string) file_get_contents($path), (string) $fact[2]);

        return match (true) {
            $fact[0] === 'has' && ! $contains => "{$fact[1]} no longer contains `{$fact[2]}`.",
            $fact[0] === 'lacks' && $contains => "{$fact[1]} now contains `{$fact[2]}`.",
            default => null,
        };
    }
}
