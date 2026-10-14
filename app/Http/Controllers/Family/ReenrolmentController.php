<?php

declare(strict_types=1);

namespace App\Http\Controllers\Family;

use App\Models\SchoolReenrolment;
use App\Services\Schools\SchoolReenrolments;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

/** Every family read/write repeats the exact Marks/Attendance child guard. */
class ReenrolmentController extends FamilyController
{
    private function enrolledSubject($group_id, $membership_id): \App\Models\GroupMembership
    {
        $group = $this->group($group_id);
        $child = $this->subject($group, $membership_id);
        abort_unless($group->kind === 'class' && $child->role === 'member' && $child->left_on === null, 404);
        return $child;
    }
    public function show($masjid_id, $group_id, $membership_id, $reenrolment_id): JsonResponse
    {
        $child = $this->enrolledSubject($group_id, $membership_id);
        $campaign = SchoolReenrolment::findOrFail($reenrolment_id);
        return response()->json(['status' => 'success', 'data' => app(SchoolReenrolments::class)->child($campaign, $child, (int) $group_id)]);
    }
    public function deposit($masjid_id, $group_id, $membership_id, $reenrolment_id): JsonResponse
    {
        $child = $this->enrolledSubject($group_id, $membership_id);
        $campaign = SchoolReenrolment::findOrFail($reenrolment_id);
        return response()->json(['status' => 'success', 'data' => app(\App\Services\Schools\SchoolReenrolmentDeposits::class)->page($campaign, $child, $this->contact())]);
    }
    public function quote(Request $request, $masjid_id, $group_id, $membership_id, $reenrolment_id): JsonResponse
    {
        $child = $this->enrolledSubject($group_id, $membership_id);
        $campaign = SchoolReenrolment::findOrFail($reenrolment_id);
        app(\App\Services\Schools\SchoolReenrolmentDeposits::class)->page($campaign, $child, $this->contact());
        $data = $request->validate(['data' => ['required', 'array'], 'pay_with' => ['required', Rule::in(['card', 'office'])], 'cover_fees' => ['sometimes', 'boolean']]);
        $form = \App\Models\Form::where('masjid_id', $campaign->masjid_id)->findOrFail($campaign->deposit_form_id);
        $clean = \App\Support\FormSchema::for($form)->only($data['data']);
        return response()->json(['status' => 'success', 'data' => \App\Support\FormPayment::quote($form, $clean, (bool) ($data['cover_fees'] ?? false), $data['pay_with'] === 'card')]);
    }
    public function answer(Request $request, $masjid_id, $group_id, $membership_id, $reenrolment_id): JsonResponse
    {
        $child = $this->enrolledSubject($group_id, $membership_id);
        $campaign = SchoolReenrolment::findOrFail($reenrolment_id);
        $data = $request->validate(['answer' => ['required', Rule::in(['returning', 'not_returning'])]]);
        return response()->json(['status' => 'success', 'data' => app(SchoolReenrolments::class)->answer($campaign, $child, $data, $this->contact())]);
    }
}
