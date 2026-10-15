<?php

namespace App\Http\Middleware;

use App\Support\{ClassSubjectMode, TenantContext, TranscriptMode};
use Closure;
use Illuminate\Http\{JsonResponse, Request};

/** Uses the school already loaded by the realm: unused OFF readers add no SQL or fields. */
class AppendTranscriptAccess
{
    public function handle(Request $request,Closure $next)
    {
        $response=$next($request);
        $school=ClassSubjectMode::loadedOrganisation((int)app(TenantContext::class)->get());
        if (!$response instanceof JsonResponse || !$response->isSuccessful() || !TranscriptMode::enabled($school)) return $response;
        $body=$response->getData(true);
        if (($body['data']['kind'] ?? null)==='class') {
            $body['data']['transcripts_enabled']=true; $response->setData($body); $response->header('Cache-Control','private, no-store');
        }
        return $response;
    }
}
