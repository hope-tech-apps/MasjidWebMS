<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "{org} added you as a {role}": the notice an EXISTING login gets when a second
 * organisation attaches it (docs/multi-tenant-admin-design.md §6).
 *
 * It is deliberately NOT AccountAccessMail. That one says "an account has been
 * created for you" and carries a set-password link whose completion overwrites the
 * password and ends every session. An existing teacher already has a password and
 * may be signed in at another school right now; the only correct message is "you
 * have one more school", with no token and no password link in it at all. The
 * absence is the safety property: nothing in this mail can change their login.
 *
 * One template for every role so the wording cannot drift between the doors
 * (teacher now, lunch staff and administrators later).
 */
class StaffAddedToOrganisation extends Mailable
{
    use Queueable, SerializesModels;

    /** A plain sign-in address. No credential rides in it. */
    public string $signInUrl;

    /**
     * @param  list<string>  $classNames  the classes assigned (teacher only), by name
     * @param  string|null  $contactEmail  who at the organisation to ask, when it has one
     */
    public function __construct(
        public User $user,
        public string $orgName,
        public string $roleLabel = 'teacher',
        public array $classNames = [],
        public ?string $contactEmail = null,
    ) {
        $this->signInUrl = rtrim((string) config('app.url'), '/').'/auth/sign-in';
    }

    public function build(): self
    {
        // The sender NAME follows the organisation, as AccountAccessMail does: the
        // subject and body already name it, and a "From" line naming a different
        // school reads wrong.
        return $this->subject('You have been added to '.$this->orgName)
            ->from(config('mail.from.address'), $this->orgName ?: config('mail.from.name', config('app.name')))
            ->view('emails.staff-added');
    }
}
