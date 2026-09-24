<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioCatalogueRequest;
use App\Support\CapabilityCatalogue;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manara Studio's feature step: what a new organisation of one type is offered
 * and born with (App\Support\CapabilityCatalogue). SuperAdmin-only through the
 * `super` middleware on the studio route group, and read-only.
 */
class StudioCatalogueController extends Controller
{
    public function show(StudioCatalogueRequest $request)
    {
        $orgType = (string) $request->validated('org_type');

        return response()->json([
            'status' => 'success',
            'data' => [
                'org_type' => $orgType,
                'groups' => CapabilityCatalogue::forOrgType($orgType),
            ],
        ], Response::HTTP_OK);
    }
}
