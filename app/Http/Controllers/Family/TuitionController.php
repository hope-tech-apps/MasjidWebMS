<?php

namespace App\Http\Controllers\Family;

use App\Models\{Masjid, TuitionAccount, TuitionChild};
use App\Services\FamilyLedger\{FamilyLedger, TuitionCharges, TuitionPayments, TuitionStatement};
use App\Support\SchoolCalendarReaders;
use Illuminate\Http\Request;

class TuitionController extends FamilyController
{
    private function account(int $id): TuitionAccount
    {
        app(FamilyLedger::class)->assertEnabled((int) $this->contact()->masjid_id);
        $account = TuitionAccount::findOrFail($id);
        abort_unless(app(FamilyLedger::class)->mayRead($account, $this->contact()), 403);
        return $account;
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        $ledger = app(FamilyLedger::class); $ledger->assertEnabled((int) $this->contact()->masjid_id);
        $accounts = TuitionAccount::all()->filter(fn ($a) => $ledger->mayRead($a, $this->contact()))->map(fn ($a) => $this->summary($a))->values();
        return response()->json(['status' => 'success', 'data' => ['accounts' => $accounts]]);
    }

    public function summary(TuitionAccount $account): array
    {
        $ledger = app(FamilyLedger::class); $today = SchoolCalendarReaders::for($account->masjid_id)->today();
        $due = $ledger->due($account); $school = Masjid::findOrFail($account->masjid_id);
        $prospective = app(TuitionCharges::class)->projectedDue($school, $account, stopAtNext: true);
        $next = $prospective[0]['due_on'] ?? null;
        $nextAmount = $next ? collect($prospective)->where('due_on', $next)->sum('amount_minor') : 0;
        $children = \App\Models\Contact::withTrashed()->whereIn('id', TuitionChild::where('account_id', $account->id)->pluck('contact_id'))->get()->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)])->all();
        return ['school_today' => $today, 'id' => $account->id, 'currency' => $account->currency, 'balance_minor' => $ledger->balance($account), 'minimum_payment_minor' => max($account->minimum_payment_minor, \App\Services\FamilyLedger\TuitionSettings::for((int) $account->masjid_id)->minimum_payment_minor), 'next_due_on' => $next, 'next_due_minor' => $nextAmount, 'due' => $due, 'children' => $children, 'card_available' => $school->canAcceptDonations()];
    }

    public function show(Request $request, $masjid_id, $account_id): \Illuminate\Http\JsonResponse
    {
        $account = $this->account((int) $account_id); $range = $this->range($request, $account);
        return response()->json(['status' => 'success', 'data' => $this->summary($account) + ['statement' => app(TuitionStatement::class)->data($account, $range['from'], $range['to'])]]);
    }

    public function range(Request $request, TuitionAccount $account): array
    {
        return \Illuminate\Support\Facades\Validator::make(['from' => $request->input('from', '1900-01-01'), 'to' => $request->input('to', SchoolCalendarReaders::for($account->masjid_id)->today())], ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
    }

    public function statement(Request $request, $masjid_id, $account_id): \Illuminate\Http\Response
    {
        $account = $this->account((int) $account_id); $range = $this->range($request, $account);
        return response(app(TuitionStatement::class)->pdf($account, $range['from'], $range['to']))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="tuition-statement.pdf"')->header('Cache-Control', 'private, no-store');
    }

    public function checkout(Request $request, $masjid_id, $account_id): \Illuminate\Http\JsonResponse
    {
        $account = $this->account((int) $account_id); $data = $request->validate(['amount_minor' => 'required|integer|min:1|max:999999999']);
        return response()->json(['status' => 'success', 'data' => app(TuitionPayments::class)->start($account, $this->contact(), (int) $data['amount_minor'])]);
    }
}
