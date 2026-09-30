<?php

namespace App\Services\Groups;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\NudgeRecipient;
use App\Support\TenantContext;
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
        // GroupAudience::storyGuardianContacts() is the ONE definition of who a
        // class story reaches: consented, AND STILL IN THE CLASS (every HTTP
        // surface refuses a departed family, but this one sends mail to their own
        // address, where no member of staff would ever see it happening), and
        // holding a live family login. The read receipts count the same set, so
        // "Seen by 4 of 7" and "who gets the email" cannot drift apart.
        $contacts = app(GroupAudience::class)->storyGuardianContacts($group);

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
     * The guardian(s) of ONE ward who ALSO granted feed consent and are still in
     * the class — a handout addressed to that child (2026-09-24).
     *
     * The third shape, and it exists because neither of the two above says this
     * one. `feedGuardians()` is the whole class, which is precisely what a
     * targeted handout must not reach; `wardGuardians()` is the right people but
     * is not consent-gated, and a targeted file IS consent-gated
     * (`GroupAudience::readableResourcesQuery()` requires feed standing for
     * every family branch). Nudging somebody about a file the portal will then
     * refuse them is a promise the next screen breaks.
     *
     * @return Collection<int,NudgeRecipient>
     */
    public function consentedWardGuardians(Group $group, int $wardContactId, ?string $authorAddress): Collection
    {
        $contacts = $group->memberships()
            ->consented()
            ->current()
            ->where('guardian_of_contact_id', $wardContactId)
            ->with('contact')
            ->get()
            ->map(fn (GroupMembership $m) => $m->contact)
            ->filter();

        return $this->resolveAddressable($contacts, $authorAddress);
    }

    /**
     * The guardians to tell that their child's WEEKLY POINTS REPORT is ready
     * (T-003.3, the Friday report).
     *
     * A fourth shape, and the strictest of the four, because the sweep that calls it
     * mails a family with nobody watching. A recipient must satisfy ALL of:
     *
     *   - the WARD is still on the roster: a PARTICIPANT membership in this class with
     *     `left_on IS NULL`. A withdrawn child's family is not told about a week the
     *     school has recorded them as gone from;
     *   - the GUARDIAN EDGE is confirmed, still current (`left_on IS NULL`), and holds
     *     feed consent. Consent is required here even though a parent may always READ
     *     their own child's record (groups.md: consent gates broadcasts, not the
     *     record), because this is an email to an address the school chose to hold, and
     *     a notice nobody agreed to receive is the mistake to make in the cautious
     *     direction;
     *   - the guardian has a LIVE family login (`resolveAddressable`), which is also the
     *     address the notice goes to. A guardian with no login is correctly unreachable.
     *
     * `$wardContactIds` are the contacts of children who have a reportable week; the
     * roster check happens here regardless, so a caller cannot widen the audience by
     * passing a departed child's id. Deduped by address, so a parent with two children
     * in the class gets ONE notice, whose link opens all their children in it.
     *
     * @param  array<int,int>  $wardContactIds
     * @return Collection<int,NudgeRecipient>
     */
    public function weeklyReportGuardians(Group $group, array $wardContactIds): Collection
    {
        if ($wardContactIds === []) {
            return collect();
        }

        $currentWards = $group->memberships()
            ->participants()
            ->current()
            ->whereIn('contact_id', $wardContactIds)
            ->pluck('contact_id')
            ->all();

        if ($currentWards === []) {
            return collect();
        }

        $contacts = $group->memberships()
            ->consented()
            ->current()
            ->whereIn('guardian_of_contact_id', $currentWards)
            ->with('contact')
            ->get()
            ->map(fn (GroupMembership $m) => $m->contact)
            ->filter();

        return $this->resolveAddressable($contacts, null);
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
     * The one person a reaction digest goes to — the AUTHOR of the story or the
     * message — IF they may still read what was reacted to, checked NOW, at send
     * time (owner, 2026-09-29).
     *
     * A reaction outlives the standing of the person it is about. A guardian who
     * wrote a message, and then withdrew consent or left the class, must not be
     * mailed about it; a teacher taken off the class, or an office administrator
     * who published a story but is not on the roster and so cannot read it back,
     * has nothing to sign in to. `$subjects` are the things the digest covers
     * ('story', 'thread:{id}'); one still readable is enough to send.
     *
     * Returns zero or one recipient: the address they SIGN IN with, and only while
     * a guardian's family login is live.
     *
     * @param  list<string>  $subjects
     * @return Collection<int,NudgeRecipient>
     */
    public function reactionRecipient(Group $group, ?int $userId, ?int $contactId, array $subjects): Collection
    {
        foreach ($subjects as $subject) {
            $threadId = str_starts_with($subject, 'thread:') ? (int) substr($subject, 7) : null;

            if ($subject !== 'story' && ($threadId === null || $threadId <= 0)) {
                continue;
            }

            $principal = $this->principal($userId, $contactId);

            if ($principal === null || ! $this->mayRead($group, $principal, $threadId)) {
                continue;
            }

            $recipient = $principal instanceof User ? $this->fromUser($principal) : $this->fromContact($principal);

            return $recipient !== null ? collect([$recipient]) : collect();
        }

        return collect();
    }

    /**
     * May this staff user or guardian STILL read the story (`$threadId` null) or
     * the thread, right now? The reaction digest asks it of the reactor, so a
     * reaction from somebody who lost their standing since the tap is not
     * announced, and (through reactionRecipient) of the author.
     */
    public function principalMayStillRead(Group $group, ?int $userId, ?int $contactId, ?int $threadId): bool
    {
        $principal = $this->principal($userId, $contactId);

        return $principal !== null && $this->mayRead($group, $principal, $threadId);
    }

    private function principal(?int $userId, ?int $contactId): User|Contact|null
    {
        if ($userId !== null) {
            // withoutGlobalScopes() also drops SoftDeletes, and an archived
            // ("moved to trash") staff member keeps their group_staff rows, so
            // they would still read as leading the class: the digest would mail
            // them, and count their taps. Trashed is nobody, the same as the
            // trashed guardian below.
            return User::withoutGlobalScopes()->whereNull('deleted_at')->find($userId);
        }

        // Contact soft-deletes: a trashed guardian is null here, i.e. nobody.
        return $contactId !== null ? Contact::withoutMasjidScope()->find($contactId) : null;
    }

    /**
     * GroupAudience's own answer, asked with the tenant bound to the group's
     * organisation for the duration: the email-to-Contact bridge and the
     * guardian's identity both resolve inside the BOUND tenant, and a job or a
     * console sweep starts unbound (which would read as "no standing").
     */
    private function mayRead(Group $group, User|Contact $principal, ?int $threadId): bool
    {
        $audience = app(GroupAudience::class);
        $tenant = app(TenantContext::class);
        $previousId = $tenant->get();
        $previousMembership = $tenant->membership();

        $tenant->set((int) $group->masjid_id);

        try {
            // A guardian who has LEFT the class is told nothing about it. The
            // thread rules deliberately keep a departed family's OWN record
            // readable (a conversation about their child does not vanish), which
            // is right for a screen they open and wrong for an email the school
            // pushes to them, or for counting them as a reactor. Standing here
            // means at least one guardian edge still on the roster.
            if ($principal instanceof Contact
                && $audience->membershipsFor($principal, $group)->every(fn (GroupMembership $m): bool => $m->hasLeft())) {
                return false;
            }

            if ($threadId === null) {
                return $audience->mayReceive($principal, $group, GroupAudience::DISCLOSURE_FEED);
            }

            $thread = GroupThread::withoutMasjidScope()->where('group_id', $group->id)->find($threadId);

            return $thread !== null && ! $thread->trashed() && $audience->mayReceiveThread($principal, $group, $thread);
        } finally {
            if ($previousId === null) {
                $tenant->forgetTenant();
            } elseif ($previousMembership !== null) {
                $tenant->setFromMembership($previousMembership);
            } else {
                $tenant->set($previousId);
            }
        }
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

        return new NudgeRecipient($address, $name !== '' ? $name : null, 'family', contactId: (int) $contact->id);
    }

    private function fromUser(User $user): ?NudgeRecipient
    {
        $address = trim((string) $user->email);

        if ($address === '') {
            return null;
        }

        return new NudgeRecipient($address, $user->name, 'staff', userId: (int) $user->id);
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
