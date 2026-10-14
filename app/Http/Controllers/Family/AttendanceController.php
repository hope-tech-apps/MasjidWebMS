<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupMembership;
use App\Services\Schools\AttendanceReader;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** One child's attendance across the classes this caller may open through the Marks guard. */
class AttendanceController extends FamilyController
{
    public function forMember($masjid_id, $group_id, $membership_id): JsonResponse
    {
        $membership = $this->subject($this->group($group_id), $membership_id);
        $memberships = [];
        $candidates = GroupMembership::query()->participants()->where('contact_id', $membership->contact_id)->with('group')->get();
        foreach ($candidates as $candidate) {
            if (! $candidate->group || $candidate->group->kind !== 'class') continue;
            try {
                // Reuse Marks' exact guard for every additional class; being guardian in one grants nothing in another.
                $memberships[] = $this->subject($candidate->group, $candidate->id)->id;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) throw $e;
            }
        }
        return response()->json(['status' => 'success', 'data' => app(AttendanceReader::class)->family((int) $membership->masjid_id, $memberships), 'meta' => $this->meta()]);
    }
}
