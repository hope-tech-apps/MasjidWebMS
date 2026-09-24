<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioPreviewRequest;
use App\Models\StudioDraft;
use App\Support\Studio\StudioPreview;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/admin/studio/drafts/{draft_id}/preview — Step 2's one derivation:
 * the palette gate, the device mockups' data and the starter plan together
 * (docs/manara-studio-w1.md S4, R19). SuperAdmin-only through the `super`
 * middleware on the studio route group.
 *
 * A POST because it carries unsaved answers, not because it changes anything:
 * it writes nothing, not even the draft's updated_at, so previewing can never
 * make another tab's autosave look stale. A provisioned draft may be previewed
 * too; it is the record of what was created.
 */
class StudioPreviewController extends Controller
{
    public function preview(StudioPreviewRequest $request, int $draft_id)
    {
        $draft = StudioDraft::findOrFail($draft_id);

        return response()->json([
            'status' => 'success',
            'data' => StudioPreview::build($draft, $request->sections()),
        ], Response::HTTP_OK);
    }
}
