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
    <style>
        :root {
            --app-loading-bg-start: #f7faf9;
            --app-loading-bg-end: #eff3ea;
            --app-loading-bg-glow-1: #d8f2ee;
            --app-loading-bg-glow-2: #ffeecf;
            --app-loading-text: #0b1c1a;
            --app-loading-muted: #4f6360;
        }

        :root.dark,
        :root[data-theme="dark"] {
            --app-loading-bg-start: #090909;
            --app-loading-bg-end: #141414;
            --app-loading-bg-glow-1: #3f3f462e;
            --app-loading-bg-glow-2: #27272a2e;
            --app-loading-text: #f5f5f5;
            --app-loading-muted: #a3a3a3;
        }

        html {
            min-height: 100%;
            background:
                radial-gradient(circle at 10% 20%, var(--app-loading-bg-glow-1) 0%, transparent 44%),
                radial-gradient(circle at 80% 0%, var(--app-loading-bg-glow-2) 0%, transparent 38%),
                linear-gradient(160deg, var(--app-loading-bg-start), var(--app-loading-bg-end));
        }

        body {
            margin: 0;
        }

        .app-loading-screen {
            box-sizing: border-box;
            display: flex;
            min-height: 100vh;
            min-height: 100dvh;
            align-items: center;
            justify-content: center;
            padding: calc(2rem + env(safe-area-inset-top, 0px)) calc(1.5rem + env(safe-area-inset-right, 0px)) calc(2rem + env(safe-area-inset-bottom, 0px)) calc(1.5rem + env(safe-area-inset-left, 0px));
            color: var(--app-loading-text);
            font-family: "Space Grotesk", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            text-align: center;
            background:
                radial-gradient(circle at 10% 20%, var(--app-loading-bg-glow-1) 0%, transparent 44%),
                radial-gradient(circle at 80% 0%, var(--app-loading-bg-glow-2) 0%, transparent 38%),
                linear-gradient(160deg, var(--app-loading-bg-start), var(--app-loading-bg-end));
        }

        .app-loading-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.65rem;
            transform: translateY(-2vh);
        }

        .app-loading-icon {
            display: block;
            width: clamp(5.5rem, 22vw, 8.5rem);
            height: clamp(5.5rem, 22vw, 8.5rem);
        }

        .app-loading-icon-dark {
            display: none;
        }

        :root.dark .app-loading-icon-light,
        :root[data-theme="dark"] .app-loading-icon-light {
            display: none;
        }

        :root.dark .app-loading-icon-dark,
        :root[data-theme="dark"] .app-loading-icon-dark {
            display: block;
        }

        .app-loading-title {
            margin: 0.1rem 0 0;
            font-size: clamp(2rem, 8vw, 3.25rem);
            font-weight: 500;
            line-height: 1;
        }

        .app-loading-label {
            margin: 0;
            color: var(--app-loading-muted);
            font-size: 0.92rem;
            font-weight: 650;
        }
    </style>
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
<div id="app">
    <div class="app-loading-screen" role="status" aria-live="polite" aria-label="Loading Davvy">
        <div class="app-loading-brand">
            <img class="app-loading-icon app-loading-icon-light" src="{{ asset('davvy.png') }}" alt="" width="136" height="136">
            <img class="app-loading-icon app-loading-icon-dark" src="{{ asset('davvy_dark.png') }}" alt="" width="136" height="136">
            <p class="app-loading-title">Davvy</p>
            <p class="app-loading-label">Loading Davvy...</p>
        </div>
    </div>
</div>
</body>
</html>
