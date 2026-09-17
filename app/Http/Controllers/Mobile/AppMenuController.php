<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\AppMenu;
use App\Support\MobileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `GET /api/mobile/masjids/{masjid_id}/menu` — the app's side menu, derived
 * from one organisation's switches.
 *
 * PUBLIC and unauthenticated, like every other endpoint in this directory, and
 * the body is IDENTICAL with and without an Authorization header. There is no
 * per-user data in it at all: it says what this organisation offers, never what
 * this member has. That is what lets it be cached server-side, carry an ETag,
 * and answer 304 — and it is why the response carries no Vary and sets no
 * cookie.
 *
 * The four answers, and what each one means to a phone (plan v3 §2.1, [R6]):
 *
 *   200  the menu, with `ETag: "<hash>"` and `Cache-Control: no-cache`
 *        (revalidate every time; the BODY is cached here for 10 minutes)
 *   304  nothing changed — keep the cached menu and the tag
 *   404  unknown organisation, a schema this build is too new for, or the kill
 *        row: "menu unavailable"
 *   503  building the payload threw: "menu unavailable"
 *
 * While the kill row is set, tenancy:canary leaves this endpoint out of its
 * plan (config/canary.php `dark_launches`, `answers => 404`) and checks the 404
 * with one probe per run. Change the kill-row status here and that declaration
 * together.
 *
 * Both clients treat 404 and 503 the same way — prefer a cached good menu,
 * otherwise fall back to the legacy /features + /orgs adapter — so this
 * endpoint is deliberately NOT part of the launch-critical chain. That
 * guarantee belongs to /features, which every installed build calls on launch.
 *
 * Every refusal carries an empty `data` OBJECT. App\Support\MobileErrorEnvelope
 * only covers the member routes, and the iPhone app decodes every mobile body
 * through one envelope whose `data` is non-optional: a refusal without the key
 * fails to decode ON THE DEVICE, where no server test can see it.
 */
class AppMenuController extends Controller
{
    public function show(Request $request, $masjid_id): JsonResponse|Response
    {
        // A build that asks for a schema this server does not speak is told
        // "unavailable" rather than handed a shape it will mis-read. Absent or
        // unparseable means 1 — the only schema there is.
        $requested = $request->query('schema');

        if (is_numeric($requested) && (int) $requested > AppMenu::schemaVersion()) {
            return $this->unavailable('This app menu version is not available.');
        }

        if (AppMenu::killed()) {
            return $this->unavailable('The app menu is not available right now.');
        }

        // Soft-deleted organisations are excluded by the global scope: a
        // trashed org has no menu, and its children are not somebody else's to
        // switch into.
        //
        // `listed_at` is deliberately NOT consulted here, and the asymmetry
        // with AppOrgs::forHome() is the point rather than an oversight. The
        // two questions are different:
        //
        //   forHome()  "which organisations may this app OFFER as somewhere to
        //              switch into?" — publishing is the act that answers yes,
        //              so a child mid-setup is filtered out.
        //   here       "what is the menu for the organisation this app WAS
        //              BUILT FOR?" — its id is compiled into the binary.
        //
        // An app is built, installed and tested against its home id for days
        // before a SuperAdmin publishes the organisation; staging org 17 is
        // unlisted today and has live app members signing in. Gating this on
        // `listed()` would mean an unpublished org's own app opens to a drawer
        // it cannot build, which is the worst moment for it. Every other
        // single-organisation mobile endpoint agrees — /show, /orgs, /features
        // and the rest all resolve by id with no listed gate; only the
        // DIRECTORY (MasjidsController::index) uses Masjid::listed(), because a
        // directory is a list of organisations offered to strangers.
        //
        // What that publishes for an unlisted org is its public identity plus
        // its module inventory, to anyone who guesses the id. That is the
        // accepted cost, pinned by
        // AppMenuTenantIsolationTest::an_unlisted_organisation_still_answers_for_its_own_app.
        $home = Masjid::find($masjid_id);

        if ($home === null) {
            return $this->unavailable('Organisation not found.');
        }

        try {
            $entry = Cache::remember(
                MobileCache::masjidKey((int) $home->id, MobileCache::MENU),
                MobileCache::TTL_MEDIUM,
                function () use ($home) {
                    $data = AppMenu::payload($home);

                    return ['hash' => $data['hash'], 'data' => $data];
                }
            );
        } catch (Throwable $e) {
            // One line, at warning, because production runs LOG_LEVEL=warning
            // and a menu that quietly stopped building for one organisation is
            // invisible otherwise — the apps just look slightly stale.
            Log::warning('app menu payload build failed', [
                'masjid_id' => (int) $home->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'failed',
                'message' => 'The app menu could not be built.',
                'data' => new \stdClass(),
            ], 503);
        }

        $etag = '"' . $entry['hash'] . '"';

        if ($this->tagMatches($request->header('If-None-Match'), $entry['hash'])) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'no-cache');
        }

        return response()->json([
            'status' => 'success',
            'data' => $entry['data'],
        ])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Does the client's If-None-Match name the tag we are holding?
     *
     * Tolerant on purpose. nginx's gzip module WEAKENS an ETag it compresses
     * ("abc" becomes W/"abc"), and a client or proxy may send several tags in
     * one comma-separated header. Comparing the raw header string would mean
     * the 304 silently never fires and every launch re-downloads the menu —
     * working, and wrong, and invisible from here.
     */
    private function tagMatches(?string $header, string $hash): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (trim($candidate, '"') === $hash) {
                return true;
            }
        }

        return false;
    }

    /** "Menu unavailable" — one meaning, one shape, whatever the cause. */
    private function unavailable(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'data' => new \stdClass(),
        ], 404);
    }
}
