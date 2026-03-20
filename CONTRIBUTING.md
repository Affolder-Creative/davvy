# Contributing to Davvy

Thanks for contributing. This project accepts issues and pull requests.

## Development Setup

Use the setup steps in [README.md](README.md) and run tests before opening a PR.

## Pull Request Expectations

1. Keep changes focused and clearly scoped.
2. Include tests when behavior changes.
3. Update docs when APIs, behavior, or configuration changes.
4. Use clear commit and PR descriptions.

## Localization Changes

When you add or change locale behavior:

1. Update frontend locale files in `resources/js/i18n/locales/<locale>/`.
2. Update backend PHP catalogs in `lang/<locale>/` when server-side strings are affected.
3. Keep locale docs current:
   - `docs/localization.md`
   - API/config/deployment docs if request/response or env behavior changes.
4. Add or update tests:
   - backend: `tests/Feature/LocalizationTest.php`, `tests/Feature/DavLocalizationTest.php`
   - frontend: i18n/locale/profile tests under `resources/js/`

## Legal Terms for Contributions

By submitting a contribution, You agree to [CLA.md](CLA.md).

In particular:

1. You keep copyright in Your contribution.
2. You grant the Maintainer broad rights to use, sublicense, and relicense
   contributions under other terms, including proprietary/commercial terms.

If You are contributing as part of Your employment, make sure Your employer
approves Your submission.
