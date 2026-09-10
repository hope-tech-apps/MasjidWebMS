<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Manara — Masjid Management Portal</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    {{--
        Montserrat is the flyer face, and it is self-hosted rather than fetched from a
        font CDN like Figtree above. The Flyer Studio exports a PNG by rasterising the
        live nodes, so the font has to be a first-party asset the export path can rely
        on — a third-party host being slow, blocked, or down would silently change what
        the masjid downloads. The templates in resources/flyer-templates ask for
        Montserrat and deliberately import nothing themselves; this is where they get it.

        One variable file covers the whole 100-900 range, which is why the weights the
        templates use (400 / 500 / 800 / 900, per flyer-templates/index.json) all
        resolve from a single request. A browser too old to understand the range
        descriptor drops the whole rule and falls back to Helvetica — which is the
        documented degradation, and far better than rendering everything in the file's
        default instance, Thin.
    --}}
    <link rel="preload" as="font" type="font/ttf" href="{{ asset('fonts/Montserrat-variable.ttf') }}" crossorigin>
    <style>
        @font-face {
            font-family: 'Montserrat';
            src: url("{{ asset('fonts/Montserrat-variable.ttf') }}") format('truetype-variations'),
                 url("{{ asset('fonts/Montserrat-variable.ttf') }}") format('truetype');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
    </style>

    <link rel="icon" type="image/svg+xml" href="{{ asset('manara-icon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('manara-icon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('manara-icon-180.png') }}">

    {{--
        WHICH ORGANISATION IS THIS, WHEN THE URL DOES NOT SAY?

        `/portal` carries no id. On this app's own domain nobody asks — the
        id-bearing `/portal/14` is what gets linked. But a school can point its
        own hostname at this application, and then the hostname IS the answer.

        Previously the only source of this was the Cloudflare Worker proxying
        alrazischool.org/portal, which injected the same global after `<head>`.
        That works for a proxied path and cannot work for a hostname pointed
        straight here, because there is no proxy in between to inject anything.

        Emitted for every request on a mapped host rather than only on /portal:
        the SPA is a single bundle and the router decides the page client-side,
        so the value has to be present before the app boots. Only OrgPortal.vue
        reads it, and an unmapped host emits nothing at all.
    --}}
    @php($portalMasjidId = config('portal.hosts')[strtolower(request()->getHost())] ?? null)
    @if ($portalMasjidId)
        <script>window.__PORTAL_MASJID__ = {{ (int) $portalMasjidId }};</script>
    @endif

    {{--
        WHICH DEPLOYMENT IS THIS?

        The SPA is one bundle served from every host, and staging is a scrubbed
        copy of production — same branding, same screens, same donation forms.
        Without this a staging tab is visually indistinguishable from the live
        site, which is how someone ends up entering a real card number, or
        "fixing" a record on a box whose data is thrown away weekly.

        Emitted before @vite, like __PORTAL_MASJID__ above, because the value has
        to exist before the app boots — EnvironmentRibbon reads it during setup.

        Always emitted, including "production": a MISSING global and a
        production one must not be distinguishable, or a build served from a
        stale Blade would read as "unknown" and the ribbon would have to guess.
        The ribbon renders nothing for production, so this costs one inert line.
    --}}
    <script>window.__APP_ENV__ = @json(\App\Support\Environment::name());</script>

    @vite('resources/js/app.js')

</head>

<body id="app">
    <admin-dashboard></admin-dashboard>
</body>

</html>