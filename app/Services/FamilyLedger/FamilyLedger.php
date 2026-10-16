<?php

namespace App\Services\FamilyLedger;

use App\Models\{Contact, GroupMembership, Masjid, TuitionAccount, TuitionChild, TuitionGuardian, TuitionLine, User};
use App\Support\CartTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The single ledger writer. Each account row is the mutex for every financial write. */
class FamilyLedger
{
    public const METHODS = ['cash', 'check', 'zelle', 'cash_app', 'venmo', 'bank_transfer', 'other'];
    public const KINDS = ['charge', 'sibling_discount', 'credit', 'office_payment', 'card_payment', 'refund', 'void'];

    public static function enabled(?Masjid $school): bool
    {
        return $school !== null && ! $school->trashed() && $school->isSchool() && $school->hasCapability('tuition_ledger');
    }

    public function assertEnabled(int $school): void { abort_unless(self::enabled(Masjid::find($school)), 403); }
    /** Sum all history, or only entries effective by the supplied school calendar day. */
    public function balance(TuitionAccount $account, ?string $asOf = null): int { return (int) TuitionLine::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->when($asOf, fn ($q) => $q->whereDate('effective_on', '<=', $asOf))->sum('amount_minor'); }

    /** Stable storage keys have explicit human-readable labels on every tuition surface. */
    public static function methodLabel(?string $method): ?string
    {
        return $method === null ? null : (['cash' => 'Cash', 'check' => 'Check', 'zelle' => 'Zelle', 'cash_app' => 'Cash App', 'venmo' => 'Venmo', 'bank_transfer' => 'Bank transfer', 'other' => 'Other', 'stripe' => 'Card'][$method] ?? 'Other');
    }

    /** Safe family-facing defaults never reuse an office note. */
    public static function kindLabel(string $kind): string
    {
        return ['charge' => 'Charge', 'sibling_discount' => 'Sibling discount', 'credit' => 'Credit', 'office_payment' => 'Payment', 'card_payment' => 'Card payment', 'refund' => 'Refund', 'void' => 'Correction'][$kind];
    }

    public function open(Masjid $school, array $children, array $guardians, int $actor): TuitionAccount
    {
        $this->assertEnabled((int) $school->id);
        return DB::transaction(function () use ($school, $children, $guardians, $actor) {
            Masjid::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $children = array_values(array_unique($children)); $guardians = array_values(array_unique($guardians));
            $this->require($children !== [] && $guardians !== [], 'Confirm at least one child and guardian.');
            foreach (array_merge($children, $guardians) as $id) Contact::where('masjid_id', $school->id)->findOrFail($id);
            foreach ($guardians as $guardian) $this->require($this->confirmedFor($school->id, $guardian, $children), 'A guardian must be confirmed for every child on this account.');
            $this->require(! TuitionChild::where('masjid_id', $school->id)->whereIn('contact_id', $children)->exists(), 'A child already has a tuition account.');
            $settings = TuitionSettings::for((int) $school->id); $settings->save();
            $account = TuitionAccount::create(['masjid_id' => $school->id, 'currency' => $settings->currency, 'created_by_user_id' => $actor]);
            foreach ($children as $child) TuitionChild::create(['masjid_id' => $school->id, 'account_id' => $account->id, 'contact_id' => $child, 'starts_on' => \App\Support\SchoolCalendarReaders::for($school->id)->today()]);
            foreach ($guardians as $guardian) TuitionGuardian::create(['masjid_id' => $school->id, 'account_id' => $account->id, 'contact_id' => $guardian, 'confirmed_by_user_id' => $actor, 'confirmed_at' => now()]);
            return $account->fresh();
        });
    }

    /** Financial attribution is retained even when the feature is switched off. */
    public function holdsContact(Contact $contact): bool
    {
        foreach (['tuition_children' => ['contact_id'], 'tuition_guardians' => ['contact_id'], 'tuition_lines' => ['child_contact_id', 'payer_contact_id'], 'tuition_checkouts' => ['payer_contact_id'], 'tuition_mails' => ['guardian_contact_id']] as $table => $columns) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) continue;
            if (DB::table($table)->where('masjid_id', $contact->masjid_id)->where(function ($q) use ($columns, $contact) {
                foreach ($columns as $column) $q->orWhere($column, $contact->id);
            })->exists()) return true;
        }
        return false;
    }

    public function confirmedFor(int $school, int $guardian, array $children): bool
    {
        if (! $children) return false;
        $confirmed = GroupMembership::where('masjid_id', $school)->where('contact_id', $guardian)->where('role', 'guardian')->confirmed()->whereIn('guardian_of_contact_id', $children)->distinct()->pluck('guardian_of_contact_id')->map(fn ($id) => (int) $id)->all();
        return count(array_diff($children, $confirmed)) === 0;
    }

    public function mayRead(TuitionAccount $account, Contact $guardian): bool
    {
        if ((int) $account->masjid_id !== (int) $guardian->masjid_id || $guardian->trashed()) return false;
        $children = TuitionChild::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->pluck('contact_id')->all();
        return TuitionGuardian::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->where('contact_id', $guardian->id)->whereNotNull('confirmed_at')->exists()
            && $this->confirmedFor((int) $account->masjid_id, (int) $guardian->id, $children);
    }

    /** The legacy reason argument is a private staff note; only description is family-visible. */
    public function office(TuitionAccount $account, string $kind, int $amount, string $date, string $reason, int $actor, ?string $method = null, ?string $reference = null, ?int $child = null, ?string $actionKey = null, ?string $description = null): TuitionLine
    {
        $this->assertEnabled((int) $account->masjid_id);
        $this->require(in_array($kind, ['charge', 'credit', 'office_payment'], true), 'Choose a charge, credit or office payment.');
        $this->require($amount > 0 && $amount <= 999999999, 'Enter a positive amount.');
        $this->require($kind !== 'office_payment' || in_array($method, self::METHODS, true), 'Choose an office payment method.');
        $this->require(! $reference || ! preg_match('/^(pi_|ch_|cs_|in_)/', trim($reference)), 'Stripe payments arrive from Stripe confirmation. Do not record a Stripe reference by hand.');
        if ($child !== null) $this->require(TuitionChild::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->where('contact_id', $child)->exists(), 'Choose a child on this account.');
        $line = $this->append($account, [
            'kind' => $kind, 'amount_minor' => $kind === 'charge' ? $amount : -$amount,
            'effective_on' => $date, 'due_on' => $kind === 'charge' ? $date : null, 'reason' => $reason, 'description' => trim($description ?? '') ?: self::kindLabel($kind),
            'recorded_by_user_id' => $actor, 'recorded_by_label' => User::findOrFail($actor)->name,
            'child_contact_id' => $child, 'method' => $kind === 'office_payment' ? $method : null,
            'reference' => $reference, 'source' => 'office', 'idempotency_key' => 'office:'.$account->id.':'.($actionKey ?? Str::uuid()),
        ]);
        $this->require($line->kind === $kind && $line->amount_minor === ($kind === 'charge' ? $amount : -$amount) && $line->effective_on->toDateString() === $date && $line->reason === $reason && $line->description === (trim($description ?? '') ?: self::kindLabel($kind)) && $line->reference === $reference && $line->method === ($kind === 'office_payment' ? $method : null) && $line->child_contact_id === $child, 'This action was already recorded with different details. Reload the ledger before entering a new action.');
        return $line;
    }

    /** Reverse an entry with a private audit reason and a separate public description. */
    public function void(TuitionLine $line, string $reason, int $actor, ?string $description = null): TuitionLine
    {
        $this->assertEnabled((int) $line->masjid_id);
        $this->require(in_array($line->source, ['office', 'schedule'], true) && $line->kind !== 'void', 'Stripe records can only be refunded in Stripe. A void cannot be voided.');
        $this->require(trim($reason) !== '', 'Enter a correction reason.');
        $account = TuitionAccount::where('masjid_id', $line->masjid_id)->findOrFail($line->account_id);
        return DB::transaction(function () use ($account, $line, $reason, $actor, $description) {
            TuitionAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            // A reversal must not count before the entry it reverses: voiding a charge dated next month
            // would otherwise lower today's balance (and what a family may pay) by money not yet asked.
            $today = \App\Support\SchoolCalendarReaders::for($account->masjid_id)->today();
            $void = $this->append($account, ['kind' => 'void', 'amount_minor' => -$line->amount_minor, 'effective_on' => max($today, $line->effective_on->toDateString()), 'reason' => $reason, 'description' => trim($description ?? '') ?: 'Correction', 'original_line_id' => $line->id, 'child_contact_id' => $line->child_contact_id, 'recorded_by_user_id' => $actor, 'recorded_by_label' => User::findOrFail($actor)->name, 'source' => 'office', 'idempotency_key' => 'void:'.$line->id]);
            // A scheduled sibling discount belongs to its charge; reversing the charge reverses it too.
            foreach (TuitionLine::where('account_id', $account->id)->where('original_line_id', $line->id)->where('kind', 'sibling_discount')->get() as $discount) $this->void($discount, $reason, $actor, $description);
            return $void;
        });
    }

    /** Internal callers supply server-derived fields; no HTTP request is passed here. */
    public function append(TuitionAccount $account, array $data): TuitionLine
    {
        $this->require(in_array($data['kind'] ?? null, self::KINDS, true) && is_int($data['amount_minor'] ?? null) && $data['amount_minor'] !== 0, 'Ledger entries require a known kind and nonzero integer minor units.');
        CartTables::existsOrFail('tuition_lines');
        // Old office reasons stay private; a missing description never falls back to free-text notes.
        $data['description'] = trim($data['description'] ?? '') ?: (($data['fee_plan_id'] ?? null) && $data['kind'] === 'charge' ? \App\Models\FeePlan::where('masjid_id', $account->masjid_id)->findOrFail($data['fee_plan_id'])->label : self::kindLabel($data['kind']));
        $data['reason'] ??= '';
        return DB::transaction(function () use ($account, $data) {
            TuitionAccount::where('masjid_id', $account->masjid_id)->whereKey($account->id)->lockForUpdate()->firstOrFail();
            return TuitionLine::firstOrCreate(['masjid_id' => $account->masjid_id, 'idempotency_key' => $data['idempotency_key']], array_merge($data, ['masjid_id' => $account->masjid_id, 'account_id' => $account->id, 'currency' => $account->currency, 'created_at' => now()]));
        });
    }

    /** FIFO allocation, including optional unposted previews, without storing a balance. */
    public function due(TuitionAccount $account, array $projected = []): array
    {
        $lines = TuitionLine::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->orderBy('effective_on')->orderBy('id')->get();
        $voided = $lines->where('kind', 'void')->pluck('original_line_id')->all();
        $charges = $lines->where('kind', 'charge')->reject(fn ($l) => in_array($l->id, $voided));
        $discounts = $lines->where('kind', 'sibling_discount')->reject(fn ($l) => in_array($l->id, $voided));
        $debts = $charges->map(fn ($charge) => ['line_id' => $charge->id, 'child_contact_id' => $charge->child_contact_id, 'due_on' => $charge->due_on?->toDateString() ?? $charge->effective_on->toDateString(), 'amount_minor' => max(0, $charge->amount_minor + $discounts->where('original_line_id', $charge->id)->sum('amount_minor'))])->values();
        // Previews increase both debt and prospective balance equally. They never invent payment credit.
        $available = max(0, $debts->sum('amount_minor') - $lines->sum('amount_minor')); $out = [];
        foreach ($debts->concat($projected)->sortBy('due_on', SORT_STRING)->values() as $debt) {
            $used = min($available, $debt['amount_minor']); $available -= $used;
            $debt['amount_minor'] -= $used;
            if ($debt['amount_minor'] > 0) $out[] = $debt;
        }
        return $out;
    }

    /**
     * What makes up balance($account, $asOf): the charges still unpaid on that day, oldest due first.
     * A family shown one total cannot tell registration plus books from tuition after a discount, so
     * the total is broken down from the same entries. Built so the amounts always sum to
     * max(0, balance): every entry dated by $asOf is counted once, and nothing dated after $asOf (or
     * from another account) is read.
     */
    public function owed(TuitionAccount $account, string $asOf): array
    {
        $lines = TuitionLine::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->whereDate('effective_on', '<=', $asOf)->orderBy('effective_on')->orderBy('id')->get()->keyBy('id');
        // A sibling discount nets into its charge, and a void into what it reverses, so a discounted
        // charge shows what is really asked and a voided one (or a reversed payment) drops out.
        $root = [];
        foreach ($lines as $line) if ($line->kind !== 'void' && ! ($line->kind === 'sibling_discount' && $lines->has($line->original_line_id))) $root[$line->id] = $line->id;
        foreach ($lines->where('kind', 'sibling_discount') as $line) $root[$line->id] ??= $root[$line->original_line_id];
        foreach ($lines->where('kind', 'void') as $line) $root[$line->id] = $root[$line->original_line_id] ?? $line->id;
        $net = [];
        foreach ($lines as $line) $net[$root[$line->id]] = ($net[$root[$line->id]] ?? 0) + $line->amount_minor;
        $plans = \App\Models\FeePlan::where('masjid_id', $account->masjid_id)->whereIn('id', $lines->pluck('fee_plan_id')->filter())->pluck('label', 'id');
        $item = function (int $id, int $amount) use ($lines, $plans) {
            $line = $lines->get($id);
            return ['line_id' => $line->id, 'kind' => $line->kind, 'description' => $line->description ?: ($line->kind === 'charge' ? $plans->get($line->fee_plan_id) : null) ?: self::kindLabel($line->kind), 'child_contact_id' => $line->child_contact_id, 'amount_minor' => $amount, 'due_on' => ($line->due_on ?? $line->effective_on)->toDateString(), 'effective_on' => $line->effective_on->toDateString()];
        };
        $order = [['due_on', 'asc'], ['effective_on', 'asc'], ['line_id', 'asc']];
        // Only a charge is something owed. A refund or a reversed payment takes money back out of
        // what was paid, so (as in due()) it reopens the oldest charge rather than being listed itself.
        $open = collect($net)->filter(fn ($amount, $id) => $amount > 0 && $lines->get($id)->kind === 'charge')->map(fn ($amount, $id) => $item($id, $amount))->sortBy($order)->values();
        $owed = max(0, array_sum($net));
        $paid = max(0, $open->sum('amount_minor') - $owed);
        $out = [];
        foreach ($open as $row) {
            $used = min($paid, $row['amount_minor']); $paid -= $used; $row['amount_minor'] -= $used;
            unset($row['effective_on']);
            if ($row['amount_minor'] > 0) $out[] = $row;
        }
        // A positive entry no charge here explains (a reversal of an entry dated after $asOf, recorded
        // before voids took their original's date) still counts in the balance; it is named as itself
        // so the parts keep adding up to the amount owed.
        $rest = $owed - array_sum(array_column($out, 'amount_minor'));
        foreach (collect($net)->filter(fn ($amount, $id) => $amount > 0 && $lines->get($id)->kind !== 'charge')->map(fn ($amount, $id) => $item($id, $amount))->sortBy($order) as $row) {
            if ($rest <= 0) break;
            $row['amount_minor'] = min($rest, $row['amount_minor']); $rest -= $row['amount_minor'];
            unset($row['effective_on']);
            $out[] = $row;
        }
        return $out;
    }

    public function require(bool $condition, string $message): void
    {
        if (! $condition) throw ValidationException::withMessages(['tuition' => $message]);
    }
}
