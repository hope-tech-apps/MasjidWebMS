<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Assistant\AssistantChatRequest;
use App\Models\Masjid;
use App\Services\Assistant\MasjidAssistantService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Masjid Assistant chat endpoint.
 *
 * Authorization is layered and all of it happens before this controller runs:
 *   auth:sanctum → admin (UserAdminMiddleware) → tenant (ResolveMasjidTenant,
 *   which 403s a MasjidAdmin targeting a masjid that isn't theirs) → assistant
 *   (the per-masjid feature gate).
 *
 * So by the time we're here, this user is provably allowed to act on this
 * masjid. Which of the portal's capabilities they may use is then decided
 * per-tool in ToolRegistry, and re-checked at execution.
 */
class AssistantController extends Controller
{
    public function __construct(private MasjidAssistantService $assistant)
    {
    }

    public function chat(AssistantChatRequest $request, string $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $user = Auth::user();

        $image = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $image = [
                'media_type' => $file->getMimeType(),
                'data' => base64_encode(file_get_contents($file->getRealPath())),
            ];
        }

        try {
            $result = $this->assistant->handle(
                user: $user,
                masjid: $masjid,
                message: $request->string('message')->toString(),
                image: $image,
                history: $request->input('history', []),
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'failed',
                'data' => 'The assistant is unavailable right now. Please try again.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'reply' => $result['reply'],
                // The visible audit trail — the UI renders this so the admin can
                // see exactly which actions ran, and verify them.
                'actions' => $result['actions'],
            ],
        ], Response::HTTP_OK);
    }
}
