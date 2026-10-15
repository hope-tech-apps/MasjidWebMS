<?php

namespace App\Services\FamilyLedger;

use App\Models\{Contact, FeePlan, Masjid, TuitionAccount, TuitionGuardian, TuitionLine};
use App\Support\SchoolCalendarReaders;
use Carbon\CarbonImmutable;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Financial statements are tuition records, never charitable tax receipts. */
class TuitionStatement
{
    public function __construct(private FamilyLedger $ledger, private \App\Services\Receipts\Letterhead $letterhead) {}

    /** Family projection is an allowlist. Free-text legacy reasons are staff notes, never descriptions. */
    public function data(TuitionAccount $account, string $from, string $to, bool $office = false): array
    {
        $query = TuitionLine::where('masjid_id', $account->masjid_id)->where('account_id', $account->id);
        $opening = (int) (clone $query)->whereDate('effective_on', '<', $from)->sum('amount_minor');
        $lines = (clone $query)->whereDate('effective_on', '>=', $from)->whereDate('effective_on', '<=', $to)->orderBy('effective_on')->orderBy('id')->get();
        $children = Contact::withTrashed()->where('masjid_id', $account->masjid_id)->whereIn('id', $lines->pluck('child_contact_id')->filter())->get()->keyBy('id');
        $plans = FeePlan::where('masjid_id', $account->masjid_id)->whereIn('id', $lines->pluck('fee_plan_id')->filter())->pluck('label', 'id');
        return ['account_id' => $account->id, 'family_name' => $this->familyName($account), 'currency' => $account->currency, 'from' => $from, 'to' => $to, 'opening_minor' => $opening, 'closing_minor' => $opening + $lines->sum('amount_minor'), 'lines' => $lines->map(function ($l) use ($children, $plans, $office, $account) {
            $child = $children->get($l->child_contact_id);
            return ['id' => $l->id, 'date' => $l->effective_on->toDateString(), 'due_on' => $l->due_on?->toDateString(), 'child' => $child ? trim($child->first_name.' '.$child->last_name) : null, 'kind' => $l->kind, 'kind_label' => FamilyLedger::kindLabel($l->kind), 'amount_minor' => $l->amount_minor, 'description' => $l->description ?: ($l->kind === 'charge' ? $plans->get($l->fee_plan_id) : null) ?: FamilyLedger::kindLabel($l->kind), 'method' => $l->method, 'method_label' => FamilyLedger::methodLabel($l->method), 'original_line_id' => $l->original_line_id]
                + ($office ? ['note' => $l->reason, 'reference' => $l->reference, 'stripe_payment_intent_id' => $l->stripe_payment_intent_id, 'recorded_by' => $l->recorded_by_label, 'recorded_at' => self::timestamp($l->created_at, (int) $account->masjid_id), 'needs_review' => $l->needs_review] : []);
        })->all()];
    }

    /** The account's explicitly confirmed guardian names identify its family on statements and office exports. */
    public function familyName(TuitionAccount $account): string
    {
        return Contact::withTrashed()->where('masjid_id', $account->masjid_id)->whereIn('id', TuitionGuardian::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->whereNotNull('confirmed_at')->pluck('contact_id'))->orderBy('id')->get()->map(fn ($c) => trim($c->first_name.' '.$c->last_name))->implode(', ');
    }

    /** Current dated school year, falling back to first financial line, then school today. */
    public static function defaultFrom(int $school, ?TuitionAccount $account = null): string
    {
        $calendar = SchoolCalendarReaders::for($school); $today = $calendar->today();
        $year = $calendar->years()->filter(fn ($y) => $y->first_day->toDateString() <= $today && $y->last_day->toDateString() >= $today)->sortByDesc('first_day')->first();
        $first = TuitionLine::where('masjid_id', $school)->when($account, fn ($q) => $q->where('account_id', $account->id))->min('effective_on');
        return $year?->first_day->toDateString() ?? ($first ? CarbonImmutable::parse($first)->toDateString() : $today);
    }

    /** Date-only values are school calendar days and must never be timezone shifted. */
    public static function date(string $day): string { return CarbonImmutable::parse($day)->format('M j, Y'); }
    /** Audit instants use the school timezone, matching LetterGrades server-side formatting. */
    public static function timestamp(?\DateTimeInterface $at, int $school): ?string { return $at ? CarbonImmutable::instance($at)->setTimezone(SchoolCalendarReaders::for($school)->timezone())->format('M j, Y, g:i A') : null; }

    /** Exact money units for CSV; no float conversion or currency prefix. */
    public static function decimal(int $minor, string $currency): string
    {
        $currency = strtolower($currency);
        $scale = in_array($currency, ['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'], true) ? 0 : (in_array($currency, ['bhd','jod','kwd','omr','tnd'], true) ? 3 : 2);
        $value = str_pad((string) abs($minor), $scale + 1, '0', STR_PAD_LEFT);
        return ($minor < 0 ? '-' : '').($scale ? substr($value, 0, -$scale).'.'.substr($value, -$scale) : $value);
    }

    /** Family money uses the school's currency symbol and its exact decimal scale. */
    public static function money(int $minor, string $currency): string
    {
        $value = self::decimal($minor, $currency);
        $format = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $scale = strlen(explode('.', $value)[1] ?? '');
        $format->setTextAttribute(\NumberFormatter::CURRENCY_CODE, strtoupper($currency));
        $format->setAttribute(\NumberFormatter::FRACTION_DIGITS, $scale);
        return $format->formatCurrency((float) $value, strtoupper($currency));
    }

    public function html(TuitionAccount $account, string $from, string $to): string
    {
        $data = $this->data($account, $from, $to); $school = Masjid::findOrFail($account->masjid_id);
        $letterhead = $this->letterhead->forMasjid($school);
        $contact = implode(' · ', array_filter([$school->email, $letterhead['phone'], $letterhead['website']]));
        return view('pdf.tuition-statement', ['statement' => $data, 'school' => $school, 'letterhead' => $letterhead, 'contact' => $contact, 'date' => fn ($v) => self::date($v), 'money' => fn ($v) => self::money($v, $account->currency)])->render();
    }

    public function pdf(TuitionAccount $account, string $from, string $to): string
    {
        $directory = storage_path('app/mpdf'); if (! is_dir($directory)) mkdir($directory, 0700, true);
        $pdf = new Mpdf(['tempDir' => $directory, 'format' => 'Letter', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->WriteHTML($this->html($account, $from, $to));
        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
