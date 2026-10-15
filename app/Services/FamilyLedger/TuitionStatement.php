<?php

namespace App\Services\FamilyLedger;

use App\Models\{Contact, Masjid, TuitionAccount, TuitionLine};
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Financial statements are tuition records, never charitable tax receipts. */
class TuitionStatement
{
    public function __construct(private FamilyLedger $ledger, private \App\Services\Receipts\Letterhead $letterhead) {}

    public function data(TuitionAccount $account, string $from, string $to, bool $office = false): array
    {
        $query = TuitionLine::where('masjid_id', $account->masjid_id)->where('account_id', $account->id);
        $opening = (int) (clone $query)->whereDate('effective_on', '<', $from)->sum('amount_minor');
        $lines = (clone $query)->whereDate('effective_on', '>=', $from)->whereDate('effective_on', '<=', $to)->orderBy('effective_on')->orderBy('id')->get();
        $children = Contact::withTrashed()->where('masjid_id', $account->masjid_id)->whereIn('id', $lines->pluck('child_contact_id')->filter())->get()->keyBy('id');
        return ['account_id' => $account->id, 'currency' => $account->currency, 'from' => $from, 'to' => $to, 'opening_minor' => $opening, 'closing_minor' => $opening + $lines->sum('amount_minor'), 'lines' => $lines->map(function ($l) use ($children, $office) {
            $child = $children->get($l->child_contact_id);
            return ['id' => $l->id, 'date' => $l->effective_on->toDateString(), 'due_on' => $l->due_on?->toDateString(), 'child' => $child ? trim($child->first_name.' '.$child->last_name) : null, 'kind' => $l->kind, 'amount_minor' => $l->amount_minor, 'reason' => $l->reason, 'method' => $l->method, 'original_line_id' => $l->original_line_id, 'recorded_at' => $l->created_at?->toIso8601String()]
                + ($office ? ['reference' => $l->reference, 'stripe_payment_intent_id' => $l->stripe_payment_intent_id, 'recorded_by' => $l->recorded_by_label, 'needs_review' => $l->needs_review] : []);
        })->all()];
    }

    /** Exact minor-unit display, including Stripe's zero/three-decimal currencies. */
    public static function money(int $minor, string $currency): string
    {
        $currency = strtolower($currency);
        $scale = in_array($currency, ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'], true) ? 0 : (in_array($currency, ['bhd','jod','kwd','omr','tnd'], true) ? 3 : 2);
        $value = str_pad((string) abs($minor), $scale + 1, '0', STR_PAD_LEFT);
        return strtoupper($currency).' '.($minor < 0 ? '-' : '').($scale ? substr($value, 0, -$scale).'.'.substr($value, -$scale) : $value);
    }

    public function html(TuitionAccount $account, string $from, string $to): string
    {
        $data = $this->data($account, $from, $to); $school = Masjid::findOrFail($account->masjid_id);
        return view('pdf.tuition-statement', ['statement' => $data, 'school' => $school, 'letterhead' => $this->letterhead->forMasjid($school), 'money' => fn ($v) => self::money($v, $account->currency)])->render();
    }

    public function pdf(TuitionAccount $account, string $from, string $to): string
    {
        $directory = storage_path('app/mpdf'); if (! is_dir($directory)) mkdir($directory, 0700, true);
        $pdf = new Mpdf(['tempDir' => $directory, 'format' => 'Letter', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->WriteHTML($this->html($account, $from, $to));
        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
