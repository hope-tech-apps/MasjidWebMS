<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\CommitRosterImportRequest;
use App\Http\Requests\Admin\Groups\PreviewRosterImportRequest;
use App\Services\Schools\RosterImportService;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The office's own roster import: upload, READ WHAT IT WILL DO, then commit.
 *
 * `schools:import-roster` has been correct since R7 and unreachable by the
 * people who own the roster — it needs a shell on the production host. This is
 * the same import with a door on it. Every rule about what a roster CSV is, and
 * every refusal, is `App\Services\Schools\RosterImportService`'s; this class
 * adds the three things HTTP needs and the console did not: a preview that is
 * only a preview, a way to prove the committed file is the previewed one, and a
 * signed-in confirmer.
 *
 * ---------------------------------------------------------------------------
 * THE PREVIEW IS THE SAFETY MECHANISM, SO IT IS ENFORCED AND NOT ADVISED
 * ---------------------------------------------------------------------------
 *
 * This creates children's records in bulk from a spreadsheet somebody typed. The
 * failure that matters is not a crash; it is sixty rows landing correctly for a
 * file that named the wrong class, spelled four children a second way, or came
 * from last year. All of those are obvious on a preview and invisible in a
 * success toast, so `commit()` refuses any upload that is not accompanied by a
 * preview OF THOSE EXACT BYTES.
 *
 * `preview()` issues an encrypted RECEIPT naming:
 *
 *   - `digest`  — sha256 of the uploaded bytes;
 *   - `masjid`  — the school the preview was read against;
 *   - `user`    — the administrator who read it;
 *   - `rows`    — how many data rows the preview covered;
 *   - `expires` — thirty minutes out.
 *
 * `commit()` opens it and re-digests the file in front of it. A different file
 * is refused, a receipt from another school is refused, a receipt from another
 * administrator is refused, and a forged or edited one will not decrypt at all.
 *
 * A PLAIN CLIENT-SUPPLIED CHECKSUM WOULD NOT DO THIS. Anybody can hash a file
 * nobody has looked at; the property that has to hold is that a human read the
 * outcome, and only a response from `preview()` is evidence of that. Binding the
 * receipt to the USER is deliberate for the same reason: the commit stamps
 * `confirmed_by_user_id` on every row it writes, and the person whose name goes
 * on those rows should be the person who read them. A colleague who wants to
 * import the file previews it themselves, which costs one click and no writes.
 *
 * The receipt authenticates the FILE. It never carries the plan. `commit()`
 * re-reads and re-plans from the upload, so a class deleted or a child enrolled
 * between the two requests is reflected in what gets written — and if that makes
 * a row unreadable, the same all-or-nothing refusal fires as on the console.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS SCREEN MUST NOT LET THE OFFICE BELIEVE
 * ---------------------------------------------------------------------------
 *
 * Four sentences ride in `meta` on every response and are shown on the screen,
 * because each names something an administrator would otherwise reasonably
 * assume had happened (or, for the fourth, would otherwise assume they could
 * take back):
 *
 *  1. NO CONSENT. Nothing here writes `consent_granted_at` or `consent_scope`.
 *     Photographs and the class feed stay shut for every family in the file
 *     until somebody records their consent, per family.
 *  2. NO LOGINS. Importing a parent's email address does not give them one.
 *     That is `FamilyAccessService::enable()`, an audited act with its own
 *     screen.
 *  3. NO NEW CLASSES. A class named in the file that does not already exist is
 *     a refused row, never a second Grade 2 founded by a typo.
 *  4. THERE IS NO UNDO. This screen has no reversal at all, and that is the
 *     design rather than an omission — see the section below.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO UNDO BUTTON
 * ---------------------------------------------------------------------------
 *
 * An earlier draft of this controller carried `DELETE .../{batch}`, which
 * deleted every contact in the batch and swept their `group_memberships` with a
 * mass query. Those are exactly the rows migration
 * 2026_09_09_040000 and `GroupMembershipsController::destroy()` exist to
 * protect: attendance, assignment scores, report cards, ḥifẓ entries, behaviour
 * awards and Arabic letter progress all hang off `group_memberships.id`. A tag
 * stays on screen, is copyable and never expires, so an office could fire that
 * delete in October against a child who has been marked present since September
 * — on MySQL a bare 1451 that rolls back and names nobody, and on SQLite (where
 * that migration returns early, so CI is structurally blind to it) a silent
 * erasure of a term of marks.
 *
 * The spec asked for preview-then-commit and nothing else. THE PREVIEW IS THE
 * SAFETY MECHANISM: an import is read before it is written, per row and by name.
 * A one-click reversal of a bulk write over children's records is a bigger
 * hazard than the mistake it reverses, and a half-guarded one is worse than
 * none. So the route is gone. An import that was genuinely wrong is corrected
 * off the roster screens, row by row, by somebody looking at each child — or, for
 * a whole batch that has touched nothing, by an engineer with a shell:
 * `schools:import-roster x --masjid=<id> --rollback=<tag>`, which refuses in full
 * the moment any row holds academic history.
 *
 * The batch tag is still minted and still returned. It is the provenance stamp
 * on every contact this wrote (`contacts.import_batch`) and the handle that
 * console command takes; it is not a delete button.
 *
 * ---------------------------------------------------------------------------
 * TENANCY
 * ---------------------------------------------------------------------------
 *
 * `ResolveMasjidTenant` binds the context from the ROUTE, and every query the
 * service makes runs under `BelongsToMasjid`. This controller therefore never
 * filters by `masjid_id` and never calls `TenantContext::set()` — the console
 * command does that because it has no middleware, and a service or controller
 * that re-bound would defeat the guardrail. What it does do is ASSERT the
 * binding before touching children's data: a bulk write is the wrong place to
 * discover that the context was left unbound by a route-group edit upstream.
 *
 * Gated by `permission:manage contacts` on both routes, matching the roster
 * screens and the records export. Teachers and members hold zero CRM permissions
 * by design and are refused at the realm gate before this class is reached.
 */
class RosterImportController extends Controller
{
    /**
     * How long a preview stands. Long enough to read sixty rows out loud to a
     * colleague; short enough that an abandoned tab cannot be committed
     * tomorrow, against a roster that has moved on.
     */
    private const RECEIPT_TTL_MINUTES = 30;

    /**
     * POST .../records/roster-import/preview
     *
     * Reads the file and answers with everything it would do. Writes nothing —
     * there is no `apply()` call on this path at all, which is the form of that
     * guarantee that survives future edits.
     */
    public function preview(PreviewRosterImportRequest $request, RosterImportService $importer, $masjid_id): JsonResponse
    {
        $this->assertTenantBound($masjid_id);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->getRealPath();

        ['rows' => $rows, 'problems' => $problems] = $importer->read($path);

        // The file as a WHOLE could not be read — no header, or a missing
        // column. Distinct from a refused row: there is nothing to preview, so
        // no receipt is issued and there is nothing to commit.
        if ($rows === null) {
            return response()->json([
                'status' => 'failed',
                'data' => ['file' => $problems],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $plan = $importer->plan($rows);

        return response()->json([
            'status' => 'success',
            'data' => $this->planPayload($plan, $rows, $file, (int) $masjid_id),
            'meta' => $this->cautions(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../records/roster-import
     *
     * The same file plus its receipt. On success every row in the file exists;
     * on any refusal none of it does.
     */
    public function commit(CommitRosterImportRequest $request, RosterImportService $importer, $masjid_id): JsonResponse
    {
        $this->assertTenantBound($masjid_id);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->getRealPath();

        if ($refusal = $this->receiptRefusal((string) $request->input('receipt'), $path, (int) $masjid_id)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['receipt' => [$refusal]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        ['rows' => $rows, 'problems' => $problems] = $importer->read($path);

        if ($rows === null) {
            return response()->json([
                'status' => 'failed',
                'data' => ['file' => $problems],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // RE-PLANNED, never replayed from the receipt. Minutes passed; a class
        // may have been renamed and a child may have been enrolled by hand. The
        // office approved a file, and this is what that file means now.
        $plan = $importer->plan($rows);

        if ($plan['refused'] !== []) {
            // The console's refusal, worded the same way, for the same reason: a
            // roster that imported forty of sixty children is worse than one
            // that imported none, because only the second is obviously
            // unfinished. The individual lines ride along so the screen can put
            // them back in front of the office without a second round trip.
            return response()->json([
                'status' => 'failed',
                'data' => [
                    'file' => ['Refusing to write: ' . count($plan['refused']) . ' row(s) could not be read. '
                        . 'Fix the file and upload it again — a partly-imported roster is worse than none.'],
                    'refused' => array_map(
                        fn (array $r) => "line {$r[0]}: {$r[1]}",
                        $plan['refused']
                    ),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // THE OTHER HALF OF `can_commit`, enforced here and not only on the
        // screen. `planPayload()` sets the flag from `refused === [] && students
        // !== []` and says the server refuses independently; until this branch
        // existed only the first half was true, so a header-only CSV — or one
        // whose data rows are all blank, or a double-submit after the file was
        // cleared — answered 201 with a batch tag and every count zero. A success
        // screen for an import that wrote nothing is the one outcome this whole
        // feature is built to prevent.
        if ($plan['students'] === []) {
            return response()->json([
                'status' => 'failed',
                'data' => ['file' => ['There is nothing in this file to import.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The tag is minted HERE, never accepted from the client. It is the
        // provenance stamp that goes on every contact this writes, and the handle
        // `schools:import-roster --rollback=` takes; a caller that could choose it
        // could point that console undo at another import's rows. The random
        // suffix separates two imports committed inside the same second.
        $batch = 'roster-' . now()->format('Ymd-His') . '-' . Str::lower(Str::random(4));

        $result = $importer->apply($plan, $batch, Auth::user());

        return response()->json([
            'status' => 'success',
            'data' => [
                'batch' => $batch,
                'created' => $result['created'],
                'matched' => $result['matched'],
            ],
            'meta' => $this->cautions(),
        ], Response::HTTP_CREATED);
    }

    // ------------------------------------------------------------- the receipt

    /**
     * The encrypted evidence that a human read this file's outcome.
     *
     * Encrypted rather than merely signed so the digest and the reader's id are
     * not readable in a browser's network tab; either would do for integrity,
     * and Laravel's `Crypt` is authenticated encryption, so tampering fails to
     * decrypt rather than decoding to something attacker-chosen.
     */
    private function issueReceipt(string $path, int $masjidId, int $rowCount): string
    {
        return Crypt::encryptString(json_encode([
            'digest' => hash_file('sha256', $path),
            'masjid' => $masjidId,
            'user' => (int) Auth::id(),
            'rows' => $rowCount,
            'expires' => now()->addMinutes(self::RECEIPT_TTL_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Why this receipt does not authorise this upload, or null if it does.
     *
     * Each refusal names its own cause. A single "invalid preview" for all five
     * would leave an office re-uploading the same file to fix an expiry, or
     * hunting a corrupt file when the real answer is that their colleague did
     * the preview — and the whole point of this screen is that the person acting
     * understands what they are looking at.
     */
    private function receiptRefusal(string $receipt, string $path, int $masjidId): ?string
    {
        try {
            $claims = json_decode(Crypt::decryptString($receipt), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return 'That preview could not be read. Preview the file again and import from that preview.';
        }

        if (! is_array($claims)) {
            return 'That preview could not be read. Preview the file again and import from that preview.';
        }

        if ((int) ($claims['expires'] ?? 0) < now()->getTimestamp()) {
            return 'That preview has expired. Preview the file again — the roster may have changed since.';
        }

        if ((int) ($claims['masjid'] ?? 0) !== $masjidId) {
            return 'That preview was made for a different school. Preview the file again here.';
        }

        if ((int) ($claims['user'] ?? 0) !== (int) Auth::id()) {
            return 'That preview was read by a different administrator. Preview the file yourself before '
                . 'importing it — your name goes on every row this creates.';
        }

        if (! hash_equals((string) ($claims['digest'] ?? ''), (string) hash_file('sha256', $path))) {
            return 'That is not the file you previewed. Preview it again.';
        }

        return null;
    }

    // -------------------------------------------------------------- shaping it

    /**
     * The plan as the office reads it: three totals, then every row by name.
     *
     * PER-ROW, not just the totals. "57 will be created" is a number an
     * administrator agrees with; "Aisha Khan — will be created" is the line on
     * which somebody notices that she is already enrolled as "Ayshah Khan" and
     * that the import is about to duplicate a child. The totals alone hide
     * exactly the mistake this file format makes.
     */
    private function planPayload(array $plan, array $rows, UploadedFile $file, int $masjidId): array
    {
        $students = [];

        foreach ($plan['students'] as $s) {
            $students[] = [
                'line' => $s['lines'][0],
                'lines' => $s['lines'],
                'class' => $s['class']->name,
                'name' => trim($s['first'] . ' ' . $s['last']),
                'grade' => $s['grade'],
                'existing' => $s['existing'],
            ];
        }

        usort($students, fn ($a, $b) => $a['line'] <=> $b['line']);

        $guardians = [];

        foreach ($plan['guardians'] as $g) {
            $guardians[] = [
                'line' => $g['lines'][0],
                'email' => $g['email'],
                'name' => trim($g['first'] . ' ' . $g['last']),
                'existing' => $g['existing'],
            ];
        }

        usort($guardians, fn ($a, $b) => $a['line'] <=> $b['line']);

        $existingStudents = count(array_filter($students, fn ($s) => $s['existing']));
        $existingGuardians = count(array_filter($guardians, fn ($g) => $g['existing']));

        $refused = array_map(fn (array $r) => ['line' => $r[0], 'why' => $r[1]], $plan['refused']);

        // DISTINCT guardian links, not rows. `plan()` keeps one entry per line
        // because `apply()` walks it line by line and `firstOrNew` settles the
        // repeats — a duplicated row in the file writes one edge. Counting the
        // entries here instead would promise the office more links than the
        // commit then reports creating, and "the commit did what the preview
        // said" is the property this whole screen exists to have. The CLI's own
        // table is left counting rows; it is unchanged on purpose.
        $edges = count(array_unique(array_map(
            fn (array $e) => $e['student'] . "\0" . $e['guardian'],
            $plan['edges']
        )));

        return [
            'rows_read' => count($rows),
            'totals' => [
                'students' => [
                    'in_file' => count($students),
                    'existing' => $existingStudents,
                    'to_create' => count($students) - $existingStudents,
                ],
                'guardians' => [
                    'in_file' => count($guardians),
                    'existing' => $existingGuardians,
                    'to_create' => count($guardians) - $existingGuardians,
                ],
                'edges' => $edges,
                'refused' => count($refused),
            ],
            'students' => $students,
            'guardians' => $guardians,
            'refused' => $refused,
            // ALL OR NOTHING, decided here as well as on the write. The screen
            // disables its Import button off this flag; `commit()` refuses on
            // BOTH halves of it independently — a refused row and an empty
            // roster — so the flag is a courtesy and never the guarantee.
            'can_commit' => $refused === [] && $students !== [],
            // Issued even when the file is refused: re-previewing after a fix
            // produces a new receipt anyway, and withholding it here would mean
            // the commit's refusal was about the receipt rather than about the
            // rows the office needs to fix.
            'receipt' => $this->issueReceipt($file->getRealPath(), $masjidId, count($rows)),
            'expires_in_minutes' => self::RECEIPT_TTL_MINUTES,
        ];
    }

    /**
     * The three sentences that stop this screen from over-promising. They ride
     * on every response rather than living in the Vue file, so the API tells the
     * truth to any client — the screen, a future mobile admin, a support script.
     */
    private function cautions(): array
    {
        return [
            'consent_note' => 'This does not record consent for anything. Photographs and class updates '
                . 'stay off for every family in this file until you record their consent, per family, '
                . 'on the class roster.',
            'login_note' => 'This does not give parents a login. Enable that per family from their '
                . 'contact record.',
            'classes_note' => 'This never creates a class. A class named in the file that does not '
                . 'already exist is a refused row.',
            // SAID BEFORE THE WRITE, not after it. This rides on the preview
            // response as well as the commit's, because "you can always undo it"
            // is the assumption that makes somebody skip reading sixty rows, and
            // it is not true here.
            'undo_note' => 'This cannot be undone from this screen. Read the rows above before you '
                . 'import: correcting an import afterwards means taking each person off the roster by '
                . 'hand, and a child who already has attendance or marks in a class cannot be taken '
                . 'off it at all.',
        ];
    }

    /**
     * The tenant must be bound to the school in the path before anything here
     * reads or writes a child's record.
     *
     * `ResolveMasjidTenant` guarantees this today for both a MasjidAdmin and a
     * SuperAdmin on a `{masjid_id}` route. The assertion is here because the
     * cost of being wrong is asymmetric: unbound means `BelongsToMasjid` adds no
     * filter at all, and an unfiltered `plan()` would match another school's
     * children as "already on record" while an unfiltered `apply()` would write
     * rows into no tenant. A 500 is the right outcome for that; sixty children
     * in the wrong school is not.
     */
    private function assertTenantBound($masjid_id): void
    {
        abort_unless(
            app(TenantContext::class)->get() === (int) $masjid_id,
            Response::HTTP_FORBIDDEN,
            'This import is not scoped to the school in the URL.'
        );
    }
}
