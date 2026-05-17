<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the security-headers bundle every HTTP response should carry.
 *
 *  - Strict-Transport-Security  — force HTTPS for a year, include subdomains
 *  - X-Content-Type-Options     — block MIME sniffing
 *  - X-Frame-Options            — deny iframe embedding (clickjacking)
 *  - Referrer-Policy            — never leak full URLs on outbound nav
 *  - Permissions-Policy         — opt out of browser features we don't use
 *  - Content-Security-Policy    — restrict scriptable origins; Supabase + Pusher
 *                                  whitelisted for the admin SPA + future apps
 *  - Cross-Origin-Opener-Policy — process isolation (Spectre defense)
 *  - X-Permitted-Cross-Domain-Policies — block Flash / Acrobat cross-domain
 *
 * Notes:
 *  - HSTS is only emitted over HTTPS (browsers ignore it on HTTP and including
 *    it would be confusing during local dev).
 *  - CSP is set to "frame-ancestors 'none'" which is the modern replacement
 *    for X-Frame-Options; both are sent for old-browser belt-and-suspenders.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('X-Frame-Options', 'DENY', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
            false,
        );
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none', false);

        // HSTS only over HTTPS so it doesn't get ignored / cause local-dev confusion.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload',
                false,
            );
        }

        // CSP: restrictive defaults. Admin SPA assets are same-origin via Blade.
        // Supabase + Pusher + Google fonts/maps are whitelisted because those
        // are the only legitimate cross-origin script/image/connect destinations
        // the admin currently uses. Tighten further by environment if needed.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.pusher.com https://js.pusher.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob: https://*.supabase.co https://*.supabase.in https://maps.gstatic.com https://maps.googleapis.com",
            "connect-src 'self' https://*.supabase.co https://*.supabase.in https://*.pusher.com wss://*.pusher.com https://onesignal.com https://*.onesignal.com",
            "frame-src 'self' https://www.google.com https://maps.google.com",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "upgrade-insecure-requests",
        ]);
        $response->headers->set('Content-Security-Policy', $csp, false);

        return $response;
    }
}
