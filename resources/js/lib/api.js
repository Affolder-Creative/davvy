import axios from "axios";
import {
  FALLBACK_LOCALE,
  SUPPORTED_LOCALES,
  currentLocaleFallbackChain,
  normalizeLocale,
} from "./locale";

export const api = axios.create({
  headers: {
    "X-Requested-With": "XMLHttpRequest",
    Accept: "application/json",
  },
  withCredentials: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
});

let activeApiLocale = FALLBACK_LOCALE;

for (const candidate of currentLocaleFallbackChain()) {
  activeApiLocale = normalizeLocale(candidate, {
    supported: SUPPORTED_LOCALES,
    fallback: FALLBACK_LOCALE,
  });

  if (activeApiLocale) {
    break;
  }
}

api.defaults.headers.common["X-Davvy-Locale"] = activeApiLocale;

/**
 * Sets the locale header used for API requests.
 *
 * @param {unknown} locale
 * @param {{supportedLocales?: string[], fallbackLocale?: string}} [options]
 * @returns {string}
 */
export function setApiLocale(locale, options = {}) {
  const normalized = normalizeLocale(locale, {
    supported: options.supportedLocales ?? SUPPORTED_LOCALES,
    fallback: options.fallbackLocale ?? FALLBACK_LOCALE,
  });

  activeApiLocale = normalized;
  api.defaults.headers.common["X-Davvy-Locale"] = normalized;

  return normalized;
}

/**
 * Returns the currently active API locale header value.
 *
 * @returns {string}
 */
export function getApiLocale() {
  return activeApiLocale;
}

/**
 * Returns the most useful error message from an API/network failure payload.
 *
 * @param {unknown} error
 * @param {string} [fallback='Something went wrong.']
 * @returns {string}
 */
export function extractError(error, fallback = "Something went wrong.") {
  if (error?.response?.data?.message) {
    return error.response.data.message;
  }

  if (error?.response?.data?.errors) {
    const first = Object.values(error.response.data.errors)[0];
    if (Array.isArray(first) && first[0]) {
      return first[0];
    }
  }

  return fallback;
}
