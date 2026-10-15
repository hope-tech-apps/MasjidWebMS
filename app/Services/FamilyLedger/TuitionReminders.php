<?php

namespace App\Services\FamilyLedger;

use App\Jobs\SendTuitionMail;
use App\Models\{Contact, Masjid, TuitionAccount, TuitionGuardian, TuitionMail};
use App\Support\SchoolCalendarReaders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One per guardian/account/due date/side; reminders never write financial entries. */
class TuitionReminders
{
    public function __construct(private FamilyLedger $ledger, private TuitionCharges $charges) {}

    public function queue(Masjid $school): int
    {
        if (! FamilyLedger::enabled($school)) return 0;
        $today = SchoolCalendarReaders::for($school->id)->today(); $queued = 0;
        foreach (TuitionAccount::where('masjid_id', $school->id)->get() as $account) {
            $items = $this->items($school, $account, $today);
            if ($items) $queued += $this->recipients($account, 'reminder', $today, $today, $items, 'reminder:'.$today);
        }
        return $queued;
    }

    /** Recomputed again at delivery, so an intervening payment changes or suppresses the notice. */
    public function items(Masjid $school, TuitionAccount $account, string $today): array
    {
        $settings = TuitionSettings::for((int) $school->id);
        $items = [];
        $before = $account->remind_before_days ?? $settings->remind_before_days; $after = $account->remind_after_days ?? $settings->remind_after_days;
        if ($before !== null) {
            $date = CarbonImmutable::parse($today)->addDays($before)->toDateString();
            foreach ($this->charges->projectedDue($school, $account, $date) as $due) if ($due['due_on'] === $date) $items[] = $due + ['side' => 'before'];
        }
        if ($after !== null) {
            $date = CarbonImmutable::parse($today)->subDays($after)->toDateString();
            foreach ($this->ledger->due($account) as $due) if ($due['due_on'] === $date) $items[] = $due + ['side' => 'after'];
        }
        return collect($items)->groupBy(fn ($i) => $i['side'].':'.$i['due_on'])->map(fn ($group) => ['side' => $group->first()['side'], 'due_on' => $group->first()['due_on'], 'amount_minor' => $group->sum('amount_minor')])->values()->all();
    }

    public function recipients(TuitionAccount $account, string $kind, string $from, string $to, array $items = [], ?string $key = null): int
    {
        $this->ledger->assertEnabled((int) $account->masjid_id); $queued = 0; $key ??= 'statement:'.Str::uuid();
        foreach (TuitionGuardian::where('masjid_id', $account->masjid_id)->where('account_id', $account->id)->get() as $member) {
            $contact = Contact::where('masjid_id', $account->masjid_id)->find($member->contact_id);
            if (! $contact || ! $this->ledger->mayRead($account, $contact) || self::address($contact) === null) continue;
            $row = DB::transaction(function () use ($account, $contact, $kind, $from, $to, $items, $key) {
                TuitionAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                return TuitionMail::firstOrCreate(['masjid_id' => $account->masjid_id, 'mail_key' => $key.':'.$account->id.':'.$contact->id], ['account_id' => $account->id, 'guardian_contact_id' => $contact->id, 'kind' => $kind, 'status' => 'queued', 'from_on' => $from, 'to_on' => $to, 'due_items' => $items]);
            });
            if (! $row->wasRecentlyCreated) continue;
            try {
                SendTuitionMail::dispatch($row->id, $account->masjid_id)->onConnection('database')->afterCommit(); $queued++;
            } catch (\Throwable $e) {
                $row->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => $e::class]);
            }
        }
        return $queued;
    }

    public static function address(Contact $contact): ?string
    {
        $address = \App\Support\ContactIdentity::submittedAddress((string) ($contact->login_email ?: $contact->email));
        // Canonical identity helper performs byte-exact identity, never a database collation comparison.
        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }
}
