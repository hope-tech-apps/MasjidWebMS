<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Mail\AnnualStatementMail;
use App\Models\Masjid;
use App\Services\Receipts\AnnualStatementService;
use App\Services\Receipts\Letterhead;
use App\Services\Receipts\StatementLetterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: year-end (annual) giving statements for 501(c)(3) donors.
 *
 * Read + email only — statements are computed on the fly from succeeded,
 * receipted donations, so there is no statement table to mutate. Emailing is a
 * queued mailable, so a bulk send returns immediately.
 *
 * Tenant is resolved from the route masjid; the service filters masjid_id
 * explicitly, so nothing crosses tenants.
 */
class AnnualStatementsController extends Controller
{
    public function __construct(
        private AnnualStatementService $statements,
        private StatementLetterService $letters,
    ) {
    }

    /** Download one donor's statement as the formal letter PDF. */
    public function downloadPdf(Request $request, $masjid_id, $contact_id)
    {
        $year = $this->resolveYear($request);
        $pdf = $this->letters->pdfFor((int) $masjid_id, (int) $contact_id, $year);

        if ($pdf === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'No receipted giving for this donor in ' . $year . '.',
            ], Response::HTTP_NOT_FOUND);
        }

        $statement = $this->statements->forContact((int) $masjid_id, (int) $contact_id, $year);
        $donorName = trim(($statement['contact']->first_name ?? '') . ' ' . ($statement['contact']->last_name ?? ''));

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->letters->filename($donorName, $year) . '"',
        ]);
    }

    /** Summary report: every donor with receipted giving in the year, with totals. */
    public function index(Request $request, $masjid_id)
    {
        $year = $this->resolveYear($request);
        $rows = $this->statements->summaryForYear((int) $masjid_id, $year);

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['currency']] = ($totals[$row['currency']] ?? 0) + $row['total_eligible'];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'year' => $year,
                'donors' => $rows,
                'total_eligible' => count($totals) <= 1 ? array_sum($totals) : null,
                'totals_by_currency' => $totals,
            ],
        ], Response::HTTP_OK);
    }

    /** One donor's full statement (preview before sending). */
    public function show(Request $request, $masjid_id, $contact_id)
    {
        $year = $this->resolveYear($request);
        $statement = $this->statements->forContact((int) $masjid_id, (int) $contact_id, $year);

        if (! $statement) {
            return response()->json([
                'status' => 'error',
                'message' => 'No receipted giving for this donor in ' . $year . '.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->present($statement),
        ], Response::HTTP_OK);
    }

    /** Queue one donor's statement; no email is 422, rendering/queue failure is 500. */
    public function send(Request $request, $masjid_id, $contact_id)
    {
        $year = $this->resolveYear($request);
        $statement = $this->statements->forContact((int) $masjid_id, (int) $contact_id, $year);

        if (! $statement) {
            return response()->json([
                'status' => 'error',
                'message' => 'No receipted giving for this donor in ' . $year . '.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $statement['contact']->email) {
            return response()->json([
                'status' => 'error',
                'message' => 'This donor has no email address on file.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $queued = $this->dispatchStatement((int) $masjid_id, $statement);

        return response()->json([
            'status' => $queued ? 'success' : 'error',
            'message' => $queued
                ? 'Statement queued for delivery.'
                : 'Failed to queue statement.',
        ], $queued ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /** Bulk: count each donor once as queued, skipped (no email), or failed; continue after failures. */
    public function sendAll(Request $request, $masjid_id)
    {
        $year = $this->resolveYear($request);
        // Summary rows separate currencies, but the emailed statement covers the whole donor/year.
        $rows = collect($this->statements->summaryForYear((int) $masjid_id, $year))->unique('contact_id');

        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if (empty($row['email'])) {
                $skipped++;

                continue;
            }

            try {
                $statement = $this->statements->forContact((int) $masjid_id, $row['contact_id'], $year);
                if ($statement && ! $statement['contact']->email) {
                    $skipped++;
                } elseif ($statement && $this->dispatchStatement((int) $masjid_id, $statement)) {
                    $queued++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logFailure((int) $masjid_id, $row['contact_id'], $e);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => ['year' => $year, 'queued' => $queued, 'skipped' => $skipped, 'failed' => $failed],
        ], Response::HTTP_OK);
    }

    /** Build + queue the snapshot. False means no email or a logged render/queue failure. */
    private function dispatchStatement(int $masjidId, array $statement): bool
    {
        $contact = $statement['contact'];
        $email = $contact->email;
        if (! $email) {
            return false;
        }

        $masjid = Masjid::find($masjidId);
        $data = $this->present($statement);
        $donorName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Valued donor';

        try {
            // Rendering belongs inside the per-donor failure boundary too.
            $pdf = $this->letters->pdfFor($masjidId, (int) $contact->id, $statement['year']);
            if ($pdf === null) {
                throw new \RuntimeException('Statement letter unavailable.');
            }

            Mail::to($email)->send(new AnnualStatementMail(
                masjidName: $masjid?->name ?? 'Your masjid',
                donorName: $donorName,
                year: $statement['year'],
                currency: $statement['currency'],
                totalEligible: $data['total_eligible'],
                giftCount: $statement['gift_count'],
                gifts: $data['gifts'],
                byFund: $data['by_fund'],
                pdf: $pdf,
                pdfName: $this->letters->filename($donorName, $statement['year']),
                // The same wording decision the attached letter made (a missing
                // organisation keeps the masjid wording), so the two never disagree.
                religiousOrg: Letterhead::religiousOrg($masjid),
                masjidId: $masjidId,
                currencies: $data['currencies'] ?? [],
            ));

            return true;
        } catch (\Throwable $e) {
            $this->logFailure($masjidId, (int) $contact->id, $e);

            return false;
        }
    }

    private function logFailure(int $masjidId, int $contactId, \Throwable $e): void
    {
        // Queue/transport exception text may contain recipients or the payload.
        Log::error('Annual statement email failed', [
            'masjid_id' => $masjidId,
            'contact_id' => $contactId,
            'exception' => $e::class,
        ]);
    }

    /** Format the service's integer-cents payload into display strings. */
    private function present(array $statement): array
    {
        $money = fn (int $cents) => number_format($cents / 100, 2);

        $data = [
            'contact' => [
                'id' => $statement['contact']->id,
                'name' => trim(($statement['contact']->first_name ?? '') . ' ' . ($statement['contact']->last_name ?? '')),
                'email' => $statement['contact']->email,
            ],
            'year' => $statement['year'],
            'currency' => $statement['currency'],
            'total_eligible' => $statement['total_eligible'] === null ? null : $money($statement['total_eligible']),
            'gift_count' => $statement['gift_count'],
            'gifts' => array_map(fn ($g) => [
                'date' => $g['date'],
                'fund' => $g['fund'],
                'amount' => $money($g['amount']),
                'serial' => $g['serial'],
            ] + (isset($g['currency']) ? ['currency' => $g['currency']] : []), $statement['gifts']),
            'by_fund' => array_map(
                fn ($fund, $cents) => ['fund' => $fund, 'amount' => $money($cents)],
                array_keys($statement['by_fund']),
                array_values($statement['by_fund']),
            ),
        ];

        if (isset($statement['currencies'])) {
            $data['currencies'] = array_map(fn ($section) => [
                'currency' => $section['currency'],
                'total_eligible' => $money($section['total_eligible']),
                'gift_count' => $section['gift_count'],
                'gifts' => array_map(fn ($g) => [
                    'date' => $g['date'], 'fund' => $g['fund'],
                    'amount' => $money($g['amount']), 'serial' => $g['serial'],
                ], $section['gifts']),
                'by_fund' => array_map(
                    fn ($fund, $cents) => ['fund' => $fund, 'amount' => $money($cents)],
                    array_keys($section['by_fund']), array_values($section['by_fund']),
                ),
            ], $statement['currencies']);
        }

        return $data;
    }

    /** Default to last calendar year (the usual statement window), clamp to sane range. */
    private function resolveYear(Request $request): int
    {
        $default = (int) now()->subYear()->year;
        $year = (int) $request->query('year', $default);

        return ($year >= 2000 && $year <= (int) now()->year) ? $year : $default;
    }
}
