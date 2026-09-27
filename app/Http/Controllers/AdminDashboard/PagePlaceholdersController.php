<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\Studio\PlaceholderChecklist;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page builder's "to fill" checklist for a Studio organisation (Studio W2
 * S10). Registered inside the page builder's gates (`web_pages` and `website`),
 * so exactly the people who may edit the pages see it. Empty, and so drawn as
 * nothing, for every organisation without Studio's marker.
 */
class PagePlaceholdersController extends Controller
{
    public function index(string $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => PlaceholderChecklist::forMasjid($masjid),
        ], Response::HTTP_OK);
    }
}
