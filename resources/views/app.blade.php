<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @php
        $iosStartupImages = [
            ['width' => 320, 'height' => 568, 'ratio' => 2, 'image_width' => 640, 'image_height' => 1136],
            ['width' => 375, 'height' => 667, 'ratio' => 2, 'image_width' => 750, 'image_height' => 1334],
            ['width' => 414, 'height' => 736, 'ratio' => 3, 'image_width' => 1242, 'image_height' => 2208],
            ['width' => 375, 'height' => 812, 'ratio' => 3, 'image_width' => 1125, 'image_height' => 2436],
            ['width' => 414, 'height' => 896, 'ratio' => 2, 'image_width' => 828, 'image_height' => 1792],
            ['width' => 414, 'height' => 896, 'ratio' => 3, 'image_width' => 1242, 'image_height' => 2688],
            ['width' => 390, 'height' => 844, 'ratio' => 3, 'image_width' => 1170, 'image_height' => 2532],
            ['width' => 393, 'height' => 852, 'ratio' => 3, 'image_width' => 1179, 'image_height' => 2556],
            ['width' => 428, 'height' => 926, 'ratio' => 3, 'image_width' => 1284, 'image_height' => 2778],
            ['width' => 430, 'height' => 932, 'ratio' => 3, 'image_width' => 1290, 'image_height' => 2796],
            ['width' => 768, 'height' => 1024, 'ratio' => 2, 'image_width' => 1536, 'image_height' => 2048],
            ['width' => 810, 'height' => 1080, 'ratio' => 2, 'image_width' => 1620, 'image_height' => 2160],
            ['width' => 820, 'height' => 1180, 'ratio' => 2, 'image_width' => 1640, 'image_height' => 2360],
            ['width' => 834, 'height' => 1112, 'ratio' => 2, 'image_width' => 1668, 'image_height' => 2224],
            ['width' => 834, 'height' => 1194, 'ratio' => 2, 'image_width' => 1668, 'image_height' => 2388],
            ['width' => 1024, 'height' => 1366, 'ratio' => 2, 'image_width' => 2048, 'image_height' => 2732],
        ];
    @endphp
    <meta charset="UTF-8">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('images/icons/apple-touch-icon-180.png') }}">
    @foreach ($iosStartupImages as $startupImage)
        @foreach (['portrait', 'landscape'] as $orientation)
            @php
                $splashWidth = $orientation === 'portrait' ? $startupImage['image_width'] : $startupImage['image_height'];
                $splashHeight = $orientation === 'portrait' ? $startupImage['image_height'] : $startupImage['image_width'];
            @endphp
            @foreach (['light', 'dark'] as $scheme)
                <link rel="apple-touch-startup-image" href="{{ asset("images/splash/ios-splash-{$scheme}-{$splashWidth}x{$splashHeight}.png") }}" media="(prefers-color-scheme: {{ $scheme }}) and (device-width: {{ $startupImage['width'] }}px) and (device-height: {{ $startupImage['height'] }}px) and (-webkit-device-pixel-ratio: {{ $startupImage['ratio'] }}) and (orientation: {{ $orientation }})">
            @endforeach
        @endforeach
    @endforeach
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
