<?php

namespace App\Jobs;

use App\Enums\GroupNotificationEvent;
use App\Mail\GroupUpdateNudgeMail;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Groups\GroupNotificationRecipientResolver;
use App\Services\Groups\GroupPushChannel;
use App\Support\NudgeRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Fans a class event out to email (and, later, push), OFF the request.
 *
 * ## It takes IDs, not models
 *
 * `SerializesModels` re-resolves an Eloquent model THROUGH the BelongsToMasjid
 * global scope, and every job starts with the tenant UNBOUND
 * (.claude/rules/tenant-scoping.md) — so a passed model would query with no
 * filter. It takes ids, fetches without the scope, and never needs the tenant
 * bound because the resolver reads relationships off the fetched Group directly.
 *
 * ## It is FAIL-SOFT, absolutely
 *
 * A notification must never turn a successful post into a 500. In tests (and any
 * sync-queue deploy) this job runs INLINE inside the controller's request, so the
 * whole of handle() is guarded: one bad address is logged and skipped, and any
 * unexpected error is caught and logged rather than thrown back into the write.
 *
 * ## It does not retry
 *
 * `$tries = 1`. Re-running the whole fan-out is the one thing that could
 * double-send; per-recipient email failures are already swallowed, and push is a
 * no-op today.
 */
class SendGroupNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $masjidId,
        public int $groupId,
        public GroupNotificationEvent $event,
        /** The ward a participant thread is about (null for a class-wide event). */
        public ?int $aboutContactId = null,
        /** The author, captured at dispatch so a since-deleted author still skips. */
        public ?int $authorUserId = null,
        public ?int $authorContactId = null,
        /**
         * REACTION only: the ONE person the digest is for (the story's or
         * message's author). A staff user or a guardian contact, never both.
         */
        public ?int $recipientUserId = null,
        public ?int $recipientContactId = null,
        /** REACTION only: what the digest covers — 'story', 'thread:{id}'. */
        public array $subjects = [],
    ) {
    }

    public function handle(GroupNotificationRecipientResolver $resolver, GroupPushChannel $push): void
    {
        try {
            $masjid = Masjid::withoutGlobalScopes()->find($this->masjidId);
            $group = Group::withoutMasjidScope()->find($this->groupId);

            if ($masjid === null || $group === null) {
                return;
            }

            $authorAddress = $this->authorUserId !== null
                ? optional(User::withoutGlobalScopes()->find($this->authorUserId))->email
                : ($this->authorContactId !== null
                    ? optional(Contact::withoutMasjidScope()->find($this->authorContactId))->login_email
                    : null);

            [$recipients, $kind] = match ($this->event) {
                GroupNotificationEvent::CLASS_STORY =>
                    [$resolver->feedGuardians($group, $authorAddress), 'update'],

                GroupNotificationEvent::TEACHER_THREAD_MESSAGE =>
                    [$resolver->classTeachers($group, $authorAddress), 'message'],

                // A staff thread message: a participant thread reaches the ward's
                // guardian(s); a group-wide thread reaches the feed audience.
                GroupNotificationEvent::GUARDIAN_THREAD_MESSAGE =>
                    [
                        $this->aboutContactId !== null
                            ? $resolver->wardGuardians($group, $this->aboutContactId, $authorAddress)
                            : $resolver->feedGuardians($group, $authorAddress),
                        'message',
                    ],

                // A handout shared with the whole class reaches the same
                // audience as the class story, and is gated by the same feed
                // consent. A handout addressed to ONE child reaches that child's
                // guardians — the split GRADE_POSTED makes below, for the same
                // reason: a class-wide nudge would tell every family that
                // something had been filed for somebody.
                //
                // `consentedWardGuardians`, not `wardGuardians`: a targeted
                // handout is still gated on FEED consent by
                // GroupAudience::readableResourcesQuery(), so nudging a guardian
                // who has not consented would mail them about a file the portal
                // will not show them.
                GroupNotificationEvent::RESOURCE_SHARED =>
                    [
                        $this->aboutContactId !== null
                            ? $resolver->consentedWardGuardians($group, $this->aboutContactId, $authorAddress)
                            : $resolver->feedGuardians($group, $authorAddress),
                        'update',
                    ],

                // A mark reaches ONE child's guardians. aboutContactId is always
                // set for this event; falling back to the feed audience would
                // tell every family in the class that a mark had been entered.
                GroupNotificationEvent::GRADE_POSTED =>
                    [
                        $this->aboutContactId !== null
                            ? $resolver->wardGuardians($group, $this->aboutContactId, $authorAddress)
                            : collect(),
                        'update',
                    ],

                // The reaction digest reaches the AUTHOR alone, and the resolver
                // re-checks NOW — at send time, not when the reaction was made —
                // that they may still read what was reacted to.
                GroupNotificationEvent::REACTION =>
                    [
                        $resolver->reactionRecipient(
                            $group, $this->recipientUserId, $this->recipientContactId, $this->subjects
                        ),
                        'reaction',
                    ],
            };

            if ($recipients->isEmpty()) {
                return;
            }

            $orgName = (string) $group->masjid?->name ?: (string) $masjid->name;
            $orgEmail = $masjid->email ?? null;
            $groupLabel = (string) $group->name;

            foreach ($recipients as $recipient) {
                try {
                    Mail::to($recipient->address)->send(new GroupUpdateNudgeMail(
                        orgName: $orgName,
                        groupLabel: $groupLabel,
                        kind: $kind,
                        // WHERE they sign in depends on WHO they are. A teacher
                        // (or an administrator) is a `users` row and signs in on
                        // the staff door; only a guardian signs in to the family
                        // portal. This was one family URL for everybody, so a
                        // teacher told "a parent replied" was sent to a sign-in
                        // page that has no login for them.
                        signInUrl: $this->signInUrlFor($recipient, $masjid),
                        recipientName: $recipient->name,
                        orgEmail: $orgEmail,
                    ));
                } catch (Throwable $e) {
                    // One dead address must not stop the rest. The log names the
                    // recipient by id, never by address: a guardian's sign-in address
                    // is personal data, and the transport's own error text often
                    // repeats it, so that is scrubbed too.
                    Log::warning('Group nudge email failed for one recipient.', [
                        'masjid_id' => (int) $masjid->id,
                        'group_id' => (int) $group->id,
                        'realm' => $recipient->realm,
                        'contact_id' => $recipient->contactId,
                        'user_id' => $recipient->userId,
                        'error' => $this->withoutAddresses($e->getMessage(), $recipient->address),
                    ]);
                }
            }

            $push->deliver($masjid, $group, $this->event, $recipients, $kind);
        } catch (Throwable $e) {
            // The whole fan-out is best-effort; the write it followed already
            // succeeded and must stay successful.
            Log::error('SendGroupNotificationJob failed: '.$e->getMessage());
        }
    }

    /** The sign-in page for THIS recipient's realm. */
    private function signInUrlFor(NudgeRecipient $recipient, Masjid $masjid): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $recipient->realm === 'staff'
            ? $base.'/auth/sign-in'
            : $base.'/family/'.$masjid->id.'/sign-in';
    }

    /** The same scrub as `points:weekly-report`'s failed-send line: this address, then anything shaped like one. */
    private function withoutAddresses(string $message, string $address): string
    {
        $message = str_ireplace($address, '[address]', $message);

        return (string) preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;]+/u', '[address]', $message);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('SendGroupNotificationJob permanently failed: '.($e?->getMessage() ?? 'unknown'));
    }
}
