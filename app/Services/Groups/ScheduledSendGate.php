<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\TenantContext;

/**
 * The questions a scheduled story or conversation is asked AGAIN at the moment it
 * would go out (T-002.4, S15).
 *
 * A schedule is a promise made days ago, on the authority of who the author was and
 * what the class looked like then. Neither is guaranteed to hold when the time
 * comes, and the write path it calls (GroupThreadWriter, the story publisher) trusts
 * its arguments. So the sweep asks here first, and a "no" is not a silent skip: the
 * caller records it as a FAILED item with the reason, which the teacher and the office
 * see in the Scheduled list.
 *
 *   AUTHOR    Is the author still somebody who may write to this class? A teacher must
 *             still be on its `group_staff`; an office administrator must still belong
 *             to the organisation and still hold `manage contacts`. An archived or
 *             deleted account is nobody. This is the S15 rule: the author left the
 *             class before the send time, so it is NOT sent.
 *   ABOUT     A conversation about one child: is that child still a participant who has
 *             not left? A conversation about a child who has since left the roster would
 *             open a private channel with a family the class no longer holds.
 *
 * Every question runs with the tenant BOUND to the item's own organisation and the
 * previous binding restored in a `finally`: GroupAudience reads through the tenant
 * scope, and a console sweep starts unbound, which reads as "no standing" for
 * everybody, the same answer for the wrong reason.
 */
class ScheduledSendGate
{
    public function __construct(private GroupAudience $audience, private GroupThreadWriter $writer)
    {
    }

    /**
     * Why the author may no longer send to `$group`, or null when they still may.
     * The strings are read by teachers: they say what happened and stay free of
     * anything about a child.
     */
    public function authorRefusal(?int $authorUserId, Group $group): ?string
    {
        if ($authorUserId === null) {
            return 'The account that wrote it has been removed.';
        }

        // withoutGlobalScopes() also drops SoftDeletes; an archived account keeps its
        // group_staff rows, so the trashed check is explicit (as the reaction digest's).
        $user = User::withoutGlobalScopes()->whereNull('deleted_at')->find($authorUserId);

        if ($user === null) {
            return 'The account that wrote it has been removed.';
        }

        return $this->withTenant($group, function () use ($user, $group): ?string {
            if ($this->audience->isLeaderOf($user, $group)) {
                return null;
            }

            // A teacher who is no longer on the class has no other standing in it.
            if ($user->type === 'Teacher') {
                return 'The author no longer teaches this class.';
            }

            if ($user->type === 'SuperAdmin') {
                return null;
            }

            $belongs = (int) $group->masjid?->user_id === (int) $user->id
                || MasjidUser::where('masjid_id', (int) $group->masjid_id)->where('user_id', $user->id)->exists();

            if ($belongs && $user->can('manage contacts')) {
                return null;
            }

            return 'The author no longer has access to this class.';
        });
    }

    /**
     * For a conversation about one child: why it may no longer be opened, or null.
     * A group-wide conversation is about nobody and always passes.
     */
    public function aboutRefusal(Group $group, ?int $aboutMembershipId, bool $aboutOneChild): ?string
    {
        if (! $aboutOneChild) {
            return null;
        }

        if ($aboutMembershipId === null) {
            return 'The child is no longer on the class roster.';
        }

        $membership = $this->withTenant($group, fn (): ?GroupMembership => $this->writer->aboutMembership($group, $aboutMembershipId));

        return $membership === null ? 'The child has left the class or is no longer on its roster.' : null;
    }

    /**
     * The participant, when still valid, for the write to name. Null exactly when
     * aboutRefusal() would refuse (or the conversation is group-wide).
     */
    public function aboutMembership(Group $group, ?int $aboutMembershipId): ?GroupMembership
    {
        if ($aboutMembershipId === null) {
            return null;
        }

        return $this->withTenant($group, fn (): ?GroupMembership => $this->writer->aboutMembership($group, $aboutMembershipId));
    }

    /**
     * Run `$callback` with the tenant bound to the group's organisation, and put back
     * exactly what was bound before (id AND the membership that admitted it).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTenant(Group $group, callable $callback): mixed
    {
        $tenant = app(TenantContext::class);
        $previousId = $tenant->get();
        $previousMembership = $tenant->membership();

        $tenant->set((int) $group->masjid_id);

        try {
            return $callback();
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
}
