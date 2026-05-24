<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SplashAnnouncements\StoreSplashAnnouncementRequest;
use App\Http\Requests\Admin\SplashAnnouncements\UpdateSplashAnnouncementRequest;
use App\Models\Masjid;
use App\Models\SplashAnnouncement;
use App\Services\OnesignalInAppMessageService;
use App\Support\Errors;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for per-masjid splash announcements.
 *
 * Mirrors AnnouncementsController in shape:
 *  - safe()->only() to pull validated input
 *  - Spatie MediaLibrary for the image (collection name 'splash_announcements')
 *  - MobileCache::flushMasjid on every mutation so the Nuxt-facing endpoint
 *    re-reads from DB on the next request
 *  - Errors::publicMessage in catch blocks so we never leak internals
 *
 * Adds one thing AnnouncementsController doesn't: after every save we call
 * OnesignalInAppMessageService::sync() so the mobile IAM mirror stays in
 * lockstep with the local row. That call is fail-soft — if OneSignal is
 * down, the admin still gets a success response and the splash still works
 * on the Nuxt site.
 */
class SplashAnnouncementsController extends Controller
{
    public function __construct(private OnesignalInAppMessageService $oneSignalIam)
    {
    }

    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $splashes = SplashAnnouncement::where('masjid_id', $masjid->id)
            ->with('image')
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $splashes,
        ], Response::HTTP_OK);
    }

    public function store(StoreSplashAnnouncementRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $inputs = $request->safe()->only([
                'title', 'body', 'cta_label', 'cta_url',
                'starts_at', 'ends_at', 'priority', 'is_active',
            ]);
            $inputs['masjid_id'] = $masjid->id;
            $inputs['priority'] = $inputs['priority'] ?? 0;
            $inputs['is_active'] = $inputs['is_active'] ?? true;

            $splash = SplashAnnouncement::create($inputs);

            if ($request->hasFile('image')) {
                $splash->addMediaFromRequest('image')->toMediaCollection('splash_announcements');
            }

            // Refresh so the image relation + media URL is available when we
            // build the OneSignal payload.
            $splash->refresh()->load('image');

            $iamId = $this->oneSignalIam->sync($splash);
            if ($iamId && $iamId !== $splash->onesignal_iam_id) {
                $splash->update(['onesignal_iam_id' => $iamId]);
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SPLASH);

            return response()->json([
                'status' => 'success',
                'data' => $splash->load('image'),
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show($masjid_id, $splash_id)
    {
        $splash = SplashAnnouncement::with('image')
            ->where('masjid_id', $masjid_id)
            ->findOrFail($splash_id);

        return response()->json([
            'status' => 'success',
            'data' => $splash,
        ], Response::HTTP_OK);
    }

    public function update(UpdateSplashAnnouncementRequest $request, $masjid_id, $splash_id)
    {
        try {
            $splash = SplashAnnouncement::where('masjid_id', $masjid_id)
                ->findOrFail($splash_id);

            $inputs = $request->safe()->only([
                'title', 'body', 'cta_label', 'cta_url',
                'starts_at', 'ends_at', 'priority', 'is_active',
            ]);
            $splash->update($inputs);

            if ($request->hasFile('image')) {
                $splash->clearMediaCollection('splash_announcements');
                $splash->addMediaFromRequest('image')->toMediaCollection('splash_announcements');
            }

            $splash->refresh()->load('image');

            $iamId = $this->oneSignalIam->sync($splash);
            if ($iamId && $iamId !== $splash->onesignal_iam_id) {
                $splash->update(['onesignal_iam_id' => $iamId]);
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SPLASH);

            return response()->json([
                'status' => 'success',
                'data' => $splash->load('image'),
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Permanent delete + remove from OneSignal. */
    public function destroy($masjid_id, $splash_id)
    {
        try {
            $splash = SplashAnnouncement::where('masjid_id', $masjid_id)
                ->withTrashed()
                ->findOrFail($splash_id);

            $this->oneSignalIam->remove($splash);
            $splash->forceDelete();

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SPLASH);

            return response()->json([
                'status' => 'success',
                'data' => null,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Soft-delete + disable in OneSignal so mobile stops seeing it. */
    public function moveToTrash($masjid_id, $splash_id)
    {
        try {
            $splash = SplashAnnouncement::where('masjid_id', $masjid_id)
                ->findOrFail($splash_id);

            // Disable before delete so the OneSignal mirror doesn't keep firing.
            $splash->update(['is_active' => false]);
            $this->oneSignalIam->sync($splash);

            $splash->delete();

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SPLASH);

            return response()->json([
                'status' => 'success',
                'data' => $splash,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
