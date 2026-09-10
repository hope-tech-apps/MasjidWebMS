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
        //
        // THE PROXIED PAGES, and only these: they are served from an
        // organisation's OWN domain (burlingtonmasjid.com/jummah-lunch/{id},
        // alrazischool.org/portal) with this app behind the rewrite. The
        // document origin there is the school's or masjid's domain, so 'self'
        // no longer covers THIS app's bundle, fonts, icons or API — every one of
        // which the Blade emits as an absolute URL back to config('app.url').
        // On these paths, and no others, this app's own origin is named
        // explicitly so the proxied page can boot and reach its API. Every other
        // page's CSP is unchanged. (The Figtree CDN used to be widened here too;
        // it is loaded on every page, so it now lives in the base policy below.)
        //
        // A LIST, not a second `if`: the next proxied page must be one string
        // here rather than a copied branch that drifts from this one. Note that
        // the CSP is only half of what a proxied page needs — the other half is
        // CORS_ALLOWED_ORIGINS naming that domain, without which the page still
        // renders perfectly and every API call is refused by the browser.
        $proxiedPaths = ['jummah-lunch', 'portal'];

        $ownOrigin = $ownStyle = $ownFont = $ownImg = $ownConnect = '';
        $isProxied = false;

        foreach ($proxiedPaths as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                $isProxied = true;
                break;
            }
        }

        if ($isProxied) {
            $app = rtrim((string) config('app.url'), '/');
            $ownOrigin = " {$app}";
            $ownStyle = " {$app}";
            $ownFont = " {$app}";
            $ownImg = " {$app}";
            $ownConnect = " {$app}";
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.pusher.com https://js.pusher.com{$ownOrigin}",
            // fonts.bunny.net sits in the BASE policy, not in the proxied-only
            // widening above, because vue-app-index.blade.php links Figtree on
            // EVERY page. It was in the widening, so the font it loads was
            // refused on every path except /portal and /jummah-lunch — on this
            // app's own domain too. The failure is quiet: the stylesheet is
            // blocked, the page falls back to the system font stack and looks
            // merely a bit off, and only the console says why.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net{$ownStyle}",
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:{$ownFont}",
            "img-src 'self' data: blob: https://*.supabase.co https://*.supabase.in https://maps.gstatic.com https://maps.googleapis.com{$ownImg}",
            "connect-src 'self' https://*.supabase.co https://*.supabase.in https://*.pusher.com wss://*.pusher.com https://onesignal.com https://*.onesignal.com{$ownConnect}",
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
