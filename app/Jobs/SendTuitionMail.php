<?php

namespace App\Jobs;

use App\Mail\AttendanceAbsenceMail;
use App\Models\{Contact, Masjid, TuitionAccount, TuitionMail};
use App\Services\FamilyLedger\{FamilyLedger, TuitionReminders, TuitionStatement};
use App\Support\{SchoolCalendarReaders, SchoolMailTransport, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Log, Mail};

/** IDs only; PDF is rendered after dequeue and never placed into a JSON queue payload. */
class SendTuitionMail implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(public int $mailId, public int $schoolId) {}

    public function handle(): void
    {
        app(TenantContext::class)->runWithout(function () {
            $row = TuitionMail::where('masjid_id', $this->schoolId)->find($this->mailId);
            if (! $row || $row->status !== 'queued') return;
            try {
                $school = Masjid::find($this->schoolId); $account = TuitionAccount::where('masjid_id', $this->schoolId)->find($row->account_id);
                $guardian = Contact::where('masjid_id', $this->schoolId)->find($row->guardian_contact_id);
                $ledger = app(FamilyLedger::class); $address = $guardian ? TuitionReminders::address($guardian) : null;
                $stale = $row->kind === 'reminder' && SchoolCalendarReaders::for($this->schoolId)->today() !== $row->from_on->toDateString();
                if (! FamilyLedger::enabled($school) || ! $account || ! $guardian || ! $address || ! $ledger->mayRead($account, $guardian) || $stale) { $row->update(['status' => 'skipped']); return; }
                if (! in_array(config('app.env'), ['local', 'testing'], true) && SchoolMailTransport::unsafe(Mail::mailer()->getSymfonyTransport())) throw new \RuntimeException('School mail transport unavailable');
                $items = $row->due_items ?? [];
                if ($row->kind === 'reminder') {
                    $current = app(TuitionReminders::class)->items($school, $account, $row->from_on->toDateString());
                    $items = array_values(array_filter($current, fn ($i) => collect($row->due_items ?? [])->contains(fn ($old) => $old['due_on'] === $i['due_on'] && $old['side'] === $i['side'])));
                    if (! $items) { $row->update(['status' => 'skipped']); return; }
                }
                $body = $row->kind === 'statement' ? 'Your school tuition statement is attached.' : "School tuition reminder:\n".implode("\n", array_map(fn ($i) => TuitionStatement::money($i['amount_minor'], $account->currency).' due '.$i['due_on'], $items));
                $mail = new AttendanceAbsenceMail($school->name, $school->email, $school->name.': Tuition '.($row->kind === 'statement' ? 'statement' : 'reminder'), $body."\n\nPlease contact the school office with questions.");
                if ($row->kind === 'statement') $mail->attachData(app(TuitionStatement::class)->pdf($account, $row->from_on->toDateString(), $row->to_on->toDateString()), 'tuition-statement.pdf', ['mime' => 'application/pdf']);
                if (! DB::table('tuition_mails')->where('id', $row->id)->where('status', 'queued')->update(['status' => 'sending', 'attempted_at' => now(), 'due_items' => json_encode($items), 'updated_at' => now()])) return;
                Mail::to($address)->send($mail);
                $row->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (\Throwable $e) {
                $this->failed($e); Log::warning('Tuition email delivery failed.', ['mail_id' => $this->mailId, 'exception_class' => $e::class]);
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        TuitionMail::withoutMasjidScope()->where('masjid_id', $this->schoolId)->whereKey($this->mailId)->whereIn('status', ['queued', 'sending'])->update(['status' => 'failed', 'failed_at' => now(), 'exception_class' => $exception ? $exception::class : \RuntimeException::class]);
    }
}
