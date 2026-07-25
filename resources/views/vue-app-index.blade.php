<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Manara — Masjid Management Portal</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="icon" type="image/svg+xml" href="{{ asset('manara-icon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('manara-icon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('manara-icon-180.png') }}">

    @vite('resources/js/app.js')

</head>

<body id="app">
    <admin-dashboard></admin-dashboard>
</body>

</html>