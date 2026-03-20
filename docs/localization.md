# Localization Guide

This guide explains how Davvy resolves locale, what is currently translated, and how to add or maintain locale support.

## Supported Locales

Davvy currently ships web-app locale bundles for:
- `de` (German)
- `en` (English)
- `es` (Spanish)
- `fr` (French)

Default env examples:
- `APP_LOCALE=en`
- `APP_FALLBACK_LOCALE=en`
- `APP_SUPPORTED_LOCALES=de,en,es,fr`

## Runtime Locale Resolution

Request locale is resolved in this order:
1. Authenticated user's `users.locale` (if signed in)
2. Explicit request locale:
   - `?locale=...`
   - `X-Davvy-Locale` header
   - `X-Locale` header
3. `Accept-Language` header
4. `APP_FALLBACK_LOCALE`

Locale values are normalized to lowercase and region variants are reduced to primary language when needed (for example `es-MX` -> `es` when `es` is supported).

## API Contract

Locale fields are included in public and authenticated auth payloads:
- `locale`
- `supported_locales`
- `fallback_locale`

Authenticated users can update preference via:
- `PATCH /api/auth/locale`
- body: `{ "locale": "<supported-locale>" }`

## Web UI Behavior

- The Profile page includes a language selector.
- Language choice updates:
  - i18next language
  - `document.documentElement.lang`
  - persisted local storage key: `davvy.locale`
  - `X-Davvy-Locale` API header for future requests

## DAV and Backend Message Coverage

- Backend PHP translation catalogs currently ship for `de`, `en`, `es`, and `fr` in `lang/`.
- For locales without a PHP catalog, backend/DAV/email strings fall back to `APP_FALLBACK_LOCALE` (default: English).

## Adding a New Locale

1. Add locale code to env/config support:
   - `APP_SUPPORTED_LOCALES`
   - frontend locale helpers if needed
2. Add frontend namespace JSON files under:
   - `resources/js/i18n/locales/<locale>/`
3. Add backend PHP catalogs under:
   - `lang/<locale>/`
4. Validate API and profile locale behavior in tests.

## Test Coverage Expectations

When locale behavior changes, update or add tests in:
- `tests/Feature/LocalizationTest.php`
- `tests/Feature/DavLocalizationTest.php`
- `resources/js/i18n/index.test.js`
- `resources/js/lib/locale.test.js`
- `resources/js/components/profile/ProfilePage.test.jsx`
