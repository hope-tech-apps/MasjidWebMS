<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MasjidDomain;
use App\Support\HostName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/organizations/by-host?host=…: which organisation serves a host.
 *
 * The renderer asks this for a Host its build-time map does not know (from
 * S11), so a new client's site works without a redeploy. It is public and
 * unauthenticated by necessity: the renderer has no credential and no tenant
 * until this answers.
 *
 * - The payload is built from an ALLOWLIST, never from toArray(): a column
 *   added to `masjids` next year must not reach an anonymous caller because
 *   someone forgot to hide it here.
 * - `host` is echoed normalised. The renderer refuses any answer whose host
 *   differs from the one it asked about, so this echo is what binds the answer
 *   to the question.
 * - Every answer, found or not, is `no-store`, and nothing is cached here per
 *   host: the host is chosen by the caller, and a cache keyed by it would grow
 *   production's database cache without bound (see MasjidDomain's cache key).
 * - A host that is missing, invalid, unknown, failed, reserved, or belongs to a
 *   trashed organisation gets the same 404, so the answer says nothing about
 *   which of those it was.
 *
 * `favicon_url` and `share_image_url` come from the `favicons` and
 * `share_images` collections Studio writes at Step 3 (S8), and are null for an
 * organisation without them. They are never taken from `logos`: every live
 * tenant has a logo and none has a favicon, and a fallback would change
 * Burlington's and MEC's tab icons unasked (draft-and-logo rule F).
 */
class OrganizationByHostController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $host = HostName::normalize(is_string($request->query('host')) ? $request->query('host') : null);

        $domain = $host === null
            ? null
            : MasjidDomain::query()->served()->where('host', $host)->first();

        $masjid = $domain?->masjid;

        if ($masjid === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'No organisation serves this host.',
            ], 404, ['Cache-Control' => 'no-store']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'OK',
            'data' => [
                'host' => $domain->host,
                'masjid_id' => $masjid->id,
                'name' => $masjid->name,
                'description' => $masjid->description,
                'favicon_url' => $masjid->favicon?->original_url,
                'share_image_url' => $masjid->share_image?->original_url,
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
