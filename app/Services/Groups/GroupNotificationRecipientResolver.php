<?php

namespace App\Services\Groups;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\User;
use App\Support\NudgeRecipient;
use Illuminate\Support\Collection;

/**
 * Who to nudge for a class event — the single place the disclosure rules and the
 * author-skip live, so the notifier can never drift from what GroupAudience lets
 * a person see.
 *
 * The consent rule is DELIBERATELY ASYMMETRIC and mirrors GroupAudience exactly:
 *
 *   - a CLASS STORY (and a group-wide thread) is a broadcast, so it reaches only
 *     guardians who granted FEED consent — GroupMembership::scopeConsented();
 *   - a PARTICIPANT thread is a private conversation a guardian is a named party
 *     to about their OWN ward, so it is NOT consent-gated (requiring consent
 *     would lock a parent out of a message about their own child) — a confirmed
 *     guardian edge naming that ward is enough, mirroring mayReceiveThread().
 *
 * Every path ends in resolveAddressable(), which maps a principal to the address
 * they SIGN IN with (a guardian's login_email, only while their family login is
 * live; a teacher's users.email), drops the unreachable, dedupes, and removes the
 * author's own address so nobody is nudged about the thing they just wrote.
 */
class GroupNotificationRecipientResolver
{
    /**
     * Guardians who consented to the feed — a class-story post, or a group-wide
     * thread message.
     *
     * @return Collection<int,NudgeRecipient>
     */
    public function feedGuardians(Group $group, ?string $authorAddress): Collection
    {
        $contacts = $group->memberships()
            ->consented()
            ->with('contact')
            ->get()
            ->map(fn (GroupMembership $m) => $m->contact)
            ->filter();

        return $this->resolveAddressable($contacts, $authorAddress);
    }

    /**
     * The guardian(s) of ONE ward — a participant thread about that child. NOT
     * consent-gated: a guardian may always be told about their own ward's thread.
     *
     * @return Collection<int,NudgeRecipient>
     */
    public function wardGuardians(Group $group, int $wardContactId, ?string $authorAddress): Collection
    {
        $contacts = $group->memberships()
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('guardian_of_contact_id', $wardContactId)
            ->confirmed()
            ->with('contact')
            ->get()
            ->map(fn (GroupMembership $m) => $m->contact)
            ->filter();

        return $this->resolveAddressable($contacts, $authorAddress);
    }

    /**
     * The teachers of the class — a parent's reply. Teachers are Users named in
     * `group_staff`, PLUS any confirmed legacy Contact `leader` on the roster.
     *
     * @return Collection<int,NudgeRecipient>
     */
    public function classTeachers(Group $group, ?string $authorAddress): Collection
    {
        // Staff logins (the current model).
        $staff = $group->staff()->get()
            ->map(fn (User $u) => $this->fromUser($u))
            ->filter();

        // Legacy Contact leaders, if any — reachable only through a family login.
        $leaderContacts = $group->memberships()
            ->where('role', GroupMembership::ROLE_LEADER)
            ->confirmed()
            ->with('contact')
            ->get()
            ->map(fn (GroupMembership $m) => $m->contact)
            ->filter()
            ->map(fn (Contact $c) => $this->fromContact($c))
            ->filter();

        return $this->finalize($staff->merge($leaderContacts), $authorAddress);
    }

    /**
     * @param  Collection<int,Contact>  $contacts
     * @return Collection<int,NudgeRecipient>
     */
    private function resolveAddressable(Collection $contacts, ?string $authorAddress): Collection
    {
        $recipients = $contacts
            ->map(fn (Contact $c) => $this->fromContact($c))
            ->filter();

        return $this->finalize($recipients, $authorAddress);
    }

    /**
     * A guardian is reachable ONLY at their live family-login address. A consented
     * guardian who never enabled a login is correctly unreachable (skipped),
     * never emailed a dead-end — the nudge tells them to sign in.
     */
    private function fromContact(Contact $contact): ?NudgeRecipient
    {
        if (! $contact->familyLoginIsActive()) {
            return null;
        }

        $address = trim((string) $contact->login_email);

        if ($address === '') {
            return null;
        }

        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        return new NudgeRecipient($address, $name !== '' ? $name : null, 'family');
    }

    private function fromUser(User $user): ?NudgeRecipient
    {
        $address = trim((string) $user->email);

        if ($address === '') {
            return null;
        }

        return new NudgeRecipient($address, $user->name, 'staff');
    }

    /**
     * Drop the unreachable, remove the author, dedupe by lowercased address.
     *
     * @param  Collection<int,NudgeRecipient|null>  $recipients
     * @return Collection<int,NudgeRecipient>
     */
    private function finalize(Collection $recipients, ?string $authorAddress): Collection
    {
        $author = $authorAddress !== null && trim($authorAddress) !== ''
            ? mb_strtolower(trim($authorAddress))
            : null;

        return $recipients
            ->filter()
            ->reject(fn (NudgeRecipient $r) => $author !== null && mb_strtolower($r->address) === $author)
            ->unique(fn (NudgeRecipient $r) => mb_strtolower($r->address))
            ->values();
    }
}
