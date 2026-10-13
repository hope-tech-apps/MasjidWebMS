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
 * (teachers, and lunch staff since one login may hold a role at each organisation).
 */
class StaffAddedToOrganisation extends Mailable
{
    use Queueable, SerializesModels;

    /** A plain sign-in address. No credential rides in it. */
    public string $signInUrl;

    /**
     * @param  list<string>  $classNames  the classes assigned (teacher only), by name
     * @param  string|null  $contactEmail  who at the organisation to ask, when it has one
     * @param  bool  $otherRole  the person now holds roles of two kinds (a teacher at one
     *                           organisation, lunch staff at another). They move between
     *                           the two with the switch at the top of the page, not with the
     *                           school menu, so the one sentence about finding this
     *                           organisation differs. False for the teacher of two schools
     *                           this mail was written for, whose wording is unchanged.
     */
    public function __construct(
        public User $user,
        public string $orgName,
        public string $roleLabel = 'teacher',
        public array $classNames = [],
        public ?string $contactEmail = null,
        public bool $otherRole = false,
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
