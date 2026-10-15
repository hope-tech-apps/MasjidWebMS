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
    public function balance(TuitionAccount $account): int { return (int) TuitionLine::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->sum('amount_minor'); }

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

    public function office(TuitionAccount $account, string $kind, int $amount, string $date, string $reason, int $actor, ?string $method = null, ?string $reference = null, ?int $child = null, ?string $actionKey = null): TuitionLine
    {
        $this->assertEnabled((int) $account->masjid_id);
        $this->require(in_array($kind, ['charge', 'credit', 'office_payment'], true), 'Choose a charge, credit or office payment.');
        $this->require($amount > 0 && $amount <= 999999999 && trim($reason) !== '', 'Enter a positive amount and a reason.');
        $this->require($kind !== 'office_payment' || in_array($method, self::METHODS, true), 'Choose an office payment method.');
        $this->require(! $reference || ! preg_match('/^(pi_|ch_|cs_|in_)/', trim($reference)), 'Stripe payments arrive from Stripe confirmation. Do not record a Stripe reference by hand.');
        if ($child !== null) $this->require(TuitionChild::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->where('contact_id', $child)->exists(), 'Choose a child on this account.');
        $line = $this->append($account, [
            'kind' => $kind, 'amount_minor' => $kind === 'charge' ? $amount : -$amount,
            'effective_on' => $date, 'due_on' => $kind === 'charge' ? $date : null, 'reason' => $reason,
            'recorded_by_user_id' => $actor, 'recorded_by_label' => User::findOrFail($actor)->name,
            'child_contact_id' => $child, 'method' => $kind === 'office_payment' ? $method : null,
            'reference' => $reference, 'source' => 'office', 'idempotency_key' => 'office:'.$account->id.':'.($actionKey ?? Str::uuid()),
        ]);
        $this->require($line->kind === $kind && $line->amount_minor === ($kind === 'charge' ? $amount : -$amount) && $line->effective_on->toDateString() === $date && $line->reason === $reason && $line->reference === $reference && $line->method === ($kind === 'office_payment' ? $method : null) && $line->child_contact_id === $child, 'This action was already recorded with different details. Reload the ledger before entering a new action.');
        return $line;
    }

    public function void(TuitionLine $line, string $reason, int $actor): TuitionLine
    {
        $this->assertEnabled((int) $line->masjid_id);
        $this->require(in_array($line->source, ['office', 'schedule'], true) && $line->kind !== 'void', 'Stripe records can only be refunded in Stripe. A void cannot be voided.');
        $this->require(trim($reason) !== '', 'Enter a correction reason.');
        $account = TuitionAccount::where('masjid_id', $line->masjid_id)->findOrFail($line->account_id);
        return DB::transaction(function () use ($account, $line, $reason, $actor) {
            TuitionAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $void = $this->append($account, ['kind' => 'void', 'amount_minor' => -$line->amount_minor, 'effective_on' => \App\Support\SchoolCalendarReaders::for($account->masjid_id)->today(), 'reason' => $reason, 'original_line_id' => $line->id, 'child_contact_id' => $line->child_contact_id, 'recorded_by_user_id' => $actor, 'recorded_by_label' => User::findOrFail($actor)->name, 'source' => 'office', 'idempotency_key' => 'void:'.$line->id]);
            // A scheduled sibling discount belongs to its charge; reversing the charge reverses it too.
            foreach (TuitionLine::where('account_id', $account->id)->where('original_line_id', $line->id)->where('kind', 'sibling_discount')->get() as $discount) $this->void($discount, $reason, $actor);
            return $void;
        });
    }

    /** Internal callers supply server-derived fields; no HTTP request is passed here. */
    public function append(TuitionAccount $account, array $data): TuitionLine
    {
        $this->require(in_array($data['kind'] ?? null, self::KINDS, true) && is_int($data['amount_minor'] ?? null) && $data['amount_minor'] !== 0, 'Ledger entries require a known kind and nonzero integer minor units.');
        CartTables::existsOrFail('tuition_lines');
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

    public function require(bool $condition, string $message): void
    {
        if (! $condition) throw ValidationException::withMessages(['tuition' => $message]);
    }
}
