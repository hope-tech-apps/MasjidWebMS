<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreScheduledMessageRequest;
use App\Http\Requests\Admin\Groups\UpdateScheduledMessageRequest;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMessageSchedule;
use App\Models\GroupThread;
use App\Models\User;
use App\Services\Groups\GroupThreadWriter;
use App\Services\Groups\ScheduledSendGate;
use App\Support\GroupAudience;
use App\Support\ScheduledTime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Send later" for a NEW conversation, and the Scheduled list beside it (T-002.4,
 * owner 2026-09-29).
 *
 * WHAT THIS IS AND IS NOT. A teacher (or the office) writes a conversation now and it
 * is opened later, by `groups:publish-due`, through the SAME GroupThreadWriter that
 * opens one on the request. Until then it is a row in `group_message_schedules` and
 * nothing else: not a thread, so no reader, unread count, receipt, reaction, digest or
 * family endpoint can know it exists. Only NEW conversations (S11); replies are sent
 * when they are written.
 *
 * WHO MAY DO WHAT (S14, as decided 2026-09-30):
 *   - see the Scheduled list  the class's teachers (co-teachers see each other's) and
 *                             the office. Both routes are gated: `teacher.leads` in
 *                             the teacher realm, `manage contacts` in the admin realm,
 *                             and the controller asks GroupAudience::mayCancelScheduled
 *                             again so a route that loses its middleware cannot
 *                             widen who sees a message about a child.
 *   - READ one                the class's teachers and its author
 *                             (GroupAudience::mayReadUnpublished). The office that is
 *                             neither sees METADATA only: class, author, time, scope,
 *                             status, failure reason. `subject`, `body` and the
 *                             child's name are absent from its payload, because a
 *                             conversation about one child is then no more visible
 *                             before it is sent than after it.
 *   - write one               anyone who could open the conversation on the spot.
 *   - edit, send now          the AUTHOR, and an office login that is also a teacher
 *                             of the class. The office on its own gets a 403: changing
 *                             words needs reading them. A co-teacher is refused too.
 *   - cancel                  the AUTHOR and the office (`manage contacts`). A
 *                             co-teacher sees it and is refused (403).
 *
 * EDIT, SEND NOW and CANCEL are each ONE conditional UPDATE guarded by the status,
 * never a read-then-write: a sweep that claims the item between the page loading and
 * the click must win cleanly. The loser is told it is no longer editable (422), and
 * nothing is half-changed.
 *
 * "SEND NOW" is a PUT that sets the time to now; the sweep sends it within a minute.
 * There is deliberately no second send path in this controller.
 *
 * Mounted in BOTH staff realms (routes/admin.php, routes/teacher.php), like the
 * conversations it feeds. Tenant isolation is the bound tenant plus BelongsToMasjid: a
 * group or schedule of another school is a 404. masjid_id is never accepted from the
 * request.
 */
class GroupMessageSchedulesController extends Controller
{
    /** @var array<int,bool> group id => may the caller read its unpublished items (one check per class per request) */
    private array $leaderCache = [];

    public function __construct(private GroupAudience $audience, private GroupThreadWriter $writer, private ScheduledSendGate $gate)
    {
    }

    /** GET .../groups/{group_id}/scheduled-messages — the items still ahead: waiting, sending or failed. */
    public function index(Request $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);
        $this->authorizeSeeing($request->user(), $group);

        $pending = $group->messageSchedules()
            ->whereIn('status', [
                GroupMessageSchedule::STATUS_SCHEDULED,
                GroupMessageSchedule::STATUS_SENDING,
                GroupMessageSchedule::STATUS_FAILED,
            ])
            ->with(['author:id,name', 'aboutMembership.contact:id,first_name,last_name'])
            ->orderBy('send_at')
            ->orderBy('id');

        // ONE PAGE HOLDING EVERYTHING: an item the list does not show cannot be edited,
        // sent now or cancelled (S14). The paginator shape is kept so no client changes.
        $items = $pending->paginate(max(1, (clone $pending)->count()));

        $zone = ScheduledTime::schoolTimezone();
        $items->through(fn (GroupMessageSchedule $item) => $this->serialize($item, $request->user(), $zone));

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => $this->meta($zone),
        ], Response::HTTP_OK);
    }

    /** POST .../groups/{group_id}/scheduled-messages */
    public function store(StoreScheduledMessageRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $about = null;

        if ($request->input('scope') === GroupThread::SCOPE_PARTICIPANT) {
            // The same refusal, from the same lookup, that opening the conversation
            // now would give: a participant of THIS group who has not left.
            $about = $this->writer->aboutMembership($group, $request->integer('about_membership_id'));

            if ($about === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That id names no participant of this group, so no conversation can be opened about them.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $item = GroupMessageSchedule::create([
            'group_id' => $group->id,
            // The AUTHENTICATED account, never a client-supplied author.
            'author_user_id' => $request->user()?->id,
            'kind' => GroupMessageSchedule::KIND_THREAD,
            'scope' => (string) $request->input('scope'),
            'about_membership_id' => $about?->id,
            'subject' => (string) $request->input('subject'),
            'body' => (string) $request->input('body'),
            'send_at' => $request->sendAt(),
            'status' => GroupMessageSchedule::STATUS_SCHEDULED,
        ]);

        $zone = ScheduledTime::schoolTimezone();

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($item->load(['author:id,name', 'aboutMembership.contact:id,first_name,last_name']), $request->user(), $zone),
            'meta' => $this->meta($zone),
        ], Response::HTTP_CREATED);
    }

    /** PUT .../scheduled-messages/{schedule_id} — edit, reschedule, or send now. */
    public function update(UpdateScheduledMessageRequest $request, $masjid_id, $group_id, $schedule_id)
    {
        $group = Group::findOrFail($group_id);
        $this->authorizeSeeing($request->user(), $group);
        $item = $group->messageSchedules()->findOrFail($schedule_id);

        $this->authorizeChanging($request->user(), $item, editing: true);

        if (! $item->isEditable()) {
            return $this->noLongerEditable($item);
        }

        $fields = $request->safe()->only(['subject', 'body']);
        $sendNow = $request->boolean('send_now');
        $sendAt = $request->sendAt();

        if (($sendNow || $sendAt !== null) && ($why = $this->refusalNow($group, $item)) !== null) {
            // The gates are asked again AT ONCE: a new time or "Send now" on an item whose
            // author left the class (or whose child left the roster) would only be
            // refused again by the sweep with the same words, so the answer is given
            // here, while the person editing can still act on it.
            return response()->json([
                'status' => 'failed',
                'data' => ['send_at' => ["{$why} It cannot be put back in the queue: cancel it and write it again."]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($sendNow || $sendAt !== null) {
            // A new time is a new chance: a FAILED item is put back in the queue, and
            // asked its questions again when its time comes.
            $fields['send_at'] = $sendNow ? now() : $sendAt;
            $fields['status'] = GroupMessageSchedule::STATUS_SCHEDULED;
            $fields['failure_reason'] = null;

            // Retention follows the send day, like a fresh item, unless the row's own
            // window was somebody's choice (nothing here sets one, so it is ours).
            $days = (int) config('groups.messaging.retention_days', 0);
            $fields['retained_until'] = $days > 0
                ? $fields['send_at']->copy()->addDays($days)->toDateString()
                : null;
        }

        if ($fields === []) {
            return $this->respond($item->fresh(['author:id,name', 'aboutMembership.contact:id,first_name,last_name']), $request->user());
        }

        // ONE guarded UPDATE: it changes the row only while it is still waiting or
        // failed. A sweep that claimed it a moment ago has moved it to `sending`, and
        // then this touches nothing and says so.
        $changed = GroupMessageSchedule::query()
            ->whereKey($item->getKey())
            ->whereIn('status', [GroupMessageSchedule::STATUS_SCHEDULED, GroupMessageSchedule::STATUS_FAILED])
            ->update($fields + ['updated_at' => now()]);

        if ($changed !== 1) {
            return $this->noLongerEditable($item->fresh());
        }

        return $this->respond($item->fresh(['author:id,name', 'aboutMembership.contact:id,first_name,last_name']), $request->user());
    }

    /** DELETE .../scheduled-messages/{schedule_id} — cancel. Kept as `cancelled`, not deleted. */
    public function destroy(Request $request, $masjid_id, $group_id, $schedule_id)
    {
        $group = Group::findOrFail($group_id);
        $this->authorizeSeeing($request->user(), $group);
        $item = $group->messageSchedules()->findOrFail($schedule_id);

        $this->authorizeChanging($request->user(), $item);

        $changed = GroupMessageSchedule::query()
            ->whereKey($item->getKey())
            ->whereIn('status', [GroupMessageSchedule::STATUS_SCHEDULED, GroupMessageSchedule::STATUS_FAILED])
            ->update(['status' => GroupMessageSchedule::STATUS_CANCELLED, 'updated_at' => now()]);

        if ($changed !== 1) {
            return $this->noLongerEditable($item->fresh());
        }

        return $this->respond($item->fresh(['author:id,name', 'aboutMembership.contact:id,first_name,last_name']), $request->user());
    }

    // ------------------------------------------------------------- internals

    /** The class's teachers and the office, and nobody else: they see the metadata and may cancel. */
    private function authorizeSeeing(?User $user, Group $group): void
    {
        if (! $this->audience->mayCancelScheduled($user, $group)) {
            abort(403, 'You are not entitled to the scheduled conversations of this group.');
        }
    }

    /**
     * Cancelling: the author and the office (S14). Editing, rescheduling, sending now
     * (`$editing`): those of them who may also READ it, so not the office on its own
     * (S14, 2026-09-30). A co-teacher sees an item and may not touch it.
     */
    private function authorizeChanging(?User $user, GroupMessageSchedule $item, bool $editing = false): void
    {
        if ($this->mayCancel($user, $item) && (! $editing || $this->mayReadContent($user, $item))) {
            return;
        }

        abort(403, $editing
            ? 'Only the author can change a scheduled conversation. The office can cancel it.'
            : 'Only the author or the office can cancel a scheduled conversation.');
    }

    private function mayCancel(?User $user, GroupMessageSchedule $item): bool
    {
        return $user !== null
            && ((int) $item->author_user_id === (int) $user->id || $user->can('manage contacts'));
    }

    /** May this caller read what the item SAYS: a teacher of its class, or its author. */
    private function mayReadContent(?User $user, GroupMessageSchedule $item): bool
    {
        if ($user === null) {
            return false;
        }

        if ($item->author_user_id !== null && (int) $item->author_user_id === (int) $user->id) {
            return true;
        }

        return $this->leaderCache[(int) $item->group_id]
            ??= $this->audience->mayReadUnpublished($user, $item->group ?? Group::findOrFail($item->group_id));
    }

    /**
     * A scheduled conversation as the OFFICE reads it (S14, 2026-09-30): where it
     * stands, never what it says or who it is about. `subject`, `body` and `about`
     * (the child's name) are ABSENT, not null; `content_hidden` says why. The office
     * may cancel it and nothing else, so `can_change` is false.
     *
     * @return array<string,mixed>
     */
    private function metadataOnly(GroupMessageSchedule $item, ?User $viewer, string $zone): array
    {
        return [
            'id' => (int) $item->id,
            'group_id' => (int) $item->group_id,
            'kind' => 'thread',
            'scope' => $item->scope,
            'audience' => $item->isAboutOneChild() ? 'one_child' : 'class',
            'send_at' => optional($item->send_at)->toIso8601String(),
            'send_at_local' => ScheduledTime::local($item->send_at, $zone),
            'status' => $item->status,
            'failure_reason' => $item->failure_reason,
            'author' => $item->author ? ['id' => $item->author->id, 'name' => $item->author->name] : null,
            'can_change' => false,
            'can_cancel' => $item->isEditable() && $this->mayCancel($viewer, $item),
            'content_hidden' => true,
            'created_at' => optional($item->created_at)->toIso8601String(),
        ];
    }

    /** The sweep's own gates, asked now (ScheduledSendGate), or null when the item may go out. */
    private function refusalNow(Group $group, GroupMessageSchedule $item): ?string
    {
        return $this->gate->authorRefusal($item->author_user_id !== null ? (int) $item->author_user_id : null, $group)
            ?? $this->gate->aboutRefusal($group, $item->about_membership_id !== null ? (int) $item->about_membership_id : null, $item->isAboutOneChild());
    }

    private function noLongerEditable(GroupMessageSchedule $item)
    {
        return response()->json([
            'status' => 'failed',
            'data' => ['status' => ['This conversation can no longer be changed: it is '.$item->status.'.']],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function respond(GroupMessageSchedule $item, ?User $user)
    {
        $zone = ScheduledTime::schoolTimezone();

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($item, $user, $zone),
            'meta' => $this->meta($zone),
        ], Response::HTTP_OK);
    }

    /**
     * One scheduled conversation as the staff who may see it read it.
     *
     * @return array<string,mixed>
     */
    private function serialize(GroupMessageSchedule $item, ?User $viewer, string $zone): array
    {
        if (! $this->mayReadContent($viewer, $item)) {
            return $this->metadataOnly($item, $viewer, $zone);
        }

        $contact = $item->aboutMembership?->contact;

        return [
            'id' => (int) $item->id,
            'group_id' => (int) $item->group_id,
            'scope' => $item->scope,
            'about' => $item->about_membership_id === null ? null : [
                'membership_id' => (int) $item->about_membership_id,
                'contact' => $contact instanceof Contact ? [
                    'id' => $contact->id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                ] : null,
            ],
            'subject' => $item->subject,
            'body' => $item->body,
            'send_at' => optional($item->send_at)->toIso8601String(),
            'send_at_local' => ScheduledTime::local($item->send_at, $zone),
            'status' => $item->status,
            'failure_reason' => $item->failure_reason,
            'sent_thread_id' => $item->sent_thread_id !== null ? (int) $item->sent_thread_id : null,
            'author' => $item->author ? ['id' => $item->author->id, 'name' => $item->author->name] : null,
            'can_change' => $item->isEditable() && $this->mayCancel($viewer, $item),
            'can_cancel' => $item->isEditable() && $this->mayCancel($viewer, $item),
            'content_hidden' => false,
            'created_at' => optional($item->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function meta(string $zone): array
    {
        return [
            'scheduling' => [
                'timezone' => $zone,
                'max_days_ahead' => ScheduledTime::maxDaysAhead(),
            ],
            'thread_scopes' => GroupThread::SCOPES,
            'max_message_length' => (int) config('groups.messaging.max_message_length', 0),
        ];
    }
}
