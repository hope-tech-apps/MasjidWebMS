<?php

namespace App\Services\FamilyLedger;

use App\Models\{Contact, FeePlan, GroupMembership, Masjid, SchoolYear, TuitionAccount, TuitionChild, TuitionLine, TuitionSchedule};
use App\Support\SchoolCalendarReaders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Dates create debt only. This class has no payment provider or mail dependency. */
class TuitionCharges
{
    public function __construct(private FamilyLedger $ledger) {}

    public function periods(TuitionSchedule $schedule): array
    {
        $year = SchoolYear::where('masjid_id', $schedule->masjid_id)->with('terms')->findOrFail($schedule->school_year_id);
        if ($schedule->cadence === 'yearly') return [['key' => 'year:'.$year->id, 'due_on' => $year->first_day->toDateString()]];
        if ($schedule->cadence === 'term') return $year->terms->map(fn ($t) => ['key' => 'term:'.$year->id.':'.$t->position, 'due_on' => $t->starts_on->toDateString()])->all();
        $out = []; $month = CarbonImmutable::parse($year->first_day->toDateString())->startOfMonth();
        while ($month->toDateString() <= $year->last_day->toDateString()) {
            $date = $month->day(min($schedule->due_day, $month->daysInMonth))->toDateString();
            if ($date >= $year->first_day->toDateString() && $date <= $year->last_day->toDateString()) $out[] = ['key' => $month->format('Y-m'), 'due_on' => $date];
            $month = $month->addMonth();
        }
        return $out;
    }

    /** Also used for before-due reminders; future charges are never prematurely posted. */
    public function preview(Masjid $school, string $date): array
    {
        if (! FamilyLedger::enabled($school)) return [];
        $out = [];
        foreach (TuitionSchedule::where('masjid_id', $school->id)->where('is_active', true)->get() as $schedule) {
            $period = collect($this->periods($schedule))->firstWhere('due_on', $date);
            if (! $period) continue;
            $plan = FeePlan::where('masjid_id', $school->id)->findOrFail($schedule->fee_plan_id);
            $members = GroupMembership::where('masjid_id', $school->id)->participants()->current()->when($schedule->group_ids, fn ($q) => $q->whereIn('group_id', $schedule->group_ids))->when($schedule->grades, fn ($q) => $q->whereIn('grade_label', $schedule->grades))->pluck('contact_id')->unique()->all();
            $children = TuitionChild::where('masjid_id', $school->id)->whereIn('contact_id', $members)->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))->get();
            foreach ($children->groupBy('account_id') as $accountId => $siblings) {
                $contacts = Contact::where('masjid_id', $school->id)->whereIn('id', $siblings->pluck('contact_id'))->get()->sortBy(fn ($c) => ($c->dateOfBirthOrNull() ?? '9999-12-31').':'.str_pad((string) $c->id, 20, '0', STR_PAD_LEFT))->values();
                foreach ($contacts as $rank => $child) {
                    $key = 'charge:'.$plan->id.':'.$child->id.':'.$period['key'];
                    $out[] = ['account_id' => (int) $accountId, 'child_contact_id' => $child->id, 'fee_plan_id' => $plan->id, 'period' => $period['key'], 'due_on' => $date, 'amount_minor' => $plan->amount_minor, 'discount_minor' => $rank === 0 ? 0 : intdiv($plan->amount_minor * $schedule->sibling_percent, 100), 'currency' => $plan->currency, 'reason' => $plan->label, 'idempotency_key' => $key];
                }
            }
        }
        return $out;
    }

    /** Preview only as far as the next unpaid due date, or through an explicit reminder date. */
    public function projectedDue(Masjid $school, TuitionAccount $account, ?string $through = null, bool $stopAtNext = false): array
    {
        $today = SchoolCalendarReaders::for($school->id)->today();
        $due = $this->ledger->due($account);
        if ($stopAtNext && $due && $due[0]['due_on'] <= $today) return $due;
        $dates = TuitionSchedule::where('masjid_id', $school->id)->where('is_active', true)->get()->flatMap(fn ($s) => array_column($this->periods($s), 'due_on'))->filter(fn ($d) => $d >= $today && ($through === null || $d <= $through))->unique()->sort()->values();
        $posted = TuitionLine::where('masjid_id', $school->id)->where('account_id', $account->id)->pluck('idempotency_key')->flip();
        $planned = [];
        foreach ($dates as $date) {
            foreach ($this->preview($school, $date) as $row) {
                if ((int) $row['account_id'] !== (int) $account->id || $posted->has($row['idempotency_key'])) continue;
                $planned[] = ['line_id' => null, 'child_contact_id' => $row['child_contact_id'], 'due_on' => $date, 'amount_minor' => $row['amount_minor'] - $row['discount_minor']];
            }
            $due = $this->ledger->due($account, $planned);
            if ($stopAtNext && $due && $due[0]['due_on'] <= $date) break;
        }
        return $due;
    }

    public function post(Masjid $school): int
    {
        if (! FamilyLedger::enabled($school)) return 0;
        $date = SchoolCalendarReaders::for($school->id)->today(); $count = 0;
        foreach ($this->preview($school, $date) as $row) {
            $count += DB::transaction(function () use ($row, $school, $date) {
                $account = TuitionAccount::where('masjid_id', $school->id)->lockForUpdate()->findOrFail($row['account_id']);
                $this->ledger->require($row['currency'] === $account->currency, 'Plan and account currencies must match.');
                if (TuitionLine::where('masjid_id', $school->id)->where('idempotency_key', $row['idempotency_key'])->exists()) return 0;
                $data = $row; unset($data['discount_minor'], $data['account_id']);
                $charge = $this->ledger->append($account, $data + ['kind' => 'charge', 'effective_on' => $date, 'source' => 'schedule', 'recorded_by_label' => 'Scheduled fee plan']);
                if ($row['discount_minor'] > 0) $this->ledger->append($account, ['kind' => 'sibling_discount', 'amount_minor' => -$row['discount_minor'], 'child_contact_id' => $row['child_contact_id'], 'fee_plan_id' => $row['fee_plan_id'], 'original_line_id' => $charge->id, 'period' => $row['period'], 'effective_on' => $date, 'reason' => 'Sibling discount', 'source' => 'schedule', 'recorded_by_label' => 'Scheduled fee plan', 'idempotency_key' => 'sibling:'.$charge->id]);
                return 1;
            });
        }
        return $count;
    }
}
