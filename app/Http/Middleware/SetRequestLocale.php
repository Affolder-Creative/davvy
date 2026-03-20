<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    /**
     * Handles the incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = $this->supportedLocales();
        $fallbackLocale = $this->fallbackLocale($supportedLocales);

        $resolvedLocale = $this->resolveAuthenticatedUserLocale($request, $supportedLocales)
            ?? $this->resolveExplicitRequestLocale($request, $supportedLocales)
            ?? $this->resolveAcceptLanguageLocale((string) $request->header('Accept-Language', ''), $supportedLocales)
            ?? $fallbackLocale;

        App::setLocale($resolvedLocale);
        $request->attributes->set('resolved_locale', $resolvedLocale);

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function supportedLocales(): array
    {
        $configured = config('app.supported_locales', ['en']);

        $normalized = collect(is_array($configured) ? $configured : [])
            ->map(fn (mixed $locale): string => strtolower(trim((string) $locale)))
            ->filter(fn (string $locale): bool => $locale !== '')
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return ['en'];
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $supportedLocales
     */
    private function fallbackLocale(array $supportedLocales): string
    {
        $fallback = strtolower(trim((string) config('app.fallback_locale', 'en')));

        return in_array($fallback, $supportedLocales, true)
            ? $fallback
            : $supportedLocales[0];
    }

    /**
     * @param  array<int, string>  $supportedLocales
     */
    private function resolveAuthenticatedUserLocale(Request $request, array $supportedLocales): ?string
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        return $this->normalizeCandidateLocale((string) ($user->locale ?? ''), $supportedLocales);
    }

    /**
     * @param  array<int, string>  $supportedLocales
     */
    private function resolveExplicitRequestLocale(Request $request, array $supportedLocales): ?string
    {
        $candidates = [
            (string) $request->query('locale', ''),
            (string) $request->header('X-Davvy-Locale', ''),
            (string) $request->header('X-Locale', ''),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCandidateLocale($candidate, $supportedLocales);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $supportedLocales
     */
    private function resolveAcceptLanguageLocale(string $header, array $supportedLocales): ?string
    {
        $values = collect(explode(',', $header))
            ->map(function (string $chunk): array {
                $parts = explode(';', trim($chunk), 2);
                $locale = strtolower(trim($parts[0] ?? ''));
                $q = 1.0;

                if (isset($parts[1]) && preg_match('/q=([0-9.]+)/i', $parts[1], $matches) === 1) {
                    $q = (float) $matches[1];
                }

                return [
                    'locale' => $locale,
                    'q' => $q,
                ];
            })
            ->filter(fn (array $entry): bool => $entry['locale'] !== '')
            ->sortByDesc('q')
            ->values();

        foreach ($values as $entry) {
            $normalized = $this->normalizeCandidateLocale((string) $entry['locale'], $supportedLocales);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $supportedLocales
     */
    private function normalizeCandidateLocale(string $candidate, array $supportedLocales): ?string
    {
        $candidate = strtolower(str_replace('_', '-', trim($candidate)));
        if ($candidate === '') {
            return null;
        }

        if (in_array($candidate, $supportedLocales, true)) {
            return $candidate;
        }

        $primary = explode('-', $candidate)[0] ?? '';
        if ($primary !== '' && in_array($primary, $supportedLocales, true)) {
            return $primary;
        }

        return null;
    }
}
