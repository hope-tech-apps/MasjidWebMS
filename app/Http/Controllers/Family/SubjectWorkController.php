<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupThread;
use App\Support\FamilySubjectWork;

/** Parent-only reads. Item lookup happens only inside the already-authorized shared projection. */
class SubjectWorkController extends FamilyController
{
    private function shared($groupId, $membershipId): array
    {
        $group = $this->group($groupId);
        $member = $group->memberships()->participants()->findOrFail($membershipId);
        // Reuse the exact child-conversation gate, with its target already resolved in this class.
        $thread = new GroupThread(['scope' => GroupThread::SCOPE_PARTICIPANT, 'about_membership_id' => $member->id]);
        $thread->setRelation('aboutMembership', $member);
        abort_unless($this->audience->mayReceiveThread($this->contact(), $group, $thread), 404);
        return FamilySubjectWork::forChildren($this->contact(), $group, collect([$member]), $this->audience)[$member->id] ?? [];
    }

    public function index($masjid_id, $group_id, $membership_id)
    {
        return response()->json(['status' => 'success', 'data' => $this->shared($group_id, $membership_id)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function item($groupId, $membershipId, $subjectId, string $kind, $id)
    {
        $subject = collect($this->shared($groupId, $membershipId))->firstWhere('id', (int) $subjectId);
        $item = collect($subject[$kind] ?? [])->firstWhere('id', (int) $id);
        abort_if($item === null, 404);
        return response()->json(['status' => 'success', 'data' => $item])->header('Cache-Control', 'private, no-store');
    }

    public function mark($masjid_id, $group_id, $membership_id, $subject_id, $mark_id)
    {
        return $this->item($group_id, $membership_id, $subject_id, 'marks', $mark_id);
    }

    public function note($masjid_id, $group_id, $membership_id, $subject_id, $note_id)
    {
        return $this->item($group_id, $membership_id, $subject_id, 'notes', $note_id);
    }
}
