<?php

namespace App\Http\Middleware;

use App\Support\{ClassSubjectMode, ReportCardCriteria, TenantContext};
use Closure;
use Illuminate\Http\{JsonResponse, Request};

/** Add the teacher entry only after an authorized class read; OFF adds no SQL. */
class AppendReportCardCriteria
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $school = ClassSubjectMode::loadedOrganisation((int) app(TenantContext::class)->get());
        if (! $response instanceof JsonResponse || ! $response->isSuccessful() || ! ReportCardCriteria::enabled($school)) return $response;
        $payload = $response->getData(true);
        $single = $request->route('group_id') !== null;
        $rows = $single ? [$payload['data']] : $payload['data'];
        foreach ($rows as &$row) if (($row['kind'] ?? null) === 'class') $row['report_card_criteria_enabled'] = true;
        unset($row);
        $payload['data'] = $single ? $rows[0] : $rows;
        return $response->setData($payload);
    }
}
