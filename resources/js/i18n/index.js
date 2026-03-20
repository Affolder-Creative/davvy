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

const APP_TITLE_KEY = "meta.appTitle";
let titleSyncBound = false;
let localeChangeChain = Promise.resolve(FALLBACK_LOCALE);
const localeResourcePromises = new Map();

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

const EN_RESOURCES = {
  common: enCommon,
  auth: enAuth,
  shell: enShell,
  dashboard: enDashboard,
  contacts: enContacts,
  queue: enQueue,
  admin: enAdmin,
  profile: enProfile,
};

const localeNamespaceLoaders = import.meta.glob(
  [
    "./locales/de/*.json",
    "./locales/es/*.json",
    "./locales/fr/*.json",
    "./locales/it/*.json",
    "./locales/ja/*.json",
    "./locales/pt/*.json",
    "./locales/zh/*.json",
  ],
  {
    import: "default",
  },
);

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

function localeNamespaceLoaderKey(locale, namespace) {
  return `./locales/${locale}/${namespace}.json`;
}

async function loadLocaleResources(locale) {
  if (locale === FALLBACK_LOCALE) {
    return EN_RESOURCES;
  }

  const cachedPromise = localeResourcePromises.get(locale);
  if (cachedPromise) {
    return cachedPromise;
  }

  const loadPromise = (async () => {
    const namespaceEntries = await Promise.all(
      I18N_NAMESPACES.map(async (namespace) => {
        const loaderKey = localeNamespaceLoaderKey(locale, namespace);
        const loader = localeNamespaceLoaders[loaderKey];
        if (!loader) {
          throw new Error(`Missing locale namespace loader for ${loaderKey}`);
        }

        const resource = await loader();
        return [namespace, resource];
      }),
    );

    for (const [namespace, resource] of namespaceEntries) {
      i18n.addResourceBundle(locale, namespace, resource, true, true);
    }

    return Object.fromEntries(namespaceEntries);
  })();

  localeResourcePromises.set(locale, loadPromise);

  try {
    return await loadPromise;
  } catch (error) {
    localeResourcePromises.delete(locale);
    throw error;
  }
}

async function resolveLocaleWithResources(
  locale,
  {
    supportedLocales = SUPPORTED_LOCALES,
    fallbackLocale = FALLBACK_LOCALE,
  } = {},
) {
  try {
    await loadLocaleResources(locale);
    return locale;
  } catch (error) {
    const fallback = normalizeLocale(fallbackLocale, {
      supported: supportedLocales,
      fallback: FALLBACK_LOCALE,
    });

    if (fallback !== locale) {
      try {
        await loadLocaleResources(fallback);
      } catch {
        return FALLBACK_LOCALE;
      }

      return fallback;
    }

    return FALLBACK_LOCALE;
  }
}

if (!i18n.isInitialized) {
  i18n.use(initReactI18next).init({
    resources: {
      [FALLBACK_LOCALE]: EN_RESOURCES,
    },
    lng: FALLBACK_LOCALE,
    fallbackLng: FALLBACK_LOCALE,
    supportedLngs: SUPPORTED_LOCALES,
    defaultNS: "common",
    ns: I18N_NAMESPACES,
    partialBundledLanguages: true,
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

export function setI18nLocale(
  locale,
  {
    supportedLocales = SUPPORTED_LOCALES,
    fallbackLocale = FALLBACK_LOCALE,
  } = {},
) {
  const applyLocale = async () => {
    const normalized = normalizeLocale(locale, {
      supported: supportedLocales,
      fallback: fallbackLocale,
    });

    const resolved = await resolveLocaleWithResources(normalized, {
      supportedLocales,
      fallbackLocale,
    });

    setDocumentLocale(resolved);
    persistLocale(resolved);

    if (i18n.resolvedLanguage !== resolved) {
      await i18n.changeLanguage(resolved);
    }

    setDocumentTitle(resolved);

    return resolved;
  };

  localeChangeChain = localeChangeChain.then(applyLocale, applyLocale);
  return localeChangeChain;
}

void setI18nLocale(initialLocale);

export default i18n;
