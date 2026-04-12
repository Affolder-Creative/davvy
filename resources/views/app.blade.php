<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    {{-- <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icons/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/icons/favicon-16.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}"> --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/icons/apple-touch-icon-180.png') }}">
    {{-- <link rel="apple-touch-icon" sizes="40x40" href="{{ asset('images/icons/ios/Icon-40x40.png') }}">
    <link rel="apple-touch-icon" sizes="58x58" href="{{ asset('images/icons/ios/Icon-58x58.png') }}">
    <link rel="apple-touch-icon" sizes="60x60" href="{{ asset('images/icons/ios/Icon-60x60.png') }}">
    <link rel="apple-touch-icon" sizes="76x76" href="{{ asset('images/icons/ios/Icon-76x76.png') }}">
    <link rel="apple-touch-icon" sizes="80x80" href="{{ asset('images/icons/ios/Icon-80x80.png') }}">
    <link rel="apple-touch-icon" sizes="87x87" href="{{ asset('images/icons/ios/Icon-87x87.png') }}">
    <link rel="apple-touch-icon" sizes="114x114" href="{{ asset('images/icons/ios/Icon-114x114.png') }}">
    <link rel="apple-touch-icon" sizes="120x120" href="{{ asset('images/icons/ios/Icon-120x120.png') }}">
    <link rel="apple-touch-icon" sizes="128x128" href="{{ asset('images/icons/ios/Icon-128x128.png') }}">
    <link rel="apple-touch-icon" sizes="136x136" href="{{ asset('images/icons/ios/Icon-136x136.png') }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ asset('images/icons/ios/Icon-152x152.png') }}">
    <link rel="apple-touch-icon" sizes="167x167" href="{{ asset('images/icons/ios/Icon-167x167.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/icons/ios/Icon-180x180.png') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('images/icons/ios/Icon-192x192.png') }}">
    <link rel="apple-touch-icon" sizes="1024x1024" href="{{ asset('images/icons/ios/Icon-1024x1024.png') }}"> --}}

    <meta name="theme-color" content="#00786f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ __('common.app_title') }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('common.app_title') }}</title>

    <script>
        (function () {
            const storageKey = "davvy-theme";
            const allowed = new Set(["system", "light", "dark"]);

            try {
                const raw = window.localStorage.getItem(storageKey);
                const preferred = allowed.has(raw) ? raw : "system";
                const resolved = preferred === "system"
                    ? (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light")
                    : preferred;

                document.documentElement.classList.toggle("dark", resolved === "dark");
                document.documentElement.dataset.theme = resolved;
                document.documentElement.style.colorScheme = resolved;
            } catch {
                // Fall back to light mode if storage is unavailable.
            }
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
<div id="app"></div>
</body>
</html>
