<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Onboarding\GeocodeAddressRequest;
use App\Services\Onboarding\GeocodingService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-Admin onboarding intake helpers — small, stateless lookups the wizard
 * calls WHILE the operator fills out the form (before the single transactional
 * OnboardingController@provision call). Gating: registered under the admin auth
 * group with the `super` middleware, same as OnboardingController.
 */
class OnboardingIntakeController extends Controller
{
    /**
     * Geocode a typed address into latitude/longitude for the wizard's
     * "Find coordinates" button (App\Services\Onboarding\GeocodingService).
     *
     * Always responds HTTP 200. On success: { status:'success', data:{
     * latitude, longitude, formatted_address } }. When the server-side key is
     * not provisioned, or the address can't be resolved, responds
     * { status:'error', message } (HTTP 200) so the wizard shows the note and
     * falls back to its manual latitude/longitude inputs — this is a helper, not
     * a hard gate on provisioning.
     */
    public function geocode(GeocodeAddressRequest $request, GeocodingService $geocoder)
    {
        if (! $geocoder->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Server-side geocoding is not configured (GOOGLE_MAPS_GEOCODING_KEY is missing). Enter latitude and longitude manually.',
            ], Response::HTTP_OK);
        }

        $result = $geocoder->geocode($request->input('address'));

        if ($result === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Could not find coordinates for that address. Check the address, or enter latitude and longitude manually.',
            ], Response::HTTP_OK);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'latitude' => $result['lat'],
                'longitude' => $result['lng'],
                'formatted_address' => $result['formatted_address'],
            ],
        ], Response::HTTP_OK);
    }
}
