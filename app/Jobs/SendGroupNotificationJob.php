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
            };

            if ($recipients->isEmpty()) {
                return;
            }

            $orgName = (string) $group->masjid?->name ?: (string) $masjid->name;
            $orgEmail = $masjid->email ?? null;
            $signInUrl = rtrim((string) config('app.url'), '/').'/family/'.$masjid->id.'/sign-in';
            $groupLabel = (string) $group->name;

            foreach ($recipients as $recipient) {
                try {
                    Mail::to($recipient->address)->send(new GroupUpdateNudgeMail(
                        orgName: $orgName,
                        groupLabel: $groupLabel,
                        kind: $kind,
                        signInUrl: $signInUrl,
                        recipientName: $recipient->name,
                        orgEmail: $orgEmail,
                    ));
                } catch (Throwable $e) {
                    // One dead address must not stop the rest.
                    Log::warning('group nudge email failed for '.$recipient->address.': '.$e->getMessage());
                }
            }

            $push->deliver($masjid, $group, $this->event, $recipients, $kind);
        } catch (Throwable $e) {
            // The whole fan-out is best-effort; the write it followed already
            // succeeded and must stay successful.
            Log::error('SendGroupNotificationJob failed: '.$e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('SendGroupNotificationJob permanently failed: '.($e?->getMessage() ?? 'unknown'));
    }
}
