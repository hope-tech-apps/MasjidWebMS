<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{Contact, FeePlan, Group, GroupMembership, Masjid, Offering, SchoolYear, TuitionAccount, TuitionChild, TuitionGuardian, TuitionLine, TuitionMail, TuitionSchedule};
use App\Services\FamilyLedger\{FamilyLedger, TuitionCharges, TuitionReminders, TuitionStatement};
use App\Support\SchoolCalendarReaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TuitionController extends Controller
{
    private function school(): Masjid
    {
        $school = Masjid::findOrFail(app(\App\Support\TenantContext::class)->get());
        abort_unless(FamilyLedger::enabled($school), 403); return $school;
    }
    private function ok(mixed $data): \Illuminate\Http\JsonResponse { return response()->json(['status' => 'success', 'data' => $data]); }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $school = $this->school(); $today = SchoolCalendarReaders::for($school->id)->today(); $ledger = app(FamilyLedger::class);
        $rows = TuitionAccount::orderBy('id')->get()->map(function ($a) use ($ledger, $today) {
            $children = Contact::withTrashed()->whereIn('id', TuitionChild::where('account_id', $a->id)->pluck('contact_id'))->get()->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)])->all();
            $due = $ledger->due($a);
            return ['id' => $a->id, 'children' => $children, 'currency' => $a->currency, 'balance_minor' => $ledger->balance($a), 'overdue_minor' => collect($due)->filter(fn ($d) => $d['due_on'] < $today)->sum('amount_minor')];
        });
        if ($request->boolean('overdue')) $rows = $rows->filter(fn ($r) => $r['overdue_minor'] > 0)->values();
        return $this->ok(['accounts' => $rows, 'card_available' => $school->canAcceptDonations()]);
    }

    public function options(): \Illuminate\Http\JsonResponse
    {
        $this->school();
        $participants = GroupMembership::participants()->current()->get(['contact_id', 'group_id', 'grade_label']);
        $guardians = GroupMembership::where('role', 'guardian')->confirmed()->get(['contact_id', 'guardian_of_contact_id']);
        $ids = $participants->pluck('contact_id')->merge($guardians->pluck('contact_id'))->unique();
        return $this->ok(['contacts' => Contact::whereIn('id', $ids)->orderBy('first_name')->get()->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]), 'participants' => $participants, 'guardians' => $guardians,
            'offerings' => Offering::orderBy('name')->get(['id', 'name']), 'years' => SchoolYear::with('terms')->get()->map(fn ($y) => ['id' => $y->id, 'label' => $y->label, 'terms' => $y->terms->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])]),
            'groups' => Group::where('kind', 'class')->get(['id', 'name']), 'grades' => $participants->pluck('grade_label')->filter()->unique()->values(), 'school_today' => SchoolCalendarReaders::for((int) $this->school()->id)->today(), 'methods' => FamilyLedger::METHODS, 'billing_settings' => \App\Services\FamilyLedger\TuitionSettings::for((int) $this->school()->id), 'currency' => \App\Services\FamilyLedger\TuitionSettings::for((int) $this->school()->id)->currency]);
    }

    public function schoolSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        $school = $this->school();
        $data = $request->validate(['currency' => 'required|string|regex:/^[a-z]{3}$/', 'minimum_payment_minor' => 'required|integer|min:0|max:999999999', 'remind_before_days' => 'nullable|integer|min:1|max:60', 'remind_after_days' => 'nullable|integer|min:1|max:60']);
        return DB::transaction(function () use ($school, $data) {
            Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $settings = \App\Services\FamilyLedger\TuitionSettings::for((int) $school->id);
            app(FamilyLedger::class)->require($settings->currency === $data['currency'] || (! TuitionAccount::exists() && ! TuitionSchedule::exists()), 'Currency is fixed once accounts or plans exist.');
            $settings->fill($data)->save(); return $this->ok($settings->fresh());
        });
    }

    public function storeAccount(Request $request): \Illuminate\Http\JsonResponse
    {
        $school = $this->school(); $data = $request->validate(['children' => 'required|array|min:1|max:30', 'children.*' => 'required|integer|distinct', 'guardians' => 'required|array|min:1|max:10', 'guardians.*' => 'required|integer|distinct']);
        return $this->ok(app(FamilyLedger::class)->open($school, $data['children'], $data['guardians'], (int) $request->user()->id));
    }

    public function show(Request $request, $masjid_id, $account_id): \Illuminate\Http\JsonResponse
    {
        $this->school(); $account = TuitionAccount::findOrFail($account_id); $range = $this->range($request);
        $recipients = TuitionGuardian::where('account_id', $account->id)->get()->map(function ($g) use ($account) {
            $c = Contact::find($g->contact_id); return ['contact_id' => $g->contact_id, 'name' => $c ? trim($c->first_name.' '.$c->last_name) : '', 'eligible' => $c && app(FamilyLedger::class)->mayRead($account, $c), 'address' => $c ? TuitionReminders::address($c) : null];
        });
        return $this->ok(['account' => $account, 'balance_minor' => app(FamilyLedger::class)->balance($account), 'due' => app(FamilyLedger::class)->due($account), 'statement' => app(TuitionStatement::class)->data($account, $range['from'], $range['to'], true), 'recipients' => $recipients, 'mails' => TuitionMail::where('account_id', $account->id)->latest('id')->limit(100)->get(['id', 'kind', 'status', 'guardian_contact_id', 'from_on', 'to_on', 'due_items', 'created_at', 'sent_at'])]);
    }

    public function settings(Request $request, $masjid_id, $account_id): \Illuminate\Http\JsonResponse
    {
        $this->school(); $account = TuitionAccount::findOrFail($account_id);
        $data = $request->validate(['minimum_payment_minor' => 'required|integer|min:0|max:999999999', 'remind_before_days' => 'nullable|integer|min:1|max:60', 'remind_after_days' => 'nullable|integer|min:1|max:60']);
        $account->update($data); return $this->ok($account->fresh());
    }

    public function entry(Request $request, $masjid_id, $account_id): \Illuminate\Http\JsonResponse
    {
        $this->school(); $account = TuitionAccount::findOrFail($account_id);
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'kind' => ['required', Rule::in(['charge', 'credit', 'office_payment'])], 'amount_minor' => 'required|integer|min:1|max:999999999', 'effective_on' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:1000', 'method' => ['nullable', Rule::in(FamilyLedger::METHODS)], 'reference' => 'nullable|string|max:255', 'child_contact_id' => 'nullable|integer']);
        return $this->ok(app(FamilyLedger::class)->office($account, $data['kind'], (int) $data['amount_minor'], $data['effective_on'], $data['reason'], $request->user()->id, $data['method'] ?? null, $data['reference'] ?? null, isset($data['child_contact_id']) ? (int) $data['child_contact_id'] : null, $data['idempotency_key']));
    }

    public function void(Request $request, $masjid_id, $account_id, $line_id): \Illuminate\Http\JsonResponse
    {
        $this->school(); TuitionAccount::findOrFail($account_id); $line = TuitionLine::where('account_id', $account_id)->findOrFail($line_id);
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        return $this->ok(app(FamilyLedger::class)->void($line, $data['reason'], $request->user()->id));
    }

    private function range(Request $request): array
    {
        return $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
    }

    public function statement(Request $request, $masjid_id, $account_id): \Illuminate\Http\Response
    {
        $this->school(); $account = TuitionAccount::findOrFail($account_id); $range = $this->range($request);
        return response(app(TuitionStatement::class)->pdf($account, $range['from'], $range['to']))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="tuition-statement.pdf"')->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, $masjid_id, $account_id): \Illuminate\Http\JsonResponse
    {
        $this->school(); $account = TuitionAccount::findOrFail($account_id); $range = $this->range($request);
        return $this->ok(['queued' => app(TuitionReminders::class)->recipients($account, 'statement', $range['from'], $range['to'])]);
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->school(); $range = $this->range($request);
        return response()->streamDownload(function () use ($range) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account', 'Currency', 'Opening minor', 'Closing minor', 'Date', 'Child', 'Kind', 'Amount minor', 'Reason', 'Method', 'Reference', 'Recorded by', 'Entry', 'Original entry', 'Recorded at', 'Needs review', 'Stripe payment intent'], ',', '"', '');
            foreach (TuitionAccount::orderBy('id')->cursor() as $a) {
                $data = app(TuitionStatement::class)->data($a, $range['from'], $range['to'], true);
                foreach ($data['lines'] as $l) {
                    $cells = [$a->id, $a->currency, $data['opening_minor'], $data['closing_minor'], $l['date'], $l['child'], $l['kind'], $l['amount_minor'], $l['reason'], $l['method'], $l['reference'], $l['recorded_by'], $l['id'], $l['original_line_id'], $l['recorded_at'], $l['needs_review'] ? 'yes' : 'no', $l['stripe_payment_intent_id']];
                    $cells = array_map(fn ($v) => is_string($v) && preg_match('/^[\s]*[=+@-]/u', $v) ? "'".$v : $v, $cells);
                    fputcsv($out, $cells, ',', '"', '');
                }
            }
            fclose($out);
        }, 'tuition-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function schedules(): \Illuminate\Http\JsonResponse
    {
        $this->school();
        return $this->ok(TuitionSchedule::orderBy('id')->get()->map(fn ($s) => $s->toArray() + ['plan' => FeePlan::findOrFail($s->fee_plan_id), 'periods' => app(TuitionCharges::class)->periods($s)]));
    }

    public function storeSchedule(Request $request): \Illuminate\Http\JsonResponse
    {
        $school = $this->school();
        $data = $request->validate(['offering_id' => 'required|integer', 'school_year_id' => 'required|integer', 'label' => 'required|string|max:255', 'amount_minor' => 'required|integer|min:1|max:999999999', 'cadence' => ['required', Rule::in(['yearly', 'term', 'monthly'])], 'due_day' => 'required|integer|min:1|max:31', 'sibling_percent' => 'required|integer|min:0|max:100', 'group_ids' => 'present|array|max:200', 'group_ids.*' => 'integer|distinct', 'grades' => 'present|array|max:100', 'grades.*' => 'string|max:80']);
        Offering::findOrFail($data['offering_id']); $year = SchoolYear::with('terms')->findOrFail($data['school_year_id']);
        foreach ($data['group_ids'] as $id) Group::where('kind', 'class')->findOrFail($id);
        $ledger = app(FamilyLedger::class);
        $ledger->require($data['group_ids'] !== [] || $data['grades'] !== [], 'Choose classes or grades.');
        $ledger->require($data['cadence'] !== 'term' || $year->terms->isNotEmpty(), 'Configure dated school terms first.');
        return DB::transaction(function () use ($school, $request, $data) {
            Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $settings = \App\Services\FamilyLedger\TuitionSettings::for((int) $school->id); $settings->save();
            $plan = FeePlan::create(['masjid_id' => $school->id, 'offering_id' => $data['offering_id'], 'kind' => FeePlan::KIND_ONE_TIME, 'amount_minor' => $data['amount_minor'], 'currency' => $settings->currency, 'label' => $data['label'], 'is_active' => false]);
            return $this->ok(TuitionSchedule::create(['masjid_id' => $school->id, 'fee_plan_id' => $plan->id, 'school_year_id' => $data['school_year_id'], 'cadence' => $data['cadence'], 'due_day' => $data['due_day'], 'sibling_percent' => $data['sibling_percent'], 'group_ids' => $data['group_ids'], 'grades' => $data['grades'], 'created_by_user_id' => $request->user()->id]));
        });
    }

    public function deactivate(Request $request, $masjid_id, $schedule_id): \Illuminate\Http\JsonResponse
    {
        $this->school(); $schedule = TuitionSchedule::findOrFail($schedule_id); $schedule->update(['is_active' => false]); return $this->ok($schedule);
    }
}
