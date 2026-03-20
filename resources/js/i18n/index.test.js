import { beforeEach, describe, expect, it } from "vitest";
import i18n, { setI18nLocale } from "./index";

describe("i18n locale synchronization", () => {
  beforeEach(async () => {
    window.localStorage.clear();
    await i18n.changeLanguage("en");
    document.documentElement.lang = "en";
    document.title = "";
  });

  it("normalizes and applies locale to i18n, document, and storage", async () => {
    setI18nLocale("es-MX", {
      supportedLocales: ["en", "es"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("es");
    expect(document.documentElement.lang).toBe("es");
    expect(document.title).toBe("Davvy - Administrador de CalDAV + CardDAV");
    expect(window.localStorage.getItem("davvy.locale")).toBe("es");
  });

  it("supports french locale when it is included in supported locales", async () => {
    setI18nLocale("fr-CA", {
      supportedLocales: ["en", "es", "fr"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("fr");
    expect(document.documentElement.lang).toBe("fr");
    expect(document.title).toBe("Davvy - Gestionnaire CalDAV + CardDAV");
    expect(window.localStorage.getItem("davvy.locale")).toBe("fr");
  });

  it("supports german locale when it is included in supported locales", async () => {
    setI18nLocale("de-DE", {
      supportedLocales: ["de", "en", "es", "fr", "ja"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("de");
    expect(document.documentElement.lang).toBe("de");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV-Manager");
    expect(window.localStorage.getItem("davvy.locale")).toBe("de");
  });

  it("supports japanese locale when it is included in supported locales", async () => {
    setI18nLocale("ja-JP", {
      supportedLocales: ["de", "en", "es", "fr", "ja"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("ja");
    expect(document.documentElement.lang).toBe("ja");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV マネージャー");
    expect(window.localStorage.getItem("davvy.locale")).toBe("ja");
  });

  it("falls back to configured fallback locale", async () => {
    setI18nLocale("fr-FR", {
      supportedLocales: ["en", "es"],
      fallbackLocale: "en",
    });

    await i18n.changeLanguage(i18n.language);

    expect(i18n.resolvedLanguage).toBe("en");
    expect(document.documentElement.lang).toBe("en");
    expect(document.title).toBe("Davvy - CalDAV + CardDAV Manager");
    expect(window.localStorage.getItem("davvy.locale")).toBe("en");
  });
});
