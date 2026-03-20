<?php

return [
    'name' => env('APP_NAME', 'Davvy'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => (string) env('APP_LOCALE', 'en'),
    'fallback_locale' => (string) env('APP_FALLBACK_LOCALE', 'en'),
    'supported_locales' => array_values(array_filter(
        array_map(
            static fn (string $locale): string => strtolower(trim($locale)),
            explode(',', (string) env('APP_SUPPORTED_LOCALES', 'de,en,es,fr,pt')),
        ),
        static fn (string $locale): bool => $locale !== '',
    )),
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'maintenance' => [
        'driver' => 'file',
    ],
];
