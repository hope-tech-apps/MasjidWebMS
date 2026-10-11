<?php

namespace App\Http\Middleware;

use App\Models\TimetableClassRoom;
use App\Support\{ClassSubjectMode, TenantContext};
use Closure;
use Illuminate\Http\{JsonResponse, Request};

/** Decorate only authorized class GET results; frozen readers and OFF SQL stay intact. */
class AppendClassLocation
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $school = app(TenantContext::class)->get();
        if (! $response instanceof JsonResponse || ! $response->isSuccessful()
            || ! ClassSubjectMode::timetableEnabled($school)) {
            return $response;
        }

        $payload = $response->getData(true);
        $single = $request->route('group_id') !== null;
        $office = $request->is('api/admin/*');
        $rows = $single ? [$payload['data']] : ($office ? $payload['data']['data'] : $payload['data']);
        $ids = array_column(array_filter($rows, fn ($row) => $row['kind'] === 'class'), 'id');

        // Both sides of the join belong to the bound school. Only ids already
        // disclosed by the existing list/leads rule can enter this lookup.
        $names = TimetableClassRoom::query()
            ->join('timetable_rooms', function ($join) {
                $join->on('timetable_rooms.id', '=', 'timetable_class_rooms.room_id')
                    ->on('timetable_rooms.masjid_id', '=', 'timetable_class_rooms.masjid_id');
            })
            ->where('timetable_class_rooms.masjid_id', $school)
            ->whereIn('timetable_class_rooms.group_id', $ids)
            ->pluck('timetable_rooms.name', 'timetable_class_rooms.group_id');
        foreach ($rows as &$row) {
            if ($row['kind'] === 'class') $row['location_name'] = $names[$row['id']] ?? null;
        }
        unset($row);
        if ($single) $payload['data'] = $rows[0];
        elseif ($office) $payload['data']['data'] = $rows;
        else $payload['data'] = $rows;
        $response->setData($payload);

        return $response;
    }
}
