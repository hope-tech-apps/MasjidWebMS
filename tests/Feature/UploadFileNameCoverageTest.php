<?php

namespace Tests\Feature;

use PhpToken;
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
 * rule a person has to remember is documentation, not a control. This file is the control.
 *
 * ## What it enforces
 *
 *  1. **Every rule that accepts an upload names the file names it takes.** Every PHP file
 *     under `app/` is read (tokens, so comments are not rules), and every rule that can
 *     admit a file (`image`, `file`, `mimes:`, `mimetypes:`, `dimensions:`, and the fluent
 *     `Rule::file()` / `File::types()` family) must have `extensions:` BESIDE it: in the
 *     same rule string, or in the same rule list. A rule added tomorrow is in scope
 *     tomorrow, with no edit to this file.
 *  2. **Or it says why it need not** (`NAME_NEVER_PUBLIC`): the file is stored under a
 *     name this application chooses, on a private disk behind a signed-in download, or
 *     not stored at all. Each entry carries its reason AND facts that can turn false (the
 *     disk a config key names, a line the storing code must still contain), so an
 *     exemption cannot outlive the thing that made it safe.
 *  3. **The scanner itself is tested** (`the_scanner_reads_rules_the_way_this_file_says`)
 *     and has a control (`the_scan_still_sees_the_rules_it_was_written_for`): a guard that
 *     has gone blind passes for ever.
 *
 * ## "Beside it" is deliberately narrow
 *
 * `extensions:` counts only in the SAME string literal or the SAME list array as the rule
 * it pins. A rule assembled from variables across statements is reported even when the
 * assembled result would be fine, because this test cannot see the assembled result. Write
 * such a field's rule whole (SaveMasjidAboutRequest does), or give the reason below.
 *
 * ## What this test cannot see
 *
 * An upload that is read with NO rule at all (`$request->file('x')` and nothing
 * validating `x`), and whether a pinned rule's list of extensions is a sensible one. The
 * first is what review is for; the second is what PublicUploadFileNameTest and
 * PublicUploadFileNameDoorsTest prove, door by door, with real bytes.
 *
 * ## If this test fails
 *
 * Read the failure: it names the file, the line and the rule. Add `extensions:` with the
 * same kinds the rule's `mimes:` lists, in lower case (the rule lower-cases the client's
 * extension and not its own list), and a sentence that tells the person what the name must
 * end in. **Do not add an exemption to make it pass** unless the client's file name truly
 * cannot reach a public address, and then say how you know.
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

    /** A rule segment that begins with one of these admits a file. */
    private const FILE_RULE_PREFIXES = ['mimes:', 'mimetypes:', 'dimensions:', 'image:'];

    /** A rule segment that IS one of these admits a file. */
    private const FILE_RULE_WORDS = ['image', 'file'];

    /** Beside a bare `image` or `file` in a list, any of these says the list is a rule. */
    private const PRESENCE_WORDS = ['required', 'nullable', 'sometimes', 'bail', 'present', 'filled'];

    // =====================================================================
    // 1. Every rule that accepts an upload pins the name, or says why not.
    // =====================================================================

    #[Test]
    public function every_rule_that_accepts_an_upload_pins_the_file_name_or_says_why_it_need_not(): void
    {
        $open = [];

        foreach ($this->appSources() as $path => $source) {
            foreach ($this->uploadRules($source) as $rule) {
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
            . "application's own origin. Put `extensions:` beside the rule (the same string, or the same\n"
            . "list), naming in LOWER CASE the kinds its `mimes:` lists, and give the field a sentence that\n"
            . "says what the name must end in. PublicUploadFileNameDoorsTest shows how to prove the door.\n\n"
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
                array_filter($this->uploadRules($source), fn (array $rule) => ! $rule['pinned']),
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
                $this->uploadRules((string) file_get_contents(base_path($path))),
                fn (array $rule) => $rule['pinned'],
            );

            $this->assertNotEmpty($pinned, "The scan no longer finds a pinned upload rule in {$path}. "
                . 'Either the pin was removed (the main test says so too) or the scan has gone blind, '
                . 'in which case every other assertion in this file passes without looking.');
        }

        $this->assertArrayHasKey(self::CONTROL[0], $this->appSources(), 'The scan no longer reads app/ at all.');
    }

    #[Test]
    public function the_scanner_reads_rules_the_way_this_file_says(): void
    {
        $cases = [
            // What it must REPORT (an upload rule with no name pinned).
            'a rule string' => ["'photo' => 'required|image|mimes:jpeg,png|max:5120',", ['rules() photo' => false]],
            'a bare image rule with a presence word' => ["'photo' => 'nullable|image',", ['rules() photo' => false]],
            'a rule that is the one word' => ["'photo' => 'image',", ['rules() photo' => false]],
            'a rule list' => ["'photo' => ['required', 'file', 'max:2048'],", ['rules() photo' => false]],
            'a rule list by sniffed type' => ["'doc' => ['required', 'file', 'mimetypes:' . implode(',', \$types)],", ['rules() doc' => false]],
            'a rule built across statements' => ["]; \$each = ['file']; \$each[] = 'mimetypes:' . implode(',', \$types); return ['docs.*' => \$each,", ['rules() $each' => false]],
            'the fluent rule' => ["'doc' => [Rule::file()->max(2048)],", ['rules() doc' => false]],
            'the fluent image rule' => ["'photo' => File::image()->max(2048),", ['rules() photo' => false]],
            'a dimensions rule alone' => ["'photo' => 'dimensions:min_width=96',", ['rules() photo' => false]],
            'one branch of two' => [
                "'clip' => \$video ? 'nullable|file|mimetypes:video/mp4|extensions:mp4' : 'nullable|file|mimes:jpeg,png|max:1',",
                ['rules() clip' => false],
            ],
            'a pin on the neighbouring field' => [
                "'a' => 'image|mimes:png|extensions:png', 'b' => 'image|mimes:png',",
                ['rules() a' => true, 'rules() b' => false],
            ],

            // What it must ACCEPT (the name is pinned beside the rule).
            'a pinned rule string' => ["'photo' => 'bail|required|image|mimes:jpeg,png|extensions:jpeg,jpg,png|max:5120',", ['rules() photo' => true]],
            'a pinned rule list' => ["'photo' => ['required', 'file', 'mimes:jpeg,png', 'extensions:jpeg,jpg,png'],", ['rules() photo' => true]],
            'a pinned bare image rule' => ["'photo' => 'bail|nullable|image|extensions:jpeg,jpg,png,gif,bmp,webp',", ['rules() photo' => true]],
            'a rule written whole after a variable' => ["'photo' => \$presence . '|image|mimes:png|extensions:png|max:1',", ['rules() photo' => true]],
            'the pinned fluent rule' => ["'doc' => [Rule::file()->extensions(['pdf'])->max(2048)],", ['rules() doc' => true]],

            // What is NOT a rule at all.
            'a comment' => ["// 'photo' => 'required|image|mimes:jpeg,png',\n 'title' => 'required|string',", []],
            'other rules' => ["'title' => 'required|string|max:255', 'starts' => ['required', 'date'],", []],
            'a field called image' => ["'image' => 'required|string',", []],
            'a file read from the request' => ["'title' => \$this->file('image') ? 'required' : 'nullable', 'x' => \$this->hasFile('file') ? 'string' : 'integer',", []],
        ];

        foreach ($cases as $name => [$body, $expected]) {
            $found = [];
            foreach ($this->uploadRules("<?php class R { public function rules(): array { return [ {$body} ]; } }") as $rule) {
                // One id can be reported more than once (a list holds `file` AND `mimetypes:`);
                // it is pinned only if every report of it is.
                $found[$rule['id']] = ($found[$rule['id']] ?? true) && $rule['pinned'];
            }

            $this->assertSame($expected, $found, "The scanner misread: {$name}");
        }

        // Outside anything that builds rules, the words are just words.
        $vocabulary = <<<'PHP'
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
            PHP;
        $this->assertSame([], $this->uploadRules($vocabulary), 'The scanner took ordinary words for rules.');

        // ...but an inline validation in a controller is a rule wherever it is written.
        $inline = "<?php class C { public function upload(\$request) { \$request->validate(['flyer' => 'file']); "
            . "Validator::make(\$request->all(), ['doc' => ['file']]); } }";
        $this->assertSame(
            ['upload() flyer', 'upload() doc'],
            array_column($this->uploadRules($inline), 'id'),
            'The scanner missed an inline validation.',
        );
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
     * Every rule in a PHP source that can admit an uploaded file.
     *
     * `id` is "<function>() <label>", the label being the field the rule is keyed by, or
     * the variable it is being built in, or `return`. `pinned` is whether `extensions:`
     * sits beside it: in the same string, or in the same list.
     *
     * @return list<array{id: string, line: int, says: string, pinned: bool}>
     */
    private function uploadRules(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            fn (PhpToken $token) => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML]),
        ));

        $position = 0;
        $found = [];
        $this->read($this->nest($tokens, $position, ''), ['function' => '', 'validating' => false, 'array' => false, 'label' => ''], $found);

        return $found;
    }

    /**
     * Tokens as a tree: everything between a bracket and its partner becomes one node.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{open: string, children: list<PhpToken|array<string, mixed>>}
     */
    private function nest(array $tokens, int &$position, string $open): array
    {
        $node = ['open' => $open, 'children' => []];

        while ($position < count($tokens)) {
            $token = $tokens[$position++];
            $mark = $this->mark($token);

            if ($token->is([T_ATTRIBUTE])) {
                $node['children'][] = $this->nest($tokens, $position, '#[');
            } elseif (in_array($mark, ['[', '(', '{'], true)) {
                $node['children'][] = $this->nest($tokens, $position, $mark);
            } elseif ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $node['children'][] = $this->nest($tokens, $position, '{');
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
     * @param  array{function: string, validating: bool, array: bool, label: string}  $context
     * @param  list<array{id: string, line: int, says: string, pinned: bool}>  $found
     */
    private function read(array $node, array $context, array &$found): void
    {
        $children = $node['children'];
        $roles = $context['array'] ? $this->roles($children) : [];
        $validating = $context['validating'] || stripos($context['function'], 'rules') !== false;

        // What the unkeyed elements of this list say together.
        $list = [];
        foreach ($roles as $index => $role) {
            if ($role['is'] === 'list' && $children[$index] instanceof PhpToken && ($text = $this->literal($children[$index])) !== null) {
                $list[] = $text;
            }
        }
        $listPinned = $this->anySegment($list, fn (string $segment) => str_starts_with($segment, 'extensions:'));
        $listIsRules = $validating || $this->anySegment($list, fn (string $segment) => in_array($segment, self::PRESENCE_WORDS, true)
            || preg_match('/^[a-z_]+:/', $segment) === 1);

        $pendingFunction = null;

        foreach ($children as $index => $child) {
            $role = $roles[$index] ?? ['is' => 'free', 'key' => '', 'alone' => false];
            $label = match ($role['is']) {
                'keyed' => $role['key'],
                'list' => $context['label'],
                default => $this->statementTarget($children, $index),
            };

            if (is_array($child)) {
                $inner = $context;
                $inner['array'] = $this->isArrayLiteral($children, $index);
                $inner['label'] = $inner['array'] ? $label : $context['label'];
                $inner['validating'] = $validating || ($child['open'] === '(' && $this->isValidationCall($children, $index));

                if ($child['open'] === '{' && $pendingFunction !== null) {
                    $inner['function'] = $pendingFunction;
                    $inner['validating'] = $context['validating'];
                    $pendingFunction = null;
                }

                $this->read($child, $inner, $found);

                continue;
            }

            if ($child->is([T_FUNCTION]) && ($children[$index + 1] ?? null) instanceof PhpToken && $children[$index + 1]->is([T_STRING])) {
                $pendingFunction = $children[$index + 1]->text;
            } elseif ($this->mark($child) === ';') {
                $pendingFunction = null;
            }

            if ($role['is'] === 'key') {
                continue;
            }

            $id = $context['function'] . '() ' . $label;

            // Rule::file(), Rule::imageFile(), File::types(), File::image(), File::default().
            if (($fluent = $this->fluentFileRule($children, $index)) !== null) {
                $found[] = ['id' => $id, 'line' => $child->line, 'says' => $fluent['says'], 'pinned' => $fluent['pinned']];

                continue;
            }

            if (($text = $this->literal($child)) === null) {
                continue;
            }

            $segments = array_map('trim', explode('|', $text));
            $pinnedHere = $this->anySegment([$text], fn (string $segment) => str_starts_with($segment, 'extensions:'));
            $prefixed = $this->anySegment([$text], fn (string $segment) => $this->startsWithAny($segment, self::FILE_RULE_PREFIXES));
            $worded = $this->anySegment([$text], fn (string $segment) => in_array($segment, self::FILE_RULE_WORDS, true));

            // `mimes:...` anywhere, or `image` / `file` as one segment of several, is a rule
            // wherever it is written. The bare word alone is a rule only where rules are
            // built: among the elements of a rule list, or as the whole of a field's rule
            // inside a rules method or a validation call.
            $isRule = $prefixed || ($worded && count($segments) > 1) || ($worded && match ($role['is']) {
                'list' => $listIsRules,
                'keyed' => $validating && $role['alone'],
                default => $validating && $this->isWholeAssignment($children, $index),
            });

            if ($isRule) {
                $found[] = [
                    'id' => $id,
                    'line' => $child->line,
                    'says' => $text,
                    'pinned' => $pinnedHere || ($role['is'] === 'list' && $listPinned),
                ];
            }
        }
    }

    /**
     * For the children of an array literal: is each one part of a key, part of a keyed
     * value, or an unkeyed element of the list?
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     * @return array<int, array{is: string, key: string, alone: bool}>
     */
    private function roles(array $children): array
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
                        $key .= $children[$index] instanceof PhpToken ? ($this->literal($children[$index]) ?? $children[$index]->text) : '…';
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
            if ($child instanceof PhpToken && $this->mark($child) === ',') {
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
    private function isArrayLiteral(array $children, int $index): bool
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
    private function isValidationCall(array $children, int $index): bool
    {
        $name = $children[$index - 1] ?? null;
        if (! $name instanceof PhpToken || ! $name->is([T_STRING])) {
            return false;
        }

        if (in_array($name->text, ['validate', 'validateWithBag', 'validator'], true)) {
            return true;
        }

        $scope = $children[$index - 3] ?? null;

        return $name->text === 'make' && $scope instanceof PhpToken && $scope->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && str_ends_with($scope->text, 'Validator');
    }

    /**
     * `$rules[] = 'file';` : the literal is all there is to the right of the `=`.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private function isWholeAssignment(array $children, int $index): bool
    {
        $before = $children[$index - 1] ?? null;
        $after = $children[$index + 1] ?? null;

        return $before instanceof PhpToken && $this->mark($before) === '='
            && $after instanceof PhpToken && $this->mark($after) === ';';
    }

    /**
     * What the statement around this child assigns to: `$each`, `return`, or nothing known.
     *
     * @param  list<PhpToken|array<string, mixed>>  $children
     */
    private function statementTarget(array $children, int $index): string
    {
        $start = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            $child = $children[$i];
            if (is_array($child) ? $child['open'] === '{' : $this->mark($child) === ';') {
                $start = $i + 1;
                break;
            }
        }

        // `case 'file': $rules[] = 'file';` : the statement starts after the case's colon.
        if (($children[$start] ?? null) instanceof PhpToken && $children[$start]->is([T_CASE, T_DEFAULT])) {
            for ($i = $start; $i < $index; $i++) {
                if ($children[$i] instanceof PhpToken && $this->mark($children[$i]) === ':') {
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
     * @param  list<PhpToken|array<string, mixed>>  $children
     * @return array{says: string, pinned: bool}|null
     */
    private function fluentFileRule(array $children, int $index): ?array
    {
        $class = $children[$index];
        $colons = $children[$index + 1] ?? null;
        $method = $children[$index + 2] ?? null;

        if (! $class->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            || ! $colons instanceof PhpToken || ! $colons->is([T_DOUBLE_COLON])
            || ! $method instanceof PhpToken || ! $method->is([T_STRING])) {
            return null;
        }

        $short = substr((string) strrchr('\\' . $class->text, '\\'), 1);
        $methods = ['Rule' => ['file', 'imageFile'], 'File' => ['types', 'image', 'default'], 'ImageFile' => ['types', 'image', 'default']];

        if (! in_array($method->text, $methods[$short] ?? [], true)) {
            return null;
        }

        // Pinned when `->extensions(` follows before the element or the statement ends.
        $pinned = false;
        for ($i = $index + 3; $i < count($children); $i++) {
            $child = $children[$i];
            if (! $child instanceof PhpToken) {
                continue;
            }
            if (in_array($this->mark($child), [',', ';'], true)) {
                break;
            }
            if ($child->is([T_STRING]) && $child->text === 'extensions' && ($children[$i - 1] ?? null) instanceof PhpToken
                && $children[$i - 1]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $pinned = true;
                break;
            }
        }

        return ['says' => "{$short}::{$method->text}()", 'pinned' => $pinned];
    }

    /** The text of a string literal (or of a plain stretch inside an interpolated one). */
    private function literal(PhpToken $token): ?string
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
    private function mark(PhpToken $token): string
    {
        return $token->id < 256 ? $token->text : '';
    }

    /**
     * @param  list<string>  $rules
     * @param  callable(string): bool  $test
     */
    private function anySegment(array $rules, callable $test): bool
    {
        foreach ($rules as $rule) {
            foreach (explode('|', $rule) as $segment) {
                if ($test(trim($segment))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<string>  $prefixes */
    private function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
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
