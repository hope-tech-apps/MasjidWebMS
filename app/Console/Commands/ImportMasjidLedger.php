<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactCard;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\Property;
use App\Models\RentPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import a masjid's historical bookkeeping ledger (a monthly-matrix CSV) into the
 * CRM: donor contacts, card last-4, offline giving, program funds, and — kept
 * separate — rental properties + rent payments.
 *
 * Safe by construction:
 *   - Dry-run by default; writes only with --execute.
 *   - Everything created carries an import_batch tag → the whole import is
 *     reversible with --rollback=<batch>.
 *   - Reads a CSV path (kept out of the web root); never fetches remotely.
 *
 * The sheet's shape (26 cols): Name, Jan..Dec YEAR1, YEAR1-total, Jan..Dec YEAR2.
 * Amounts are dollars; stored as integer cents. Historical gifts are booked as
 * succeeded offline donations dated to their month, WITHOUT receipts (they are
 * records of past giving, not newly-receiptable events).
 */
class ImportMasjidLedger extends Command
{
    protected $signature = 'crm:import-ledger
        {csv : Path to the decoded CSV}
        {--masjid= : Masjid id to import into}
        {--year1=2025 : Calendar year of the first Jan..Dec block}
        {--year2=2026 : Calendar year of the second Jan..Dec block}
        {--batch= : Import batch tag (default: auto)}
        {--execute : Actually write (otherwise dry-run)}
        {--rollback= : Delete everything with this batch tag and exit}';

    protected $description = 'Import a historical donor/rent ledger CSV into the CRM (reversible).';

    /** Row names (lowercased, exact-ish) that are totals/expenses, not income. */
    private const SKIP = [
        'total donation', 'total donation for gaza', 'total exp', 'food pantry exp',
        'reimbursement to jolynn jalal', 'donations jolynn jalal', 'fund raiser cook out',
    ];

    public function handle(): int
    {
        $masjidId = (int) $this->option('masjid');
        $masjid = Masjid::withoutGlobalScopes()->find($masjidId);
        if (! $masjid) {
            $this->error("Masjid {$masjidId} not found.");

            return self::FAILURE;
        }

        if ($tag = $this->option('rollback')) {
            return $this->rollback($masjidId, $tag);
        }

        $path = $this->argument('csv');
        if (! is_readable($path)) {
            $this->error("Cannot read CSV: {$path}");

            return self::FAILURE;
        }

        $batch = $this->option('batch') ?: 'ledger_' . now()->format('Ymd_His');
        $y1 = (int) $this->option('year1');
        $y2 = (int) $this->option('year2');
        $execute = (bool) $this->option('execute');

        $rows = array_map('str_getcsv', file($path));
        array_shift($rows); // header

        $plan = $this->buildPlan($rows, $y1, $y2);

        $this->report($plan, $masjid, $batch, $execute);

        // Always write a manifest for review.
        $manifestDir = storage_path('app/imports');
        @mkdir($manifestDir, 0750, true);
        $manifestFile = "{$manifestDir}/manifest-{$batch}.json";
        file_put_contents($manifestFile, json_encode($this->manifest($plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("\nManifest written: {$manifestFile}");

        if (! $execute) {
            $this->warn("\nDRY RUN — nothing written. Re-run with --execute --batch={$batch} to apply.");

            return self::SUCCESS;
        }

        $this->write($plan, $masjid, $batch);
        $this->info("\nIMPORT COMPLETE (batch {$batch}). Reverse with: crm:import-ledger x --masjid={$masjidId} --rollback={$batch}");

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ planning

    private function buildPlan(array $rows, int $y1, int $y2): array
    {
        $donors = [];   // keyed by normalized base name → merged donor
        $placeholders = [];
        $programs = [];
        $rents = [];
        $skipped = [];

        foreach ($rows as $r) {
            $name = trim($r[0] ?? '');
            if ($name === '') {
                continue;
            }
            $low = strtolower($name);

            if (in_array($low, self::SKIP, true)) {
                $skipped[] = $name;

                continue;
            }

            $gifts = $this->monthGifts($r, $y1, $y2);

            if ($this->isRent($name)) {
                $rents[] = ['raw' => $name] + $this->parseProperty($name) + ['payments' => $gifts];

                continue;
            }
            if ($this->isProgram($name)) {
                $programs[] = ['raw' => $name, 'fund' => $this->programFund($name), 'gifts' => $gifts];

                continue;
            }

            $parsed = $this->parseDonor($name);

            if ($parsed['placeholder']) {
                $placeholders[] = $parsed + ['gifts' => $gifts];

                continue;
            }

            // Merge payment-method-split rows for the same person.
            $key = $parsed['base'];
            if (! isset($donors[$key])) {
                $donors[$key] = [
                    'display' => $parsed['display'],
                    'first' => $parsed['first'],
                    'last' => $parsed['last'],
                    'business' => $parsed['business'],
                    'email' => $parsed['email'],
                    'cards' => [],
                    'gifts' => [],   // [ [date, cents, method, note], ... ]
                ];
            }
            $donors[$key]['cards'] = array_values(array_unique(array_merge($donors[$key]['cards'], $parsed['cards'])));
            $donors[$key]['email'] = $donors[$key]['email'] ?: $parsed['email'];
            foreach ($gifts as $g) {
                $donors[$key]['gifts'][] = $g + ['method' => $parsed['method'], 'note' => $parsed['methodLabel']];
            }
        }

        return compact('donors', 'placeholders', 'programs', 'rents', 'skipped');
    }

    /** Non-empty monthly cells → [ ['date'=>Y-m-d, 'cents'=>int], ... ]. */
    private function monthGifts(array $r, int $y1, int $y2): array
    {
        $out = [];
        // cols 1..12 = year1 Jan..Dec ; col 13 = year1 total (skip) ; 14..25 = year2
        $blocks = [[1, $y1], [14, $y2]];
        foreach ($blocks as [$start, $year]) {
            for ($m = 1; $m <= 12; $m++) {
                $cents = $this->cents($r[$start + $m - 1] ?? '');
                if ($cents !== null && $cents !== 0) {
                    $out[] = ['date' => sprintf('%04d-%02d-01', $year, $m), 'cents' => $cents];
                }
            }
        }

        return $out;
    }

    /** Leading signed dollar amount → cents; null if the cell has no number. */
    private function cents(string $cell): ?int
    {
        $c = trim(str_replace(['$', ',', '"'], '', $cell));
        if (! preg_match('/^-?\d+(\.\d+)?/', $c, $m)) {
            return null;
        }

        return (int) round(((float) $m[0]) * 100);
    }

    // ------------------------------------------------------------------ name parsing

    private function parseDonor(string $name): array
    {
        $email = null;
        if (preg_match('/[\w.+-]+@[\w.-]+\.\w+/', $name, $em)) {
            $email = strtolower($em[0]);
        }

        $placeholder = false;
        $display = $name;

        if (preg_match('/^\s*unknown name credit\s*(\d{3,5})?\s*(.*)$/i', $name, $u)) {
            $card = $u[1] ?? '';
            $trailing = trim($u[2] ?? '');
            // A trailing real name means it IS identified; otherwise a placeholder.
            if ($trailing !== '' && ! preg_match('/^\*+$/', $trailing) && ! ctype_digit($trailing)) {
                $display = $trailing;
            } else {
                $placeholder = true;
                $display = 'Unidentified Card ' . ($card !== '' ? $card : '(unknown)');
            }
            $cards = $card !== '' ? [substr($card, -4)] : [];
            $parsed = $this->splitName($display, false);

            return $parsed + [
                'base' => strtolower($display),
                'cards' => $cards,
                'method' => 'credit',
                'methodLabel' => 'Credit',
                'email' => $email,
                'placeholder' => $placeholder,
            ];
        }

        // Card last-4s anywhere in the name.
        $cards = [];
        if (preg_match_all('/\b(\d{4,5})\b/', $name, $cm)) {
            foreach ($cm[1] as $d) {
                $cards[] = substr($d, -4);
            }
        }
        $cards = array_values(array_unique($cards));

        // Payment method from the label.
        [$method, $methodLabel] = $this->method($name);

        // Clean display name: drop the email, method words, card runs, slashes, "Ch".
        $clean = $name;
        if ($email) {
            $clean = str_ireplace($email, '', $clean);
        }
        $clean = preg_replace('/\b(credit\/zelle\/cash|credit|check|cheque|cash|zelle|zella|giftcard|gift card|venmo|paypal|square)\b/i', '', $clean);
        $clean = preg_replace('#\bC/#i', '', $clean);
        $clean = preg_replace('#[0-9/]{3,}#', ' ', $clean);   // card runs / slashes
        $clean = preg_replace('/\b(ch|c)\b/i', '', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean), " -/,");
        if ($clean === '') {
            $clean = trim(preg_replace('/\s+/', ' ', preg_replace('/[0-9\/]/', ' ', $name)));
        }

        $business = (bool) preg_match('/\b(inc|llc|lic|clinic|bakery|grocery|market|enterprises|associates|pllc|distributors|bookstore|group home|properties|community|dba|mini mart|trans|company)\b/i', $name);

        return $this->splitName($clean, $business) + [
            'base' => $this->baseKey($clean),
            'display' => $clean,
            'cards' => $cards,
            'method' => $method,
            'methodLabel' => $methodLabel,
            'email' => $email,
            'placeholder' => false,
        ];
    }

    private function splitName(string $clean, bool $business): array
    {
        if ($business) {
            return ['display' => $clean, 'first' => Str::limit($clean, 250, ''), 'last' => '', 'business' => true];
        }
        $parts = preg_split('/\s+/', trim($clean)) ?: [$clean];
        $first = array_shift($parts) ?: 'Donor';

        return ['display' => $clean, 'first' => $first, 'last' => implode(' ', $parts), 'business' => false];
    }

    private function baseKey(string $clean): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $clean)));
    }

    private function method(string $name): array
    {
        $n = strtolower($name);
        return match (true) {
            str_contains($n, 'credit/zelle/cash') => ['credit', 'Credit/Zelle/Cash'],
            str_contains($n, 'check'), str_contains($n, 'cheque') => ['check', 'Check'],
            str_contains($n, 'giftcard'), str_contains($n, 'gift card') => ['giftcard', 'Gift card'],
            str_contains($n, 'zelle'), str_contains($n, 'zella') => ['zelle', 'Zelle'],
            str_contains($n, 'cash') => ['cash', 'Cash'],
            str_contains($n, 'credit'), str_contains($n, 'c/') => ['credit', 'Credit'],
            default => ['unknown', ''],
        };
    }

    // ------------------------------------------------------------------ programs / rent

    private function isProgram(string $name): bool
    {
        $keys = ['donation box', 'food sale', 'bake sale', 'camp fee', 'camp fund', 'camp food',
            'membership fees', 'security', 'maamoul', 'art school', 'islamic school', 'iftar',
            'fitrana', 'special project', 'food bank', 'cemetery', 'ramadan qutayef', 'ramadan qutayef'];
        $n = strtolower($name);
        foreach ($keys as $k) {
            if (str_contains($n, $k)) {
                return true;
            }
        }

        return false;
    }

    private function programFund(string $name): array
    {
        $n = strtolower($name);
        return match (true) {
            str_contains($n, 'zakat') => ['General — Zakat', 'zakat'],
            str_contains($n, 'sadaqah') => ['General — Sadaqah', 'sadaqah'],
            str_contains($n, 'fitrana'), str_contains($n, 'fitir') => ['General — Fitra', 'fitra'],
            str_contains($n, 'islamic school'), str_contains($n, 'art school'), str_contains($n, 'maamoul'), str_contains($n, 'bake sale') => ['Islamic School', 'general'],
            str_contains($n, 'camp') => ['Camp', 'general'],
            str_contains($n, 'cemetery') => ['Cemetery', 'general'],
            str_contains($n, 'membership') => ['Membership', 'general'],
            str_contains($n, 'iftar'), str_contains($n, 'qutayef'), str_contains($n, 'food sale'), str_contains($n, 'food bank') => ['Fundraising', 'general'],
            str_contains($n, 'special project') => ['Special Project', 'general'],
            default => ['General Donation', 'general'],
        };
    }

    private function isRent(string $name): bool
    {
        return (bool) preg_match('/^\s*(rent |masj?id? house|masjd house|school house rent)/i', $name);
    }

    private function parseProperty(string $name): array
    {
        // "Rent White House 1/1904 S Mebane St" | "Masjd House 3/ 704 Longest St - Anthony"
        $tenant = null;
        if (preg_match('/-\s*([A-Za-z][A-Za-z .]+)$/', $name, $t)) {
            $tenant = trim($t[1]);
            $name = trim(preg_replace('/-\s*[A-Za-z][A-Za-z .]+$/', '', $name));
        }
        $label = trim(preg_replace('/^\s*rent\s+/i', '', $name));
        [$pname, $addr] = array_pad(preg_split('#/#', $label, 2), 2, null);

        return [
            'name' => trim($pname),
            'address' => $addr ? trim($addr) : null,
            'tenant' => $tenant,
        ];
    }

    // ------------------------------------------------------------------ reporting

    private function report(array $plan, Masjid $masjid, string $batch, bool $execute): void
    {
        $donorGifts = fn ($d) => array_sum(array_column($d['gifts'], 'cents'));
        $donorTotal = array_sum(array_map($donorGifts, $plan['donors']));
        $phTotal = array_sum(array_map(fn ($p) => array_sum(array_column($p['gifts'], 'cents')), $plan['placeholders']));
        $progTotal = array_sum(array_map(fn ($p) => array_sum(array_column($p['gifts'], 'cents')), $plan['programs']));

        $this->info(($execute ? 'EXECUTE' : 'DRY RUN') . " — masjid {$masjid->name} (#{$masjid->id}), batch {$batch}");
        $this->table(['Bucket', 'Records', 'Total ($)'], [
            ['Named donors (merged)', count($plan['donors']), number_format($donorTotal / 100)],
            ['Placeholder card donors', count($plan['placeholders']), number_format($phTotal / 100)],
            ['Program funds income', count($plan['programs']), number_format($progTotal / 100)],
            ['Rental properties', count($plan['rents']), '—'],
            ['Skipped (totals/expenses)', count($plan['skipped']), '—'],
        ]);

        $cards = array_sum(array_map(fn ($d) => count($d['cards']), $plan['donors']));
        $gifts = array_sum(array_map(fn ($d) => count($d['gifts']), $plan['donors']))
            + array_sum(array_map(fn ($p) => count($p['gifts']), $plan['placeholders']))
            + array_sum(array_map(fn ($p) => count($p['gifts']), $plan['programs']));
        $this->line("  → will create: " . (count($plan['donors']) + count($plan['placeholders'])) . " contacts, {$cards} cards, ~{$gifts} offline donations, "
            . count($plan['rents']) . " properties.");

        $top = $plan['donors'];
        uasort($top, fn ($a, $b) => array_sum(array_column($b['gifts'], 'cents')) <=> array_sum(array_column($a['gifts'], 'cents')));
        $this->line("\nTop 10 donors (sanity-check against the sheet):");
        foreach (array_slice($top, 0, 10, true) as $d) {
            $this->line(sprintf('  %-34s $%s  (%d gifts, cards: %s)',
                Str::limit($d['display'], 33), number_format($donorGifts($d) / 100), count($d['gifts']),
                $d['cards'] ? implode(',', $d['cards']) : '—'));
        }
    }

    private function manifest(array $plan): array
    {
        return [
            'contacts' => array_values(array_map(fn ($d) => [
                'name' => $d['display'], 'business' => $d['business'], 'email' => $d['email'],
                'cards' => $d['cards'], 'gift_count' => count($d['gifts']),
                'total' => array_sum(array_column($d['gifts'], 'cents')) / 100,
            ], $plan['donors'])),
            'placeholders' => array_map(fn ($p) => [
                'name' => $p['display'], 'cards' => $p['cards'],
                'total' => array_sum(array_column($p['gifts'], 'cents')) / 100,
            ], $plan['placeholders']),
            'programs' => array_map(fn ($p) => [
                'raw' => $p['raw'], 'fund' => $p['fund'][0],
                'total' => array_sum(array_column($p['gifts'], 'cents')) / 100,
            ], $plan['programs']),
            'properties' => array_map(fn ($r) => [
                'name' => $r['name'], 'address' => $r['address'], 'tenant' => $r['tenant'],
                'payment_count' => count($r['payments']),
            ], $plan['rents']),
            'skipped' => $plan['skipped'],
        ];
    }

    // ------------------------------------------------------------------ writing

    private function write(array $plan, Masjid $masjid, string $batch): void
    {
        DB::transaction(function () use ($plan, $masjid, $batch) {
            $mid = $masjid->id;
            $funds = [];   // name → Fund
            $fund = function (string $name, string $type) use (&$funds, $mid, $batch) {
                if (! isset($funds[$name])) {
                    $funds[$name] = Fund::withoutGlobalScopes()->firstOrCreate(
                        ['masjid_id' => $mid, 'name' => $name],
                        ['type' => $type, 'receiptable' => $type !== 'general' ? true : true, 'is_active' => true],
                    );
                }

                return $funds[$name];
            };
            $general = $fund('General Donation', 'general');

            $bar = $this->output->createProgressBar(count($plan['donors']) + count($plan['placeholders']) + count($plan['programs']) + count($plan['rents']));

            foreach (array_merge(array_values($plan['donors']), array_values($plan['placeholders'])) as $d) {
                $isPlaceholder = ($d['first'] ?? null) === null ? true : ! empty($d['placeholder'] ?? false);
                $contact = Contact::withoutGlobalScopes()->create([
                    'masjid_id' => $mid,
                    'first_name' => Str::limit($d['first'] ?: 'Donor', 250, ''),
                    'last_name' => Str::limit($d['last'] ?? '', 250, ''),
                    'email' => $d['email'] ?? null,
                    'is_placeholder' => ! empty($d['placeholder']),
                    'notes' => ! empty($d['business']) ? 'Business/organization' : null,
                    'import_batch' => $batch,
                ]);
                foreach ($d['cards'] as $last4) {
                    ContactCard::withoutGlobalScopes()->firstOrCreate(
                        ['contact_id' => $contact->id, 'last4' => $last4],
                        ['masjid_id' => $mid],
                    );
                }
                foreach ($d['gifts'] as $i => $g) {
                    $this->makeDonation($mid, $general->id, $contact->id, $g, $batch, $i);
                }
                $bar->advance();
            }

            foreach ($plan['programs'] as $p) {
                [$fname, $ftype] = $p['fund'];
                $f = $fund($fname, $ftype);
                foreach ($p['gifts'] as $i => $g) {
                    $this->makeDonation($mid, $f->id, null, $g + ['method' => 'unknown', 'note' => $p['raw']], $batch, $i);
                }
                $bar->advance();
            }

            foreach ($plan['rents'] as $r) {
                $prop = Property::withoutGlobalScopes()->create([
                    'masjid_id' => $mid, 'name' => $r['name'], 'address' => $r['address'],
                    'tenant_name' => $r['tenant'], 'is_active' => true, 'import_batch' => $batch,
                ]);
                foreach ($r['payments'] as $g) {
                    RentPayment::withoutGlobalScopes()->create([
                        'masjid_id' => $mid, 'property_id' => $prop->id,
                        'paid_on' => $g['date'], 'amount' => $g['cents'],
                        'import_batch' => $batch,
                    ]);
                }
                $bar->advance();
            }
            $bar->finish();
        });
    }

    private function makeDonation(int $mid, int $fundId, ?int $contactId, array $g, string $batch, int $i): void
    {
        Donation::withoutGlobalScopes()->create([
            'masjid_id' => $mid,
            'contact_id' => $contactId,
            'fund_id' => $fundId,
            'type' => 'one_time',
            'source' => 'offline',
            'payment_method' => $g['method'] ?? 'unknown',
            'donated_at' => $g['date'],
            'note' => $g['note'] ?? null,
            'intended_amount' => $g['cents'],
            'charged_amount' => $g['cents'],
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'status' => 'succeeded',
            'idempotency_key' => 'imp_' . $batch . '_' . substr(md5(($contactId ?? 'p') . $fundId . $g['date'] . $g['cents'] . $i), 0, 20),
            'import_batch' => $batch,
        ]);
    }

    private function rollback(int $mid, string $tag): int
    {
        if (! $this->option('execute')) {
            $counts = [
                'donations' => Donation::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->count(),
                'contacts' => Contact::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->count(),
                'rent_payments' => RentPayment::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->count(),
                'properties' => Property::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->count(),
            ];
            $this->warn("Rollback DRY RUN for batch {$tag}: " . json_encode($counts) . ". Add --execute to delete.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mid, $tag) {
            Donation::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->delete();
            RentPayment::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->delete();
            Property::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->forceDelete();
            $ids = Contact::withoutGlobalScopes()->where('masjid_id', $mid)->where('import_batch', $tag)->pluck('id');
            ContactCard::withoutGlobalScopes()->whereIn('contact_id', $ids)->delete();
            Contact::withoutGlobalScopes()->whereIn('id', $ids)->forceDelete();
        });
        $this->info("Rolled back batch {$tag}.");

        return self::SUCCESS;
    }
}
