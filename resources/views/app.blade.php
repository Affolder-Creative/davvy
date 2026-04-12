<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('images/icons/apple-touch-icon-180.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#00786f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ __('common.app_title') }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
