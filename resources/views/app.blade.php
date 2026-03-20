<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
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
    <title>{{ __('common.app_title') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
<div id="app"></div>
</body>
</html>
