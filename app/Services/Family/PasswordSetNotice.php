<?php

namespace App\Services\Family;

use App\Mail\PasswordSetNoticeMail;
use App\Models\Contact;
use App\Models\Masjid;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells a contact that a password was set for their login address (owner,
 * 2026-09-17: "Yes, send it").
 *
 * FamilyPasswordService::set() is the only caller, and it calls `afterCommit()`
 * from INSIDE its transaction. That placement is the whole design:
 *
 *  - The mail is sent only once the OUTERMOST transaction commits. set() runs
 *    inside MemberSignupService::consume()'s transaction on the app's
 *    create-account and forgot-password doors, so "after set() returns" is
 *    still before the code is spent for good. `DB::afterCommit()` waits for the
 *    real commit.
 *  - A rolled-back write sends nothing. Laravel drops a transaction's
 *    after-commit callbacks when it rolls back, including the callbacks of an
 *    inner transaction that had already committed into it.
 *  - A refused attempt sends nothing, because every refusal happens before
 *    set() is called (the request rules, a wrong or spent code, a blank name, a
 *    revoked contact).
 *
 * `send()` never throws. It runs after the commit, so an exception from it
 * would turn a password that WAS changed into a 500 the person reads as "it
 * did not work". A failure is logged at warning, which production records
 * (LOG_LEVEL=warning), with ids and the exception class only: no address, and
 * no exception message, which for a mail transport can quote the recipient.
 */
class PasswordSetNotice
{
    /**
     * Queue the notice for when the current transaction commits. Call it only
     * from inside the transaction that wrote the password, after the write.
     *
     * The address and the moment are read NOW, inside that transaction: the
     * notice is about the address this password was chosen under and the time
     * it was written, whatever happens to the model afterwards.
     */
    public function afterCommit(Contact $contact): void
    {
        $address = $contact->login_email;
        $at = $contact->password_set_at ?? now();
        $usesApp = $contact->verified_at !== null;
        $usesFamilyPortal = $contact->familyLoginIsActive();

        DB::afterCommit(fn () => $this->send($contact, $address, $at, $usesApp, $usesFamilyPortal));
    }

    private function send(
        Contact $contact,
        ?string $address,
        CarbonInterface $at,
        bool $usesApp,
        bool $usesFamilyPortal,
    ): void {
        try {
            if ($address === null || trim($address) === '') {
                // Neither door can set a password on a contact without a login
                // address today. If one ever does, nobody is told, and this line
                // is how anyone finds out.
                Log::warning('password set notice not sent: the contact has no login address', [
                    'contact_id' => $contact->id,
                    'masjid_id' => $contact->masjid_id,
                ]);

                return;
            }

            // Masjid is the tenant and carries no BelongsToMasjid scope, so this
            // is a plain lookup whether or not a tenant is bound.
            $masjid = Masjid::find($contact->masjid_id);

            Mail::to($address)->send(new PasswordSetNoticeMail(
                orgName: $masjid?->name ?? (string) config('app.name'),
                loginEmail: $address,
                setAt: self::formatForOrganisation($at, $masjid),
                recipientName: $contact->first_name,
                orgEmail: $masjid?->email,
                usesApp: $usesApp,
                usesFamilyPortal: $usesFamilyPortal,
            ));
        } catch (Throwable $e) {
            Log::warning('password set notice delivery failed', [
                'contact_id' => $contact->id,
                'masjid_id' => $contact->masjid_id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * The moment in the organisation's own timezone, followed by PHP's
     * abbreviation for the zone ("EDT"), so "3:04 PM" cannot be read in the
     * wrong one. A zone with no abbreviation prints its UTC offset instead
     * ("10:04 PM +03" for Asia/Riyadh). UTC when the organisation has none or
     * an unknown one. The same format ContactUsNotifier::receivedAt() gives the
     * office.
     */
    public static function formatForOrganisation(CarbonInterface $at, ?Masjid $masjid): string
    {
        $name = trim((string) $masjid?->timezone);

        if ($name !== '' && strcasecmp($name, 'UTC') !== 0) {
            try {
                return $at->copy()->setTimezone(new DateTimeZone($name))->format('D j M Y, g:i A T');
            } catch (Throwable) {
                // An unknown zone name falls through to UTC.
            }
        }

        return $at->copy()->utc()->format('D j M Y, g:i A \U\T\C');
    }
}
