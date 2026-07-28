<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Forms\IndexFormResponsesRequest;
use App\Models\Masjid;
use App\Support\Errors;
use App\Support\FormInsights;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manara Insights for a form's responses.
 *
 * Gated on the Assistant entitlement by the `assistant` middleware (which checks
 * masjids.assistant_enabled and must run AFTER `tenant`). The SPA hides the panel when
 * the flag is false, but that gate is cosmetic — the router guard deliberately fails open
 * while the masjid is still loading — so the server refusing is the real boundary.
 *
 * Note this endpoint does NOT go through MasjidAssistantService. That service exposes the
 * whole tool surface, writes included, so routing "summarise my camp responses" through it
 * would let a summary request mutate masjid content. Insights are computed directly and
 * are strictly read-only.
 */
class FormInsightsController extends Controller
{
    /**
     * GET /api/admin/masjids/{masjid_id}/forms/{form_id}/insights
     *
     * Honours the same filters as the responses list, so the panel always describes
     * exactly the set the admin is looking at.
     */
    public function show(IndexFormResponsesRequest $request, $masjid_id, $form_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $form = $masjid->forms()->findOrFail($form_id);

            $responses = $form->responses()
                ->where('masjid_id', $masjid->id)
                ->search($request->input('q'))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('submitted_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('submitted_at', '<=', $request->date('to')))
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => FormInsights::for($form)->build($responses),
                'meta' => [
                    'form' => [
                        'id' => $form->id,
                        'name' => $form->name,
                    ],
                    'filtered' => $request->hasAny(['q', 'status', 'from', 'to']),
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
