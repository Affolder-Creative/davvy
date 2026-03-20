export const SUPPORTED_LOCALES = ["de", "en", "es", "fr", "zh"];
export const FALLBACK_LOCALE = "en";
const LOCALE_STORAGE_KEY = "davvy.locale";
const RTL_PRIMARY_LOCALES = new Set(["ar", "fa", "he", "ur"]);
const LOCALE_LABEL_OVERRIDES = Object.freeze({
  de: "Deutsch",
  en: "English",
  es: "Español",
  fr: "Français",
  zh: "中文",
});

function canonicalizeLocaleCode(value) {
  return String(value ?? "")
    .trim()
    .toLowerCase()
    .replace(/_/g, "-");
}

function primaryLocaleCode(value) {
  const normalized = canonicalizeLocaleCode(value);
  if (!normalized) {
    return "";
  }

  return normalized.split("-")[0] || "";
}

export function normalizeLocale(
  candidate,
  { supported = SUPPORTED_LOCALES, fallback = FALLBACK_LOCALE } = {},
) {
  const normalizedSupported =
    Array.isArray(supported) && supported.length > 0
      ? supported
          .map((locale) => String(locale).trim().toLowerCase())
          .filter(Boolean)
      : [fallback];

  const normalizedFallback = normalizedSupported.includes(
    String(fallback).trim().toLowerCase(),
  )
    ? String(fallback).trim().toLowerCase()
    : normalizedSupported[0] || FALLBACK_LOCALE;

  const value = String(candidate ?? "")
    .trim()
    .toLowerCase()
    .replace(/_/g, "-");
  if (!value) {
    return normalizedFallback;
  }

  if (normalizedSupported.includes(value)) {
    return value;
  }

  const primary = value.split("-")[0];
  if (normalizedSupported.includes(primary)) {
    return primary;
  }

  return normalizedFallback;
}

/**
 * Returns a user-facing native language name for the locale code.
 *
 * @param {unknown} locale
 * @param {{fallback?: string}} [options]
 * @returns {string}
 */
export function localeDisplayName(locale, { fallback } = {}) {
  const normalized = canonicalizeLocaleCode(locale);
  if (!normalized) {
    return fallback ?? FALLBACK_LOCALE;
  }

  const primary = primaryLocaleCode(normalized);
  if (LOCALE_LABEL_OVERRIDES[normalized]) {
    return LOCALE_LABEL_OVERRIDES[normalized];
  }
  if (LOCALE_LABEL_OVERRIDES[primary]) {
    return LOCALE_LABEL_OVERRIDES[primary];
  }

  if (typeof Intl?.DisplayNames === "function") {
    try {
      const displayNames = new Intl.DisplayNames([normalized], {
        type: "language",
      });
      const derived = displayNames.of(normalized) || displayNames.of(primary);
      if (typeof derived === "string" && derived.trim() !== "") {
        return derived;
      }
    } catch {
      // Fall through to deterministic fallback.
    }
  }

  return fallback ?? normalized;
}

/**
 * Returns text direction for a locale code.
 *
 * @param {unknown} locale
 * @returns {"ltr"|"rtl"}
 */
export function localeDirection(locale) {
  const primary = primaryLocaleCode(locale);
  return RTL_PRIMARY_LOCALES.has(primary) ? "rtl" : "ltr";
}

/**
 * Builds normalized locale select options with labels and direction.
 *
 * @param {unknown} locales
 * @param {{fallbackLocale?: string}} [options]
 * @returns {Array<{value: string, label: string, dir: "ltr"|"rtl"}>}
 */
export function buildLocaleOptions(
  locales,
  { fallbackLocale = FALLBACK_LOCALE } = {},
) {
  const normalized = Array.isArray(locales)
    ? locales.map((locale) => canonicalizeLocaleCode(locale)).filter(Boolean)
    : [];

  const options =
    normalized.length > 0
      ? [...new Set(normalized)]
      : [normalizeLocale(fallbackLocale)];

  return options.map((value) => ({
    value,
    label: localeDisplayName(value, {
      fallback: value,
    }),
    dir: localeDirection(value),
  }));
}

export function getDocumentLocale() {
  if (typeof document === "undefined") {
    return null;
  }

  return document.documentElement?.lang || null;
}

export function setDocumentLocale(locale) {
  if (typeof document === "undefined") {
    return;
  }

  document.documentElement.lang = locale;
}

export function getPersistedLocale() {
  if (typeof window === "undefined" || !window.localStorage) {
    return null;
  }

  try {
    return window.localStorage.getItem(LOCALE_STORAGE_KEY);
  } catch {
    return null;
  }
}

export function persistLocale(locale) {
  if (typeof window === "undefined" || !window.localStorage) {
    return;
  }

  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale);
  } catch {
    // Ignore persistence failures.
  }
}

export function currentLocaleFallbackChain() {
  return [getPersistedLocale(), getDocumentLocale(), FALLBACK_LOCALE];
}
