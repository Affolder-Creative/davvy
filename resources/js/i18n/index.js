import i18n from "i18next";
import { initReactI18next } from "react-i18next";
import {
  FALLBACK_LOCALE,
  SUPPORTED_LOCALES,
  currentLocaleFallbackChain,
  normalizeLocale,
  persistLocale,
  setDocumentLocale,
} from "../lib/locale";

import enCommon from "./locales/en/common.json";
import enAuth from "./locales/en/auth.json";
import enShell from "./locales/en/shell.json";
import enDashboard from "./locales/en/dashboard.json";
import enContacts from "./locales/en/contacts.json";
import enQueue from "./locales/en/queue.json";
import enAdmin from "./locales/en/admin.json";
import enProfile from "./locales/en/profile.json";

import esCommon from "./locales/es/common.json";
import esAuth from "./locales/es/auth.json";
import esShell from "./locales/es/shell.json";
import esDashboard from "./locales/es/dashboard.json";
import esContacts from "./locales/es/contacts.json";
import esQueue from "./locales/es/queue.json";
import esAdmin from "./locales/es/admin.json";
import esProfile from "./locales/es/profile.json";

const APP_TITLE_KEY = "meta.appTitle";
let titleSyncBound = false;

export const I18N_NAMESPACES = [
  "common",
  "auth",
  "shell",
  "dashboard",
  "contacts",
  "queue",
  "admin",
  "profile",
];

const resources = {
  en: {
    common: enCommon,
    auth: enAuth,
    shell: enShell,
    dashboard: enDashboard,
    contacts: enContacts,
    queue: enQueue,
    admin: enAdmin,
    profile: enProfile,
  },
  es: {
    common: esCommon,
    auth: esAuth,
    shell: esShell,
    dashboard: esDashboard,
    contacts: esContacts,
    queue: esQueue,
    admin: esAdmin,
    profile: esProfile,
  },
};

function setDocumentTitle(locale) {
  if (typeof document === "undefined") {
    return;
  }

  const nextTitle = i18n.t(APP_TITLE_KEY, {
    ns: "common",
    lng: locale,
    defaultValue: document.title || "Davvy",
  });

  document.title = String(nextTitle || document.title || "Davvy");
}

function resolveInitialLocale() {
  for (const candidate of currentLocaleFallbackChain()) {
    const locale = normalizeLocale(candidate, {
      supported: SUPPORTED_LOCALES,
      fallback: FALLBACK_LOCALE,
    });

    if (locale) {
      return locale;
    }
  }

  return FALLBACK_LOCALE;
}

const initialLocale = resolveInitialLocale();

if (!i18n.isInitialized) {
  i18n.use(initReactI18next).init({
    resources,
    lng: initialLocale,
    fallbackLng: FALLBACK_LOCALE,
    supportedLngs: SUPPORTED_LOCALES,
    defaultNS: "common",
    ns: I18N_NAMESPACES,
    interpolation: {
      escapeValue: false,
    },
    returnNull: false,
  });
}

if (!titleSyncBound) {
  i18n.on("languageChanged", (language) => {
    setDocumentTitle(language);
  });
  titleSyncBound = true;
}

export function setI18nLocale(locale, {
  supportedLocales = SUPPORTED_LOCALES,
  fallbackLocale = FALLBACK_LOCALE,
} = {}) {
  const normalized = normalizeLocale(locale, {
    supported: supportedLocales,
    fallback: fallbackLocale,
  });

  setDocumentLocale(normalized);
  setDocumentTitle(normalized);
  persistLocale(normalized);

  if (i18n.resolvedLanguage === normalized) {
    return normalized;
  }

  void i18n.changeLanguage(normalized);

  return normalized;
}

setI18nLocale(initialLocale);

export default i18n;
