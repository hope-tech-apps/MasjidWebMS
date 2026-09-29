<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioOrganisationPreviewRequest;
use App\Models\Masjid;
use App\Support\Studio\OrganisationSnapshot;
use App\Support\Studio\PreviewInput;
use App\Support\Studio\StudioPreview;
use Symfony\Component\HttpFoundation\Response;

/**
 * Studio opening an organisation that already exists (Studio W2 S9, R14).
 * SuperAdmin-only through the `super` middleware on the studio route group.
 *
 * Read-only: the snapshot, and a preview that applies the changes being
 * considered to an in-memory copy. The only writers Studio uses for a live
 * organisation are the ones that already exist: the bulk capability PATCH
 * (S7), the theme screen's save, and brand-asset regeneration (S8).
 */
class StudioOrganisationsController extends Controller
{
    public function show(int $organisation_id)
    {
        return response()->json([
            'status' => 'success',
            'data' => OrganisationSnapshot::of(Masjid::findOrFail($organisation_id)),
        ], Response::HTTP_OK);
    }

    /**
     * A POST because it carries unsaved choices, not because it changes
     * anything: it writes nothing (StudioOrganisationPreviewTest reads the
     * query log).
     */
    public function preview(StudioOrganisationPreviewRequest $request, int $organisation_id)
    {
        $masjid = Masjid::findOrFail($organisation_id);

        return response()->json([
            'status' => 'success',
            'data' => StudioPreview::forInput(PreviewInput::fromMasjid($masjid, $request->overrides())),
        ], Response::HTTP_OK);
    }
}
