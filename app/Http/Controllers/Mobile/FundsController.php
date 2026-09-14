<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Masjid;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public (unauthenticated) list of a masjid's active donation funds, so the app's
 * native donate screen can offer designations (General, Zakat, Sadaqah, …).
 *
 * Unbound (no tenant middleware): filtered by masjid_id explicitly. Only active
 * funds are exposed; a masjid with no funds simply returns an empty list.
 */
class FundsController extends Controller
{
    public function index($masjid_id)
    {
        // While Giving is off the app is offered nothing to give to, so a Donate
        // screen that loads fresh shows the organisation's donation link instead.
        // This list feeds the checkout, which refuses too (Mobile\DonationsController).
        // Answered BEFORE the cache and never written into it, so switching Giving
        // back on lists the funds again at once. A missing organisation still gets
        // the findOrFail 404 inside the closure. A stale config mid-deploy reads each
        // organisation type's default (Masjid::moduleIsOff), so no masjid loses it.
        $masjid = Masjid::find($masjid_id);

        if ($masjid !== null && $masjid->moduleIsOff('giving')) {
            return response()->json([
                'status' => 'success',
                'data' => [],
            ], Response::HTTP_OK);
        }

        $funds = Cache::remember(
            "mobile.masjid.{$masjid_id}.funds",
            300,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);

                return Fund::where('masjid_id', $masjid->id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'type']);
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $funds,
        ], Response::HTTP_OK);
    }
}
