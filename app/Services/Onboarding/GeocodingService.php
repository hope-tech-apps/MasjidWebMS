<?php

namespace App\Services\Onboarding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side address -> coordinates lookup for the Super-Admin onboarding
 * wizard. Turns a typed street address into latitude/longitude via the Google
 * Geocoding API so the operator no longer has to look coordinates up by hand
 * before provisioning a new masjid.
 *
 * Contract:
 *   GET https://maps.googleapis.com/maps/api/geocode/json?address={addr}&key={key}
 *   -> { "status": "OK", "results": [ { "geometry": { "location": {lat,lng} },
 *        "formatted_address": "..." }, ... ] }
 *
 * The key is a SERVER-SIDE key (services.google.geocoding_key /
 * GOOGLE_MAPS_GEOCODING_KEY) — the browser key baked into the public Nuxt site
 * is HTTP-referrer restricted and unusable here.
 *
 * Fail-soft everywhere: an unconfigured key, a transport error, a non-OK Google
 * status, or an out-of-range coordinate all return null rather than throwing,
 * so the wizard can fall back to its manual latitude/longitude inputs and the
 * onboarding flow never crashes. Callers distinguish "not configured" from "no
 * result" via isConfigured().
 */
class GeocodingService
{
    /** Google Geocoding API endpoint. */
    public const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Whether a server-side geocoding key is wired. Lets the controller return a
     * clear "geocoding not configured" message instead of a generic no-result.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.google.geocoding_key'));
    }

    /**
     * Geocode a free-form address into coordinates.
     *
     * @param  string  $address  a typed street address.
     * @return array{lat: float, lng: float, formatted_address: string}|null
     *         null when the key is absent, the request fails, Google returns no
     *         usable result, or the coordinates fall outside valid ranges.
     */
    public function geocode(string $address): ?array
    {
        $key = config('services.google.geocoding_key');

        // Not configured — let the caller surface a clear message and fall back
        // to the wizard's manual latitude/longitude inputs.
        if (empty($key)) {
            return null;
        }

        $address = trim($address);
        if ($address === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)->get(self::ENDPOINT, [
                'address' => $address,
                'key' => $key,
            ]);
        } catch (\Throwable $e) {
            Log::error('Geocoding request threw', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Geocoding request failed', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();
        $status = $body['status'] ?? null;

        // Google returns its own status string; only "OK" carries results.
        // ZERO_RESULTS / REQUEST_DENIED / OVER_QUERY_LIMIT etc. are logged (the
        // Google error_message never contains the key) and treated as no-result.
        if ($status !== 'OK' || empty($body['results'][0])) {
            Log::warning('Geocoding returned no usable result', [
                'google_status' => $status,
                'error_message' => $body['error_message'] ?? null,
            ]);

            return null;
        }

        $result = $body['results'][0];
        $lat = $result['geometry']['location']['lat'] ?? null;
        $lng = $result['geometry']['location']['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        // Guard the same ranges the provision request validates.
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'formatted_address' => $result['formatted_address'] ?? $address,
        ];
    }
}
