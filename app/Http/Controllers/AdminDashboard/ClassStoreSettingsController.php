<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassStore\SetClassStoreSettingsRequest;
use App\Models\Masjid;
use App\Models\MasjidPointsSetting;
use App\Support\ClassStoreSettings;
use App\Support\SchoolPointsWeek;
use App\Support\SchoolSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * How a school's points turn into Manara Bucks, for a SuperAdmin (T-003.4).
 *
 * SuperAdmin only, like the capability that switches the store on (`class_store`): the same
 * person decides that a school has the store, how many points make one buck (R1, settable per
 * school), from which day points count, and whether the paper cash-out is offered at all
 * (built and OFF while the physical Manara Bucks are paused). Neither a school's own
 * administrators nor its teachers can move any of it. GET checks in the controller (a 403 like
 * PointsReportScheduleController); PUT checks in the request's authorize().
 *
 * GET says what the settings ARE, whether the store is switched on, and the zone the weeks are
 * read in. A change goes on the record with who made it, at a level production keeps (a rate
 * change decides how much a week of points is worth to every child).
 */
class ClassStoreSettingsController extends Controller
{
    public function show(string $masjid_id)
    {
        $this->authorizeSuper();

        return $this->respond(Masjid::findOrFail($masjid_id));
    }

    public function update(SetClassStoreSettingsRequest $request, string $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $row = MasjidPointsSetting::withoutMasjidScope()->firstOrNew(['masjid_id' => $masjid->id]);
        $row->masjid_id = $masjid->id;

        foreach (['points_per_buck', 'paper_bucks_enabled', 'bucks_from'] as $field) {
            // Only what was SENT changes; a field the request omits stays as it was.
            if ($request->exists($field)) {
                $row->{$field} = $request->validated($field);
            }
        }

        $row->save();

        Log::warning('Class store settings changed', [
            'masjid_id' => $masjid->id,
            'actor_user_id' => Auth::id(),
            'points_per_buck' => $row->points_per_buck,
            'paper_bucks_enabled' => (bool) $row->paper_bucks_enabled,
            'bucks_from' => $row->bucks_from?->toDateString(),
        ]);

        return $this->respond($masjid);
    }

    private function authorizeSuper(): void
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can change how a school\'s points become Manara Bucks.');
        }
    }

    private function respond(Masjid $masjid)
    {
        $settings = ClassStoreSettings::for((int) $masjid->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'masjid_id' => (int) $masjid->id,
                'enabled' => SchoolSettings::classStore($masjid),
                'points_per_buck' => $settings['points_per_buck'],
                'paper_bucks_enabled' => $settings['paper_bucks_enabled'],
                'bucks_from' => $settings['bucks_from'],
                // Null until the first minting run for a school with the store on sets it.
                'bucks_from_source' => $settings['bucks_from'] === null ? 'not started' : 'set',
                'timezone' => SchoolPointsWeek::timezone((int) $masjid->id),
            ],
        ], Response::HTTP_OK);
    }
}
