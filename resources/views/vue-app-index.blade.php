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

    @vite('resources/js/app.js')

</head>

<body id="app">
    <admin-dashboard></admin-dashboard>
</body>

</html>